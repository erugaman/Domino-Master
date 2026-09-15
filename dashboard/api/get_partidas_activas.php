<?php
// dashboard/api/get_partidas_activas.php - Versión con soporte multi-mesa robusto
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../api/config.php';

$grupoId = isset($_GET['grupo_id']) ? (int)$_GET['grupo_id'] : 0;

if (!$grupoId) {
    echo json_encode(['success' => false, 'error' => 'Grupo no especificado']);
    exit;
}

try {
    // ============================================
    // 1. OBTENER TODAS LAS PARTIDAS ACTIVAS DEL GRUPO
    // ============================================
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
        $partidaId = (int)$partida['id'];
        $tipo = (int)$partida['tipo'];
        
        // ============================================
        // 2. OBTENER REGISTRO VIVO (SOLO SI ESTÁ ACTIVO)
        // ============================================
        $stmt = $pdo->prepare("
            SELECT 
                id,
                juego_num,
                ronda,
                equipo_a_puntos,
                equipo_b_puntos,
                equipo_a_jugadores,
                equipo_b_jugadores,
                jugador_mano,
                ultima_actualizacion
            FROM puntos_en_vivo
            WHERE partida_id = ? 
            ORDER BY id DESC 
            LIMIT 1
        ");
        $stmt->execute([$partidaId]);
        $registroVivo = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // ============================================
        // 3. OBTENER ÚLTIMO JUEGO COMPLETADO
        // ============================================
        $stmt = $pdo->prepare("
            SELECT 
                j.num_juego, 
                j.num_ronda,
                j.puntos_equipo_a, 
                j.puntos_equipo_b, 
                j.rayas,
                j.ganador,
                j.equipo_a_jugador1,
                j.equipo_a_jugador2,
                j.equipo_b_jugador1,
                j.equipo_b_jugador2,
                j.jugador_mano_id,
                ja1.nombre as a1_nombre,
                ja2.nombre as a2_nombre,
                jb1.nombre as b1_nombre,
                jb2.nombre as b2_nombre,
                jm.nombre as jugador_mano_nombre
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
        $stmt->execute([$partidaId, $grupoId]);
        $ultimoJuego = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // ============================================
        // 4. OBTENER JUGADORES DE LA PARTIDA
        // ============================================
        $stmt = $pdo->prepare("
            SELECT j.nombre, j.img, 
                   COALESCE(pj.rayas_finales, 0) as rayas, 
                   COALESCE(pj.juegos_jugados, 0) as juegos
            FROM partida_jugadores pj
            INNER JOIN jugadores j ON pj.jugador_id = j.id
            WHERE pj.partida_id = ? AND pj.grupo_id = ?
            ORDER BY pj.rayas_finales DESC
        ");
        $stmt->execute([$partidaId, $grupoId]);
        $jugadores = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // ============================================
        // 5. DETERMINAR ESTADO DEL JUEGO ACTUAL
        // ============================================
        $hayJuegoEnCurso = false;
        $puntosAMostrar = 0;
        $puntosBMostrar = 0;
        $juegoActualNum = 1;
        $rondaActual = 1;
        $jugadorManoNombre = null;
        $equipoAActual = [];
        $equipoBActual = [];
        
        if ($registroVivo) {
            $puntosVivoA = (int)($registroVivo['equipo_a_puntos'] ?? 0);
            $puntosVivoB = (int)($registroVivo['equipo_b_puntos'] ?? 0);
            $hayJuegoEnCurso = ($puntosVivoA > 0 || $puntosVivoB > 0);
            
            if ($hayJuegoEnCurso) {
                // JUEGO EN CURSO: usar datos vivos
                $puntosAMostrar = $puntosVivoA;
                $puntosBMostrar = $puntosVivoB;
                $juegoActualNum = (int)($registroVivo['juego_num'] ?? 1);
                $rondaActual = (int)($registroVivo['ronda'] ?? 1);
                
                if (!empty($registroVivo['equipo_a_jugadores'])) {
                    $equipoAActual = array_filter(array_map('trim', explode(',', $registroVivo['equipo_a_jugadores'])));
                }
                if (!empty($registroVivo['equipo_b_jugadores'])) {
                    $equipoBActual = array_filter(array_map('trim', explode(',', $registroVivo['equipo_b_jugadores'])));
                }
                
                $jugadorManoId = (int)($registroVivo['jugador_mano'] ?? 0);
                if ($jugadorManoId > 0) {
                    $stmtMano = $pdo->prepare("SELECT nombre FROM jugadores WHERE id = ?");
                    $stmtMano->execute([$jugadorManoId]);
                    $jugadorManoNombre = $stmtMano->fetchColumn() ?: null;
                }
            }
        }
        
        // SI NO HAY JUEGO EN CURSO: usar último juego completado
        if (!$hayJuegoEnCurso) {
            if ($ultimoJuego) {
                $juegoActualNum = (int)$ultimoJuego['num_juego'] + 1;
                $rondaActual = (int)($ultimoJuego['num_ronda'] ?? 1);
                
                // Puntos del último juego (referencia histórica)
                $puntosAMostrar = (int)$ultimoJuego['puntos_equipo_a'];
                $puntosBMostrar = (int)$ultimoJuego['puntos_equipo_b'];
                
                // NO asumimos equipos del próximo juego
                $equipoAActual = [];
                $equipoBActual = [];
                
                // Jugador mano: referencia del último juego
                $jugadorManoNombre = $ultimoJuego['jugador_mano_nombre'] ?? null;
            } else {
                // Primer juego de la partida
                $juegoActualNum = 1;
                $rondaActual = 1;
            }
        }
        
        // ============================================
        // 6. CALCULAR INFORMACIÓN DE RONDA (TRIANGULAR 6)
        // ============================================
        $juegosRestantesRonda = 0;
        $juegoEnRonda = 1;
        
        if ($tipo === 6) {
            $juegoEnRonda = (($juegoActualNum - 1) % 3) + 1;
            $juegosRestantesRonda = max(0, 3 - $juegoEnRonda + 1);
            if ($juegosRestantesRonda === 3) $juegosRestantesRonda = 0;
        }
        
        // ============================================
        // 7. CALCULAR TIEMPO TRANSCURRIDO
        // ============================================
        $inicio = new DateTime($partida['fecha_inicio']);
        $ahora = new DateTime();
        $diff = $inicio->diff($ahora);
        
        // ============================================
        // 8. ESCAPAR NOMBRES DE JUGADORES
        // ============================================
        foreach ($jugadores as &$j) {
            $j['nombre'] = htmlspecialchars($j['nombre'], ENT_QUOTES, 'UTF-8');
            if (empty($j['img'])) {
                $j['img'] = "https://api.dicebear.com/7.x/avataaars/svg?seed=" . urlencode($j['nombre']);
            }
        }
        unset($j);
        
        // ============================================
        // 9. CALCULAR TOTAL DE JUEGOS SEGÚN TIPO
        // ============================================
        $totalJuegos = 3;
        if ($tipo === 5) $totalJuegos = 5;
        if ($tipo === 7) $totalJuegos = 8;
        
        // ============================================
        // 10. CONSTRUIR RESULTADO
        // ============================================
        $resultado[] = [
            'mesa_num' => $index + 1,
            'partida' => [
                'id' => $partidaId,
                'tipo' => $tipo,
                'total_juegos' => $totalJuegos,
                'juegos_jugados' => (int)$partida['juegos_jugados'],
                'juego_actual' => $juegoActualNum,
                'num_ronda' => $rondaActual,
                'juego_en_ronda' => $juegoEnRonda,
                'juegos_restantes_ronda' => $juegosRestantesRonda,
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
                'equipo_a' => array_values($equipoAActual),
                'equipo_b' => array_values($equipoBActual)
            ],
            'ultimo_juego' => $ultimoJuego ? [
                'num_juego' => (int)$ultimoJuego['num_juego'],
                'num_ronda' => (int)$ultimoJuego['num_ronda'],
                'puntos_a' => (int)$ultimoJuego['puntos_equipo_a'],
                'puntos_b' => (int)$ultimoJuego['puntos_equipo_b'],
                'rayas' => (int)$ultimoJuego['rayas'],
                'ganadores' => array_values(array_filter([$ultimoJuego['a1_nombre'], $ultimoJuego['a2_nombre']])),
                'perdedores' => array_values(array_filter([$ultimoJuego['b1_nombre'], $ultimoJuego['b2_nombre']])),
                'jugador_mano' => $ultimoJuego['jugador_mano_nombre'] ?? null
            ] : null,
            'jugador_mano_actual' => $jugadorManoNombre
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