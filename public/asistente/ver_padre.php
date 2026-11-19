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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../assets/css/app.css">
    <link rel="stylesheet" href="../assets/css/ver_padre.css">
</head>
<body class="bg-light">
<?php render_app_header(panel_home_path(), 'logout.php'); ?>
<main class="container py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <h1 class="h3 fw-bold mb-1 text-dark d-flex align-items-center gap-2">
                <i class="bi bi-person-lines-fill text-primary"></i> Detalle del Padre / Apoderado
            </h1>
            <p class="text-muted mb-0 small">Información de contacto, estudiantes a cargo e historial de pagos.</p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <a href="ficha_padre.php?id=<?= (int)$responsable['id'] ?>" target="_blank" rel="opener" class="btn btn-outline-primary d-inline-flex align-items-center gap-1.5 px-3 py-2 fw-semibold" style="border-radius: 10px; font-size: 0.96rem;">
                <i class="bi bi-printer"></i> Imprimir Ficha Familiar
            </a>
            <a href="padres.php" class="btn btn-outline-secondary d-inline-flex align-items-center gap-1 px-3 py-2" style="border-radius: 10px; font-size: 0.96rem;">
                <i class="bi bi-arrow-left"></i> Volver a Padres
            </a>
        </div>
    </div>

    <section class="card info-card mb-4">
        <div class="card-body p-4">
            <h2 class="section-header"><i class="bi bi-person-vcard text-primary"></i> Información Personal y Contacto</h2>
            <dl class="row mb-0">
                <dt class="col-sm-3">Nombres</dt>
                <dd class="col-sm-9 fw-semibold text-primary"><?= $h($responsable['nombres']) ?></dd>
                <dt class="col-sm-3">Apellidos</dt>
                <dd class="col-sm-9 fw-semibold"><?= $h($responsable['apellido_paterno'] . ' ' . ($responsable['apellido_materno'] ?? '')) ?></dd>
                <dt class="col-sm-3">DNI</dt>
                <dd class="col-sm-9"><span class="badge bg-light text-dark border fs-6 font-monospace"><?= $h($responsable['dni']) ?></span></dd>
                <dt class="col-sm-3">Celular Principal</dt>
                <dd class="col-sm-9">
                    <?php $cel = $responsable['celular_actual'] ?: $responsable['celular']; ?>
                    <?php if ($cel): ?>
                        <span class="font-monospace fw-bold fs-6 text-dark"><?= $h($cel) ?></span>
                        <a href="https://wa.me/51<?= htmlspecialchars(preg_replace('/\D/', '', $cel)) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-success ms-2 px-2 py-0 align-middle">
                            <i class="bi bi-whatsapp"></i> WhatsApp
                        </a>
                    <?php else: ?>
                        <span class="text-muted">-</span>
                    <?php endif; ?>
                </dd>
                <dt class="col-sm-3">Correo</dt>
                <dd class="col-sm-9"><?= $h($responsable['correo']) ?></dd>
                <dt class="col-sm-3">Dirección</dt>
                <dd class="col-sm-9"><?= $h($responsable['direccion']) ?></dd>
                <dt class="col-sm-3">Tarjeta APAFA</dt>
                <dd class="col-sm-9">
                    <?php if ($responsable['nro_tarjeta_apafa']): ?>
                        <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle font-monospace fs-6">
                            <?= $h($responsable['nro_tarjeta_apafa']) ?>
                        </span>
                    <?php else: ?>
                        <span class="text-muted">No asignada</span>
                    <?php endif; ?>
                </dd>
            </dl>
        </div>
    </section>

    <section class="card info-card mb-4">
        <div class="card-body p-4">
            <h2 class="section-header">
                <i class="bi bi-mortarboard text-primary"></i> Estudiantes a Cargo
                <span class="badge bg-primary-subtle text-primary border border-primary-subtle fs-6 ms-2"><?= count($estudiantes) ?></span>
            </h2>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="min-width: 240px;">Estudiante</th>
                            <th style="min-width: 110px;">DNI</th>
                            <th style="min-width: 120px;">Parentesco</th>
                            <th style="min-width: 110px;">Apoderado</th>
                            <th style="min-width: 220px;">Matrícula Vigente</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($estudiantes as $estudiante): ?>
                            <tr>
                                <td>
                                    <a href="ver_estudiante.php?id=<?= (int) $estudiante['id'] ?>" class="text-decoration-none fw-semibold text-dark hover-primary" style="font-size: 1.02rem;">
                                        <?= $h($estudiante['apellido_paterno'] . ' ' . $estudiante['apellido_materno'] . ', ' . $estudiante['nombres']) ?>
                                    </a>
                                </td>
                                <td><span class="badge bg-light text-dark border font-monospace"><?= $h($estudiante['dni']) ?></span></td>
                                <td><?= $h($estudiante['parentesco']) ?></td>
                                <td>
                                    <?php if ($estudiante['es_apoderado']): ?>
                                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle">Sí (Principal)</span>
                                    <?php else: ?>
                                        <span class="badge bg-light text-muted border">No</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= $estudiante['nivel'] ? '<span class="badge bg-info-subtle text-info-emphasis border border-info-subtle">' . $h($estudiante['nivel'] . ' / ' . $estudiante['grado'] . ' / ' . $estudiante['seccion'] . ' (' . $estudiante['anio'] . ')') . '</span>' : '<span class="text-muted">Sin matrícula</span>' ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$estudiantes): ?>
                            <tr><td colspan="5" class="text-center text-muted py-3">No tiene estudiantes vinculados.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section class="card info-card">
        <div class="card-body p-4">
            <h2 class="section-header"><i class="bi bi-cash-stack text-primary"></i> Pagos Realizados</h2>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="min-width: 115px;">Fecha</th>
                            <th style="min-width: 220px;">Estudiante</th>
                            <th style="min-width: 170px;">Concepto</th>
                            <th style="min-width: 120px;">Monto</th>
                            <th style="min-width: 120px;">Estado</th>
                            <th style="min-width: 110px;">Recibo</th>
                            <th style="min-width: 200px;">Observación</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pagos as $pago): ?>
                            <tr>
                                <td class="font-monospace text-muted"><?= $h($pago['fecha_pago']) ?></td>
                                <td>
                                    <?php if ($pago['estudiante_id']): ?>
                                        <a href="ver_estudiante.php?id=<?= (int) $pago['estudiante_id'] ?>" class="text-decoration-none fw-semibold text-dark hover-primary">
                                            <?= $h($pago['estudiante_apellido_paterno'] . ', ' . $pago['estudiante_nombres']) ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="fw-semibold text-dark"><?= $h($pago['concepto']) ?></td>
                                <td class="fw-bold text-success font-monospace" style="white-space: nowrap;">S/ <?= $h(number_format((float) $pago['monto'], 2)) ?></td>
                                <td>
                                    <span class="badge <?= $pago['estado'] === 'Pagado' ? 'bg-success-subtle text-success border border-success-subtle' : 'bg-warning-subtle text-warning-emphasis border border-warning-subtle' ?>">
                                        <?= $h($pago['estado']) ?>
                                    </span>
                                </td>
                                <td><span class="badge bg-light text-dark border font-monospace"><?= $h($pago['nro_recibo']) ?></span></td>
                                <td class="text-muted small"><?= $h($pago['observacion']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$pagos): ?>
                            <tr><td colspan="7" class="text-center text-muted py-3">No hay pagos registrados para este responsable.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
