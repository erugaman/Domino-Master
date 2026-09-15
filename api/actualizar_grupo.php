<?php
// actualizar_grupo.php
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
require_once 'validate_image.php';

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

if (!$data || empty($data['id']) || empty($data['nombre'])) {
    echo json_encode(['success' => false, 'error' => 'Datos incompletos']);
    exit;
}

// Verificar que el grupo pertenece al usuario
if ($data['id'] != $grupoId) {
    echo json_encode(['success' => false, 'error' => 'No tienes permiso para modificar este grupo']);
    exit;
}

try {
    $logoUrl = null;
    if (!empty($data['logo_url'])) {
        $validacion = validarImagenBase64($data['logo_url'], 5242880);
        if (!$validacion['valid']) {
            echo json_encode(['success' => false, 'error' => $validacion['error']]);
            exit;
        }
        $logoUrl = optimizarImagen($data['logo_url'], 200, 75);
    }
    
    if ($logoUrl !== null) {
        $stmt = $pdo->prepare("UPDATE grupos SET nombre = ?, descripcion = ?, logo_url = ? WHERE id = ?");
        $stmt->execute([$data['nombre'], $data['descripcion'] ?? '', $logoUrl, $data['id']]);
    } else {
        $stmt = $pdo->prepare("UPDATE grupos SET nombre = ?, descripcion = ? WHERE id = ?");
        $stmt->execute([$data['nombre'], $data['descripcion'] ?? '', $data['id']]);
    }
    
    echo json_encode(['success' => true, 'message' => 'Grupo actualizado correctamente']);
    
} catch(Exception $e) {
    error_log("Error en actualizar_grupo.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error al actualizar el grupo']);
}
?>