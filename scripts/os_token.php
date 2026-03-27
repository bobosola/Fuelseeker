<?php
/**
 * Ordnance Survey OAuth Token Proxy
 * 
 * API credentials are loaded from environment variables (see .secrets.example)
 */

session_start();

require_once __DIR__ . '/config.php';

// Generate Base64 auth string from environment variables
$osAuthBase64 = base64_encode(OS_API_KEY . ':' . OS_API_SECRET);

// Extract hostnames from ALLOWED_ORIGINS for CSRF validation
$allowedHosts = array_map(function($origin) {
    return parse_url($origin, PHP_URL_HOST) ?: $origin;
}, ALLOWED_ORIGINS);

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

// Get CSRF token from header (try multiple methods)
$receivedToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (empty($receivedToken) && function_exists('getallheaders')) {
    $headers = getallheaders();
    $receivedToken = $headers['X-CSRF-Token'] ?? '';
}

if (empty($receivedToken) || empty($_SESSION['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden: Invalid CSRF token']);
    exit();
}

if (!hash_equals($_SESSION['csrf_token'], $receivedToken)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden: CSRF token mismatch']);
    exit();
}

$post = "POST /oauth2/token/v1 HTTP/1.1\r\n";
$post .= "Host: api.os.uk\r\n";
$post .= "Authorization: Basic " . $osAuthBase64 . "\r\n";
$post .= "Content-type: application/x-www-form-urlencoded\r\n";
$post .= "Content-length: 29\r\n";
$post .= "Connection: close\r\n";
$post .= "\r\n";
$post .= "grant_type=client_credentials\r\n";
$post .= "\r\n";

$port = 443;
$error_code = "";
$error_message = "";
$timeout = 10;

$fp = @fsockopen("tls://api.os.uk", $port, $error_code, $error_message, $timeout = 10);

if (!$fp) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to connect to OS API: ' . $error_message]);
    exit();
}

fputs($fp, $post);
$response = '';
while (!feof($fp)) {
    $response .= fgets($fp);
}
fclose($fp);

$parts = explode("\r\n\r\n", $response, 2);
if (count($parts) < 2) {
    $parts = explode("\n\n", $response, 2);
}

$body = $parts[1] ?? $response;
$body = trim($body);

$data = json_decode($body, true);

if ($data === null) {
    http_response_code(500);
    echo json_encode(['error' => 'Invalid response from OS API', 'raw' => $body]);
    exit();
}

if (empty($data['access_token'])) {
    http_response_code(500);
    echo json_encode(['error' => 'No access token in response', 'response' => $data]);
    exit();
}

$result = [
    'access_token' => $data['access_token'],
    'token_type' => $data['token_type'] ?? 'Bearer',
    'expires_in' => (int)($data['expires_in'] ?? 299)
];

http_response_code(200);
echo json_encode($result);
