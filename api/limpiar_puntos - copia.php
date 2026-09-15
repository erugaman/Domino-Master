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
    
    // Limpiar puntos parciales (establecer a 0)
    $stmt = $pdo->prepare("
        UPDATE puntos_en_vivo 
        SET equipo_a_puntos = 0, 
            equipo_b_puntos = 0, 
            jugador_mano = 0,
            ultima_actualizacion = NOW()
        WHERE partida_id = ?
    ");
    $stmt->execute([$data['partida_id']]);
    
    // Si no existe el registro, crearlo con ceros
    if ($stmt->rowCount() === 0) {
        $stmt = $pdo->prepare("
            INSERT INTO puntos_en_vivo (partida_id, juego_num, equipo_a_puntos, equipo_b_puntos, jugador_mano, ultima_actualizacion)
            VALUES (?, 1, 0, 0, 0, NOW())
        ");
        $stmt->execute([$data['partida_id']]);
    }
    
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