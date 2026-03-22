<?php
/**
 * FuelSeeker UK PC Deployment Script
 * 
 * Builds fuel database locally and deploys to Hetzner server via HTTPS.
 * Designed to run on a UK-based PC (no VPN needed for Fuel Finder API).
 * 
 * Usage:
 *   php deploy_to_hetzner.php
 */

// Security: Only allow CLI access
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo json_encode(['error' => 'This script can only be run from the command line']);
    exit(1);
}

require_once 'config.php';

// ============================================================================
// Configuration
// ============================================================================

// DEPLOY_URL is now defined in config.php from .env file
// Falls back to default if not set in .env

// Retry configuration
const MAX_RETRIES = 3;
const RETRY_DELAYS = [5, 15, 45]; // Seconds between retries (exponential backoff)

// cURL timeout (seconds) - very generous for UK broadband upload
// A 13MB file at 1 Mbps upload = ~104 seconds, plus overhead
// With gzip compression, ~4-5MB at 1 Mbps = ~32-40 seconds
const UPLOAD_TIMEOUT = 300; // 5 minutes

// Note: USE_GZIP constant is defined in config.php from .secrets file

// ============================================================================
// Path Configuration 
// ============================================================================

$baseDir = dirname(__FILE__);

// Ensure data directory exists
$dataDir = $baseDir . '/data';
if (!is_dir($dataDir)) {
    @mkdir($dataDir, 0755, true);
}

// Ensure temp directory exists
$tempDir = $dataDir . '/tmp';
if (!is_dir($tempDir)) {
    mkdir($tempDir, 0755, true);
}

// Ensure logs directory exists
$logsDir = $dataDir . '/logs';
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
// Database Building (Reuses streaming update logic)
// ============================================================================

// Global cleanup function for buildDatabase (must be declared at global scope)
function buildCleanup($lockFile) {
    if (file_exists($lockFile)) {
        unlink($lockFile);
    }
}

