<?php
// api/crear_partida.php - Modelo A: múltiples mesas activas simultáneas
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

if (!$data || !isset($data['tipo']) || !isset($data['jugadores'])) {
    echo json_encode(['success' => false, 'error' => 'Datos incompletos']);
    exit;
}

try {
    $pdo->beginTransaction();
    
    // ============================================
    // ✅ MODELO A: MÚLTIPLES MESAS ACTIVAS SIMULTÁNEAS
    // ============================================
    // Cada llamada a este endpoint crea una NUEVA partida activa.
    // NO se cierran las partidas anteriores del grupo.
    // 
    // El cierre de cada mesa es responsabilidad exclusiva de:
    //   - finalizar_partida.php (con partida_id específico)
    //   - El botón "Finalizar Mesa" en la app
    //
    // ⚠️ NUNCA agregar aquí un UPDATE masivo que cierre partidas.
    
    // Crear la NUEVA partida
    $stmt = $pdo->prepare("
        INSERT INTO partidas (tipo_ronda, grupo_id, fecha_inicio, estado, creado_por)
        VALUES (?, ?, NOW(), 'activa', ?)
    ");
    $stmt->execute([$data['tipo'], $grupoId, $_SESSION['usuario_id']]);
    $partidaId = $pdo->lastInsertId();
    
    // Registrar jugadores en la partida
    $stmt = $pdo->prepare("
        INSERT INTO partida_jugadores (partida_id, jugador_id, grupo_id, rayas_finales, juegos_jugados)
        VALUES (?, ?, ?, 0, 0)
    ");
    
    $jugadoresRegistrados = 0;
    foreach ($data['jugadores'] as $jugador) {
        $checkStmt = $pdo->prepare("SELECT id, nombre FROM jugadores WHERE id = ? AND grupo_id = ? AND activo = 1");
        $checkStmt->execute([$jugador['id'], $grupoId]);
        $jugadorInfo = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($jugadorInfo) {
            $stmt->execute([$partidaId, $jugador['id'], $grupoId]);
            $jugadoresRegistrados++;
        }
    }
    
    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'partida_id' => $partidaId,
        'jugadores_registrados' => $jugadoresRegistrados,
        'message' => 'Partida creada correctamente'
    ]);
    
} catch(Exception $e) {
    if (isset($pdo)) $pdo->rollBack();
    error_log("Error en crear_partida.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Error al crear la partida: ' . $e->getMessage()
    ]);
}
?>