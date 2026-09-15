<?php
// dashboard/api/get_ranking_day.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Ruta correcta: desde dashboard/api/ hasta api/config.php
require_once __DIR__ . '/../../api/config.php';

$grupoId = isset($_GET['grupo_id']) ? (int)$_GET['grupo_id'] : 0;

if (!$grupoId) {
    echo json_encode(['success' => false, 'error' => 'No se especificó grupo']);
    exit;
}

try {
    date_default_timezone_set('America/Mexico_City');
    $hoy = date('Y-m-d');
    
    // Obtener todos los juegos del día
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
        WHERE DATE(j.creado_en) = ? AND j.grupo_id = ?
    ");
    $stmt->execute([$hoy, $grupoId]);
    $juegosHoy = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Si no hay juegos hoy
    if (empty($juegosHoy)) {
        echo json_encode([
            'success' => true,
            'fecha' => $hoy,
            'stats' => [
                'total_partidas' => 0,
                'total_juegos' => 0,
                'total_rayas' => 0
            ],
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
    
    foreach ($juegosHoy as $juego) {
        // Equipo A
        $equipoA = [];
        if ($juego['equipo_a_jugador1']) $equipoA[] = $juego['equipo_a_jugador1'];
        if ($juego['equipo_a_jugador2']) $equipoA[] = $juego['equipo_a_jugador2'];
        
        // Equipo B
        $equipoB = [];
        if ($juego['equipo_b_jugador1']) $equipoB[] = $juego['equipo_b_jugador1'];
        if ($juego['equipo_b_jugador2']) $equipoB[] = $juego['equipo_b_jugador2'];
        
        $rayas = (int)$juego['rayas'];
        $ganador = $juego['ganador'];
        
        // Procesar equipo A
        foreach ($equipoA as $jid) {
            if (!isset($estadisticas[$jid])) {
                $estadisticas[$jid] = [
                    'rayas' => 0,
                    'juegos' => 0,
                    'victorias' => 0,
                    'derrotas' => 0
                ];
            }
            $estadisticas[$jid]['juegos']++;
            
            if ($ganador === 'A') {
                $estadisticas[$jid]['rayas'] += $rayas;
                $estadisticas[$jid]['victorias']++;
            } else {
                $estadisticas[$jid]['rayas'] -= $rayas;
                $estadisticas[$jid]['derrotas']++;
            }
        }
        
        // Procesar equipo B
        foreach ($equipoB as $jid) {
            if (!isset($estadisticas[$jid])) {
                $estadisticas[$jid] = [
                    'rayas' => 0,
                    'juegos' => 0,
                    'victorias' => 0,
                    'derrotas' => 0
                ];
            }
            $estadisticas[$jid]['juegos']++;
            
            if ($ganador === 'B') {
                $estadisticas[$jid]['rayas'] += $rayas;
                $estadisticas[$jid]['victorias']++;
            } else {
                $estadisticas[$jid]['rayas'] -= $rayas;
                $estadisticas[$jid]['derrotas']++;
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
                'rayas_hoy' => $stats['rayas'],
                'juegos_hoy' => $stats['juegos'],
                'victorias_hoy' => $stats['victorias'],
                'derrotas_hoy' => $stats['derrotas']
            ];
        }
    }
    
    // Ordenar por rayas (mayor a menor)
    usort($ranking, function($a, $b) {
        return $b['rayas_hoy'] - $a['rayas_hoy'];
    });
    
    // Estadísticas generales
    $totalPartidas = count(array_unique(array_column($juegosHoy, 'partida_id')));
    $totalJuegos = count($juegosHoy);
    $totalRayas = array_sum(array_column($juegosHoy, 'rayas'));
    
    echo json_encode([
        'success' => true,
        'fecha' => $hoy,
        'stats' => [
            'total_partidas' => $totalPartidas,
            'total_juegos' => $totalJuegos,
            'total_rayas' => $totalRayas
        ],
        'ranking' => $ranking,
        'timestamp' => time()
    ]);
    
} catch(PDOException $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Error de base de datos: ' . $e->getMessage()
    ]);
} catch(Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>