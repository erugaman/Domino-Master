<?php
// dashboard/api/get_stats_jugadores.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../api/config.php';

$grupoId = isset($_GET['grupo_id']) ? (int)$_GET['grupo_id'] : 0;

if (!$grupoId) {
    echo json_encode(['success' => false, 'error' => 'No se especificó grupo']);
    exit;
}

try {
    // Primero buscar partida activa, si no hay, buscar la última completada
    $stmt = $pdo->prepare("
        SELECT id FROM partidas 
        WHERE grupo_id = ? AND estado = 'activa'
        ORDER BY fecha_inicio DESC
        LIMIT 1
    ");
    $stmt->execute([$grupoId]);
    $partida = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$partida) {
        // No hay partida activa, buscar la última completada
        $stmt = $pdo->prepare("
            SELECT id FROM partidas 
            WHERE grupo_id = ? AND estado = 'completada'
            ORDER BY fecha_inicio DESC
            LIMIT 1
        ");
        $stmt->execute([$grupoId]);
        $partida = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    if (!$partida) {
        echo json_encode([
            'success' => true,
            'labels' => [],
            'rayas_ganadas' => [],
            'rayas_perdidas' => [],
            'rayas_netas' => [],
            'colores_netas' => []
        ]);
        exit;
    }
    
    // Obtener estadísticas de cada jugador en esa partida
    $stmt = $pdo->prepare("
        SELECT 
            j.nombre,
            j.img,
            COALESCE(SUM(
                CASE 
                    WHEN (jg.equipo_a_jugador1 = j.id OR jg.equipo_a_jugador2 = j.id) AND jg.ganador = 'A' THEN jg.rayas
                    WHEN (jg.equipo_b_jugador1 = j.id OR jg.equipo_b_jugador2 = j.id) AND jg.ganador = 'B' THEN jg.rayas
                    ELSE 0
                END
            ), 0) as rayas_ganadas,
            COALESCE(SUM(
                CASE 
                    WHEN (jg.equipo_a_jugador1 = j.id OR jg.equipo_a_jugador2 = j.id) AND jg.ganador = 'B' THEN jg.rayas
                    WHEN (jg.equipo_b_jugador1 = j.id OR jg.equipo_b_jugador2 = j.id) AND jg.ganador = 'A' THEN jg.rayas
                    ELSE 0
                END
            ), 0) as rayas_perdidas,
            COUNT(jg.id) as juegos_jugados
        FROM jugadores j
        LEFT JOIN partida_jugadores pj ON j.id = pj.jugador_id AND pj.grupo_id = j.grupo_id
        LEFT JOIN juegos jg ON pj.partida_id = jg.partida_id AND (jg.equipo_a_jugador1 = j.id OR jg.equipo_a_jugador2 = j.id OR jg.equipo_b_jugador1 = j.id OR jg.equipo_b_jugador2 = j.id)
        WHERE j.grupo_id = ? AND j.activo = 1 AND pj.partida_id = ?
        GROUP BY j.id
        ORDER BY (rayas_ganadas - rayas_perdidas) DESC
    ");
    $stmt->execute([$grupoId, $partida['id']]);
    $jugadores = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $labels = [];
    $rayasGanadas = [];
    $rayasPerdidas = [];
    $rayasNetas = [];
    $coloresNetas = [];
    
    foreach ($jugadores as $j) {
        $labels[] = htmlspecialchars($j['nombre'], ENT_QUOTES, 'UTF-8');
        $ganadas = (int)$j['rayas_ganadas'];
        $perdidas = (int)$j['rayas_perdidas'];
        $netas = $ganadas - $perdidas;
        
        $rayasGanadas[] = $ganadas;
        $rayasPerdidas[] = $perdidas;
        $rayasNetas[] = $netas;
        $coloresNetas[] = $netas >= 0 ? '#10b981' : '#ef4444';
    }
    
    echo json_encode([
        'success' => true,
        'labels' => $labels,
        'rayas_ganadas' => $rayasGanadas,
        'rayas_perdidas' => $rayasPerdidas,
        'rayas_netas' => $rayasNetas,
        'colores_netas' => $coloresNetas,
        'partida_id' => $partida['id'],
        'timestamp' => time()
    ]);
    
} catch(Exception $e) {
    error_log("Error en get_stats_jugadores.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>