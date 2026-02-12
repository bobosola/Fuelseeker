<?php
/**
 * Local Fuel API
 * 
 * Serves fuel station data from the local SQLite database.
 * Much faster than querying the remote API.
 */

const DB_PATH = __DIR__ . '/../data/fuel_data.db';

// ============================================================================
// Security: Only allow requests from this site
// ============================================================================
// This API should only be accessible from pages on the same domain.
// We check the Origin/Referer header to block external requests.
// ============================================================================

$allowedOrigins = [
    'https://fuelseeker.net',
    'https://www.fuelseeker.net',
    // Add localhost for development if needed:
     'http://localhost:8085',
     'http://127.0.0.1:8085',
     'https://localhost:8085',
     'https://127.0.0.1:8085',
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$referer = $_SERVER['HTTP_REFERER'] ?? '';

// Check if request is from an allowed page on our site
// This blocks: external sites, direct browser access, curl without headers
$isAllowed = false;
foreach ($allowedOrigins as $allowed) {
    if (strpos($origin, $allowed) === 0 || strpos($referer, $allowed) === 0) {
        $isAllowed = true;
        break;
    }
}

// Block external requests (unless it's an OPTIONS preflight)
if (!$isAllowed && $_SERVER['REQUEST_METHOD'] !== 'OPTIONS') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Access denied. API is for site use only.']);
    exit;
}

// CORS headers - only allow same-origin requests
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: ' . ($origin && $isAllowed ? $origin : 'null'));
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// ============================================================================
// Handle CORS "Preflight" OPTIONS Requests
// ============================================================================
// Browsers automatically send an OPTIONS request BEFORE the actual request
// to check "am I allowed to make this call?" This is called a "preflight".
// 
// Example flow:
// 1. Browser wants to POST to this API
// 2. Browser first sends OPTIONS request (asking for permission)
// 3. This code responds with HTTP 200 ("yes, you're allowed")
// 4. Browser then sends the actual POST request
// 5. The actual API logic runs and returns data
//
// Without this, the browser would get confused and block the request.
// ============================================================================

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);  // Return "OK" - permission granted
    exit();                   // Stop here - don't run the actual API code
}

// Check if database exists
if (!file_exists(DB_PATH)) {
    http_response_code(503);
    echo json_encode(['error' => 'Database not initialized. Run update_data.php first.']);
    exit();
}

$action = $_GET['action'] ?? '';

try {
    $db = new PDO('sqlite:' . DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    switch ($action) {
        case 'search':
            searchStations($db);
            break;
            
        case 'nearby':
            getNearbyStations($db);
            break;
            
        case 'status':
            getStatus($db);
            break;
            
        default:
            // Invalid action - return 200 but with error message (avoids Caddy's error page)
            header('Content-Type: application/json');
            $response = json_encode(['error' => 'Invalid action. Use: search, nearby, or status']);
            if ($response === false) {
                echo '{"error":"JSON encoding failed"}';
            } else {
                echo $response;
            }
            exit;
    }
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => $e->getMessage()]);
}

/**
 * Search stations by name, postcode, or location
 */
