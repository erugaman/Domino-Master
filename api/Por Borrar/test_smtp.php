<?php
// test_smtp.php - Prueba de conexión SMTP
require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/src/SMTP.php';
require_once __DIR__ . '/PHPMailer/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$mail = new PHPMailer(true);

try {
    $mail->isSMTP();
    $mail->Host = 'smtp.gmail.com';
    $mail->SMTPAuth = true;
    $mail->Username = 'domino.cazaypesca@gmail.com';
    $mail->Password = 'omgw rblr fzsx wele';
    $mail->SMTPSecure = 'tls';
    $mail->Port = 587;
    $mail->Timeout = 10;
    
    $mail->setFrom('domino.cazaypesca@gmail.com', 'Test');
    $mail->addAddress('domino.cazaypesca@gmail.com', 'Test');
    $mail->Subject = 'Test SMTP';
    $mail->Body = 'Conexión exitosa';
    
    if ($mail->send()) {
        echo "✅ Conexión SMTP exitosa\n";
    } else {
        echo "❌ Error: " . $mail->ErrorInfo . "\n";
    }
} catch (Exception $e) {
    echo "❌ Error SMTP: " . $e->getMessage() . "\n";
}
?>