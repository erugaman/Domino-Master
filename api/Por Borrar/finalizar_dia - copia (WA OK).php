<?php
// api/finalizar_dia.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

// Verificar autenticacion
$grupoId = verificarAutenticacion();
if (!$grupoId) {
    exit;
}

// Verificar que sea administrador
if (!verificarAdmin($pdo)) {
    exit;
}

// ============================================
// CONFIGURACION
// ============================================

$configCorreo = [
    'smtp_host' => 'smtp.gmail.com',
    'smtp_port' => 587,
    'smtp_auth' => true,
    'smtp_user' => 'domino.cazaypesca@gmail.com',
    'smtp_pass' => 'omgw rblr fzsx wele',
    'smtp_secure' => 'tls',
    'from_email' => 'domino.cazaypesca@gmail.com',
    'from_name' => 'Domino Master 9.2',
];

$configWhatsApp = [
    'api_url' => 'http://localhost:3000',
    'enabled' => true,
    'timeout' => 10
];

// Verificar PHPMailer
$phpmailerPath = __DIR__ . '/PHPMailer/src/PHPMailer.php';
$phpmailerDisponible = file_exists($phpmailerPath);
if ($phpmailerDisponible) {
    require_once $phpmailerPath;
    require_once __DIR__ . '/PHPMailer/src/SMTP.php';
    require_once __DIR__ . '/PHPMailer/src/Exception.php';
}

// ============================================
// FUNCIÓN WHATSAPP
// ============================================

function enviarWhatsApp($destino, $mensaje, $configWhatsApp) {
    $data = [
        'to' => $destino,
        'message' => $mensaje
    ];
    
    $ch = curl_init($configWhatsApp['api_url'] . '/send-message');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $configWhatsApp['timeout']);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        error_log("WhatsApp Error: {$curlError}");
        return false;
    }
    
    $result = json_decode($response, true);
    return ($httpCode === 200 && isset($result['success']) && $result['success'] === true);
}

// ============================================
// FUNCIONES CORREO
// ============================================

function enviarCorreoPHPMailer($destinatario, $nombre, $estadisticas, $grupoNombre, $fechas, $resumen, $config, $grupoId) {
    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $config['smtp_host'];
        $mail->SMTPAuth = true;
        $mail->Username = $config['smtp_user'];
        $mail->Password = $config['smtp_pass'];
        $mail->SMTPSecure = $config['smtp_secure'];
        $mail->Port = $config['smtp_port'];
        $mail->CharSet = 'UTF-8';
        $mail->Timeout = 10;
        
        $mail->setFrom($config['from_email'], $config['from_name']);
        $mail->addAddress($destinatario, $nombre);
        $mail->isHTML(true);
        
        $textoFechas = is_array($fechas) ? implode(' y ', $fechas) : $fechas;
        $mail->Subject = "Domino Master - Ranking {$textoFechas}";
        
        // Buscar estadísticas del jugador
        $misStats = null;
        foreach ($estadisticas as $j) {
            if ($j['nombre'] === $nombre) {
                $misStats = $j;
                break;
            }
        }
        
        if (!$misStats) {
            return false;
        }
        
        $total = isset($misStats['total_rayas']) ? $misStats['total_rayas'] : 0;
        $signo = $total >= 0 ? '+' : '';
        
        $html = "<html><body style='font-family: Arial; background:#0a0a0a; color:#fff; padding:20px;'>";
        $html .= "<div style='max-width:600px; margin:0 auto; background:#1c1c1e; border-radius:16px; padding:20px;'>";
        $html .= "<h2 style='color:#ffd700; text-align:center;'>🏆 Domino Master 9.2</h2>";
        $html .= "<h3 style='color:#0ced3f;'>Ranking del periodo {$textoFechas}</h3>";
        $html .= "<div style='background:#1c3a2a; border-radius:12px; padding:15px; margin:15px 0; border:1px solid #0ced3f;'>";
        $html .= "<h4 style='color:#0ced3f;'>📊 Tu resumen</h4>";
        $html .= "<p>⭐ Rayas: <strong style='color:" . ($total >= 0 ? '#0ced3f' : '#ff453a') . "'>" . $signo . $total . "</strong></p>";
        $html .= "<p>✅ Victorias: <span style='color:#0ced3f;'>" . $misStats['victorias'] . "</span> | ❌ Derrotas: <span style='color:#ff453a;'>" . $misStats['derrotas'] . "</span></p>";
        $html .= "<p>🎲 Juegos: " . $misStats['juegos_jugados'] . " | 🎯 Partidas: " . $misStats['partidas_jugadas'] . "</p>";
        $html .= "</div>";
        
        $html .= "<div style='margin:15px 0;'>";
        $html .= "<h4 style='color:#ffd700;'>🏆 Top 3 del periodo</h4>";
        $top = array_slice($estadisticas, 0, 3);
        foreach ($top as $pos => $j) {
            $totalJ = $j['total_rayas'];
            $signoJ = $totalJ >= 0 ? '+' : '';
            $medalla = $pos == 0 ? '🥇' : ($pos == 1 ? '🥈' : '🥉');
            $html .= "<p>{$medalla} " . $j['nombre'] . ": {$signoJ}{$totalJ} rayas</p>";
        }
        $html .= "</div>";
        
        $html .= "<p><a href='http://frm.noip.me/desarrollo/ranking.html?grupo_id={$grupoId}' style='color:#0ced3f;'>🔗 Ver ranking completo</a></p>";
        $html .= "<hr style='border-color:#333;'>";
        $html .= "<p style='font-size:11px; color:#8e8e93; text-align:center;'>Este es un correo automático de Domino Master 9.2</p>";
        $html .= "</div></body></html>";
        
        $mail->Body = $html;
        $mail->AltBody = "Domino Master 9.2 - Ranking del periodo {$textoFechas}\n\nTu resumen:\nRayas: {$signo}{$total}\nVictorias: " . $misStats['victorias'] . "\nDerrotas: " . $misStats['derrotas'] . "\n\nVisita: http://frm.noip.me/desarrollo/ranking.html?grupo_id={$grupoId}";
        
        return $mail->send();
    } catch (Exception $e) {
        error_log("Error PHPMailer: " . $e->getMessage());
        return false;
    }
}

