<?php
/**
 * Database Deployment Endpoint
 * 
 * Receives database file via HTTPS POST and performs atomic symlink swap.
 * Used by UK PC to deploy fuel data to Hetzner server.
 */

// ============================================================================
// Configuration
// ============================================================================
require_once __DIR__ . '/config.php';

// Deployment API key from config
$deployApiKey = DEPLOY_API_KEY;

// File size limits (bytes)
const MIN_FILE_SIZE = 1024 * 1024;      // 1 MB minimum
const MAX_FILE_SIZE = 50 * 1024 * 1024; // 50 MB maximum

// SQLite magic bytes
const SQLITE_MAGIC = 'SQLite format 3';

// Data directory paths
$dataDir = __DIR__ . '/../data';
$symlinkPath = $dataDir . '/fuel_data.db';
$versionA = $dataDir . '/fuel_data.db.v1';
$versionB = $dataDir . '/fuel_data.db.v2';
$deployLog = $dataDir . '/deploy.log';

// ============================================================================
// Helper Functions
// ============================================================================

/**
 * Send JSON response and exit
 * @param bool $success Whether the operation succeeded
 * @param string $message Human-readable message
 * @param array $data Additional data to include
 * @param int $httpCode HTTP status code
 */
function sendResponse($success, $message, $data = [], $httpCode = 200) {
    http_response_code($httpCode);
    header('Content-Type: application/json');
    
    $response = array_merge([
        'success' => $success,
        'message' => $message,
        'timestamp' => date('Y-m-d H:i:s')
    ], $data);
    
    echo json_encode($response, JSON_PRETTY_PRINT);
    exit($success ? 0 : 1);
}

/**
 * Log deployment event
 * @param string $message Log message
 * @param string $level Log level (INFO, ERROR, WARN)
 */
function logDeploy($message, $level = 'INFO') {
    global $deployLog;
    $line = '[' . date('Y-m-d H:i:s') . '] [' . $level . '] ' . $message . "\n";
    @file_put_contents($deployLog, $line, FILE_APPEND | LOCK_EX);
}

/**
 * Validate uploaded file is a valid SQLite database
 * @param string $filePath Path to uploaded temp file
 * @return bool True if valid SQLite file
 */
function validateSQLiteFile($filePath) {
    // Check file exists and is readable
    if (!file_exists($filePath) || !is_readable($filePath)) {
        return false;
    }
    
    // Read magic bytes (first 16 bytes)
    $handle = fopen($filePath, 'rb');
    if (!$handle) {
        return false;
    }
    
    $magic = fread($handle, 16);
    fclose($handle);
    
    // Check for SQLite magic string (includes null terminator)
    return strpos($magic, SQLITE_MAGIC) === 0;
}

/**
 * Perform atomic symlink swap
 * @param string $newFile Path to new database file
 * @return array Result with success flag and details
 */
