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

$gradesStmt = $pdo->prepare(
    "SELECT c.id AS curso_id, c.nombre AS curso_nombre, c.grado AS curso_grado,
            pa.id AS periodo_id, pa.nombre AS periodo_nombre,
            cal.nota, cal.actualizado_en,
            m.anio_id, a.anio
     FROM calificacion cal
     JOIN matricula m ON m.id = cal.matricula_id
     JOIN curso c ON c.id = cal.curso_id
     JOIN periodo_academico pa ON pa.id = cal.periodo_id
     LEFT JOIN anio a ON a.id = m.anio_id
     WHERE m.estudiante_id = :id
     ORDER BY a.anio DESC NULLS LAST, pa.fecha_inicio ASC NULLS LAST, c.nombre ASC"
);
$gradesStmt->execute(['id' => $id]);
$calificaciones = $gradesStmt->fetchAll();

$totalNotas = count($calificaciones);
$countAD = 0;
$countA = 0;
$countB = 0;
$countC = 0;
foreach ($calificaciones as $cal) {
    $n = strtoupper(trim((string)$cal['nota']));
    if ($n === 'AD') $countAD++;
    elseif ($n === 'A') $countA++;
    elseif ($n === 'B') $countB++;
    elseif ($n === 'C') $countC++;
}
$cursosAprobados = $countAD + $countA + $countB;
$cursosDesaprobados = $countC;

// Nivel de Logro Global (Escala Literal C, B, A, AD)
$logroGlobal = 'Sin notas';
$badgeLogroGlobal = 'bg-secondary';
if ($totalNotas > 0) {
    if ($countC > 0 && ($countC / $totalNotas) > 0.3) {
        $logroGlobal = 'C · En Inicio';
        $badgeLogroGlobal = 'bg-danger text-white';
    } elseif ($countAD >= $countA && $countAD >= $countB) {
        $logroGlobal = 'AD · Destacado';
        $badgeLogroGlobal = 'bg-primary text-white';
    } elseif ($countA >= $countB) {
        $logroGlobal = 'A · Esperado';
        $badgeLogroGlobal = 'bg-success text-white';
    } else {
        $logroGlobal = 'B · En Proceso';
        $badgeLogroGlobal = 'bg-warning text-dark';
    }
}

