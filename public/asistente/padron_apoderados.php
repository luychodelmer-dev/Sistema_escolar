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

// Año predeterminado: el activo o el primero disponible
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
$filtroTipo = trim($_GET['tipo'] ?? 'principales'); // 'principales' o 'todos'
$busqueda = trim($_GET['buscar'] ?? '');

// Construcción de la consulta de padrón
$sql = "
    SELECT r.id AS responsable_id, r.dni AS responsable_dni,
           r.nombres AS responsable_nombres,
           r.apellido_paterno AS responsable_apellido_paterno,
           r.apellido_materno AS responsable_apellido_materno,
           COALESCE(NULLIF(r.celular_actual, ''), r.celular, '-') AS responsable_celular,
           COALESCE(r.direccion, 'No registrada') AS responsable_direccion,
           COALESCE(r.nro_tarjeta_apafa, '-') AS tarjeta_apafa,
           MAX(er.parentesco) AS parentesco,
           STRING_AGG(DISTINCT (e.apellido_paterno || ' ' || e.apellido_materno || ', ' || e.nombres || ' (' || g.nombre || ' \"' || s.nombre || '\")'), ' ; ') AS estudiantes_cargo,
           STRING_AGG(DISTINCT e.dni, ', ') AS estudiantes_dnis,
           COUNT(DISTINCT e.id) AS total_hijos
    FROM responsable r
    JOIN estudiante_responsable er ON er.responsable_id = r.id
    JOIN estudiante e ON e.id = er.estudiante_id
    JOIN matricula m ON m.estudiante_id = e.id
    JOIN anio a ON a.id = m.anio_id
    JOIN nivel n ON n.id = m.nivel_id
    JOIN grado_seccion gs ON gs.id = m.grado_seccion_id
    JOIN grado g ON g.id = gs.grado_id
    JOIN seccion s ON s.id = gs.seccion_id
    WHERE a.anio = :anio
";
$params = ['anio' => $filtroAnio];

if ($filtroTipo === 'principales') {
    $sql .= " AND (er.es_apoderado = TRUE OR m.apoderado_principal_id = r.id)";
}

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
if ($busqueda !== '') {
    $sql .= " AND (
        CONCAT_WS(' ', r.dni, r.nombres, r.apellido_paterno, r.apellido_materno) ILIKE :busq 
        OR CONCAT_WS(' ', r.apellido_paterno, r.apellido_materno, r.nombres) ILIKE :busq
        OR CONCAT_WS(' ', e.dni, e.nombres, e.apellido_paterno, e.apellido_materno) ILIKE :busq
        OR CONCAT_WS(' ', e.apellido_paterno, e.apellido_materno, e.nombres) ILIKE :busq
    )";
    $params['busq'] = "%$busqueda%";
}

$sql .= " GROUP BY r.id, r.dni, r.nombres, r.apellido_paterno, r.apellido_materno, r.celular_actual, r.celular, r.direccion, r.nro_tarjeta_apafa";
$sql .= " ORDER BY r.apellido_paterno ASC, r.apellido_materno ASC, r.nombres ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$padres = $stmt->fetchAll();

// Textos informativos para el membrete
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

$totalRegistros = count($padres);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Padrón Oficial de Apoderados - I.E. Divino Maestro</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../assets/css/app.css">
    <link rel="stylesheet" href="../assets/css/reports-print.css">
    <link rel="stylesheet" href="../assets/css/padron_apoderados.css">
</head>
<body class="bg-light">

<?php render_app_header(panel_home_path(), 'logout.php'); ?>

