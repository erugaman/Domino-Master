<?php
// crear_grupo.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/config.php';
require_once 'validate_image.php';

$data = json_decode(file_get_contents('php://input'), true);

if (!$data || empty($data['nombre']) || empty($data['admin_nombre']) || empty($data['username']) || empty($data['password'])) {
    echo json_encode(['success' => false, 'error' => 'Faltan datos requeridos']);
    exit;
}

if (strlen($data['password']) < 4) {
    echo json_encode(['success' => false, 'error' => 'La contraseña debe tener al menos 4 caracteres']);
    exit;
}

try {
    $pdo->beginTransaction();
    
    // Validar logo si se proporcionó
    $logoUrl = null;
    if (!empty($data['logo_url'])) {
        $validacion = validarImagenBase64($data['logo_url'], 5242880);
        if (!$validacion['valid']) {
            echo json_encode(['success' => false, 'error' => $validacion['error']]);
            exit;
        }
        $logoUrl = optimizarImagen($data['logo_url'], 200, 75);
    }
    
    // Crear grupo
    $stmt = $pdo->prepare("
        INSERT INTO grupos (nombre, descripcion, logo_url, fecha_creacion)
        VALUES (?, ?, ?, NOW())
    ");
    $stmt->execute([$data['nombre'], $data['descripcion'] ?? '', $logoUrl]);
    $grupoId = $pdo->lastInsertId();
    
    // Crear jugador administrador
    $stmt = $pdo->prepare("
        INSERT INTO jugadores (nombre, email, img, grupo_id, activo, creado_en)
        VALUES (?, ?, ?, ?, 1, NOW())
    ");
    $stmt->execute([$data['admin_nombre'], $data['admin_email'] ?? '', null, $grupoId]);
    $jugadorId = $pdo->lastInsertId();
    
    // Crear usuario administrador
    $hashedPassword = password_hash($data['password'], PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("
        INSERT INTO usuarios (username, password, rol, jugador_id, grupo_id, activo, fecha_creacion)
        VALUES (?, ?, 'admin', ?, ?, 1, NOW())
    ");
    $stmt->execute([$data['username'], $hashedPassword, $jugadorId, $grupoId]);
    
    $pdo->commit();
    
    echo json_encode(['success' => true, 'message' => 'Grupo creado exitosamente']);
    
} catch(Exception $e) {
    if (isset($pdo)) $pdo->rollBack();
    error_log("Error en crear_grupo.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error al crear el grupo: ' . $e->getMessage()]);
}
?>