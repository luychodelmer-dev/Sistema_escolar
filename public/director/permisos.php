<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/header.php';

require_director();

// Asegurar existencia de columnas modulo, icono y orden en la tabla permisos
try {
    $hasModulo = $pdo->query("
        SELECT 1 FROM information_schema.columns 
        WHERE table_schema = 'public' AND table_name = 'permisos' AND column_name = 'modulo'
    ")->fetchColumn();

    if (!$hasModulo) {
        $pdo->exec("ALTER TABLE permisos ADD COLUMN IF NOT EXISTS modulo VARCHAR(50) DEFAULT 'general'");
        $pdo->exec("ALTER TABLE permisos ADD COLUMN IF NOT EXISTS icono VARCHAR(50) DEFAULT 'bi-shield-check'");
        $pdo->exec("ALTER TABLE permisos ADD COLUMN IF NOT EXISTS orden INT DEFAULT 10");

        $pdo->exec("
            UPDATE permisos SET modulo = 'estudiantes', icono = 'bi-person-badge', orden = 1 WHERE codigo = 'p_estudiantes';
            UPDATE permisos SET modulo = 'estudiantes', icono = 'bi-people', orden = 2 WHERE codigo = 'p_padres';
            UPDATE permisos SET modulo = 'estudiantes', icono = 'bi-person-gear', orden = 3 WHERE codigo = 'p_editar';
            UPDATE permisos SET modulo = 'matriculas', icono = 'bi-journal-check', orden = 4 WHERE codigo = 'p_matricular';
            UPDATE permisos SET modulo = 'matriculas', icono = 'bi-calendar3-range', orden = 5 WHERE codigo = 'p_cursos_periodos';
            UPDATE permisos SET modulo = 'calificaciones', icono = 'bi-card-checklist', orden = 6 WHERE codigo = 'p_calificar';
            UPDATE permisos SET modulo = 'calificaciones', icono = 'bi-award', orden = 7 WHERE codigo = 'p_cierre_academico';
            UPDATE permisos SET modulo = 'pagos', icono = 'bi-cash-coin', orden = 8 WHERE codigo = 'p_cobrar';
            UPDATE permisos SET modulo = 'pagos', icono = 'bi-file-earmark-spreadsheet', orden = 9 WHERE codigo = 'p_reportes_caja';
            UPDATE permisos SET modulo = 'auditoria', icono = 'bi-shield-shaded', orden = 10 WHERE codigo = 'p_auditoria';
        ");
    }
} catch (Throwable $e) {
    error_log('Verificación de columnas permisos: ' . $e->getMessage());
}

$moduloPorCodigo = [
    'p_estudiantes' => ['modulo' => 'estudiantes', 'icono' => 'bi-person-badge', 'orden' => 1],
    'p_padres' => ['modulo' => 'estudiantes', 'icono' => 'bi-people', 'orden' => 2],
    'p_editar' => ['modulo' => 'estudiantes', 'icono' => 'bi-person-gear', 'orden' => 3],
    'p_matricular' => ['modulo' => 'matriculas', 'icono' => 'bi-journal-check', 'orden' => 4],
    'p_cursos_periodos' => ['modulo' => 'matriculas', 'icono' => 'bi-calendar3-range', 'orden' => 5],
    'p_calificar' => ['modulo' => 'calificaciones', 'icono' => 'bi-card-checklist', 'orden' => 6],
    'p_cierre_academico' => ['modulo' => 'calificaciones', 'icono' => 'bi-award', 'orden' => 7],
    'p_cobrar' => ['modulo' => 'pagos', 'icono' => 'bi-cash-coin', 'orden' => 8],
    'p_reportes_caja' => ['modulo' => 'pagos', 'icono' => 'bi-file-earmark-spreadsheet', 'orden' => 9],
    'p_auditoria' => ['modulo' => 'auditoria', 'icono' => 'bi-shield-shaded', 'orden' => 10],
];

try {
    $permisos = $pdo->query('
        SELECT id, codigo, nombre, descripcion, COALESCE(modulo, \'general\') AS modulo, COALESCE(icono, \'bi-shield-check\') AS icono, COALESCE(orden, 10) AS orden 
        FROM permisos 
        WHERE activo = TRUE 
        ORDER BY orden ASC, nombre ASC
    ')->fetchAll();
} catch (Throwable $e) {
    $permisos = $pdo->query('
        SELECT id, codigo, nombre, descripcion 
        FROM permisos 
        WHERE activo = TRUE 
        ORDER BY nombre ASC
    ')->fetchAll();
    foreach ($permisos as &$p) {
        $meta = $moduloPorCodigo[$p['codigo']] ?? ['modulo' => 'general', 'icono' => 'bi-shield-check', 'orden' => 10];
        $p['modulo'] = $meta['modulo'];
        $p['icono'] = $meta['icono'];
        $p['orden'] = $meta['orden'];
    }
    unset($p);
    usort($permisos, static fn ($a, $b) => ($a['orden'] <=> $b['orden']) ?: strcasecmp($a['nombre'], $b['nombre']));
}

$modulosDef = [
    'estudiantes' => [
        'titulo' => 'Estudiantes y Familias',
        'icono' => 'bi-people-fill',
        'badge' => 'primary',
        'desc' => 'Gestión de expedientes de alumnos, padres y apoderados',
    ],
    'matriculas' => [
        'titulo' => 'Matrículas y Plan Académico',
        'icono' => 'bi-journal-check',
        'badge' => 'success',
        'desc' => 'Inscripciones por año lectivo, aulas y asignaturas',
    ],
    'calificaciones' => [
        'titulo' => 'Evaluaciones y Calificaciones',
        'icono' => 'bi-card-checklist',
        'badge' => 'warning',
        'desc' => 'Registro de notas oficiales (AD, A, B, C) y cierre',
    ],
    'pagos' => [
        'titulo' => 'Caja y Finanzas',
        'icono' => 'bi-cash-coin',
        'badge' => 'info',
        'desc' => 'Cobros de pensiones, cuota APAFA y reportes de caja',
    ],
    'auditoria' => [
        'titulo' => 'Seguridad y Auditoría',
        'icono' => 'bi-shield-lock-fill',
        'badge' => 'secondary',
        'desc' => 'Supervisión de bitácora y movimientos de usuarios',
    ],
];

$permisosAgrupados = [];
foreach ($permisos as $p) {
    $mod = $p['modulo'] ?? 'general';
    $permisosAgrupados[$mod][] = $p;
}
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
        } elseif ($action === 'reset_password' && $usuarioId) {
            $newPassword = $_POST['new_password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';
            if (strlen($newPassword) < 8) {
                throw new RuntimeException('La nueva contraseña debe tener al menos 8 caracteres.');
            }
            if ($newPassword !== $confirmPassword) {
                throw new RuntimeException('Las contraseñas no coinciden.');
            }
            $stmt = $pdo->prepare("SELECT nombres, correo FROM usuario WHERE id = :id AND rol = 'asistente'");
            $stmt->execute(['id' => $usuarioId]);
            $asistente = $stmt->fetch();
            if (!$asistente) {
                throw new RuntimeException('Asistente inválido.');
            }
            $hash = password_hash($newPassword, PASSWORD_DEFAULT);
            $update = $pdo->prepare("UPDATE usuario SET password_hash = :hash, actualizado_en = CURRENT_TIMESTAMP WHERE id = :id");
            $update->execute(['hash' => $hash, 'id' => $usuarioId]);

            // Invalida solicitudes pendientes de este usuario
            $cancelStmt = $pdo->prepare("UPDATE solicitud_reseteo_password SET estado = 'completada', completado_en = CURRENT_TIMESTAMP WHERE usuario_id = :id AND estado = 'pendiente'");
            $cancelStmt->execute(['id' => $usuarioId]);

            registrar_auditoria('password_restablecida_director', "El director restableció la contraseña para {$asistente['nombres']} ({$asistente['correo']}).", $usuarioId);
            $mensaje = "Contraseña restablecida correctamente para {$asistente['nombres']}.";
        } elseif ($action === 'reject_request') {
            $reqId = filter_var($_POST['solicitud_id'] ?? 0, FILTER_VALIDATE_INT);
            if ($reqId) {
                $cancelReq = $pdo->prepare("UPDATE solicitud_reseteo_password SET estado = 'rechazada' WHERE id = :id AND estado = 'pendiente'");
                $cancelReq->execute(['id' => $reqId]);
                registrar_auditoria('solicitud_reseteo_rechazada', "El director rechazó la solicitud de reseteo #{$reqId}.");
                $mensaje = 'Solicitud de recuperación rechazada.';
            }
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
try {
    $asignaciones = $pdo->query(
        'SELECT asignacion.usuario_id, permiso.id AS permiso_id, permiso.codigo, permiso.nombre, COALESCE(permiso.modulo, \'general\') AS modulo
         FROM usuario_permisos AS asignacion
         JOIN permisos AS permiso ON permiso.id = asignacion.permiso_id
         ORDER BY COALESCE(permiso.orden, 10) ASC, permiso.nombre ASC'
    )->fetchAll();
} catch (Throwable $e) {
    $asignaciones = $pdo->query(
        'SELECT asignacion.usuario_id, permiso.id AS permiso_id, permiso.codigo, permiso.nombre
         FROM usuario_permisos AS asignacion
         JOIN permisos AS permiso ON permiso.id = asignacion.permiso_id
         ORDER BY permiso.nombre ASC'
    )->fetchAll();
    foreach ($asignaciones as &$asig) {
        $asig['modulo'] = $moduloPorCodigo[$asig['codigo']]['modulo'] ?? 'general';
    }
    unset($asig);
}
$mapa = [];
foreach ($asignaciones as $asignacion) {
    $mapa[$asignacion['usuario_id']][(int) $asignacion['permiso_id']] = [
        'nombre' => $asignacion['nombre'],
        'codigo' => $asignacion['codigo'],
        'modulo' => $asignacion['modulo'],
    ];
}

$solicitudesPendientes = [];
try {
    $solicitudesPendientes = $pdo->query(
        "SELECT s.id, s.usuario_id, s.codigo, s.ip_solicitud, s.creado_en, s.expira_en,
                GREATEST(0, FLOOR(EXTRACT(EPOCH FROM (s.expira_en - CURRENT_TIMESTAMP)) / 60))::int AS min_restantes,
                u.nombres, u.correo
         FROM solicitud_reseteo_password s
         JOIN usuario u ON u.id = s.usuario_id
         WHERE s.estado = 'pendiente'
           AND s.expira_en > CURRENT_TIMESTAMP
           AND COALESCE(s.canal, 'direccion') = 'direccion'
           AND u.rol = 'asistente'
         ORDER BY s.creado_en DESC"
    )->fetchAll();
} catch (Throwable $e) {
    $solicitudesPendientes = [];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Permisos - Sistema Escolar</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../assets/css/app.css">
    <link rel="stylesheet" href="../assets/css/permisos.css">
</head>
<body>
<?php render_app_header(panel_home_path(), '../asistente/logout.php'); ?>
<main class="container py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <h1 class="h3 fw-bold mb-1 text-dark d-flex align-items-center gap-2">
                <i class="bi bi-shield-lock-fill text-primary"></i> Gestionar Permisos
            </h1>
            <p class="text-muted mb-0 small">Crea cuentas para asistentes y administra sus accesos granulares.</p>
        </div>
        <a href="<?= htmlspecialchars(panel_home_path()) ?>" class="btn btn-outline-primary d-inline-flex align-items-center gap-1 px-3 py-2 fw-semibold" style="border-radius: 10px; font-size: 0.96rem;">
            <i class="bi bi-arrow-left"></i> Volver al Panel
        </a>
    </div>
    <?php if ($mensaje !== ''): ?>
        <div class="alert alert-success d-flex align-items-center gap-2 shadow-sm border-0 mb-4 fs-6">
            <i class="bi bi-check-circle-fill fs-5"></i>
            <div><?= htmlspecialchars($mensaje) ?></div>
        </div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="alert alert-danger d-flex align-items-center gap-2 shadow-sm border-0 mb-4 fs-6">
            <i class="bi bi-exclamation-triangle-fill fs-5"></i>
            <div><?= htmlspecialchars($error) ?></div>
        </div>
    <?php endif; ?>

    <?php if (!empty($solicitudesPendientes)): ?>
    <section class="card mb-4 border-warning shadow-sm" style="border-radius: 16px; background: #fffdf7;">
        <div class="card-header bg-warning bg-opacity-10 py-3 px-4 border-bottom border-warning border-opacity-25 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div class="d-flex align-items-center gap-2">
                <span class="badge bg-warning text-dark p-2 rounded-circle d-inline-flex align-items-center justify-content-center" style="width: 32px; height: 32px;">
                    <i class="bi bi-key-fill"></i>
                </span>
                <div>
                    <h2 class="h6 fw-bold mb-0 text-dark">Solicitudes de Recuperación de Contraseña (Intranet)</h2>
                    <small class="text-muted">Usuarios que solicitaron código de acceso para restablecer su clave</small>
                </div>
            </div>
            <span class="badge bg-warning text-dark px-3 py-2 fw-bold" style="font-size: 0.85rem; border-radius: 20px;">
                <i class="bi bi-clock-history me-1"></i> <?= count($solicitudesPendientes) ?> pendiente(s)
            </span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table align-middle mb-0 bg-transparent">
                    <thead>
                        <tr>
                            <th class="ps-4">Usuario</th>
                            <th>Código de Autorización</th>
                            <th>Hora Solicitud</th>
                            <th>Vencimiento</th>
                            <th class="pe-4 text-end">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($solicitudesPendientes as $sol): ?>
                            <tr>
                                <td class="ps-4">
                                    <div class="fw-bold text-dark"><?= htmlspecialchars($sol['nombres']) ?></div>
                                    <div class="text-muted small font-monospace"><?= htmlspecialchars($sol['correo']) ?></div>
                                </td>
                                <td>
                                    <div class="d-inline-flex align-items-center gap-2">
                                        <span class="badge bg-dark font-monospace fs-6 px-3 py-2 shadow-sm" style="letter-spacing: 2px;">
                                            <?= htmlspecialchars($sol['codigo']) ?>
                                        </span>
                                        <button type="button" class="btn btn-sm btn-outline-secondary copy-btn" style="border-radius: 7px;" onclick="copiarCodigo('<?= htmlspecialchars($sol['codigo']) ?>', this)">
                                            <i class="bi bi-clipboard"></i> Copiar
                                        </button>
                                    </div>
                                </td>
                                <td class="small text-muted">
                                    <i class="bi bi-calendar3 me-1"></i> <?= htmlspecialchars(date('d/m/Y H:i', strtotime($sol['creado_en']))) ?>
                                </td>
                                <td>
                                    <?php
                                    $minRestantes = (int) ($sol['min_restantes'] ?? 0);
                                    ?>
                                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle font-monospace">
                                        Expira en <?= $minRestantes ?> min
                                    </span>
                                </td>
                                <td class="pe-4 text-end">
                                    <div class="d-inline-flex gap-2">
                                        <button type="button" class="btn btn-primary btn-sm px-3 fw-semibold d-inline-flex align-items-center gap-1" style="border-radius: 8px;"
                                                onclick="abrirModalReset(<?= (int)$sol['usuario_id'] ?>, '<?= htmlspecialchars(addslashes($sol['nombres'])) ?>', '<?= htmlspecialchars(addslashes($sol['correo'])) ?>')">
                                            <i class="bi bi-shield-lock-fill"></i> Resetear Clave
                                        </button>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('¿Rechazar e invalidar esta solicitud?');">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokenCsrf) ?>">
                                            <input type="hidden" name="action" value="reject_request">
                                            <input type="hidden" name="solicitud_id" value="<?= (int)$sol['id'] ?>">
                                            <button type="submit" class="btn btn-outline-danger btn-sm" style="border-radius: 8px;" title="Rechazar solicitud">
                                                <i class="bi bi-x-lg"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="p-3 bg-light bg-opacity-50 border-top text-muted small d-flex align-items-center gap-2">
                <i class="bi bi-info-circle text-primary"></i>
                <span><strong>¿Cómo funciona la autorización?</strong> El asistente solicita el reseteo desde el login sin ver el código en pantalla. Solo tú puedes ver aquí el código de 6 dígitos (válido por 15 min, máximo 5 intentos) o hacer clic en <em>Resetear Clave</em> directamente.</span>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <section class="card form-card mb-4">
        <div class="card-body p-4 p-md-5">
            <h2 class="h5 fw-bold mb-3 pb-2 border-bottom text-dark d-flex align-items-center gap-2">
                <i class="bi bi-person-plus-fill text-primary"></i> Registrar Asistente
            </h2>
            <form method="POST" class="row g-3">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokenCsrf) ?>">
                <input type="hidden" name="action" value="create">
                <div class="col-md-6">
                    <label class="form-label" for="nombres">Nombre Completo <span class="text-danger">*</span></label>
                    <input class="form-control" id="nombres" name="nombres" maxlength="150" required placeholder="Ej: Juan Silva Morales">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="correo">Usuario de Acceso (Correo) <span class="text-danger">*</span></label>
                    <input class="form-control font-monospace" id="correo" name="correo" type="email" maxlength="150" autocomplete="username" required placeholder="ejemplo@colegio.edu.pe">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="password">Contraseña Inicial <span class="text-danger">*</span></label>
                    <input class="form-control font-monospace" id="password" name="password" type="password" minlength="8" autocomplete="new-password" required placeholder="Mínimo 8 caracteres">
                </div>
                <fieldset class="col-12 mt-3">
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                        <div>
                            <legend class="form-label fw-bold fs-6 mb-0 text-dark d-flex align-items-center gap-2">
                                <i class="bi bi-shield-check text-primary"></i> Permisos Granulares
                            </legend>
                            <small class="text-muted">Elige los accesos o selecciona una plantilla rápida según su función:</small>
                        </div>
                        <div class="d-flex flex-wrap gap-1">
                            <span class="small text-muted align-self-center me-1 d-none d-md-inline">Plantillas:</span>
                            <button type="button" class="btn btn-sm btn-outline-primary fw-semibold" onclick="aplicarPlantilla('docente', this.closest('form'))">
                                <i class="bi bi-mortarboard-fill"></i> Docente
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-success fw-semibold" onclick="aplicarPlantilla('caja', this.closest('form'))">
                                <i class="bi bi-cash-stack"></i> Caja / APAFA
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-info fw-semibold" onclick="aplicarPlantilla('secretaria', this.closest('form'))">
                                <i class="bi bi-file-earmark-person"></i> Secretaría
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-dark fw-semibold" onclick="aplicarPlantilla('coordinacion', this.closest('form'))">
                                <i class="bi bi-building-gear"></i> Coordinación
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="aplicarPlantilla('ninguno', this.closest('form'))" title="Desmarcar todo">
                                <i class="bi bi-x-circle"></i>
                            </button>
                        </div>
                    </div>

                    <div class="row g-3">
                        <?php foreach ($modulosDef as $modKey => $modInfo): ?>
                            <?php if (empty($permisosAgrupados[$modKey])) continue; ?>
                            <div class="col-lg-6">
                                <div class="modulo-card h-100 p-3 shadow-sm border">
                                    <div class="d-flex align-items-center justify-content-between mb-2 pb-2 border-bottom">
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="badge bg-<?= $modInfo['badge'] ?> bg-opacity-10 text-<?= $modInfo['badge'] ?> p-2 rounded-circle fs-6">
                                                <i class="bi <?= $modInfo['icono'] ?>"></i>
                                            </span>
                                            <div>
                                                <h6 class="fw-bold mb-0 text-dark"><?= htmlspecialchars($modInfo['titulo']) ?></h6>
                                                <small class="text-muted" style="font-size: 0.8rem;"><?= htmlspecialchars($modInfo['desc']) ?></small>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="d-flex flex-column gap-2 mt-2">
                                        <?php foreach ($permisosAgrupados[$modKey] as $permiso): ?>
                                            <label class="permiso-item d-flex align-items-start gap-2 mb-0">
                                                <input class="form-check-input mt-1" type="checkbox" name="permisos[]" 
                                                       value="<?= (int) $permiso['id'] ?>" 
                                                       data-codigo="<?= htmlspecialchars($permiso['codigo']) ?>">
                                                <div class="flex-grow-1">
                                                    <div class="d-flex align-items-center gap-1">
                                                        <i class="bi <?= htmlspecialchars($permiso['icono']) ?> text-secondary small"></i>
                                                        <span class="permiso-titulo fw-bold text-dark small"><?= htmlspecialchars($permiso['nombre']) ?></span>
                                                    </div>
                                                    <small class="text-muted d-block" style="font-size: 0.78rem; line-height: 1.25;"><?= htmlspecialchars($permiso['descripcion']) ?></small>
                                                </div>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
                <div class="col-12 pt-3 border-top mt-4">
                    <button class="btn btn-primary d-inline-flex align-items-center gap-2 px-4 py-2 fw-semibold" style="border-radius: 9px; font-size: 1rem;" type="submit">
                        <i class="bi bi-save"></i> Crear Asistente
                    </button>
                </div>
            </form>
        </div>
    </section>

    <section class="card form-card">
        <div class="card-header bg-white py-3 px-4 border-bottom">
            <h2 class="h5 fw-bold mb-0 text-dark d-flex align-items-center gap-2">
                <i class="bi bi-people-fill text-primary"></i> Asistentes Registrados
            </h2>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th class="ps-4" style="min-width: 220px;">Asistente</th>
                            <th style="min-width: 260px;">Permisos Asignados</th>
                            <th style="min-width: 110px;">Acceso</th>
                            <th class="pe-4" style="min-width: 210px;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($asistentes as $asistente): ?>
                            <tr>
                                <td class="ps-4">
                                    <strong class="text-dark fs-6"><?= htmlspecialchars($asistente['nombres']) ?></strong>
                                    <div class="text-muted small font-monospace mt-1"><?= htmlspecialchars($asistente['correo']) ?></div>
                                </td>
                                <td>
                                    <?php
                                    $pList = array_values($mapa[$asistente['id']] ?? []);
                                    if ($pList): ?>
                                        <div class="d-flex flex-wrap gap-1">
                                            <?php foreach ($pList as $pInfo): 
                                                $mod = $pInfo['modulo'] ?? 'general';
                                                $badgeStyle = "badge-modulo-{$mod}";
                                            ?>
                                                <span class="badge <?= $badgeStyle ?> fw-semibold" style="font-size: 0.78rem;">
                                                    <?= htmlspecialchars($pInfo['nombre']) ?>
                                                </span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted small fst-italic">Sin permisos activos</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge <?= $asistente['estado'] ? 'bg-success-subtle text-success border border-success-subtle' : 'bg-secondary-subtle text-secondary' ?> fs-6">
                                        <?= $asistente['estado'] ? 'Activo' : 'Revocado' ?>
                                    </span>
                                </td>
                                <td class="pe-4">
                                    <div class="d-inline-flex flex-wrap gap-2 align-items-center">
                                        <details class="position-relative">
                                            <summary class="btn btn-outline-primary btn-sm px-3 fw-semibold" style="border-radius: 8px; font-size: 0.88rem;">
                                                <i class="bi bi-pencil-square"></i> Permisos (<?= count($pList) ?>)
                                            </summary>
                                            <form method="POST" class="border rounded-3 p-3 mt-2 bg-light shadow-sm text-start" style="min-width: 320px; max-width: 520px;">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokenCsrf) ?>">
                                                <input type="hidden" name="action" value="update">
                                                <input type="hidden" name="usuario_id" value="<?= (int) $asistente['id'] ?>">
                                                
                                                <div class="d-flex flex-wrap align-items-center justify-content-between gap-1 mb-2 pb-2 border-bottom">
                                                    <span class="fw-bold small text-dark"><i class="bi bi-sliders text-primary"></i> Asignar Rol Rápido:</span>
                                                    <div class="btn-group btn-group-sm">
                                                        <button type="button" class="btn btn-outline-primary py-0 px-2" style="font-size: 0.75rem;" onclick="aplicarPlantilla('docente', this.closest('form'))">Docente</button>
                                                        <button type="button" class="btn btn-outline-success py-0 px-2" style="font-size: 0.75rem;" onclick="aplicarPlantilla('caja', this.closest('form'))">Caja</button>
                                                        <button type="button" class="btn btn-outline-info py-0 px-2" style="font-size: 0.75rem;" onclick="aplicarPlantilla('secretaria', this.closest('form'))">Secretaría</button>
                                                        <button type="button" class="btn btn-outline-dark py-0 px-2" style="font-size: 0.75rem;" onclick="aplicarPlantilla('coordinacion', this.closest('form'))">Coord.</button>
                                                        <button type="button" class="btn btn-outline-secondary py-0 px-2" style="font-size: 0.75rem;" onclick="aplicarPlantilla('ninguno', this.closest('form'))">0</button>
                                                    </div>
                                                </div>

                                                <div class="d-flex flex-column gap-2 mb-3" style="max-height: 280px; overflow-y: auto;">
                                                    <?php foreach ($modulosDef as $modKey => $modInfo): ?>
                                                        <?php if (empty($permisosAgrupados[$modKey])) continue; ?>
                                                        <div class="p-2 border rounded bg-white">
                                                            <div class="fw-bold text-dark small mb-1 pb-1 border-bottom d-flex align-items-center gap-1">
                                                                <i class="bi <?= $modInfo['icono'] ?> text-<?= $modInfo['badge'] ?>"></i>
                                                                <?= htmlspecialchars($modInfo['titulo']) ?>
                                                            </div>
                                                            <?php foreach ($permisosAgrupados[$modKey] as $permiso): ?>
                                                                <?php $isChecked = isset($mapa[$asistente['id']][(int) $permiso['id']]); ?>
                                                                <label class="form-check d-flex align-items-center gap-2 mb-1" style="font-size: 0.82rem;">
                                                                    <input class="form-check-input mt-0" type="checkbox" name="permisos[]" 
                                                                           value="<?= (int) $permiso['id'] ?>" 
                                                                           data-codigo="<?= htmlspecialchars($permiso['codigo']) ?>"
                                                                           <?= $isChecked ? 'checked' : '' ?>>
                                                                    <span class="form-check-label text-dark"><?= htmlspecialchars($permiso['nombre']) ?></span>
                                                                </label>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                                <button class="btn btn-primary btn-sm px-3 py-1 fw-semibold w-100" style="border-radius: 7px; font-size: 0.88rem;" type="submit">
                                                    <i class="bi bi-check2"></i> Guardar Cambios
                                                </button>
                                            </form>
                                        </details>
                                        <button type="button" class="btn btn-outline-warning btn-sm px-3 fw-semibold text-dark" style="border-radius: 8px; font-size: 0.9rem;"
                                                onclick="abrirModalReset(<?= (int)$asistente['id'] ?>, '<?= htmlspecialchars(addslashes($asistente['nombres'])) ?>', '<?= htmlspecialchars(addslashes($asistente['correo'])) ?>')">
                                            <i class="bi bi-key-fill text-warning"></i> Cambiar Clave
                                        </button>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokenCsrf) ?>">
                                            <input type="hidden" name="usuario_id" value="<?= (int) $asistente['id'] ?>">
                                            <?php if ($asistente['estado']): ?>
                                                <input type="hidden" name="action" value="revoke">
                                                <button class="btn btn-outline-danger btn-sm px-3 fw-semibold" style="border-radius: 8px; font-size: 0.9rem;" type="submit">
                                                    <i class="bi bi-slash-circle"></i> Revocar
                                                </button>
                                            <?php else: ?>
                                                <input type="hidden" name="action" value="restore">
                                                <button class="btn btn-outline-success btn-sm px-3 fw-semibold" style="border-radius: 8px; font-size: 0.9rem;" type="submit">
                                                    <i class="bi bi-arrow-clockwise"></i> Restaurar
                                                </button>
                                            <?php endif; ?>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$asistentes): ?>
                            <tr><td colspan="4" class="text-center text-muted py-4">Todavía no hay asistentes registrados.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</main>

<!-- Modal Reset Contraseña Asistente -->
<div class="modal fade" id="resetPasswordModal" tabindex="-1" aria-labelledby="resetPasswordModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokenCsrf) ?>">
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="usuario_id" id="modalResetUsuarioId" value="">
                
                <div class="modal-header bg-light border-bottom py-3 px-4">
                    <h5 class="modal-title fw-bold text-dark d-flex align-items-center gap-2 mb-0" id="resetPasswordModalLabel">
                        <i class="bi bi-shield-lock-fill text-warning"></i> Restablecer Contraseña
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="p-3 mb-3 bg-light rounded-3 border">
                        <div class="text-muted small fw-semibold">Asistente:</div>
                        <div class="fw-bold text-dark fs-6" id="modalResetNombre">-</div>
                        <div class="text-muted small font-monospace" id="modalResetCorreo">-</div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label" for="modalNewPassword">Nueva Contraseña <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="text" class="form-control font-monospace" id="modalNewPassword" name="new_password" minlength="8" required placeholder="Mínimo 8 caracteres">
                            <button class="btn btn-outline-secondary d-inline-flex align-items-center gap-1" type="button" onclick="generarPasswordSegura()" title="Generar clave aleatoria">
                                <i class="bi bi-shuffle"></i> Generar
                            </button>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="modalConfirmPassword">Confirmar Contraseña <span class="text-danger">*</span></label>
                        <input type="text" class="form-control font-monospace" id="modalConfirmPassword" name="confirm_password" minlength="8" required placeholder="Repite la contraseña">
                    </div>
                    <div class="alert alert-info py-2 px-3 small mb-0 d-flex align-items-center gap-2 border-0">
                        <i class="bi bi-info-circle-fill fs-6"></i>
                        <span>La clave se actualizará de inmediato y se completarán las solicitudes pendientes del usuario.</span>
                    </div>
                </div>
                <div class="modal-footer border-top bg-light py-3 px-4">
                    <button type="button" class="btn btn-outline-secondary px-3" data-bs-dismiss="modal" style="border-radius: 8px;">Cancelar</button>
                    <button type="submit" class="btn btn-primary px-4 fw-semibold" style="border-radius: 8px;">
                        <i class="bi bi-check-lg me-1"></i> Guardar Nueva Contraseña
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/permisos.js" defer></script>
</body>
</html>
