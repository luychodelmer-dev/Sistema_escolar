<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/header.php';

require_auth(['director', 'hiperadmin', 'administrador']);
$csrfToken = $_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));
$puedeEliminarAuditoria = has_permission('p_auditoria');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'eliminar_auditoria') {
    require_permission('p_auditoria');
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!is_string($submittedToken) || !hash_equals($csrfToken, $submittedToken)) {
        http_response_code(400);
        exit('Solicitud inválida. Recarga la página e inténtalo nuevamente.');
    }
    $auditId = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($auditId === false) {
        http_response_code(400);
        exit('El registro de auditoría no es válido.');
    }
    $deleteAudit = $pdo->prepare('DELETE FROM auditoria_movimiento WHERE id = :id');
    $deleteAudit->execute(['id' => $auditId]);
    header('Location: auditoria.php');
    exit;
}

if (($_GET['ajax'] ?? '') === 'grafico') {
    header('Content-Type: application/json; charset=utf-8');
    $stmt = $pdo->query("
        SELECT a.usuario_id, COALESCE(u.nombres, 'Usuario eliminado') AS usuario, COUNT(*) AS total
        FROM auditoria_movimiento a
        LEFT JOIN usuario u ON u.id = a.usuario_id
        WHERE LOWER(a.accion) <> 'inicio_sesion'
        GROUP BY a.usuario_id, u.nombres
        ORDER BY total DESC, usuario ASC
    ");
    $actividad = $stmt->fetchAll();
    echo json_encode([
        'labels' => array_column($actividad, 'usuario'),
        'data' => array_map('intval', array_column($actividad, 'total')),
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

$usuarios = $pdo->query('SELECT id, nombres FROM usuario ORDER BY nombres')->fetchAll();
$conditions = [];
$params = [];
$fechaInicio = $_GET['fecha_inicio'] ?? '';
$fechaFin = $_GET['fecha_fin'] ?? '';
$usuarioId = $_GET['usuario_id'] ?? '';
$tipoAccion = $_GET['tipo_accion'] ?? '';

if (is_string($fechaInicio) && $fechaInicio !== '' && DateTime::createFromFormat('!Y-m-d', $fechaInicio)) {
    $conditions[] = 'a.creado_en >= :fecha_inicio';
    $params['fecha_inicio'] = $fechaInicio;
}
if (is_string($fechaFin) && $fechaFin !== '' && DateTime::createFromFormat('!Y-m-d', $fechaFin)) {
    $conditions[] = 'a.creado_en < CAST(:fecha_fin AS date) + INTERVAL \'1 day\'';
    $params['fecha_fin'] = $fechaFin;
}
$validatedUsuarioId = is_string($usuarioId)
    ? filter_var($usuarioId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : false;
if ($validatedUsuarioId !== false && $validatedUsuarioId !== null) {
    $conditions[] = 'a.usuario_id = :usuario_id';
    $params['usuario_id'] = $validatedUsuarioId;
}
if (is_string($tipoAccion) && in_array($tipoAccion, ['INSERT', 'UPDATE', 'DELETE'], true)) {
    $conditions[] = match ($tipoAccion) {
        'INSERT' => "(LOWER(a.accion) LIKE 'crear%' OR LOWER(a.accion) LIKE 'registr%')",
        'UPDATE' => "(LOWER(a.accion) LIKE 'editar%' OR LOWER(a.accion) LIKE 'actualizar%' OR LOWER(a.accion) LIKE 'cambio%')",
        'DELETE' => "LOWER(a.accion) LIKE 'eliminar%'",
    };
}
$whereSql = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
$sql = "SELECT a.id, a.creado_en, a.accion, a.detalles, a.ip_origen, u.nombres AS usuario
        FROM auditoria_movimiento a
        LEFT JOIN usuario u ON u.id = a.usuario_id
        $whereSql
        ORDER BY a.id DESC
        LIMIT 500";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$movimientos = $stmt->fetchAll();
$renderRows = static function (array $rows) use ($puedeEliminarAuditoria, $csrfToken): void {
    foreach ($rows as $movimiento) {
        echo '<tr><td class="font-monospace text-muted small" style="white-space: nowrap;">' . htmlspecialchars($movimiento['creado_en']) . '</td>'
            . '<td class="fw-semibold text-dark">' . htmlspecialchars($movimiento['usuario'] ?? 'Usuario eliminado') . '</td>'
            . '<td><span class="badge bg-light text-secondary border font-monospace" style="font-size: 0.85rem;">' . htmlspecialchars($movimiento['accion']) . '</span></td>'
            . '<td class="audit-details">' . nl2br(htmlspecialchars($movimiento['detalles'] ?? '')) . '</td>'
            . '<td class="font-monospace small text-muted">' . htmlspecialchars($movimiento['ip_origen'] ?? '-') . '</td><td>';
        if ($puedeEliminarAuditoria) {
            echo '<form method="POST" onsubmit="return confirm(\'¿Eliminar este registro de auditoría?\')">'
                . '<input type="hidden" name="action" value="eliminar_auditoria">'
                . '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') . '">'
                . '<input type="hidden" name="id" value="' . (int) $movimiento['id'] . '">'
                . '<button class="btn btn-sm btn-outline-danger px-2 py-1" style="font-size: 0.85rem; border-radius: 7px;" type="submit">Eliminar</button></form>';
        }
        echo '</td></tr>';
    }
    if (!$rows) {
        echo '<tr><td colspan="6" class="text-center text-muted py-4">No hay movimientos para los filtros seleccionados.</td></tr>';
    }
};
if (($_GET['ajax'] ?? '') === 'tabla') {
    header('Content-Type: text/html; charset=utf-8');
    $renderRows($movimientos);
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Auditoría - Sistema Escolar</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../assets/css/app.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="../assets/css/auditoria.css">
</head>
<body>
<?php render_app_header(panel_home_path(), '../asistente/logout.php'); ?>
<main class="container py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <h1 class="h3 fw-bold mb-1 text-dark d-flex align-items-center gap-2">
                <i class="bi bi-clock-history text-primary"></i> Auditoría de Movimientos
            </h1>
            <p class="text-muted mb-0 small">Registro cronológico de accesos, modificaciones y transacciones.</p>
        </div>
        <a href="<?= htmlspecialchars(panel_home_path()) ?>" class="btn btn-outline-primary d-inline-flex align-items-center gap-1 px-3 py-2 fw-semibold" style="border-radius: 10px; font-size: 0.96rem;">
            <i class="bi bi-arrow-left"></i> Volver al Panel
        </a>
    </div>

    <section class="card audit-card mb-4">
        <div class="card-body p-4">
            <div class="row g-4 align-items-center">
                <div class="col-lg-8 border-end-lg">
                    <h5 class="fw-bold text-dark mb-3"><i class="bi bi-bar-chart-line text-primary"></i> Actividad por Usuario</h5>
                    <div style="height: 22rem;"><canvas id="activityChart" aria-label="Cantidad de acciones por usuario" role="img"></canvas></div>
                    <p id="activitySummary" class="text-muted text-center mt-3 mb-0 fw-semibold" style="font-size: 0.95rem;" aria-live="polite">Cargando actividad...</p>
                </div>
                <div class="col-lg-4">
                    <h5 class="fw-bold text-dark mb-3"><i class="bi bi-funnel text-primary"></i> Filtros de Auditoría</h5>
                    <form id="audit-filters" class="row g-2 align-items-end">
                        <div class="col-12">
                            <label class="form-label" for="fecha_inicio">Desde</label>
                            <input class="form-control" type="date" id="fecha_inicio" name="fecha_inicio" value="<?= htmlspecialchars(is_string($fechaInicio) ? $fechaInicio : '') ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="fecha_fin">Hasta</label>
                            <input class="form-control" type="date" id="fecha_fin" name="fecha_fin" value="<?= htmlspecialchars(is_string($fechaFin) ? $fechaFin : '') ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="usuario_id">Usuario Responsable</label>
                            <select class="form-select" id="usuario_id" name="usuario_id">
                                <option value="">Todos los usuarios</option>
                                <?php foreach ($usuarios as $usuario): ?>
                                    <option value="<?= (int) $usuario['id'] ?>" <?= (string) $usuarioId === (string) $usuario['id'] ? 'selected' : '' ?>><?= htmlspecialchars($usuario['nombres']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="tipo_accion">Tipo de Acción</label>
                            <select class="form-select" id="tipo_accion" name="tipo_accion">
                                <option value="">Todas las acciones</option>
                                <?php foreach (['INSERT', 'UPDATE', 'DELETE'] as $tipo): ?>
                                    <option value="<?= $tipo ?>" <?= $tipoAccion === $tipo ? 'selected' : '' ?>><?= $tipo ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </section>

    <section class="card audit-card">
        <div class="card-header bg-white py-3 px-4 border-bottom">
            <h5 class="fw-bold mb-0 text-dark d-flex align-items-center gap-2">
                <i class="bi bi-list-columns-reverse text-primary"></i> Registros Recientes
            </h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="min-width: 160px;">Fecha y Hora</th>
                            <th style="min-width: 170px;">Usuario</th>
                            <th style="min-width: 160px;">Movimiento</th>
                            <th style="min-width: 280px;">Detalles</th>
                            <th style="min-width: 120px;">IP Origen</th>
                            <th style="min-width: 105px;">Acción</th>
                        </tr>
                    </thead>
                    <tbody id="audit-rows"><?php $renderRows($movimientos); ?></tbody>
                </table>
            </div>
        </div>
    </section>
</main>
<script src="../assets/js/auditoria.js" defer></script>
</body>
</html>
