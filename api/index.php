<?php
// Redirigir peticiones API al directorio api/
$request = $_SERVER['REQUEST_URI'];
if (strpos($request, '/api/') === false) {
    // Si no es API, servir index.html
    readfile('index.html');
    exit;
}

// Para peticiones API, buscar el archivo en la carpeta api
$file = basename($request);
$apiFile = __DIR__ . '/api/' . $file;

if (file_exists($apiFile)) {
    require_once $apiFile;
} else {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Archivo no encontrado: ' . $file]);
}
?>