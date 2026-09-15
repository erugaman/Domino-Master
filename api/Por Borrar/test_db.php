<?php
require_once __DIR__ . '/config.php';

try {
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM jugadores");
    $result = $stmt->fetch();
    echo json_encode(['success' => true, 'total_jugadores' => $result['total']]);
} catch(Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>