function atomicSwap($newFile) {
    global $symlinkPath, $versionA, $versionB;
    
    // Debug logging
    logDeploy("atomicSwap called with temp file: $newFile", 'DEBUG');
    logDeploy("  File exists: " . (file_exists($newFile) ? 'YES' : 'NO'), 'DEBUG');
    logDeploy("  File readable: " . (is_readable($newFile) ? 'YES' : 'NO'), 'DEBUG');
    logDeploy("  File size: " . filesize($newFile) . " bytes", 'DEBUG');
    logDeploy("  Current symlink: $symlinkPath", 'DEBUG');
    logDeploy("  Symlink exists: " . (file_exists($symlinkPath) || is_link($symlinkPath) ? 'YES' : 'NO'), 'DEBUG');
    
    // Determine which version to write to (the one NOT currently symlinked)
    $currentTarget = @readlink($symlinkPath);
    logDeploy("  Current target: " . ($currentTarget ?: 'none'), 'DEBUG');
    
    if ($currentTarget === false) {
        // No symlink exists, this is first deployment
        $targetVersion = $versionA;
    } elseif (basename($currentTarget) === 'fuel_data.db.v2') {
        $targetVersion = $versionA;
    } else {
        $targetVersion = $versionB;
    }
    logDeploy("  Target version: $targetVersion", 'DEBUG');
    
    // Log the swap details
    $oldVersion = basename($currentTarget ?: 'none');
    $newVersion = basename($targetVersion);
    
    // Remove WAL/shm files from target if they exist
    foreach ([$targetVersion . '-wal', $targetVersion . '-shm'] as $wal) {
        if (file_exists($wal)) {
            @unlink($wal);
        }
    }
    
    // Check if we can write to the target directory
    $targetDir = dirname($targetVersion);
    logDeploy("  Target directory: $targetDir", 'DEBUG');
    logDeploy("  Target dir exists: " . (is_dir($targetDir) ? 'YES' : 'NO'), 'DEBUG');
    logDeploy("  Target dir writable: " . (is_writable($targetDir) ? 'YES' : 'NO'), 'DEBUG');
    if (is_dir($targetDir)) {
        logDeploy("  Target dir perms: " . substr(sprintf('%o', fileperms($targetDir)), -4), 'DEBUG');
        logDeploy("  Target dir owner: " . posix_getpwuid(fileowner($targetDir))['name'] ?? fileowner($targetDir), 'DEBUG');
    }
    
    if (!is_writable($targetDir)) {
        return [
            'success' => false,
            'error' => 'Target directory not writable: ' . $targetDir . ' (permissions: ' . substr(sprintf('%o', fileperms($targetDir)), -4) . ', owner: ' . (posix_getpwuid(fileowner($targetDir))['name'] ?? fileowner($targetDir)) . ')'
        ];
    }
    
    // Check source file exists and is readable
    if (!file_exists($newFile) || !is_readable($newFile)) {
        return [
            'success' => false,
            'error' => 'Uploaded file not accessible: ' . $newFile . ' (exists: ' . (file_exists($newFile) ? 'yes' : 'no') . ', readable: ' . (is_readable($newFile) ? 'yes' : 'no') . ')'
        ];
    }
    
    // If target exists, try to remove it first
    if (file_exists($targetVersion)) {
        if (!unlink($targetVersion)) {
            return [
                'success' => false,
                'error' => 'Cannot remove existing target file: ' . $targetVersion
            ];
        }
    }
    
    // Move uploaded file to target version
    // Use rename() first (faster), fallback to copy+delete for cross-device moves
    $renameSuccess = @rename($newFile, $targetVersion);
    
    if (!$renameSuccess) {
        $error = error_get_last();
        logDeploy('rename() failed: ' . ($error['message'] ?? 'unknown error') . ', trying copy+unlink', 'WARN');
        
        if (!copy($newFile, $targetVersion)) {
            $error = error_get_last();
            return [
                'success' => false,
                'error' => 'Failed to copy uploaded file: ' . ($error['message'] ?? 'unknown error') . '. Check that PHP has write permission to: ' . $targetDir
            ];
        }
        
        // Copy succeeded, now delete the original
        if (!unlink($newFile)) {
            logDeploy('Warning: Could not delete temp file after copy: ' . $newFile, 'WARN');
        }
    }
    
    // Set appropriate permissions
    chmod($targetVersion, 0644);
    
    // Remove old symlink if exists
    if (file_exists($symlinkPath) || is_link($symlinkPath)) {
        if (!unlink($symlinkPath)) {
            // Try to recover - rename target back
            rename($targetVersion, $newFile);
            return [
                'success' => false,
                'error' => 'Failed to remove existing symlink'
            ];
        }
    }
    
    // Create new symlink atomically
    if (!symlink($targetVersion, $symlinkPath)) {
        // Try to recover
        rename($targetVersion, $newFile);
        return [
            'success' => false,
            'error' => 'Failed to create new symlink'
        ];
    }
    
    return [
        'success' => true,
        'previous_version' => $oldVersion,
        'new_version' => $newVersion,
        'file_size' => filesize($targetVersion)
    ];
}

// ============================================================================
// Main Request Handler
// ============================================================================

// Handle status check (GET request)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'status') {
    $currentTarget = @readlink($symlinkPath);
    $lastDeploy = @file_exists($deployLog) ? filemtime($deployLog) : null;
    
    sendResponse(true, 'Deployment status', [
        'current_version' => $currentTarget ? basename($currentTarget) : 'none',
        'symlink_valid' => $currentTarget !== false && file_exists($symlinkPath),
        'last_deployment' => $lastDeploy ? date('Y-m-d H:i:s', $lastDeploy) : 'never',
        'v1_exists' => file_exists($versionA),
        'v2_exists' => file_exists($versionB)
    ]);
}

// Only accept POST requests for deployment
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed. Use POST for deployment, GET with action=status for status.', [], 405);
}

// Authenticate request
$headers = getallheaders();
$providedKey = '';

// Check for X-Deploy-Key header (case-insensitive)
foreach ($headers as $name => $value) {
    if (strtolower($name) === 'x-deploy-key') {
        $providedKey = $value;
        break;
    }
}

if (empty($deployApiKey)) {
    logDeploy('DEPLOY_API_KEY not configured on server', 'ERROR');
    sendResponse(false, 'Server configuration error: deployment not configured', [], 500);
}

if (empty($providedKey) || $providedKey !== $deployApiKey) {
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    logDeploy("Authentication failed from IP: $clientIp", 'ERROR');
    sendResponse(false, 'Invalid or missing deployment key', [], 403);
}

