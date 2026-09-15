<?php
// api/get_stats_parejas.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

// Verificar autenticación
$grupoId = verificarAutenticacion();
if (!$grupoId) {
    exit;
}

try {
    // ============================================
    // 1. Obtener TODOS los juegos del grupo
    // ============================================
    $stmt = $pdo->prepare("
        SELECT 
            j.id,
            j.equipo_a_jugador1,
            j.equipo_a_jugador2,
            j.equipo_b_jugador1,
            j.equipo_b_jugador2,
            j.rayas,
            j.ganador
        FROM juegos j
        WHERE j.grupo_id = ?
        ORDER BY j.id DESC
    ");
    $stmt->execute([$grupoId]);
    $juegos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // ============================================
    // 2. Obtener información de jugadores
    // ============================================
    $stmtJugadores = $pdo->prepare("
        SELECT id, nombre, img 
        FROM jugadores 
        WHERE grupo_id = ? AND activo = 1
    ");
    $stmtJugadores->execute([$grupoId]);
    $jugadoresDB = $stmtJugadores->fetchAll(PDO::FETCH_ASSOC);
    
    $jugadoresInfo = [];
    foreach ($jugadoresDB as $j) {
        $jugadoresInfo[$j['id']] = [
            'nombre' => $j['nombre'],
            'img' => $j['img'] ?: "https://api.dicebear.com/7.x/avataaars/svg?seed=" . urlencode($j['nombre'])
        ];
    }
    
    // ============================================
    // 3. Procesar juegos para obtener estadísticas de parejas
    // ============================================
    $parejasStats = [];
    
    foreach ($juegos as $juego) {
        // Procesar EQUIPO A
        $equipoA = [];
        if ($juego['equipo_a_jugador1']) $equipoA[] = $juego['equipo_a_jugador1'];
        if ($juego['equipo_a_jugador2']) $equipoA[] = $juego['equipo_a_jugador2'];
        sort($equipoA);
        
        if (count($equipoA) == 2) {
            $key = $equipoA[0] . '_' . $equipoA[1];
            if (!isset($parejasStats[$key])) {
                $parejasStats[$key] = [
                    'jugador1_id' => $equipoA[0],
                    'jugador2_id' => $equipoA[1],
                    'veces_jugadas' => 0,
                    'victorias' => 0,
                    'derrotas' => 0,
                    'rayas_ganadas' => 0,
                    'rayas_perdidas' => 0
                ];
            }
            
            $parejasStats[$key]['veces_jugadas']++;
            
            if ($juego['ganador'] == 'A') {
                $parejasStats[$key]['victorias']++;
                $parejasStats[$key]['rayas_ganadas'] += $juego['rayas'];
            } else {
                $parejasStats[$key]['derrotas']++;
                $parejasStats[$key]['rayas_perdidas'] += $juego['rayas'];
            }
        }
        
        // Procesar EQUIPO B
        $equipoB = [];
        if ($juego['equipo_b_jugador1']) $equipoB[] = $juego['equipo_b_jugador1'];
        if ($juego['equipo_b_jugador2']) $equipoB[] = $juego['equipo_b_jugador2'];
        sort($equipoB);
        
        if (count($equipoB) == 2) {
            $key = $equipoB[0] . '_' . $equipoB[1];
            if (!isset($parejasStats[$key])) {
                $parejasStats[$key] = [
                    'jugador1_id' => $equipoB[0],
                    'jugador2_id' => $equipoB[1],
                    'veces_jugadas' => 0,
                    'victorias' => 0,
                    'derrotas' => 0,
                    'rayas_ganadas' => 0,
                    'rayas_perdidas' => 0
                ];
            }
            
            $parejasStats[$key]['veces_jugadas']++;
            
            if ($juego['ganador'] == 'B') {
                $parejasStats[$key]['victorias']++;
                $parejasStats[$key]['rayas_ganadas'] += $juego['rayas'];
            } else {
                $parejasStats[$key]['derrotas']++;
                $parejasStats[$key]['rayas_perdidas'] += $juego['rayas'];
            }
        }
    }
    
    // ============================================
    // 4. Construir array final de parejas
    // ============================================
    $parejas = [];
    foreach ($parejasStats as $key => $stats) {
        // Verificar que los jugadores existen
        if (!isset($jugadoresInfo[$stats['jugador1_id']]) || !isset($jugadoresInfo[$stats['jugador2_id']])) {
            continue;
        }
        
        // Verificar que no sea la misma persona
        if ($stats['jugador1_id'] == $stats['jugador2_id']) {
            continue;
        }
        
        $jugador1 = $jugadoresInfo[$stats['jugador1_id']];
        $jugador2 = $jugadoresInfo[$stats['jugador2_id']];
        
        $rayas_totales = $stats['rayas_ganadas'] - $stats['rayas_perdidas'];
        $porcentaje = $stats['veces_jugadas'] > 0 
            ? round(($stats['victorias'] / $stats['veces_jugadas']) * 100, 1) 
            : 0;
        
        $parejas[] = [
            'jugador1_id' => $stats['jugador1_id'],
            'jugador2_id' => $stats['jugador2_id'],
            'jugador1_nombre' => htmlspecialchars($jugador1['nombre'], ENT_QUOTES, 'UTF-8'),
            'jugador2_nombre' => htmlspecialchars($jugador2['nombre'], ENT_QUOTES, 'UTF-8'),
            'jugador1_img' => $jugador1['img'],
            'jugador2_img' => $jugador2['img'],
            'veces_jugadas' => $stats['veces_jugadas'],
            'victorias' => $stats['victorias'],
            'derrotas' => $stats['derrotas'],
            'rayas_ganadas' => $stats['rayas_ganadas'],
            'rayas_perdidas' => $stats['rayas_perdidas'],
            'rayas_totales' => $rayas_totales,
            'diferencia' => $rayas_totales,
            'diferencia_rayas' => $rayas_totales,
            'porcentaje_victorias' => $porcentaje
        ];
    }
    
    // ============================================
    // 5. Ordenar por diferencia (mejores primero)
    // ============================================
    $mejoresParejas = $parejas;
    usort($mejoresParejas, function($a, $b) {
        if ($a['diferencia'] != $b['diferencia']) {
            return $b['diferencia'] - $a['diferencia'];
        }
        return $b['victorias'] - $a['victorias'];
    });
    $mejoresParejas = array_slice($mejoresParejas, 0, 10);
    
    // Peores parejas
    $peoresParejas = $parejas;
    usort($peoresParejas, function($a, $b) {
        if ($a['diferencia'] != $b['diferencia']) {
            return $a['diferencia'] - $b['diferencia'];
        }
        return $b['derrotas'] - $a['derrotas'];
    });
    $peoresParejas = array_slice($peoresParejas, 0, 10);
    
    // Top rayas totales
    $topRayasTotales = $parejas;
    usort($topRayasTotales, function($a, $b) {
        return $b['rayas_totales'] - $a['rayas_totales'];
    });
    $topRayasTotales = array_slice($topRayasTotales, 0, 10);
    
    // Top rayas ganadas
    $topRayasGanadas = $parejas;
    usort($topRayasGanadas, function($a, $b) {
        return $b['rayas_ganadas'] - $a['rayas_ganadas'];
    });
    $topRayasGanadas = array_slice($topRayasGanadas, 0, 10);
    
    // Top rayas perdidas
    $topRayasPerdidas = $parejas;
    usort($topRayasPerdidas, function($a, $b) {
        return $b['rayas_perdidas'] - $a['rayas_perdidas'];
    });
    $topRayasPerdidas = array_slice($topRayasPerdidas, 0, 10);
    
    // Calcular totales
    $totalJuegosParejas = array_sum(array_column($parejas, 'veces_jugadas'));
    $totalRayasGanadas = array_sum(array_column($parejas, 'rayas_ganadas'));
    $totalRayasPerdidas = array_sum(array_column($parejas, 'rayas_perdidas'));
    
    echo json_encode([
        'success' => true,
        'total_parejas' => count($parejas),
        'total_juegos_analizados' => $totalJuegosParejas,
        'total_rayas_ganadas' => $totalRayasGanadas,
        'total_rayas_perdidas' => $totalRayasPerdidas,
        'total_rayas_neta' => $totalRayasGanadas - $totalRayasPerdidas,
        'parejas' => $parejas,
        'mejores_parejas' => $mejoresParejas,
        'peores_parejas' => $peoresParejas,
        'mejor_pareja' => !empty($mejoresParejas) ? $mejoresParejas[0] : null,
        'peor_pareja' => !empty($peoresParejas) ? $peoresParejas[0] : null,
        'top_rayas_totales' => $topRayasTotales,
        'top_rayas_ganadas' => $topRayasGanadas,
        'top_rayas_perdidas' => $topRayasPerdidas,
        'timestamp' => time()
    ]);
    
} catch(PDOException $e) {
    error_log("Error en get_stats_parejas.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Error de base de datos: ' . $e->getMessage()
    ]);
} catch(Exception $e) {
    error_log("Error en get_stats_parejas.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>