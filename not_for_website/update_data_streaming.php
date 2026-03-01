<?php
/**
 * Fuel Data Update Script - Streaming Version (Minimal Memory Usage)
 * 
 * Writes data directly to CSV during API fetch to avoid memory exhaustion.
 * Uses streaming approach with bounded memory usage.
 */

// Security: Only allow CLI access
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo json_encode(['error' => 'This script can only be run from the command line']);
    exit(1);
}

// ============================================================================
// Path Configuration - Auto-detection with Environment Variable Override
// ============================================================================
// Priority:
// 1. Environment variables (FUELSEEKER_SCRIPT_DIR, FUELSEEKER_DATA_DIR)
// 2. Auto-detect based on script location (__DIR__)
// 3. Default hardcoded paths
//
// Usage examples:
//   # Live server (uses paths relative to this script's location)
//   php update_data_streaming.php
//
//   # Local development with custom paths
//   FUELSEEKER_SCRIPT_DIR=/custom/scripts FUELSEEKER_DATA_DIR=/custom/data php update_data_streaming.php
// ============================================================================

function detectPaths() {
    // 1. Check environment variables first (highest priority)
    $envScriptDir = getenv('FUELSEEKER_SCRIPT_DIR');
    $envDataDir = getenv('FUELSEEKER_DATA_DIR');
    
    if ($envScriptDir && $envDataDir) {
        return [
            'scriptDir' => rtrim($envScriptDir, '/'),
            'dataDir' => rtrim($envDataDir, '/')
        ];
    }
    
    // 2. Auto-detect based on this script's location
    // This script is in: /path/to/project/not_for_website/
    // We need to go up one level to find scripts/ and data/
    $thisDir = __DIR__;
    $baseDir = dirname($thisDir); // Parent of not_for_website/
    
    $autoScriptDir = $baseDir . '/scripts';
    $autoDataDir = $baseDir . '/data';
    
    // Verify the auto-detected paths exist
    if (is_dir($autoScriptDir) && is_dir($autoDataDir)) {
        return [
            'scriptDir' => $autoScriptDir,
            'dataDir' => $autoDataDir
        ];
    }
    
    // 3. Fallback to hardcoded paths (for backward compatibility)
    // Local development paths
    return [
        'scriptDir' => '/Users/bobosola/Sites/fuelseeker.net/scripts',
        'dataDir' => '/Users/bobosola/Sites/fuelseeker.net/data'
    ];
}

$paths = detectPaths();
$scriptDir = $paths['scriptDir'];
$dataDir = $paths['dataDir'];
$tempDir = $dataDir . '/tmp';

// Log which paths are being used
$isEnv = getenv('FUELSEEKER_SCRIPT_DIR') && getenv('FUELSEEKER_DATA_DIR');
$isAuto = strpos($scriptDir, '/not_for_website') === false && $scriptDir === dirname(__DIR__) . '/scripts';

if ($isEnv) {
    echo "[INFO] Using environment variable paths\n";
} elseif ($isAuto) {
    echo "[INFO] Using auto-detected paths from script location\n";
} else {
    echo "[INFO] Using fallback default paths\n";
}
echo "[INFO] Script directory: $scriptDir\n";
echo "[INFO] Data directory: $dataDir\n\n";

require_once $scriptDir . '/config.php';

