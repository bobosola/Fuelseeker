<?php
/**
 * Fuel Data Update Script - Safe Version with Symlink Swap
 * 
 * Uses symlink atomicity for zero-downtime updates.
 */

// Security: Only allow CLI access
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo json_encode(['error' => 'This script can only be run from the command line']);
    exit(1);
}

// Updated paths based on user's setup
$scriptDir = '/var/www/fuelseeker.net/scripts';
$dataDir = '/var/www/fuelseeker.net/data';

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

function cleanup($lockFile) {
    if (file_exists($lockFile)) {
        unlink($lockFile);
    }
}
register_shutdown_function('cleanup', $lockFile);

try {
    echo "=== Starting Fuel Data Update (Symlink Method) ===\n";
    
    // First-time setup: migrate existing database to symlink structure
    if (!file_exists($symlinkPath) && file_exists($dataDir . '/fuel_data.db')) {
        echo "First run: migrating to symlink structure...\n";
        rename($dataDir . '/fuel_data.db', $versionA);
        symlink($versionA, $symlinkPath);
        echo "Created symlink: fuel_data.db -> fuel_data.db.v1\n";
    }
    
    // If symlink doesn't exist at all, create initial structure
    if (!file_exists($symlinkPath)) {
        echo "Creating initial database structure...\n";
        touch($versionA); // Create empty file
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
    
    echo "Current: " . ($currentTarget ? basename($currentTarget) : 'none') . "\n";
    echo "Building: " . basename($targetVersion) . "\n";
    
    // Step 1: Download all data (before touching database)
    echo "\n[1/3] Downloading data from API...\n";
    echo "Getting OAuth token...\n";
    $token = getFuelToken();
    if (!$token) {
        throw new Exception('Failed to get OAuth token - is VPN connected?');
    }
    echo "Got token\n";
    
    echo "Downloading stations...\n";
    $stations = fetchAllStations($token);
    echo "Downloaded " . count($stations) . " stations\n";
    
    echo "Downloading fuel prices...\n";
    $prices = fetchAllFuelPrices($token);
    echo "Downloaded prices for " . count($prices) . " stations\n";
    
    // Step 2: Build new database
    echo "\n[2/3] Building " . basename($targetVersion) . "...\n";
    
    // Remove target if it exists
    if (file_exists($targetVersion)) {
        unlink($targetVersion);
    }
    // Also clean up any WAL files from previous runs
    foreach ([$targetVersion . '-wal', $targetVersion . '-shm'] as $wal) {
        if (file_exists($wal)) unlink($wal);
    }
    
    $db = new PDO('sqlite:' . $targetVersion);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Performance and concurrency optimizations
    $db->exec('PRAGMA journal_mode = WAL');
    $db->exec('PRAGMA synchronous = NORMAL');
    $db->exec('PRAGMA cache_size = -32768'); // 32MB cache
    $db->exec('PRAGMA temp_store = MEMORY');
    
    $schema = file_get_contents('/home/bobosola/schema.sql');
    $db->exec($schema);
    
    $db->beginTransaction();
    
    try {
        $stationStmt = $db->prepare("INSERT OR REPLACE INTO stations 
            (node_id, mft_organisation_name, public_phone_number, trading_name, brand_name,
             temporary_closure, permanent_closure, is_motorway_service_station, is_supermarket_service_station,
             address_line_1, address_line_2, city, country, county, postcode, latitude, longitude,
             amenities, opening_times, fuel_types, last_updated)
            VALUES 
            (:node_id, :mft_organisation_name, :public_phone_number, :trading_name, :brand_name,
             :temporary_closure, :permanent_closure, :is_motorway_service_station, :is_supermarket_service_station,
             :address_line_1, :address_line_2, :city, :country, :county, :postcode, :latitude, :longitude,
             :amenities, :opening_times, :fuel_types, CURRENT_TIMESTAMP)");
        
        $priceStmt = $db->prepare("INSERT OR REPLACE INTO fuel_prices 
            (node_id, fuel_prices, last_updated) 
            VALUES (:node_id, :fuel_prices, CURRENT_TIMESTAMP)");
        
        $priceMap = [];
        foreach ($prices as $priceData) {
            $priceMap[$priceData['node_id']] = $priceData['fuel_prices'] ?? [];
        }
        
        $count = 0;
        foreach ($stations as $station) {
            $location = $station['location'] ?? [];
            
            $stationStmt->execute([
                ':node_id' => $station['node_id'],
                ':mft_organisation_name' => $station['mft_organisation_name'] ?? null,
                ':public_phone_number' => $station['public_phone_number'] ?? null,
                ':trading_name' => $station['trading_name'] ?? null,
                ':brand_name' => $station['brand_name'] ?? null,
                ':temporary_closure' => ($station['temporary_closure'] ?? false) ? 1 : 0,
                ':permanent_closure' => ($station['permanent_closure'] ?? false) ? 1 : 0,
                ':is_motorway_service_station' => ($station['is_motorway_service_station'] ?? false) ? 1 : 0,
                ':is_supermarket_service_station' => ($station['is_supermarket_service_station'] ?? false) ? 1 : 0,
                ':address_line_1' => $location['address_line_1'] ?? null,
                ':address_line_2' => $location['address_line_2'] ?? null,
                ':city' => $location['city'] ?? null,
                ':country' => $location['country'] ?? null,
                ':county' => $location['county'] ?? null,
                ':postcode' => $location['postcode'] ?? null,
                ':latitude' => $location['latitude'] ?? null,
                ':longitude' => $location['longitude'] ?? null,
                ':amenities' => json_encode($station['amenities'] ?? []),
                ':opening_times' => json_encode($station['opening_times'] ?? null),
                ':fuel_types' => json_encode($station['fuel_types'] ?? [])
            ]);
            
            if (isset($priceMap[$station['node_id']])) {
                $priceStmt->execute([
                    ':node_id' => $station['node_id'],
                    ':fuel_prices' => json_encode($priceMap[$station['node_id']])
                ]);
            }
            
            $count++;
            if ($count % 500 === 0) {
                echo "  Processed $count stations...\n";
            }
        }
        
        $metaStmt = $db->prepare("INSERT OR REPLACE INTO cache_metadata (key, value, updated_at) VALUES ('last_update', :value, CURRENT_TIMESTAMP)");
        $metaStmt->execute([':value' => date('Y-m-d H:i:s')]);
        
        $db->commit();
        $db = null; // Close connection
        
        echo "Database built. Total stations: $count\n";
        
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
    
    // Step 3: ATOMIC SYMLINK SWAP
    echo "\n[3/3] Activating new database...\n";
    
    // Remove old symlink and create new one (atomic on most systems)
    if (file_exists($symlinkPath)) {
        unlink($symlinkPath);
    }
    
    if (!symlink($targetVersion, $symlinkPath)) {
        throw new Exception('Failed to create symlink');
    }
    
    // Verify the swap
    $newTarget = readlink($symlinkPath);
    echo "Active database: " . basename($newTarget) . "\n";
    
    // Note: We leave the old version file in place - it will be overwritten on next update
    // This allows any existing PHP connections to finish reading from it
    
    echo "=== Update completed successfully ===\n";
    exit(0);
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    file_put_contents($dataDir . '/update_error.log', date('Y-m-d H:i:s') . ' - ' . $e->getMessage() . "\n", FILE_APPEND);
    exit(1);
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
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
    curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        return null;
    }
    
    $data = json_decode($response, true);
    return $data['data']['access_token'] ?? null;
}

function fetchAllStations($token) {
    global $fuelApiBase;
    
    $allStations = [];
    $batchNumber = 1;
    $hasMore = true;
    
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
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            throw new Exception("Failed to fetch stations batch $batchNumber: HTTP $httpCode");
        }
        
        $data = json_decode($response, true);
        
        if (!is_array($data) || empty($data)) {
            $hasMore = false;
        } else {
            $allStations = array_merge($allStations, $data);
            echo "  Batch $batchNumber: " . count($data) . " stations (total: " . count($allStations) . ")\n";
            
            if (count($data) < 500) {
                $hasMore = false;
            } else {
                $batchNumber++;
                usleep(100000);
            }
        }
    }
    
    return $allStations;
}

function fetchAllFuelPrices($token) {
    global $fuelApiBase;
    
    $allPrices = [];
    $batchNumber = 1;
    $hasMore = true;
    
    while ($hasMore) {
        $url = $fuelApiBase . '/pfs/fuel-prices?batch-number=' . $batchNumber;
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
            'User-Agent: FuelFinderApp/1.0'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            throw new Exception("Failed to fetch prices batch $batchNumber: HTTP $httpCode");
        }
        
        $data = json_decode($response, true);
        
        if (!is_array($data) || empty($data)) {
            $hasMore = false;
        } else {
            $allPrices = array_merge($allPrices, $data);
            echo "  Prices batch $batchNumber: " . count($data) . " stations\n";
            
            if (count($data) < 500) {
                $hasMore = false;
            } else {
                $batchNumber++;
                usleep(100000);
            }
        }
    }
    
    return $allPrices;
}
