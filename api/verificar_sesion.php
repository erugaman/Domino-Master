<?php
// api/verificar_sesion.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/config.php';
session_start();

if (isset($_SESSION['usuario_id']) && isset($_SESSION['grupo_id'])) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                u.id,
                u.username,
                u.rol,
                u.activo,
                u.jugador_id,
                g.id as grupo_id,
                g.nombre as grupo_nombre,
                g.descripcion as grupo_descripcion,
                g.logo_url as grupo_logo,
                j.nombre as jugador_nombre,
                j.img as jugador_img
            FROM usuarios u
            INNER JOIN grupos g ON u.grupo_id = g.id
            LEFT JOIN jugadores j ON u.jugador_id = j.id AND j.grupo_id = g.id
            WHERE u.id = ? AND u.grupo_id = ?
        ");
        $stmt->execute([$_SESSION['usuario_id'], $_SESSION['grupo_id']]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($usuario && $usuario['activo'] == 1) {
            echo json_encode([
                'success' => true,
                'usuario' => [
                    'id' => $usuario['id'],
                    'username' => htmlspecialchars($usuario['username'], ENT_QUOTES, 'UTF-8'),
                    'rol' => $usuario['rol'],  // ← Enviar el rol
                    'grupo' => [
                        'id' => $usuario['grupo_id'],
                        'nombre' => htmlspecialchars($usuario['grupo_nombre'], ENT_QUOTES, 'UTF-8'),
                        'descripcion' => htmlspecialchars($usuario['grupo_descripcion'] ?? '', ENT_QUOTES, 'UTF-8'),
                        'logo' => $usuario['grupo_logo']
                    ],
                    'jugador' => [
                        'id' => $usuario['jugador_id'],
                        'nombre' => htmlspecialchars($usuario['jugador_nombre'] ?? '', ENT_QUOTES, 'UTF-8'),
                        'img' => $usuario['jugador_img'] ?? "https://api.dicebear.com/7.x/avataaars/svg?seed=" . urlencode($usuario['username'])
                    ]
                ]
            ]);
            exit;
        }
    } catch(Exception $e) {
        error_log("Error en verificar_sesion.php: " . $e->getMessage());
    }
}

echo json_encode(['success' => false]);
?>