try {
    validateConfig();
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

$fuelApiBase = 'https://www.fuel-finder.service.gov.uk/api/v1';

// Symlink setup: fuel_data.db -> fuel_data.db.v1 (or v2)
$symlinkPath = $dataDir . '/fuel_data.db';
$versionA = $dataDir . '/fuel_data.db.v1';
$versionB = $dataDir . '/fuel_data.db.v2';
$lockFile = $dataDir . '/update.lock';

// Prevent concurrent runs
if (file_exists($lockFile)) {
    $lockAge = time() - filemtime($lockFile);
    if ($lockAge < 3600) {
        echo "Update already in progress (lock file exists, age: {$lockAge}s)\n";
        exit(1);
    }
    unlink($lockFile);
}
touch($lockFile);

function cleanup($lockFile, $tempDir) {
    if (file_exists($lockFile)) {
        unlink($lockFile);
    }
    // Clean up temp files
    foreach (glob($tempDir . '/*.csv') as $f) {
        @unlink($f);
    }
}
register_shutdown_function('cleanup', $lockFile, $tempDir);

// Helper to flush output immediately
function logMsg($msg) {
    echo "[" . date('H:i:s') . "] $msg\n";
    flush();
}

try {
    logMsg("=== Starting Fuel Data Update (Streaming Method) ===");
    logMsg("Memory limit: " . ini_get('memory_limit'));
    
    // Ensure temp directory exists
    if (!is_dir($tempDir)) {
        mkdir($tempDir, 0755, true);
    }
    
    // Clean old temp files
    foreach (glob($tempDir . '/*.csv') as $f) {
        @unlink($f);
    }
    
    // First-time setup: migrate existing database to symlink structure
    if (!file_exists($symlinkPath) && file_exists($dataDir . '/fuel_data.db')) {
        logMsg("First run: migrating to symlink structure...");
        rename($dataDir . '/fuel_data.db', $versionA);
        symlink($versionA, $symlinkPath);
        logMsg("Created symlink: fuel_data.db -> fuel_data.db.v1");
    }
    
    // If symlink doesn't exist at all, create initial structure
    if (!file_exists($symlinkPath)) {
        logMsg("Creating initial database structure...");
        touch($versionA);
        symlink($versionA, $symlinkPath);
    }
    
    // Determine which version to write to (the one NOT currently symlinked)
    $currentTarget = @readlink($symlinkPath);
    if ($currentTarget === false || basename($currentTarget) === 'fuel_data.db.v2') {
        $targetVersion = $versionA;
        $oldVersion = $versionB;
    } else {
        $targetVersion = $versionB;
        $oldVersion = $versionA;
    }
    
    logMsg("Current: " . ($currentTarget ? basename($currentTarget) : 'none'));
    logMsg("Building: " . basename($targetVersion));
    
    // Step 1: Get OAuth token
    logMsg("[1/4] Getting OAuth token...");
    $token = getFuelToken();
    if (!$token) {
        throw new Exception('Failed to get OAuth token - is VPN connected?');
    }
    logMsg("Got token");
    
    // Step 2: Stream stations directly to CSV (NO memory accumulation)
    logMsg("[2/4] Downloading stations to CSV...");
    $stationsCsv = $tempDir . '/stations.csv';
    $stationsFile = fopen($stationsCsv, 'w');
    if (!$stationsFile) {
        throw new Exception('Cannot create stations CSV');
    }
    
    $stationCount = streamStationsToCsv($token, $stationsFile);
    fclose($stationsFile);
    logMsg("Downloaded $stationCount stations to CSV");
    
    // Step 3: Stream prices directly to CSV (NO memory accumulation)
    logMsg("[3/4] Downloading prices to CSV...");
    $pricesCsv = $tempDir . '/prices.csv';
    $pricesFile = fopen($pricesCsv, 'w');
    if (!$pricesFile) {
        throw new Exception('Cannot create prices CSV');
    }
    
    $priceCount = streamPricesToCsv($token, $pricesFile);
    fclose($pricesFile);
    logMsg("Downloaded prices for $priceCount stations to CSV");
    
    // Memory check
    logMsg("Memory used: " . round(memory_get_peak_usage(true) / 1024 / 1024, 2) . " MB");
    
    // Step 4: Build database using SQLite CLI
    logMsg("[4/4] Building database via SQLite CLI...");
    
    // Remove target if it exists
    if (file_exists($targetVersion)) {
        unlink($targetVersion);
    }
    foreach ([$targetVersion . '-wal', $targetVersion . '-shm'] as $wal) {
        if (file_exists($wal)) unlink($wal);
    }
    
    buildDatabaseWithCli($targetVersion, $stationsCsv, $pricesCsv);
    logMsg("Database built successfully");
    
    // Atomic symlink swap
    logMsg("Activating new database...");
    if (file_exists($symlinkPath)) {
        unlink($symlinkPath);
    }
    
    if (!symlink($targetVersion, $symlinkPath)) {
        throw new Exception('Failed to create symlink');
    }
    
    $newTarget = readlink($symlinkPath);
    logMsg("Active database: " . basename($newTarget));
    
    // Clean up temp files
    @unlink($stationsCsv);
    @unlink($pricesCsv);
    
    logMsg("=== Update completed successfully ===");
    exit(0);
    
} catch (Exception $e) {
    logMsg("ERROR: " . $e->getMessage());
    file_put_contents($dataDir . '/update_error.log', date('Y-m-d H:i:s') . ' - ' . $e->getMessage() . "\n", FILE_APPEND);
    exit(1);
}

/**
 * Stream stations directly to CSV file - NO memory accumulation
 */
function streamStationsToCsv($token, $fileHandle) {
    global $fuelApiBase;
    
    $batchNumber = 1;
    $hasMore = true;
    $totalCount = 0;
    
    while ($hasMore) {
        $url = $fuelApiBase . '/pfs?batch-number=' . $batchNumber;
        
        logMsg("  Fetching batch $batchNumber...");
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
            'User-Agent: FuelFinderApp/1.0'
        ]);
        // Longer timeout for slow API responses + retry capability
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_TCP_KEEPALIVE, 1);
        curl_setopt($ch, CURLOPT_TCP_KEEPIDLE, 10);
        curl_setopt($ch, CURLOPT_TCP_KEEPINTVL, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        // Don't wait forever
        curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        // Retry once on timeout or 5xx errors
        if ($httpCode === 0 || $httpCode >= 500) {
            logMsg("  Batch $batchNumber failed ($httpCode), retrying in 2s...");
            sleep(2);
            
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $token,
                'Accept: application/json',
                'User-Agent: FuelFinderApp/1.0'
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
            curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
            curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
        }
        
        if ($httpCode !== 200) {
            throw new Exception("Failed to fetch stations batch $batchNumber: HTTP $httpCode, Error: $curlError");
        }
        
        $data = json_decode($response, true);
        
        // Handle API response wrapper (some endpoints wrap in 'data' key)
        $stations = $data['data'] ?? $data;
        
        if (!is_array($stations) || empty($stations)) {
            $hasMore = false;
        } else {
            // Write directly to file - NO accumulation in memory
            foreach ($stations as $station) {
                $location = $station['location'] ?? [];
                
                $row = [
                    $station['node_id'] ?? '',
                    '', // mft_organisation_name - no longer in API, kept for schema compatibility
                    $station['public_phone_number'] ?? '',
                    $station['trading_name'] ?? '',
                    $station['brand_name'] ?? '',
                    ($station['temporary_closure'] ?? false) ? 1 : 0,
                    ($station['permanent_closure'] ?? false) ? 1 : 0,
                    ($station['is_motorway_service_station'] ?? false) ? 1 : 0,
                    ($station['is_supermarket_service_station'] ?? false) ? 1 : 0,
                    $location['address_line_1'] ?? '',
                    $location['address_line_2'] ?? '',
                    $location['city'] ?? '',
                    $location['country'] ?? '',
                    $location['county'] ?? '',
                    $location['postcode'] ?? '',
                    $location['latitude'] ?? null,
                    $location['longitude'] ?? null,
                    json_encode($station['amenities'] ?? []),
                    json_encode($station['opening_times'] ?? null),
                    json_encode($station['fuel_types'] ?? []),
                    date('Y-m-d H:i:s')
                ];
                
                fputcsv($fileHandle, $row, ',', '"', '');
                $totalCount++;
            }
            
            logMsg("  Batch $batchNumber: " . count($stations) . " stations (total: $totalCount)");
            
            if (count($stations) < 500) {
                $hasMore = false;
            } else {
                $batchNumber++;
                // Shorter delay
                usleep(50000); // 50ms
            }
            
            // Force garbage collection every batch
            if ($batchNumber % 5 === 0) {
                gc_collect_cycles();
            }
        }
    }
    
    return $totalCount;
}

