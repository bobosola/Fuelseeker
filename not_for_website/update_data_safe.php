<?php
/**
 * Fuel Data Update Script - Safe Version with Backup
 * 
 * This script downloads all fuel station data from the gov.uk API
 * and stores it locally in a SQLite database with backup/restore safety.
 */

// Security: Only allow CLI access
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo json_encode(['error' => 'This script can only be run from the command line']);
    exit(1);
}

require_once '../scripts/config.php';

// Validate that all required environment variables are set
try {
    validateConfig();
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

const FUEL_API_BASE = 'https://www.fuel-finder.service.gov.uk/api/v1';
const DATA_DIR = __DIR__;
const DB_PATH = DATA_DIR . '/fuel_data.db';
const BACKUP_PATH = DATA_DIR . '/fuel_data.db.backup';
const LOCK_FILE = DATA_DIR . '/update.lock';

// Ensure data directory exists
if (!is_dir(DATA_DIR)) {
    mkdir(DATA_DIR, 0755, true);
}

// Prevent concurrent runs
if (file_exists(LOCK_FILE)) {
    $lockTime = filemtime($LOCK_FILE);
    if (time() - $lockTime < 3600) { // 1 hour timeout
        echo "Update already in progress (lock file exists)\n";
        exit(1);
    }
    unlink(LOCK_FILE);
}

touch(LOCK_FILE);

// Track if we need to restore backup
$restoreNeeded = false;

function cleanup() {
    global $LOCK_FILE, $restoreNeeded, $BACKUP_PATH, $DB_PATH;
    
    // Remove lock file
    if (file_exists($LOCK_FILE)) {
        unlink($LOCK_FILE);
    }
    
    // Restore backup if update failed
    if ($restoreNeeded && file_exists($BACKUP_PATH)) {
        echo "Restoring backup database...\n";
        copy($BACKUP_PATH, $DB_PATH);
        echo "Backup restored.\n";
    }
    
    // Clean up backup file
    if (file_exists($BACKUP_PATH)) {
        unlink($BACKUP_PATH);
    }
}

// Register cleanup function
register_shutdown_function('cleanup');

try {
    updateDataSafe();
    echo "Update completed successfully\n";
    $restoreNeeded = false; // Don't restore on success
    exit(0);
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    file_put_contents(DATA_DIR . '/update_error.log', date('Y-m-d H:i:s') . ' - ' . $e->getMessage() . "\n", FILE_APPEND);
    $restoreNeeded = true; // Trigger restore
    exit(1);
}

function updateDataSafe() {
    global $DB_PATH, $BACKUP_PATH, $restoreNeeded;
    
    echo "Starting fuel data update (safe mode)...\n";
    
    // Step 1: Create backup of existing database
    if (file_exists($DB_PATH)) {
        echo "Creating backup of existing database...\n";
        if (!copy($DB_PATH, $BACKUP_PATH)) {
            throw new Exception('Failed to create database backup');
        }
        $restoreNeeded = true; // Mark that we may need to restore
        echo "Backup created: " . BACKUP_PATH . "\n";
    }
    
    // Step 2: Download all data first (before touching database)
    echo "Getting OAuth token...\n";
    $token = getFuelToken();
    if (!$token) {
        throw new Exception('Failed to get OAuth token');
    }
    echo "Got token\n";
    
    echo "Downloading stations...\n";
    $stations = fetchAllStations($token);
    echo "Downloaded " . count($stations) . " stations\n";
    
    echo "Downloading fuel prices...\n";
    $prices = fetchAllFuelPrices($token);
    echo "Downloaded prices for " . count($prices) . " stations\n";
    
    // Step 3: Update database with transaction
    echo "Updating database...\n";
    
    $db = new PDO('sqlite:' . $DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Create tables (this is idempotent - safe to run multiple times)
    $schema = file_get_contents(__DIR__ . '/schema.sql');
    $db->exec($schema);
    
    // Begin transaction for all inserts
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
        
        // Create price lookup map
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
        
        // Update metadata
        $metaStmt = $db->prepare("INSERT OR REPLACE INTO cache_metadata (key, value, updated_at) VALUES ('last_update', :value, CURRENT_TIMESTAMP)");
        $metaStmt->execute([':value' => date('Y-m-d H:i:s')]);
        
        // Commit all changes
        $db->commit();
        
        echo "Database update complete. Total stations: $count\n";
        
    } catch (Exception $e) {
        // Rollback on error
        $db->rollBack();
        throw $e;
    }
}

// Include the same helper functions from original
function getFuelToken() {
    $postData = json_encode([
        'client_id' => FUEL_CLIENT_ID,
        'client_secret' => FUEL_CLIENT_SECRET
    ]);
    
    $ch = curl_init(FUEL_API_BASE . '/oauth/generate_access_token');
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
    $allStations = [];
    $batchNumber = 1;
    $hasMore = true;
    
    while ($hasMore) {
        $url = FUEL_API_BASE . '/pfs?batch-number=' . $batchNumber;
        
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
    $allPrices = [];
    $batchNumber = 1;
    $hasMore = true;
    
    while ($hasMore) {
        $url = FUEL_API_BASE . '/pfs/fuel-prices?batch-number=' . $batchNumber;
        
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
