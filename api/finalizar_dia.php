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
// FUNCION PARA RECALCULAR ESTADISTICAS DE JUGADORES
// ============================================

function recalcularEstadisticasJugadores($pdo, $grupoId) {
    // Obtener todos los jugadores activos del grupo
    $stmt = $pdo->prepare("SELECT id FROM jugadores WHERE grupo_id = ? AND activo = 1");
    $stmt->execute([$grupoId]);
    $jugadores = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $actualizados = 0;
    
    foreach ($jugadores as $jugador) {
        $jid = $jugador['id'];
        
        // Recalcular estadísticas completas del jugador desde la tabla juegos
        $stmt = $pdo->prepare("
            SELECT 
                COALESCE(SUM(
                    CASE 
                        WHEN (j.equipo_a_jugador1 = ? OR j.equipo_a_jugador2 = ?) AND j.ganador = 'A' THEN j.rayas
                        WHEN (j.equipo_b_jugador1 = ? OR j.equipo_b_jugador2 = ?) AND j.ganador = 'B' THEN j.rayas
                        WHEN (j.equipo_a_jugador1 = ? OR j.equipo_a_jugador2 = ?) AND j.ganador = 'B' THEN -j.rayas
                        WHEN (j.equipo_b_jugador1 = ? OR j.equipo_b_jugador2 = ?) AND j.ganador = 'A' THEN -j.rayas
                        ELSE 0
                    END
                ), 0) as total_rayas,
                COUNT(*) as total_juegos,
                SUM(CASE 
                    WHEN (j.equipo_a_jugador1 = ? OR j.equipo_a_jugador2 = ?) AND j.ganador = 'A' THEN 1
                    WHEN (j.equipo_b_jugador1 = ? OR j.equipo_b_jugador2 = ?) AND j.ganador = 'B' THEN 1
                    ELSE 0 END) as partidas_ganadas,
                SUM(CASE WHEN j.jugador_mano_id = ? THEN 1 ELSE 0 END) as veces_mano
            FROM juegos j
            WHERE (j.equipo_a_jugador1 = ? OR j.equipo_a_jugador2 = ? OR j.equipo_b_jugador1 = ? OR j.equipo_b_jugador2 = ?)
                AND j.grupo_id = ?
        ");
        
        $stmt->execute([
            $jid, $jid, $jid, $jid, $jid, $jid, $jid, $jid,
            $jid, $jid, $jid, $jid,
            $jid,
            $jid, $jid, $jid, $jid,
            $grupoId
        ]);
        
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $partidas_perdidas = $stats['total_juegos'] - $stats['partidas_ganadas'];
        $promedio = $stats['total_juegos'] > 0 ? round($stats['total_rayas'] / $stats['total_juegos'], 2) : 0;
        
        // Actualizar o insertar en estadisticas_jugadores
        $stmt = $pdo->prepare("
            INSERT INTO estadisticas_jugadores 
                (jugador_id, grupo_id, total_rayas, total_juegos, partidas_ganadas, partidas_perdidas, veces_mano, promedio_rayas_por_juego, ultima_actualizacion)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                total_rayas = VALUES(total_rayas),
                total_juegos = VALUES(total_juegos),
                partidas_ganadas = VALUES(partidas_ganadas),
                partidas_perdidas = VALUES(partidas_perdidas),
                veces_mano = VALUES(veces_mano),
                promedio_rayas_por_juego = VALUES(promedio_rayas_por_juego),
                ultima_actualizacion = NOW()
        ");
        
        $stmt->execute([
            $jid,
            $grupoId,
            $stats['total_rayas'],
            $stats['total_juegos'],
            $stats['partidas_ganadas'],
            $partidas_perdidas,
            $stats['veces_mano'],
            $promedio
        ]);
        
        $actualizados++;
    }
    
    return $actualizados;
}

// ============================================
// FUNCION WHATSAPP
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

function enviarCorreoPHPMailerCompleto($destinatario, $nombre, $estadisticasCompletas, $rankingGeneral, $grupoNombre, $fechas, $resumenGeneral, $partidasDelDia, $config, $grupoId) {
    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        
        $mail->isSMTP();
        $mail->Host       = $config['smtp_host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $config['smtp_user'];
        $mail->Password   = $config['smtp_pass'];
        $mail->SMTPSecure = $config['smtp_secure'];
        $mail->Port       = $config['smtp_port'];
        $mail->CharSet    = 'UTF-8';
        $mail->SMTPDebug  = 0;
        
        $mail->setFrom($config['from_email'], $config['from_name']);
        $mail->addAddress($destinatario, $nombre);
        
        $mail->isHTML(true);
        
        $textoFechas = is_array($fechas) ? implode(' y ', $fechas) : $fechas;
        $mail->Subject = "Domino Master 9.2 - Ranking del periodo {$textoFechas} - {$grupoNombre}";
        
        $mail->Body    = generarHTMLRankingCompleto($nombre, $estadisticasCompletas, $rankingGeneral, $grupoNombre, $fechas, $resumenGeneral, $partidasDelDia, $grupoId);
        $mail->AltBody = generarTextoRankingCompleto($nombre, $estadisticasCompletas, $rankingGeneral, $resumenGeneral, $partidasDelDia);
        
        return $mail->send();
    } catch (Exception $e) {
        error_log("Error PHPMailer: " . $e->getMessage());
        return false;
    }
}

function generarHTMLRankingCompleto($nombre, $estadisticasCompletas, $rankingGeneral, $grupoNombre, $fechas, $resumenGeneral, $partidasDelDia, $grupoId) {
    $misEstadisticas = null;
    foreach ($estadisticasCompletas as $j) {
        if ($j['nombre'] === $nombre) {
            $misEstadisticas = $j;
            break;
        }
    }
    
    $textoFechas = is_array($fechas) ? implode(' y ', $fechas) : $fechas;
    $fechaEnlace = is_array($fechas) ? $fechas[count($fechas)-1] : $fechas;
    $enlaceRanking = "http://frm.noip.me/desarrollo/ranking.html?grupo_id={$grupoId}&fecha={$fechaEnlace}";
    
    $html = '<!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; background-color: #0a0a0a; padding: 20px; margin: 0; }
            .container { max-width: 800px; margin: 0 auto; background-color: #1c1c1e; border-radius: 16px; padding: 24px; border: 1px solid #333; }
            .header { text-align: center; border-bottom: 2px solid #ffd700; padding-bottom: 16px; margin-bottom: 20px; }
            .logo { font-size: 48px; }
            .titulo { color: #ffd700; font-size: 24px; margin: 10px 0; font-weight: bold; }
            .subtitulo { color: #0ced3f; font-size: 13px; }
            .fechas-nota { background: rgba(255, 215, 0, 0.15); border-radius: 8px; padding: 8px; text-align: center; margin: 10px 0; font-size: 12px; color: #ffd700; }
            .resumen-general { background-color: #2c2c2e; border-radius: 12px; padding: 16px; margin: 20px 0; }
            .resumen-general-title { text-align: center; margin-bottom: 12px; color: #ffd700; font-weight: bold; }
            .resumen-general-stats { display: flex; justify-content: space-around; text-align: center; flex-wrap: wrap; gap: 16px; }
            .resumen-general-item .label { font-size: 11px; color: #8e8e93; }
            .resumen-general-item .value { font-size: 24px; font-weight: bold; color: #ffd700; }
            .resumen-personal { background-color: #2c2c2e; border-radius: 12px; padding: 16px; margin: 20px 0; text-align: center; }
            .resumen-personal-label { font-size: 13px; color: #8e8e93; margin-bottom: 8px; }
            .resumen-personal-valor { font-size: 36px; font-weight: bold; color: #ffd700; }
            .resumen-personal-detalle { display: flex; justify-content: center; gap: 24px; margin-top: 12px; flex-wrap: wrap; }
            .resumen-detalle-item { text-align: center; }
            .resumen-detalle-item .label { font-size: 11px; color: #8e8e93; }
            .resumen-detalle-item .value { font-size: 18px; font-weight: bold; }
            .ranking-section { margin: 20px 0; }
            .ranking-title { font-weight: bold; margin-bottom: 12px; font-size: 16px; color: #ffd700; border-left: 3px solid #ffd700; padding-left: 10px; }
            .stats-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
            .stats-table th { text-align: left; padding: 10px 8px; background-color: #2c2c2e; color: #ffd700; font-size: 12px; }
            .stats-table td { padding: 8px; border-bottom: 1px solid #2c2c2e; }
            .stats-table tr.destacado td { background-color: rgba(255, 215, 0, 0.15); }
            .txt-pos { color: #30d158; font-weight: bold; }
            .txt-neg { color: #ff453a; font-weight: bold; }
            .txt-white { color: #ffffff; }
            .footer { text-align: center; margin-top: 24px; padding-top: 16px; border-top: 1px solid #2c2c2e; font-size: 11px; color: #8e8e93; }
            a, a:visited, a:hover, a:active { color: #0a84ff !important; text-decoration: none; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <div class="logo">🁚🂓</div>
                <div class="titulo">Domino Master 9.2</div>
                <div class="subtitulo">Ranking del periodo ' . $textoFechas . ' - ' . htmlspecialchars($grupoNombre) . '</div>
                ' . (is_array($fechas) && count($fechas) > 1 ? '<div class="fechas-nota">📅 Periodo que abarca ' . $textoFechas . ' (sesión de juego nocturna)</div>' : '') . '
            </div>';
    
    if ($resumenGeneral && $resumenGeneral['total_partidas'] > 0) {
        $html .= '
            <div class="resumen-general">
                <div class="resumen-general-title">📊 RESUMEN DEL PERIODO</div>
                <div class="resumen-general-stats">
                    <div class="resumen-general-item">
                        <div class="label">🎲 Partidas Jugadas</div>
                        <div class="value">' . $resumenGeneral['total_partidas'] . '</div>
                    </div>
                    <div class="resumen-general-item">
                        <div class="label">🃏 Juegos Realizados</div>
                        <div class="value">' . $resumenGeneral['total_juegos'] . '</div>
                    </div>
                    <div class="resumen-general-item">
                        <div class="label">⭐ Total Rayas</div>
                        <div class="value">' . $resumenGeneral['total_rayas_generadas'] . '</div>
                    </div>
                    <div class="resumen-general-item">
                        <div class="label">🏆 Total Puntos</div>
                        <div class="value">' . $resumenGeneral['total_puntos'] . '</div>
                    </div>
                </div>
            </div>';
    }
    
    if ($misEstadisticas) {
        $totalRayasSigno = $misEstadisticas['total_rayas'] >= 0 ? '+' : '';
        $totalRayasClass = $misEstadisticas['total_rayas'] >= 0 ? 'txt-pos' : 'txt-neg';
        $html .= '
            <div class="resumen-personal">
                <div class="resumen-personal-label">📊 TU RESUMEN DEL PERIODO</div>
                <div class="resumen-personal-valor ' . $totalRayasClass . '">' . $totalRayasSigno . $misEstadisticas['total_rayas'] . ' rayas</div>
                <div class="resumen-personal-detalle">
                    <div class="resumen-detalle-item">
                        <div class="label">✅ Victorias</div>
                        <div class="value txt-pos">' . $misEstadisticas['victorias'] . '</div>
                    </div>
                    <div class="resumen-detalle-item">
                        <div class="label">❌ Derrotas</div>
                        <div class="value txt-neg">' . $misEstadisticas['derrotas'] . '</div>
                    </div>
                    <div class="resumen-detalle-item">
                        <div class="label">🎲 Juegos</div>
                        <div class="value txt-white">' . $misEstadisticas['juegos_jugados'] . '</div>
                    </div>
                    <div class="resumen-detalle-item">
                        <div class="label">🎯 Partidas</div>
                        <div class="value txt-white">' . ($misEstadisticas['partidas_jugadas'] ?? 1) . '</div>
                    </div>
                </div>
            </div>';
    }
    
    if (!empty($estadisticasCompletas)) {
        $html .= '
            <div class="ranking-section">
                <div class="ranking-title">🏆 Ranking del periodo</div>
                <table class="stats-table">
                    <thead>
                        <tr><th>#</th><th>Jugador</th><th style="text-align:center">Juegos</th><th style="text-align:center">Victorias</th><th style="text-align:center">Derrotas</th><th style="text-align:center">Rayas</th></table>
                    </thead>
                    <tbody>';
        
        foreach ($estadisticasCompletas as $pos => $j) {
            $rayasTotales = $j['total_rayas'];
            $rayasSigno = $rayasTotales >= 0 ? '+' : '';
            $rayasClass = $rayasTotales >= 0 ? 'txt-pos' : 'txt-neg';
            $destacado = ($j['nombre'] === $nombre) ? 'destacado' : '';
            $numeroPos = $pos + 1;
            
            $html .= '<tr class="' . $destacado . '">
                        <td style="padding:8px; color:#ffd700; font-weight:bold;">#' . $numeroPos . '</td>
                        <td style="padding:8px; color:#ffffff; font-weight:bold;">' . htmlspecialchars($j['nombre']) . '</td>
                        <td style="padding:8px; text-align:center;">' . $j['juegos_jugados'] . '</td>
                        <td style="padding:8px; text-align:center;" class="txt-pos">' . $j['victorias'] . '</td>
                        <td style="padding:8px; text-align:center;" class="txt-neg">' . $j['derrotas'] . '</td>
                        <td style="padding:8px; text-align:center; font-weight:bold;" class="' . $rayasClass . '">' . $rayasSigno . $rayasTotales . '</td>
                      </tr>';
        }
        
        $html .= '</tbody> </table> </div>';
    }
    
    if (!empty($rankingGeneral)) {
        $html .= '
            <div class="ranking-section">
                <div class="ranking-title">🏆 Ranking General (Histórico)</div>
                <table class="stats-table">
                    <thead>
                        <tr><th>#</th><th>Jugador</th><th style="text-align:center">Rayas</th><th style="text-align:center">Juegos</th><th style="text-align:center">Victorias</th><th style="text-align:center">Derrotas</th></tr>
                    </thead>
                    <tbody>';
        
        foreach ($rankingGeneral as $pos => $j) {
            $rayas = $j['total_rayas'];
            $rayasSigno = $rayas >= 0 ? '+' : '';
            $rayasClass = $rayas >= 0 ? 'txt-pos' : 'txt-neg';
            $numeroPos = $pos + 1;
            $destacadoGeneral = ($j['nombre'] === $nombre) ? 'destacado' : '';
            
            $html .= '<tr class="' . $destacadoGeneral . '">
                        <td style="padding:8px; color:#ffd700; font-weight:bold;">#' . $numeroPos . '</td>
                        <td style="padding:8px; color:#ffffff; font-weight:bold;">' . htmlspecialchars($j['nombre']) . '</td>
                        <td style="padding:8px; text-align:center; font-weight:bold;" class="' . $rayasClass . '">' . $rayasSigno . $rayas . '</td>
                        <td style="padding:8px; text-align:center;">' . ($j['total_juegos'] ?? 0) . '</td>
                        <td style="padding:8px; text-align:center;" class="txt-pos">' . ($j['partidas_ganadas'] ?? 0) . '</td>
                        <td style="padding:8px; text-align:center;" class="txt-neg">' . ($j['partidas_perdidas'] ?? 0) . '</td>
                      </tr>';
        }
        
        $html .= '</tbody> </table> </div>';
    }
    
    $html .= '
            <div class="footer">
                <p>Este es un correo automático de Domino Master 9.2</p>
                <p>📊 <a href="' . $enlaceRanking . '">Ver ranking completo →</a></p>
            </div>
        </div>
    </body>
    </html>';
    
    return $html;
}

function generarTextoRankingCompleto($nombre, $estadisticasCompletas, $rankingGeneral, $resumenGeneral, $partidasDelDia) {
    $texto = "========================================\n";
    $texto .= "DOMINO MASTER 9.2 - RANKING\n";
    $texto .= "========================================\n\n";
    
    if ($resumenGeneral && $resumenGeneral['total_partidas'] > 0) {
        $texto .= "RESUMEN GENERAL:\n";
        $texto .= "   Partidas: " . $resumenGeneral['total_partidas'] . "\n";
        $texto .= "   Juegos: " . $resumenGeneral['total_juegos'] . "\n";
        $texto .= "   Rayas: " . $resumenGeneral['total_rayas_generadas'] . "\n";
        $texto .= "   Puntos: " . $resumenGeneral['total_puntos'] . "\n\n";
    }
    
    foreach ($estadisticasCompletas as $j) {
        if ($j['nombre'] === $nombre) {
            $totalSig = $j['total_rayas'] >= 0 ? '+' : '';
            $texto .= "TU RESUMEN:\n";
            $texto .= "   Victorias: " . $j['victorias'] . "\n";
            $texto .= "   Derrotas: " . $j['derrotas'] . "\n";
            $texto .= "   Total: " . $totalSig . $j['total_rayas'] . " rayas\n";
            $texto .= "   Juegos: " . $j['juegos_jugados'] . "\n\n";
            break;
        }
    }
    
    $texto .= "RANKING DEL PERIODO:\n";
    $texto .= "------------------------------------------------\n";
    foreach ($estadisticasCompletas as $pos => $j) {
        $totalSig = $j['total_rayas'] >= 0 ? '+' : '';
        $texto .= ($pos + 1) . ". " . $j['nombre'] . " - " . $j['juegos_jugados'] . "J, " . $j['victorias'] . "V, " . $j['derrotas'] . "D - " . $totalSig . $j['total_rayas'] . " rayas\n";
    }
    
    $texto .= "\nRANKING GENERAL:\n";
    $texto .= "------------------------------------------------\n";
    foreach ($rankingGeneral as $pos => $j) {
        $sig = $j['total_rayas'] >= 0 ? '+' : '';
        $texto .= ($pos + 1) . ". " . $j['nombre'] . ": " . $sig . $j['total_rayas'] . " rayas\n";
    }
    
    return $texto;
}

// ============================================
// FUNCIÓN PARA CONSTRUIR MENSAJE WHATSAPP (SIN LID)
// ============================================

function construirMensajeWhatsAppRanking($estadisticasCompletas, $rankingGeneral, $resumenGeneral, $fechas, $grupoNombre) {
    $textoFechas = is_array($fechas) ? implode(' y ', $fechas) : $fechas;
    
    $mensaje = "🏆 *DOMINO MASTER 9.2* 🏆\n";
    $mensaje .= "📅 *Periodo:* {$textoFechas}\n";
    $mensaje .= "🎲 *Grupo:* {$grupoNombre}\n";
    $mensaje .= "────────────────────\n\n";
    
    if ($resumenGeneral && $resumenGeneral['total_partidas'] > 0) {
        $mensaje .= "*📊 RESUMEN GENERAL*\n";
        $mensaje .= "🎲 Partidas: {$resumenGeneral['total_partidas']}\n";
        $mensaje .= "🃏 Juegos: {$resumenGeneral['total_juegos']}\n";
        $mensaje .= "⭐ Rayas: {$resumenGeneral['total_rayas_generadas']}\n";
        $mensaje .= "🏆 Puntos: {$resumenGeneral['total_puntos']}\n\n";
    }
    
    $mensaje .= "*🏆 RANKING DEL PERIODO*\n";
    foreach ($estadisticasCompletas as $pos => $j) {
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
        $mensaje .= "{$medalla} {$j['nombre']}: {$signo}{$total} rayas\n";
        $mensaje .= "   └ ✅{$j['victorias']} ❌{$j['derrotas']} 🎲{$j['juegos_jugados']}\n";
    }
    
    if (!empty($rankingGeneral)) {
        $mensaje .= "\n*🏆 RANKING GENERAL (TOP 5)*\n";
        $top5 = array_slice($rankingGeneral, 0, 5);
        foreach ($top5 as $pos => $j) {
            $total = $j['total_rayas'];
            $signo = $total >= 0 ? '+' : '';
            $medalla = $pos == 0 ? '🥇' : ($pos == 1 ? '🥈' : ($pos == 2 ? '🥉' : ($pos + 1) . "."));
            $mensaje .= "{$medalla} {$j['nombre']}: {$signo}{$total} rayas\n";
        }
    }
    
    $mensaje .= "\n────────────────────\n";
    $mensaje .= "🤖 Mensaje automático de Domino Master 9.2\n";
    $mensaje .= "📊 Ranking: http://frm.noip.me/desarrollo/ranking.html";
    
    return $mensaje;
}

// ============================================
// FUNCIÓN PARA FECHAS CONSECUTIVAS
// ============================================

function obtenerFechasConsecutivasConActividad($pdo, $grupoId) {
    $stmt = $pdo->prepare("
        SELECT DISTINCT DATE(creado_en) as fecha
        FROM juegos
        WHERE grupo_id = ? AND creado_en >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ORDER BY fecha DESC
    ");
    $stmt->execute([$grupoId]);
    $fechasConJuegos = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (empty($fechasConJuegos)) {
        return [];
    }
    
    $fechaActual = $fechasConJuegos[0];
    $fechasEncontradas = [$fechaActual];
    
    $fechaAnterior = date('Y-m-d', strtotime($fechaActual . ' -1 day'));
    if (in_array($fechaAnterior, $fechasConJuegos)) {
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT p.id) as partidas_consecutivas
            FROM partidas p
            JOIN juegos j ON j.partida_id = p.id
            WHERE p.grupo_id = ? 
                AND DATE(p.fecha_inicio) = ?
                AND DATE(j.creado_en) = ?
        ");
        $stmt->execute([$grupoId, $fechaAnterior, $fechaActual]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result['partidas_consecutivas'] > 0) {
            $fechasEncontradas[] = $fechaAnterior;
            sort($fechasEncontradas);
        }
    }
    
    return $fechasEncontradas;
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
    
    // Detectar las fechas a procesar
    $fechasAProcesar = obtenerFechasConsecutivasConActividad($pdo, $grupoId);
    
    if (empty($fechasAProcesar)) {
        echo json_encode([
            'success' => true,
            'message' => 'No hay actividad registrada en los últimos días para finalizar',
            'fechas_procesadas' => []
        ]);
        exit;
    }
    
    $fechaPlaceholders = implode(',', array_fill(0, count($fechasAProcesar), '?'));
    
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
    
    // Limpiar puntos parciales
    if (!empty($partidasActivas)) {
        $ids = $partidasActivas;
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("DELETE FROM puntos_en_vivo WHERE partida_id IN ($placeholders)");
        $stmt->execute($ids);
    }
    
    // ============================================
    // RECALCULAR ESTADISTICAS DE JUGADORES
    // ============================================
    $jugadoresActualizados = recalcularEstadisticasJugadores($pdo, $grupoId);
    
    // Obtener estadisticas del periodo
    $sql = "
        SELECT 
            j.id, j.nombre, j.email,
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
            AND DATE(jg.creado_en) IN ($fechaPlaceholders) AND jg.grupo_id = ?
        WHERE j.grupo_id = ? AND j.activo = 1
        GROUP BY j.id
        HAVING juegos_jugados > 0
        ORDER BY (rayas_ganadas - rayas_perdidas) DESC
    ";
    
    $params = array_merge($fechasAProcesar, [$grupoId, $grupoId]);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $estadisticasCompletas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($estadisticasCompletas as $key => $j) {
        $estadisticasCompletas[$key]['total_rayas'] = $j['rayas_ganadas'] - $j['rayas_perdidas'];
        $estadisticasCompletas[$key]['derrotas'] = $j['juegos_jugados'] - $j['victorias'];
        $estadisticasCompletas[$key]['nombre'] = htmlspecialchars($j['nombre'], ENT_QUOTES, 'UTF-8');
    }
    
    // Resumen general del periodo
    $sqlResumen = "
        SELECT 
            COUNT(DISTINCT partida_id) as total_partidas,
            COUNT(*) as total_juegos,
            SUM(rayas) as total_rayas_generadas,
            SUM(CASE WHEN ganador='A' THEN puntos_equipo_a ELSE puntos_equipo_b END) as total_puntos
        FROM juegos
        WHERE DATE(creado_en) IN ($fechaPlaceholders) AND grupo_id = ?
    ";
    $paramsResumen = array_merge($fechasAProcesar, [$grupoId]);
    $stmt = $pdo->prepare($sqlResumen);
    $stmt->execute($paramsResumen);
    $resumenGeneral = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$resumenGeneral) {
        $resumenGeneral = [
            'total_partidas' => 0,
            'total_juegos' => 0,
            'total_rayas_generadas' => 0,
            'total_puntos' => 0
        ];
    }
    
    // Ranking general historico (usando estadisticas_jugadores ya actualizadas)
    $stmt = $pdo->prepare("
        SELECT 
            j.id, j.nombre,
            COALESCE(ej.total_rayas, 0) as total_rayas,
            COALESCE(ej.total_juegos, 0) as total_juegos,
            COALESCE(ej.partidas_ganadas, 0) as partidas_ganadas,
            COALESCE(ej.partidas_perdidas, 0) as partidas_perdidas
        FROM jugadores j
        LEFT JOIN estadisticas_jugadores ej ON j.id = ej.jugador_id AND ej.grupo_id = j.grupo_id
        WHERE j.grupo_id = ? AND j.activo = 1
        ORDER BY COALESCE(ej.total_rayas, 0) DESC
    ");
    $stmt->execute([$grupoId]);
    $rankingGeneral = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($rankingGeneral as $key => $j) {
        $rankingGeneral[$key]['nombre'] = htmlspecialchars($j['nombre'], ENT_QUOTES, 'UTF-8');
    }
    
    // ============================================
    // ENVIAR CORREOS
    // ============================================
    $correos = 0;
    $erroresCorreo = array();
    
    foreach ($estadisticasCompletas as $jugador) {
        $stmt = $pdo->prepare("SELECT email FROM jugadores WHERE nombre = ? AND grupo_id = ? AND activo = 1");
        $stmt->execute([$jugador['nombre'], $grupoId]);
        $email = $stmt->fetchColumn();
        
        if ($email && !empty($email) && $phpmailerDisponible) {
            if (enviarCorreoPHPMailerCompleto($email, $jugador['nombre'], $estadisticasCompletas, $rankingGeneral, $grupoNombre, $fechasAProcesar, $resumenGeneral, [], $configCorreo, $grupoId)) {
                $correos++;
            } else {
                $erroresCorreo[] = $jugador['nombre'];
            }
        }
    }
    
    // ============================================
    // ENVIAR WHATSAPP AL GRUPO
    // ============================================
    $whatsappEnviado = false;
    $errorWhatsApp = null;
    
    if ($configWhatsApp['enabled'] && !empty($lidGroupWa) && count($estadisticasCompletas) > 0) {
        $mensajeRanking = construirMensajeWhatsAppRanking($estadisticasCompletas, $rankingGeneral, $resumenGeneral, $fechasAProcesar, $grupoNombre);
        
        if (strlen($mensajeRanking) > 4000) {
            $mensajeRanking = substr($mensajeRanking, 0, 3950) . "\n\n... (mensaje truncado)";
        }
        
        $whatsappEnviado = enviarWhatsApp($lidGroupWa, $mensajeRanking, $configWhatsApp);
        
        if (!$whatsappEnviado) {
            $errorWhatsApp = "Error al enviar mensaje al grupo";
        }
    } elseif (empty($lidGroupWa)) {
        $errorWhatsApp = "El grupo no tiene lid_group_wa configurado";
    } elseif (count($estadisticasCompletas) == 0) {
        $errorWhatsApp = "No hay jugadores con actividad en el periodo";
    }
    
    $textoFechas = implode(' y ', $fechasAProcesar);
    $mensajeFinal = "✅ Periodo {$textoFechas} finalizado";
    $mensajeFinal .= " | Partidas cerradas: {$partidasCerradas}";
    $mensajeFinal .= " | Estadísticas recalculadas: {$jugadoresActualizados} jugadores";
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
        'jugadores_actualizados' => $jugadoresActualizados,
        'correos' => $correos,
        'whatsapp_enviado' => $whatsappEnviado,
        'error_whatsapp' => $errorWhatsApp,
        'errores_correo' => $erroresCorreo,
        'fechas_procesadas' => $fechasAProcesar,
        'total_jugadores' => count($estadisticasCompletas),
        'grupo_nombre' => $grupoNombre,
        'total_partidas_periodo' => $resumenGeneral['total_partidas']
    ]);
    
} catch(Exception $e) {
    if (isset($pdo)) $pdo->rollBack();
    error_log("Error en finalizar_dia.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'line' => $e->getLine(),
        'file' => basename($e->getFile())
    ]);
}
?>