<main class="container-fluid px-3 px-md-4 px-xl-5 py-4">

    <!-- BARRA SUPERIOR Y TÍTULO (SOLO PANTALLA) -->
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4 no-print">
        <div>
            <h1 class="h3 fw-bold mb-1 text-dark d-flex align-items-center gap-2">
                <i class="bi bi-person-lines-fill text-primary"></i> Padrón Oficial de Padres y Apoderados
            </h1>
            <p class="text-muted mb-0 small">Emite listas oficiales para asambleas de APAFA, reuniones de aula, citaciones y control de asistencia.</p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <button type="button" class="btn btn-primary d-inline-flex align-items-center gap-2 px-3 py-2 fw-semibold shadow-sm" onclick="window.print();" style="border-radius: 10px;">
                <i class="bi bi-printer-fill"></i> Imprimir Padrón / Guardar PDF
            </button>
            <a href="padres.php" class="btn btn-outline-secondary d-inline-flex align-items-center gap-1 px-3 py-2" style="border-radius: 10px;">
                <i class="bi bi-arrow-left"></i> Volver a Padres
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

            <div class="col-md-2">
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

            <div class="col-md-2">
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

            <div class="col-md-2">
                <label class="form-label small fw-bold text-secondary" for="tipo">Tipo de Registro</label>
                <select class="form-select" id="tipo" name="tipo">
                    <option value="principales" <?= $filtroTipo === 'principales' ? 'selected' : '' ?>>Solo Apoderados Principales</option>
                    <option value="todos" <?= $filtroTipo === 'todos' ? 'selected' : '' ?>>Todos (Padres y Tutores)</option>
                </select>
            </div>

            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary w-100 fw-semibold d-inline-flex align-items-center justify-content-center gap-1">
                    <i class="bi bi-funnel-fill"></i> Filtrar
                </button>
                <a href="padron_apoderados.php" class="btn btn-light border text-secondary" title="Limpiar filtros">
                    <i class="bi bi-x-circle"></i>
                </a>
            </div>
        </form>
    </section>

    <!-- DOCUMENTO OFICIAL DEL PADRÓN (IMPRIMIBLE / PDF) -->
    <article class="padron-document">

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

        <!-- 2. Banner de Padrón -->
        <div class="doc-banner">
            Padrón Oficial de Padres de Familia y Apoderados &bull; Año Lectivo <?= htmlspecialchars((string)$filtroAnio) ?>
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
                <strong>Total Apoderados:</strong>
                <span style="color: #1e3a8a;"><?= $totalRegistros ?> registrados</span>
            </div>
            <div class="meta-item">
                <strong>Filtro Aplicado:</strong>
                <span><?= $filtroTipo === 'principales' ? 'Apoderados Principales' : 'Todos los Familiares' ?></span>
            </div>
        </div>

        <!-- 4. Tabla de Apoderados -->
        <div class="table-responsive">
            <table class="padron-table">
                <thead>
                    <tr>
                        <th style="width: 35px;" class="text-center">N°</th>
                        <th style="width: 85px;">DNI</th>
                        <th>Apellidos y Nombres del Apoderado</th>
                        <th style="width: 95px;">Parentesco</th>
                        <th style="width: 100px;">Celular</th>
                        <th>Estudiante(s) a Cargo</th>
                        <th style="width: 80px;">Tarjeta</th>
                        <th style="width: 125px;" class="text-center">Firma de Conformidad</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$padres): ?>
                        <tr>
                            <td colspan="8" class="text-center py-4 text-muted">
                                <i class="bi bi-info-circle fs-4 d-block mb-1"></i>
                                No se encontraron apoderados con los filtros seleccionados.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php $correlativo = 1; ?>
                        <?php foreach ($padres as $row): ?>
                            <?php
                            $nombreCompleto = trim($row['responsable_apellido_paterno'] . ' ' . ($row['responsable_apellido_materno'] ?? '') . ', ' . $row['responsable_nombres']);
                            ?>
                            <tr>
                                <td class="num-col"><?= $correlativo++ ?></td>
                                <td class="dni-col"><?= htmlspecialchars($row['responsable_dni'] ?? '-') ?></td>
                                <td class="name-col"><?= htmlspecialchars($nombreCompleto) ?></td>
                                <td>
                                    <span class="badge bg-light text-secondary border" style="font-size: 0.78rem;">
                                        <?= htmlspecialchars(ucfirst($row['parentesco'] ?? 'Apoderado')) ?>
                                    </span>
                                </td>
                                <td class="font-monospace" style="font-size: 0.85rem; font-weight: 600;">
                                    <?= htmlspecialchars($row['responsable_celular']) ?>
                                </td>
                                <td>
                                    <small><?= htmlspecialchars($row['estudiantes_cargo'] ?? '-') ?></small>
                                </td>
                                <td class="font-monospace text-muted small">
                                    <?= htmlspecialchars($row['tarjeta_apafa']) ?>
                                </td>
                                <td class="firma-col"></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- 5. Resumen Cuantitativo al Pie -->
        <div class="d-flex justify-content-between align-items-center small text-muted border-top pt-2 mb-4">
            <div>
                <strong>Total Apoderados / Familias en este Padrón:</strong> <?= $totalRegistros ?> &bull;
                Fecha de Emisión: <?= date('d/m/Y') ?>
            </div>
            <div>
                Documento emitido para Asambleas, Citaciones y Control de la I.E. &quot;Divino Maestro&quot;
            </div>
        </div>

        <!-- 6. Firmas Oficiales -->
        <div class="doc-signatures">
            <div class="sig-box">
                <div class="sig-border"></div>
                <div class="sig-title">Junta Directiva APAFA / Tutor(a)</div>
                <div class="sig-subtitle">Firma de Conformidad</div>
            </div>
            <div class="sig-box">
                <div class="sig-border"></div>
                <div class="sig-title">Dirección Institucional</div>
                <div class="sig-subtitle">I.E. &quot;Divino Maestro&quot; - Mollepampa</div>
            </div>
        </div>

    </article>

</main>

<script src="../assets/js/padron_apoderados.js" defer></script>
</body>
</html>