// ============================================
// FUNCIÓN PARA CONSTRUIR MENSAJE
// ============================================

function construirMensajeRanking($estadisticas, $resumenGeneral, $fechas, $grupoNombre) {
    $textoFechas = is_array($fechas) ? implode(' y ', $fechas) : $fechas;
    
    $mensaje = "🏆 *RANKING DOMINO MASTER 9.2* 🏆\n";
    $mensaje .= "📅 *Periodo:* {$textoFechas}\n";
    $mensaje .= "🎲 *Grupo:* {$grupoNombre}\n";
    $mensaje .= "────────────────────\n\n";
    
    $mensaje .= "*CLASIFICACIÓN GENERAL*\n";
    foreach ($estadisticas as $pos => $j) {
        $total = $j['total_rayas'];
        $signo = $total >= 0 ? '+' : '';
        if ($pos == 0) {
            $medalla = '🥇';
        } elseif ($pos == 1) {
            $medalla = '🥈';
        } elseif ($pos == 2) {
            $medalla = '🥉';
        } else {
            $medalla = ($pos + 1) . ".";
        }
        $mensaje .= "{$medalla} " . $j['nombre'] . ": {$signo}{$total} rayas\n";
        $mensaje .= "   └ ✅" . $j['victorias'] . " ❌" . $j['derrotas'] . " 🎲" . $j['juegos_jugados'] . "\n";
    }
    
    $mensaje .= "\n*RESUMEN GENERAL*\n";
    $mensaje .= "🎲 Partidas: " . $resumenGeneral['total_partidas'] . "\n";
    $mensaje .= "🃏 Juegos: " . $resumenGeneral['total_juegos'] . "\n";
    $mensaje .= "⭐ Rayas: " . $resumenGeneral['total_rayas'] . "\n";
    $mensaje .= "🏆 Puntos: " . $resumenGeneral['total_puntos'] . "\n";
    
    $mensaje .= "\n────────────────────\n";
    $mensaje .= "🤖 Mensaje automático de Domino Master 9.2";
    
    return $mensaje;
}

// ============================================
// FUNCIÓN PARA FECHAS
// ============================================

