<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/header.php';

require_auth(['asistente', 'director']);

$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS pagos (
    id SERIAL PRIMARY KEY,
    responsable VARCHAR(150) NOT NULL,
    concepto VARCHAR(80) NOT NULL,
    monto NUMERIC(10, 2) NOT NULL,
    fecha_pago DATE NOT NULL,
    estado VARCHAR(20) NOT NULL DEFAULT 'Pagado',
    observacion VARCHAR(250),
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)
SQL);

$error = '';
$mensaje = '';
$busqueda = trim($_GET['buscar'] ?? '');
$puedeCobrar = has_permission('p_cobrar');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_permission('p_cobrar');
    $datos = [
        'responsable' => trim($_POST['responsable'] ?? ''),
        'concepto' => trim($_POST['concepto'] ?? ''),
        'monto' => trim($_POST['monto'] ?? ''),
        'fecha_pago' => trim($_POST['fecha_pago'] ?? ''),
        'estado' => trim($_POST['estado'] ?? 'Pagado'),
        'observacion' => trim($_POST['observacion'] ?? ''),
    ];

    if ($datos['responsable'] === '' || $datos['concepto'] === '' || $datos['monto'] === '' || $datos['fecha_pago'] === '') {
        $error = 'Completa todos los campos obligatorios.';
    } elseif (!is_numeric($datos['monto']) || (float) $datos['monto'] < 0) {
        $error = 'El monto debe ser un número válido.';
    } else {
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO pagos (responsable, concepto, monto, fecha_pago, estado, observacion)
                 VALUES (:responsable, :concepto, :monto, :fecha_pago, :estado, :observacion)'
            );
            $stmt->execute($datos);
            registrar_auditoria('crear_pago', 'Registró pago de concepto ' . $datos['concepto'] . ' por monto ' . $datos['monto'] . '.');
            header('Location: pagos.php?guardado=1');
            exit;
        } catch (PDOException $exception) {
            $error = 'No se pudo guardar el pago.';
        }
    }
}

if (isset($_GET['guardado'])) {
    $mensaje = 'Pago registrado correctamente.';
}

$sql = 'SELECT * FROM pagos';
$params = [];
if ($busqueda !== '') {
    $sql .= ' WHERE responsable ILIKE :busqueda OR concepto ILIKE :busqueda OR estado ILIKE :busqueda';
    $params['busqueda'] = "%$busqueda%";
}
$sql .= ' ORDER BY fecha_pago DESC, id DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$pagos = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pagos - Sistema Escolar</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>body { background: #f4f7fb; color: #172b4d; } .page-header { background: #172b4d; color: #fff; } .card { border: 0; border-radius: 12px; } .required::after { content: ' *'; color: #dc3545; } .institution-brand { display: flex; align-items: center; gap: .6rem; white-space: nowrap; } .institution-brand img { width: 42px; height: 42px; object-fit: contain; border-radius: 50%; background: #fff; } .institution-brand span { font-size: 1.1rem; white-space: nowrap; } .header-user-photo { width: 52px; height: 52px; object-fit: cover; border: 2px solid rgba(255, 255, 255, .85); border-radius: 50%; } @media (max-width: 768px) { .institution-brand span { font-size: .95rem; } }</style>
</head>
<body>
<?php render_app_header('panel.php', 'logout.php'); ?>
<main class="container py-4">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4"><div><h1 class="h3 mb-1">Control de pagos</h1><p class="text-muted mb-0">Registra cuotas, aportes y otros pagos escolares.</p></div><a href="panel.php" class="btn btn-outline-primary">Volver al panel</a></div>
    <?php if ($mensaje !== ''): ?><div class="alert alert-success"><?= htmlspecialchars($mensaje) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert alert-danger" role="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($puedeCobrar): ?><section class="card shadow-sm mb-4"><div class="card-body p-4"><h2 class="h5 mb-3">Nuevo pago</h2><form method="POST" class="row g-3">
        <div class="col-md-5"><label class="form-label required" for="responsable">Responsable</label><input class="form-control" id="responsable" name="responsable" required></div>
        <div class="col-md-3"><label class="form-label required" for="concepto">Concepto</label><select class="form-select" id="concepto" name="concepto" required><option value="">Seleccionar</option><option>Cuota APAFA</option><option>Qaliwarma</option><option>Internet</option><option>Multa</option><option>Apoyo a la gestión</option><option>Otro</option></select></div>
        <div class="col-md-2"><label class="form-label required" for="monto">Monto</label><input class="form-control" id="monto" name="monto" type="number" min="0" step="0.01" required></div>
        <div class="col-md-2"><label class="form-label required" for="fecha_pago">Fecha</label><input class="form-control" id="fecha_pago" name="fecha_pago" type="date" value="<?= date('Y-m-d') ?>" required></div>
        <div class="col-md-3"><label class="form-label" for="estado">Estado</label><select class="form-select" id="estado" name="estado"><option>Pagado</option><option>Pendiente</option><option>Anulado</option></select></div>
        <div class="col-md-9"><label class="form-label" for="observacion">Observación</label><input class="form-control" id="observacion" name="observacion" maxlength="250"></div>
        <div class="col-12"><button class="btn btn-warning" type="submit">Guardar pago</button></div>
    </form></div></section><?php endif; ?>
    <section class="card shadow-sm"><div class="card-body p-4"><form method="GET" class="row g-2 mb-3"><div class="col-sm-9"><label class="visually-hidden" for="buscar">Buscar pago</label><input class="form-control" id="buscar" name="buscar" value="<?= htmlspecialchars($busqueda) ?>" placeholder="Buscar por responsable, concepto o estado"></div><div class="col-sm-3"><button class="btn btn-outline-primary w-100" type="submit">Buscar</button></div></form><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th>Responsable</th><th>Concepto</th><th>Monto</th><th>Fecha</th><th>Estado</th><th>Observación</th></tr></thead><tbody>
    <?php foreach ($pagos as $pago): ?><tr><td><?= htmlspecialchars($pago['responsable']) ?></td><td><?= htmlspecialchars($pago['concepto']) ?></td><td>S/ <?= htmlspecialchars(number_format((float) $pago['monto'], 2)) ?></td><td><?= htmlspecialchars($pago['fecha_pago']) ?></td><td><?= htmlspecialchars($pago['estado']) ?></td><td><?= htmlspecialchars($pago['observacion'] ?: '-') ?></td></tr><?php endforeach; ?>
    <?php if (!$pagos): ?><tr><td colspan="6" class="text-center text-muted py-4">No hay pagos para mostrar.</td></tr><?php endif; ?></tbody></table></div></div></section>
</main>
</body>
</html>
