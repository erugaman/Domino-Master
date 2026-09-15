<?php
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

// Verificar autenticación
$grupoId = verificarAutenticacion();
if (!$grupoId) {
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (!$data || empty($data['usuario_id']) || empty($data['nueva_password'])) {
    echo json_encode(['success' => false, 'error' => 'Faltan datos requeridos']);
    exit;
}

if (strlen($data['nueva_password']) < 4) {
    echo json_encode(['success' => false, 'error' => 'La contraseña debe tener al menos 4 caracteres']);
    exit;
}

try {
    // Verificar que el usuario pertenece al grupo y es el mismo o es admin
    $esAdmin = false;
    $stmt = $pdo->prepare("SELECT rol FROM usuarios WHERE id = ? AND grupo_id = ? AND activo = 1");
    $stmt->execute([$_SESSION['usuario_id'], $grupoId]);
    $usuarioActual = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($usuarioActual && $usuarioActual['rol'] === 'admin') {
        $esAdmin = true;
    }
    
    // Verificar que el usuario a modificar existe y pertenece al grupo
    $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE id = ? AND grupo_id = ?");
    $stmt->execute([$data['usuario_id'], $grupoId]);
    
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Usuario no encontrado']);
        exit;
    }
    
    // Solo el propio usuario o un admin pueden cambiar la contraseña
    if ($data['usuario_id'] != $_SESSION['usuario_id'] && !$esAdmin) {
        echo json_encode(['success' => false, 'error' => 'No tienes permiso para cambiar esta contraseña']);
        exit;
    }
    
    $hashedPassword = password_hash($data['nueva_password'], PASSWORD_DEFAULT);
    
    $stmt = $pdo->prepare("UPDATE usuarios SET password = ? WHERE id = ? AND grupo_id = ?");
    $stmt->execute([$hashedPassword, $data['usuario_id'], $grupoId]);
    
    echo json_encode(['success' => true, 'message' => 'Contraseña actualizada correctamente']);
    
} catch(Exception $e) {
    error_log("Error en cambiar_password.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error al cambiar la contraseña'
    ]);
}
?>