<?php
// get_history.php
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
    // Obtener todas las partidas del grupo - usando tipo_ronda
    $stmt = $pdo->prepare("
        SELECT 
            p.id,
            p.tipo_ronda as tipo,
            p.fecha_inicio,
            p.fecha_fin,
            p.estado,
            p.creado_por
        FROM partidas p
        WHERE p.grupo_id = ?
        ORDER BY p.fecha_inicio DESC
    ");
    $stmt->execute([$grupoId]);
    $partidas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($partidas)) {
        echo json_encode([
            'success' => true,
            'history' => [],
            'total_partidas' => 0,
            'message' => 'No hay partidas registradas para este grupo'
        ]);
        exit;
    }
    
    $history = [];
    
    foreach ($partidas as $partida) {
        // Obtener jugadores de la partida
        $stmtJugadores = $pdo->prepare("
            SELECT 
                pj.jugador_id,
                j.nombre,
                j.img,
                pj.rayas_finales,
                pj.juegos_jugados
            FROM partida_jugadores pj
            INNER JOIN jugadores j ON pj.jugador_id = j.id AND j.grupo_id = pj.grupo_id
            WHERE pj.partida_id = ? AND pj.grupo_id = ?
        ");
        $stmtJugadores->execute([$partida['id'], $grupoId]);
        $jugadoresPartida = $stmtJugadores->fetchAll(PDO::FETCH_ASSOC);
        
        $jugadores = [];
        foreach ($jugadoresPartida as $j) {
            $jugadores[] = [
                'id' => $j['jugador_id'],
                'n' => htmlspecialchars($j['nombre'], ENT_QUOTES, 'UTF-8'),
                'img' => $j['img'] ?: "https://api.dicebear.com/7.x/avataaars/svg?seed=" . urlencode($j['nombre']),
                'r' => (int)$j['rayas_finales'],
                'j' => (int)$j['juegos_jugados']
            ];
        }
        
        // Obtener los juegos de esta partida
        $stmtJuegos = $pdo->prepare("
            SELECT 
                j.num_juego,
                j.num_ronda,
                j.puntos_equipo_a,
                j.puntos_equipo_b,
                j.rayas,
                j.ganador,
                COALESCE(j.es_especial, 0) as es_especial,
                j.creado_en,
                ja1.nombre as ganador1_nombre,
                ja2.nombre as ganador2_nombre,
                jb1.nombre as perdedor1_nombre,
                jb2.nombre as perdedor2_nombre,
                jm.nombre as mano_nombre
            FROM juegos j
            LEFT JOIN jugadores ja1 ON j.equipo_a_jugador1 = ja1.id AND ja1.grupo_id = j.grupo_id
            LEFT JOIN jugadores ja2 ON j.equipo_a_jugador2 = ja2.id AND ja2.grupo_id = j.grupo_id
            LEFT JOIN jugadores jb1 ON j.equipo_b_jugador1 = jb1.id AND jb1.grupo_id = j.grupo_id
            LEFT JOIN jugadores jb2 ON j.equipo_b_jugador2 = jb2.id AND jb2.grupo_id = j.grupo_id
            LEFT JOIN jugadores jm ON j.jugador_mano_id = jm.id AND jm.grupo_id = j.grupo_id
            WHERE j.partida_id = ? AND j.grupo_id = ?
            ORDER BY j.num_juego ASC
        ");
        $stmtJuegos->execute([$partida['id'], $grupoId]);
        $juegos = $stmtJuegos->fetchAll(PDO::FETCH_ASSOC);
        
        $log = [];
        foreach ($juegos as $juego) {
            $ganadores = [];
            if ($juego['ganador1_nombre']) {
                $ganadores[] = [
                    'nombre' => htmlspecialchars($juego['ganador1_nombre'], ENT_QUOTES, 'UTF-8')
                ];
            }
            if ($juego['ganador2_nombre']) {
                $ganadores[] = [
                    'nombre' => htmlspecialchars($juego['ganador2_nombre'], ENT_QUOTES, 'UTF-8')
                ];
            }
            
            $perdedores = [];
            if ($juego['perdedor1_nombre']) {
                $perdedores[] = [
                    'nombre' => htmlspecialchars($juego['perdedor1_nombre'], ENT_QUOTES, 'UTF-8')
                ];
            }
            if ($juego['perdedor2_nombre']) {
                $perdedores[] = [
                    'nombre' => htmlspecialchars($juego['perdedor2_nombre'], ENT_QUOTES, 'UTF-8')
                ];
            }
            
            $log[] = [
                'j' => (int)$juego['num_juego'],
                'numRonda' => (int)$juego['num_ronda'],
                'gan' => $ganadores,
                'per' => $perdedores,
                'r' => (int)$juego['rayas'],
                'ptsW' => (int)$juego['puntos_equipo_a'],
                'ptsL' => (int)$juego['puntos_equipo_b'],
                'mano' => $juego['mano_nombre'] ? htmlspecialchars($juego['mano_nombre'], ENT_QUOTES, 'UTF-8') : null,
                'especial' => (bool)$juego['es_especial']
            ];
        }
        
        $history[] = [
            'id' => $partida['id'],
            'tipo' => (string)$partida['tipo'], // Convertir a string para que coincida con el frontend
            'fecha' => $partida['fecha_inicio'],
            'fecha_fin' => $partida['fecha_fin'],
            'estado' => $partida['estado'],
            'jugadores' => $jugadores,
            'log' => $log,
            'total_juegos' => count($log)
        ];
    }
    
    echo json_encode([
        'success' => true,
        'history' => $history,
        'total_partidas' => count($history)
    ]);
    
} catch(Exception $e) {
    error_log("Error en get_history.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error al obtener el historial: ' . $e->getMessage()
    ]);
}
?>