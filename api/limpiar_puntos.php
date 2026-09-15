<?php
// api/limpiar_puntos.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/config.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['grupo_id'])) {
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (!$data || empty($data['partida_id'])) {
    echo json_encode(['success' => false, 'error' => 'ID de partida requerido']);
    exit;
}

$grupoId = $_SESSION['grupo_id'];

try {
    // Verificar que la partida pertenece al grupo
    $stmt = $pdo->prepare("SELECT id FROM partidas WHERE id = ? AND grupo_id = ?");
    $stmt->execute([$data['partida_id'], $grupoId]);
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Partida no válida']);
        exit;
    }
    
    // ============================================
    // ⭐ ELIMINAR TODOS LOS REGISTROS DE LA PARTIDA
    // ============================================
    $stmt = $pdo->prepare("DELETE FROM puntos_en_vivo WHERE partida_id = ?");
    $stmt->execute([$data['partida_id']]);
    
    // ============================================
    // ⭐ CREAR UN REGISTRO INICIAL CON CEROS
    // ============================================
    $stmt = $pdo->prepare("
        INSERT INTO puntos_en_vivo (partida_id, juego_num, ronda, equipo_a_puntos, equipo_b_puntos, equipo_a_jugadores, equipo_b_jugadores, jugador_mano, ultima_actualizacion)
        VALUES (?, 1, 1, 0, 0, '', '', 0, NOW())
    ");
    $stmt->execute([$data['partida_id']]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Puntos limpiados correctamente',
        'partida_id' => $data['partida_id']
    ]);
    
} catch(Exception $e) {
    error_log("Error en limpiar_puntos.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>