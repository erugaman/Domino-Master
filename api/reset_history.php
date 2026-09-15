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

// Verificar autenticación y permisos de administrador
$grupoId = verificarAutenticacion();
if (!$grupoId) {
    exit;
}

$esAdmin = verificarAdmin($pdo);
if (!$esAdmin) {
    exit;
}

try {
    $pdo->beginTransaction();
    
    // Eliminar todos los juegos del grupo
    $stmt = $pdo->prepare("DELETE FROM juegos WHERE grupo_id = ?");
    $stmt->execute([$grupoId]);
    
    // Eliminar todas las partidas del grupo
    $stmt = $pdo->prepare("DELETE FROM partidas WHERE grupo_id = ?");
    $stmt->execute([$grupoId]);
    
    // Eliminar registros de partida_jugadores
    $stmt = $pdo->prepare("DELETE FROM partida_jugadores WHERE grupo_id = ?");
    $stmt->execute([$grupoId]);
    
    // Reiniciar estadísticas de jugadores
    $stmt = $pdo->prepare("
        UPDATE estadisticas_jugadores 
        SET total_rayas = 0, 
            total_juegos = 0, 
            partidas_ganadas = 0, 
            partidas_perdidas = 0, 
            veces_mano = 0, 
            promedio_rayas_por_juego = 0,
            ultima_actualizacion = NOW()
        WHERE grupo_id = ?
    ");
    $stmt->execute([$grupoId]);
    
    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Historial reiniciado correctamente'
    ]);
    
} catch(Exception $e) {
    if (isset($pdo)) $pdo->rollBack();
    error_log("Error en reset_history.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error al reiniciar el historial: ' . $e->getMessage()
    ]);
}
?>