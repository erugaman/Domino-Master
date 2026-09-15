<?php
session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/config.php';

$data = json_decode(file_get_contents('php://input'), true);

$grupoId = $_SESSION['grupo_id'] ?? null;

if (!$grupoId) {
    echo json_encode(['success' => false, 'error' => 'No hay grupo activo']);
    exit;
}

if (!isset($data['jugador_id'])) {
    echo json_encode(['success' => false, 'error' => 'Datos incompletos']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        INSERT INTO grupo_jugadores (grupo_id, jugador_id, activo, fecha_ingreso) 
        VALUES (?, ?, 1, NOW())
        ON DUPLICATE KEY UPDATE activo = 1
    ");
    $stmt->execute([$grupoId, $data['jugador_id']]);
    
    $stmt = $pdo->prepare("UPDATE jugadores SET grupo_id = ? WHERE id = ?");
    $stmt->execute([$grupoId, $data['jugador_id']]);
    
    echo json_encode(['success' => true]);
} catch(Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>