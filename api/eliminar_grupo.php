<?php
// eliminar_grupo.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

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

$data = json_decode(file_get_contents('php://input'), true);

if (!$data || empty($data['id'])) {
    echo json_encode(['success' => false, 'error' => 'ID de grupo requerido']);
    exit;
}

// Verificar que el grupo pertenece al usuario
if ($data['id'] != $grupoId) {
    echo json_encode(['success' => false, 'error' => 'No tienes permiso para eliminar este grupo']);
    exit;
}

try {
    $pdo->beginTransaction();
    
    // Eliminar datos relacionados (las foreign keys con CASCADE harán el trabajo)
    // Primero eliminamos usuarios
    $stmt = $pdo->prepare("DELETE FROM usuarios WHERE grupo_id = ?");
    $stmt->execute([$grupoId]);
    
    // Eliminar estadísticas
    $stmt = $pdo->prepare("DELETE FROM estadisticas_jugadores WHERE grupo_id = ?");
    $stmt->execute([$grupoId]);
    
    // Eliminar juegos y partidas (CASCADE se encargará)
    $stmt = $pdo->prepare("DELETE FROM partidas WHERE grupo_id = ?");
    $stmt->execute([$grupoId]);
    
    // Eliminar jugadores
    $stmt = $pdo->prepare("DELETE FROM jugadores WHERE grupo_id = ?");
    $stmt->execute([$grupoId]);
    
    // Finalmente eliminar el grupo
    $stmt = $pdo->prepare("DELETE FROM grupos WHERE id = ?");
    $stmt->execute([$grupoId]);
    
    $pdo->commit();
    
    // Limpiar sesión
    session_destroy();
    
    echo json_encode(['success' => true, 'message' => 'Grupo eliminado correctamente']);
    
} catch(Exception $e) {
    if (isset($pdo)) $pdo->rollBack();
    error_log("Error en eliminar_grupo.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error al eliminar el grupo: ' . $e->getMessage()
    ]);
}
?>