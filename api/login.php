<?php
// login.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/config.php';

session_start();

$data = json_decode(file_get_contents('php://input'), true);

if (!$data || empty($data['username']) || empty($data['password'])) {
    echo json_encode(['success' => false, 'error' => 'Usuario y contraseña requeridos']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT 
            u.id, 
            u.username, 
            u.password, 
            u.rol,
            u.activo,
            u.jugador_id,
            u.grupo_id,
            g.nombre as grupo_nombre,
            g.descripcion as grupo_descripcion,
            g.logo_url as grupo_logo,
            j.nombre as jugador_nombre,
            j.email as jugador_email,
            j.img as jugador_img
        FROM usuarios u
        INNER JOIN grupos g ON u.grupo_id = g.id
        LEFT JOIN jugadores j ON u.jugador_id = j.id AND j.grupo_id = g.id
        WHERE u.username = ? AND u.grupo_id = g.id
    ");
    $stmt->execute([$data['username']]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$usuario) {
        echo json_encode(['success' => false, 'error' => 'Usuario no encontrado']);
        exit;
    }
    
    if ($usuario['activo'] != 1) {
        echo json_encode(['success' => false, 'error' => 'Usuario desactivado']);
        exit;
    }
    
    if (!password_verify($data['password'], $usuario['password'])) {
        echo json_encode(['success' => false, 'error' => 'Contraseña incorrecta']);
        exit;
    }
    
    // Guardar sesión
    $_SESSION['usuario_id'] = $usuario['id'];
    $_SESSION['usuario_nombre'] = $usuario['username'];
    $_SESSION['rol'] = $usuario['rol'];
    $_SESSION['grupo_id'] = $usuario['grupo_id'];
    $_SESSION['jugador_id'] = $usuario['jugador_id'];
    

	// api/login.php - Asegurar que el rol está en la respuesta
	// Buscar esta sección y verificar que 'rol' esté incluido

	echo json_encode([
		'success' => true,
		'usuario' => [
			'id' => $usuario['id'],
			'username' => htmlspecialchars($usuario['username'], ENT_QUOTES, 'UTF-8'),
			'rol' => $usuario['rol'],  // ← Asegurar que está aquí
			'grupo' => [
				'id' => $usuario['grupo_id'],
				'nombre' => htmlspecialchars($usuario['grupo_nombre'], ENT_QUOTES, 'UTF-8'),
				'descripcion' => htmlspecialchars($usuario['grupo_descripcion'] ?? '', ENT_QUOTES, 'UTF-8'),
				'logo' => $usuario['grupo_logo']
			],
			'jugador' => [
				'id' => $usuario['jugador_id'],
				'nombre' => htmlspecialchars($usuario['jugador_nombre'] ?? '', ENT_QUOTES, 'UTF-8'),
				'email' => htmlspecialchars($usuario['jugador_email'] ?? '', ENT_QUOTES, 'UTF-8'),
				'img' => $usuario['jugador_img'] ?? "https://api.dicebear.com/7.x/avataaars/svg?seed=" . urlencode($usuario['username'])
			]
		]
	]);

    
} catch(Exception $e) {
    error_log("Error en login.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error al iniciar sesión']);
}
?>