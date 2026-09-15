<?php
// api/guardar_puntos_parciales.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/config.php';
session_start();

// Verificar autenticacion
if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['grupo_id'])) {
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (!$data || empty($data['partida_id']) || !isset($data['juego_num']) || !isset($data['ronda'])) {
    echo json_encode(['success' => false, 'error' => 'Datos incompletos (partida_id, juego_num y ronda requeridos)']);
    exit;
}

$grupoId = $_SESSION['grupo_id'];

try {
    // Verificar que la partida pertenece al grupo
    $stmt = $pdo->prepare("SELECT id FROM partidas WHERE id = ? AND grupo_id = ? AND estado = 'activa'");
    $stmt->execute([$data['partida_id'], $grupoId]);
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Partida no válida o no activa']);
        exit;
    }
    
    // Obtener el jugador_mano_id
    $jugadorManoId = isset($data['jugador_mano_id']) ? (int)$data['jugador_mano_id'] : 0;
    
    if ($jugadorManoId <= 0 && !empty($data['jugador_mano'])) {
        $stmt = $pdo->prepare("SELECT id FROM jugadores WHERE nombre = ? AND grupo_id = ?");
        $stmt->execute([$data['jugador_mano'], $grupoId]);
        $jugadorManoId = (int)$stmt->fetchColumn();
    }
    
    // ============================================
    // ⭐ SIEMPRE INSERTAR UN NUEVO REGISTRO
    // ============================================
    // NO usar ON DUPLICATE KEY UPDATE
    $stmt = $pdo->prepare("
        INSERT INTO puntos_en_vivo (partida_id, juego_num, ronda, equipo_a_puntos, equipo_b_puntos, equipo_a_jugadores, equipo_b_jugadores, jugador_mano, ultima_actualizacion)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    
    $stmt->execute([
        $data['partida_id'],
        $data['juego_num'],
        $data['ronda'],
        $data['equipo_a_puntos'],
        $data['equipo_b_puntos'],
        $data['equipo_a_jugadores'] ?? null,
        $data['equipo_b_jugadores'] ?? null,
        $jugadorManoId
    ]);
    
    $nuevoId = $pdo->lastInsertId();
    
    echo json_encode([
        'success' => true,
        'message' => 'Puntos parciales guardados',
        'id' => $nuevoId,
        'partida_id' => $data['partida_id'],
        'juego_num' => $data['juego_num'],
        'ronda' => $data['ronda'],
        'jugador_mano_id' => $jugadorManoId,
        'puntos' => $data['equipo_a_puntos'] . '-' . $data['equipo_b_puntos']
    ]);
    
} catch(Exception $e) {
    error_log("Error en guardar_puntos_parciales.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>