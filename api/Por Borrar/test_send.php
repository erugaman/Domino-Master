<?php
// test_send.php
$numero = "4422263002";
$mensaje = "🧪 *PRUEBA FINAL* 🧪\n\n";
$mensaje .= "Este es un mensaje de prueba.\n";
$mensaje .= "Si recibes esto, el bot funciona.\n\n";
$mensaje .= "✅ Domino Master 9.2";

$data = ['to' => $numero, 'message' => $mensaje];

$ch = curl_init('http://localhost:3000/send-message');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: {$httpCode}\n";
echo "Response: {$response}\n";

$result = json_decode($response, true);
if ($httpCode === 200 && isset($result['success']) && $result['success']) {
    echo "\n✅ Mensaje enviado correctamente!\n";
} else {
    echo "\n❌ Error: " . ($result['error'] ?? 'Desconocido') . "\n";
}
?>