<?php
/**
 * FuelSeeker UK PC Deployment Script
 * 
 * Builds fuel database locally and deploys to Hetzner server via HTTPS.
 * Designed to run on a UK-based PC (no VPN needed for Fuel Finder API).
 * 
 * Usage:
 *   php deploy_to_hetzner.php
 * 
 * Environment variables:
 *   FUELSEEKER_SCRIPT_DIR - Path to scripts directory
 *   FUELSEEKER_DATA_DIR   - Path to data directory
 *   FUELSEEKER_DEPLOY_URL - Deployment URL (default: https://fuelseeker.net/scripts/db_deploy.php)
 * 
 * Cron setup (3x daily):
 *   0 6,14,22 * * * /usr/bin/php /path/to/fuelseeker/not_for_website/deploy_to_hetzner.php >> /path/to/fuelseeker/logs/deploy.log 2>&1
 */

// Security: Only allow CLI access
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo json_encode(['error' => 'This script can only be run from the command line']);
    exit(1);
}

// ============================================================================
// Configuration
// ============================================================================

// API key location (chmod 600 required)
define('API_KEY_FILE', getenv('HOME') . '/.fuelseeker_deploy_key');

// Default deployment URL (override with FUELSEEKER_DEPLOY_URL env var)
define('DEPLOY_URL', getenv('FUELSEEKER_DEPLOY_URL') ?: 'https://fuelseeker.net/scripts/db_deploy.php');

// Retry configuration
const MAX_RETRIES = 3;
const RETRY_DELAYS = [5, 15, 45]; // Seconds between retries (exponential backoff)

// cURL timeout (seconds) - generous for UK broadband upload
const UPLOAD_TIMEOUT = 120;

// ============================================================================
// Path Configuration - Auto-detection with Environment Variable Override
// ============================================================================

function detectPaths() {
    // 1. Check environment variables first
    $envScriptDir = getenv('FUELSEEKER_SCRIPT_DIR');
    $envDataDir = getenv('FUELSEEKER_DATA_DIR');
    
    if ($envScriptDir && $envDataDir) {
        return [
            'scriptDir' => rtrim($envScriptDir, '/'),
            'dataDir' => rtrim($envDataDir, '/')
        ];
    }
    
    // 2. Auto-detect based on this script's location
    $thisDir = __DIR__;
    $baseDir = dirname($thisDir); // Parent of not_for_website/
    
    $autoScriptDir = $baseDir . '/scripts';
    $autoDataDir = $baseDir . '/data';
    
    if (is_dir($autoScriptDir) && is_dir($autoDataDir)) {
        return [
            'scriptDir' => $autoScriptDir,
            'dataDir' => $autoDataDir
        ];
    }
    
    // 3. Fallback to hardcoded paths
    return [
        'scriptDir' => '/Users/bobosola/Sites/fuelseeker.net/scripts',
        'dataDir' => '/Users/bobosola/Sites/fuelseeker.net/data'
    ];
}

$paths = detectPaths();
$scriptDir = $paths['scriptDir'];
$dataDir = $paths['dataDir'];
$tempDir = $dataDir . '/tmp';
$logsDir = dirname($dataDir) . '/logs';

// Ensure logs directory exists
if (!is_dir($logsDir)) {
    @mkdir($logsDir, 0755, true);
}

// ============================================================================
// Logging
// ============================================================================

function logMsg($message, $level = 'INFO') {
    global $logsDir;
    $line = '[' . date('Y-m-d H:i:s') . '] [' . $level . '] ' . $message . "\n";
    echo $line;
    flush();
    
    // Also write to deploy log
    @file_put_contents($logsDir . '/deploy.log', $line, FILE_APPEND | LOCK_EX);
}

// ============================================================================
// API Key Loading
// ============================================================================

function loadApiKey() {
    if (!file_exists(API_KEY_FILE)) {
        throw new Exception('API key file not found: ' . API_KEY_FILE);
    }
    
    // Check permissions (should be 600 or more restrictive)
    $perms = fileperms(API_KEY_FILE) & 0777;
    if ($perms > 0600) {
        throw new Exception('API key file has insecure permissions (' . sprintf('%o', $perms) . '). Run: chmod 600 ' . API_KEY_FILE);
    }
    
    $key = trim(file_get_contents(API_KEY_FILE));
    if (empty($key) || strlen($key) < 32) {
        throw new Exception('API key is empty or too short');
    }
    
    return $key;
}

