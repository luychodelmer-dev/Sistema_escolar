<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/header.php';

require_auth(['asistente', 'director', 'hiperadmin']);

// Opciones de filtros
$anios = $pdo->query('SELECT id, anio, activo FROM anio ORDER BY anio DESC')->fetchAll();
$niveles = $pdo->query('SELECT id, nombre FROM nivel ORDER BY id ASC')->fetchAll();
$grados = $pdo->query('SELECT g.id, g.nivel_id, g.numero, g.nombre, n.nombre AS nivel FROM grado g JOIN nivel n ON n.id = g.nivel_id ORDER BY g.nivel_id, g.numero')->fetchAll();
$secciones = $pdo->query('SELECT id, nombre FROM seccion ORDER BY nombre ASC')->fetchAll();

// Año predeterminado: el activo o el primero disponible o el año actual
$defaultAnio = date('Y');
foreach ($anios as $a) {
    if (!empty($a['activo'])) {
        $defaultAnio = (int) $a['anio'];
        break;
    }
}

$filtroAnio = filter_var($_GET['anio'] ?? $defaultAnio, FILTER_VALIDATE_INT) ?: (int)$defaultAnio;
$filtroNivel = filter_var($_GET['nivel_id'] ?? null, FILTER_VALIDATE_INT);
$filtroGrado = filter_var($_GET['grado_id'] ?? null, FILTER_VALIDATE_INT);
$filtroSeccion = filter_var($_GET['seccion_id'] ?? null, FILTER_VALIDATE_INT);
$filtroEstado = trim($_GET['estado'] ?? 'Activa');

// Construcción de la consulta de nómina
$sql = "
    SELECT m.id AS matricula_id, m.es_estudiante_nuevo, m.estado AS estado_matricula, m.creado_en AS fecha_matricula,
           e.id AS estudiante_id, e.dni, e.nombres, e.apellido_paterno, e.apellido_materno,
           e.fecha_nacimiento, e.estado_academico,
           a.anio,
           n.nombre AS nivel_nombre,
           g.nombre AS grado_nombre, g.numero AS grado_numero,
           s.nombre AS seccion_nombre,
           COALESCE(resp.nombres || ' ' || resp.apellido_paterno, 'No asignado') AS apoderado_nombre,
           COALESCE(resp.celular, '-') AS apoderado_celular
    FROM matricula m
    JOIN estudiante e ON e.id = m.estudiante_id
    JOIN anio a ON a.id = m.anio_id
    JOIN nivel n ON n.id = m.nivel_id
    JOIN grado_seccion gs ON gs.id = m.grado_seccion_id
    JOIN grado g ON g.id = gs.grado_id
    JOIN seccion s ON s.id = gs.seccion_id
    LEFT JOIN responsable resp ON resp.id = m.apoderado_principal_id
    WHERE a.anio = :anio
";
$params = ['anio' => $filtroAnio];

if ($filtroNivel) {
    $sql .= " AND m.nivel_id = :nivel_id";
    $params['nivel_id'] = $filtroNivel;
}
if ($filtroGrado) {
    $sql .= " AND gs.grado_id = :grado_id";
    $params['grado_id'] = $filtroGrado;
}
if ($filtroSeccion) {
    $sql .= " AND gs.seccion_id = :seccion_id";
    $params['seccion_id'] = $filtroSeccion;
}
if ($filtroEstado !== '' && $filtroEstado !== 'todos') {
    $sql .= " AND m.estado = :estado";
    $params['estado'] = $filtroEstado;
}

$sql .= " ORDER BY n.id ASC, g.numero ASC, s.nombre ASC, e.apellido_paterno ASC, e.apellido_materno ASC, e.nombres ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$matriculas = $stmt->fetchAll();

// Textos descriptivos para el encabezado del reporte
$nombreNivel = 'TODOS LOS NIVELES';
if ($filtroNivel) {
    foreach ($niveles as $n) {
        if ((int)$n['id'] === $filtroNivel) {
            $nombreNivel = mb_strtoupper($n['nombre'], 'UTF-8');
            break;
        }
    }
}

$nombreGrado = 'TODOS LOS GRADOS';
if ($filtroGrado) {
    foreach ($grados as $g) {
        if ((int)$g['id'] === $filtroGrado) {
            $nombreGrado = mb_strtoupper($g['nombre'], 'UTF-8');
            break;
        }
    }
}

$nombreSeccion = 'TODAS';
if ($filtroSeccion) {
    foreach ($secciones as $s) {
        if ((int)$s['id'] === $filtroSeccion) {
            $nombreSeccion = '&quot;' . htmlspecialchars($s['nombre']) . '&quot;';
            break;
        }
    }
}

