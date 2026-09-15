<?php
// dashboard/api/get_chart_data.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../api/config.php';

$grupoId = isset($_GET['grupo_id']) ? (int)$_GET['grupo_id'] : 0;

if (!$grupoId) {
    echo json_encode(['success' => false, 'error' => 'No se especificó grupo']);
    exit;
}

try {
    // Obtener todos los juegos de la partida activa
    $stmt = $pdo->prepare("
        SELECT 
            j.num_juego,
            j.puntos_equipo_a,
            j.puntos_equipo_b,
            j.rayas,
            j.creado_en,
            p.id as partida_id
        FROM juegos j
        INNER JOIN partidas p ON j.partida_id = p.id
        WHERE p.grupo_id = ? AND p.estado = 'activa'
        ORDER BY j.num_juego ASC
    ");
    $stmt->execute([$grupoId]);
    $juegos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($juegos)) {
        echo json_encode([
            'success' => true,
            'total_juegos' => 0,
            'labels' => [],
            'puntos_acumulados_a' => [],
            'puntos_acumulados_b' => [],
            'rayas_por_juego' => []
        ]);
        exit;
    }
    
    $labels = [];
    $puntosAcumuladosA = [];
    $puntosAcumuladosB = [];
    $rayasPorJuego = [];
    $acumuladoA = 0;
    $acumuladoB = 0;
    
    foreach ($juegos as $juego) {
        $acumuladoA += $juego['puntos_equipo_a'];
        $acumuladoB += $juego['puntos_equipo_b'];
        
        $labels[] = 'Juego ' . $juego['num_juego'];
        $puntosAcumuladosA[] = $acumuladoA;
        $puntosAcumuladosB[] = $acumuladoB;
        $rayasPorJuego[] = (int)$juego['rayas'];
    }
    
    echo json_encode([
        'success' => true,
        'total_juegos' => count($juegos),
        'labels' => $labels,
        'puntos_acumulados_a' => $puntosAcumuladosA,
        'puntos_acumulados_b' => $puntosAcumuladosB,
        'rayas_por_juego' => $rayasPorJuego,
        'timestamp' => time()
    ]);
    
} catch(Exception $e) {
    error_log("Error en get_chart_data.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>