function buildDatabase($dataDir, $tempDir) {
    $fuelApiBase = 'https://www.fuel-finder.service.gov.uk/api/v1';
    $dbPath = $dataDir . '/fuel_data.db.new';
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
    
    register_shutdown_function('buildCleanup', $lockFile);
    
    logMsg('=== Starting Database Build ===');
    
    // Clean old temp files
    foreach (glob($tempDir . '/*.csv') as $f) {
        @unlink($f);
    }
    
    // Remove old database file if exists
    if (file_exists($dbPath)) {
        unlink($dbPath);
    }
    
    // Get OAuth token
    logMsg('[1/4] Getting OAuth token...');
    logMsg('Note: The Fuel Finder API is geo-restricted to UK IP addresses.');
    logMsg('If you get HTTP 403, your IP may be blocked (non-UK location).');
    $token = getFuelToken($fuelApiBase);
    if (!$token) {
        throw new Exception('Failed to get OAuth token from Fuel Finder API. Ensure you are running from a UK IP address.');
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
    
    // Clean up WAL/shm files if they exist
    foreach ([$dbPath . '-wal', $dbPath . '-shm'] as $wal) {
        if (file_exists($wal)) unlink($wal);
    }
    
    buildDatabaseWithCli($dbPath, $stationsCsv, $pricesCsv);
    
    // Get file size for reporting
    $fileSize = filesize($dbPath);
    $sizeMB = round($fileSize / 1024 / 1024, 2);
    logMsg("Database built: {$sizeMB} MB");
    
    // Clean up CSV files
    @unlink($stationsCsv);
    @unlink($pricesCsv);
    
    buildCleanup($lockFile);
    
    return [
        'dbPath' => $dbPath,
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
    $ch = curl_init($fuelApiBase . '/oauth/generate_access_token');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'client_id' => FUEL_CLIENT_ID,
        'client_secret' => FUEL_CLIENT_SECRET
    ]));
    
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'User-Agent: FuelFinderApp/1.0'
    ]);
    
    // Enable following redirects and keep headers on redirect
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
    
    // Enable compression
    curl_setopt($ch, CURLOPT_ENCODING, '');
    
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    
    // Debug: log request details
    logMsg('OAuth request to: ' . $fuelApiBase . '/oauth/generate_access_token');
    logMsg('Client ID: ' . substr(FUEL_CLIENT_ID, 0, 8) . '...');
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    
    if ($curlError) {
        logMsg("cURL error: $curlError", 'ERROR');
        return null;
    }
    
    if ($httpCode !== 200 || !$response) {
        logMsg("Bad OAuth attempt. HTTP $httpCode URL: $effectiveUrl", 'ERROR');
        logMsg("Response preview: " . substr($response, 0, 500), 'ERROR');
        return null;
    }
    
    $data = json_decode($response, true);
    
    // Debug: log raw response structure
    logMsg('OAuth response structure: ' . print_r(array_keys($data ?? []), true));
    
    // The API returns nested structure: data.data.access_token
    $token = $data['data']['data']['access_token'] ?? $data['data']['access_token'] ?? $data['access_token'] ?? null;
    
    if ($token) {
        logMsg('Token received: ' . substr($token, 0, 20) . '...');
    }
    
    return $token;
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
        
        // Set headers to make request look like a legitimate browser/API client
        // CloudFront may block requests without proper headers
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept-Language: en-GB,en-US;q=0.9,en;q=0.8',
            'Accept-Encoding: gzip, deflate, br',
            'Origin: https://www.fuel-finder.service.gov.uk',
            'Referer: https://www.fuel-finder.service.gov.uk/',
            'DNT: 1'
        ]);
        curl_setopt($ch, CURLOPT_ENCODING, ''); // Enable compression
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        // Check for 404 - means no more batches
        if ($httpCode === 404) {
            logMsg("No more station batches (404 at batch $batchNumber)");
            break;
        }
        
        if ($httpCode !== 200 || !$response) {
            fclose($file);
            throw new Exception("Failed to fetch stations batch $batchNumber (HTTP $httpCode)");
        }
        
        $data = json_decode($response, true);
        // The PFS endpoint returns an array directly, not wrapped in 'stations'
        if (empty($data) || !is_array($data)) {
            logMsg("No more station data (empty response at batch $batchNumber)");
            break;
        }
        
        foreach ($data as $station) {
            // Extract location data
            $location = $station['location'] ?? [];
            
            // Extract fuel types from location if available
            $fuelTypes = [];
            if (!empty($location['fuel_types'])) {
                $fuelTypes = $location['fuel_types'];
            }
            
            // Build row matching schema: node_id, mft_organisation_name, public_phone_number, 
            // trading_name, brand_name, temporary_closure, permanent_closure, 
            // is_motorway_service_station, is_supermarket_service_station, 
            // address_line_1, address_line_2, city, country, county, postcode, 
            // latitude, longitude, amenities, opening_times, fuel_types, last_updated
            $row = [
                $station['node_id'] ?? '',                                    // node_id
                $station['mft_organisation_name'] ?? '',                      // mft_organisation_name
                $station['public_phone_number'] ?? '',                        // public_phone_number
                $station['trading_name'] ?? '',                               // trading_name
                $station['brand_name'] ?? '',                                 // brand_name
                ($station['temporary_closure'] ?? false) ? 1 : 0,             // temporary_closure
                ($station['permanent_closure'] ?? false) ? 1 : 0,             // permanent_closure
                ($station['is_motorway_service_station'] ?? false) ? 1 : 0,   // is_motorway_service_station
                ($station['is_supermarket_service_station'] ?? false) ? 1 : 0,// is_supermarket_service_station
                $location['address_line_1'] ?? '',                            // address_line_1
                $location['address_line_2'] ?? '',                            // address_line_2
                $location['city'] ?? '',                                      // city
                $location['country'] ?? 'United Kingdom',                     // country
                $location['county'] ?? '',                                    // county
                $location['postcode'] ?? '',                                  // postcode
                $location['latitude'] ?? 0,                                   // latitude
                $location['longitude'] ?? 0,                                  // longitude
                isset($station['amenities']) ? json_encode($station['amenities']) : null, // amenities
                isset($station['opening_times']) ? json_encode($station['opening_times']) : null, // opening_times
                !empty($fuelTypes) ? json_encode($fuelTypes) : null,          // fuel_types
                date('Y-m-d H:i:s')                                           // last_updated
            ];
            fputcsv($file, $row, ',', '"', '\\');
            $totalCount++;
        }
        
        // Check for more batches - if we got fewer than 500, this is the last batch
        $hasMore = count($data) >= 500;
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
        $url = $fuelApiBase . '/pfs/fuel-prices?batch-number=' . $batchNumber;
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        // Set headers to make request look like a legitimate browser/API client
        // CloudFront may block requests without proper headers
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept-Language: en-GB,en-US;q=0.9,en;q=0.8',
            'Accept-Encoding: gzip, deflate, br',
            'Origin: https://www.fuel-finder.service.gov.uk',
            'Referer: https://www.fuel-finder.service.gov.uk/',
            'DNT: 1'
        ]);
        curl_setopt($ch, CURLOPT_ENCODING, ''); // Enable compression
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        // Check for 404 - means no more batches
        if ($httpCode === 404) {
            logMsg("No more price batches (404 at batch $batchNumber)");
            break;
        }
        
        if ($httpCode !== 200 || !$response) {
            fclose($file);
            throw new Exception("Failed to fetch prices batch $batchNumber (HTTP $httpCode)");
        }
        
        $data = json_decode($response, true);
        
        // The fuel-prices endpoint returns an array directly, not wrapped in 'stations'
        if (empty($data) || !is_array($data)) {
            logMsg("No more price data (empty response at batch $batchNumber)");
            break;
        }
        
        foreach ($data as $station) {
            $row = [
                $station['node_id'] ?? '',
                isset($station['fuel_prices']) ? json_encode($station['fuel_prices']) : '[]',
                date('Y-m-d H:i:s')
            ];
            fputcsv($file, $row, ',', '"', '\\');
            $totalCount++;
        }
        
        // Check for more batches - if we got fewer than 500, this is the last batch
        $hasMore = count($data) >= 500;
        $batchNumber++;
        
        if ($hasMore) {
            usleep(100000);
        }
    }
    
    fclose($file);
    return $totalCount;
}

