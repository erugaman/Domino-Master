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
require_once 'validate_image.php';

// Verificar autenticación
$grupoId = verificarAutenticacion();
if (!$grupoId) {
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (!$data || empty($data['name'])) {
    echo json_encode(['success' => false, 'error' => 'Nombre del jugador requerido']);
    exit;
}

try {
    // Validar imagen si se proporcionó
    $imagenFinal = null;
    if (!empty($data['img']) && $data['img'] !== '') {
        // Validar que no sea la imagen por defecto de DiceBear (opcional)
        if (strpos($data['img'], 'dicebear.com') === false) {
            $validacion = validarImagenBase64($data['img'], 5242880); // 5MB max
            
            if (!$validacion['valid']) {
                echo json_encode(['success' => false, 'error' => $validacion['error']]);
                exit;
            }
            
            // Opcional: optimizar la imagen
            $imagenFinal = optimizarImagen($data['img'], 300, 75);
        } else {
            $imagenFinal = $data['img'];
        }
    }
    
    if (empty($data['id'])) {
        // Insertar nuevo jugador
        $stmt = $pdo->prepare("
            INSERT INTO jugadores (nombre, email, telefono, fecha_nacimiento, img, grupo_id, activo, creado_en)
            VALUES (?, ?, ?, ?, ?, ?, 1, NOW())
        ");
        $stmt->execute([
            $data['name'],
            $data['email'] ?? null,
            $data['phone'] ?? null,
            $data['bday'] ?? null,
            $imagenFinal,
            $grupoId
        ]);
        
        echo json_encode(['success' => true, 'message' => 'Jugador creado correctamente']);
        
    } else {
        // Verificar que el jugador pertenezca al grupo
        $stmt = $pdo->prepare("SELECT id FROM jugadores WHERE id = ? AND grupo_id = ? AND activo = 1");
        $stmt->execute([$data['id'], $grupoId]);
        
        if (!$stmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'No tienes permiso para modificar este jugador']);
            exit;
        }
        
        // Actualizar jugador existente
        $sql = "UPDATE jugadores SET nombre = ?, email = ?, telefono = ?, fecha_nacimiento = ?";
        $params = [$data['name'], $data['email'] ?? null, $data['phone'] ?? null, $data['bday'] ?? null];
        
        if ($imagenFinal !== null) {
            $sql .= ", img = ?";
            $params[] = $imagenFinal;
        }
        
        $sql .= " WHERE id = ? AND grupo_id = ?";
        $params[] = $data['id'];
        $params[] = $grupoId;
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        echo json_encode(['success' => true, 'message' => 'Jugador actualizado correctamente']);
    }
    
} catch(Exception $e) {
    error_log("Error en save_player.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error al guardar el jugador: ' . $e->getMessage()]);
}
?>