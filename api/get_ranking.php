<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/config.php';
require_once 'auth.php';

// Verificar autenticación
$grupoId = verificarAutenticacion();
if (!$grupoId) {
    exit;
}

try {
    // Obtener ranking del grupo
    $stmt = $pdo->prepare("
        SELECT 
            j.id,
            j.nombre as name,
            j.img,
            COALESCE(ej.total_rayas, 0) as rayas_totales,
            COALESCE(ej.total_juegos, 0) as juegos_jugados,
            COALESCE(ej.partidas_ganadas, 0) as partidas_ganadas
        FROM jugadores j
        LEFT JOIN estadisticas_jugadores ej ON j.id = ej.jugador_id AND ej.grupo_id = j.grupo_id
        WHERE j.grupo_id = ? AND j.activo = 1
        ORDER BY COALESCE(ej.total_rayas, 0) DESC
    ");
    $stmt->execute([$grupoId]);
    $ranking = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Escapar datos para evitar XSS
    foreach ($ranking as &$jugador) {
        $jugador['name'] = htmlspecialchars($jugador['name'], ENT_QUOTES, 'UTF-8');
    }
    
    echo json_encode([
        'success' => true,
        'ranking' => $ranking
    ]);
    
} catch(Exception $e) {
    error_log("Error en get_ranking.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error al obtener el ranking'
    ]);
}
?>