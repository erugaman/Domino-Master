<?php
// test_whatsapp_manual.php - Herramienta manual para enviar mensajes por WhatsApp
// Uso: Ejecutar desde navegador o terminal

// Configuración del bot
$configWhatsApp = [
    'api_url' => 'http://localhost:3000',
    'timeout' => 30
];

// ============================================
// FUNCIONES
// ============================================

/**
 * Obtiene el estado del bot
 */
function obtenerEstado($config) {
    $ch = curl_init($config['api_url'] . '/status');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        return json_decode($response, true);
    }
    return null;
}

/**
 * Obtiene los LIDs registrados en el bot
 */
function obtenerLIDs($config) {
    $ch = curl_init($config['api_url'] . '/lids');
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

/**
 * Busca el LID de un número de teléfono
 */
function buscarLIDPorNumero($numero, $lids) {
    $numeroLimpio = preg_replace('/[^0-9]/', '', $numero);
    
    if (substr($numeroLimpio, 0, 2) != '52') {
        $numeroLimpio = '52' . $numeroLimpio;
    }
    
    foreach ($lids as $lid) {
        $identifier = preg_replace('/[^0-9]/', '', $lid['identifier']);
        if ($identifier === $numeroLimpio || strpos($identifier, $numeroLimpio) !== false) {
            return $lid['lid'];
        }
    }
    return null;
}

/**
 * Envía un mensaje por WhatsApp (versión corregida)
 */
function enviarMensaje($destino, $mensaje, $tipoDestino, $config) {
    $data = [];
    
    if ($tipoDestino === 'lid') {
        // Si es LID, aseguramos el formato correcto
        $lidDestino = $destino;
        
        // Verificar que tenga @g.us o @c.us
        if (strpos($lidDestino, '@') === false) {
            // Si no tiene @, probablemente es un LID sin sufijo
            $lidDestino = $lidDestino . '@g.us';
        } elseif (strpos($lidDestino, '@lid') !== false) {
            // Si tiene @lid, reemplazar con formato correcto
            $lidDestino = str_replace('@lid', '@g.us', $lidDestino);
        }
        
        $data = [
            'to' => $lidDestino, 
            'message' => $mensaje
        ];
    } else {
        // Envío por número de teléfono
        $numeroLimpio = preg_replace('/[^0-9]/', '', $destino);
        
        if (strlen($numeroLimpio) < 10) {
            return ['success' => false, 'error' => 'Número inválido (mínimo 10 dígitos)'];
        }
        
        if (substr($numeroLimpio, 0, 2) != '52') {
            $numeroLimpio = '52' . $numeroLimpio;
        }
        
        $data = [
            'to' => $numeroLimpio, 
            'message' => $mensaje
        ];
    }
    
    $ch = curl_init($config['api_url'] . '/send-message');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $config['timeout']);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        return ['success' => false, 'error' => 'CURL Error: ' . $curlError];
    }
    
    $result = json_decode($response, true);
    
    return [
        'success' => $httpCode === 200 && isset($result['success']) && $result['success'] === true,
        'http_code' => $httpCode,
        'response' => $result
    ];
}

/**
 * Envía un resultado con formato especial de LID
 * NUEVA FUNCIÓN: Envía la cadena completa con el LID como se solicita
 */
function enviarResultadoConLID($groupName, $lidNumber, $resultadoMensaje = '', $config) {
    // Construir el LID completo con el formato correcto
    $fullLID = $lidNumber;
    
    // Asegurar que tenga @g.us
    if (strpos($fullLID, '@') === false) {
        $fullLID = $fullLID . '@g.us';
    }
    
    // Construir el mensaje con el formato exacto que pide el usuario
    $mensajeFormateado = "{$groupName} ({$lidNumber}) -> LID: {$fullLID}";
    
    // Si hay un resultado adicional, agregarlo
    if (!empty($resultadoMensaje)) {
        $mensajeFormateado .= "\n\n" . $resultadoMensaje;
    }
    
    // Enviar el mensaje
    $data = [
        'to' => $fullLID,
        'message' => $mensajeFormateado
    ];
    
    $ch = curl_init($config['api_url'] . '/send-message');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $config['timeout']);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        return ['success' => false, 'error' => 'CURL Error: ' . $curlError];
    }
    
    $result = json_decode($response, true);
    
    return [
        'success' => $httpCode === 200 && isset($result['success']) && $result['success'] === true,
        'http_code' => $httpCode,
        'response' => $result,
        'message_sent' => $mensajeFormateado
    ];
}

