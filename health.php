<?php
// Simple health check for Railway deployment
echo json_encode([
    'status' => 'ok',
    'message' => 'PHP application is running',
    'timestamp' => date('Y-m-d H:i:s'),
    'php_version' => phpversion(),
    'database_connected' => false
]);

// Try database connection
try {
    require 'db.php';
    echo json_encode(['database_connected' => true]);
} catch (Exception $e) {
    echo json_encode(['database_error' => $e->getMessage()]);
}
?>
