<?php
/**
 * Módulo de Autenticación, Control de Accesos, Permisos y Auditoría
 */

/**
 * Exige que el usuario haya iniciado sesión y pertenezca a uno de los roles permitidos.
 */
function require_auth(array $roles = []): void
{
    if (!isset($_SESSION['user_id'])) {
        header('Location: /login.php');
        exit;
    }

    if ($roles !== [] && !in_array($_SESSION['rol'] ?? '', $roles, true)) {
        http_response_code(403);
        exit('No tienes permisos para acceder a esta página.');
    }
}

/**
 * Comprueba si el usuario autenticado cuenta con un permiso específico.
 */
function has_permission(string $permission): bool
{
    if (in_array(strtolower($_SESSION['rol'] ?? ''), ['hiperadmin', 'director', 'administrador'], true)) {
        return true;
    }

    global $pdo;
    if (!isset($pdo)) {
        return false;
    }

    $stmt = $pdo->prepare(
        'SELECT EXISTS (
            SELECT 1
            FROM usuario_permisos AS asignacion
            JOIN permisos ON permisos.id = asignacion.permiso_id
            WHERE asignacion.usuario_id = :usuario_id
              AND permisos.codigo = :permiso
              AND permisos.activo = TRUE
        )'
    );
    $stmt->execute([
        'usuario_id' => $_SESSION['user_id'] ?? 0,
        'permiso'    => $permission,
    ]);

    $granted = $stmt->fetchColumn();
    return $granted === true || $granted === 't' || $granted === 1 || $granted === '1';
}

/**
 * Exige que el usuario cuente con el permiso indicado; si no, responde con 403.
 */
function require_permission(string $permission): void
{
    if (in_array(strtolower($_SESSION['rol'] ?? ''), ['hiperadmin', 'director', 'administrador'], true)) {
        return;
    }

    if (!has_permission($permission)) {
        http_response_code(403);
        exit('No tienes permiso para realizar esta acción.');
    }
}

/**
 * Retorna true si el usuario actual tiene rol directivo / administrador.
 */
function is_director(): bool
{
    return in_array(strtolower($_SESSION['rol'] ?? ''), ['director', 'hiperadmin', 'administrador'], true);
}

/**
 * Exige acceso exclusivo de directores o administradores.
 */
function require_director(): void
{
    require_auth(['director', 'hiperadmin', 'administrador']);
}

/**
 * Registra una acción en la tabla de auditoría del sistema escolar.
 */
function registrar_auditoria(string $accion, string $detalles, ?int $usuarioId = null): void
{
    global $pdo;
    $targetId = $usuarioId ?? ($_SESSION['user_id'] ?? null);
    if (!isset($pdo) || $targetId === null) {
        return;
    }

    try {
        // Extraer y validar una IP única compatible con PostgreSQL inet
        $rawIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
        $cleanIp = null;
        if (!empty($rawIp)) {
            $parts = explode(',', $rawIp);
            $firstIp = trim($parts[0]);
            if (filter_var($firstIp, FILTER_VALIDATE_IP)) {
                $cleanIp = $firstIp;
            }
        }

        $stmt = $pdo->prepare(
            'INSERT INTO auditoria_movimiento (usuario_id, accion, detalles, ip_origen)
             VALUES (:usuario_id, :accion, :detalles, :ip_origen)'
        );
        $stmt->execute([
            'usuario_id' => $targetId,
            'accion'     => $accion,
            'detalles'   => $detalles,
            'ip_origen'  => $cleanIp,
        ]);
    } catch (\Throwable $e) {
        // La auditoría no debe bloquear el flujo principal de la aplicación
        error_log('Error al registrar auditoría: ' . $e->getMessage());
    }
}
