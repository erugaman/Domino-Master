<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
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

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        // Obtener información del grupo y usuarios
        $stmt = $pdo->prepare("SELECT nombre FROM grupos WHERE id = ?");
        $stmt->execute([$grupoId]);
        $grupo = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Obtener usuarios del grupo
        $stmt = $pdo->prepare("
            SELECT 
                u.id,
                u.username,
                u.rol,
                u.activo,
                u.fecha_creacion,
                j.id as jugador_id,
                j.nombre as jugador_nombre,
                j.img as jugador_avatar
            FROM usuarios u
            LEFT JOIN jugadores j ON u.jugador_id = j.id AND j.grupo_id = u.grupo_id
            WHERE u.grupo_id = ?
            ORDER BY u.activo DESC, u.username ASC
        ");
        $stmt->execute([$grupoId]);
        $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Obtener jugadores que no tienen usuario asociado
        $stmt = $pdo->prepare("
            SELECT j.id, j.nombre, j.img
            FROM jugadores j
            WHERE j.grupo_id = ? AND j.activo = 1
            AND NOT EXISTS (
                SELECT 1 FROM usuarios u WHERE u.jugador_id = j.id AND u.grupo_id = j.grupo_id
            )
            ORDER BY j.nombre ASC
        ");
        $stmt->execute([$grupoId]);
        $jugadoresDisponibles = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($usuarios as &$u) {
            $u['username'] = htmlspecialchars($u['username'], ENT_QUOTES, 'UTF-8');
            $u['jugador_nombre'] = htmlspecialchars($u['jugador_nombre'], ENT_QUOTES, 'UTF-8');
        }
        foreach ($jugadoresDisponibles as &$j) {
            $j['nombre'] = htmlspecialchars($j['nombre'], ENT_QUOTES, 'UTF-8');
        }
        
        echo json_encode([
            'success' => true,
            'grupo_nombre' => htmlspecialchars($grupo['nombre'], ENT_QUOTES, 'UTF-8'),
            'usuarios' => $usuarios,
            'jugadores_disponibles' => $jugadoresDisponibles
        ]);
        
    } elseif ($method === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!$data || !isset($data['accion'])) {
            echo json_encode(['success' => false, 'error' => 'Acción no especificada']);
            exit;
        }
        
        if ($data['accion'] === 'crear') {
            // Validar datos
            if (empty($data['jugador_id']) || empty($data['username']) || empty($data['password'])) {
                echo json_encode(['success' => false, 'error' => 'Faltan datos requeridos']);
                exit;
            }
            
            if (strlen($data['password']) < 4) {
                echo json_encode(['success' => false, 'error' => 'La contraseña debe tener al menos 4 caracteres']);
                exit;
            }
            
            // Verificar que el jugador existe y pertenece al grupo
            $stmt = $pdo->prepare("SELECT id FROM jugadores WHERE id = ? AND grupo_id = ? AND activo = 1");
            $stmt->execute([$data['jugador_id'], $grupoId]);
            if (!$stmt->fetch()) {
                echo json_encode(['success' => false, 'error' => 'Jugador no válido']);
                exit;
            }
            
            // Verificar que el username no existe
            $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE username = ? AND grupo_id = ?");
            $stmt->execute([$data['username'], $grupoId]);
            if ($stmt->fetch()) {
                echo json_encode(['success' => false, 'error' => 'El nombre de usuario ya existe']);
                exit;
            }
            
            $hashedPassword = password_hash($data['password'], PASSWORD_DEFAULT);
            
            $stmt = $pdo->prepare("
                INSERT INTO usuarios (username, password, rol, jugador_id, grupo_id, activo, fecha_creacion)
                VALUES (?, ?, ?, ?, ?, 1, NOW())
            ");
            $stmt->execute([$data['username'], $hashedPassword, $data['rol'], $data['jugador_id'], $grupoId]);
            
            echo json_encode(['success' => true, 'message' => 'Usuario creado correctamente']);
            
        } elseif ($data['accion'] === 'cambiar_password') {
            if (empty($data['usuario_id']) || empty($data['nueva_password'])) {
                echo json_encode(['success' => false, 'error' => 'Faltan datos requeridos']);
                exit;
            }
            
            if (strlen($data['nueva_password']) < 4) {
                echo json_encode(['success' => false, 'error' => 'La contraseña debe tener al menos 4 caracteres']);
                exit;
            }
            
            // Verificar que el usuario pertenece al grupo
            $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE id = ? AND grupo_id = ?");
            $stmt->execute([$data['usuario_id'], $grupoId]);
            if (!$stmt->fetch()) {
                echo json_encode(['success' => false, 'error' => 'Usuario no válido']);
                exit;
            }
            
            $hashedPassword = password_hash($data['nueva_password'], PASSWORD_DEFAULT);
            
            $stmt = $pdo->prepare("UPDATE usuarios SET password = ? WHERE id = ? AND grupo_id = ?");
            $stmt->execute([$hashedPassword, $data['usuario_id'], $grupoId]);
            
            echo json_encode(['success' => true, 'message' => 'Contraseña actualizada']);
            
        } elseif ($data['accion'] === 'cambiar_rol') {
            if (empty($data['usuario_id']) || empty($data['rol'])) {
                echo json_encode(['success' => false, 'error' => 'Faltan datos requeridos']);
                exit;
            }
            
            if (!in_array($data['rol'], ['admin', 'user'])) {
                echo json_encode(['success' => false, 'error' => 'Rol no válido']);
                exit;
            }
            
            // No permitir cambiar el rol del propio usuario (evitar quitarse admin)
            if ($data['usuario_id'] == $_SESSION['usuario_id']) {
                echo json_encode(['success' => false, 'error' => 'No puedes cambiar tu propio rol']);
                exit;
            }
            
            $stmt = $pdo->prepare("UPDATE usuarios SET rol = ? WHERE id = ? AND grupo_id = ?");
            $stmt->execute([$data['rol'], $data['usuario_id'], $grupoId]);
            
            echo json_encode(['success' => true, 'message' => 'Rol actualizado']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Acción no reconocida']);
        }
        
    } elseif ($method === 'DELETE') {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['usuario_id'])) {
            echo json_encode(['success' => false, 'error' => 'ID de usuario requerido']);
            exit;
        }
        
        // No permitir eliminar el propio usuario
        if ($data['usuario_id'] == $_SESSION['usuario_id']) {
            echo json_encode(['success' => false, 'error' => 'No puedes eliminar tu propio usuario']);
            exit;
        }
        
        // Desactivar usuario en lugar de eliminar
        $stmt = $pdo->prepare("UPDATE usuarios SET activo = 0 WHERE id = ? AND grupo_id = ?");
        $stmt->execute([$data['usuario_id'], $grupoId]);
        
        echo json_encode(['success' => true, 'message' => 'Usuario desactivado']);
        
    } else {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    }
    
} catch(Exception $e) {
    error_log("Error en admin_usuarios.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error en el servidor: ' . $e->getMessage()
    ]);
}
?>