<?php
// ============================================================
// get_ranking_dia_completo.php
// Ruta: /desarrollo/api/get_ranking_dia_completo.php
// ============================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// ============================================================
// INCLUIR CONFIGURACIÓN DE LA BASE DE DATOS
// ============================================================
require_once __DIR__ . '/config.php';

// ============================================================
// VALIDAR CONEXIÓN
// ============================================================
if (!isset($pdo) || !($pdo instanceof PDO)) {
    echo json_encode(['success' => false, 'error' => 'Error de conexión: No se pudo establecer la conexión con la base de datos']);
    exit;
}

// Obtener parámetros
$grupoId = isset($_GET['grupo_id']) ? intval($_GET['grupo_id']) : 0;
$fecha = isset($_GET['fecha']) ? $_GET['fecha'] : date('Y-m-d');

// Validar grupo
if ($grupoId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Grupo ID inválido']);
    exit;
}

// Validar formato de fecha
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) && !strpos($fecha, ',')) {
    echo json_encode(['success' => false, 'error' => 'Formato de fecha inválido']);
    exit;
}

// ============================================================
// FUNCIÓN: Obtener Ranking General (Histórico) CON RAYAS GANADAS Y PERDIDAS
// ============================================================
function getRankingGeneral($pdo, $grupoId) {
    $query = "
        SELECT 
            j.id,
            j.nombre,
            j.img,
            -- Datos de estadisticas_jugadores
            ej.total_rayas,
            ej.total_juegos,
            ej.partidas_ganadas,
            ej.partidas_perdidas,
            ej.promedio_rayas_por_juego,
            -- CALCULAR RAYAS GANADAS Y PERDIDAS HISTÓRICAS
            -- Rayas ganadas (cuando el jugador está en el equipo ganador)
            COALESCE((
                SELECT SUM(gi.rayas)
                FROM juegos gi
                WHERE gi.grupo_id = :grupo_id
                AND (
                    (gi.ganador = 'A' AND (gi.equipo_a_jugador1 = j.id OR gi.equipo_a_jugador2 = j.id)) OR
                    (gi.ganador = 'B' AND (gi.equipo_b_jugador1 = j.id OR gi.equipo_b_jugador2 = j.id))
                )
            ), 0) AS rayas_ganadas,
            
            -- Rayas perdidas (cuando el jugador está en el equipo perdedor)
            COALESCE((
                SELECT SUM(gi.rayas)
                FROM juegos gi
                WHERE gi.grupo_id = :grupo_id
                AND (
                    (gi.ganador = 'A' AND (gi.equipo_b_jugador1 = j.id OR gi.equipo_b_jugador2 = j.id)) OR
                    (gi.ganador = 'B' AND (gi.equipo_a_jugador1 = j.id OR gi.equipo_a_jugador2 = j.id))
                )
            ), 0) AS rayas_perdidas,
            
            -- Efectividad calculada
            ROUND((ej.partidas_ganadas / NULLIF(ej.total_juegos, 0)) * 100, 1) AS efectividad
        FROM 
            jugadores j
        INNER JOIN 
            estadisticas_jugadores ej ON j.id = ej.jugador_id
        WHERE 
            ej.grupo_id = :grupo_id
        ORDER BY 
            ej.total_rayas DESC
    ";

    $stmt = $pdo->prepare($query);
    $stmt->execute(['grupo_id' => $grupoId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ============================================================
// FUNCIÓN: Obtener Estadísticas del Período
// ============================================================
function getEstadisticasPeriodo($pdo, $grupoId, $fecha) {
    $fechas = explode(',', $fecha);
    $fechaInicio = $fechas[0] . ' 00:00:00';
    $fechaFin = isset($fechas[1]) ? $fechas[1] . ' 23:59:59' : $fechas[0] . ' 23:59:59';
    
    $query = "
        SELECT 
            j.id,
            j.nombre,
            j.img,
            COALESCE(SUM(CASE 
                WHEN (gi.ganador = 'A' AND (gi.equipo_a_jugador1 = j.id OR gi.equipo_a_jugador2 = j.id)) OR
                     (gi.ganador = 'B' AND (gi.equipo_b_jugador1 = j.id OR gi.equipo_b_jugador2 = j.id))
                THEN gi.rayas ELSE 0 END), 0) AS rayas_ganadas,
            COALESCE(SUM(CASE 
                WHEN (gi.ganador = 'A' AND (gi.equipo_b_jugador1 = j.id OR gi.equipo_b_jugador2 = j.id)) OR
                     (gi.ganador = 'B' AND (gi.equipo_a_jugador1 = j.id OR gi.equipo_a_jugador2 = j.id))
                THEN gi.rayas ELSE 0 END), 0) AS rayas_perdidas,
            COALESCE(SUM(gi.rayas), 0) AS total_rayas,
            COUNT(DISTINCT gi.id) AS juegos_jugados,
            COUNT(DISTINCT gi.partida_id) AS partidas_jugadas
        FROM 
            jugadores j
        LEFT JOIN 
            partida_jugadores pj ON j.id = pj.jugador_id
        LEFT JOIN 
            juegos gi ON pj.partida_id = gi.partida_id
        WHERE 
            gi.grupo_id = :grupo_id
            AND gi.creado_en BETWEEN :fecha_inicio AND :fecha_fin
            AND j.id IN (
                SELECT DISTINCT pj2.jugador_id
                FROM partida_jugadores pj2
                INNER JOIN juegos gi2 ON pj2.partida_id = gi2.partida_id
                WHERE gi2.grupo_id = :grupo_id
                AND gi2.creado_en BETWEEN :fecha_inicio AND :fecha_fin
            )
        GROUP BY 
            j.id, j.nombre, j.img
        ORDER BY 
            total_rayas DESC
    ";

    $stmt = $pdo->prepare($query);
    $stmt->execute([
        'grupo_id' => $grupoId,
        'fecha_inicio' => $fechaInicio,
        'fecha_fin' => $fechaFin
    ]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ============================================================
// FUNCIÓN: Obtener Partidas del Período
// ============================================================
function getPartidas($pdo, $grupoId, $fecha) {
    $fechas = explode(',', $fecha);
    $fechaInicio = $fechas[0] . ' 00:00:00';
    $fechaFin = isset($fechas[1]) ? $fechas[1] . ' 23:59:59' : $fechas[0] . ' 23:59:59';
    
    $query = "
        SELECT 
            p.id AS partida_id,
            p.tipo_ronda,
            p.fecha_inicio,
            p.fecha_fin,
            p.num_ronda,
            COUNT(DISTINCT gi.id) AS juegos_partida,
            SUM(gi.rayas) AS rayas_partida,
            SUM(gi.puntos_equipo_a) AS puntos_totales_a,
            SUM(gi.puntos_equipo_b) AS puntos_totales_b,
            CASE 
                WHEN p.tipo_ronda = 1 THEN 'Partida Normal'
                WHEN p.tipo_ronda = 2 THEN 'Partida Cuádruple'
                ELSE 'Partida'
            END AS tipo_texto
        FROM 
            partidas p
        INNER JOIN 
            juegos gi ON p.id = gi.partida_id
        WHERE 
            p.grupo_id = :grupo_id
            AND p.fecha_inicio BETWEEN :fecha_inicio AND :fecha_fin
        GROUP BY 
            p.id
        ORDER BY 
            p.fecha_inicio DESC
    ";

    $stmt = $pdo->prepare($query);
    $stmt->execute([
        'grupo_id' => $grupoId,
        'fecha_inicio' => $fechaInicio,
        'fecha_fin' => $fechaFin
    ]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ============================================================
// FUNCIÓN: Obtener Juegos por Partida
// ============================================================
function getJuegosPorPartida($pdo, $partidaId) {
    $query = "
        SELECT 
            gi.id AS juego_id,
            gi.num_juego,
            gi.rayas,
            gi.puntos_equipo_a,
            gi.puntos_equipo_b,
            gi.ganador,
            (SELECT j.nombre FROM jugadores j WHERE j.id = gi.equipo_a_jugador1) AS equipo_a_jugador1_nombre,
            (SELECT j.nombre FROM jugadores j WHERE j.id = gi.equipo_a_jugador2) AS equipo_a_jugador2_nombre,
            (SELECT j.nombre FROM jugadores j WHERE j.id = gi.equipo_b_jugador1) AS equipo_b_jugador1_nombre,
            (SELECT j.nombre FROM jugadores j WHERE j.id = gi.equipo_b_jugador2) AS equipo_b_jugador2_nombre,
            (SELECT j.nombre FROM jugadores j WHERE j.id = gi.jugador_mano_id) AS jugador_mano
        FROM 
            juegos gi
        WHERE 
            gi.partida_id = :partida_id
        ORDER BY 
            gi.num_juego ASC
    ";

    $stmt = $pdo->prepare($query);
    $stmt->execute(['partida_id' => $partidaId]);
    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($result as &$juego) {
        $ganadores = [];
        $perdedores = [];
        
        if ($juego['ganador'] == 'A') {
            if ($juego['equipo_a_jugador1_nombre']) $ganadores[] = $juego['equipo_a_jugador1_nombre'];
            if ($juego['equipo_a_jugador2_nombre']) $ganadores[] = $juego['equipo_a_jugador2_nombre'];
            if ($juego['equipo_b_jugador1_nombre']) $perdedores[] = $juego['equipo_b_jugador1_nombre'];
            if ($juego['equipo_b_jugador2_nombre']) $perdedores[] = $juego['equipo_b_jugador2_nombre'];
        } else if ($juego['ganador'] == 'B') {
            if ($juego['equipo_b_jugador1_nombre']) $ganadores[] = $juego['equipo_b_jugador1_nombre'];
            if ($juego['equipo_b_jugador2_nombre']) $ganadores[] = $juego['equipo_b_jugador2_nombre'];
            if ($juego['equipo_a_jugador1_nombre']) $perdedores[] = $juego['equipo_a_jugador1_nombre'];
            if ($juego['equipo_a_jugador2_nombre']) $perdedores[] = $juego['equipo_a_jugador2_nombre'];
        }
        
        $juego['ganadores_nombres'] = $ganadores;
        $juego['perdedores_nombres'] = $perdedores;
        
        unset($juego['equipo_a_jugador1_nombre']);
        unset($juego['equipo_a_jugador2_nombre']);
        unset($juego['equipo_b_jugador1_nombre']);
        unset($juego['equipo_b_jugador2_nombre']);
    }
    
    return $result;
}

// ============================================================
// FUNCIÓN: Obtener Resumen General
// ============================================================
function getResumenGeneral($partidas) {
    $resumen = [
        'total_partidas' => count($partidas),
        'total_juegos' => 0,
        'total_rayas' => 0,
        'total_puntos' => 0
    ];

    foreach ($partidas as $partida) {
        $resumen['total_juegos'] += $partida['juegos_partida'];
        $resumen['total_rayas'] += $partida['rayas_partida'];
        $resumen['total_puntos'] += $partida['puntos_totales_a'] + $partida['puntos_totales_b'];
    }
    
    return $resumen;
}

// ============================================================
// EJECUCIÓN PRINCIPAL
// ============================================================

try {
    $rankingGeneral = getRankingGeneral($pdo, $grupoId);
    $estadisticasPeriodo = getEstadisticasPeriodo($pdo, $grupoId, $fecha);
    $partidas = getPartidas($pdo, $grupoId, $fecha);
    
    $juegosPorPartida = [];
    foreach ($partidas as $partida) {
        $juegosPorPartida[$partida['partida_id']] = getJuegosPorPartida($pdo, $partida['partida_id']);
    }
    
    $resumenGeneral = getResumenGeneral($partidas);
    
    $fechas = explode(',', $fecha);
    $esPeriodoMultiple = count($fechas) > 1;
    
    $response = [
        'success' => true,
        'fecha' => $fecha,
        'texto_fechas' => $esPeriodoMultiple ? implode(' y ', $fechas) : $fecha,
        'es_periodo_multiple' => $esPeriodoMultiple,
        'fechas_periodo' => $esPeriodoMultiple ? $fechas : null,
        'resumen_general' => $resumenGeneral,
        'estadisticas_jugadores' => $estadisticasPeriodo,
        'ranking_general' => $rankingGeneral,  // ✅ Ahora incluye rayas_ganadas y rayas_perdidas históricas
        'partidas' => $partidas,
        'juegos_por_partida' => $juegosPorPartida
    ];

    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Error en la consulta: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Error: ' . $e->getMessage()
    ]);
}
?>