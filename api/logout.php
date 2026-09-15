<?php
// logout.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

session_start();
session_destroy();

echo json_encode(['success' => true, 'message' => 'Sesión cerrada correctamente']);
?>