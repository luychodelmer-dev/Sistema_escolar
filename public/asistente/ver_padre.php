<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/header.php';

require_auth(['asistente', 'director']);
$pdo->exec('ALTER TABLE pago ADD COLUMN IF NOT EXISTS responsable_id BIGINT REFERENCES responsable(id) ON DELETE SET NULL');

$id = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false) {
    http_response_code(400);
    exit('ID de responsable inválido.');
}

$stmt = $pdo->prepare('SELECT * FROM responsable WHERE id = :id');
$stmt->execute(['id' => $id]);
$responsable = $stmt->fetch();
if (!$responsable) {
    http_response_code(404);
    exit('Responsable no encontrado.');
}

$studentsStmt = $pdo->prepare(
    'SELECT a.id, a.dni, a.nombres, a.apellido_paterno, a.apellido_materno,
            ar.tipo, ar.parentesco, ar.es_apoderado,
            matricula.anio, matricula.nivel, matricula.grado, matricula.seccion
     FROM estudiante_responsable ar
     JOIN estudiante a ON a.id = ar.estudiante_id
     LEFT JOIN LATERAL (
         SELECT an.anio, n.nombre AS nivel, g.nombre AS grado, s.nombre AS seccion
         FROM matricula m
         LEFT JOIN anio an ON an.id = m.anio_id
         LEFT JOIN nivel n ON n.id = m.nivel_id
         LEFT JOIN grado_seccion gs ON gs.id = m.grado_seccion_id
         LEFT JOIN grado g ON g.id = gs.grado_id
         LEFT JOIN seccion s ON s.id = gs.seccion_id
         WHERE m.estudiante_id = a.id
         ORDER BY an.anio DESC NULLS LAST, m.id DESC
         LIMIT 1
     ) matricula ON TRUE
     WHERE ar.responsable_id = :id
     ORDER BY a.apellido_paterno, a.apellido_materno, a.nombres'
);
$studentsStmt->execute(['id' => $id]);
$estudiantes = $studentsStmt->fetchAll();

$paymentStmt = $pdo->prepare(
    "SELECT DISTINCT p.id, p.fecha_pago, p.monto, p.nro_recibo, p.observacion,
            COALESCE(NULLIF(p.concepto, ''), cp.descripcion, '-') AS concepto,
            COALESCE(NULLIF(p.estado, ''), p.estado_pago, 'Pagado') AS estado,
            a.id AS estudiante_id, a.nombres AS estudiante_nombres,
            a.apellido_paterno AS estudiante_apellido_paterno
     FROM pago p
     LEFT JOIN matricula m ON m.id = p.matricula_id
     LEFT JOIN estudiante a ON a.id = m.estudiante_id
     LEFT JOIN estudiante_responsable ar ON ar.estudiante_id = m.estudiante_id AND ar.responsable_id = :linked_id
     LEFT JOIN concepto_pago cp ON cp.id = p.concepto_id
      WHERE p.responsable_id = :direct_id
          OR ar.responsable_id IS NOT NULL
        OR LOWER(TRIM(COALESCE(p.responsable, ''))) = LOWER(TRIM(:legacy_name))
     ORDER BY p.fecha_pago DESC, p.id DESC"
);
$paymentStmt->execute([
    'direct_id' => $id,
    'linked_id' => $id,
    'legacy_name' => $responsable['nombres'] . ' ' . $responsable['apellido_paterno'] . ' ' . ($responsable['apellido_materno'] ?? ''),
]);
$pagos = $paymentStmt->fetchAll();
$h = static fn ($value): string => htmlspecialchars((string) ($value ?? '-'), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Detalle del apoderado - Sistema Escolar</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php render_app_header(panel_home_path(), 'logout.php'); ?>
<main class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4"><h1 class="h3 mb-0">Detalle del padre / apoderado</h1><a href="padres.php" class="btn btn-outline-secondary">Volver</a></div>
    <section class="card border-0 shadow-sm mb-4"><div class="card-body"><h2 class="h5">Información personal</h2><dl class="row mb-0">
        <dt class="col-sm-3">Nombres</dt><dd class="col-sm-9"><?= $h($responsable['nombres']) ?></dd>
        <dt class="col-sm-3">Apellidos</dt><dd class="col-sm-9"><?= $h($responsable['apellido_paterno'] . ' ' . ($responsable['apellido_materno'] ?? '')) ?></dd>
        <dt class="col-sm-3">DNI</dt><dd class="col-sm-9"><?= $h($responsable['dni']) ?></dd>
        <dt class="col-sm-3">Celular</dt><dd class="col-sm-9"><?= $h($responsable['celular_actual'] ?: $responsable['celular']) ?></dd>
        <dt class="col-sm-3">Correo</dt><dd class="col-sm-9"><?= $h($responsable['correo']) ?></dd>
        <dt class="col-sm-3">Dirección</dt><dd class="col-sm-9"><?= $h($responsable['direccion']) ?></dd>
        <dt class="col-sm-3">Tarjeta APAFA</dt><dd class="col-sm-9"><?= $h($responsable['nro_tarjeta_apafa']) ?></dd>
    </dl></div></section>
    <section class="card border-0 shadow-sm mb-4"><div class="card-body"><h2 class="h5">Estudiantes a cargo</h2><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th>Estudiante</th><th>DNI</th><th>Parentesco</th><th>Apoderado</th><th>Matrícula vigente</th></tr></thead><tbody>
        <?php foreach ($estudiantes as $estudiante): ?><tr><td><a href="ver_estudiante.php?id=<?= (int) $estudiante['id'] ?>"><?= $h($estudiante['apellido_paterno'] . ' ' . $estudiante['apellido_materno'] . ', ' . $estudiante['nombres']) ?></a></td><td><?= $h($estudiante['dni']) ?></td><td><?= $h($estudiante['parentesco']) ?></td><td><?= $estudiante['es_apoderado'] ? 'Sí' : 'No' ?></td><td><?= $estudiante['nivel'] ? $h($estudiante['nivel'] . ' / ' . $estudiante['grado'] . ' / ' . $estudiante['seccion'] . ' / ' . $estudiante['anio']) : 'Sin matrícula' ?></td></tr><?php endforeach; ?>
        <?php if (!$estudiantes): ?><tr><td colspan="5" class="text-center text-muted">No tiene estudiantes vinculados.</td></tr><?php endif; ?>
    </tbody></table></div></div></section>
    <section class="card border-0 shadow-sm"><div class="card-body"><h2 class="h5">Pagos realizados</h2><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th>Fecha</th><th>Estudiante</th><th>Concepto</th><th>Monto</th><th>Estado</th><th>Recibo</th><th>Observación</th></tr></thead><tbody>
        <?php foreach ($pagos as $pago): ?><tr><td><?= $h($pago['fecha_pago']) ?></td><td><?php if ($pago['estudiante_id']): ?><a href="ver_estudiante.php?id=<?= (int) $pago['estudiante_id'] ?>"><?= $h($pago['estudiante_apellido_paterno'] . ', ' . $pago['estudiante_nombres']) ?></a><?php else: ?>-<?php endif; ?></td><td><?= $h($pago['concepto']) ?></td><td>S/ <?= $h(number_format((float) $pago['monto'], 2)) ?></td><td><?= $h($pago['estado']) ?></td><td><?= $h($pago['nro_recibo']) ?></td><td><?= $h($pago['observacion']) ?></td></tr><?php endforeach; ?>
        <?php if (!$pagos): ?><tr><td colspan="7" class="text-center text-muted">No hay pagos registrados para este responsable.</td></tr><?php endif; ?>
    </tbody></table></div></div></section>
</main>
</body>
</html>
