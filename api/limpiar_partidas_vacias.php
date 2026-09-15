<?php
// api/limpiar_partidas_vacias.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Desactivar errores en pantalla para no romper el JSON
error_reporting(0);
ini_set('display_errors', 0);

// Intentar cargar configuración
$configLoaded = false;
$paths = ['config.php', '../config.php', '../../config.php', '../../../config.php'];

foreach ($paths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $configLoaded = true;
        break;
    }
}

if (!$configLoaded || !isset($pdo)) {
    echo json_encode(['success' => false, 'error' => 'No se pudo cargar la configuración']);
    exit;
}

$grupoId = isset($_GET['grupo_id']) ? (int)$_GET['grupo_id'] : 0;
$action = isset($_GET['action']) ? $_GET['action'] : 'list';
$modoSimulacion = isset($_GET['simular']) ? filter_var($_GET['simular'], FILTER_VALIDATE_BOOLEAN) : true;

if ($grupoId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Grupo no especificado']);
    exit;
}

try {
    // Buscar partidas sin juegos
    $stmt = $pdo->prepare("
        SELECT p.id, p.tipo_ronda, p.fecha_inicio, p.estado,
               (SELECT COUNT(*) FROM partida_jugadores WHERE partida_id = p.id AND grupo_id = p.grupo_id) as total_jugadores
        FROM partidas p
        WHERE p.grupo_id = ? 
          AND NOT EXISTS (
              SELECT 1 FROM juegos j WHERE j.partida_id = p.id AND j.grupo_id = p.grupo_id
          )
        ORDER BY p.fecha_inicio DESC
    ");
    $stmt->execute([$grupoId]);
    $partidasVacias = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $totalPartidas = count($partidasVacias);
    $eliminadas = 0;
    $errores = [];
    
    if ($action === 'delete' && !$modoSimulacion && $totalPartidas > 0) {
        $pdo->beginTransaction();
        
        try {
            foreach ($partidasVacias as $partida) {
                // Eliminar registros de partida_jugadores
                $stmt = $pdo->prepare("DELETE FROM partida_jugadores WHERE partida_id = ? AND grupo_id = ?");
                $stmt->execute([$partida['id'], $grupoId]);
                
                // Eliminar la partida
                $stmt = $pdo->prepare("DELETE FROM partidas WHERE id = ? AND grupo_id = ?");
                $stmt->execute([$partida['id'], $grupoId]);
                $eliminadas++;
            }
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            $errores[] = $e->getMessage();
        }
    }
    
    echo json_encode([
        'success' => true,
        'modo_simulacion' => $modoSimulacion,
        'action' => $action,
        'total_encontradas' => $totalPartidas,
        'eliminadas' => $eliminadas,
        'partidas' => $partidasVacias,
        'errores' => $errores
    ]);
    
} catch(Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>