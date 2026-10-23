<?php
function require_auth(array $roles = []): void
{
    if (!isset($_SESSION['user_id'])) {
        header('Location: ../login.php');
        exit;
    }

    if ($roles !== [] && !in_array($_SESSION['rol'] ?? '', $roles, true)) {
        http_response_code(403);
        exit('No tienes permisos para acceder a esta página.');
    }
}

function has_permission(string $permission): bool
{
    if (in_array($_SESSION['rol'] ?? '', ['director', 'hiperadmin'], true)) {
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
        'permiso' => $permission,
    ]);

    $granted = $stmt->fetchColumn();
    return $granted === true || $granted === 't' || $granted === 1 || $granted === '1';
}

function require_permission(string $permission): void
{
    if (!has_permission($permission)) {
        http_response_code(403);
        exit('No tienes permiso para realizar esta acción.');
    }
}

function require_director(): void
{
    require_auth(['director']);
}

function registrar_auditoria(string $accion, string $detalles): void
{
    global $pdo;
    if (!isset($pdo) || !isset($_SESSION['user_id'])) {
        return;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO auditoria_movimiento (usuario_id, accion, detalles, ip_origen)
         VALUES (:usuario_id, :accion, :detalles, :ip_origen)'
    );
    $stmt->execute([
        'usuario_id' => $_SESSION['user_id'],
        'accion' => $accion,
        'detalles' => $detalles,
        'ip_origen' => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
}
