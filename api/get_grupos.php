<?php
// api/get_grupos.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/config.php';

try {
    // Obtener todos los grupos activos
    $stmt = $pdo->prepare("
        SELECT id, nombre, logo_url, descripcion 
        FROM grupos 
        ORDER BY nombre ASC 
        LIMIT 50
    ");
    $stmt->execute();
    $grupos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($grupos as &$g) {
        $g['nombre'] = htmlspecialchars($g['nombre'], ENT_QUOTES, 'UTF-8');
        $g['descripcion'] = htmlspecialchars($g['descripcion'] ?? '', ENT_QUOTES, 'UTF-8');
    }
    
    echo json_encode([
        'success' => true,
        'grupos' => $grupos,
        'total' => count($grupos)
    ]);
    
} catch(PDOException $e) {
    error_log("PDO Error en get_grupos.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Error de base de datos: ' . $e->getMessage()
    ]);
} catch(Exception $e) {
    error_log("Error en get_grupos.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>