// ============================================================================
// Database Building (Reuses streaming update logic)
// ============================================================================

require_once $scriptDir . '/config.php';

function buildDatabase($dataDir, $scriptDir, $tempDir) {
    $fuelApiBase = 'https://www.fuel-finder.service.gov.uk/api/v1';
    $symlinkPath = $dataDir . '/fuel_data.db';
    $versionA = $dataDir . '/fuel_data.db.v1';
    $versionB = $dataDir . '/fuel_data.db.v2';
    $lockFile = $dataDir . '/update.lock';
    
    // Prevent concurrent builds
    if (file_exists($lockFile)) {
        $lockAge = time() - filemtime($lockFile);
        if ($lockAge < 3600) {
            throw new Exception('Build already in progress (lock file exists, age: ' . $lockAge . 's)');
        }
        unlink($lockFile);
    }
    touch($lockFile);
    
    function buildCleanup($lockFile) {
        if (file_exists($lockFile)) {
            unlink($lockFile);
        }
    }
    register_shutdown_function('buildCleanup', $lockFile);
    
    logMsg('=== Starting Database Build ===');
    
    // Ensure temp directory exists
    if (!is_dir($tempDir)) {
        mkdir($tempDir, 0755, true);
    }
    
    // Clean old temp files
    foreach (glob($tempDir . '/*.csv') as $f) {
        @unlink($f);
    }
    
    // First-time setup: create symlink structure if needed
    if (!file_exists($symlinkPath) && file_exists($dataDir . '/fuel_data.db')) {
        logMsg('Migrating to symlink structure...');
        rename($dataDir . '/fuel_data.db', $versionA);
        symlink($versionA, $symlinkPath);
    }
    
    if (!file_exists($symlinkPath)) {
        logMsg('Creating initial database structure...');
        touch($versionA);
        symlink($versionA, $symlinkPath);
    }
    
    // Determine which version to build (the one NOT currently symlinked)
    $currentTarget = @readlink($symlinkPath);
    if ($currentTarget === false || basename($currentTarget) === 'fuel_data.db.v2') {
        $targetVersion = $versionA;
    } else {
        $targetVersion = $versionB;
    }
    
    logMsg('Current: ' . ($currentTarget ? basename($currentTarget) : 'none'));
    logMsg('Building: ' . basename($targetVersion));
    
    // Get OAuth token
    logMsg('[1/4] Getting OAuth token...');
    $token = getFuelToken($fuelApiBase);
    if (!$token) {
        throw new Exception('Failed to get OAuth token from Fuel Finder API');
    }
    logMsg('Got token');
    
    // Stream stations to CSV
    logMsg('[2/4] Downloading stations to CSV...');
    $stationsCsv = $tempDir . '/stations.csv';
    $stationCount = streamStationsToCsv($fuelApiBase, $token, $stationsCsv);
    logMsg("Downloaded $stationCount stations");
    
    // Stream prices to CSV
    logMsg('[3/4] Downloading prices to CSV...');
    $pricesCsv = $tempDir . '/prices.csv';
    $priceCount = streamPricesToCsv($fuelApiBase, $token, $pricesCsv);
    logMsg("Downloaded prices for $priceCount stations");
    
    // Build database using SQLite CLI
    logMsg('[4/4] Building database via SQLite CLI...');
    
    // Clean up target version
    if (file_exists($targetVersion)) {
        unlink($targetVersion);
    }
    foreach ([$targetVersion . '-wal', $targetVersion . '-shm'] as $wal) {
        if (file_exists($wal)) unlink($wal);
    }
    
    buildDatabaseWithCli($targetVersion, $stationsCsv, $pricesCsv, $scriptDir);
    
    // Get file size for reporting
    $fileSize = filesize($targetVersion);
    $sizeMB = round($fileSize / 1024 / 1024, 2);
    logMsg("Database built: {$sizeMB} MB");
    
    // Clean up CSV files
    @unlink($stationsCsv);
    @unlink($pricesCsv);
    
    buildCleanup($lockFile);
    
    return [
        'targetVersion' => $targetVersion,
        'versionName' => basename($targetVersion),
        'fileSize' => $fileSize,
        'sizeMB' => $sizeMB,
        'stationCount' => $stationCount,
        'priceCount' => $priceCount
    ];
}