function buildDatabaseWithCli($dbPath, $stationsCsv, $pricesCsv) {
    // Check SQLite3 is available
    exec('which sqlite3', $output, $returnCode);
    if ($returnCode !== 0) {
        throw new Exception('sqlite3 command not found. Please install SQLite3.');
    }
    
    // Read schema - look in multiple locations
    $schemaFile = __DIR__ . '/schema.sql';
    if (!file_exists($schemaFile)) {
        // Try parent directory (for development setup)
        $schemaFile = dirname(__DIR__) . '/not_for_website/schema.sql';
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
    
    // Compress if enabled
    $uploadPath = $dbPath;
    $isCompressed = false;
    $originalSize = filesize($dbPath);
    
    if (USE_GZIP && extension_loaded('zlib')) {
        $compressedPath = $dbPath . '.gz';
        logMsg('Compressing database...');
        
        $input = fopen($dbPath, 'rb');
        $output = gzopen($compressedPath, 'wb9'); // Level 9 = max compression
        
        if ($input && $output) {
            while (!feof($input)) {
                gzwrite($output, fread($input, 1024 * 1024)); // 1MB chunks
            }
            fclose($input);
            gzclose($output);
            
            $compressedSize = filesize($compressedPath);
            $savings = round((1 - $compressedSize / $originalSize) * 100, 1);
            logMsg("Compressed: " . round($originalSize / 1024 / 1024, 2) . " MB → " . 
                   round($compressedSize / 1024 / 1024, 2) . " MB ($savings% reduction)");
            
            $uploadPath = $compressedPath;
            $isCompressed = true;
        } else {
            logMsg('Compression failed, using uncompressed file', 'WARN');
            if (file_exists($compressedPath)) {
                unlink($compressedPath);
            }
        }
    }
    
    logMsg('Database: ' . basename($uploadPath) . ' (' . round(filesize($uploadPath) / 1024 / 1024, 2) . ' MB)');
    
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
            'database' => new CURLFile($uploadPath, $isCompressed ? 'application/gzip' : 'application/x-sqlite3', 
                                       $isCompressed ? 'fuel_data.db.gz' : 'fuel_data.db')
        ];
        
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        $headers = [
            'X-Deploy-Key: ' . $apiKey,
            'Expect: ' // Disable Expect: 100-continue header which can cause issues
        ];
        
        if ($isCompressed) {
            $headers[] = 'X-Content-Encoding: gzip';
        }
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        // Timeout settings
        curl_setopt($ch, CURLOPT_TIMEOUT, UPLOAD_TIMEOUT);           // Total timeout
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);                // Connection timeout
        curl_setopt($ch, CURLOPT_LOW_SPEED_TIME, 60);                // If speed drops below 1KB/s for 60s, timeout
        curl_setopt($ch, CURLOPT_LOW_SPEED_LIMIT, 1024);             // 1KB/s minimum speed
        
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        
        // Enable verbose logging for debugging
        // curl_setopt($ch, CURLOPT_VERBOSE, true);
        
        logMsg("Starting upload (timeout: " . UPLOAD_TIMEOUT . "s)...");
        
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
            
            // Clean up compressed file if created
            if ($isCompressed && file_exists($uploadPath)) {
                unlink($uploadPath);
            }
            
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
    
    // Clean up compressed file on failure too
    if ($isCompressed && file_exists($uploadPath)) {
        unlink($uploadPath);
    }
    
    logMsg("All " . MAX_RETRIES . " attempts failed. Last error: $lastError", 'ERROR');
    return false;
}

// ============================================================================
// Main
// ============================================================================

try {
    logMsg('=== FuelSeeker UK PC Deployment ===');
    logMsg('Data dir: ' . $dataDir);
    
    // Load and validate API credentials
    if (!defined('FUEL_CLIENT_ID') || empty(FUEL_CLIENT_ID)) {
        throw new Exception('FUEL_CLIENT_ID not configured. Please check your .env file.');
    }
    if (!defined('FUEL_CLIENT_SECRET') || empty(FUEL_CLIENT_SECRET)) {
        throw new Exception('FUEL_CLIENT_SECRET not configured. Please check your .env file.');
    }
    if (!defined('DEPLOY_API_KEY') || empty(DEPLOY_API_KEY)) {
        throw new Exception('DEPLOY_API_KEY not configured. Please check your .env file.');
    }
    
    $apiKey = DEPLOY_API_KEY;
    logMsg('API credentials loaded successfully');
    logMsg('Deploy API key: ' . strlen($apiKey) . ' chars');
    
    // Build database
    $buildResult = buildDatabase($dataDir, $tempDir);
    
    // Deploy to server
    $deployed = deployToServer($buildResult['dbPath'], $apiKey);
    
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