$totalEstudiantes = count($matriculas);
$totalNuevos = 0;
$totalContinuos = 0;
foreach ($matriculas as $m) {
    if (!empty($m['es_estudiante_nuevo'])) {
        $totalNuevos++;
    } else {
        $totalContinuos++;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Nómina Oficial de Matrícula - I.E. Divino Maestro</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../assets/css/app.css">
    <link rel="stylesheet" href="../assets/css/reports-print.css">
    <link rel="stylesheet" href="../assets/css/nomina_matriculas.css">
</head>
<body class="bg-light">

<?php render_app_header(panel_home_path(), 'logout.php'); ?>

<main class="container py-4">

    <!-- BARRA SUPERIOR Y TÍTULO (SOLO PANTALLA) -->
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4 no-print">
        <div>
            <h1 class="h3 fw-bold mb-1 text-dark d-flex align-items-center gap-2">
                <i class="bi bi-file-earmark-ruled text-primary"></i> Nómina Oficial de Matrícula
            </h1>
            <p class="text-muted mb-0 small">Filtra por año escolar, nivel, grado y sección para emitir el reporte oficial imprimible o en PDF.</p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <button type="button" class="btn btn-primary d-inline-flex align-items-center gap-2 px-3 py-2 fw-semibold shadow-sm" onclick="window.print();" style="border-radius: 10px;">
                <i class="bi bi-printer-fill"></i> Imprimir Nómina / Guardar PDF
            </button>
            <a href="estudiantes.php" class="btn btn-outline-secondary d-inline-flex align-items-center gap-1 px-3 py-2" style="border-radius: 10px;">
                <i class="bi bi-arrow-left"></i> Volver a Estudiantes
            </a>
        </div>
    </div>

    <!-- TARJETA DE FILTROS (SOLO PANTALLA) -->
    <section class="filter-card no-print">
        <form method="GET" class="row g-3 align-items-end" id="filter-form">
            <div class="col-md-2">
                <label class="form-label small fw-bold text-secondary" for="anio">Año Lectivo</label>
                <select class="form-select" id="anio" name="anio">
                    <?php foreach ($anios as $a): ?>
                        <option value="<?= (int)$a['anio'] ?>" <?= (int)$a['anio'] === $filtroAnio ? 'selected' : '' ?>>
                            <?= (int)$a['anio'] ?><?= !empty($a['activo']) ? ' (Activo)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-3">
                <label class="form-label small fw-bold text-secondary" for="nivel_id">Nivel Educativo</label>
                <select class="form-select" id="nivel_id" name="nivel_id">
                    <option value="">-- Todos los niveles --</option>
                    <?php foreach ($niveles as $n): ?>
                        <option value="<?= (int)$n['id'] ?>" <?= $filtroNivel === (int)$n['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($n['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-3">
                <label class="form-label small fw-bold text-secondary" for="grado_id">Grado</label>
                <select class="form-select" id="grado_id" name="grado_id">
                    <option value="">-- Todos los grados --</option>
                    <?php foreach ($grados as $g): ?>
                        <option value="<?= (int)$g['id'] ?>" data-nivel-id="<?= (int)$g['nivel_id'] ?>" <?= $filtroGrado === (int)$g['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($g['nivel']) ?> - <?= htmlspecialchars($g['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label small fw-bold text-secondary" for="seccion_id">Sección</label>
                <select class="form-select" id="seccion_id" name="seccion_id">
                    <option value="">-- Todas --</option>
                    <?php foreach ($secciones as $s): ?>
                        <option value="<?= (int)$s['id'] ?>" <?= $filtroSeccion === (int)$s['id'] ? 'selected' : '' ?>>
                            Sección &quot;<?= htmlspecialchars($s['nombre']) ?>&quot;
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary w-100 fw-semibold d-inline-flex align-items-center justify-content-center gap-1">
                    <i class="bi bi-funnel-fill"></i> Filtrar
                </button>
                <a href="nomina_matriculas.php" class="btn btn-light border text-secondary" title="Limpiar filtros">
                    <i class="bi bi-x-circle"></i>
                </a>
            </div>
        </form>
    </section>

    <!-- DOCUMENTO OFICIAL DE NÓMINA (IMPRIMIBLE / PDF) -->
    <article class="nomina-document">

        <!-- 1. Encabezado Institucional -->
        <header class="doc-header">
            <img class="doc-logo" src="../assets/images/logo-institucion.jpg.jpg" alt="Logo Institucional">
            <div class="doc-title-block">
                <div class="doc-sup-title">República del Perú &bull; Ministerio de Educación &bull; DRE Cajamarca &bull; UGEL Cajamarca</div>
                <h2 class="doc-main-title">Institución Educativa &quot;Divino Maestro&quot;</h2>
                <div class="doc-sub-title">Mollepampa &bull; Cajamarca</div>
            </div>
            <div style="width: 75px; text-align: right;" class="d-none d-sm-block">
                <span class="badge bg-light text-secondary border p-2 font-monospace" style="font-size: 0.72rem;">
                    Año <?= htmlspecialchars((string)$filtroAnio) ?>
                </span>
            </div>
        </header>

        <!-- 2. Banner de Nómina -->
        <div class="doc-banner">
            Nómina Oficial de Estudiantes Matriculados &bull; Año Lectivo <?= htmlspecialchars((string)$filtroAnio) ?>
        </div>

        <!-- 3. Parámetros y Metadatos -->
        <div class="meta-grid">
            <div class="meta-item">
                <strong>Nivel:</strong>
                <span><?= $nombreNivel ?></span>
            </div>
            <div class="meta-item">
                <strong>Grado:</strong>
                <span><?= $nombreGrado ?></span>
            </div>
            <div class="meta-item">
                <strong>Sección:</strong>
                <span><?= $nombreSeccion ?></span>
            </div>
            <div class="meta-item">
                <strong>Total Matriculados:</strong>
                <span style="color: #1e3a8a;"><?= $totalEstudiantes ?> alumnos</span>
            </div>
            <div class="meta-item">
                <strong>Fecha de Emisión:</strong>
                <span><?= date('d/m/Y') ?></span>
            </div>
        </div>

        <!-- 4. Tabla de Estudiantes -->
        <div class="table-responsive">
            <table class="nomina-table">
                <thead>
                    <tr>
                        <th style="width: 40px;" class="text-center">N°</th>
                        <th style="width: 95px;">DNI</th>
                        <th>Apellidos y Nombres</th>
                        <th style="width: 110px;">Nivel / Grado / Sec.</th>
                        <th style="width: 95px;">Condición</th>
                        <th>Apoderado Principal</th>
                        <th style="width: 100px;">Contacto</th>
                        <th style="width: 100px;" class="text-center">Firma / Obs.</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$matriculas): ?>
                        <tr>
                            <td colspan="8" class="text-center py-4 text-muted">
                                <i class="bi bi-info-circle fs-4 d-block mb-1"></i>
                                No se encontraron estudiantes matriculados con los filtros seleccionados.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php $correlativo = 1; ?>
                        <?php foreach ($matriculas as $row): ?>
                            <?php
                            $nombreCompleto = trim($row['apellido_paterno'] . ' ' . $row['apellido_materno'] . ', ' . $row['nombres']);
                            $condicionTexto = !empty($row['es_estudiante_nuevo']) ? 'Nuevo' : 'Continuo';
                            ?>
                            <tr>
                                <td class="num-col"><?= $correlativo++ ?></td>
                                <td class="dni-col"><?= htmlspecialchars($row['dni'] ?? '-') ?></td>
                                <td class="name-col"><?= htmlspecialchars($nombreCompleto) ?></td>
                                <td>
                                    <small class="text-muted"><?= htmlspecialchars($row['nivel_nombre']) ?></small><br>
                                    <strong><?= htmlspecialchars($row['grado_nombre'] ?? $row['grado_numero'] ?? '') ?> &quot;<?= htmlspecialchars($row['seccion_nombre']) ?>&quot;</strong>
                                </td>
                                <td>
                                    <span class="badge <?= !empty($row['es_estudiante_nuevo']) ? 'bg-primary-subtle text-primary border border-primary-subtle' : 'bg-light text-secondary border' ?>" style="font-size: 0.75rem;">
                                        <?= $condicionTexto ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($row['apoderado_nombre']) ?></td>
                                <td class="font-monospace" style="font-size: 0.8rem;"><?= htmlspecialchars($row['apoderado_celular']) ?></td>
                                <td class="obs-col"></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- 5. Resumen Cuantitativo al Pie -->
        <div class="d-flex justify-content-between align-items-center small text-muted border-top pt-2 mb-4">
            <div>
                <strong>Total Matriculados:</strong> <?= $totalEstudiantes ?> &bull;
                <strong>Nuevos:</strong> <?= $totalNuevos ?> &bull;
                <strong>Ratificados / Continuos:</strong> <?= $totalContinuos ?>
            </div>
            <div>
                Documento emitido desde el Sistema de Gestión Escolar
            </div>
        </div>

        <!-- 6. Firmas Oficiales -->
        <div class="doc-signatures">
            <div class="sig-box">
                <div class="sig-border"></div>
                <div class="sig-title">Profesor(a) Tutor(a) / Docente de Aula</div>
                <div class="sig-subtitle">Firma y Post Firma</div>
            </div>
            <div class="sig-box">
                <div class="sig-border"></div>
                <div class="sig-title">Dirección Institucional</div>
                <div class="sig-subtitle">I.E. &quot;Divino Maestro&quot; - Mollepampa</div>
            </div>
        </div>

    </article>

</main>

<script src="../assets/js/nomina_matriculas.js" defer></script>
</body>
</html>