// ============================================
// PROCESAR ENVÍO (si se envió el formulario)
// ============================================

$resultado = null;
$mostrarFormulario = true;
$tipoEnvio = $_POST['tipo_envio'] ?? 'normal';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tipoDestino = $_POST['tipo_destino'] ?? 'numero';
    $destino = trim($_POST['destino'] ?? '');
    $mensaje = trim($_POST['mensaje'] ?? '');
    $tipoEnvio = $_POST['tipo_envio'] ?? 'normal';
    
    // Variables para envío con LID especial
    $groupName = trim($_POST['group_name'] ?? '');
    $lidNumber = trim($_POST['lid_number'] ?? '');
    $resultMessage = trim($_POST['result_message'] ?? '');
    
    if ($tipoEnvio === 'resultado_lid') {
        // Envío especial con formato LID
        if (empty($groupName)) {
            $resultado = ['success' => false, 'error' => 'Ingresa el nombre del grupo'];
        } elseif (empty($lidNumber)) {
            $resultado = ['success' => false, 'error' => 'Ingresa el número LID'];
        } else {
            $resultado = enviarResultadoConLID($groupName, $lidNumber, $resultMessage, $configWhatsApp);
        }
    } else {
        // Envío normal
        if (empty($destino)) {
            $resultado = ['success' => false, 'error' => 'Ingresa un destino (número o LID)'];
        } elseif (empty($mensaje)) {
            $resultado = ['success' => false, 'error' => 'Ingresa un mensaje'];
        } else {
            $resultado = enviarMensaje($destino, $mensaje, $tipoDestino, $configWhatsApp);
        }
    }
}

