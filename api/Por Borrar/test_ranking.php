<?php
// api/test_ranking.php
header('Content-Type: application/json');

require_once __DIR__ . '/config.php';

try {
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM juegos");
    $count = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'total_juegos' => $count['total'],
        'message' => 'Conexión OK'
    ]);
} catch(Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>