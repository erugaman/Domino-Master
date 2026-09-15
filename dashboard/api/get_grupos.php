<?php
// dashboard/api/get_grupos.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Ruta correcta: desde dashboard/api/ hasta api/config.php
require_once __DIR__ . '/../../api/config.php';

try {
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
    echo json_encode([
        'success' => false,
        'error' => 'Error de base de datos: ' . $e->getMessage()
    ]);
} catch(Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>