// Obtener estado del bot
$estado = obtenerEstado($configWhatsApp);
$lids = obtenerLIDs($configWhatsApp);

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WhatsApp Bot - Domino Master 9.2</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;
            background: #0a0a0a;
            color: #ffffff;
            padding: 20px;
        }
        
        .container {
            max-width: 900px;
            margin: 0 auto;
        }
        
        .header {
            background: linear-gradient(135deg, #1c1c1e 0%, #2c2c2e 100%);
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 24px;
            text-align: center;
            border: 1px solid #333;
        }
        
        .logo {
            font-size: 48px;
            margin-bottom: 8px;
        }
        
        .title {
            color: #ffd700;
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 8px;
        }
        
        .subtitle {
            color: #0ced3f;
            font-size: 13px;
        }
        
        .status-card {
            background: #1c1c1e;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 20px;
            border: 1px solid #333;
        }
        
        .status-online {
            color: #0ced3f;
            font-weight: bold;
        }
        
        .status-offline {
            color: #ff453a;
            font-weight: bold;
        }
        
        .form-card {
            background: #1c1c1e;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            border: 1px solid #333;
        }
        
        .form-group {
            margin-bottom: 16px;
        }
        
        label {
            display: block;
            color: #ffd700;
            font-size: 13px;
            margin-bottom: 6px;
            font-weight: bold;
        }
        
        input, textarea, select {
            width: 100%;
            background: #2c2c2e;
            border: 1px solid #444;
            color: #fff;
            padding: 10px 12px;
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
        }
        
        textarea {
            resize: vertical;
            min-height: 100px;
        }
        
        .radio-group {
            display: flex;
            gap: 20px;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .radio-group label {
            display: flex;
            align-items: center;
            gap: 6px;
            color: #fff;
            font-weight: normal;
            margin: 0;
        }
        
        .radio-group input {
            width: auto;
        }
        
        button {
            background: #0ced3f;
            color: #000;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: bold;
            cursor: pointer;
            width: 100%;
            transition: transform 0.2s;
        }
        
        button:hover {
            transform: scale(1.02);
            background: #0ad83a;
        }
        
        .btn-secondary {
            background: #0a84ff;
            margin-top: 10px;
        }
        
        .btn-secondary:hover {
            background: #096dd9;
        }
        
        .result-card {
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 20px;
            border: 1px solid;
        }
        
        .result-success {
            background: #1c3a2a;
            border-color: #0ced3f;
        }
        
        .result-error {
            background: #3a1c1c;
            border-color: #ff453a;
        }
        
        .result-title {
            font-weight: bold;
            margin-bottom: 10px;
            font-size: 16px;
        }
        
        .result-success .result-title {
            color: #0ced3f;
        }
        
        .result-error .result-title {
            color: #ff453a;
        }
        
        .result-message {
            background: #2c2c2e;
            padding: 10px;
            border-radius: 6px;
            font-family: monospace;
            font-size: 12px;
            margin-top: 10px;
            word-break: break-all;
        }
        
        .lids-card {
            background: #1c1c1e;
            border-radius: 12px;
            padding: 16px;
            border: 1px solid #333;
        }
        
        .lids-title {
            color: #ffd700;
            font-size: 14px;
            font-weight: bold;
            margin-bottom: 12px;
        }
        
        .lid-item {
            background: #2c2c2e;
            border-radius: 8px;
            padding: 10px;
            margin-bottom: 8px;
            font-size: 12px;
            font-family: monospace;
            word-break: break-all;
        }
        
        .lid-identifier {
            color: #0ced3f;
            font-weight: bold;
        }
        
        .lid-value {
            color: #8e8e93;
            font-size: 10px;
            margin-top: 4px;
        }
        
        .btn-copy {
            background: #0a84ff;
            color: #fff;
            border: none;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 10px;
            cursor: pointer;
            margin-top: 6px;
            width: auto;
        }
        
        hr {
            border-color: #333;
            margin: 16px 0;
        }
        
        .footer {
            text-align: center;
            padding: 20px;
            color: #6b6b6b;
            font-size: 12px;
        }
        
        .info-text {
            color: #8e8e93;
            font-size: 11px;
            margin-top: 4px;
        }
        
        .ejemplo {
            background: #2c2c2e;
            padding: 8px;
            border-radius: 6px;
            font-size: 11px;
            margin-top: 8px;
            font-family: monospace;
        }
        
        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: bold;
            margin-left: 8px;
        }
        
        .badge-new {
            background: #ffd700;
            color: #000;
        }
        
        .section-title {
            font-size: 16px;
            font-weight: bold;
            margin-bottom: 15px;
            color: #ffd700;
            border-left: 3px solid #ffd700;
            padding-left: 10px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo">🁚🂓</div>
            <div class="title">Domino Master 9.2</div>
            <div class="subtitle">WhatsApp Bot - Envío Manual</div>
        </div>
        
        <!-- Estado del bot -->
        <div class="status-card">
            <strong>📡 Estado del Bot:</strong>
            <?php if ($estado && isset($estado['connected']) && $estado['connected']): ?>
                <span class="status-online">✅ Conectado</span>
                <span style="color:#8e8e93; margin-left:10px;">📱 Teléfono: <?php echo htmlspecialchars($estado['phone'] ?? 'N/A'); ?></span>
            <?php else: ?>
                <span class="status-offline">❌ Desconectado</span>
                <div class="info-text" style="margin-top:8px;">
                    ⚠️ El bot no está conectado. Ejecuta <code style="background:#333; padding:2px 6px; border-radius:4px;">npm start</code> en la carpeta del bot.
                </div>
            <?php endif; ?>
        </div>
        
        <!-- SECCIÓN ESPECIAL: ENVÍO CON FORMATO LID (NUEVO) -->
        <div class="form-card">
            <div class="section-title">
                🎯 ENVÍO ESPECIAL CON FORMATO LID 
                <span class="badge badge-new">NUEVO</span>
            </div>
            <form method="POST">
                <input type="hidden" name="tipo_envio" value="resultado_lid">
                
                <div class="form-group">
                    <label>🏷️ Nombre del Grupo:</label>
                    <input type="text" name="group_name" value="<?php echo htmlspecialchars($_POST['group_name'] ?? 'Domino (Caza y Pesca)'); ?>" 
                           placeholder="Ej: Domino (Caza y Pesca)">
                    <div class="info-text">Nombre que aparecerá en el mensaje</div>
                </div>
                
                <div class="form-group">
                    <label>🔑 Número LID (sin @g.us):</label>
                    <input type="text" name="lid_number" value="<?php echo htmlspecialchars($_POST['lid_number'] ?? '5214424241200-1367014349'); ?>" 
                           placeholder="Ej: 5214424241200-1367014349">
                    <div class="info-text">Solo el número, sin @g.us</div>
                </div>
                
                <div class="form-group">
                    <label>📝 Mensaje adicional (opcional):</label>
                    <textarea name="result_message" placeholder="Ej: Resultado del juego: Juan ganó con 120 puntos"><?php echo htmlspecialchars($_POST['result_message'] ?? ''); ?></textarea>
                    <div class="info-text">Este texto se agregará después del formato LID</div>
                </div>
                
                <button type="submit">📤 ENVIAR CON FORMATO LID</button>
            </form>
            
            <?php if ($tipoEnvio === 'resultado_lid' && $resultado && isset($resultado['message_sent'])): ?>
            <div class="ejemplo" style="margin-top:15px; background:#0a3a2a;">
                <strong>📨 Formato del mensaje que se enviará:</strong><br>
                <?php echo nl2br(htmlspecialchars($resultado['message_sent'])); ?>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Formulario de envío normal -->
        <div class="form-card">
            <div class="section-title">📨 ENVÍO NORMAL</div>
            <form method="POST">
                <input type="hidden" name="tipo_envio" value="normal">
                
                <div class="form-group">
                    <label>🎯 Tipo de destino:</label>
                    <div class="radio-group">
                        <label>
                            <input type="radio" name="tipo_destino" value="numero" <?php echo (!isset($_POST['tipo_destino']) || $_POST['tipo_destino'] === 'numero') ? 'checked' : ''; ?>>
                            📞 Número de teléfono
                        </label>
                        <label>
                            <input type="radio" name="tipo_destino" value="lid" <?php echo (isset($_POST['tipo_destino']) && $_POST['tipo_destino'] === 'lid') ? 'checked' : ''; ?>>
                            🔑 LID (ID de WhatsApp)
                        </label>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>📱 Destino:</label>
                    <input type="text" name="destino" value="<?php echo htmlspecialchars($_POST['destino'] ?? ''); ?>" 
                           placeholder="<?php echo (!isset($_POST['tipo_destino']) || $_POST['tipo_destino'] === 'numero') ? 'Ej: 4422263002' : 'Ej: 5214424241200-1367014349@g.us'; ?>">
                    <div class="info-text" id="info-destino-normal">
                        <?php if (!isset($_POST['tipo_destino']) || $_POST['tipo_destino'] === 'numero'): ?>
                            💡 Ingresa solo los 10 dígitos (sin código de país). El bot agregará 52 automáticamente.
                        <?php else: ?>
                            💡 Usa el LID exacto como aparece en la lista de contactos registrados.
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>💬 Mensaje:</label>
                    <textarea name="mensaje" placeholder="Escribe tu mensaje aquí...&#10;&#10;Puedes usar *negritas* con asteriscos"><?php echo htmlspecialchars($_POST['mensaje'] ?? ''); ?></textarea>
                    <div class="info-text">
                        💡 Puedes usar *texto* para negritas y \n para saltos de línea
                    </div>
                </div>
                
                <button type="submit">📤 ENVIAR MENSAJE NORMAL</button>
            </form>
        </div>
        
        <!-- Resultado del envío -->
        <?php if ($resultado !== null): ?>
        <div class="result-card <?php echo $resultado['success'] ? 'result-success' : 'result-error'; ?>">
            <div class="result-title">
                <?php echo $resultado['success'] ? '✅ OPERACIÓN EXITOSA' : '❌ ERROR EN LA OPERACIÓN'; ?>
            </div>
            <div style="font-size:13px;">
                <?php if ($resultado['success']): ?>
                    <p>📨 El mensaje fue enviado correctamente.</p>
                    <p>📡 HTTP Code: <?php echo $resultado['http_code']; ?></p>
                    <?php if (isset($resultado['message_sent'])): ?>
                        <div class="result-message">
                            <strong>Mensaje enviado:</strong><br>
                            <?php echo nl2br(htmlspecialchars($resultado['message_sent'])); ?>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <p>⚠️ <?php echo htmlspecialchars($resultado['error'] ?? $resultado['response']['error'] ?? 'Error desconocido'); ?></p>
                    <p>📡 HTTP Code: <?php echo $resultado['http_code']; ?></p>
                    <?php if (isset($resultado['response']['error']) && strpos($resultado['response']['error'], 'LID') !== false): ?>
                        <div class="info-text" style="margin-top:8px;">
                            💡 <strong>Sugerencia:</strong> El número no tiene LID registrado. 
                            Pídele a la persona que envíe un mensaje al bot primero (cualquier texto como "Hola").
                            Una vez que lo haga, aparecerá en la lista de contactos registrados.
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Contactos registrados (LIDs) -->
        <div class="lids-card">
            <div class="lids-title">📋 CONTACTOS REGISTRADOS (LIDs)</div>
            <?php if (!empty($lids)): ?>
                <?php foreach ($lids as $lid): ?>
                <div class="lid-item">
                    <div class="lid-identifier">
                        📱 <?php echo htmlspecialchars($lid['identifier']); ?>
                    </div>
                    <div class="lid-value">
                        🔑 LID: <?php echo htmlspecialchars($lid['lid']); ?>
                    </div>
                    <button class="btn-copy" onclick="copiarTexto('<?php echo htmlspecialchars($lid['lid']); ?>')">
                        📋 Copiar LID
                    </button>
                </div>
                <?php endforeach; ?>
                <div class="info-text" style="margin-top:10px;">
                    ✅ Estos contactos ya han interactuado con el bot y pueden recibir mensajes.
                </div>
            <?php else: ?>
                <div class="info-text" style="text-align:center; padding:20px;">
                    ⚠️ No hay contactos registrados aún.<br><br>
                    📌 <strong>Para registrar un contacto:</strong><br>
                    1. Pídele a la persona que envíe un mensaje al bot<br>
                    2. El mensaje puede ser cualquier texto como "Hola"<br>
                    3. Una vez enviado, aparecerá automáticamente en esta lista
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Ejemplo de formato LID -->
        <div class="lids-card" style="margin-top:16px;">
            <div class="lids-title">📝 EJEMPLO DEL FORMATO LID QUE SE ENVIARÁ</div>
            <div class="ejemplo">
                <strong>Entrada:</strong><br>
                • Nombre del grupo: Domino (Caza y Pesca)<br>
                • Número LID: 5214424241200-1367014349<br>
                • Mensaje adicional: Resultado: Juan 120, María 95<br>
                <br>
                <strong>Mensaje que se enviará:</strong><br>
                <span style="color:#0ced3f;">
                Domino (Caza y Pesca) (5214424241200-1367014349) -> LID: 5214424241200-1367014349@g.us<br>
                <br>
                Resultado: Juan 120, María 95
                </span>
            </div>
        </div>
        
        <div class="footer">
            Domino Master 9.2 - Sistema de WhatsApp Bot
        </div>
    </div>
    
    <script>
        function copiarTexto(texto) {
            navigator.clipboard.writeText(texto).then(() => {
                alert('✅ LID copiado al portapapeles: ' + texto);
            }).catch(() => {
                prompt('📋 Copia manualmente:', texto);
            });
        }
        
        // Mostrar/ocultar información según tipo de destino
        document.querySelectorAll('input[name="tipo_destino"]').forEach(radio => {
            radio.addEventListener('change', function() {
                const infoDestino = document.getElementById('info-destino-normal');
                const destinoInput = document.querySelector('input[name="destino"]');
                
                if (this.value === 'numero') {
                    infoDestino.innerHTML = '💡 Ingresa solo los 10 dígitos (sin código de país). El bot agregará 52 automáticamente.';
                    destinoInput.placeholder = 'Ej: 4422263002';
                } else {
                    infoDestino.innerHTML = '💡 Usa el LID exacto como aparece en la lista de contactos registrados.';
                    destinoInput.placeholder = 'Ej: 5214424241200-1367014349@g.us';
                }
            });
        });
    </script>
</body>
</html>