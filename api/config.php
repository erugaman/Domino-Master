<?php
// ============================================
// CONFIGURACIÓN
// ============================================

$host = 'desarrollo';
$dbname = 'desarrollo';
$username = 'domino_app';
$password = 'domino_pwd';

date_default_timezone_set('America/Mexico_City'); // Ajusta según tu zona horaria

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Error de conexión: ' . $e->getMessage()]);
    exit;
}
?>