$h = static fn ($value): string => htmlspecialchars((string) ($value ?? '-'), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Detalle del estudiante - Sistema Escolar</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../assets/css/app.css">
    <link rel="stylesheet" href="../assets/css/ver_estudiante.css">
</head>
<body class="bg-light">
<?php render_app_header(panel_home_path(), 'logout.php'); ?>
<main class="container py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <h1 class="h3 fw-bold mb-1 text-dark d-flex align-items-center gap-2">
                <i class="bi bi-person-badge text-primary"></i> Detalle del Estudiante
            </h1>
            <p class="text-muted mb-0 small">Expediente académico, datos familiares y movimientos de pago.</p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <?php if (!empty($matriculas)): ?>
            <a href="ficha_matricula.php?estudiante_id=<?= (int)$estudiante['id'] ?>" target="_blank" rel="opener" class="btn btn-outline-primary d-inline-flex align-items-center gap-1 px-3 py-2 fw-semibold" style="border-radius: 10px; font-size: 0.96rem;">
                <i class="bi bi-printer"></i> Imprimir Ficha
            </a>
            <?php endif; ?>
            <a href="editar_estudiante.php?id=<?= (int)$estudiante['id'] ?>" class="btn btn-primary d-inline-flex align-items-center gap-1 px-3 py-2 fw-semibold" style="border-radius: 10px; font-size: 0.96rem;">
                <i class="bi bi-pencil-square"></i> Editar Datos
            </a>
            <a href="estudiantes.php" class="btn btn-outline-secondary d-inline-flex align-items-center gap-1 px-3 py-2" style="border-radius: 10px; font-size: 0.96rem;">
                <i class="bi bi-arrow-left"></i> Volver a Estudiantes
            </a>
        </div>
    </div>

    <section class="card info-card mb-4">
        <div class="card-body p-4">
            <h2 class="section-header"><i class="bi bi-person-vcard text-primary"></i> Información Personal</h2>
            <dl class="row mb-0">
                <dt class="col-sm-3">Nombres</dt>
                <dd class="col-sm-9 fw-semibold text-primary"><?= $h($estudiante['nombres']) ?></dd>
                <dt class="col-sm-3">Apellidos</dt>
                <dd class="col-sm-9 fw-semibold"><?= $h($estudiante['apellido_paterno'] . ' ' . $estudiante['apellido_materno']) ?></dd>
                <dt class="col-sm-3">DNI</dt>
                <dd class="col-sm-9"><span class="badge bg-light text-dark border fs-6 font-monospace"><?= $h($estudiante['dni']) ?></span></dd>
                <dt class="col-sm-3">Fecha de Nacimiento</dt>
                <dd class="col-sm-9"><?= $h($estudiante['fecha_nacimiento']) ?></dd>
                <dt class="col-sm-3">Estado Académico</dt>
                <dd class="col-sm-9">
                    <?php
                    $badgeClass = match ($estudiante['estado_academico']) {
                        'Activo' => 'bg-success-subtle text-success border border-success-subtle',
                        'Promovido' => 'text-white border',
                        'Repitente' => 'text-white border',
                        'Promoción' => 'bg-primary-subtle text-primary border border-primary-subtle',
                        'Recuperación' => 'text-dark border',
                        'Retirado' => 'bg-danger-subtle text-danger border border-danger-subtle',
                        default => 'bg-warning-subtle text-warning-emphasis border border-warning-subtle',
                    };
                    $customStyle = match ($estudiante['estado_academico']) {
                        'Promovido' => 'style="background-color: #059669; border-color: #047857;"',
                        'Repitente' => 'style="background-color: #dc2626; border-color: #b91c1c;"',
                        'Recuperación' => 'style="background-color: #fef08a; border-color: #fde047;"',
                        default => '',
                    };
                    $badgeIcon = match ($estudiante['estado_academico']) {
                        'Activo' => 'bi-check-circle-fill',
                        'Promovido' => 'bi-check2-circle',
                        'Repitente' => 'bi-arrow-repeat',
                        'Promoción' => 'bi-mortarboard-fill',
                        'Recuperación' => 'bi-clock-history',
                        'Retirado' => 'bi-x-circle-fill',
                        default => 'bi-arrow-left-right',
                    };
                    ?>
                    <span class="badge <?= $badgeClass ?> fs-6" <?= $customStyle ?>>
                        <i class="bi <?= $badgeIcon ?> me-1"></i><?= $h($estudiante['estado_academico']) ?>
                    </span>
                </dd>
            </dl>
        </div>
    </section>

    <section class="card info-card mb-4">
        <div class="card-body p-4">
            <h2 class="section-header">
                <i class="bi bi-people text-primary"></i> Hermanos
                <span class="badge bg-primary-subtle text-primary border border-primary-subtle fs-6 ms-2"><?= count($hermanos) ?></span>
            </h2>
            <?php if ($hermanos): ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($hermanos as $hermano): ?>
                        <li class="list-group-item px-0 py-2 d-flex justify-content-between align-items-center">
                            <a href="ver_estudiante.php?id=<?= (int) $hermano['id'] ?>" class="text-decoration-none fw-semibold text-dark hover-primary" style="font-size: 1.02rem;">
                                <?= $h($hermano['apellido_paterno'] . ' ' . ($hermano['apellido_materno'] ?? '') . ', ' . $hermano['nombres']) ?>
                            </a>
                            <span class="badge bg-light text-secondary border fs-6"><?= $h($hermano['nivel'] && $hermano['grado'] ? $hermano['nivel'] . ' / ' . $hermano['grado'] : 'Sin matrícula') ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="text-muted mb-0">No hay hermanos vinculados por responsables compartidos.</p>
            <?php endif; ?>
        </div>
    </section>

    <section class="card info-card mb-4">
        <div class="card-body p-4">
            <h2 class="section-header"><i class="bi bi-person-lines-fill text-primary"></i> Padres y Apoderados</h2>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="min-width: 100px;">Tipo</th>
                            <th style="min-width: 240px;">Responsable</th>
                            <th style="min-width: 120px;">Parentesco</th>
                            <th style="min-width: 110px;">Apoderado</th>
                            <th style="min-width: 150px;">Contacto</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($responsables as $responsable): ?>
                            <tr>
                                <td><span class="badge bg-light text-secondary border"><?= $h(ucfirst($responsable['tipo'])) ?></span></td>
                                <td>
                                    <a href="ver_padre.php?id=<?= (int) $responsable['id'] ?>" class="text-decoration-none fw-semibold text-dark hover-primary" style="font-size: 1.02rem;">
                                        <?= $h($responsable['apellido_paterno'] . ' ' . ($responsable['apellido_materno'] ?? '') . ', ' . $responsable['nombres']) ?>
                                    </a>
                                    <div class="text-muted small mt-1 font-monospace">DNI: <?= $h($responsable['dni']) ?></div>
                                </td>
                                <td><?= $h($responsable['parentesco']) ?></td>
                                <td>
                                    <?php if ($responsable['es_apoderado']): ?>
                                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle">Sí (Principal)</span>
                                    <?php else: ?>
                                        <span class="badge bg-light text-muted border">No</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= $h($responsable['celular'] ?: $responsable['correo']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$responsables): ?>
                            <tr><td colspan="5" class="text-center text-muted py-3">No hay responsables vinculados.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section class="card info-card mb-4">
        <div class="card-body p-4">
            <h2 class="section-header"><i class="bi bi-mortarboard text-primary"></i> Historial de Matrículas</h2>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="min-width: 90px;">Año</th>
                            <th style="min-width: 140px;">Nivel</th>
                            <th style="min-width: 110px;">Grado</th>
                            <th style="min-width: 100px;">Sección</th>
                            <th style="min-width: 120px;">Estado</th>
                            <th style="min-width: 110px;" class="text-end">Ficha</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($matriculas as $matricula): ?>
                            <tr>
                                <td><span class="badge bg-light text-dark border font-monospace fs-6"><?= $h($matricula['anio']) ?></span></td>
                                <td><?= $h($matricula['nivel']) ?></td>
                                <td><?= $h($matricula['grado']) ?></td>
                                <td><span class="badge bg-light text-secondary border fs-6"><?= $h($matricula['seccion']) ?></span></td>
                                <td>
                                    <span class="badge <?= $matricula['estado'] === 'Matriculado' ? 'bg-success-subtle text-success border border-success-subtle' : 'bg-light text-secondary border' ?>">
                                        <?= $h($matricula['estado']) ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <a href="ficha_matricula.php?id=<?= (int)$matricula['id'] ?>" target="_blank" rel="opener" class="btn btn-sm btn-outline-primary d-inline-flex align-items-center gap-1" style="border-radius: 7px; font-size: 0.82rem;">
                                        <i class="bi bi-printer"></i> Imprimir
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$matriculas): ?>
                            <tr><td colspan="6" class="text-center text-muted py-3">No hay matrículas registradas.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section class="card info-card mb-4">
        <div class="card-body p-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <h2 class="section-header mb-0">
                    <i class="bi bi-journal-bookmark-fill text-primary"></i> Rendimiento y Calificaciones Académicas
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle fs-6 ms-2"><?= $totalNotas ?> evaluaciones</span>
                </h2>
                <a href="calificaciones.php" class="btn btn-sm btn-outline-primary d-inline-flex align-items-center gap-1 px-3 py-1.5 fw-semibold" style="border-radius: 8px;">
                    <i class="bi bi-pencil-square"></i> Cargar / Modificar Notas
                </a>
            </div>

            <?php if ($totalNotas > 0): ?>
                <div class="row g-3 mb-4">
                    <div class="col-sm-6 col-lg-3">
                        <div class="p-3 rounded-3 bg-light border text-center">
                            <span class="text-muted small text-uppercase fw-semibold d-block">Nivel de Logro Global</span>
                            <span class="badge <?= $badgeLogroGlobal ?> fs-5 mt-1 px-3 py-1.5"><?= $logroGlobal ?></span>
                            <span class="text-muted d-block small mt-1">Escala C, B, A, AD</span>
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <div class="p-3 rounded-3 bg-light border text-center">
                            <span class="text-muted small text-uppercase fw-semibold d-block">Logros Aprobados</span>
                            <span class="fs-3 fw-bold text-success"><?= $cursosAprobados ?></span>
                            <span class="text-muted d-block small">AD (<?= $countAD ?>) · A (<?= $countA ?>) · B (<?= $countB ?>)</span>
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <div class="p-3 rounded-3 bg-light border text-center">
                            <span class="text-muted small text-uppercase fw-semibold d-block">En Inicio (Desaprobados)</span>
                            <span class="fs-3 fw-bold text-danger"><?= $cursosDesaprobados ?></span>
                            <span class="text-muted d-block small">C (Requiere recuperación)</span>
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <div class="p-3 rounded-3 bg-light border text-center">
                            <span class="text-muted small text-uppercase fw-semibold d-block">Situación Académica</span>
                            <?php if ($cursosDesaprobados === 0): ?>
                                <span class="badge text-white px-3 py-2 fs-6 mt-1" style="background-color: #059669;">
                                    <i class="bi bi-check2-circle me-1"></i>Promovido
                                </span>
                            <?php else: ?>
                                <span class="badge text-white px-3 py-2 fs-6 mt-1" style="background-color: #dc2626;">
                                    <i class="bi bi-arrow-repeat me-1"></i>Repitente
                                </span>
                            <?php endif; ?>
                            <span class="text-muted d-block small mt-1">Calculada por notas</span>
                        </div>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th style="min-width: 90px;">Año</th>
                                <th style="min-width: 160px;">Periodo</th>
                                <th style="min-width: 220px;">Curso / Asignatura</th>
                                <th style="min-width: 140px;">Calificación Literal</th>
                                <th style="min-width: 220px;">Nivel de Logro / Condición</th>
                                <th style="min-width: 150px;">Última Modificación</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($calificaciones as $cal): ?>
                                <?php $nota = strtoupper(trim((string)$cal['nota'])); ?>
                                <tr>
                                    <td><span class="badge bg-light text-dark border font-monospace fs-6"><?= $h($cal['anio']) ?></span></td>
                                    <td><span class="fw-semibold text-secondary"><?= $h($cal['periodo_nombre']) ?></span></td>
                                    <td>
                                        <span class="fw-bold text-dark"><?= $h($cal['curso_nombre']) ?></span>
                                        <?php if (!empty($cal['curso_grado'])): ?>
                                            <small class="text-muted d-block"><?= $h($cal['curso_grado']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($nota === 'AD'): ?>
                                            <span class="badge px-3 py-1.5 fs-6 fw-bold text-white shadow-sm" style="background-color: #2563eb;">AD</span>
                                        <?php elseif ($nota === 'A'): ?>
                                            <span class="badge px-3 py-1.5 fs-6 fw-bold text-white shadow-sm" style="background-color: #16a34a;">A</span>
                                        <?php elseif ($nota === 'B'): ?>
                                            <span class="badge px-3 py-1.5 fs-6 fw-bold text-dark shadow-sm" style="background-color: #fef08a; border: 1px solid #facc15;">B</span>
                                        <?php elseif ($nota === 'C'): ?>
                                            <span class="badge px-3 py-1.5 fs-6 fw-bold text-white shadow-sm" style="background-color: #dc2626;">C</span>
                                        <?php else: ?>
                                            <span class="badge bg-light text-muted border px-2 py-1"><?= $h($nota) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($nota === 'AD'): ?>
                                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1 fs-6">
                                                <i class="bi bi-star-fill me-1 text-primary"></i>Logro Destacado (Aprobado)
                                            </span>
                                        <?php elseif ($nota === 'A'): ?>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1 fs-6">
                                                <i class="bi bi-check-circle-fill me-1 text-success"></i>Logro Esperado (Aprobado)
                                            </span>
                                        <?php elseif ($nota === 'B'): ?>
                                            <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-2 py-1 fs-6">
                                                <i class="bi bi-clock-history me-1 text-warning-emphasis"></i>En Proceso (Regular)
                                            </span>
                                        <?php elseif ($nota === 'C'): ?>
                                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1 fs-6">
                                                <i class="bi bi-arrow-repeat me-1 text-danger"></i>En Inicio (Desaprobado)
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-light text-muted border px-2 py-1">Sin clasificar</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="font-monospace text-muted small"><?= $h($cal['actualizado_en']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="text-center py-4 bg-light rounded-3 border">
                    <i class="bi bi-journal-x text-muted" style="font-size: 2.2rem;"></i>
                    <h5 class="mt-2 text-secondary fw-semibold">Sin calificaciones registradas</h5>
                    <p class="text-muted small mb-3">Este estudiante aún no cuenta con notas ingresadas en sus periodos académicos.</p>
                    <a href="calificaciones.php" class="btn btn-primary btn-sm px-3 py-2 fw-semibold" style="border-radius: 8px;">
                        <i class="bi bi-plus-circle me-1"></i> Subir Notas en Calificaciones
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="card info-card">
        <div class="card-body p-4">
            <h2 class="section-header"><i class="bi bi-cash-stack text-primary"></i> Historial de Pagos</h2>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="min-width: 115px;">Fecha</th>
                            <th style="min-width: 180px;">Concepto</th>
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
                            <tr><td colspan="6" class="text-center text-muted py-3">No hay pagos vinculados a sus matrículas.</td></tr>
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
