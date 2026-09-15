<?php
// api/test_email.php - Prueba de envio de correos
header('Content-Type: text/html; charset=UTF-8');

// Mostrar errores para depuracion
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Configuracion del correo (CAMBIAR ESTOS VALORES)
$config = array(
    'smtp_host' => 'smtp.gmail.com',
    'smtp_port' => 587,
    'smtp_auth' => true,
    'smtp_user' => 'domino.cazaypesca@gmail.com',
    'smtp_pass' => 'omgw rblr fzsx wele',
    'smtp_secure' => 'tls',
    'from_email' => 'domino.cazaypesca@gmail.com',
    'from_name' => 'Domino Master 9.2 - Test',
    'to_email' => 'erugama@gmail.com',
    'to_name' => 'Usuario de Prueba'
);

$mensaje = '';
$error = '';
$resultado = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $config['smtp_user'] = isset($_POST['smtp_user']) ? $_POST['smtp_user'] : $config['smtp_user'];
    $config['smtp_pass'] = isset($_POST['smtp_pass']) ? $_POST['smtp_pass'] : $config['smtp_pass'];
    $config['to_email'] = isset($_POST['to_email']) ? $_POST['to_email'] : $config['to_email'];
    
    $phpmailerPath = __DIR__ . '/PHPMailer/src/PHPMailer.php';
    
    if (!file_exists($phpmailerPath)) {
        $error = "PHPMailer no esta instalado. Descargalo de: https://github.com/PHPMailer/PHPMailer/archive/refs/heads/master.zip<br>
                  y coloca la carpeta 'src' dentro de 'api/PHPMailer/'";
    } else {
        require_once $phpmailerPath;
        require_once __DIR__ . '/PHPMailer/src/SMTP.php';
        require_once __DIR__ . '/PHPMailer/src/Exception.php';
        
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        
        try {
            $mail->SMTPDebug = 0;
            $mail->isSMTP();
            $mail->Host       = $config['smtp_host'];
            $mail->SMTPAuth   = $config['smtp_auth'];
            $mail->Username   = $config['smtp_user'];
            $mail->Password   = $config['smtp_pass'];
            $mail->SMTPSecure = $config['smtp_secure'];
            $mail->Port       = $config['smtp_port'];
            
            $mail->setFrom($config['from_email'], $config['from_name']);
            $mail->addAddress($config['to_email'], $config['to_name']);
            
            $mail->isHTML(true);
            $mail->Subject = 'Prueba de correo - Domino Master 9.2';
            $mail->Body    = '
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; background-color: #1a1a1a; color: #ffffff; padding: 20px; }
                    .container { max-width: 600px; margin: 0 auto; background-color: #2c2c2e; border-radius: 16px; padding: 24px; }
                    .header { text-align: center; border-bottom: 2px solid #ffd700; padding-bottom: 16px; margin-bottom: 20px; }
                    .logo { font-size: 48px; }
                    .titulo { color: #ffd700; font-size: 24px; margin: 10px 0; }
                    .success { color: #30d158; font-size: 20px; text-align: center; margin: 20px 0; }
                </style>
            </head>
            <body>
                <div class="container">
                    <div class="header">
                        <div class="logo">🁚🂓</div>
                        <div class="titulo">Domino Master 9.2</div>
                    </div>
                    <div class="success">✅ ¡Correo de prueba enviado correctamente!</div>
                    <p>Este es un correo de prueba para verificar la configuracion SMTP.</p>
                    <p>Fecha y hora: ' . date('Y-m-d H:i:s') . '</p>
                </div>
            </body>
            </html>
            ';
            
            $mail->AltBody = 'Correo de prueba de Domino Master 9.2. Fecha: ' . date('Y-m-d H:i:s');
            
            $mail->send();
            $resultado = "✅ CORREO ENVIADO EXITOSAMENTE a {$config['to_email']}";
            $mensaje = $resultado;
            
        } catch (Exception $e) {
            $error = "Error al enviar correo: " . $mail->ErrorInfo;
        }
    }
}

$mailConfig = array();
if (function_exists('ini_get')) {
    $mailConfig['SMTP'] = ini_get('SMTP') ? ini_get('SMTP') : 'no configurado';
    $mailConfig['smtp_port'] = ini_get('smtp_port') ? ini_get('smtp_port') : 'no configurado';
    $mailConfig['sendmail_from'] = ini_get('sendmail_from') ? ini_get('sendmail_from') : 'no configurado';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Prueba de correo - Domino Master 9.2</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #0a0a0a;
            color: #fff;
            margin: 0;
            padding: 20px;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: #1c1c1e;
            border-radius: 16px;
            padding: 24px;
            border: 1px solid #333;
        }
        h1 {
            color: #ffd700;
            margin-bottom: 24px;
        }
        .info-card {
            background: #2c2c2e;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 20px;
        }
        .info-card h3 {
            color: #0a84ff;
            margin-bottom: 12px;
        }
        .info-card p {
            margin: 8px 0;
            font-size: 13px;
        }
        label {
            display: block;
            margin-top: 12px;
            margin-bottom: 6px;
            font-weight: 600;
        }
        input {
            width: 100%;
            padding: 10px;
            background: #2c2c2e;
            border: 1px solid #3d3d41;
            border-radius: 8px;
            color: #fff;
            font-size: 14px;
            box-sizing: border-box;
        }
        button {
            background: #0a84ff;
            color: #fff;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            margin-top: 20px;
            width: 100%;
        }
        button:hover {
            background: #0066cc;
        }
        .success {
            background: rgba(48, 209, 88, 0.2);
            border: 1px solid #30d158;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            color: #30d158;
        }
        .error {
            background: rgba(255, 69, 58, 0.2);
            border: 1px solid #ff453a;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            color: #ff453a;
        }
        .warning {
            background: rgba(255, 159, 10, 0.2);
            border: 1px solid #ff9f0a;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            color: #ff9f0a;
        }
        hr {
            border-color: #333;
            margin: 20px 0;
        }
        code {
            background: #000;
            padding: 2px 6px;
            border-radius: 4px;
            font-family: monospace;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        td {
            padding: 8px;
            border-bottom: 1px solid #333;
        }
        td:first-child {
            font-weight: 600;
            width: 40%;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>📧 Prueba de envio de correos - Domino Master 9.2</h1>
        
        <?php if ($mensaje): ?>
            <div class="success"><?php echo $mensaje; ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="error">❌ <?php echo $error; ?></div>
        <?php endif; ?>
        
        <div class="info-card">
            <h3>🔧 Configuracion actual de PHP</h3>
            <table>
                <tr><td>SMTP</td><td><code><?php echo $mailConfig['SMTP']; ?></code></td></tr>
                <tr><td>Puerto SMTP</td><td><code><?php echo $mailConfig['smtp_port']; ?></code></td></tr>
                <tr><td>Sendmail From</td><td><code><?php echo $mailConfig['sendmail_from']; ?></code></td></tr>
                <tr><td>PHPMailer</td><td><code><?php echo file_exists(__DIR__ . '/PHPMailer/src/PHPMailer.php') ? '✅ Instalado' : '❌ No instalado'; ?></code></td></tr>
            </table>
        </div>
        
        <?php if (!file_exists(__DIR__ . '/PHPMailer/src/PHPMailer.php')): ?>
            <div class="warning">
                ⚠️ <strong>PHPMailer no esta instalado</strong><br>
                Para usar envio de correos, descarga PHPMailer de:<br>
                <code>https://github.com/PHPMailer/PHPMailer/archive/refs/heads/master.zip</code><br>
                Descomprime y copia la carpeta <strong>src</strong> dentro de <strong>api/PHPMailer/</strong>
            </div>
        <?php endif; ?>
        
        <hr>
        
        <form method="POST" action="">
            <label>📧 Servidor SMTP</label>
            <input type="text" name="smtp_host" value="<?php echo htmlspecialchars($config['smtp_host']); ?>" placeholder="smtp.gmail.com">
            
            <label>🔐 Usuario SMTP (tu correo)</label>
            <input type="email" name="smtp_user" value="<?php echo htmlspecialchars($config['smtp_user']); ?>" placeholder="tucorreo@gmail.com" required>
            
            <label>🔑 Contraseña SMTP</label>
            <input type="password" name="smtp_pass" value="Caza&Pesca2026" placeholder="Tu contraseña o contraseña de aplicacion" required>
            
            <label>📬 Correo de destino (prueba)</label>
            <input type="email" name="to_email" value="<?php echo htmlspecialchars($config['to_email']); ?>" placeholder="destinatario@gmail.com" required>
            
            <button type="submit">📧 Enviar correo de prueba</button>
        </form>
        
        <hr>
        
        <div class="info-card">
            <h3>📝 Instrucciones para configurar Gmail</h3>
            <p>1. Activa la verificacion en dos pasos en tu cuenta de Google</p>
            <p>2. Genera una <strong>"Contraseña de aplicacion"</strong> para "Correo" y "Windows"</p>
            <p>3. Usa esa contraseña en el campo "Contraseña SMTP"</p>
            <p>4. El usuario SMTP es tu correo completo (ej: tudominio@gmail.com)</p>
        </div>
    </div>
</body>
</html>