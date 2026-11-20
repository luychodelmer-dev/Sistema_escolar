<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/header.php';

require_auth(['asistente', 'director', 'hiperadmin', 'administrador']);
require_permission('p_calificar');

// AJAX para cascading dropdowns
if (isset($_GET['ajax']) && in_array($_GET['ajax'], ['grados', 'secciones'], true)) {
    header('Content-Type: application/json; charset=utf-8');
    $parentId = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($_GET['ajax'] === 'grados') {
        if ($parentId !== false) {
            $optionsStmt = $pdo->prepare('SELECT id, nombre FROM grado WHERE nivel_id = :id AND activo = TRUE ORDER BY numero');
            $optionsStmt->execute(['id' => $parentId]);
            echo json_encode($optionsStmt->fetchAll(), JSON_UNESCAPED_UNICODE);
        } else {
            // Todos los niveles: devolver todos los grados con su nivel
            $optionsStmt = $pdo->query('
                SELECT g.id, (n.nombre || \' - \' || g.nombre) AS nombre 
                FROM grado g 
                JOIN nivel n ON n.id = g.nivel_id 
                WHERE g.activo = TRUE 
                ORDER BY n.id, g.numero
            ');
            echo json_encode($optionsStmt->fetchAll(), JSON_UNESCAPED_UNICODE);
        }
        exit;
    } else {
        if ($parentId === false) {
            exit('[]');
        }
        $optionsStmt = $pdo->prepare(
            'SELECT DISTINCT s.id, s.nombre
             FROM grado_seccion gs
             JOIN seccion s ON s.id = gs.seccion_id
             WHERE gs.grado_id = :id AND gs.activo = TRUE AND s.activo = TRUE
             ORDER BY s.nombre'
        );
        $optionsStmt->execute(['id' => $parentId]);
        echo json_encode($optionsStmt->fetchAll(), JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$niveles = $pdo->query('SELECT id, nombre FROM nivel ORDER BY id')->fetchAll();
$periodos = $pdo->query('SELECT id, nombre FROM periodo_academico WHERE activo = TRUE ORDER BY fecha_inicio ASC, id ASC')->fetchAll();
$gradosDb = $pdo->query("
    SELECT g.id AS grado_id, g.nombre AS grado_nombre, g.numero, n.id AS nivel_id, n.nombre AS nivel_nombre
    FROM grado g
    JOIN nivel n ON n.id = g.nivel_id
    WHERE g.activo = TRUE
    ORDER BY n.id, g.numero
")->fetchAll();

// Parámetros de filtro
$nivelId = filter_var($_GET['nivel_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
$gradoEntrada = $_GET['grado_id'] ?? null;
$gradoId = (is_string($gradoEntrada) || is_int($gradoEntrada)) && $gradoEntrada !== ''
    ? filter_var($gradoEntrada, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : false;
$gradoId = $gradoId !== false ? $gradoId : null;
$seccionId = filter_var($_GET['seccion_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
$periodoId = filter_var($_GET['periodo_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;

// Cargar por defecto Nivel Primaria en la primera visita limpia
if ($nivelId === null && !isset($_GET['nivel_id']) && empty($_GET['limpiar'])) {
    foreach ($gradosDb as $g) {
        if (mb_stripos($g['nivel_nombre'], 'primaria') !== false) {
            $nivelId = (int) $g['nivel_id'];
            break;
        }
    }
    if (!$nivelId && !empty($gradosDb)) {
        $nivelId = (int) $gradosDb[0]['nivel_id'];
    }
} elseif ($gradoId !== null && $nivelId === null) {
    foreach ($gradosDb as $g) {
        if ((int) $g['grado_id'] === $gradoId) {
            $nivelId = (int) $g['nivel_id'];
            break;
        }
    }
}

// Grados disponibles según nivel
$gradosDisponibles = [];
if ($nivelId !== null) {
    $gStmt = $pdo->prepare('SELECT id, nombre, numero FROM grado WHERE nivel_id = :nid AND activo = TRUE ORDER BY numero');
    $gStmt->execute(['nid' => $nivelId]);
    $gradosDisponibles = $gStmt->fetchAll();

    $gradoIdsDisponibles = array_map('intval', array_column($gradosDisponibles, 'id'));
    if ($gradoId !== null && !in_array($gradoId, $gradoIdsDisponibles, true)) {
        $gradoId = null; // Si no pertenece al nivel, seleccionar "Todos los grados"
    }
} else {
    // Si nivelId es null ("Todos los niveles"), cargar grados de todos los niveles
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
    $seccionId = null; // En "Todos los grados", la sección es global
}

// Periodo por defecto
$periodoIds = array_map('intval', array_column($periodos, 'id'));
if ($periodoId === null || !in_array($periodoId, $periodoIds, true)) {
    $periodoId = !empty($periodoIds) ? $periodoIds[0] : null;
}

// Información del Grado, Nivel y Periodo seleccionados
$gradoActualInfo = null;
if ($gradoId !== null) {
    foreach ($gradosDb as $g) {
        if ((int) $g['grado_id'] === $gradoId) {
            $gradoActualInfo = $g;
            break;
        }
    }
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

$seccionActualInfo = null;
if ($seccionId !== null) {
    foreach ($seccionesDisponibles as $s) {
        if ((int) $s['id'] === $seccionId) {
            $seccionActualInfo = $s;
            break;
        }
    }
}

$periodoActualInfo = null;
if ($periodoId !== null) {
    foreach ($periodos as $p) {
        if ((int) $p['id'] === $periodoId) {
            $periodoActualInfo = $p;
            break;
        }
    }
}

// 1. Obtener Cursos a mostrar
$cursosAula = [];
if ($gradoActualInfo) {
    // Para un grado específico
    $searchStr1 = $gradoActualInfo['nivel_nombre'] . ' - ' . $gradoActualInfo['grado_nombre'];
    $searchStr2 = '%' . $gradoActualInfo['nivel_nombre'] . '%' . $gradoActualInfo['grado_nombre'] . '%';
    $cStmt = $pdo->prepare('
        SELECT id, nombre, grado 
        FROM curso 
        WHERE activo = TRUE AND (grado ILIKE :s1 OR grado ILIKE :s2) 
        ORDER BY nombre
    ');
    $cStmt->execute(['s1' => $searchStr1, 's2' => $searchStr2]);
    $cursosAula = $cStmt->fetchAll();
} else {
    // Para "Todos los grados": mostrar las áreas curriculares distintivas
    if ($nivelActualNombre) {
        $cStmt = $pdo->prepare('
            SELECT DISTINCT TRIM(nombre) AS nombre 
            FROM curso 
            WHERE activo = TRUE AND grado ILIKE :pat 
            ORDER BY TRIM(nombre)
        ');
        $cStmt->execute(['pat' => '%' . $nivelActualNombre . '%']);
        $cursosAula = $cStmt->fetchAll();
    } else {
        $cursosAula = $pdo->query('
            SELECT DISTINCT TRIM(nombre) AS nombre 
            FROM curso 
            WHERE activo = TRUE 
            ORDER BY TRIM(nombre)
        ')->fetchAll();
    }
}

// 2. Conteo de Estudiantes Matriculados
$totalMatriculados = 0;
if ($gradoId !== null) {
    $sqlMat = "
        SELECT COUNT(m.id) 
        FROM matricula m
        JOIN grado_seccion gs ON gs.id = m.grado_seccion_id
        WHERE m.estado = 'Activa' AND gs.grado_id = :gid
    ";
    $paramsMat = ['gid' => $gradoId];
    if ($seccionId !== null) {
        $sqlMat .= " AND gs.seccion_id = :sec";
        $paramsMat['sec'] = $seccionId;
    }
    $stmtMat = $pdo->prepare($sqlMat);
    $stmtMat->execute($paramsMat);
    $totalMatriculados = (int) $stmtMat->fetchColumn();
} else {
    $sqlMat = "SELECT COUNT(m.id) FROM matricula m WHERE m.estado = 'Activa'";
    $paramsMat = [];
    if ($nivelId !== null) {
        $sqlMat .= " AND m.nivel_id = :nid";
        $paramsMat['nid'] = $nivelId;
    }
    $stmtMat = $pdo->prepare($sqlMat);
    $stmtMat->execute($paramsMat);
    $totalMatriculados = (int) $stmtMat->fetchColumn();
}

// 3. Conteo de Calificaciones por Curso (AD, A, B, C)
$notasPorCurso = [];
if ($periodoId !== null) {
    if ($gradoId !== null) {
        $sqlNotas = "
            SELECT 
                cal.curso_id,
                COUNT(CASE WHEN UPPER(TRIM(cal.nota)) = 'AD' THEN 1 END) AS count_ad,
                COUNT(CASE WHEN UPPER(TRIM(cal.nota)) = 'A' THEN 1 END) AS count_a,
                COUNT(CASE WHEN UPPER(TRIM(cal.nota)) = 'B' THEN 1 END) AS count_b,
                COUNT(CASE WHEN UPPER(TRIM(cal.nota)) = 'C' THEN 1 END) AS count_c,
                COUNT(cal.id) AS total_con_nota
            FROM calificacion cal
            JOIN matricula m ON m.id = cal.matricula_id
            JOIN grado_seccion gs ON gs.id = m.grado_seccion_id
            WHERE m.estado = 'Activa'
              AND gs.grado_id = :gid
              AND cal.periodo_id = :pid
        ";
        $paramsNotas = ['gid' => $gradoId, 'pid' => $periodoId];
        if ($seccionId !== null) {
            $sqlNotas .= " AND gs.seccion_id = :sec";
            $paramsNotas['sec'] = $seccionId;
        }
        $sqlNotas .= " GROUP BY cal.curso_id";

        $stmtNotas = $pdo->prepare($sqlNotas);
        $stmtNotas->execute($paramsNotas);
        $notasPorCurso = $stmtNotas->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
    } else {
        // En "Todos los grados", agrupar por nombre de área curricular
        $sqlNotas = "
            SELECT 
                TRIM(c.nombre) AS curso_nombre,
                COUNT(CASE WHEN UPPER(TRIM(cal.nota)) = 'AD' THEN 1 END) AS count_ad,
                COUNT(CASE WHEN UPPER(TRIM(cal.nota)) = 'A' THEN 1 END) AS count_a,
                COUNT(CASE WHEN UPPER(TRIM(cal.nota)) = 'B' THEN 1 END) AS count_b,
                COUNT(CASE WHEN UPPER(TRIM(cal.nota)) = 'C' THEN 1 END) AS count_c,
                COUNT(cal.id) AS total_con_nota
            FROM calificacion cal
            JOIN curso c ON c.id = cal.curso_id
            JOIN matricula m ON m.id = cal.matricula_id
            WHERE m.estado = 'Activa'
              AND cal.periodo_id = :pid
        ";
        $paramsNotas = ['pid' => $periodoId];
        if ($nivelId !== null) {
            $sqlNotas .= " AND m.nivel_id = :nid";
            $paramsNotas['nid'] = $nivelId;
        }
        $sqlNotas .= " GROUP BY TRIM(c.nombre)";

        $stmtNotas = $pdo->prepare($sqlNotas);
        $stmtNotas->execute($paramsNotas);
        $notasPorCurso = $stmtNotas->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
    }
}

// 4. Consolidado estructurado para Gráfica y Tabla
$matrizConsolidado = [];
$totalAD = 0;
$totalA = 0;
$totalB = 0;
$totalC = 0;
$totalEvaluaciones = 0;

$labelsCursos = [];
$dataAD = [];
$dataA = [];
$dataB = [];
$dataC = [];

foreach ($cursosAula as $c) {
    $nombreMat = trim((string)$c['nombre']);
    if ($gradoId !== null) {
        $cid = (int) ($c['id'] ?? 0);
        $data = $notasPorCurso[$cid] ?? null;
    } else {
        $cid = null;
        $data = $notasPorCurso[$nombreMat] ?? null;
    }

    if (!$data) {
        $data = [
            'count_ad' => 0,
            'count_a' => 0,
            'count_b' => 0,
            'count_c' => 0,
            'total_con_nota' => 0,
        ];
    }

    $ad = (int) $data['count_ad'];
    $a = (int) $data['count_a'];
    $b = (int) $data['count_b'];
    $cNota = (int) $data['count_c'];
    $subtotal = $ad + $a + $b + $cNota;

    $totalAD += $ad;
    $totalA += $a;
    $totalB += $b;
    $totalC += $cNota;
    $totalEvaluaciones += $subtotal;

    // Abreviar nombre para el gráfico si es muy largo
    $nombreCorto = $nombreMat;
    if (mb_strlen($nombreCorto) > 16) {
        $reemplazos = [
            'Educación Física' => 'ED. FÍSICA',
            'Educación Religiosa' => 'ED. RELIG.',
            'Ciencia y Tecnología' => 'CIENCIA Y TEC.',
            'Arte y Cultura' => 'ARTE Y CULT.',
            'Personal Social' => 'PERS. SOCIAL',
            'Ciencias Sociales' => 'CC. SOCIALES',
            'Desarrollo Personal, Ciudadanía y Cívica' => 'DPCC',
            'Educación para el Trabajo' => 'ED. TRABAJO',
            'Tutoría y Orientación Educativa' => 'TUTORÍA',
            'Competencias Transversales' => 'TRANSVERSAL',
        ];
        $nombreCorto = $reemplazos[$nombreCorto] ?? (mb_substr($nombreCorto, 0, 14) . '…');
    }

    $labelsCursos[] = $nombreCorto;
    $dataAD[] = $ad;
    $dataA[] = $a;
    $dataB[] = $b;
    $dataC[] = $cNota;

    $matrizConsolidado[] = [
        'id' => $cid,
        'nombre' => $nombreMat,
        'nombre_corto' => $nombreCorto,
        'ad' => $ad,
        'a' => $a,
        'b' => $b,
        'c' => $cNota,
        'total' => $subtotal,
    ];
}

$totalAprobados = $totalAD + $totalA + $totalB;
$pctAprobados = $totalEvaluaciones > 0 ? round(($totalAprobados / $totalEvaluaciones) * 100, 1) : 0;
$pctRiesgo = $totalEvaluaciones > 0 ? round(($totalC / $totalEvaluaciones) * 100, 1) : 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reporte Gráfico de Calificaciones - Sistema Escolar</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../assets/css/app.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2"></script>
    <link rel="stylesheet" href="../assets/css/reports-print.css">
    <link rel="stylesheet" href="../assets/css/reporte_calificaciones.css">
</head>
<body class="bg-light">
<?php render_app_header(panel_home_path(), 'logout.php'); ?>

<main class="container-fluid px-3 px-md-4 px-xl-5 py-4">
    <!-- Breadcrumb y Encabezado de Página -->
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4 no-print">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1 small">
                    <li class="breadcrumb-item"><a href="<?= htmlspecialchars(panel_home_path()) ?>" class="text-decoration-none text-muted"><i class="bi bi-house-door"></i> Inicio</a></li>
                    <li class="breadcrumb-item"><a href="calificaciones.php" class="text-decoration-none text-muted">Calificaciones</a></li>
                    <li class="breadcrumb-item active text-primary fw-semibold" aria-current="page">Reporte Gráfico Consolidado</li>
                </ol>
            </nav>
            <h1 class="h3 fw-bold mb-1 text-dark d-flex align-items-center gap-2">
                <i class="bi bi-graph-up-arrow text-primary"></i> Reporte Consolidado y Gráfica de Calificaciones
                <span class="badge bg-primary-subtle text-primary border border-primary-subtle fs-6 fw-semibold">Escala Literal CNEB</span>
            </h1>
            <p class="text-muted mb-0 small">Curvas estadísticas comparativas de logro (AD, A, B, C) por área curricular y matriz oficial de evaluación institucional.</p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <button class="btn btn-primary d-inline-flex align-items-center gap-2 px-3 py-2 fw-semibold shadow-sm" onclick="window.print()" style="border-radius: 10px;">
                <i class="bi bi-printer-fill"></i> Imprimir Reporte / Guardar PDF
            </button>
            <a href="calificaciones.php" class="btn btn-outline-secondary d-inline-flex align-items-center gap-1 px-3 py-2 fw-semibold" style="border-radius: 10px;">
                <i class="bi bi-arrow-left"></i> Volver a Registro
            </a>
        </div>
    </div>

    <!-- Encabezado Membretado Oficial para Modo Impresión -->
    <div class="d-none print-only mb-4 border-bottom pb-3">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h3 class="fw-bold mb-0 text-dark">I.E. DIVINO MAESTRO</h3>
                <span class="text-muted small">Sistema de Gestión Académica y Evaluación del Currículo Nacional</span>
            </div>
            <div class="text-end">
                <h5 class="fw-bold mb-0 text-primary">CONSOLIDADO ESTADÍSTICO DE CALIFICACIONES</h5>
                <small class="text-muted">Fecha de Emisión: <?= date('d/m/Y H:i') ?></small>
            </div>
        </div>
        <div class="mt-2 pt-2 border-top d-flex gap-4 small fw-semibold text-dark">
            <div><strong>Nivel:</strong> <?= htmlspecialchars($nivelActualNombre ?? 'Todos los Niveles') ?></div>
            <div><strong>Grado:</strong> <?= htmlspecialchars($gradoActualInfo['grado_nombre'] ?? 'Todos los Grados') ?></div>
            <div><strong>Sección:</strong> <?= $seccionActualInfo ? htmlspecialchars($seccionActualInfo['nombre']) : 'Todas las Secciones' ?></div>
            <div><strong>Periodo:</strong> <?= htmlspecialchars($periodoActualInfo['nombre'] ?? 'Todos') ?></div>
            <div><strong>Estudiantes Matriculados:</strong> <?= $totalMatriculados ?></div>
        </div>
    </div>

    <!-- Formulario de Filtros de Aula (Nivel, Grado, Sección) -->
    <form method="GET" class="card main-card mb-4 no-print" id="formFiltroReporte">
        <div class="card-body p-3 p-md-4">
            <div class="row g-3 align-items-end">
                <div class="col-12 col-sm-6 col-md-3">
                    <label class="form-label fw-semibold small text-muted" for="nivel_id">1. Nivel Educativo</label>
                    <select class="form-select fw-semibold" id="nivel_id" name="nivel_id" onchange="onNivelChange(this.value)">
                        <option value="" <?= $nivelId === null ? 'selected' : '' ?>>-- Todos los niveles --</option>
                        <?php foreach ($niveles as $niv): ?>
                            <option value="<?= (int) $niv['id'] ?>" <?= $nivelId === (int) $niv['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($niv['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-sm-6 col-md-3">
                    <label class="form-label fw-semibold small text-muted" for="grado_id">2. Grado</label>
                    <select class="form-select fw-semibold" id="grado_id" name="grado_id" onchange="this.form.submit()">
                        <option value="" <?= $gradoId === null ? 'selected' : '' ?>>-- Todos los grados --</option>
                        <?php foreach ($gradosDisponibles as $grd): ?>
                            <option value="<?= (int) $grd['id'] ?>" <?= $gradoId === (int) $grd['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($grd['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-sm-6 col-md-3">
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
                <div class="col-12 col-sm-6 col-md-3 d-flex gap-2">
                    <input type="hidden" name="periodo_id" id="hidden_periodo_id" value="<?= htmlspecialchars((string) $periodoId) ?>">
                    <button class="btn btn-primary w-100 fw-semibold" type="submit" style="min-height: 42px; border-radius: 9px;">
                        <i class="bi bi-arrow-repeat me-1"></i> Actualizar Reporte
                    </button>
                    <a href="reporte_calificaciones.php?limpiar=1" class="btn btn-outline-secondary fw-semibold px-3" style="min-height: 42px; border-radius: 9px;" title="Restablecer">
                        <i class="bi bi-x-lg"></i>
                    </a>
                </div>
            </div>
        </div>
    </form>

    <!-- Resumen Superior en Tarjetas KPIs -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="kpi-stat-card border-start border-4 border-primary">
                <span class="text-muted small text-uppercase fw-semibold d-block">Alumnos en Aula</span>
                <h3 class="fw-bold text-dark mb-0 mt-1"><?= $totalMatriculados ?></h3>
                <small class="text-muted"><?= count($cursosAula) ?> asignaturas evaluadas</small>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="kpi-stat-card border-start border-4 border-success">
                <span class="text-success small text-uppercase fw-bold d-block">Logro Esperado / Destacado</span>
                <h3 class="fw-bold text-success mb-0 mt-1"><?= $totalAD + $totalA ?></h3>
                <small class="text-muted"><?= $totalEvaluaciones > 0 ? round((($totalAD + $totalA) / $totalEvaluaciones) * 100, 1) : 0 ?>% de notas emitidas</small>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="kpi-stat-card border-start border-4 border-warning">
                <span class="text-warning-emphasis small text-uppercase fw-bold d-block">En Proceso (B)</span>
                <h3 class="fw-bold text-warning-emphasis mb-0 mt-1"><?= $totalB ?></h3>
                <small class="text-muted"><?= $totalEvaluaciones > 0 ? round(($totalB / $totalEvaluaciones) * 100, 1) : 0 ?>% de notas emitidas</small>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="kpi-stat-card border-start border-4 border-danger">
                <span class="text-danger small text-uppercase fw-bold d-block">En Inicio / Riesgo (C)</span>
                <h3 class="fw-bold text-danger mb-0 mt-1"><?= $totalC ?></h3>
                <small class="text-danger fw-semibold"><?= $pctRiesgo ?>% requiere refuerzo</small>
            </div>
        </div>
    </div>

    <!-- Contenedor Principal: Slicer de Periodo + Gráfica Multiserie (IDÉNTICO A EXCEL) -->
    <div class="card main-card mb-4">
        <div class="card-header bg-white py-3 px-4 border-bottom d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <h5 class="fw-bold text-dark mb-0 d-flex align-items-center gap-2">
                    <i class="bi bi-bezier2 text-primary"></i> 
                    Distribución de Calificaciones por Curso
                    <?php if ($gradoActualInfo): ?>
                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle fs-6 fw-semibold">
                            <?= htmlspecialchars($gradoActualInfo['nivel_nombre'] . ' - ' . $gradoActualInfo['grado_nombre']) ?>
                            <?= $seccionActualInfo ? ' - Sección "' . htmlspecialchars($seccionActualInfo['nombre']) . '"' : ' - Todas las Secciones' ?>
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
                <small class="text-muted">Comparativa de líneas de tendencia oficial: <strong>AD</strong> (Destacado), <strong>A</strong> (Esperado), <strong>B</strong> (En proceso) y <strong>C</strong> (En inicio).</small>
            </div>
            <div>
                <span class="badge bg-secondary-subtle text-secondary border px-3 py-1.5 fw-semibold" style="font-size: 0.88rem;">
                    <i class="bi bi-calendar-event me-1"></i> <?= htmlspecialchars($periodoActualInfo['nombre'] ?? 'Periodo') ?>
                </span>
            </div>
        </div>

        <div class="card-body p-4">
            <div class="row g-4 align-items-start">
                <!-- Slicer de Periodo / Bimestre (Estilo Excel a la izquierda) -->
                <div class="col-12 col-md-2 col-xl-2 no-print">
                    <div class="slicer-card">
                        <div class="slicer-header">
                            <span>bimestre</span>
                            <i class="bi bi-funnel-fill text-muted"></i>
                        </div>
                        <div class="p-0">
                            <?php 
                            $numBim = 1;
                            foreach ($periodos as $per): 
                                $isAct = $periodoId === (int) $per['id'];
                            ?>
                                <a href="?nivel_id=<?= $nivelId ?>&grado_id=<?= $gradoId ?>&seccion_id=<?= $seccionId ?>&periodo_id=<?= (int)$per['id'] ?>" 
                                   class="slicer-btn <?= $isAct ? 'active' : '' ?>">
                                    <?= $numBim ?> · <?= htmlspecialchars($per['nombre']) ?>
                                </a>
                            <?php 
                            $numBim++;
                            endforeach; 
                            ?>
                        </div>
                    </div>

                    <div class="alert alert-light border small text-muted mt-3 py-2 px-2.5">
                        <i class="bi bi-info-circle me-1 text-primary"></i>
                        Haz clic en cada bimestre para alternar la visualización del consolidado.
                    </div>
                </div>

                <!-- Canvas del Gráfico de Líneas Multiserie -->
                <div class="col-12 col-md-10 col-xl-10">
                    <div class="chart-wrapper position-relative" style="height: 380px;">
                        <canvas id="chartCalificacionesConsolidado"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Matriz de Datos Oficial (IDÉNTICA A LA TABLA INFERIOR DE EXCEL) -->
    <div class="card main-card mb-4 overflow-hidden">
        <div class="card-header bg-white py-3 px-4 border-bottom d-flex flex-wrap justify-content-between align-items-center gap-2">
            <h5 class="fw-bold text-dark mb-0 d-flex align-items-center gap-2">
                <i class="bi bi-table text-primary"></i> Matriz Consolidada de Logros por Asignatura
            </h5>
            <span class="text-muted small">Cifras oficiales de estudiantes por escala literal en el periodo</span>
        </div>
        
        <div class="table-responsive">
            <table class="table table-consolidado">
                <thead>
                    <tr>
                        <th rowspan="2" class="bg-light text-dark fw-bold" style="min-width: 140px; width: 140px;">NIVEL DE LOGRO</th>
                        <?php 
                        $colIdx = 0;
                        foreach ($matrizConsolidado as $item): 
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
                        foreach ($matrizConsolidado as $item): 
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
                        foreach ($matrizConsolidado as $item): 
                            $tdBg = ($colIdx % 2 === 0) ? 'style="background-color: #f0fdf4;"' : '';
                            $colIdx++;
                        ?>
                            <td <?= $tdBg ?> class="fw-bold <?= $item['ad'] > 0 ? 'text-primary' : 'text-muted' ?>">
                                <?= $item['ad'] ?>
                            </td>
                        <?php endforeach; ?>
                        <td class="row-label-ad fs-6"><?= $totalAD ?></td>
                    </tr>

                    <!-- Fila A -->
                    <tr>
                        <td class="row-label-a text-start ps-3">
                            <span class="badge bg-success text-white me-1">A</span> Esperado
                        </td>
                        <?php 
                        $colIdx = 0;
                        foreach ($matrizConsolidado as $item): 
                            $tdBg = ($colIdx % 2 === 0) ? 'style="background-color: #f0fdf4;"' : '';
                            $colIdx++;
                        ?>
                            <td <?= $tdBg ?> class="fw-bold <?= $item['a'] > 0 ? 'text-success' : 'text-muted' ?>">
                                <?= $item['a'] ?>
                            </td>
                        <?php endforeach; ?>
                        <td class="row-label-a fs-6"><?= $totalA ?></td>
                    </tr>

                    <!-- Fila B -->
                    <tr>
                        <td class="row-label-b text-start ps-3">
                            <span class="badge bg-warning text-dark me-1">B</span> En Proceso
                        </td>
                        <?php 
                        $colIdx = 0;
                        foreach ($matrizConsolidado as $item): 
                            $tdBg = ($colIdx % 2 === 0) ? 'style="background-color: #f0fdf4;"' : '';
                            $colIdx++;
                        ?>
                            <td <?= $tdBg ?> class="fw-bold <?= $item['b'] > 0 ? 'text-warning-emphasis' : 'text-muted' ?>">
                                <?= $item['b'] ?>
                            </td>
                        <?php endforeach; ?>
                        <td class="row-label-b fs-6"><?= $totalB ?></td>
                    </tr>

                    <!-- Fila C -->
                    <tr>
                        <td class="row-label-c text-start ps-3">
                            <span class="badge bg-danger text-white me-1">C</span> En Inicio
                        </td>
                        <?php 
                        $colIdx = 0;
                        foreach ($matrizConsolidado as $item): 
                            $tdBg = ($colIdx % 2 === 0) ? 'style="background-color: #f0fdf4;"' : '';
                            $colIdx++;
                        ?>
                            <td <?= $tdBg ?> class="fw-bold <?= $item['c'] > 0 ? 'text-danger' : 'text-muted' ?>">
                                <?= $item['c'] ?>
                            </td>
                        <?php endforeach; ?>
                        <td class="row-label-c fs-6"><?= $totalC ?></td>
                    </tr>

                    <!-- Fila Total Evaluados -->
                    <tr class="table-secondary">
                        <td class="row-label-total text-start ps-3">TOTAL ALUMNOS</td>
                        <?php foreach ($matrizConsolidado as $item): ?>
                            <td class="fw-bold text-dark">
                                <?= $item['total'] ?>
                            </td>
                        <?php endforeach; ?>
                        <td class="row-label-total fs-6 text-primary"><?= $totalEvaluaciones ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Firmas Oficiales para Impresión -->
    <div class="d-none print-only mt-5 pt-5">
        <div class="row text-center">
            <div class="col-4">
                <div class="border-top border-dark mx-4 pt-2">
                    <span class="fw-bold d-block small">Profesor / Tutor de Aula</span>
                    <span class="text-muted" style="font-size: 0.75rem;">Firma y Sello</span>
                </div>
            </div>
            <div class="col-4">
                <div class="border-top border-dark mx-4 pt-2">
                    <span class="fw-bold d-block small">Coordinación Académica</span>
                    <span class="text-muted" style="font-size: 0.75rem;">V°B° Pedagógico</span>
                </div>
            </div>
            <div class="col-4">
                <div class="border-top border-dark mx-4 pt-2">
                    <span class="fw-bold d-block small">Dirección General</span>
                    <span class="text-muted" style="font-size: 0.75rem;">I.E. Divino Maestro</span>
                </div>
            </div>
        </div>
    </div>
</main>

<script id="reporte-calificaciones-data" type="application/json">
    <?= json_encode([
        'labelsCursos' => $labelsCursos,
        'dataAD' => $dataAD,
        'dataA' => $dataA,
        'dataB' => $dataB,
        'dataC' => $dataC
    ], JSON_UNESCAPED_UNICODE) ?>
</script>
<script src="../assets/js/reporte_calificaciones.js" defer></script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
