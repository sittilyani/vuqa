<?php
$host = 'localhost';
$username = 'root';
$password = '';
$database = 'transition';

$conn = new mysqli($host, $username, $password, $database);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// ADD THIS - Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ─── Anthropic Claude API Key ───────────────────────────────────────────────
// Set your API key here (get one from https://console.anthropic.com)
// Leave blank to disable real-AI features; the AI Advisor will use the
// built-in rule-based engine instead.
define('ANTHROPIC_API_KEY', '');          // e.g. 'sk-ant-api03-...'
define('ANTHROPIC_MODEL',   'claude-sonnet-4-20250514');
define('ANTHROPIC_MAX_TOKENS', 4096);
?>