function obtenerFechasConActividad($pdo, $grupoId) {
    $fechaActual = date('Y-m-d');
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM juegos WHERE grupo_id = ? AND DATE(creado_en) = ?");
    $stmt->execute([$grupoId, $fechaActual]);
    $juegosHoy = $stmt->fetchColumn();
    
    if ($juegosHoy == 0) {
        $stmt = $pdo->prepare("SELECT DISTINCT DATE(creado_en) FROM juegos WHERE grupo_id = ? ORDER BY creado_en DESC LIMIT 1");
        $stmt->execute([$grupoId]);
        $fechaActual = $stmt->fetchColumn();
        if (!$fechaActual) {
            return [];
        }
    }
    
    $fechaAnterior = date('Y-m-d', strtotime($fechaActual . ' -1 day'));
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT p.id) FROM partidas p 
        JOIN juegos j ON j.partida_id = p.id 
        WHERE p.grupo_id = ? AND DATE(p.fecha_inicio) = ? AND DATE(j.creado_en) = ?
    ");
    $stmt->execute([$grupoId, $fechaAnterior, $fechaActual]);
    $tieneActividadAnterior = $stmt->fetchColumn();
    
    if ($tieneActividadAnterior > 0) {
        return [$fechaAnterior, $fechaActual];
    } else {
        return [$fechaActual];
    }
}

// ============================================
// PROCESO PRINCIPAL
// ============================================