/**
 * Stream prices directly to CSV file - NO memory accumulation
 */
function streamPricesToCsv($token, $fileHandle) {
    global $fuelApiBase;
    
    $batchNumber = 1;
    $hasMore = true;
    $totalCount = 0;
    
    while ($hasMore) {
        $url = $fuelApiBase . '/pfs/fuel-prices?batch-number=' . $batchNumber;
        
        logMsg("  Fetching prices batch $batchNumber...");
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
            'User-Agent: FuelFinderApp/1.0'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
        curl_setopt($ch, CURLOPT_TCP_KEEPALIVE, 1);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        // Retry once on timeout or 5xx errors
        if ($httpCode === 0 || $httpCode >= 500) {
            logMsg("  Prices batch $batchNumber failed ($httpCode), retrying in 2s...");
            sleep(2);
            
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $token,
                'Accept: application/json',
                'User-Agent: FuelFinderApp/1.0'
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
            curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
            curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
        }
        
        if ($httpCode !== 200) {
            throw new Exception("Failed to fetch prices batch $batchNumber: HTTP $httpCode, Error: $curlError");
        }
        
        $data = json_decode($response, true);
        
        // Handle API response wrapper (some endpoints wrap in 'data' key)
        $prices = $data['data'] ?? $data;
        
        if (!is_array($prices) || empty($prices)) {
            $hasMore = false;
        } else {
            // Write directly to file - NO accumulation in memory
            foreach ($prices as $priceData) {
                $row = [
                    $priceData['node_id'] ?? '',
                    json_encode($priceData['fuel_prices'] ?? []),
                    date('Y-m-d H:i:s')
                ];
                
                fputcsv($fileHandle, $row, ',', '"', '');
                $totalCount++;
            }
            
            logMsg("  Prices batch $batchNumber: " . count($prices) . " stations");
            
            if (count($prices) < 500) {
                $hasMore = false;
            } else {
                $batchNumber++;
                usleep(50000); // 50ms
            }
            
            if ($batchNumber % 5 === 0) {
                gc_collect_cycles();
            }
        }
    }
    
    return $totalCount;
}

