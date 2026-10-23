<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/header.php';

require_auth(['asistente', 'director']);
$id = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false) {
    http_response_code(400);
    exit('ID de estudiante inválido.');
}

$stmt = $pdo->prepare('SELECT * FROM estudiante WHERE id = :id');
$stmt->execute(['id' => $id]);
$estudiante = $stmt->fetch();
if (!$estudiante) {
    http_response_code(404);
    exit('Estudiante no encontrado.');
}

$familyStmt = $pdo->prepare(
    'SELECT ar.tipo, ar.parentesco, ar.es_apoderado, r.id, r.dni, r.nombres,
            r.apellido_paterno, r.apellido_materno, r.celular, r.correo, r.direccion
     FROM estudiante_responsable ar
     JOIN responsable r ON r.id = ar.responsable_id
     WHERE ar.estudiante_id = :id
     ORDER BY CASE ar.tipo WHEN \'padre\' THEN 1 WHEN \'madre\' THEN 2 ELSE 3 END, r.apellido_paterno'
);
$familyStmt->execute(['id' => $id]);
$responsables = $familyStmt->fetchAll();

$enrollmentStmt = $pdo->prepare(
    'SELECT m.id, m.estado, a.anio, n.nombre AS nivel, g.nombre AS grado, s.nombre AS seccion
     FROM matricula m
     LEFT JOIN anio a ON a.id = m.anio_id
     LEFT JOIN nivel n ON n.id = m.nivel_id
     LEFT JOIN grado_seccion gs ON gs.id = m.grado_seccion_id
     LEFT JOIN grado g ON g.id = gs.grado_id
     LEFT JOIN seccion s ON s.id = gs.seccion_id
     WHERE m.estudiante_id = :id
     ORDER BY a.anio DESC NULLS LAST, m.id DESC'
);
$enrollmentStmt->execute(['id' => $id]);
$matriculas = $enrollmentStmt->fetchAll();

$siblingsStmt = $pdo->prepare(
    'SELECT DISTINCT sibling.id, sibling.nombres, sibling.apellido_paterno, sibling.apellido_materno,
            latest.nivel, latest.grado
     FROM estudiante_responsable family
     JOIN estudiante_responsable sibling_family
       ON sibling_family.responsable_id = family.responsable_id
      AND sibling_family.estudiante_id <> family.estudiante_id
     JOIN estudiante sibling ON sibling.id = sibling_family.estudiante_id
     LEFT JOIN LATERAL (
         SELECT n.nombre AS nivel, g.nombre AS grado
         FROM matricula m
         LEFT JOIN anio a ON a.id = m.anio_id
         LEFT JOIN nivel n ON n.id = m.nivel_id
         LEFT JOIN grado_seccion gs ON gs.id = m.grado_seccion_id
         LEFT JOIN grado g ON g.id = gs.grado_id
         WHERE m.estudiante_id = sibling.id
         ORDER BY a.anio DESC NULLS LAST, m.id DESC
         LIMIT 1
     ) latest ON TRUE
     WHERE family.estudiante_id = :id
     ORDER BY sibling.apellido_paterno, sibling.apellido_materno, sibling.nombres'
);
$siblingsStmt->execute(['id' => $id]);
$hermanos = $siblingsStmt->fetchAll();