try {
    date_default_timezone_set('America/Mexico_City');
    
    // Obtener información del grupo
    $stmt = $pdo->prepare("SELECT nombre, lid_group_wa FROM grupos WHERE id = ?");
    $stmt->execute([$grupoId]);
    $grupoInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$grupoInfo) {
        throw new Exception("Grupo no encontrado");
    }
    
    $grupoNombre = $grupoInfo['nombre'];
    $lidGroupWa = $grupoInfo['lid_group_wa'];
    
    $fechas = obtenerFechasConActividad($pdo, $grupoId);
    
    if (empty($fechas)) {
        echo json_encode(['success' => true, 'message' => 'No hay actividad registrada en el periodo']);
        exit;
    }
    
    $placeholders = implode(',', array_fill(0, count($fechas), '?'));
    
    // Cerrar partidas activas
    $stmt = $pdo->prepare("SELECT id FROM partidas WHERE grupo_id = ? AND estado = 'activa'");
    $stmt->execute([$grupoId]);
    $partidasActivas = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $partidasCerradas = 0;
    
    foreach ($partidasActivas as $pid) {
        $stmt = $pdo->prepare("UPDATE partidas SET estado = 'completada', fecha_fin = NOW() WHERE id = ?");
        $stmt->execute([$pid]);
        $partidasCerradas++;
    }
    
    // Obtener estadisticas de jugadores
    $sql = "
        SELECT j.id, j.nombre, j.email,
            COALESCE(SUM(CASE WHEN (jg.equipo_a_jugador1=j.id OR jg.equipo_a_jugador2=j.id) AND jg.ganador='A' THEN jg.rayas
                              WHEN (jg.equipo_b_jugador1=j.id OR jg.equipo_b_jugador2=j.id) AND jg.ganador='B' THEN jg.rayas ELSE 0 END),0) as rayas_ganadas,
            COALESCE(SUM(CASE WHEN (jg.equipo_a_jugador1=j.id OR jg.equipo_a_jugador2=j.id) AND jg.ganador='B' THEN jg.rayas
                              WHEN (jg.equipo_b_jugador1=j.id OR jg.equipo_b_jugador2=j.id) AND jg.ganador='A' THEN jg.rayas ELSE 0 END),0) as rayas_perdidas,
            COUNT(jg.id) as juegos_jugados,
            COUNT(DISTINCT jg.partida_id) as partidas_jugadas,
            SUM(CASE WHEN (jg.equipo_a_jugador1=j.id OR jg.equipo_a_jugador2=j.id) AND jg.ganador='A' THEN 1
                     WHEN (jg.equipo_b_jugador1=j.id OR jg.equipo_b_jugador2=j.id) AND jg.ganador='B' THEN 1 ELSE 0 END) as victorias
        FROM jugadores j
        LEFT JOIN juegos jg ON (jg.equipo_a_jugador1=j.id OR jg.equipo_a_jugador2=j.id OR jg.equipo_b_jugador1=j.id OR jg.equipo_b_jugador2=j.id)
            AND DATE(jg.creado_en) IN ($placeholders) AND jg.grupo_id = ?
        WHERE j.grupo_id = ? AND j.activo = 1
        GROUP BY j.id HAVING juegos_jugados > 0
        ORDER BY (rayas_ganadas - rayas_perdidas) DESC
    ";
    
    $params = array_merge($fechas, [$grupoId, $grupoId]);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $jugadores = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($jugadores as $key => $j) {
        $jugadores[$key]['total_rayas'] = $j['rayas_ganadas'] - $j['rayas_perdidas'];
        $jugadores[$key]['derrotas'] = $j['juegos_jugados'] - $j['victorias'];
        $jugadores[$key]['nombre'] = htmlspecialchars($j['nombre'], ENT_QUOTES, 'UTF-8');
    }
    
    // Resumen general
    $sqlResumen = "
        SELECT COUNT(DISTINCT partida_id) as total_partidas, COUNT(*) as total_juegos, 
               COALESCE(SUM(rayas), 0) as total_rayas,
               COALESCE(SUM(CASE WHEN ganador='A' THEN puntos_equipo_a ELSE puntos_equipo_b END), 0) as total_puntos
        FROM juegos WHERE DATE(creado_en) IN ($placeholders) AND grupo_id = ?
    ";
    $paramsResumen = array_merge($fechas, [$grupoId]);
    $stmt = $pdo->prepare($sqlResumen);
    $stmt->execute($paramsResumen);
    $resumen = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Valores por defecto
    if (!isset($resumen['total_partidas'])) $resumen['total_partidas'] = 0;
    if (!isset($resumen['total_juegos'])) $resumen['total_juegos'] = 0;
    if (!isset($resumen['total_rayas'])) $resumen['total_rayas'] = 0;
    if (!isset($resumen['total_puntos'])) $resumen['total_puntos'] = 0;
    
    // ============================================
    // ENVIAR NOTIFICACIONES
    // ============================================
    
    $correos = 0;
    $erroresCorreo = array();
    
    // Enviar correos individuales
    foreach ($jugadores as $j) {
        $stmt = $pdo->prepare("SELECT email FROM jugadores WHERE nombre = ? AND grupo_id = ?");
        $stmt->execute([$j['nombre'], $grupoId]);
        $email = $stmt->fetchColumn();
        
        if ($email && !empty($email) && $phpmailerDisponible) {
            if (enviarCorreoPHPMailer($email, $j['nombre'], $jugadores, $grupoNombre, $fechas, $resumen, $configCorreo, $grupoId)) {
                $correos++;
            } else {
                $erroresCorreo[] = $j['nombre'];
            }
        }
    }
    
    // Enviar WhatsApp al grupo
    $whatsappEnviado = false;
    $errorWhatsApp = null;
    
    if ($configWhatsApp['enabled'] && !empty($lidGroupWa) && count($jugadores) > 0) {
        $mensajeRanking = construirMensajeRanking($jugadores, $resumen, $fechas, $grupoNombre);
        $lidNumber = preg_replace('/@g\.us$/', '', $lidGroupWa);
        $mensajeFormateado = $grupoNombre . " (" . $lidNumber . ") -> LID: " . $lidGroupWa . "\n\n" . $mensajeRanking;
        
        if (strlen($mensajeFormateado) > 4000) {
            $mensajeFormateado = substr($mensajeFormateado, 0, 3950) . "\n\n... (mensaje truncado)";
        }
        
        $whatsappEnviado = enviarWhatsApp($lidGroupWa, $mensajeFormateado, $configWhatsApp);
        
        if (!$whatsappEnviado) {
            $errorWhatsApp = "Error al enviar mensaje al grupo";
        }
    } elseif (empty($lidGroupWa)) {
        $errorWhatsApp = "El grupo no tiene lid_group_wa configurado";
    }
    
    $textoFechas = implode(' y ', $fechas);
    $mensajeFinal = "✅ Periodo {$textoFechas} finalizado";
    $mensajeFinal .= " | Partidas cerradas: {$partidasCerradas}";
    $mensajeFinal .= " | Correos enviados: {$correos}";
    
    if ($whatsappEnviado) {
        $mensajeFinal .= " | WhatsApp: Enviado al grupo";
    } elseif ($errorWhatsApp) {
        $mensajeFinal .= " | ⚠️ WhatsApp: {$errorWhatsApp}";
    }
    
    if (!empty($erroresCorreo)) {
        $mensajeFinal .= " | Errores correo: " . implode(', ', $erroresCorreo);
    }
    
    echo json_encode([
        'success' => true,
        'message' => $mensajeFinal,
        'partidas_cerradas' => $partidasCerradas,
        'correos' => $correos,
        'whatsapp_enviado' => $whatsappEnviado,
        'error_whatsapp' => $errorWhatsApp,
        'errores_correo' => $erroresCorreo,
        'fechas' => $fechas,
        'total_jugadores' => count($jugadores),
        'grupo_nombre' => $grupoNombre
    ]);
    
} catch(Exception $e) {
    echo json_encode([
        'success' => false, 
        'error' => $e->getMessage(),
        'line' => $e->getLine(),
        'file' => basename($e->getFile())
    ]);
}
?>