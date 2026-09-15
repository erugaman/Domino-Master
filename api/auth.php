<?php
// auth.php - Middleware de autenticación para todos los endpoints

session_start();

/**
 * Verifica que el usuario esté autenticado y tenga un grupo activo
 * @return int|false Retorna el grupo_id si está autenticado, false en caso contrario
 */
function verificarAutenticacion() {
    if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['grupo_id'])) {
        http_response_code(401);
        echo json_encode([
            'success' => false, 
            'error' => 'No autorizado. Inicia sesión nuevamente.'
        ]);
        return false;
    }
    return $_SESSION['grupo_id'];
}

/**
 * Verifica que el usuario sea administrador del grupo
 * @param PDO $pdo Conexión a la base de datos
 * @return bool True si es admin, false en caso contrario
 */
function verificarAdmin($pdo) {
    if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['grupo_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'No autorizado']);
        return false;
    }
    
    $stmt = $pdo->prepare("SELECT rol FROM usuarios WHERE id = ? AND grupo_id = ? AND activo = 1");
    $stmt->execute([$_SESSION['usuario_id'], $_SESSION['grupo_id']]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$usuario || $usuario['rol'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Se requieren permisos de administrador']);
        return false;
    }
    
    return true;
}

/**
 * Obtiene el grupo_id de la sesión actual
 * @return int|null
 */
function getGrupoId() {
    return $_SESSION['grupo_id'] ?? null;
}

/**
 * Obtiene el usuario_id de la sesión actual
 * @return int|null
 */
function getUsuarioId() {
    return $_SESSION['usuario_id'] ?? null;
}
?>