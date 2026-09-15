<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/config.php';
require_once 'auth.php';

// Verificar autenticación
$grupoId = verificarAutenticacion();
if (!$grupoId) {
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (!$data || empty($data['id'])) {
    echo json_encode(['success' => false, 'error' => 'ID de jugador requerido']);
    exit;
}

try {
    // Verificar que el jugador pertenece al grupo
    $stmt = $pdo->prepare("SELECT id FROM jugadores WHERE id = ? AND grupo_id = ?");
    $stmt->execute([$data['id'], $grupoId]);
    
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Jugador no encontrado o no pertenece a este grupo']);
        exit;
    }
    
    // Verificar si el jugador tiene partidas asociadas
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total 
        FROM juegos 
        WHERE (equipo_a_jugador1 = ? OR equipo_a_jugador2 = ? OR equipo_b_jugador1 = ? OR equipo_b_jugador2 = ?)
        AND grupo_id = ?
    ");
    $stmt->execute([$data['id'], $data['id'], $data['id'], $data['id'], $grupoId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result['total'] > 0) {
        // Si tiene partidas, solo desactivar
        $stmt = $pdo->prepare("UPDATE jugadores SET activo = 0 WHERE id = ? AND grupo_id = ?");
        $stmt->execute([$data['id'], $grupoId]);
        echo json_encode(['success' => true, 'message' => 'Jugador desactivado (tiene partidas registradas)']);
    } else {
        // Si no tiene partidas, eliminar completamente
        $stmt = $pdo->prepare("DELETE FROM jugadores WHERE id = ? AND grupo_id = ?");
        $stmt->execute([$data['id'], $grupoId]);
        echo json_encode(['success' => true, 'message' => 'Jugador eliminado correctamente']);
    }
    
} catch(Exception $e) {
    error_log("Error en delete_player.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error al eliminar el jugador'
    ]);
}
?>