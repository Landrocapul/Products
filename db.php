<?php
// Database configuration - Production ready with environment variables
$host = getenv('DB_HOST') ?: "sql307.infinityfree.com";
$dbname = getenv('DB_NAME') ?: "if0_40482619_lazada";
$username = getenv('DB_USER') ?: "if0_40482619";
$password = getenv('DB_PASS') ?: "4uKF4gkfId";

// For Railway, the database URL might be provided as DATABASE_URL
$database_url = getenv('DATABASE_URL');
if ($database_url) {
    // Parse Railway's DATABASE_URL format: mysql://user:password@host:port/database
    $url_parts = parse_url($database_url);
    if ($url_parts) {
        $host = $url_parts['host'] ?? $host;
        $username = $url_parts['user'] ?? $username;
        $password = $url_parts['pass'] ?? $password;
        $dbname = ltrim($url_parts['path'] ?? '', '/') ?: $dbname;

        // Handle port if specified
        if (isset($url_parts['port'])) {
            $host .= ':' . $url_parts['port'];
        }
    }
}

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db_connected = true;
} catch (PDOException $e) {
    $db_connected = false;
    $pdo = null; // Set to null so other code can check
    // Don't die here - let the application handle gracefully
}