$paymentStmt = $pdo->prepare(
    "SELECT p.id, p.fecha_pago, p.monto, p.nro_recibo, p.observacion,
            COALESCE(NULLIF(p.concepto, ''), cp.descripcion, '-') AS concepto,
            COALESCE(NULLIF(p.estado, ''), p.estado_pago, 'Pagado') AS estado
     FROM pago p
     JOIN matricula m ON m.id = p.matricula_id
     LEFT JOIN concepto_pago cp ON cp.id = p.concepto_id
     WHERE m.estudiante_id = :id
     ORDER BY p.fecha_pago DESC, p.id DESC"
);
$paymentStmt->execute(['id' => $id]);
$pagos = $paymentStmt->fetchAll();
$h = static fn ($value): string => htmlspecialchars((string) ($value ?? '-'), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Detalle del estudiante - Sistema Escolar</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php render_app_header(panel_home_path(), 'logout.php'); ?>
<main class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4"><h1 class="h3 mb-0">Detalle del estudiante</h1><a href="estudiantes.php" class="btn btn-outline-secondary">Volver</a></div>
    <section class="card border-0 shadow-sm mb-4"><div class="card-body"><h2 class="h5">Información personal</h2><dl class="row mb-0">
        <dt class="col-sm-3">Nombres</dt><dd class="col-sm-9"><?= $h($estudiante['nombres']) ?></dd>
        <dt class="col-sm-3">Apellidos</dt><dd class="col-sm-9"><?= $h($estudiante['apellido_paterno'] . ' ' . $estudiante['apellido_materno']) ?></dd>
        <dt class="col-sm-3">DNI</dt><dd class="col-sm-9"><?= $h($estudiante['dni']) ?></dd>
        <dt class="col-sm-3">Fecha de nacimiento</dt><dd class="col-sm-9"><?= $h($estudiante['fecha_nacimiento']) ?></dd>
    </dl></div></section>
    <section class="card border-0 shadow-sm mb-4"><div class="card-body"><h2 class="h5">Hermanos <span class="badge text-bg-primary"><?= count($hermanos) ?></span></h2>
        <?php if ($hermanos): ?><ul class="list-group list-group-flush">
            <?php foreach ($hermanos as $hermano): ?><li class="list-group-item px-0"><a href="ver_estudiante.php?id=<?= (int) $hermano['id'] ?>"><?= $h($hermano['apellido_paterno'] . ' ' . ($hermano['apellido_materno'] ?? '') . ', ' . $hermano['nombres']) ?></a> <span class="badge text-bg-light"><?= $h($hermano['nivel'] && $hermano['grado'] ? $hermano['nivel'] . ' / ' . $hermano['grado'] : 'Sin matrícula') ?></span></li><?php endforeach; ?>
        </ul><?php else: ?><p class="text-muted mb-0">No hay hermanos vinculados por responsables compartidos.</p><?php endif; ?>
    </div></section>
    <section class="card border-0 shadow-sm mb-4"><div class="card-body"><h2 class="h5">Padres y apoderados</h2><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th>Tipo</th><th>Responsable</th><th>Parentesco</th><th>Apoderado</th><th>Contacto</th></tr></thead><tbody>
        <?php foreach ($responsables as $responsable): ?><tr><td><?= $h(ucfirst($responsable['tipo'])) ?></td><td><a href="ver_padre.php?id=<?= (int) $responsable['id'] ?>"><?= $h($responsable['apellido_paterno'] . ' ' . ($responsable['apellido_materno'] ?? '') . ', ' . $responsable['nombres']) ?></a><br><small><?= $h($responsable['dni']) ?></small></td><td><?= $h($responsable['parentesco']) ?></td><td><?= $responsable['es_apoderado'] ? 'Sí' : 'No' ?></td><td><?= $h($responsable['celular'] ?: $responsable['correo']) ?></td></tr><?php endforeach; ?>
        <?php if (!$responsables): ?><tr><td colspan="5" class="text-center text-muted">No hay responsables vinculados.</td></tr><?php endif; ?>
    </tbody></table></div></div></section>
    <section class="card border-0 shadow-sm mb-4"><div class="card-body"><h2 class="h5">Historial de matrículas</h2><div class="table-responsive"><table class="table table-hover mb-0"><thead><tr><th>Año</th><th>Nivel</th><th>Grado</th><th>Sección</th><th>Estado</th></tr></thead><tbody>
        <?php foreach ($matriculas as $matricula): ?><tr><td><?= $h($matricula['anio']) ?></td><td><?= $h($matricula['nivel']) ?></td><td><?= $h($matricula['grado']) ?></td><td><?= $h($matricula['seccion']) ?></td><td><?= $h($matricula['estado']) ?></td></tr><?php endforeach; ?>
        <?php if (!$matriculas): ?><tr><td colspan="5" class="text-center text-muted">No hay matrículas registradas.</td></tr><?php endif; ?>
    </tbody></table></div></div></section>
    <section class="card border-0 shadow-sm"><div class="card-body"><h2 class="h5">Historial de pagos</h2><div class="table-responsive"><table class="table table-hover mb-0"><thead><tr><th>Fecha</th><th>Concepto</th><th>Monto</th><th>Estado</th><th>Recibo</th><th>Observación</th></tr></thead><tbody>
        <?php foreach ($pagos as $pago): ?><tr><td><?= $h($pago['fecha_pago']) ?></td><td><?= $h($pago['concepto']) ?></td><td>S/ <?= $h(number_format((float) $pago['monto'], 2)) ?></td><td><?= $h($pago['estado']) ?></td><td><?= $h($pago['nro_recibo']) ?></td><td><?= $h($pago['observacion']) ?></td></tr><?php endforeach; ?>
        <?php if (!$pagos): ?><tr><td colspan="6" class="text-center text-muted">No hay pagos vinculados a sus matrículas.</td></tr><?php endif; ?>
    </tbody></table></div></div></section>
</main>
</body>
</html>