/**
 * Build database using SQLite CLI
 */
function buildDatabaseWithCli($dbPath, $stationsCsv, $pricesCsv) {
    $schema = file_get_contents(__DIR__ . '/schema.sql');
    
    $sqlFile = dirname($stationsCsv) . '/import.sql';
    $sql = <<<SQL
PRAGMA journal_mode = OFF;
PRAGMA synchronous = OFF;
PRAGMA cache_size = -65536;
PRAGMA temp_store = MEMORY;
PRAGMA locking_mode = EXCLUSIVE;

-- Drop existing tables to avoid conflicts
DROP TABLE IF EXISTS stations;
DROP TABLE IF EXISTS fuel_prices;
DROP TABLE IF EXISTS cache_metadata;

{$schema}

.mode csv
.import '{$stationsCsv}' stations

-- Import prices with duplicate handling
CREATE TEMP TABLE temp_prices(node_id TEXT, fuel_prices TEXT, last_updated TEXT);
.mode csv
.import '{$pricesCsv}' temp_prices

-- Insert only unique prices (latest wins if duplicates)
INSERT INTO fuel_prices(node_id, fuel_prices, last_updated)
SELECT node_id, fuel_prices, last_updated 
FROM temp_prices 
GROUP BY node_id;

DROP TABLE temp_prices;

CREATE INDEX IF NOT EXISTS idx_stations_location ON stations(latitude, longitude);
CREATE INDEX IF NOT EXISTS idx_stations_postcode ON stations(postcode);

INSERT INTO cache_metadata (key, value, updated_at) 
VALUES ('last_update', datetime('now'), CURRENT_TIMESTAMP);

PRAGMA journal_mode = WAL;
PRAGMA synchronous = NORMAL;
SQL;

    file_put_contents($sqlFile, $sql);
    
    // Run SQLite with nice to reduce CPU impact on web server
    $cmd = 'nice -n 15 ' . escapeshellcmd("sqlite3") . ' ' . escapeshellarg($dbPath) . ' < ' . escapeshellarg($sqlFile) . ' 2>&1';
    exec($cmd, $output, $exitCode);
    
    @unlink($sqlFile);
    
    if ($exitCode !== 0) {
        throw new Exception('SQLite CLI import failed: ' . implode("\n", $output));
    }
    
    if (!file_exists($dbPath) || filesize($dbPath) < 1024) {
        throw new Exception('Database file was not created properly');
    }
}

function getFuelToken() {
    global $fuelApiBase;
    
    $postData = json_encode([
        'client_id' => FUEL_CLIENT_ID,
        'client_secret' => FUEL_CLIENT_SECRET
    ]);
    
    $ch = curl_init($fuelApiBase . '/oauth/generate_access_token');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($postData),
        'User-Agent: FuelFinderApp/1.0'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
    curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
    curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        return null;
    }
    
    $data = json_decode($response, true);
    return $data['data']['access_token'] ?? null;
}
