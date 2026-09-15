<?php
// api/verificar_partida.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/config.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['grupo_id'])) {
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

$partidaId = isset($_GET['partida_id']) ? (int)$_GET['partida_id'] : 0;

if ($partidaId <= 0) {
    echo json_encode(['success' => false, 'error' => 'ID de partida requerido']);
    exit;
}

$grupoId = $_SESSION['grupo_id'];

try {
    $stmt = $pdo->prepare("SELECT estado FROM partidas WHERE id = ? AND grupo_id = ?");
    $stmt->execute([$partidaId, $grupoId]);
    $partida = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($partida) {
        echo json_encode([
            'success' => true,
            'estado' => $partida['estado']
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'Partida no encontrada'
        ]);
    }
    
} catch(Exception $e) {
    error_log("Error en verificar_partida.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>