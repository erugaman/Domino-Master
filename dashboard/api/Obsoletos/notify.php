<?php
// dashboard/api/notify.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$lastNotify = 0;
$notifyFile = __DIR__ . '/../notify.txt';

if (file_exists($notifyFile)) {
    $lastNotify = (int)file_get_contents($notifyFile);
}

echo json_encode([
    'success' => true,
    'last_notify' => $lastNotify,
    'timestamp' => time()
]);
?>