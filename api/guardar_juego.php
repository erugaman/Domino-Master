<?php
// api/guardar_juego.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

// Verificar autenticación
$grupoId = verificarAutenticacion();
if (!$grupoId) {
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (!$data) {
    echo json_encode(['success' => false, 'error' => 'Datos inválidos']);
    exit;
}

// Función para obtener ID del jugador por nombre
function getJugadorId($nombre, $pdo, $grupoId) {
    if (empty($nombre)) return null;
    $stmt = $pdo->prepare("SELECT id FROM jugadores WHERE nombre = ? AND activo = 1 AND grupo_id = ?");
    $stmt->execute([$nombre, $grupoId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ? $result['id'] : null;
}

// Calcular rayas según puntos del perdedor
function calcularRayas($ptsPerdedor) {
    if ($ptsPerdedor <= 0) return 3;
    if ($ptsPerdedor < 51) return 2;
    return 1;
}

try {
    // Validar datos requeridos
    if (empty($data['partida_id']) || empty($data['ganadores']) || empty($data['perdedores'])) {
        throw new Exception('Faltan datos requeridos para guardar el juego');
    }
    
    // Validar puntos
    $ptsGanador = isset($data['pts_ganador']) ? (int)$data['pts_ganador'] : 0;
    $ptsPerdedor = isset($data['pts_perdedor']) ? (int)$data['pts_perdedor'] : 0;
    
    if ($ptsGanador <= 0 && $ptsPerdedor <= 0) {
        throw new Exception('Se requieren puntos válidos para el juego');
    }
    
    // Calcular rayas
    $rayas = isset($data['rayas']) ? (int)$data['rayas'] : calcularRayas($ptsPerdedor);
    
    // Determinar si es juego especial
    $esJuegoEspecial = isset($data['especial']) && $data['especial'] === true;
    $jugadoresEspeciales = $data['jugadores_especiales'] ?? [];
    
    // Obtener IDs de jugadores
    $ganadorIds = [];
    foreach ($data['ganadores'] as $nombre) {
        $id = getJugadorId($nombre, $pdo, $grupoId);
        if ($id) $ganadorIds[] = $id;
    }
    
    $perdedorIds = [];
    foreach ($data['perdedores'] as $nombre) {
        $id = getJugadorId($nombre, $pdo, $grupoId);
        if ($id) $perdedorIds[] = $id;
    }
    
    if (empty($ganadorIds) || empty($perdedorIds)) {
        throw new Exception('No se pudieron identificar los jugadores');
    }
    
    // Completar a 2 jugadores por equipo
    while (count($ganadorIds) < 2) $ganadorIds[] = null;
    while (count($perdedorIds) < 2) $perdedorIds[] = null;
    
    // Obtener ID del jugador mano
    $manoId = null;
    if (!empty($data['jugador_mano'])) {
        $manoId = getJugadorId($data['jugador_mano'], $pdo, $grupoId);
    }
    
    $pdo->beginTransaction();
    
    // Insertar el juego
    $stmt = $pdo->prepare("
        INSERT INTO juegos (
            partida_id, 
            num_juego, 
            num_ronda,
            equipo_a_jugador1, 
            equipo_a_jugador2,
            equipo_b_jugador1, 
            equipo_b_jugador2,
            puntos_equipo_a, 
            puntos_equipo_b,
            ganador, 
            rayas, 
            jugador_mano_id,
            grupo_id,
            creado_en
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    
    $stmt->execute([
        $data['partida_id'],
        $data['num_juego'],
        $data['num_ronda'] ?? 1,
        $ganadorIds[0] ?? null,
        $ganadorIds[1] ?? null,
        $perdedorIds[0] ?? null,
        $perdedorIds[1] ?? null,
        $ptsGanador,
        $ptsPerdedor,
        'A',
        $rayas,
        $manoId,
        $grupoId
    ]);
    
    $juegoId = $pdo->lastInsertId();
    
    // Actualizar partida_jugadores
    foreach ($ganadorIds as $jid) {
        if ($jid) {
            $rayasParaGanador = $rayas;
            if ($esJuegoEspecial && !empty($jugadoresEspeciales)) {
                $stmtNom = $pdo->prepare("SELECT nombre FROM jugadores WHERE id = ?");
                $stmtNom->execute([$jid]);
                $jugador = $stmtNom->fetch(PDO::FETCH_ASSOC);
                if ($jugador && !in_array($jugador['nombre'], $jugadoresEspeciales)) {
                    $rayasParaGanador = 0;
                }
            }
            
            $stmt = $pdo->prepare("
                UPDATE partida_jugadores 
                SET rayas_finales = COALESCE(rayas_finales, 0) + ?, 
                    juegos_jugados = COALESCE(juegos_jugados, 0) + 1 
                WHERE partida_id = ? AND jugador_id = ? AND grupo_id = ?
            ");
            $stmt->execute([$rayasParaGanador, $data['partida_id'], $jid, $grupoId]);
        }
    }
    
    foreach ($perdedorIds as $jid) {
        if ($jid) {
            $rayasParaPerdedor = $rayas;
            if ($esJuegoEspecial && !empty($jugadoresEspeciales)) {
                $stmtNom = $pdo->prepare("SELECT nombre FROM jugadores WHERE id = ?");
                $stmtNom->execute([$jid]);
                $jugador = $stmtNom->fetch(PDO::FETCH_ASSOC);
                if ($jugador && !in_array($jugador['nombre'], $jugadoresEspeciales)) {
                    $rayasParaPerdedor = 0;
                }
            }
            
            $stmt = $pdo->prepare("
                UPDATE partida_jugadores 
                SET rayas_finales = COALESCE(rayas_finales, 0) - ?, 
                    juegos_jugados = COALESCE(juegos_jugados, 0) + 1 
                WHERE partida_id = ? AND jugador_id = ? AND grupo_id = ?
            ");
            $stmt->execute([$rayasParaPerdedor, $data['partida_id'], $jid, $grupoId]);
        }
    }
    
    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'juego_id' => $juegoId,
        'rayas_calculadas' => $rayas,
        'message' => 'Juego guardado correctamente'
    ]);
    
} catch(PDOException $e) {
    if (isset($pdo)) $pdo->rollBack();
    error_log("PDO Error en guardar_juego.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Error de base de datos: ' . $e->getMessage()
    ]);
} catch(Exception $e) {
    if (isset($pdo)) $pdo->rollBack();
    error_log("Error en guardar_juego.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>