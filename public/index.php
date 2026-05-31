<?php
// Load bootstrap
require_once __DIR__ . '/../bootstrap.php';

// Load CORS
require_once __DIR__ . '/../config/cors.php';
require_once __DIR__ . '/../routes/api.php';
require_once __DIR__ . '/../routes/web.php';

// Get request method and path
$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Remove base path only if script dir ends with /public (local dev)
$scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
if ($scriptDir && basename($scriptDir) === 'public' && strpos($path, $scriptDir) === 0) {
    $path = substr($path, strlen($scriptDir));
}

$path = trim($path, '/');
$segments = explode('/', $path);

// Simple routing
if ($path === '' || $path === 'index.php') {
    jsonResponse(true, 'Medlan API is running', [
        'version' => '1.0',
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}

if ($segments[0] === 'api') {
    handle_api_route($segments, $method);
} else {
    handle_web_route($segments, $method);
}
