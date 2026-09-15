<?php
// dashboard/api/get_ranking_historico.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../api/config.php';

$grupoId = isset($_GET['grupo_id']) ? (int)$_GET['grupo_id'] : 0;
$periodo = isset($_GET['periodo']) ? $_GET['periodo'] : 'dia';

if (!$grupoId) {
    echo json_encode(['success' => false, 'error' => 'No se especificó grupo']);
    exit;
}

try {
    date_default_timezone_set('America/Mexico_City');
    
    $fechaInicio = date('Y-m-d');
    $fechaFin = date('Y-m-d');
    
    switch($periodo) {
        case 'semana':
            $fechaInicio = date('Y-m-d', strtotime('-7 days'));
            break;
        case 'mes':
            $fechaInicio = date('Y-m-d', strtotime('-30 days'));
            break;
        case 'anio':
            $fechaInicio = date('Y-m-d', strtotime('-365 days'));
            break;
        default:
            $fechaInicio = date('Y-m-d');
    }
    
    // Obtener todos los juegos del período
    $stmt = $pdo->prepare("
        SELECT 
            j.id,
            j.partida_id,
            j.equipo_a_jugador1,
            j.equipo_a_jugador2,
            j.equipo_b_jugador1,
            j.equipo_b_jugador2,
            j.rayas,
            j.ganador
        FROM juegos j
        WHERE DATE(j.creado_en) >= ? AND DATE(j.creado_en) <= ? AND j.grupo_id = ?
    ");
    $stmt->execute([$fechaInicio, $fechaFin, $grupoId]);
    $juegos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($juegos)) {
        echo json_encode([
            'success' => true,
            'periodo' => $periodo,
            'fecha_inicio' => $fechaInicio,
            'stats' => ['total_partidas' => 0, 'total_juegos' => 0, 'total_rayas' => 0],
            'ranking' => []
        ]);
        exit;
    }
    
    // Obtener nombres de jugadores
    $stmt = $pdo->prepare("SELECT id, nombre, img FROM jugadores WHERE grupo_id = ? AND activo = 1");
    $stmt->execute([$grupoId]);
    $jugadoresInfo = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $j) {
        $jugadoresInfo[$j['id']] = [
            'nombre' => $j['nombre'],
            'img' => $j['img'] ?: "https://api.dicebear.com/7.x/avataaars/svg?seed=" . urlencode($j['nombre'])
        ];
    }
    
    // Acumular estadísticas
    $estadisticas = [];
    
    foreach ($juegos as $juego) {
        $equipoA = [];
        if ($juego['equipo_a_jugador1']) $equipoA[] = $juego['equipo_a_jugador1'];
        if ($juego['equipo_a_jugador2']) $equipoA[] = $juego['equipo_a_jugador2'];
        
        $equipoB = [];
        if ($juego['equipo_b_jugador1']) $equipoB[] = $juego['equipo_b_jugador1'];
        if ($juego['equipo_b_jugador2']) $equipoB[] = $juego['equipo_b_jugador2'];
        
        $rayas = (int)$juego['rayas'];
        $ganador = $juego['ganador'];
        
        foreach ($equipoA as $jid) {
            if (!isset($estadisticas[$jid])) {
                $estadisticas[$jid] = [
                    'total_rayas' => 0,
                    'total_juegos' => 0,
                    'total_victorias' => 0,
                    'total_derrotas' => 0
                ];
            }
            $estadisticas[$jid]['total_juegos']++;
            
            if ($ganador === 'A') {
                $estadisticas[$jid]['total_rayas'] += $rayas;
                $estadisticas[$jid]['total_victorias']++;
            } else {
                $estadisticas[$jid]['total_rayas'] -= $rayas;
                $estadisticas[$jid]['total_derrotas']++;
            }
        }
        
        foreach ($equipoB as $jid) {
            if (!isset($estadisticas[$jid])) {
                $estadisticas[$jid] = [
                    'total_rayas' => 0,
                    'total_juegos' => 0,
                    'total_victorias' => 0,
                    'total_derrotas' => 0
                ];
            }
            $estadisticas[$jid]['total_juegos']++;
            
            if ($ganador === 'B') {
                $estadisticas[$jid]['total_rayas'] += $rayas;
                $estadisticas[$jid]['total_victorias']++;
            } else {
                $estadisticas[$jid]['total_rayas'] -= $rayas;
                $estadisticas[$jid]['total_derrotas']++;
            }
        }
    }
    
    // Construir ranking
    $ranking = [];
    foreach ($estadisticas as $jid => $stats) {
        if (isset($jugadoresInfo[$jid])) {
            $ranking[] = [
                'nombre' => htmlspecialchars($jugadoresInfo[$jid]['nombre'], ENT_QUOTES, 'UTF-8'),
                'img' => $jugadoresInfo[$jid]['img'],
                'total_rayas' => $stats['total_rayas'],
                'total_juegos' => $stats['total_juegos'],
                'total_victorias' => $stats['total_victorias'],
                'total_derrotas' => $stats['total_derrotas']
            ];
        }
    }
    
    // Ordenar por rayas
    usort($ranking, function($a, $b) {
        return $b['total_rayas'] - $a['total_rayas'];
    });
    
    // Estadísticas generales
    $totalPartidas = count(array_unique(array_column($juegos, 'partida_id')));
    $totalJuegos = count($juegos);
    $totalRayas = array_sum(array_column($juegos, 'rayas'));
    
    echo json_encode([
        'success' => true,
        'periodo' => $periodo,
        'fecha_inicio' => $fechaInicio,
        'stats' => [
            'total_partidas' => $totalPartidas,
            'total_juegos' => $totalJuegos,
            'total_rayas' => $totalRayas
        ],
        'ranking' => $ranking,
        'timestamp' => time()
    ]);
    
} catch(Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>