// ============================================================================
// Fuel API Functions (from update_data_streaming.php)
// ============================================================================

function getFuelToken($fuelApiBase) {
    $ch = curl_init($fuelApiBase . '/oauth/token');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'grant_type' => 'client_credentials',
        'client_id' => FUEL_CLIENT_ID,
        'client_secret' => FUEL_CLIENT_SECRET
    ]));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200 || !$response) {
        return null;
    }
    
    $data = json_decode($response, true);
    return $data['access_token'] ?? null;
}

function streamStationsToCsv($fuelApiBase, $token, $csvPath) {
    $file = fopen($csvPath, 'w');
    if (!$file) {
        throw new Exception('Cannot create stations CSV');
    }
    
    $batchNumber = 1;
    $hasMore = true;
    $totalCount = 0;
    
    while ($hasMore) {
        $url = $fuelApiBase . '/pfs?batch-number=' . $batchNumber;
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
            'User-Agent: FuelFinderApp/1.0'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200 || !$response) {
            fclose($file);
            throw new Exception("Failed to fetch stations batch $batchNumber (HTTP $httpCode)");
        }
        
        $data = json_decode($response, true);
        if (empty($data['stations'])) {
            break;
        }
        
        foreach ($data['stations'] as $station) {
            // Extract fields matching schema
            $row = [
                $station['site_id'] ?? '',
                $station['brand'] ?? '',
                $station['address'] ?? '',
                $station['postcode'] ?? '',
                $station['latitude'] ?? 0,
                $station['longitude'] ?? 0,
                json_encode($station['location'] ?? []),
                isset($station['opening_times']) ? json_encode($station['opening_times']) : null,
                isset($station['facilities']) ? json_encode($station['facilities']) : null,
                date('Y-m-d H:i:s')
            ];
            fputcsv($file, $row);
            $totalCount++;
        }
        
        $hasMore = !empty($data['has_more']) && $data['has_more'];
        $batchNumber++;
        
        // Rate limiting
        if ($hasMore) {
            usleep(100000); // 100ms
        }
    }
    
    fclose($file);
    return $totalCount;
}

function streamPricesToCsv($fuelApiBase, $token, $csvPath) {
    $file = fopen($csvPath, 'w');
    if (!$file) {
        throw new Exception('Cannot create prices CSV');
    }
    
    $batchNumber = 1;
    $hasMore = true;
    $totalCount = 0;
    
    while ($hasMore) {
        $url = $fuelApiBase . '/prices?batch-number=' . $batchNumber;
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
            'User-Agent: FuelFinderApp/1.0'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200 || !$response) {
            fclose($file);
            throw new Exception("Failed to fetch prices batch $batchNumber (HTTP $httpCode)");
        }
        
        $data = json_decode($response, true);
        if (empty($data['stations'])) {
            break;
        }
        
        foreach ($data['stations'] as $station) {
            $row = [
                $station['site_id'] ?? '',
                isset($station['prices']) ? json_encode($station['prices']) : '[]',
                date('Y-m-d H:i:s')
            ];
            fputcsv($file, $row);
            $totalCount++;
        }
        
        $hasMore = !empty($data['has_more']) && $data['has_more'];
        $batchNumber++;
        
        if ($hasMore) {
            usleep(100000);
        }
    }
    
    fclose($file);
    return $totalCount;
}

