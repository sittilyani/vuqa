<?php
// transitions/transition_ai_proxy.php
// Server-side proxy for Anthropic Claude API calls.
// The browser cannot call api.anthropic.com directly without exposing the key.
// This file holds the API key securely on the server and forwards to Claude.
// Supports two HTTP methods:
//   POST {prompt}  → forward to Claude, return JSON response
//   GET ?action=health → diagnostic check (session not required)

session_start();
include('../includes/config.php');
// session_check.php is intentionally NOT included here so the health endpoint
// works without a full session, but we still protect the POST endpoint below.

header('Content-Type: application/json');

// ─── Health / diagnostic endpoint ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'health') {
    $curl_ok   = function_exists('curl_init');
    $fopen_ok  = ini_get('allow_url_fopen') && function_exists('stream_context_create');
    $key_ok    = defined('ANTHROPIC_API_KEY') && ANTHROPIC_API_KEY !== '';
    $model     = defined('ANTHROPIC_MODEL') ? ANTHROPIC_MODEL : 'claude-sonnet-4-20250514';
    $session_ok = isset($_SESSION['user_id']);

    echo json_encode([
        'status'          => ($key_ok && ($curl_ok || $fopen_ok)) ? 'ready' : 'not_ready',
        'api_key_set'     => $key_ok,
        'curl_available'  => $curl_ok,
        'fopen_available' => (bool)$fopen_ok,
        'model'           => $model,
        'session_active'  => $session_ok,
        'php_version'     => PHP_VERSION,
    ]);
    exit();
}

// ─── All other requests need a live session ────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorised', 'message' => 'Session expired — please log in again.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed']);
    exit();
}

// ─── API key check ─────────────────────────────────────────────────────────
if (!defined('ANTHROPIC_API_KEY') || ANTHROPIC_API_KEY === '') {
    http_response_code(503);
    echo json_encode([
        'error'   => 'api_key_missing',
        'message' => 'The Anthropic API key is not configured on this server. '
                   . 'Open includes/config.php and set: define(\'ANTHROPIC_API_KEY\', \'sk-ant-...\');'
    ]);
    exit();
}

// ─── Parse request body ────────────────────────────────────────────────────
$body = json_decode(file_get_contents('php://input'), true);
if (!$body || empty($body['prompt'])) {
    http_response_code(400);
    echo json_encode(['error' => 'bad_request', 'message' => 'Missing prompt field.']);
    exit();
}

$prompt     = $body['prompt'];
$model      = defined('ANTHROPIC_MODEL')      ? ANTHROPIC_MODEL      : 'claude-sonnet-4-20250514';
$max_tokens = defined('ANTHROPIC_MAX_TOKENS') ? (int)ANTHROPIC_MAX_TOKENS : 4096;

$api_url      = 'https://api.anthropic.com/v1/messages';
$request_body = json_encode([
    'model'      => $model,
    'max_tokens' => $max_tokens,
    'messages'   => [['role' => 'user', 'content' => $prompt]]
]);
$headers = [
    'Content-Type: application/json',
    'x-api-key: ' . ANTHROPIC_API_KEY,
    'anthropic-version: 2023-06-01',
];

// ─── Method 1: cURL (preferred) ────────────────────────────────────────────
if (function_exists('curl_init')) {
    $ch = curl_init($api_url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $request_body,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => true,
    ]);

    $response    = curl_exec($ch);
    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error  = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        // cURL failed — fall through to file_get_contents fallback
        $curl_err_msg = $curl_error;
        $response     = false;
    } else {
        http_response_code($http_status);
        echo $response;
        exit();
    }
} else {
    $curl_err_msg = 'cURL extension not available on this server.';
    $response     = false;
}

// ─── Method 2: file_get_contents fallback ─────────────────────────────────
if (!ini_get('allow_url_fopen')) {
    http_response_code(503);
    echo json_encode([
        'error'   => 'no_http_client',
        'message' => 'Cannot reach the Anthropic API. '
                   . 'cURL error: ' . ($curl_err_msg ?? 'cURL unavailable') . '. '
                   . 'Also, allow_url_fopen is disabled on this server. '
                   . 'Ask your hosting provider to enable cURL or allow_url_fopen.',
        'debug'   => [
            'curl_available' => function_exists('curl_init'),
            'curl_error'     => $curl_err_msg ?? null,
            'fopen_allowed'  => false,
        ]
    ]);
    exit();
}

// Build HTTP context for file_get_contents
$http_context = stream_context_create([
    'http' => [
        'method'        => 'POST',
        'header'        => implode("\r\n", $headers),
        'content'       => $request_body,
        'timeout'       => 120,
        'ignore_errors' => true,   // return body even on 4xx/5xx
    ],
    'ssl' => [
        'verify_peer'      => true,
        'verify_peer_name' => true,
    ],
]);

$response = @file_get_contents($api_url, false, $http_context);

if ($response === false) {
    http_response_code(502);
    echo json_encode([
        'error'   => 'upstream_failed',
        'message' => 'Both cURL and file_get_contents failed to reach api.anthropic.com. '
                   . 'Your server may be blocking outbound HTTPS. '
                   . 'cURL error was: ' . ($curl_err_msg ?? 'n/a'),
    ]);
    exit();
}

// Extract HTTP status from $http_response_header (set by file_get_contents)
$http_status = 200;
if (isset($http_response_header[0])) {
    preg_match('/HTTP\/\S+\s+(\d+)/', $http_response_header[0], $m);
    if ($m) $http_status = (int)$m[1];
}

http_response_code($http_status);
echo $response;
exit();