// Validate file upload
if (!isset($_FILES['database']) || $_FILES['database']['error'] !== UPLOAD_ERR_OK) {
    $errorCode = $_FILES['database']['error'] ?? 'no_file';
    $errorMessages = [
        UPLOAD_ERR_INI_SIZE => 'file too large (exceeds upload_max_filesize)',
        UPLOAD_ERR_FORM_SIZE => 'file too large (exceeds form MAX_FILE_SIZE)',
        UPLOAD_ERR_PARTIAL => 'file partially uploaded (network/timeout issue)',
        UPLOAD_ERR_NO_FILE => 'no file uploaded',
        UPLOAD_ERR_NO_TMP_DIR => 'missing temp folder',
        UPLOAD_ERR_CANT_WRITE => 'failed to write to disk',
        UPLOAD_ERR_EXTENSION => 'upload stopped by extension'
    ];
    $errorMsg = $errorMessages[$errorCode] ?? "unknown error (code $errorCode)";
    logDeploy("File upload failed: $errorMsg", 'ERROR');
    sendResponse(false, 'File upload failed: ' . $errorMsg, [], 400);
}

$uploadedFile = $_FILES['database']['tmp_name'];
$fileSize = $_FILES['database']['size'];

// Check if file is gzip compressed
$isGzipped = false;
foreach ($headers as $name => $value) {
    if (strtolower($name) === 'x-content-encoding' && strtolower($value) === 'gzip') {
        $isGzipped = true;
        break;
    }
}

// Also check by examining file magic bytes (gzip starts with 0x1f 0x8b)
if (!$isGzipped && file_exists($uploadedFile)) {
    $fh = fopen($uploadedFile, 'rb');
    if ($fh) {
        $magic = fread($fh, 2);
        fclose($fh);
        $isGzipped = ($magic === "\x1f\x8b");
    }
}

// Decompress if gzip encoded
if ($isGzipped) {
    logDeploy("Received gzip-compressed file ($fileSize bytes), decompressing...", 'INFO');
    
    if (!extension_loaded('zlib')) {
        @unlink($uploadedFile);
        logDeploy("Cannot decompress: zlib extension not available", 'ERROR');
        sendResponse(false, 'Server error: cannot decompress file', [], 500);
    }
    
    $decompressedFile = $uploadedFile . '.decompressed';
    $input = gzopen($uploadedFile, 'rb');
    $output = fopen($decompressedFile, 'wb');
    
    if (!$input || !$output) {
        @unlink($uploadedFile);
        @unlink($decompressedFile);
        logDeploy("Failed to open files for decompression", 'ERROR');
        sendResponse(false, 'Server error: decompression failed', [], 500);
    }
    
    while (!gzeof($input)) {
        fwrite($output, gzread($input, 1024 * 1024)); // 1MB chunks
    }
    
    gzclose($input);
    fclose($output);
    unlink($uploadedFile); // Remove compressed file
    
    $uploadedFile = $decompressedFile;
    $fileSize = filesize($uploadedFile);
    logDeploy("Decompressed to $fileSize bytes", 'INFO');
}

// Validate file size
if ($fileSize < MIN_FILE_SIZE) {
    @unlink($uploadedFile);
    logDeploy("File too small: $fileSize bytes", 'ERROR');
    sendResponse(false, 'File too small (minimum ' . (MIN_FILE_SIZE / 1024 / 1024) . ' MB)', [], 400);
}

if ($fileSize > MAX_FILE_SIZE) {
    @unlink($uploadedFile);
    logDeploy("File too large: $fileSize bytes", 'ERROR');
    sendResponse(false, 'File too large (maximum ' . (MAX_FILE_SIZE / 1024 / 1024) . ' MB)', [], 400);
}

// Validate SQLite format
if (!validateSQLiteFile($uploadedFile)) {
    @unlink($uploadedFile);
    logDeploy("Invalid SQLite file uploaded", 'ERROR');
    sendResponse(false, 'Invalid database file (not a valid SQLite file)', [], 400);
}

// Ensure data directory exists
if (!is_dir($dataDir)) {
    if (!mkdir($dataDir, 0755, true)) {
        @unlink($uploadedFile);
        logDeploy("Failed to create data directory: $dataDir", 'ERROR');
        sendResponse(false, 'Server error: cannot create data directory', [], 500);
    }
}

// Perform atomic swap
$result = atomicSwap($uploadedFile);

if (!$result['success']) {
    logDeploy("Swap failed: " . $result['error'], 'ERROR');
    sendResponse(false, 'Deployment failed: ' . $result['error'], [], 500);
}

// Success!
$sizeMB = round($result['file_size'] / 1024 / 1024, 2);
logDeploy("Successful deployment: {$result['new_version']} ({$sizeMB} MB, was {$result['previous_version']})", 'INFO');

sendResponse(true, 'Deployment successful', [
    'previous_version' => $result['previous_version'],
    'new_version' => $result['new_version'],
    'file_size_mb' => $sizeMB
]);
