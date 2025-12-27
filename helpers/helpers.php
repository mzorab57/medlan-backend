<?php

// JSON Response helper
function jsonResponse($success, $message, $data = null, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    $response = [
        'success' => $success,
        'message' => $message
    ];
    
    if ($data !== null) {
        $response['data'] = $data;
    }
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

// Get JSON input
function getJsonInput() {
    return json_decode(file_get_contents('php://input'), true) ?? [];
}

// Sanitize input
function sanitize($data) {
    return htmlspecialchars(strip_tags(trim($data)));
}

// Generate slug from text
function generateSlug($text) {
    // Kurdish-friendly slug
    $text = mb_strtolower($text, 'UTF-8');
    $text = preg_replace('/[^\p{L}\p{N}\s-]/u', '', $text);
    $text = preg_replace('/[\s-]+/', '-', $text);
    return trim($text, '-');
}

// Validate email
function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

// Log error
function logError($message) {
    $log_file = __DIR__ . '/../logs/error.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($log_file, "[$timestamp] $message\n", FILE_APPEND);
}

function randomString($length = 12) {
    $bytes = random_bytes($length);
    $s = rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    $s = preg_replace('/[^A-Za-z0-9]/', '', $s);
    if (strlen($s) < $length) {
        $s = str_pad($s, $length, 'A');
    }
    return substr($s, 0, $length);
}
