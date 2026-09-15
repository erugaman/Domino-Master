<?php
// api/get_players.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

// Verificar autenticación
$grupoId = verificarAutenticacion();
if (!$grupoId) {
    exit;
}

// Obtener el rol del usuario
$stmt = $pdo->prepare("SELECT rol FROM usuarios WHERE id = ? AND grupo_id = ?");
$stmt->execute([$_SESSION['usuario_id'], $grupoId]);
$usuario = $stmt->fetch(PDO::FETCH_ASSOC);
$esAdmin = ($usuario && $usuario['rol'] === 'admin');

try {
    // Los usuarios normales también deben ver TODOS los jugadores para poder jugar
    // Solo se restringe la edición, no la visualización
    $stmt = $pdo->prepare("
        SELECT id, nombre as name, email, telefono as phone, fecha_nacimiento as bday, img, creado_en
        FROM jugadores 
        WHERE grupo_id = ? AND activo = 1
        ORDER BY nombre ASC
    ");
    $stmt->execute([$grupoId]);
    $players = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($players as &$p) {
        $p['name'] = htmlspecialchars($p['name'], ENT_QUOTES, 'UTF-8');
        if (!empty($p['email'])) {
            $p['email'] = htmlspecialchars($p['email'], ENT_QUOTES, 'UTF-8');
        }
        if (empty($p['img'])) {
            $p['img'] = "https://api.dicebear.com/7.x/avataaars/svg?seed=" . urlencode($p['name']);
        }
    }
    
    echo json_encode([
        'success' => true,
        'players' => $players,
        'is_admin' => $esAdmin
    ]);
    
} catch(Exception $e) {
    error_log("Error en get_players.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Error al cargar los jugadores'
    ]);
}
?>