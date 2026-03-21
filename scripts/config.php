<?php
/**
 * Configuration file for Fuel Finder
 * 
 * Loads API credentials from environment variables.
 */

// Load .env file if it exists 
$file = __DIR__ . '/.env';
$envFile = null;
if (file_exists($file)) {
    $envFile = $file;
}
if ($envFile) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
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
            if (strpos($value, '"') === 0 && substr($value, -1) === '"') {
                $value = substr($value, 1, -1);
            } elseif (strpos($value, "'") === 0 && substr($value, -1) === "'") {
                $value = substr($value, 1, -1);
            }
            
            // Set environment variable if not already set
            if (!getenv($key)) {
                putenv("$key=$value");
                $_ENV[$key] = $value;
            }
        }
    }
}

// API Configuration
define('FUEL_CLIENT_ID', getenv('FUEL_CLIENT_ID') ?: '');
define('FUEL_CLIENT_SECRET', getenv('FUEL_CLIENT_SECRET') ?: '');
define('OS_API_KEY', getenv('OS_API_KEY') ?: '');
define('OS_API_SECRET', getenv('OS_API_SECRET') ?: '');
define('DEPLOY_API_KEY', getenv('DEPLOY_API_KEY') ?: '');


// Validate configuration
function validateConfig() {
    $missing = [];
    
    if (empty(FUEL_CLIENT_ID)) {
        $missing[] = 'FUEL_CLIENT_ID';
    }
    if (empty(FUEL_CLIENT_SECRET)) {
        $missing[] = 'FUEL_CLIENT_SECRET';
    }
    if (empty(OS_API_KEY)) {
        $missing[] = 'OS_API_KEY';
    }
    if (empty(OS_API_SECRET)) {
        $missing[] = 'OS_API_SECRET';
    }
    if (empty(DEPLOY_API_KEY)) {
        $missing[] = 'DEPLOY_API_KEY';
    }
    
    if (!empty($missing)) {
        throw new Exception('Missing environment variables: ' . implode(', ', $missing) . 
            '. Please copy .env.example to .env and fill in your API credentials.');
    }
}
