<?php
/**
 * Fuel API Proxy
 * Proxies requests to the Fuel Finder API to avoid CORS issues
 */

session_start();

require_once __DIR__ . '/config.php';
const FUEL_API_BASE = 'https://www.fuel-finder.service.gov.uk/api/v1';

$allowedHosts = ['localhost', '127.0.0.1', 'fuelseeker.net', 'www.fuelseeker.net'];

function getAllHeadersSafe() {
    if (function_exists('getallheaders')) {
        return getallheaders();
    }
    
    $headers = array();
    foreach ($_SERVER as $name => $value) {
        if (substr($name, 0, 5) == 'HTTP_') {
            $headerName = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
            $headers[$headerName] = $value;
        }
    }
    return $headers;
}

function isAllowedHost($referer, $allowedHosts) {
    if (empty($referer)) {
        return false;
    }
    foreach ($allowedHosts as $host) {
        if (strpos($referer, $host) !== false) {
            return true;
        }
    }
    return false;
}

function getCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCsrfToken() {
    $headers = getAllHeadersSafe();
    $receivedToken = isset($headers['X-CSRF-Token']) ? $headers['X-CSRF-Token'] : 
                     (isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? $_SERVER['HTTP_X_CSRF_TOKEN'] : '');
    
    if (empty($receivedToken) || empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $receivedToken);
}

function getFuelToken() {
    $postData = json_encode(array(
        'client_id' => FUEL_CLIENT_ID,
        'client_secret' => FUEL_CLIENT_SECRET
    ));
    
    $ch = curl_init(FUEL_API_BASE . '/oauth/generate_access_token');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        'Content-Length: ' . strlen($postData),
        'User-Agent: FuelFinderApp/1.0'
    ));
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
    curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1); // Force IPv4
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        return null;
    }
    
    $data = json_decode($response, true);
    return $data['data']['access_token'] ?? null;
}

// ============================================================================
// CORS (Cross-Origin Resource Sharing) Headers
// ============================================================================
// Browsers block requests between different origins (domains) for security.
// These headers tell the browser "it's OK to accept responses from this server"
// even if the request came from a different domain.
//
// Access-Control-Allow-Origin: *  = Allow any website to call this API
// Access-Control-Allow-Methods:   = Which HTTP methods are allowed (GET, POST, etc)
// Access-Control-Allow-Headers:   = Which custom headers are allowed
//
// Note: For this Fuel Finder app, the frontend and API are on the same origin
// (localhost:8085), so these aren't strictly needed. But they're included for
// future flexibility (e.g., if the API moves to a different subdomain).
// ============================================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');   // Allow requests from any origin
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');  // Allowed HTTP methods
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');  // Allowed custom headers

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

// Validate request - check both Referer and Origin headers
// Allow empty referrer for local testing
$referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';

$isLocal = in_array($_SERVER['SERVER_NAME'], ['localhost', '127.0.0.1']);
$hasValidOrigin = !empty($origin) && isAllowedHost($origin, $allowedHosts);
$hasValidReferer = !empty($referer) && isAllowedHost($referer, $allowedHosts);

// Allow if: local development, or has valid origin, or has valid referer
if (!$isLocal && !$hasValidOrigin && !$hasValidReferer) {
    http_response_code(403);
    echo json_encode(array('error' => 'Forbidden: Invalid referrer/origin'));
    exit();
}

// Parse the endpoint from the request
$endpoint = isset($_GET['endpoint']) ? $_GET['endpoint'] : '';
$batchNumber = isset($_GET['batch']) ? intval($_GET['batch']) : 1;

if (!in_array($endpoint, array('stations', 'prices'))) {
    http_response_code(400);
    echo json_encode(array('error' => 'Invalid endpoint'));
    exit();
}

// Get access token
$token = getFuelToken();
if (!$token) {
    http_response_code(500);
    echo json_encode(array('error' => 'Failed to get access token'));
    exit();
}

// Build the API URL
if ($endpoint === 'stations') {
    $url = FUEL_API_BASE . '/pfs?batch-number=' . $batchNumber;
} else {
    $url = FUEL_API_BASE . '/pfs/fuel-prices?batch-number=' . $batchNumber;
}

// Make the request
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, array(
    'Authorization: Bearer ' . $token,
    'Accept: application/json',
    'User-Agent: FuelFinderApp/1.0'
));
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($error) {
    http_response_code(500);
    echo json_encode(array('error' => 'Request failed: ' . $error));
    exit();
}

http_response_code($httpCode);
echo $response;
