<?php
// Simple test endpoint
if ($_SERVER['REQUEST_URI'] === '/test') {
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'ok',
        'message' => 'PHP is working',
        'php_version' => PHP_VERSION,
        'time' => date('Y-m-d H:i:s')
    ]);
    exit;
}

// Main webhook handler
require_once 'webhook.php';