function searchStations($db) {
    $query = $_GET['q'] ?? '';
    $limit = intval($_GET['limit'] ?? 50);
    
    if (empty($query)) {
        http_response_code(400);
        echo json_encode(['error' => 'Query parameter required']);
        return;
    }
    
    // Search by postcode or name
    $stmt = $db->prepare("
        SELECT s.*, p.fuel_prices 
        FROM stations s
        LEFT JOIN fuel_prices p ON s.node_id = p.node_id
        WHERE (s.postcode LIKE :query 
               OR s.trading_name LIKE :query 
               OR s.brand_name LIKE :query
               OR s.city LIKE :query)
          AND s.permanent_closure = 0
        ORDER BY s.trading_name
        LIMIT :limit
    ");
    
    $searchTerm = '%' . $query . '%';
    $stmt->bindValue(':query', $searchTerm, PDO::PARAM_STR);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    
    $stations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format results to match original API format
    $results = array_map('formatStation', $stations);
    
    echo json_encode($results);
}

/**
 * Get stations near a specific lat/lng coordinate
 */
function getNearbyStations($db) {
    $lat = floatval($_GET['lat'] ?? 0);
    $lng = floatval($_GET['lng'] ?? 0);
    $radius = floatval($_GET['radius'] ?? 5); // miles
    $limit = intval($_GET['limit'] ?? 100);
    
    if ($lat === 0 || $lng === 0) {
        http_response_code(400);
        echo json_encode(['error' => 'lat and lng parameters required']);
        return;
    }
    
    // Approximate degree conversion (rough for UK)
    $latDelta = $radius / 69.0; // 1 degree lat ≈ 69 miles
    $lngDelta = $radius / (69.0 * cos(deg2rad($lat)));
    
    $minLat = $lat - $latDelta;
    $maxLat = $lat + $latDelta;
    $minLng = $lng - $lngDelta;
    $maxLng = $lng + $lngDelta;
    
    // Get stations within bounding box, then calculate exact distance
    $stmt = $db->prepare("
        SELECT s.*, p.fuel_prices 
        FROM stations s
        LEFT JOIN fuel_prices p ON s.node_id = p.node_id
        WHERE s.latitude BETWEEN :minLat AND :maxLat
          AND s.longitude BETWEEN :minLng AND :maxLng
          AND s.permanent_closure = 0
          AND s.temporary_closure = 0
    ");
    
    $stmt->execute([
        ':minLat' => $minLat,
        ':maxLat' => $maxLat,
        ':minLng' => $minLng,
        ':maxLng' => $maxLng
    ]);
    
    $stations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate exact distance and filter
    $results = [];
    foreach ($stations as $station) {
        $distance = haversineDistance($lat, $lng, $station['latitude'], $station['longitude']);
        if ($distance <= $radius) {
            $station['distance'] = $distance;
            $results[] = formatStation($station);
        }
    }
    
    // Sort by distance
    usort($results, function($a, $b) {
        return $a['distance'] <=> $b['distance'];
    });
    
    // Limit results
    $results = array_slice($results, 0, $limit);
    
    echo json_encode($results);
}

/**
 * Get database status
 */
function getStatus($db) {
    $stationCount = $db->query("SELECT COUNT(*) FROM stations WHERE permanent_closure = 0")->fetchColumn();
    $priceCount = $db->query("SELECT COUNT(*) FROM fuel_prices")->fetchColumn();
    
    $lastUpdate = $db->query("SELECT value FROM cache_metadata WHERE key = 'last_update'")->fetchColumn();
    
    // Get file modification times of both database files
    $dataDir = dirname(DB_PATH);
    $v1File = $dataDir . '/fuel_data.db.v1';
    $v2File = $dataDir . '/fuel_data.db.v2';
    
    $v1Time = file_exists($v1File) ? filemtime($v1File) : 0;
    $v2Time = file_exists($v2File) ? filemtime($v2File) : 0;
    
    // Use the most recent file modification time
    $fileTime = max($v1Time, $v2Time);
    
    // Format as ISO date for consistency with database timestamp
    if ($fileTime > 0) {
        $fileUpdate = date('Y-m-d H:i:s', $fileTime);
        // Use file time if it's more recent than the metadata timestamp
        if (!$lastUpdate || $fileTime > strtotime($lastUpdate)) {
            $lastUpdate = $fileUpdate;
        }
    }
    
    echo json_encode([
        'total_stations' => intval($stationCount),
        'stations_with_prices' => intval($priceCount),
        'last_update' => $lastUpdate,
        'status' => 'ok'
    ]);
}

/**
 * Format station data to match original API structure
 */
function formatStation($row) {
    return [
        'node_id' => $row['node_id'],
        'mft_organisation_name' => $row['mft_organisation_name'],
        'public_phone_number' => $row['public_phone_number'],
        'trading_name' => $row['trading_name'],
        'brand_name' => $row['brand_name'],
        'temporary_closure' => (bool)$row['temporary_closure'],
        'permanent_closure' => (bool)$row['permanent_closure'],
        'is_motorway_service_station' => (bool)$row['is_motorway_service_station'],
        'is_supermarket_service_station' => (bool)$row['is_supermarket_service_station'],
        'location' => [
            'address_line_1' => $row['address_line_1'],
            'address_line_2' => $row['address_line_2'],
            'city' => $row['city'],
            'country' => $row['country'],
            'county' => $row['county'],
            'postcode' => $row['postcode'],
            'latitude' => $row['latitude'],
            'longitude' => $row['longitude']
        ],
        'amenities' => json_decode($row['amenities'] ?? '[]', true),
        'opening_times' => json_decode($row['opening_times'] ?? 'null', true),
        'fuel_types' => json_decode($row['fuel_types'] ?? '[]', true),
        'fuel_prices' => json_decode($row['fuel_prices'] ?? '[]', true),
        'distance' => isset($row['distance']) ? round($row['distance'], 2) : null
    ];
}

/**
 * Calculate distance between two lat/lng points using Haversine formula
 */
function haversineDistance($lat1, $lng1, $lat2, $lng2) {
    $R = 3959; // Earth's radius in miles
    
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    
    $a = sin($dLat / 2) * sin($dLat / 2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLng / 2) * sin($dLng / 2);
    
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    
    return $R * $c;
}
