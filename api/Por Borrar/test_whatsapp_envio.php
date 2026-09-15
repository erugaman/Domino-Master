<?php
// test_whatsapp_envio.php - Versión con soporte para LID
$configWhatsApp = [
    'api_url' => 'http://localhost:3000',
    'enabled' => true,
    'timeout' => 15
];

// Función para obtener los LIDs registrados en el bot
function obtenerLIDs($configWhatsApp) {
    $ch = curl_init($configWhatsApp['api_url'] . '/lids');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        $data = json_decode($response, true);
        return $data['lids'] ?? [];
    }
    return [];
}

// Función para enviar mensaje usando número o LID directo
function enviarWhatsAppPrueba($destino, $mensaje, $configWhatsApp) {
    // Verificar si el destino ya es un LID (contiene @lid)
    if (strpos($destino, '@lid') !== false) {
        // Ya es un LID, usarlo directamente
        $data = ['to' => $destino, 'message' => $mensaje];
    } else {
        // Es un número de teléfono, limpiarlo
        $numeroLimpio = preg_replace('/[^0-9]/', '', $destino);
        
        if (strlen($numeroLimpio) < 10) {
            return ['success' => false, 'error' => 'Número inválido'];
        }
        
        // Agregar código de país 52 si no tiene
        if (substr($numeroLimpio, 0, 2) != '52') {
            $numeroLimpio = '52' . $numeroLimpio;
        }
        
        $data = ['to' => $numeroLimpio, 'message' => $mensaje];
    }
    
    $ch = curl_init($configWhatsApp['api_url'] . '/send-message');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $configWhatsApp['timeout']);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        return ['success' => false, 'error' => 'CURL Error: ' . $curlError];
    }
    
    $decodedResponse = json_decode($response, true);
    
    return [
        'success' => $httpCode === 200 && isset($decodedResponse['success']) && $decodedResponse['success'] === true,
        'http_code' => $httpCode,
        'response' => $decodedResponse
    ];
}

// ============================================
// EJEMPLOS DE USO
// ============================================

echo "========================================\n";
echo "🚀 ENVIANDO MENSAJE CON WHATSAPP BOT\n";
echo "========================================\n\n";

// Opción 1: Enviar usando LID directo (RECOMENDADO)
echo "📱 Opción 1: Usando LID directo\n";
echo "----------------------------------------\n";

// Obtener los LIDs registrados
$lids = obtenerLIDs($configWhatsApp);
if (!empty($lids)) {
    echo "LIDs disponibles:\n";
    foreach ($lids as $lid) {
        echo "  - {$lid['identifier']} -> {$lid['lid']}\n";
    }
    echo "\n";
    
    // Usar el primer LID encontrado
    $primerLID = $lids[0]['lid'];
    $mensajeLID = "🧪 *PRUEBA CON LID* 🧪\n\n";
    $mensajeLID .= "Este mensaje se envió usando el formato LID.\n";
    $mensajeLID .= "✅ El bot funciona correctamente!\n";
    $mensajeLID .= "🕐 " . date('Y-m-d H:i:s');
    
    echo "Enviando a LID: {$primerLID}\n";
    $resultadoLID = enviarWhatsAppPrueba($primerLID, $mensajeLID, $configWhatsApp);
    
    echo "HTTP Code: " . $resultadoLID['http_code'] . "\n";
    if ($resultadoLID['success']) {
        echo "✅ Mensaje enviado correctamente usando LID!\n\n";
    } else {
        echo "❌ Error: " . ($resultadoLID['response']['error'] ?? 'Desconocido') . "\n\n";
    }
} else {
    echo "⚠️ No hay LIDs registrados. Espera a que alguien te envíe un mensaje primero.\n\n";
}

// Opción 2: Enviar usando número de teléfono (el bot buscará el LID automáticamente)
echo "📱 Opción 2: Usando número de teléfono\n";
echo "----------------------------------------\n";

$numero = "4422263002"; // Solo 10 dígitos
$mensajeNumero = "🧪 *PRUEBA CON NÚMERO* 🧪\n\n";
$mensajeNumero .= "Este mensaje se envió usando el número de teléfono.\n";
$mensajeNumero .= "El bot automáticamente busca el LID correspondiente.\n";
$mensajeNumero .= "✅ Domino Master 9.2\n";
$mensajeNumero .= "🕐 " . date('Y-m-d H:i:s');

echo "Enviando a número: {$numero}\n";
echo "Número formateado: 52{$numero}\n";

$resultadoNumero = enviarWhatsAppPrueba($numero, $mensajeNumero, $configWhatsApp);

echo "HTTP Code: " . $resultadoNumero['http_code'] . "\n";
if ($resultadoNumero['success']) {
    echo "✅ Mensaje enviado correctamente usando número!\n";
    if (isset($resultadoNumero['response']['to'])) {
        echo "📌 ID usado: " . $resultadoNumero['response']['to'] . "\n";
    }
} else {
    echo "❌ Error: " . ($resultadoNumero['response']['error'] ?? 'Desconocido') . "\n";
    echo "\n💡 Sugerencia: Asegúrate de que el número {$numero} te haya enviado un mensaje primero.\n";
    echo "   El bot necesita recibir un mensaje para conocer el LID del contacto.\n";
}

echo "\n========================================\n";
echo "📊 Resumen de LIDs registrados:\n";
echo "========================================\n";
$lidsFinal = obtenerLIDs($configWhatsApp);
if (!empty($lidsFinal)) {
    foreach ($lidsFinal as $lid) {
        echo "🔹 {$lid['identifier']}\n";
        echo "   └─ LID: {$lid['lid']}\n";
    }
} else {
    echo "⚠️ No hay LIDs registrados aún.\n";
}
echo "\n";
?>