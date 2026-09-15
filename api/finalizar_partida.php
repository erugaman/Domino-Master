<?php
// api/finalizar_partida.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

// Verificar autenticación
$grupoId = verificarAutenticacion();
if (!$grupoId) {
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (!$data || empty($data['partida_id'])) {
    echo json_encode(['success' => false, 'error' => 'ID de partida requerido']);
    exit;
}

try {
    // Verificar que la partida existe y pertenece al grupo
    $stmt = $pdo->prepare("SELECT id, estado FROM partidas WHERE id = ? AND grupo_id = ?");
    $stmt->execute([$data['partida_id'], $grupoId]);
    $partida = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$partida) {
        echo json_encode(['success' => false, 'error' => 'Partida no encontrada']);
        exit;
    }
    
    // Solo marcar como completada si está activa
    if ($partida['estado'] === 'activa') {
        $stmt = $pdo->prepare("
            UPDATE partidas 
            SET estado = 'completada', fecha_fin = NOW() 
            WHERE id = ? AND grupo_id = ?
        ");
        $stmt->execute([$data['partida_id'], $grupoId]);
        
        // Limpiar puntos parciales
        $stmt = $pdo->prepare("DELETE FROM puntos_en_vivo WHERE partida_id = ?");
        $stmt->execute([$data['partida_id']]);
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Partida finalizada correctamente'
    ]);
    
} catch(Exception $e) {
    error_log("Error en finalizar_partida.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error al finalizar la partida: ' . $e->getMessage()
    ]);
}
?>