<?php
/**
 * Configuration file for Fuel Finder (UK PC)
 * 
 * Loads API credentials from .secrets file and defines constants.
 */

$secretsFile = __DIR__ . '/.secrets';

if (!file_exists($secretsFile)) {
    fwrite(STDERR, "[ERROR] Could not find .secrets file at: $secretsFile\n");
    fwrite(STDERR, "       Copy .secrets.example to .secrets and add your API credentials.\n");
    exit(1);
}

$lines = file($secretsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$secrets = [];

foreach ($lines as $line) {
    // Skip comments
    if (strpos($line, '#') === 0) {
        continue;
    }
    
    // Parse KEY=value
    if (strpos($line, '=') !== false) {
        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        
        // Remove quotes if present
        if ((strpos($value, '"') === 0 && substr($value, -1) === '"') ||
            (strpos($value, "'") === 0 && substr($value, -1) === "'")) {
            $value = substr($value, 1, -1);
        }
        
        $secrets[$key] = $value;
    }
}

// Define constants directly from parsed values
define('FUEL_CLIENT_ID', $secrets['FUEL_CLIENT_ID'] ?? '');
define('FUEL_CLIENT_SECRET', $secrets['FUEL_CLIENT_SECRET'] ?? '');
define('DEPLOY_API_KEY', $secrets['DEPLOY_API_KEY'] ?? '');
define('DEPLOY_URL', $secrets['DEPLOY_URL'] ?? 'https://fuelseeker.net/scripts/db_deploy.php');
define('USE_GZIP', ($secrets['USE_GZIP'] ?? 'true') !== 'false'); // Default true
