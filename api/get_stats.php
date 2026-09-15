<?php
// get_stats.php - Versión que calcula estadísticas en tiempo real
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
    // Obtener todos los jugadores activos del grupo
    $stmt = $pdo->prepare("
        SELECT id, nombre, img 
        FROM jugadores 
        WHERE grupo_id = ? AND activo = 1
    ");
    $stmt->execute([$grupoId]);
    $jugadores = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stats = [];
    
    foreach ($jugadores as $jugador) {
        $jid = $jugador['id'];
        
        // Calcular estadísticas en tiempo real desde la tabla juegos
        $stmtStats = $pdo->prepare("
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
                COUNT(*) as total_juegos
            FROM juegos j
            WHERE (j.equipo_a_jugador1 = ? OR j.equipo_a_jugador2 = ? OR j.equipo_b_jugador1 = ? OR j.equipo_b_jugador2 = ?)
                AND j.grupo_id = ?
        ");
        
        $stmtStats->execute([
            $jid, $jid, $jid, $jid, $jid, $jid, $jid, $jid,
            $jid, $jid, $jid, $jid,
            $grupoId
        ]);
        
        $result = $stmtStats->fetch(PDO::FETCH_ASSOC);
        
        $stats[] = [
            'id' => $jugador['id'],
            'name' => htmlspecialchars($jugador['nombre'], ENT_QUOTES, 'UTF-8'),
            'img' => $jugador['img'] ?: "https://api.dicebear.com/7.x/avataaars/svg?seed=" . urlencode($jugador['nombre']),
            'total_rayas' => (int)$result['total_rayas'],
            'total_juegos' => (int)$result['total_juegos']
        ];
    }
    
    // Ordenar por rayas totales (mayor a menor)
    usort($stats, function($a, $b) {
        return $b['total_rayas'] - $a['total_rayas'];
    });
    
    echo json_encode([
        'success' => true,
        'stats' => $stats
    ]);
    
} catch(Exception $e) {
    error_log("Error en get_stats.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error al obtener estadísticas: ' . $e->getMessage()
    ]);
}
?>