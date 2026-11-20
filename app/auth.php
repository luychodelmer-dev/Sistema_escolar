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
 * Comprueba si el usuario autenticado cuenta con un permiso específico o equivalente.
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

    // Mapeo de compatibilidad con permisos heredados
    $fallbackMap = [
        'p_estudiantes'      => ['p_estudiantes', 'p_matricular'],
        'p_padres'           => ['p_padres', 'p_editar'],
        'p_calificar'        => ['p_calificar', 'p_matricular'],
        'p_cursos_periodos'  => ['p_cursos_periodos', 'p_matricular'],
        'p_cierre_academico' => ['p_cierre_academico', 'p_matricular'],
        'p_reportes_caja'    => ['p_reportes_caja', 'p_cobrar'],
        'p_editar'           => ['p_editar', 'p_padres'],
    ];

    $codigosAChequear = $fallbackMap[$permission] ?? [$permission];
    $inPlaceholders = implode(',', array_fill(0, count($codigosAChequear), '?'));
    $params = array_merge([$_SESSION['user_id'] ?? 0], $codigosAChequear);

    $stmt = $pdo->prepare(
        "SELECT EXISTS (
            SELECT 1
            FROM usuario_permisos AS asignacion
            JOIN permisos ON permisos.id = asignacion.permiso_id
            WHERE asignacion.usuario_id = ?
              AND permisos.codigo IN ($inPlaceholders)
              AND permisos.activo = TRUE
        )"
    );
    $stmt->execute($params);

    $granted = $stmt->fetchColumn();
    return $granted === true || $granted === 't' || $granted === 1 || $granted === '1';
}

/**
 * Comprueba si el usuario tiene al menos uno de los permisos indicados.
 */
function has_any_permission(array $permissions): bool
{
    if (in_array(strtolower($_SESSION['rol'] ?? ''), ['hiperadmin', 'director', 'administrador'], true)) {
        return true;
    }
    foreach ($permissions as $p) {
        if (has_permission($p)) {
            return true;
        }
    }
    return false;
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
 * Exige que el usuario cuente con al menos uno de los permisos indicados.
 */
function require_any_permission(array $permissions): void
{
    if (in_array(strtolower($_SESSION['rol'] ?? ''), ['hiperadmin', 'director', 'administrador'], true)) {
        return;
    }
    if (!has_any_permission($permissions)) {
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
