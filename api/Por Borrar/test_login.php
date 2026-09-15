<?php
session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/config.php';

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['username']) || !isset($data['password'])) {
    echo json_encode(['success' => false, 'error' => 'Usuario y contraseña requeridos']);
    exit;
}

try {
    // Consulta simplificada - igual que test_login.php pero con verificación de contraseña
    $stmt = $pdo->prepare("
        SELECT u.*, g.nombre as grupo_nombre, g.logo_url as grupo_logo,
               j.nombre as jugador_nombre, j.avatar_url as jugador_avatar
        FROM usuarios u
        LEFT JOIN grupos g ON u.grupo_id = g.id
        LEFT JOIN jugadores j ON u.jugador_id = j.id
        WHERE u.username = ? AND u.activo = 1
    ");
    $stmt->execute([$data['username']]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$usuario) {
        echo json_encode(['success' => false, 'error' => 'Usuario no encontrado']);
        exit;
    }
    
    if (!password_verify($data['password'], $usuario['password'])) {
        echo json_encode(['success' => false, 'error' => 'Contraseña incorrecta']);
        exit;
    }
    
    // Guardar sesión
    $_SESSION['usuario_id'] = $usuario['id'];
    $_SESSION['grupo_id'] = $usuario['grupo_id'];
    $_SESSION['jugador_id'] = $usuario['jugador_id'];
    $_SESSION['rol'] = $usuario['rol'];
    
    echo json_encode([
        'success' => true,
        'usuario' => [
            'id' => $usuario['id'],
            'username' => $usuario['username'],
            'rol' => $usuario['rol'],
            'grupo' => [
                'id' => $usuario['grupo_id'],
                'nombre' => $usuario['grupo_nombre'],
                'logo' => $usuario['grupo_logo'] ?? ''
            ],
            'jugador' => [
                'id' => $usuario['jugador_id'],
                'nombre' => $usuario['jugador_nombre'],
                'avatar' => $usuario['jugador_avatar'] ?? ''
            ]
        ]
    ]);
    
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Error de BD: ' . $e->getMessage()]);
} catch(Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
}
?>