<?php
// test_wa.php
$url = 'http://localhost:3000/status';

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

echo "HTTP Code: " . $httpCode . "\n";
echo "Response: " . $response . "\n";
echo "CURL Error: " . $curlError . "\n";

if ($httpCode === 200) {
    echo "✅ Conexión exitosa con el bot de WhatsApp\n";
} else {
    echo "❌ No se pudo conectar al bot. ¿Está corriendo?\n";
    echo "Ejecuta: cd C:\wamp64\wa-bot && npm start\n";
}
?>