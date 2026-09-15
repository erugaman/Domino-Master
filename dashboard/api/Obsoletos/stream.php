<?php
// dashboard/api/stream.php
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Access-Control-Allow-Origin: *');
header('X-Accel-Buffering: no'); // Importante para evitar buffering

// Deshabilitar buffer de PHP
ob_end_clean();
ob_implicit_flush(true);

require_once '../../config.php';

$grupoId = isset($_GET['grupo_id']) ? (int)$_GET['grupo_id'] : 0;
$lastTimestamp = isset($_GET['last_timestamp']) ? (int)$_GET['last_timestamp'] : 0;

if (!$grupoId) {
    echo "event: error\n";
    echo "data: " . json_encode(['error' => 'No grupo specified']) . "\n\n";
    exit;
}

// Configurar para que no se cierre la conexión
set_time_limit(0);
ignore_user_abort(true);

while (true) {
    try {
        // 1. Verificar si hay nueva partida activa
        $stmt = $pdo->prepare("
            SELECT id, tipo_ronda as tipo, fecha_inicio,
                   (SELECT COUNT(*) FROM juegos WHERE partida_id = p.id AND grupo_id = p.grupo_id) as juegos_jugados
            FROM partidas p
            WHERE p.grupo_id = ? AND p.estado = 'activa'
            ORDER BY p.fecha_inicio DESC
            LIMIT 1
        ");
        $stmt->execute([$grupoId]);
        $partida = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $data = [];
        
        if ($partida) {
            // Obtener jugadores de la partida
            $stmt = $pdo->prepare("
                SELECT j.nombre, j.img, pj.rayas_finales as rayas, pj.juegos_jugados as juegos
                FROM partida_jugadores pj
                INNER JOIN jugadores j ON pj.jugador_id = j.id
                WHERE pj.partida_id = ? AND pj.grupo_id = ?
                ORDER BY pj.rayas_finales DESC
            ");
            $stmt->execute([$partida['id'], $grupoId]);
            $jugadores = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Obtener puntos totales
            $stmt = $pdo->prepare("
                SELECT SUM(puntos_equipo_a) as total_a, SUM(puntos_equipo_b) as total_b
                FROM juegos
                WHERE partida_id = ? AND grupo_id = ?
            ");
            $stmt->execute([$partida['id'], $grupoId]);
            $puntos = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Obtener último juego
            $stmt = $pdo->prepare("
                SELECT num_juego, puntos_equipo_a, puntos_equipo_b, rayas,
                       ja1.nombre as g1, ja2.nombre as g2,
                       jb1.nombre as p1, jb2.nombre as p2
                FROM juegos j
                LEFT JOIN jugadores ja1 ON j.equipo_a_jugador1 = ja1.id
                LEFT JOIN jugadores ja2 ON j.equipo_a_jugador2 = ja2.id
                LEFT JOIN jugadores jb1 ON j.equipo_b_jugador1 = jb1.id
                LEFT JOIN jugadores jb2 ON j.equipo_b_jugador2 = jb2.id
                WHERE j.partida_id = ? AND j.grupo_id = ?
                ORDER BY j.num_juego DESC LIMIT 1
            ");
            $stmt->execute([$partida['id'], $grupoId]);
            $ultimo = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $equipoA = [];
            $equipoB = [];
            if ($ultimo) {
                if ($ultimo['g1']) $equipoA[] = $ultimo['g1'];
                if ($ultimo['g2']) $equipoA[] = $ultimo['g2'];
                if ($ultimo['p1']) $equipoB[] = $ultimo['p1'];
                if ($ultimo['p2']) $equipoB[] = $ultimo['p2'];
            }
            
            $inicio = new DateTime($partida['fecha_inicio']);
            $ahora = new DateTime();
            $diff = $inicio->diff($ahora);
            
            $data = [
                'type' => 'game_update',
                'has_active_game' => true,
                'partida' => [
                    'id' => $partida['id'],
                    'tipo' => $partida['tipo'],
                    'juegos_jugados' => (int)$partida['juegos_jugados'],
                    'puntos_equipo_a' => (int)($puntos['total_a'] ?? 0),
                    'puntos_equipo_b' => (int)($puntos['total_b'] ?? 0),
                    'tiempo_transcurrido' => $diff->format('%H:%I:%S')
                ],
                'jugadores' => $jugadores,
                'ultimo_juego' => $ultimo ? [
                    'num_juego' => $ultimo['num_juego'],
                    'puntos_a' => $ultimo['puntos_equipo_a'],
                    'puntos_b' => $ultimo['puntos_equipo_b'],
                    'rayas' => $ultimo['rayas'],
                    'equipo_a' => $equipoA,
                    'equipo_b' => $equipoB
                ] : null,
                'timestamp' => time()
            ];
        } else {
            $data = [
                'type' => 'no_game',
                'has_active_game' => false,
                'timestamp' => time()
            ];
        }
        
        // 2. Obtener ranking del día
        $hoy = date('Y-m-d');
        $stmt = $pdo->prepare("
            SELECT j.nombre, j.img,
                   COALESCE(SUM(CASE 
                       WHEN (jg.equipo_a_jugador1 = j.id OR jg.equipo_a_jugador2 = j.id) AND jg.ganador = 'A' THEN jg.rayas
                       WHEN (jg.equipo_b_jugador1 = j.id OR jg.equipo_b_jugador2 = j.id) AND jg.ganador = 'B' THEN jg.rayas
                       WHEN (jg.equipo_a_jugador1 = j.id OR jg.equipo_a_jugador2 = j.id) AND jg.ganador = 'B' THEN -jg.rayas
                       ELSE 0
                   END), 0) as rayas_hoy
            FROM jugadores j
            LEFT JOIN juegos jg ON (jg.equipo_a_jugador1 = j.id OR jg.equipo_a_jugador2 = j.id OR 
                                    jg.equipo_b_jugador1 = j.id OR jg.equipo_b_jugador2 = j.id)
                AND DATE(jg.creado_en) = ? AND jg.grupo_id = ?
            WHERE j.grupo_id = ? AND j.activo = 1
            GROUP BY j.id
            HAVING rayas_hoy != 0
            ORDER BY rayas_hoy DESC
            LIMIT 10
        ");
        $stmt->execute([$hoy, $grupoId, $grupoId]);
        $rankingDia = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $data['ranking_dia'] = $rankingDia;
        
        // Enviar evento
        echo "event: update\n";
        echo "data: " . json_encode($data) . "\n\n";
        ob_flush();
        flush();
        
        // Esperar 2 segundos antes de la siguiente verificación
        sleep(2);
        
        // Verificar si el cliente cerró la conexión
        if (connection_aborted()) {
            break;
        }
        
    } catch(Exception $e) {
        error_log("SSE Stream Error: " . $e->getMessage());
        sleep(5);
    }
}
?>