function buildDatabaseWithCli($dbPath, $stationsCsv, $pricesCsv, $scriptDir) {
    // Check SQLite3 is available
    exec('which sqlite3', $output, $returnCode);
    if ($returnCode !== 0) {
        throw new Exception('sqlite3 command not found. Please install SQLite3.');
    }
    
    // Read schema
    $schemaFile = dirname($scriptDir) . '/not_for_website/schema.sql';
    if (!file_exists($schemaFile)) {
        // Try alternative location
        $schemaFile = __DIR__ . '/schema.sql';
    }
    
    if (!file_exists($schemaFile)) {
        throw new Exception('Schema file not found: ' . $schemaFile);
    }
    
    $schema = file_get_contents($schemaFile);
    
    // Create database with schema
    $cmd = 'sqlite3 ' . escapeshellarg($dbPath) . ' 2>&1';
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w']
    ];
    
    $process = proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($process)) {
        throw new Exception('Failed to start sqlite3 process');
    }
    
    fwrite($pipes[0], $schema);
    fclose($pipes[0]);
    
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    
    $exitCode = proc_close($process);
    
    if ($exitCode !== 0) {
        throw new Exception("Schema creation failed: $output");
    }
    
    // Import stations CSV
    $importCmd = sprintf(
        'sqlite3 %s -cmd ".mode csv" -cmd ".import %s stations" "" 2>&1',
        escapeshellarg($dbPath),
        escapeshellarg($stationsCsv)
    );
    exec($importCmd, $output, $exitCode);
    if ($exitCode !== 0) {
        throw new Exception('Failed to import stations: ' . implode("\n", $output));
    }
    
    // Import prices CSV
    $importCmd = sprintf(
        'sqlite3 %s -cmd ".mode csv" -cmd ".import %s fuel_prices" "" 2>&1',
        escapeshellarg($dbPath),
        escapeshellarg($pricesCsv)
    );
    exec($importCmd, $output, $exitCode);
    if ($exitCode !== 0) {
        throw new Exception('Failed to import prices: ' . implode("\n", $output));
    }
}

// ============================================================================
// Deployment
// ============================================================================

function deployToServer($dbPath, $apiKey) {
    logMsg('=== Starting Deployment ===');
    logMsg('Target: ' . DEPLOY_URL);
    logMsg('Database: ' . basename($dbPath) . ' (' . round(filesize($dbPath) / 1024 / 1024, 2) . ' MB)');
    
    $attempt = 0;
    $lastError = '';
    
    while ($attempt < MAX_RETRIES) {
        $attempt++;
        
        if ($attempt > 1) {
            $delay = RETRY_DELAYS[$attempt - 2] ?? 60;
            logMsg("Waiting {$delay}s before retry...");
            sleep($delay);
        }
        
        logMsg("Upload attempt $attempt of " . MAX_RETRIES . "...");
        
        $ch = curl_init(DEPLOY_URL);
        
        $postData = [
            'database' => new CURLFile($dbPath, 'application/x-sqlite3', 'fuel_data.db')
        ];
        
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'X-Deploy-Key: ' . $apiKey
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, UPLOAD_TIMEOUT);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            $lastError = 'cURL error: ' . $curlError;
            logMsg($lastError, 'WARN');
            continue;
        }
        
        if ($httpCode === 0) {
            $lastError = 'Connection failed (timeout or network error)';
            logMsg($lastError, 'WARN');
            continue;
        }
        
        $data = json_decode($response, true);
        
        if ($httpCode === 200 && $data && $data['success']) {
            logMsg('Deployment successful!', 'SUCCESS');
            logMsg('Previous version: ' . ($data['previous_version'] ?? 'unknown'));
            logMsg('New version: ' . ($data['new_version'] ?? 'unknown'));
            logMsg('Server time: ' . ($data['timestamp'] ?? 'unknown'));
            return true;
        }
        
        $lastError = 'HTTP ' . $httpCode . ': ' . ($data['message'] ?? $response);
        logMsg('Failed: ' . $lastError, 'WARN');
        
        // Don't retry on authentication errors (4xx except 408, 429)
        if ($httpCode >= 400 && $httpCode < 500 && $httpCode !== 408 && $httpCode !== 429) {
            logMsg('Client error - not retrying', 'ERROR');
            break;
        }
    }
    
    logMsg("All " . MAX_RETRIES . " attempts failed. Last error: $lastError", 'ERROR');
    return false;
}

// ============================================================================
// Main
// ============================================================================

try {
    logMsg('=== FuelSeeker UK PC Deployment ===');
    logMsg('Script dir: ' . $scriptDir);
    logMsg('Data dir: ' . $dataDir);
    
    // Load API key
    $apiKey = loadApiKey();
    logMsg('API key loaded (' . strlen($apiKey) . ' chars)');
    
    // Build database
    $buildResult = buildDatabase($dataDir, $scriptDir, $tempDir);
    
    // Deploy to server
    $deployed = deployToServer($buildResult['targetVersion'], $apiKey);
    
    if ($deployed) {
        logMsg('=== Deployment Complete ===', 'SUCCESS');
        exit(0);
    } else {
        logMsg('=== Deployment Failed ===', 'ERROR');
        exit(1);
    }
    
} catch (Exception $e) {
    logMsg('ERROR: ' . $e->getMessage(), 'ERROR');
    exit(1);
}
