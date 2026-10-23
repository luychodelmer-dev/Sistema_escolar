<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/header.php';

require_director();

$permisos = $pdo->query('SELECT id, codigo, nombre FROM permisos WHERE activo = TRUE ORDER BY nombre')->fetchAll();
$tokenCsrf = $_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));
$mensaje = $_SESSION['flash_message'] ?? '';
unset($_SESSION['flash_message']);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($tokenCsrf, $_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        exit('Solicitud inválida. Recarga la página e inténtalo nuevamente.');
    }

    $action = $_POST['action'] ?? '';
    $usuarioId = filter_var($_POST['usuario_id'] ?? null, FILTER_VALIDATE_INT);
    $seleccionados = $_POST['permisos'] ?? [];
    $seleccionados = is_array($seleccionados) ? array_values(array_unique(array_filter(array_map('intval', $seleccionados), static fn ($id) => $id > 0))) : [];

    try {
        if (in_array($action, ['create', 'update'], true)) {
            $idsActivos = array_column($permisos, 'id');
            if (array_diff($seleccionados, $idsActivos)) {
                throw new RuntimeException('Selecciona permisos válidos.');
            }

            $syncPermisos = static function (int $id) use ($pdo, $seleccionados): void {
                $delete = $pdo->prepare('DELETE FROM usuario_permisos WHERE usuario_id = :usuario_id');
                $delete->execute(['usuario_id' => $id]);
                $insert = $pdo->prepare(
                    'INSERT INTO usuario_permisos (usuario_id, permiso_id, otorgado_por)
                     VALUES (:usuario_id, :permiso_id, :otorgado_por)'
                );
                foreach ($seleccionados as $permisoId) {
                    $insert->execute([
                        'usuario_id' => $id,
                        'permiso_id' => $permisoId,
                        'otorgado_por' => $_SESSION['user_id'],
                    ]);
                }
            };

            $pdo->beginTransaction();
            if ($action === 'create') {
                $nombres = trim($_POST['nombres'] ?? '');
                $correo = trim($_POST['correo'] ?? '');
                $password = $_POST['password'] ?? '';
                if ($nombres === '' || mb_strlen($nombres) > 150 || !filter_var($correo, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
                    throw new RuntimeException('Completa un nombre, correo válido y contraseña de al menos 8 caracteres.');
                }
                $stmt = $pdo->prepare('SELECT 1 FROM usuario WHERE correo = :correo');
                $stmt->execute(['correo' => $correo]);
                if ($stmt->fetchColumn()) {
                    throw new RuntimeException('Ya existe una cuenta con ese correo.');
                }
                $stmt = $pdo->prepare(
                    "INSERT INTO usuario (nombres, correo, password_hash, rol) VALUES (:nombres, :correo, :password_hash, 'asistente') RETURNING id"
                );
                $stmt->execute([
                    'nombres' => $nombres,
                    'correo' => $correo,
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                ]);
                $usuarioId = (int) $stmt->fetchColumn();
                $syncPermisos($usuarioId);
                $pdo->commit();
                registrar_auditoria('asistente_creado', "Asistente creado: {$nombres} ({$correo}).");
                $mensaje = 'Asistente creado correctamente.';
            } else {
                if (!$usuarioId) {
                    throw new RuntimeException('Asistente inválido.');
                }
                $stmt = $pdo->prepare("SELECT nombres, correo FROM usuario WHERE id = :id AND rol = 'asistente'");
                $stmt->execute(['id' => $usuarioId]);
                $asistente = $stmt->fetch();
                if (!$asistente) {
                    throw new RuntimeException('Asistente inválido.');
                }
                $syncPermisos($usuarioId);
                $pdo->commit();
                registrar_auditoria('cambio_permisos', "Permisos actualizados para {$asistente['nombres']} ({$asistente['correo']}).");
                $mensaje = 'Permisos actualizados correctamente.';
            }
        } elseif (in_array($action, ['revoke', 'restore'], true) && $usuarioId) {
            $stmt = $pdo->prepare("UPDATE usuario SET estado = :estado WHERE id = :id AND rol = 'asistente'");
            $estado = $action === 'restore';
            $stmt->bindValue(':estado', $estado, PDO::PARAM_BOOL);
            $stmt->bindValue(':id', $usuarioId, PDO::PARAM_INT);
            $stmt->execute();
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('Asistente inválido.');
            }
            registrar_auditoria($action === 'revoke' ? 'acceso_revocado' : 'acceso_restaurado', "Acceso {$action} para asistente {$usuarioId}.");
            $mensaje = $action === 'revoke' ? 'Acceso revocado.' : 'Acceso restaurado.';
        } else {
            throw new RuntimeException('Acción inválida.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
    }
}

$asistentes = $pdo->query("SELECT id, nombres, correo, estado FROM usuario WHERE rol = 'asistente' ORDER BY nombres")->fetchAll();
$asignaciones = $pdo->query(
    'SELECT asignacion.usuario_id, permiso.id AS permiso_id, permiso.nombre
     FROM usuario_permisos AS asignacion
     JOIN permisos AS permiso ON permiso.id = asignacion.permiso_id
     ORDER BY permiso.nombre'
)->fetchAll();
$mapa = [];
foreach ($asignaciones as $asignacion) {
    $mapa[$asignacion['usuario_id']][(int) $asignacion['permiso_id']] = $asignacion['nombre'];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Permisos - Sistema Escolar</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>body { background: #f4f7fb; color: #172b4d; }.page-header { background: #172b4d; color: #fff; }.institution-brand { display:flex;align-items:center;gap:.6rem;white-space:nowrap; }.institution-brand img { width:42px;height:42px;object-fit:contain;border-radius:50%;background:#fff; }.institution-brand span { font-size:1.1rem;white-space:nowrap; }.header-user-photo { width:52px;height:52px;object-fit:cover;border:2px solid rgba(255,255,255,.85);border-radius:50%; } @media (max-width:768px) {.institution-brand span {font-size:.95rem;}}</style>
</head>
<body>
<?php render_app_header(panel_home_path(), '../asistente/logout.php'); ?>
<main class="container py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4"><div><h1 class="h3 mb-1">Gestionar permisos</h1><p class="text-muted mb-0">Crea asistentes y administra sus accesos.</p></div><a href="<?= htmlspecialchars(panel_home_path()) ?>" class="btn btn-outline-primary">Volver al panel</a></div>
    <?php if ($mensaje !== ''): ?><div class="alert alert-success"><?= htmlspecialchars($mensaje) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <section class="card shadow-sm border-0 mb-4"><div class="card-body p-4">
        <h2 class="h5 mb-3">Registrar asistente</h2>
        <form method="POST" class="row g-3">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokenCsrf) ?>"><input type="hidden" name="action" value="create">
            <div class="col-md-6"><label class="form-label" for="nombres">Nombre completo</label><input class="form-control" id="nombres" name="nombres" maxlength="150" required></div>
            <div class="col-md-6"><label class="form-label" for="correo">Usuario de acceso (correo)</label><input class="form-control" id="correo" name="correo" type="email" maxlength="150" autocomplete="username" required></div>
            <div class="col-md-6"><label class="form-label" for="password">Contraseña inicial</label><input class="form-control" id="password" name="password" type="password" minlength="8" autocomplete="new-password" required></div>
            <fieldset class="col-12"><legend class="form-label fs-6">Permisos</legend><div class="d-flex flex-wrap gap-3">
                <?php foreach ($permisos as $permiso): ?><label class="form-check"><input class="form-check-input" type="checkbox" name="permisos[]" value="<?= (int) $permiso['id'] ?>"><span class="form-check-label"><?= htmlspecialchars($permiso['nombre']) ?></span></label><?php endforeach; ?>
                <?php if (!$permisos): ?><span class="text-muted">No hay permisos activos configurados.</span><?php endif; ?>
            </div></fieldset>
            <div class="col-12"><button class="btn btn-primary" type="submit">Crear asistente</button></div>
        </form>
    </div></section>

    <section class="card shadow-sm border-0"><div class="card-body p-0"><div class="table-responsive"><table class="table align-middle mb-0">
        <thead><tr><th class="ps-4">Asistente</th><th>Permisos asignados</th><th>Acceso</th><th class="pe-4">Acciones</th></tr></thead><tbody>
        <?php foreach ($asistentes as $asistente): ?>
            <tr>
                <td class="ps-4"><strong><?= htmlspecialchars($asistente['nombres']) ?></strong><br><small class="text-muted"><?= htmlspecialchars($asistente['correo']) ?></small></td>
                <td><?= htmlspecialchars(implode(', ', array_values($mapa[$asistente['id']] ?? [])) ?: 'Sin permisos') ?></td>
                <td><span class="badge <?= $asistente['estado'] ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= $asistente['estado'] ? 'Activo' : 'Revocado' ?></span></td>
                <td class="pe-4"><details><summary class="btn btn-outline-primary btn-sm">Editar permisos</summary>
                    <form method="POST" class="border rounded p-3 mt-2">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokenCsrf) ?>"><input type="hidden" name="action" value="update"><input type="hidden" name="usuario_id" value="<?= (int) $asistente['id'] ?>">
                        <?php foreach ($permisos as $permiso): ?><label class="form-check"><input class="form-check-input" type="checkbox" name="permisos[]" value="<?= (int) $permiso['id'] ?>" <?= isset($mapa[$asistente['id']][(int) $permiso['id']]) ? 'checked' : '' ?>><span class="form-check-label"><?= htmlspecialchars($permiso['nombre']) ?></span></label><?php endforeach; ?>
                        <button class="btn btn-primary btn-sm mt-2" type="submit">Guardar cambios</button>
                    </form>
                </details>
                <form method="POST" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokenCsrf) ?>"><input type="hidden" name="usuario_id" value="<?= (int) $asistente['id'] ?>">
                    <?php if ($asistente['estado']): ?><input type="hidden" name="action" value="revoke"><button class="btn btn-outline-danger btn-sm mt-2" type="submit">Revocar acceso</button>
                    <?php else: ?><input type="hidden" name="action" value="restore"><button class="btn btn-outline-success btn-sm mt-2" type="submit">Restaurar acceso</button><?php endif; ?>
                </form></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$asistentes): ?><tr><td colspan="4" class="text-center text-muted py-4">Todavía no hay asistentes registrados.</td></tr><?php endif; ?>
        </tbody></table></div></div></section>
</main>
</body>
</html>
