<?php
/**
 * Fuel Finder API OAuth Token Proxy
 * 
 * API credentials are loaded from environment variables (see .env.example)
 */

session_start();

require_once __DIR__ . '/config.php';

const TOKEN_URL = 'https://www.fuel-finder.service.gov.uk/api/v1/oauth/generate_access_token';

$allowedHosts = ['localhost', '127.0.0.1', 'fuel.osola.uk', 'fuel.local'];

function getAllHeadersSafe() {
    if (function_exists('getallheaders')) {
        return getallheaders();
    }
    $headers = [];
    foreach ($_SERVER as $name => $value) {
        if (substr($name, 0, 5) == 'HTTP_') {
            $headerName = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
            $headers[$headerName] = $value;
        }
    }
    return $headers;
}

function isAllowedHost($referer, $allowedHosts) {
    if (empty($referer)) return false;
    foreach ($allowedHosts as $host) {
        if (strpos($referer, $host) !== false) return true;
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
    $receivedToken = $headers['X-CSRF-Token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($receivedToken) || empty($_SESSION['csrf_token'])) return false;
    return hash_equals($_SESSION['csrf_token'], $receivedToken);
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

if (isset($_GET['action']) && $_GET['action'] === 'get_csrf_token') {
    echo json_encode(['csrf_token' => getCsrfToken()]);
    exit();
}

$referer = $_SERVER['HTTP_REFERER'] ?? '';
if (!isAllowedHost($referer, $allowedHosts)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden: Invalid referrer']);
    exit();
}

if (!validateCsrfToken()) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden: Invalid CSRF token']);
    exit();
}

$postData = json_encode(['client_id' => FUEL_CLIENT_ID, 'client_secret' => FUEL_CLIENT_SECRET]);

$ch = curl_init(TOKEN_URL);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Content-Length: ' . strlen($postData),
    'User-Agent: FuelFinderApp/1.0'
]);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($error) {
    http_response_code(500);
    echo json_encode(['error' => 'Token request failed: ' . $error]);
    exit();
}

if ($httpCode !== 200) {
    http_response_code($httpCode);
    echo $response;
    exit();
}

http_response_code(200);
echo $response;
