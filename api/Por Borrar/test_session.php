<?php
session_start();
header('Content-Type: application/json');

// Probar si la sesión funciona
$_SESSION['test'] = 'valor de prueba';

echo json_encode([
    'session_id' => session_id(),
    'session_data' => $_SESSION,
    'session_save_path' => session_save_path(),
    'session_status' => session_status()
]);
?>