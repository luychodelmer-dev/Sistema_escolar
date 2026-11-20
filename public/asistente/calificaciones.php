<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/header.php';
require_once __DIR__ . '/../../app/services/calificaciones_service.php';

require_auth(['asistente', 'director', 'hiperadmin', 'administrador']);
require_permission('p_calificar');

// Migración y AJAX
asegurar_esquema_calificaciones($pdo);
manejar_ajax_calificaciones($pdo);

$csrfToken = $_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));

// Catálogos principales
$cursos = $pdo->query('SELECT id, nombre, grado FROM curso WHERE activo = TRUE ORDER BY grado, nombre')->fetchAll();
$periodos = $pdo->query('SELECT id, nombre FROM periodo_academico WHERE activo = TRUE ORDER BY fecha_inicio DESC, nombre')->fetchAll();
$niveles = $pdo->query('SELECT id, nombre FROM nivel ORDER BY id')->fetchAll();
$gradosDb = $pdo->query("
    SELECT g.id AS grado_id, g.nombre AS grado_nombre, g.numero, n.id AS nivel_id, n.nombre AS nivel_nombre
    FROM grado g
    JOIN nivel n ON n.id = g.nivel_id
    WHERE g.activo = TRUE
    ORDER BY n.id, g.numero
")->fetchAll();

$cursosMapeados = obtener_cursos_mapeados($cursos, $gradosDb);
$cursoIds = array_map('intval', array_column($cursos, 'id'));
$periodoIds = array_map('intval', array_column($periodos, 'id'));

// Lectura y sanitización de filtros
$nivelEntrada = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' ? ($_POST['nivel_id'] ?? '') : ($_GET['nivel_id'] ?? '');
$gradoEntrada = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' ? ($_POST['grado_id'] ?? '') : ($_GET['grado_id'] ?? '');
$seccionEntrada = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' ? ($_POST['seccion_id'] ?? '') : ($_GET['seccion_id'] ?? '');
$cursoEntrada = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' ? ($_POST['curso_id'] ?? '') : ($_GET['curso_id'] ?? '');
$periodoEntrada = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' ? ($_POST['periodo_id'] ?? '') : ($_GET['periodo_id'] ?? '');

$nivelId = is_string($nivelEntrada) || is_int($nivelEntrada) ? filter_var($nivelEntrada, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
$gradoId = is_string($gradoEntrada) || is_int($gradoEntrada) ? filter_var($gradoEntrada, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
$seccionId = is_string($seccionEntrada) || is_int($seccionEntrada) ? filter_var($seccionEntrada, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
$cursoId = is_string($cursoEntrada) || is_int($cursoEntrada) ? filter_var($cursoEntrada, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
$periodoId = is_string($periodoEntrada) || is_int($periodoEntrada) ? filter_var($periodoEntrada, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;

$nivelId = $nivelId !== false ? $nivelId : null;
$gradoId = $gradoId !== false ? $gradoId : null;
$seccionId = $seccionId !== false ? $seccionId : null;
$cursoId = $cursoId !== false && in_array($cursoId, $cursoIds, true) ? $cursoId : null;
$periodoId = $periodoId !== false && in_array($periodoId, $periodoIds, true) ? $periodoId : null;

// Cargar por defecto el aula inicial en primera visita limpia
if ($nivelId === null && !isset($_GET['nivel_id']) && !isset($_POST['nivel_id']) && empty($_GET['limpiar'])) {
    foreach ($gradosDb as $g) {
        if (mb_stripos($g['nivel_nombre'], 'primaria') !== false) {
            $nivelId = (int) $g['nivel_id'];
            $gradoId = (int) $g['grado_id'];
            break;
        }
    }
    if (!$gradoId && !empty($gradosDb)) {
        $nivelId = (int) $gradosDb[0]['nivel_id'];
        $gradoId = (int) $gradosDb[0]['grado_id'];
    }
} elseif ($gradoId !== null && $nivelId === null) {
    foreach ($gradosDb as $g) {
        if ((int) $g['grado_id'] === $gradoId) {
            $nivelId = (int) $g['nivel_id'];
            break;
        }
    }
}

// Grados disponibles
$gradosDisponibles = [];
if ($nivelId !== null) {
    $gStmt = $pdo->prepare('SELECT id, nombre, numero FROM grado WHERE nivel_id = :nid AND activo = TRUE ORDER BY numero');
    $gStmt->execute(['nid' => $nivelId]);
    $gradosDisponibles = $gStmt->fetchAll();
    $gradoIdsDisponibles = array_map('intval', array_column($gradosDisponibles, 'id'));
    if ($gradoId !== null && !in_array($gradoId, $gradoIdsDisponibles, true)) {
        $gradoId = null;
    }
} else {
    $gradosDisponibles = $pdo->query('
        SELECT g.id, (n.nombre || \' - \' || g.nombre) AS nombre, g.numero 
        FROM grado g 
        JOIN nivel n ON n.id = g.nivel_id 
        WHERE g.activo = TRUE 
        ORDER BY n.id, g.numero
    ')->fetchAll();
    if ($gradoId !== null) {
        $gradoIdsDisponibles = array_map('intval', array_column($gradosDisponibles, 'id'));
        if (!in_array($gradoId, $gradoIdsDisponibles, true)) {
            $gradoId = null;
        }
    }
}

$tabActiva = ($gradoId === null || $nivelId === null) ? 'grafica' : 'registro';
if (isset($_GET['tab']) && in_array($_GET['tab'], ['registro', 'grafica'], true)) {
    $tabActiva = $_GET['tab'];
}

$nivelActualNombre = null;
if ($nivelId !== null) {
    foreach ($niveles as $n) {
        if ((int)$n['id'] === $nivelId) {
            $nivelActualNombre = $n['nombre'];
            break;
        }
    }
}

// Secciones disponibles
$seccionesDisponibles = [];
if ($gradoId !== null) {
    $sStmt = $pdo->prepare('
        SELECT DISTINCT s.id, s.nombre 
        FROM grado_seccion gs 
        JOIN seccion s ON s.id = gs.seccion_id 
        WHERE gs.grado_id = :gid AND gs.activo = TRUE AND s.activo = TRUE 
        ORDER BY s.nombre
    ');
    $sStmt->execute(['gid' => $gradoId]);
    $seccionesDisponibles = $sStmt->fetchAll();
    if ($seccionId !== null) {
        $seccIds = array_map('intval', array_column($seccionesDisponibles, 'id'));
        if (!in_array($seccionId, $seccIds, true)) {
            $seccionId = null;
        }
    }
} else {
    $seccionId = null;
}

// Cursos disponibles
$cursosDisponibles = [];
$gradoActualInfo = null;
if ($gradoId !== null) {
    foreach ($gradosDb as $g) {
        if ((int) $g['grado_id'] === $gradoId) {
            $gradoActualInfo = $g;
            break;
        }
    }
    if ($gradoActualInfo) {
        $searchStr1 = $gradoActualInfo['nivel_nombre'] . ' - ' . $gradoActualInfo['grado_nombre'];
        $searchStr2 = '%' . $gradoActualInfo['nivel_nombre'] . '%' . $gradoActualInfo['grado_nombre'] . '%';
        $cStmt = $pdo->prepare('
            SELECT id, nombre, grado 
            FROM curso 
            WHERE activo = TRUE AND (grado ILIKE :s1 OR grado ILIKE :s2) 
            ORDER BY nombre
        ');
        $cStmt->execute(['s1' => $searchStr1, 's2' => $searchStr2]);
        $cursosDisponibles = $cStmt->fetchAll();
    }
} else {
    if ($nivelActualNombre) {
        $cStmt = $pdo->prepare('
            SELECT DISTINCT TRIM(nombre) AS nombre 
            FROM curso 
            WHERE activo = TRUE AND grado ILIKE :pat 
            ORDER BY TRIM(nombre)
        ');
        $cStmt->execute(['pat' => '%' . $nivelActualNombre . '%']);
        $cursosDisponibles = $cStmt->fetchAll();
    } else {
        $cursosDisponibles = $pdo->query('
            SELECT DISTINCT TRIM(nombre) AS nombre 
            FROM curso 
            WHERE activo = TRUE 
            ORDER BY TRIM(nombre)
        ')->fetchAll();
    }
}

if ($gradoId !== null) {
    $cursosDisponiblesIds = array_map('intval', array_column($cursosDisponibles, 'id'));
    if ($cursoId === null || !in_array($cursoId, $cursosDisponiblesIds, true)) {
        $cursoId = !empty($cursosDisponiblesIds) ? $cursosDisponiblesIds[0] : null;
    }
} else {
    $cursoId = null;
}

if ($periodoId === null && empty($_GET['limpiar']) && !empty($periodoIds)) {
    $periodoId = $periodoIds[0];
}

$cursoInfo = $cursoId !== null ? ($cursosMapeados[$cursoId] ?? null) : null;

// Procesar acciones POST mediante el servicio
$error = '';
$mensaje = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $resPost = procesar_post_calificaciones($pdo, $_POST, $cursoId, $periodoId, $csrfToken);
    $error = $resPost['error'];
    $mensaje = $resPost['mensaje'];
}

// Cargar alumnos y notas del aula
$alumnosData = obtener_alumnos_y_notas($pdo, $nivelId, $gradoId, $seccionId, $cursoId, $periodoId);
$alumnos = $alumnosData['alumnos'];
$notasActuales = $alumnosData['notasActuales'];

// Métricas de desempeño
$metricas = calcular_metricas_aula($alumnos, $notasActuales);
$totalCargados = $metricas['totalCargados'];
$calificadosCount = $metricas['calificadosCount'];
$countAD = $metricas['countAD'];
$countA = $metricas['countA'];
$countB = $metricas['countB'];
$countC = $metricas['countC'];
$aprobadosCount = $metricas['aprobadosCount'];
$desaprobadosCount = $metricas['desaprobadosCount'];

$cursoSeleccionado = null;
foreach ($cursos as $c) {
    if ((int)$c['id'] === $cursoId) {
        $cursoSeleccionado = $c;
        break;
    }
}
$periodoSeleccionado = null;
foreach ($periodos as $p) {
    if ((int)$p['id'] === $periodoId) {
        $periodoSeleccionado = $p;
        break;
    }
}

// Consolidado y datos para Chart.js
$consolidado = obtener_consolidado_grafica($pdo, $nivelId, $gradoId, $seccionId, $periodoId, $cursosDisponibles);
$consolidadoAula = $consolidado['consolidadoAula'];
$labelsGrafica = $consolidado['labelsGrafica'];
$dataGraficaAD = $consolidado['dataGraficaAD'];
$dataGraficaA = $consolidado['dataGraficaA'];
$dataGraficaB = $consolidado['dataGraficaB'];
$dataGraficaC = $consolidado['dataGraficaC'];
$totalGeneralAD = $consolidado['totalGeneralAD'];
$totalGeneralA = $consolidado['totalGeneralA'];
$totalGeneralB = $consolidado['totalGeneralB'];
$totalGeneralC = $consolidado['totalGeneralC'];
$totalGeneralEvaluados = $consolidado['totalGeneralEvaluados'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Calificaciones - Sistema Escolar</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../assets/css/app.css">
    <link rel="stylesheet" href="../assets/css/calificaciones.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2"></script>

</head>
<body class="bg-light">
<?php render_app_header(panel_home_path(), 'logout.php'); ?>

<main class="container-fluid px-3 px-md-4 px-xl-5 py-4">
    <!-- Breadcrumb y Encabezado de Página -->
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1 small">
                    <li class="breadcrumb-item"><a href="<?= htmlspecialchars(panel_home_path()) ?>" class="text-decoration-none text-muted"><i class="bi bi-house-door"></i> Inicio</a></li>
                    <li class="breadcrumb-item active text-primary fw-semibold" aria-current="page">Calificaciones</li>
                </ol>
            </nav>
            <h1 class="h3 fw-bold mb-1 text-dark d-flex align-items-center gap-2">
                <i class="bi bi-pencil-square text-primary"></i> Registro de Calificaciones
                <span class="badge bg-primary-subtle text-primary border border-primary-subtle fs-6 fw-semibold">Escala Literal C, B, A, AD</span>
            </h1>
            <p class="text-muted mb-0 small">Evaluación del Currículo Nacional (C = En Inicio, B = En Proceso, A = Logro Esperado, AD = Logro Destacado) y control de alumnos promovidos y repitentes.</p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <a href="reporte_calificaciones.php?nivel_id=<?= $nivelId ?>&grado_id=<?= $gradoId ?>&seccion_id=<?= $seccionId ?>&periodo_id=<?= $periodoId ?>" class="btn btn-primary d-inline-flex align-items-center gap-1 px-3 py-2 fw-semibold" style="border-radius: 10px; font-size: 0.96rem;">
                <i class="bi bi-graph-up-arrow"></i> Reporte Estadístico
            </a>
            <a href="cursos_periodos.php" class="btn btn-outline-primary d-inline-flex align-items-center gap-1 px-3 py-2 fw-semibold" style="border-radius: 10px; font-size: 0.96rem;">
                <i class="bi bi-book-half"></i> Cursos y Periodos
            </a>
            <a href="estudiantes.php" class="btn btn-outline-secondary d-inline-flex align-items-center gap-1 px-3 py-2" style="border-radius: 10px; font-size: 0.96rem;">
                <i class="bi bi-mortarboard"></i> Ver Estudiantes
            </a>
        </div>
    </div>

    <?php if ($mensaje !== ''): ?>
        <div class="alert alert-success d-flex align-items-center gap-2 shadow-sm border-0 mb-4 fs-6" style="border-radius: 12px;">
            <i class="bi bi-check-circle-fill fs-5"></i>
            <div><?= htmlspecialchars($mensaje) ?></div>
        </div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="alert alert-danger d-flex align-items-center gap-2 shadow-sm border-0 mb-4 fs-6" style="border-radius: 12px;">
            <i class="bi bi-exclamation-triangle-fill fs-5"></i>
            <div><?= htmlspecialchars($error) ?></div>
        </div>
    <?php endif; ?>

    <!-- Formulario Inteligente de Filtro en Cascada: Nivel -> Grado -> Sección -> Cursos del Aula -> Periodo -->
    <form method="GET" class="card main-card mb-4" id="formFiltroCalificaciones">
        <div class="card-body p-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
                <h5 class="fw-bold text-dark mb-0 d-flex align-items-center gap-2">
                    <i class="bi bi-funnel-fill text-primary"></i> Filtro de Calificación por Aula y Curso
                </h5>
                <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-3 py-2 fw-semibold" id="badge-cursos-count" style="font-size: 0.88rem;">
                    <?php if ($nivelId === null): ?>
                        <i class="bi bi-bezier2 me-1"></i> Modo Consolidado (Todos los niveles)
                    <?php elseif ($gradoId === null): ?>
                        <i class="bi bi-bezier2 me-1"></i> Modo Consolidado (Todos los grados)
                    <?php else: ?>
                        <i class="bi bi-book me-1"></i> <?= count($cursosDisponibles) ?> <?= count($cursosDisponibles) === 1 ? 'curso disponible' : 'cursos disponibles' ?> para esta aula
                    <?php endif; ?>
                </span>
            </div>

            <div class="row g-3 align-items-end">
                <!-- 1. Nivel Educativo -->
                <div class="col-12 col-sm-6 col-md-2">
                    <label class="form-label fw-semibold small text-muted" for="nivel_id">1. Nivel</label>
                    <select class="form-select fw-semibold" id="nivel_id" name="nivel_id" onchange="onNivelChange(this.value)">
                        <option value="" <?= $nivelId === null ? 'selected' : '' ?>>-- Todos los niveles --</option>
                        <?php foreach ($niveles as $niv): ?>
                            <option value="<?= (int) $niv['id'] ?>" <?= $nivelId === (int) $niv['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($niv['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- 2. Grado -->
                <div class="col-12 col-sm-6 col-md-2">
                    <label class="form-label fw-semibold small text-muted" for="grado_id">2. Grado</label>
                    <select class="form-select fw-semibold" id="grado_id" name="grado_id" onchange="onGradoChange(this.value)">
                        <option value="" <?= $gradoId === null ? 'selected' : '' ?>>-- Todos los grados --</option>
                        <?php foreach ($gradosDisponibles as $grd): ?>
                            <option value="<?= (int) $grd['id'] ?>" <?= $gradoId === (int) $grd['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($grd['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- 3. Sección -->
                <div class="col-12 col-sm-6 col-md-2">
                    <label class="form-label fw-semibold small text-muted" for="seccion_id">3. Sección</label>
                    <select class="form-select" id="seccion_id" name="seccion_id" onchange="this.form.submit()" <?= $gradoId === null ? 'disabled' : '' ?>>
                        <option value="">Todas las secciones</option>
                        <?php foreach ($seccionesDisponibles as $sec): ?>
                            <option value="<?= (int) $sec['id'] ?>" <?= $seccionId === (int) $sec['id'] ? 'selected' : '' ?>>
                                Sección "<?= htmlspecialchars($sec['nombre']) ?>"
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- 4. Cursos que existen para este Nivel y Grado -->
                <div class="col-12 col-sm-6 col-md-3">
                    <label class="form-label fw-semibold small text-muted" for="curso_id">
                        4. Cursos del Aula <?= $gradoId !== null ? '<span class="text-danger">*</span>' : '' ?>
                    </label>
                    <select class="form-select fw-semibold text-primary" id="curso_id" name="curso_id" <?= $gradoId !== null ? 'required' : 'disabled' ?> onchange="this.form.submit()">
                        <?php if ($gradoId === null): ?>
                            <option value="">-- Todos los cursos (Consolidado) --</option>
                        <?php else: ?>
                            <option value="">-- Selecciona el curso --</option>
                            <?php foreach ($cursosDisponibles as $cur): ?>
                                <option value="<?= (int) $cur['id'] ?>" <?= $cursoId === (int) $cur['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cur['nombre']) ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>

                <!-- 5. Periodo Académico -->
                <div class="col-12 col-sm-6 col-md-3">
                    <label class="form-label fw-semibold small text-muted" for="periodo_id">5. Periodo <span class="text-danger">*</span></label>
                    <select class="form-select fw-semibold" id="periodo_id" name="periodo_id" required onchange="this.form.submit()">
                        <option value="">-- Periodo --</option>
                        <?php foreach ($periodos as $periodo): ?>
                            <option value="<?= (int) $periodo['id'] ?>" <?= $periodoId === (int) $periodo['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($periodo['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Botones y Explicación -->
                <div class="col-12 text-end pt-2 border-top d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <small class="text-muted d-flex align-items-center gap-1">
                        <i class="bi bi-info-circle text-primary fs-6"></i>
                        <span>Elige <strong>Nivel</strong> y <strong>Grado</strong>: el sistema muestra qué cursos existen para esa aula y lista a sus alumnos matriculados.</span>
                    </small>
                    <div>
                        <?php if ($cursoId || $periodoId || $seccionId || $gradoId || $nivelId): ?>
                            <a href="calificaciones.php?limpiar=1" class="btn btn-outline-secondary me-2 px-3 py-2 fw-semibold" style="border-radius: 9px;">Limpiar</a>
                        <?php endif; ?>
                        <button class="btn btn-primary px-4 py-2 fw-semibold" type="submit" style="border-radius: 9px;">
                            <i class="bi bi-people-fill me-1"></i> Cargar Lista de Alumnos
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </form>

    <?php if ($periodoId !== null && ($cursoId !== null || $gradoId === null || $nivelId === null)): ?>
        <!-- Selector de Vista: Registro Individual de Notas vs. Gráfica y Consolidado -->
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <ul class="nav nav-pills p-1 bg-white border rounded-3 shadow-sm" id="pills-vista-calificaciones" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?= $tabActiva === 'registro' ? 'active' : '' ?> fw-bold px-3 py-2 text-dark" id="tab-btn-registro" data-bs-toggle="pill" data-bs-target="#tab-pane-registro" type="button" role="tab" aria-selected="<?= $tabActiva === 'registro' ? 'true' : 'false' ?>">
                        <i class="bi bi-pencil-square me-1 text-primary"></i> Registro Individual de Notas
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?= $tabActiva === 'grafica' ? 'active' : '' ?> fw-bold px-3 py-2 text-dark" id="tab-btn-grafica" data-bs-toggle="pill" data-bs-target="#tab-pane-grafica" type="button" role="tab" aria-selected="<?= $tabActiva === 'grafica' ? 'true' : 'false' ?>">
                        <i class="bi bi-bezier2 me-1 text-primary"></i> Gráfica y Consolidado del Aula
                    </button>
                </li>
            </ul>

            <div class="d-flex align-items-center gap-2">
                <a href="reporte_calificaciones.php?nivel_id=<?= $nivelId ?>&grado_id=<?= $gradoId ?>&seccion_id=<?= $seccionId ?>&periodo_id=<?= $periodoId ?>" class="btn btn-outline-primary fw-semibold d-inline-flex align-items-center gap-1 shadow-sm px-3 py-2" style="border-radius: 9px; font-size: 0.92rem;">
                    <i class="bi bi-printer-fill me-1"></i> Reporte Imprimible Oficial
                </a>
            </div>
        </div>

        <div class="tab-content" id="pills-tabContent-calificaciones">
            <!-- TAB 1: REGISTRO INDIVIDUAL DE NOTAS -->
            <div class="tab-pane fade <?= $tabActiva === 'registro' ? 'show active' : '' ?>" id="tab-pane-registro" role="tabpanel" aria-labelledby="tab-btn-registro">
            <?php if ($nivelId === null || $gradoId === null): ?>
                <div class="card main-card p-5 text-center my-2 shadow-sm border-0">
                    <div class="py-4 px-3" style="max-width: 680px; margin: 0 auto;">
                        <div class="mb-3">
                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-3 py-2 rounded-pill fs-6 fw-bold">
                                <i class="bi bi-info-circle me-1"></i> Modo Consolidado: <?= $nivelId === null ? 'Todos los Niveles y Grados' : 'Todos los Grados' ?>
                            </span>
                        </div>
                        <h4 class="fw-bold text-dark mb-2">Vista Consolidada Institucional <?= $nivelActualNombre ? 'de ' . htmlspecialchars($nivelActualNombre) : '' ?></h4>
                        <p class="text-muted mb-4">
                            Has seleccionado la opción <strong>"<?= $nivelId === null ? 'Todos los niveles' : 'Todos los grados' ?>"</strong>. La gráfica interactiva multilínea y la matriz consolidada de calificaciones de todas las áreas curriculares se encuentran en la pestaña contigua:
                        </p>
                        <div class="mb-4">
                            <button type="button" class="btn btn-primary px-4 py-2.5 fw-semibold shadow-sm" style="border-radius: 10px;" onclick="bootstrap.Tab.getOrCreateInstance(document.getElementById('tab-btn-grafica')).show();">
                                <i class="bi bi-bezier2 me-1"></i> Ver Gráfica y Consolidado
                            </button>
                        </div>
                        <div class="alert alert-light border small text-secondary mb-0 py-2.5">
                            <i class="bi bi-pencil-square text-primary me-1"></i> Para ingresar o modificar notas estudiante por estudiante, selecciona un grado específico en los filtros superiores.
                        </div>
                    </div>
                </div>
            <?php else: ?>
        <!-- Resumen de Evaluación del Aula en Escala Literal (C, B, A, AD) -->
        <div class="row g-2 mb-4">
            <div class="col-6 col-sm-4 col-md-2">
                <div class="kpi-stat-card text-center">
                    <span class="text-muted small text-uppercase fw-semibold d-block">Alumnos</span>
                    <h4 class="fw-bold text-dark mb-0 mt-1"><?= $totalCargados ?></h4>
                    <small class="text-muted" id="stat-evaluados"><?= $calificadosCount ?> calificados</small>
                </div>
            </div>
            <div class="col-6 col-sm-4 col-md-2">
                <div class="kpi-stat-card text-center border-primary-subtle bg-primary-subtle bg-opacity-10">
                    <span class="text-primary small text-uppercase fw-bold d-block">AD · Destacado</span>
                    <h4 class="fw-bold text-primary mb-0 mt-1" id="stat-ad"><?= $countAD ?></h4>
                    <small class="text-primary">Logro Destacado</small>
                </div>
            </div>
            <div class="col-6 col-sm-4 col-md-2">
                <div class="kpi-stat-card text-center border-success-subtle bg-success-subtle bg-opacity-10">
                    <span class="text-success small text-uppercase fw-bold d-block">A · Esperado</span>
                    <h4 class="fw-bold text-success mb-0 mt-1" id="stat-a"><?= $countA ?></h4>
                    <small class="text-success">Logro Esperado</small>
                </div>
            </div>
            <div class="col-6 col-sm-4 col-md-2">
                <div class="kpi-stat-card text-center border-warning-subtle bg-warning-subtle bg-opacity-10">
                    <span class="text-warning-emphasis small text-uppercase fw-bold d-block">B · En Proceso</span>
                    <h4 class="fw-bold text-warning-emphasis mb-0 mt-1" id="stat-b"><?= $countB ?></h4>
                    <small class="text-warning-emphasis">Cercano al logro</small>
                </div>
            </div>
            <div class="col-6 col-sm-4 col-md-2">
                <div class="kpi-stat-card text-center border-danger-subtle bg-danger-subtle bg-opacity-10">
                    <span class="text-danger small text-uppercase fw-bold d-block">C · En Inicio</span>
                    <h4 class="fw-bold text-danger mb-0 mt-1" id="stat-c"><?= $countC ?></h4>
                    <small class="text-danger">Repitente / Riesgo</small>
                </div>
            </div>
            <div class="col-6 col-sm-4 col-md-2">
                <div class="kpi-stat-card text-center border-success bg-white">
                    <span class="text-muted small text-uppercase fw-semibold d-block">Situación</span>
                    <h4 class="fw-bold text-success mb-0 mt-1" id="stat-promovidos"><i class="bi bi-check2-circle"></i> <?= $aprobadosCount ?></h4>
                    <small class="text-muted">Promovidos (AD/A/B)</small>
                </div>
            </div>
        </div>

        <!-- Tabla de Calificaciones -->
        <form method="POST" class="card main-card">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="curso_id" value="<?= $cursoId ?>">
            <input type="hidden" name="periodo_id" value="<?= $periodoId ?>">
            <input type="hidden" name="nivel_id" value="<?= htmlspecialchars((string) $nivelId) ?>">
            <input type="hidden" name="grado_id" value="<?= htmlspecialchars((string) $gradoId) ?>">
            <input type="hidden" name="seccion_id" value="<?= htmlspecialchars((string) $seccionId) ?>">

            <div class="card-header bg-white py-3 px-4 border-bottom d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <h5 class="fw-bold text-dark mb-0 d-flex align-items-center gap-2">
                        <i class="bi bi-card-checklist text-primary"></i> 
                        <?= htmlspecialchars($cursoSeleccionado['nombre'] ?? 'Curso') ?> · <?= htmlspecialchars($periodoSeleccionado['nombre'] ?? 'Periodo') ?>
                        <?php if ($cursoInfo && !empty($cursoInfo['aula_etiqueta'])): ?>
                            <span class="badge bg-secondary-subtle text-secondary border fs-6 fw-normal"><?= htmlspecialchars($cursoInfo['aula_etiqueta']) ?></span>
                        <?php endif; ?>
                    </h5>
                    <small class="text-muted">Asigna la calificación literal (AD, A, B o C) a cada alumno y presiona Guardar Calificaciones.</small>
                </div>

                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <!-- Botones de ayuda rápida -->
                    <div class="btn-group btn-group-sm" role="group">
                        <button type="button" class="btn btn-outline-success" onclick="asignarRapido('A')" title="Asignar 'A' (Logro Esperado) a todos los alumnos que aún no tienen nota">
                            <i class="bi bi-magic me-1"></i> Poner 'A' a vacíos
                        </button>
                        <button type="button" class="btn btn-outline-primary" onclick="asignarRapido('AD')" title="Asignar 'AD' (Logro Destacado) a todos los alumnos que aún no tienen nota">
                            <i class="bi bi-star me-1"></i> Poner 'AD' a vacíos
                        </button>
                        <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#modalVaciarNotas" title="Eliminar todas las notas de este curso y periodo">
                            <i class="bi bi-trash3 me-1"></i> Vaciar Notas
                        </button>
                    </div>

                    <?php if ($alumnos): ?>
                        <button class="btn btn-success d-inline-flex align-items-center gap-2 px-3 py-2 fw-semibold" type="submit" name="action" value="guardar" style="border-radius: 9px;">
                            <i class="bi bi-check-lg"></i> Guardar Calificaciones
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Barra Permanente de Acciones Masivas y Selección -->
            <div id="bulkActionBar" class="bg-light border-bottom px-4 py-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <button type="button" class="btn btn-sm btn-outline-primary fw-bold px-3 py-1.5 shadow-sm" id="btnSelectAllHeader" onclick="toggleSelectAllBtn()" style="border-radius: 8px;">
                        <i class="bi bi-check2-all me-1"></i> Seleccionar Todos
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary px-3 py-1.5" id="btnDeselectAllHeader" onclick="deseleccionarTodos()" disabled style="border-radius: 8px;">
                        <i class="bi bi-x-lg me-1"></i> Deseleccionar
                    </button>
                    <div class="vr mx-1 d-none d-sm-block"></div>
                    <span class="badge bg-secondary text-white rounded-pill px-3 py-2 fs-6 shadow-sm" id="bulkBadgeContador">
                        <i class="bi bi-check2-square me-1"></i> <span id="bulkSelectedCount">0</span> alumnos seleccionados
                    </span>
                    <span class="text-muted small d-none d-md-inline" id="bulkHintText">
                        (Marca las casillas a la izquierda de cada alumno)
                    </span>
                </div>

                <div class="d-flex align-items-center gap-2 flex-wrap" id="bulkActionButtons">
                    <span class="small fw-bold text-muted me-1 d-none d-lg-inline">Acciones en lote:</span>
                    <div class="btn-group btn-group-sm" role="group">
                        <button type="button" class="btn btn-outline-primary fw-semibold bulk-requires-select" onclick="asignarNotaSeleccionados('AD')" disabled title="Asignar 'AD' (Destacado) a los alumnos marcados">
                            <i class="bi bi-star-fill text-warning me-1"></i> Poner AD
                        </button>
                        <button type="button" class="btn btn-outline-success fw-semibold bulk-requires-select" onclick="asignarNotaSeleccionados('A')" disabled title="Asignar 'A' (Esperado) a los alumnos marcados">
                            <i class="bi bi-check-circle-fill text-success me-1"></i> Poner A
                        </button>
                        <button type="button" class="btn btn-outline-warning text-dark fw-semibold bulk-requires-select" onclick="asignarNotaSeleccionados('B')" disabled title="Asignar 'B' (En proceso) a los alumnos marcados">
                            <i class="bi bi-clock-fill text-warning me-1"></i> Poner B
                        </button>
                        <button type="button" class="btn btn-outline-danger fw-semibold bulk-requires-select" onclick="asignarNotaSeleccionados('C')" disabled title="Asignar 'C' (En inicio) a los alumnos marcados">
                            <i class="bi bi-exclamation-circle-fill text-danger me-1"></i> Poner C
                        </button>
                    </div>

                    <button type="button" class="btn btn-sm btn-outline-secondary fw-semibold bulk-requires-select" onclick="limpiarSeleccionadosEnTabla()" disabled title="Quitar calificación en pantalla de los seleccionados">
                        <i class="bi bi-eraser me-1"></i> Limpiar en Pantalla
                    </button>

                    <button type="button" class="btn btn-sm btn-danger fw-semibold px-3 bulk-requires-select shadow-sm" onclick="confirmarBorrarSeleccionados()" disabled title="Eliminar permanentemente de la base de datos las notas de los alumnos seleccionados" style="border-radius: 8px;">
                        <i class="bi bi-trash3-fill me-1"></i> Borrar Notas Seleccionadas
                    </button>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th class="text-center" style="width: 55px;">
                                <div class="d-flex flex-column align-items-center">
                                    <input type="checkbox" class="form-check-input" id="checkAllAlumnos" onchange="toggleSelectAll(this)" title="Marcar / Desmarcar todos los alumnos" style="cursor: pointer; width: 1.25rem; height: 1.25rem;">
                                    <span class="text-muted text-uppercase mt-1" style="font-size: 0.68rem; font-weight: 800; letter-spacing: 0.05em;">Todos</span>
                                </div>
                            </th>
                            <th class="text-center" style="width: 48px;">#</th>
                            <th style="min-width: 100px;">DNI</th>
                            <th style="min-width: 260px;">Estudiante</th>
                            <th style="min-width: 160px;">Aula / Asignación</th>
                            <th style="min-width: 130px;">Estado Escolar</th>
                            <th style="min-width: 180px;">Calificación Literal</th>
                            <th style="min-width: 220px;">Nivel de Logro / Condición</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$alumnos): ?>
                        <tr>
                            <td colspan="8" class="text-center py-5">
                                <div class="py-4">
                                    <i class="bi bi-people text-muted" style="font-size: 2.2rem;"></i>
                                    <h5 class="mt-3 text-secondary fw-semibold">No se encontraron estudiantes para esta selección</h5>
                                    <p class="text-muted small mb-0">Verifica que haya alumnos matriculados en esta aula y sección.</p>
                                </div>
                            </td>
                        </tr>
                    <?php else: 
                        $sec = 1;
                        $avatarColors = [
                            ['bg' => '#eff6ff', 'text' => '#1d4ed8'],
                            ['bg' => '#f0fdf4', 'text' => '#15803d'],
                            ['bg' => '#fdf2f8', 'text' => '#be185d'],
                            ['bg' => '#fefce8', 'text' => '#a16207'],
                            ['bg' => '#f5f3ff', 'text' => '#6d28d9'],
                        ];
                        foreach ($alumnos as $alumno): 
                            $matriculaId = (int) $alumno['matricula_id'];
                            $notaActual = $notasActuales[$matriculaId] ?? '';
                            $nombreCompleto = $alumno['apellido_paterno'] . ' ' . ($alumno['apellido_materno'] ?? '') . ', ' . $alumno['nombres'];
                            $inicial = mb_strtoupper(mb_substr($alumno['apellido_paterno'] ?? 'A', 0, 1) . mb_substr($alumno['nombres'] ?? 'A', 0, 1));
                            $col = $avatarColors[$matriculaId % count($avatarColors)];
                            $avatarStyle = 'background-color:' . $col['bg'] . ';color:' . $col['text'] . ';';
                        ?>
                        <tr id="row-alumno-<?= $matriculaId ?>">
                            <td class="text-center">
                                <input type="checkbox" class="form-check-input check-alumno" 
                                       value="<?= $matriculaId ?>" 
                                       id="check-alumno-<?= $matriculaId ?>"
                                       onchange="onAlumnoCheckChange()" 
                                       title="Seleccionar a <?= htmlspecialchars($nombreCompleto, ENT_QUOTES, 'UTF-8') ?>"
                                       style="cursor: pointer; width: 1.15rem; height: 1.15rem;">
                            </td>
                            <td class="text-center text-muted fw-semibold"><?= $sec++ ?></td>
                            <td><span class="font-monospace fw-bold text-dark" style="font-size: 0.96rem;"><?= htmlspecialchars($alumno['dni']) ?></span></td>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="avatar-circle" style="<?= $avatarStyle ?>"><?= htmlspecialchars($inicial) ?></div>
                                    <div>
                                        <a href="ver_estudiante.php?id=<?= (int)$alumno['estudiante_id'] ?>" class="text-decoration-none fw-bold text-dark hover-primary" style="font-size: 1.02rem;">
                                            <?= htmlspecialchars($nombreCompleto) ?>
                                        </a>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <?php if (!empty($alumno['nivel'])): ?>
                                    <span class="badge bg-light text-secondary border">
                                        <?= htmlspecialchars($alumno['nivel'] . ' ' . $alumno['grado'] . ' "' . $alumno['seccion'] . '"') ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted small">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $estadoEst = $alumno['estado_academico'];
                                $badgeClass = match ($estadoEst) {
                                    'Activo' => 'bg-success-subtle text-success border border-success-subtle',
                                    'Promovido' => 'text-white',
                                    'Repitente' => 'text-white',
                                    'Promoción' => 'bg-primary-subtle text-primary border border-primary-subtle',
                                    'Retirado' => 'bg-secondary-subtle text-secondary border border-secondary-subtle',
                                    default => 'bg-warning-subtle text-warning-emphasis border border-warning-subtle',
                                };
                                $customStyle = match ($estadoEst) {
                                    'Promovido' => 'style="background-color: #059669;"',
                                    'Repitente' => 'style="background-color: #dc2626;"',
                                    default => '',
                                };
                                ?>
                                <span class="badge <?= $badgeClass ?> px-2 py-1" <?= $customStyle ?>>
                                    <?= htmlspecialchars($estadoEst) ?>
                                </span>
                            </td>
                            <td>
                                <div class="d-flex align-items-center gap-1">
                                    <select class="form-select nota-literal-select <?= $notaActual ? 'nota-' . strtolower($notaActual) : '' ?>"
                                            name="notas[<?= $matriculaId ?>]"
                                            id="select-nota-<?= $matriculaId ?>"
                                            data-matricula-id="<?= $matriculaId ?>"
                                            onchange="updateLiteralBadge(this)">
                                        <option value="" class="text-muted">-- Sin calificar --</option>
                                        <option value="AD" <?= $notaActual === 'AD' ? 'selected' : '' ?> class="text-primary fw-bold">AD · Logro Destacado</option>
                                        <option value="A" <?= $notaActual === 'A' ? 'selected' : '' ?> class="text-success fw-bold">A · Logro Esperado</option>
                                        <option value="B" <?= $notaActual === 'B' ? 'selected' : '' ?> class="text-warning-emphasis fw-bold">B · En Proceso</option>
                                        <option value="C" <?= $notaActual === 'C' ? 'selected' : '' ?> class="text-danger fw-bold">C · En Inicio (Riesgo)</option>
                                    </select>
                                    <button type="button" class="btn btn-sm btn-outline-danger p-1 border-0" 
                                            onclick="borrarNotaFila(<?= $matriculaId ?>)" 
                                            title="Quitar calificación de este alumno">
                                        <i class="bi bi-trash3 text-danger fs-5"></i>
                                    </button>
                                </div>
                            </td>
                            <td>
                                <span class="grade-status-badge" id="badge-<?= $matriculaId ?>">
                                    <?php if ($notaActual === 'AD'): ?>
                                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1.5 fs-6">
                                            <i class="bi bi-star-fill me-1 text-primary"></i>Logro Destacado (Promovido)
                                        </span>
                                    <?php elseif ($notaActual === 'A'): ?>
                                        <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1.5 fs-6">
                                            <i class="bi bi-check-circle-fill me-1 text-success"></i>Logro Esperado (Promovido)
                                        </span>
                                    <?php elseif ($notaActual === 'B'): ?>
                                        <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-2 py-1.5 fs-6">
                                            <i class="bi bi-clock-history me-1 text-warning-emphasis"></i>En Proceso (Regular)
                                        </span>
                                    <?php elseif ($notaActual === 'C'): ?>
                                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1.5 fs-6">
                                            <i class="bi bi-arrow-repeat me-1 text-danger"></i>En Inicio (Repitente)
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-light text-muted border px-2 py-1">Sin calificar</span>
                                    <?php endif; ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($alumnos): ?>
                <div class="card-footer bg-white py-3 px-4 border-top d-flex justify-content-between align-items-center">
                    <span class="text-muted small">Total: <strong><?= count($alumnos) ?></strong> estudiantes listados.</span>
                    <button class="btn btn-success d-inline-flex align-items-center gap-2 px-4 py-2 fw-semibold" type="submit" style="border-radius: 9px; font-size: 1rem;">
                        <i class="bi bi-check-lg"></i> Guardar Calificaciones
                    </button>
                </div>
            <?php endif; ?>
        </form>
            <?php endif; ?>
            </div>

            <!-- TAB 2: GRÁFICA Y CONSOLIDADO DEL AULA (IDÉNTICO A EXCEL) -->
            <div class="tab-pane fade <?= $tabActiva === 'grafica' ? 'show active' : '' ?>" id="tab-pane-grafica" role="tabpanel" aria-labelledby="tab-btn-grafica">
                <div class="card main-card mb-4">
                    <div class="card-header bg-white py-3 px-4 border-bottom d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <div>
                            <h5 class="fw-bold text-dark mb-0 d-flex align-items-center gap-2">
                                <i class="bi bi-bezier2 text-primary"></i> Gráfica de Rendimiento por Curso
                                <?php if ($gradoActualInfo): ?>
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle fs-6 fw-semibold">
                                        <?= htmlspecialchars($gradoActualInfo['nivel_nombre'] . ' - ' . $gradoActualInfo['grado_nombre']) ?>
                                        <?= $seccionId ? ' (Sección ' . htmlspecialchars($seccionesDisponibles[array_search($seccionId, array_column($seccionesDisponibles, 'id'))]['nombre'] ?? '') . ')' : ' (Todas las secciones)' ?>
                                    </span>
                                <?php elseif ($nivelActualNombre): ?>
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle fs-6 fw-semibold">
                                        <?= htmlspecialchars($nivelActualNombre) ?> - Todos los Grados (Todas las Secciones)
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle fs-6 fw-semibold">
                                        Institucional - Todos los Niveles y Grados (Todas las Secciones)
                                    </span>
                                <?php endif; ?>
                            </h5>
                            <small class="text-muted">Distribución comparativa de notas por escala oficial (AD, A, B, C) en todas las asignaturas de esta aula.</small>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-secondary-subtle text-secondary border px-3 py-1.5 fw-semibold" style="font-size: 0.88rem;">
                                <i class="bi bi-calendar-check me-1"></i> <?= htmlspecialchars($periodoSeleccionado['nombre'] ?? 'Periodo') ?>
                            </span>
                            <a href="reporte_calificaciones.php?nivel_id=<?= $nivelId ?>&grado_id=<?= $gradoId ?>&seccion_id=<?= $seccionId ?>&periodo_id=<?= $periodoId ?>" class="btn btn-sm btn-primary fw-semibold px-3 py-1.5" style="border-radius: 8px;">
                                <i class="bi bi-printer-fill me-1"></i> Ver Reporte Oficial
                            </a>
                        </div>
                    </div>
                    <div class="card-body p-4">
                        <div class="chart-wrapper position-relative" style="height: 380px;">
                            <canvas id="chartCalificacionesAula"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Matriz de Datos Oficial (Idéntica a la tabla inferior de Excel) -->
                <div class="card main-card mb-4 overflow-hidden">
                    <div class="card-header bg-white py-3 px-4 border-bottom d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <h5 class="fw-bold text-dark mb-0 d-flex align-items-center gap-2">
                            <i class="bi bi-table text-primary"></i> Matriz Consolidada de Calificaciones
                        </h5>
                        <span class="text-muted small">Conteo oficial de alumnos por escala de logro en el periodo</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-consolidado">
                            <thead>
                                <tr>
                                    <th rowspan="2" class="bg-light text-dark fw-bold" style="min-width: 140px; width: 140px;">NIVEL DE LOGRO</th>
                                    <?php 
                                    $colIdx = 0;
                                    foreach ($consolidadoAula as $item): 
                                        $thClass = ($colIdx % 2 === 0) ? 'th-green-light' : 'th-white';
                                        $colIdx++;
                                    ?>
                                        <th class="<?= $thClass ?>" style="min-width: 105px;">
                                            <?= htmlspecialchars($item['nombre_corto']) ?>
                                        </th>
                                    <?php endforeach; ?>
                                    <th rowspan="2" class="bg-dark text-white fw-bold" style="min-width: 110px;">TOTAL GENERAL</th>
                                </tr>
                                <tr>
                                    <?php 
                                    $colIdx = 0;
                                    foreach ($consolidadoAula as $item): 
                                        $thClass = ($colIdx % 2 === 0) ? 'th-green-light' : 'th-white';
                                        $colIdx++;
                                    ?>
                                        <th class="<?= $thClass ?> text-muted" style="font-size: 0.75rem;">Total</th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- Fila AD -->
                                <tr>
                                    <td class="row-label-ad text-start ps-3">
                                        <span class="badge bg-primary text-white me-1">AD</span> Destacado
                                    </td>
                                    <?php 
                                    $colIdx = 0;
                                    foreach ($consolidadoAula as $item): 
                                        $tdBg = ($colIdx % 2 === 0) ? 'style="background-color: #f0fdf4;"' : '';
                                        $colIdx++;
                                    ?>
                                        <td <?= $tdBg ?> class="fw-bold <?= $item['ad'] > 0 ? 'text-primary' : 'text-muted' ?>">
                                            <?= $item['ad'] ?>
                                        </td>
                                    <?php endforeach; ?>
                                    <td class="row-label-ad fs-6"><?= $totalGeneralAD ?></td>
                                </tr>

                                <!-- Fila A -->
                                <tr>
                                    <td class="row-label-a text-start ps-3">
                                        <span class="badge bg-success text-white me-1">A</span> Esperado
                                    </td>
                                    <?php 
                                    $colIdx = 0;
                                    foreach ($consolidadoAula as $item): 
                                        $tdBg = ($colIdx % 2 === 0) ? 'style="background-color: #f0fdf4;"' : '';
                                        $colIdx++;
                                    ?>
                                        <td <?= $tdBg ?> class="fw-bold <?= $item['a'] > 0 ? 'text-success' : 'text-muted' ?>">
                                            <?= $item['a'] ?>
                                        </td>
                                    <?php endforeach; ?>
                                    <td class="row-label-a fs-6"><?= $totalGeneralA ?></td>
                                </tr>

                                <!-- Fila B -->
                                <tr>
                                    <td class="row-label-b text-start ps-3">
                                        <span class="badge bg-warning text-dark me-1">B</span> En Proceso
                                    </td>
                                    <?php 
                                    $colIdx = 0;
                                    foreach ($consolidadoAula as $item): 
                                        $tdBg = ($colIdx % 2 === 0) ? 'style="background-color: #f0fdf4;"' : '';
                                        $colIdx++;
                                    ?>
                                        <td <?= $tdBg ?> class="fw-bold <?= $item['b'] > 0 ? 'text-warning-emphasis' : 'text-muted' ?>">
                                            <?= $item['b'] ?>
                                        </td>
                                    <?php endforeach; ?>
                                    <td class="row-label-b fs-6"><?= $totalGeneralB ?></td>
                                </tr>

                                <!-- Fila C -->
                                <tr>
                                    <td class="row-label-c text-start ps-3">
                                        <span class="badge bg-danger text-white me-1">C</span> En Inicio
                                    </td>
                                    <?php 
                                    $colIdx = 0;
                                    foreach ($consolidadoAula as $item): 
                                        $tdBg = ($colIdx % 2 === 0) ? 'style="background-color: #f0fdf4;"' : '';
                                        $colIdx++;
                                    ?>
                                        <td <?= $tdBg ?> class="fw-bold <?= $item['c'] > 0 ? 'text-danger' : 'text-muted' ?>">
                                            <?= $item['c'] ?>
                                        </td>
                                    <?php endforeach; ?>
                                    <td class="row-label-c fs-6"><?= $totalGeneralC ?></td>
                                </tr>

                                <!-- Fila Total Evaluados -->
                                <tr class="table-secondary">
                                    <td class="row-label-total text-start ps-3">TOTAL ALUMNOS</td>
                                    <?php foreach ($consolidadoAula as $item): ?>
                                        <td class="fw-bold text-dark">
                                            <?= $item['total'] ?>
                                        </td>
                                    <?php endforeach; ?>
                                    <td class="row-label-total fs-6 text-primary"><?= $totalGeneralEvaluados ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="card main-card p-5 text-center">
            <div class="py-4">
                <i class="bi bi-clipboard2-check text-primary" style="font-size: 3rem;"></i>
                <h4 class="fw-bold text-dark mt-3">Selecciona un Curso y un Periodo</h4>
                <p class="text-muted mx-auto" style="max-width: 480px;">Elige una asignatura y el periodo académico (bimestre/trimestre) en el panel superior para cargar la nómina de estudiantes y comenzar a subir sus calificaciones literales (C, B, A, AD).</p>
            </div>
        </div>
    <?php endif; ?>

        <!-- Inclusión de Modales Modulares -->
    <?php require __DIR__ . '/../../app/views/partials/modal_vaciar_calificaciones.php'; ?>
    <?php require __DIR__ . '/../../app/views/partials/modal_borrar_calificaciones_seleccionadas.php'; ?>

    </div>
</main>

    <!-- Datos para Chart.js y Script Modular -->
    <script id="chart-calificaciones-data" type="application/json">
    <?= json_encode([
        'labels' => $labelsGrafica,
        'dataAD' => $dataGraficaAD,
        'dataA' => $dataGraficaA,
        'dataB' => $dataGraficaB,
        'dataC' => $dataGraficaC,
        'tabActiva' => $tabActiva
    ], JSON_UNESCAPED_UNICODE) ?>
    </script>
    <script src="../assets/js/calificaciones.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>