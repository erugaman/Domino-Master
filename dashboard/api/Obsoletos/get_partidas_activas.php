<?php
// dashboard/api/get_partidas_activas.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../api/config.php';

$grupoId = isset($_GET['grupo_id']) ? (int)$_GET['grupo_id'] : 0;

if (!$grupoId) {
    echo json_encode(['success' => false, 'error' => 'Grupo no especificado']);
    exit;
}

try {
    // Obtener todas las partidas activas del grupo
    $stmt = $pdo->prepare("
        SELECT 
            p.id,
            p.tipo_ronda as tipo,
            p.fecha_inicio,
            p.estado,
            (SELECT COUNT(*) FROM juegos WHERE partida_id = p.id AND grupo_id = p.grupo_id) as juegos_jugados,
            (SELECT COUNT(*) FROM partida_jugadores WHERE partida_id = p.id AND grupo_id = p.grupo_id) as total_jugadores
        FROM partidas p
        WHERE p.grupo_id = ? AND p.estado = 'activa'
        ORDER BY p.fecha_inicio ASC
    ");
    $stmt->execute([$grupoId]);
    $partidas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $resultado = [];
    
    foreach ($partidas as $index => $partida) {
        // Obtener jugadores de la partida
        $stmt = $pdo->prepare("
            SELECT j.nombre, j.img, COALESCE(pj.rayas_finales, 0) as rayas, COALESCE(pj.juegos_jugados, 0) as juegos
            FROM partida_jugadores pj
            INNER JOIN jugadores j ON pj.jugador_id = j.id
            WHERE pj.partida_id = ? AND pj.grupo_id = ?
            ORDER BY pj.rayas_finales DESC
        ");
        $stmt->execute([$partida['id'], $grupoId]);
        $jugadores = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // ============================================
        // OBTENER PUNTOS PARCIALES
        // ============================================
        $stmt = $pdo->prepare("
            SELECT 
                equipo_a_puntos, 
                equipo_b_puntos, 
                equipo_a_jugadores, 
                equipo_b_jugadores, 
                juego_num,
                jugador_mano,
                ultima_actualizacion
            FROM puntos_en_vivo
            WHERE partida_id = ? 
            ORDER BY id DESC 
            LIMIT 1
        ");
        $stmt->execute([$partida['id']]);
        $puntosParciales = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Verificar si hay puntos parciales
        $hayJuegoEnCurso = ($puntosParciales && ($puntosParciales['equipo_a_puntos'] > 0 || $puntosParciales['equipo_b_puntos'] > 0));
        
        // ============================================
        // OBTENER EL ÚLTIMO JUEGO COMPLETADO (SIEMPRE EXISTE)
        // ============================================
        $stmt = $pdo->prepare("
            SELECT 
                j.num_juego, 
                j.puntos_equipo_a, 
                j.puntos_equipo_b, 
                j.rayas,
                j.ganador,
                CASE WHEN j.ganador = 'A' THEN ja1.nombre ELSE jb1.nombre END as ganador1,
                CASE WHEN j.ganador = 'A' THEN ja2.nombre ELSE jb2.nombre END as ganador2,
                CASE WHEN j.ganador = 'A' THEN jb1.nombre ELSE ja1.nombre END as perdedor1,
                CASE WHEN j.ganador = 'A' THEN jb2.nombre ELSE ja2.nombre END as perdedor2,
                jm.nombre as jugador_mano,
                j.num_ronda
            FROM juegos j
            LEFT JOIN jugadores ja1 ON j.equipo_a_jugador1 = ja1.id
            LEFT JOIN jugadores ja2 ON j.equipo_a_jugador2 = ja2.id
            LEFT JOIN jugadores jb1 ON j.equipo_b_jugador1 = jb1.id
            LEFT JOIN jugadores jb2 ON j.equipo_b_jugador2 = jb2.id
            LEFT JOIN jugadores jm ON j.jugador_mano_id = jm.id
            WHERE j.partida_id = ? AND j.grupo_id = ?
            ORDER BY j.num_juego DESC 
            LIMIT 1
        ");
        $stmt->execute([$partida['id'], $grupoId]);
        $ultimoJuego = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // ============================================
        // DETERMINAR PUNTOS A MOSTRAR
        // ============================================
        $puntosAMostrar = 0;
        $puntosBMostrar = 0;
        $juegoActualNum = 1;
        $numRonda = 1;
        
        // Obtener el número de ronda del último juego
        if ($ultimoJuego) {
            $numRonda = (int)($ultimoJuego['num_ronda'] ?? 1);
            // Si hay puntos parciales, el juego actual es el siguiente
            $juegoActualNum = $ultimoJuego['num_juego'] + 1;
        }
        
        if ($hayJuegoEnCurso) {
            // Usar puntos parciales
            $puntosAMostrar = (int)$puntosParciales['equipo_a_puntos'];
            $puntosBMostrar = (int)$puntosParciales['equipo_b_puntos'];
            $juegoActualNum = (int)$puntosParciales['juego_num'];
        } elseif ($ultimoJuego) {
            // Sin puntos parciales, mostrar el último juego completado
            $puntosAMostrar = (int)$ultimoJuego['puntos_equipo_a'];
            $puntosBMostrar = (int)$ultimoJuego['puntos_equipo_b'];
            $juegoActualNum = $ultimoJuego['num_juego'] + 1;
        }
        
        // ============================================
        // EQUIPOS DEL JUEGO ACTUAL
        // ============================================
        $equipoAActual = [];
        $equipoBActual = [];
        
        if ($hayJuegoEnCurso && !empty($puntosParciales['equipo_a_jugadores'])) {
            $equipoAActual = explode(',', $puntosParciales['equipo_a_jugadores']);
            $equipoBActual = explode(',', $puntosParciales['equipo_b_jugadores']);
        } elseif ($ultimoJuego) {
            // Usar equipos del último juego completado
            $equipoAActual = array_filter([$ultimoJuego['ganador1'], $ultimoJuego['ganador2']]);
            $equipoBActual = array_filter([$ultimoJuego['perdedor1'], $ultimoJuego['perdedor2']]);
        }
        
        // ============================================
        // NOMBRE DEL JUGADOR MANO
        // ============================================
        $jugadorManoNombre = null;
        if ($puntosParciales && !empty($puntosParciales['jugador_mano']) && $puntosParciales['jugador_mano'] > 0) {
            $stmtMano = $pdo->prepare("SELECT nombre FROM jugadores WHERE id = ?");
            $stmtMano->execute([$puntosParciales['jugador_mano']]);
            $jugadorManoNombre = $stmtMano->fetchColumn();
        } elseif ($ultimoJuego) {
            $jugadorManoNombre = $ultimoJuego['jugador_mano'] ?? null;
        }
        
        // ============================================
        // CALCULAR TIEMPO TRANSCURRIDO
        // ============================================
        $inicio = new DateTime($partida['fecha_inicio']);
        $ahora = new DateTime();
        $diff = $inicio->diff($ahora);
        
        // Escapar nombres
        foreach ($jugadores as &$j) {
            $j['nombre'] = htmlspecialchars($j['nombre'], ENT_QUOTES, 'UTF-8');
            if (empty($j['img'])) {
                $j['img'] = "https://api.dicebear.com/7.x/avataaars/svg?seed=" . urlencode($j['nombre']);
            }
        }
        
        // ============================================
        // DETERMINAR TIPO DE RONDA Y TOTAL DE JUEGOS
        // ============================================
        $tipo = (int)$partida['tipo'];
        $totalJuegos = 3;
        if ($tipo === 5) $totalJuegos = 5;
        if ($tipo === 7) $totalJuegos = 8;
        
        $resultado[] = [
            'mesa_num' => $index + 1,
            'partida' => [
                'id' => $partida['id'],
                'tipo' => $tipo,
                'total_juegos' => $totalJuegos,
                'num_ronda' => $numRonda,
                'juegos_jugados' => (int)$partida['juegos_jugados'],
                'juego_actual' => $juegoActualNum,
                'total_jugadores' => (int)$partida['total_jugadores'],
                'tiempo_transcurrido' => $diff->format('%H:%I:%S'),
                'hay_juego_en_curso' => $hayJuegoEnCurso
            ],
            'jugadores' => $jugadores,
            'marcador' => [
                'equipo_a' => $puntosAMostrar,
                'equipo_b' => $puntosBMostrar,
                'es_parcial' => $hayJuegoEnCurso
            ],
            'equipos' => [
                'equipo_a' => $equipoAActual,
                'equipo_b' => $equipoBActual
            ],
            'ultimo_juego' => $ultimoJuego ? [
                'num_juego' => $ultimoJuego['num_juego'],
                'num_ronda' => $ultimoJuego['num_ronda'],
                'puntos_a' => (int)$ultimoJuego['puntos_equipo_a'],
                'puntos_b' => (int)$ultimoJuego['puntos_equipo_b'],
                'rayas' => (int)$ultimoJuego['rayas'],
                'ganadores' => array_filter([$ultimoJuego['ganador1'], $ultimoJuego['ganador2']]),
                'perdedores' => array_filter([$ultimoJuego['perdedor1'], $ultimoJuego['perdedor2']]),
                'jugador_mano' => $ultimoJuego['jugador_mano'] ?? null
            ] : null,
            'jugador_mano_actual' => $jugadorManoNombre,
            'timestamp' => time()
        ];
    }
    
    echo json_encode([
        'success' => true,
        'total_mesas' => count($resultado),
        'mesas' => $resultado
    ]);
    
} catch(Exception $e) {
    error_log("Error en get_partidas_activas.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>