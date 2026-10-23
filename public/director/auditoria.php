<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/header.php';

require_director();

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
$sql = "SELECT a.creado_en, a.accion, a.detalles, a.ip_origen, u.nombres AS usuario
        FROM auditoria_movimiento a
        LEFT JOIN usuario u ON u.id = a.usuario_id
        $whereSql
        ORDER BY a.id DESC
        LIMIT 500";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$movimientos = $stmt->fetchAll();
$renderRows = static function (array $rows): void {
    foreach ($rows as $movimiento) {
        echo '<tr><td>' . htmlspecialchars($movimiento['creado_en']) . '</td>'
            . '<td>' . htmlspecialchars($movimiento['usuario'] ?? 'Usuario eliminado') . '</td>'
            . '<td>' . htmlspecialchars($movimiento['accion']) . '</td>'
            . '<td class="audit-details">' . nl2br(htmlspecialchars($movimiento['detalles'] ?? '')) . '</td>'
            . '<td>' . htmlspecialchars($movimiento['ip_origen'] ?? '-') . '</td></tr>';
    }
    if (!$rows) {
        echo '<tr><td colspan="5" class="text-center text-muted py-4">No hay movimientos para los filtros seleccionados.</td></tr>';
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
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Auditoría - Sistema Escolar</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>body { background:#f4f7fb;color:#172b4d; }.page-header { background:#172b4d;color:#fff; }.institution-brand { display:flex;align-items:center;gap:.6rem;white-space:nowrap; }.institution-brand img { width:42px;height:42px;object-fit:contain;border-radius:50%;background:#fff; }.institution-brand span { font-size:1.1rem;white-space:nowrap; }.header-user-photo { width:52px;height:52px;object-fit:cover;border:2px solid rgba(255,255,255,.85);border-radius:50%; }.audit-details { white-space:pre-wrap;min-width:18rem; } @media (max-width:768px) {.institution-brand span {font-size:.95rem;}}</style>
</head>
<body>
<?php render_app_header(panel_home_path(), '../asistente/logout.php'); ?>
<main class="container py-4"><div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4"><div><h1 class="h3 mb-1">Auditoría de movimientos</h1><p class="text-muted mb-0">Registro de accesos y cambios realizados en el sistema.</p></div><a href="<?= htmlspecialchars(panel_home_path()) ?>" class="btn btn-outline-primary">Volver al panel</a></div>
<form id="audit-filters" class="card card-body shadow-sm border-0 mb-3 row g-2 align-items-end">
    <div class="col-md-3"><label class="form-label" for="fecha_inicio">Desde</label><input class="form-control" type="date" id="fecha_inicio" name="fecha_inicio" value="<?= htmlspecialchars(is_string($fechaInicio) ? $fechaInicio : '') ?>"></div>
    <div class="col-md-3"><label class="form-label" for="fecha_fin">Hasta</label><input class="form-control" type="date" id="fecha_fin" name="fecha_fin" value="<?= htmlspecialchars(is_string($fechaFin) ? $fechaFin : '') ?>"></div>
    <div class="col-md-3"><label class="form-label" for="usuario_id">Usuario</label><select class="form-select" id="usuario_id" name="usuario_id"><option value="">Todos</option><?php foreach ($usuarios as $usuario): ?><option value="<?= (int) $usuario['id'] ?>" <?= (string) $usuarioId === (string) $usuario['id'] ? 'selected' : '' ?>><?= htmlspecialchars($usuario['nombres']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-3"><label class="form-label" for="tipo_accion">Tipo de acción</label><select class="form-select" id="tipo_accion" name="tipo_accion"><option value="">Todas</option><?php foreach (['INSERT', 'UPDATE', 'DELETE'] as $tipo): ?><option value="<?= $tipo ?>" <?= $tipoAccion === $tipo ? 'selected' : '' ?>><?= $tipo ?></option><?php endforeach; ?></select></div>
</form>
<section class="card shadow-sm border-0"><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th>Fecha</th><th>Usuario</th><th>Acción</th><th>Detalles</th><th>IP</th></tr></thead><tbody id="audit-rows"><?php $renderRows($movimientos); ?></tbody></table></div></section></main>
<script>
const auditFilters = document.getElementById('audit-filters');
const auditRows = document.getElementById('audit-rows');
let auditRequest;
let auditTimer;
async function refreshAudit() {
    auditRequest?.abort();
    auditRequest = new AbortController();
    const query = new URLSearchParams(new FormData(auditFilters));
    query.set('ajax', 'tabla');
    try {
        const response = await fetch(`auditoria.php?${query}`, {
            headers: { Accept: 'text/html' },
            signal: auditRequest.signal
        });
        if (!response.ok) throw new Error('No se pudo actualizar la auditoría.');
        auditRows.innerHTML = await response.text();
    } catch (error) {
        if (error.name !== 'AbortError') {
            auditRows.innerHTML = '<tr><td colspan="5" class="text-danger text-center">Error al cargar los registros.</td></tr>';
            console.error(error);
        }
    }
}
auditFilters.addEventListener('input', () => {
    clearTimeout(auditTimer);
    auditTimer = setTimeout(refreshAudit, 250);
});
auditFilters.addEventListener('change', refreshAudit);
</script>
</body>
</html>
