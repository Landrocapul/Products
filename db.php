<?php
// Database configuration - Production ready with environment variables
$host = getenv('DB_HOST') ?: "localhost";
$dbname = getenv('DB_NAME') ?: "lazada";
$username = getenv('DB_USER') ?: "root";
$password = getenv('DB_PASS') ?: "";

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
} catch (PDOException $e) {
    // In production, don't show detailed errors
    error_log("Database connection failed: " . $e->getMessage());
    die("Database connection failed. Please try again later.");
}
