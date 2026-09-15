<?php
// recalcular_estadisticas.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/config.php';
require_once 'auth.php';

// Verificar autenticación y permisos de administrador
$grupoId = verificarAutenticacion();
if (!$grupoId) {
    exit;
}

$esAdmin = verificarAdmin($pdo);
if (!$esAdmin) {
    exit;
}

try {
    // Limpiar estadísticas actuales del grupo
    $stmt = $pdo->prepare("DELETE FROM estadisticas_jugadores WHERE grupo_id = ?");
    $stmt->execute([$grupoId]);
    
    // Obtener todos los jugadores activos del grupo
    $stmt = $pdo->prepare("SELECT id FROM jugadores WHERE grupo_id = ? AND activo = 1");
    $stmt->execute([$grupoId]);
    $jugadores = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $actualizados = 0;
    
    foreach ($jugadores as $jugador) {
        $jid = $jugador['id'];
        
        // Recalcular estadísticas completas del jugador
        $stmt = $pdo->prepare("
            SELECT 
                COALESCE(SUM(
                    CASE 
                        WHEN (j.equipo_a_jugador1 = ? OR j.equipo_a_jugador2 = ?) AND j.ganador = 'A' THEN j.rayas
                        WHEN (j.equipo_b_jugador1 = ? OR j.equipo_b_jugador2 = ?) AND j.ganador = 'B' THEN j.rayas
                        WHEN (j.equipo_a_jugador1 = ? OR j.equipo_a_jugador2 = ?) AND j.ganador = 'B' THEN -j.rayas
                        WHEN (j.equipo_b_jugador1 = ? OR j.equipo_b_jugador2 = ?) AND j.ganador = 'A' THEN -j.rayas
                        ELSE 0
                    END
                ), 0) as total_rayas,
                COUNT(*) as total_juegos,
                SUM(CASE 
                    WHEN (j.equipo_a_jugador1 = ? OR j.equipo_a_jugador2 = ?) AND j.ganador = 'A' THEN 1
                    WHEN (j.equipo_b_jugador1 = ? OR j.equipo_b_jugador2 = ?) AND j.ganador = 'B' THEN 1
                    ELSE 0 END) as partidas_ganadas,
                SUM(CASE WHEN j.jugador_mano_id = ? THEN 1 ELSE 0 END) as veces_mano
            FROM juegos j
            WHERE (j.equipo_a_jugador1 = ? OR j.equipo_a_jugador2 = ? OR j.equipo_b_jugador1 = ? OR j.equipo_b_jugador2 = ?)
                AND j.grupo_id = ?
        ");
        
        $stmt->execute([
            $jid, $jid, $jid, $jid, $jid, $jid, $jid, $jid,
            $jid, $jid, $jid, $jid,
            $jid,
            $jid, $jid, $jid, $jid,
            $grupoId
        ]);
        
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $partidas_perdidas = $stats['total_juegos'] - $stats['partidas_ganadas'];
        $promedio = $stats['total_juegos'] > 0 ? round($stats['total_rayas'] / $stats['total_juegos'], 2) : 0;
        
        // Insertar estadísticas actualizadas
        $stmt = $pdo->prepare("
            INSERT INTO estadisticas_jugadores 
                (jugador_id, grupo_id, total_rayas, total_juegos, partidas_ganadas, partidas_perdidas, veces_mano, promedio_rayas_por_juego, ultima_actualizacion)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $jid,
            $grupoId,
            $stats['total_rayas'],
            $stats['total_juegos'],
            $stats['partidas_ganadas'],
            $partidas_perdidas,
            $stats['veces_mano'],
            $promedio
        ]);
        
        $actualizados++;
    }
    
    echo json_encode([
        'success' => true,
        'message' => "Estadísticas recalculadas para {$actualizados} jugadores",
        'jugadores_actualizados' => $actualizados
    ]);
    
} catch(Exception $e) {
    error_log("Error en recalcular_estadisticas.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>