<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/header.php';

require_auth(['asistente', 'director', 'hiperadmin', 'administrador']);
$csrfToken = $_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));
$puedeCambiarEstado = has_permission('p_estudiantes');
$puedeGestionarEstudiantes = has_permission('p_estudiantes');

try {
    $pdo->exec("
        DO $$
        BEGIN
            IF NOT EXISTS (
                SELECT 1 FROM pg_constraint 
                WHERE conname = 'estudiante_estado_academico_check' 
                  AND pg_get_constraintdef(oid) LIKE '%Recuperación%'
            ) THEN
                ALTER TABLE estudiante DROP CONSTRAINT IF EXISTS estudiante_estado_academico_check;
                ALTER TABLE estudiante ADD CONSTRAINT estudiante_estado_academico_check
                    CHECK (estado_academico IN ('Activo', 'Retirado', 'Trasladado', 'Promoción', 'Promovido', 'Repitente', 'Recuperación'));
            END IF;
        END $$;
    ");
} catch (Throwable) {
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'actualizar_estado') {
    require_permission('p_estudiantes');
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!is_string($submittedToken) || !hash_equals($csrfToken, $submittedToken)) {
        http_response_code(400);
        echo json_encode(['error' => 'Solicitud inválida. Recarga la página e inténtalo nuevamente.']);
        exit;
    }
    $studentId = filter_var($_POST['estudiante_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $academicStatus = $_POST['estado_academico'] ?? '';
    if ($studentId === false || !is_string($academicStatus) ||
        !in_array($academicStatus, ['Activo', 'Retirado', 'Trasladado', 'Promoción', 'Promovido', 'Repitente', 'Recuperación'], true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Estudiante o estado académico inválido.']);
        exit;
    }

    try {
        $pdo->beginTransaction();
        $updateStmt = $pdo->prepare(
            'UPDATE estudiante
             SET estado_academico = :estado, actualizado_en = CURRENT_TIMESTAMP
             WHERE id = :id
             RETURNING id'
        );
        $updateStmt->execute(['estado' => $academicStatus, 'id' => $studentId]);
        if (!$updateStmt->fetchColumn()) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(['error' => 'No se encontró al estudiante.']);
            exit;
        }
        registrar_auditoria('actualizar_estado_academico', 'Actualizó estado del estudiante ID ' . $studentId . ' a ' . $academicStatus . '.');
        $pdo->commit();
        echo json_encode(['ok' => true, 'estado_academico' => $academicStatus], JSON_UNESCAPED_UNICODE);
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Error al actualizar estado académico: ' . $exception->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'No se pudo actualizar el estado académico.']);
    }
    exit;
}

if (isset($_GET['ajax']) && in_array($_GET['ajax'], ['grados', 'secciones'], true)) {
    header('Content-Type: application/json; charset=utf-8');
    $parentId = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($parentId === false) {
        http_response_code(400);
        exit('[]');
    }

    if ($_GET['ajax'] === 'grados') {
        $optionsStmt = $pdo->prepare(
            'SELECT id, nombre FROM grado WHERE nivel_id = :id AND activo = TRUE ORDER BY numero'
        );
    } elseif ($_GET['ajax'] === 'secciones') {
        $optionsStmt = $pdo->prepare(
            'SELECT DISTINCT s.id, s.nombre
             FROM grado_seccion gs
             JOIN seccion s ON s.id = gs.seccion_id
             WHERE gs.grado_id = :id AND gs.activo = TRUE AND s.activo = TRUE
             ORDER BY s.nombre'
        );
    } else {
        http_response_code(400);
        exit('[]');
    }

    $optionsStmt->execute(['id' => $parentId]);
    echo json_encode($optionsStmt->fetchAll(), JSON_UNESCAPED_UNICODE);
    exit;
}

$getFilterId = static function (string $key): ?int {
    $value = filter_var($_GET[$key] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $value === false ? null : $value;
};
$busqueda = trim($_GET['q'] ?? '');
$academicStatuses = ['Activo', 'Promovido', 'Repitente', 'Promoción', 'Recuperación', 'Retirado', 'Trasladado'];
$estadoAcademico = $_GET['estado'] ?? 'Activo';
if (!is_string($estadoAcademico) || !in_array($estadoAcademico, $academicStatuses, true)) {
    $estadoAcademico = 'Activo';
}
$nivelId = $getFilterId('nivel');
$gradoId = $getFilterId('grado');
$seccionId = $getFilterId('seccion');
$niveles = $pdo->query('SELECT id, nombre FROM nivel ORDER BY id')->fetchAll();

// KPIs & Métricas Generales de Estudiantes
$totalEstudiantes = (int)$pdo->query("SELECT count(*) FROM estudiante")->fetchColumn();
$activosEstudiantes = (int)$pdo->query("SELECT count(*) FROM estudiante WHERE estado_academico = 'Activo'")->fetchColumn();
$inactivosEstudiantes = (int)$pdo->query("SELECT count(*) FROM estudiante WHERE estado_academico IN ('Retirado', 'Trasladado')")->fetchColumn();
$matriculados2026 = (int)$pdo->query("
    SELECT count(DISTINCT m.estudiante_id) 
    FROM matricula m 
    JOIN anio a ON a.id = m.anio_id 
    WHERE a.anio = 2026 AND m.estado = 'Activa'
")->fetchColumn();

$pctActivos = $totalEstudiantes > 0 ? round(($activosEstudiantes / $totalEstudiantes) * 100, 1) : 0;
$pctMatriculados = $totalEstudiantes > 0 ? round(($matriculados2026 / $totalEstudiantes) * 100, 1) : 0;
$pctInactivos = $totalEstudiantes > 0 ? round(($inactivosEstudiantes / $totalEstudiantes) * 100, 1) : 0;

// Distribución por Nivel Educativo
$porNivel = ['Inicial' => 0, 'Primaria' => 0, 'Secundaria' => 0, 'Sin Asignar' => 0];
$nivelCounts = $pdo->query("
    SELECT n.nombre AS nivel, count(DISTINCT m.estudiante_id) AS total
    FROM matricula m
    JOIN nivel n ON n.id = m.nivel_id
    JOIN anio a ON a.id = m.anio_id
    JOIN estudiante e ON e.id = m.estudiante_id
    WHERE a.anio = 2026 AND m.estado = 'Activa'
    GROUP BY n.nombre
")->fetchAll();

foreach ($nivelCounts as $nc) {
    $nName = mb_strtolower($nc['nivel'], 'UTF-8');
    if (str_contains($nName, 'inic')) {
        $porNivel['Inicial'] += (int)$nc['total'];
    } elseif (str_contains($nName, 'prim')) {
        $porNivel['Primaria'] += (int)$nc['total'];
    } elseif (str_contains($nName, 'secu')) {
        $porNivel['Secundaria'] += (int)$nc['total'];
    } else {
        $porNivel['Sin Asignar'] += (int)$nc['total'];
    }
}
$sumNiveles = $porNivel['Inicial'] + $porNivel['Primaria'] + $porNivel['Secundaria'];
$porNivel['Sin Asignar'] = max(0, $totalEstudiantes - $sumNiveles);
$totalDistribucion = max(1, $totalEstudiantes);
$pctInicial = round(($porNivel['Inicial'] / $totalDistribucion) * 100, 1);
$pctPrimaria = round(($porNivel['Primaria'] / $totalDistribucion) * 100, 1);
$pctSecundaria = round(($porNivel['Secundaria'] / $totalDistribucion) * 100, 1);
$pctSinAsignar = round(($porNivel['Sin Asignar'] / $totalDistribucion) * 100, 1);

$conditions = [];
$params = [];
$conditions[] = 'al.estado_academico = :estado_academico';
$params['estado_academico'] = $estadoAcademico;
if ($busqueda !== '') {
    $conditions[] = "(
        CONCAT_WS(' ', al.dni, al.nombres, al.apellido_paterno, al.apellido_materno) ILIKE :busqueda
        OR CONCAT_WS(' ', al.apellido_paterno, al.apellido_materno, al.nombres) ILIKE :busqueda
        OR EXISTS (
            SELECT 1 FROM estudiante_responsable er
            JOIN responsable r ON r.id = er.responsable_id
            WHERE er.estudiante_id = al.id
              AND (
                  r.dni ILIKE :busqueda
                  OR r.nombres ILIKE :busqueda
                  OR r.apellido_paterno ILIKE :busqueda
                  OR r.apellido_materno ILIKE :busqueda
                  OR CONCAT_WS(' ', r.nombres, r.apellido_paterno, r.apellido_materno) ILIKE :busqueda
                  OR CONCAT_WS(' ', r.apellido_paterno, r.apellido_materno, r.nombres) ILIKE :busqueda
              )
        )
    )";
    $params['busqueda'] = '%' . $busqueda . '%';
}
if ($nivelId !== null) {
    $conditions[] = 'm.nivel_id = :nivel_id';
    $params['nivel_id'] = $nivelId;
}
if ($gradoId !== null) {
    $conditions[] = 'g.id = :grado_id';
    $params['grado_id'] = $gradoId;
}
if ($seccionId !== null) {
    $conditions[] = 's.id = :seccion_id';
    $params['seccion_id'] = $seccionId;
}
$whereSql = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

$paginaActual = max(1, (int) ($_GET['pagina'] ?? 1));
$porPagina = 50;

$countSql = "SELECT count(*) FROM (
    SELECT DISTINCT ON (al.id) al.id
    FROM matricula m
    JOIN estudiante al ON al.id = m.estudiante_id
    JOIN nivel n ON n.id = m.nivel_id
    JOIN grado_seccion gs ON gs.id = m.grado_seccion_id
    JOIN grado g ON g.id = gs.grado_id
    JOIN seccion s ON s.id = gs.seccion_id
    JOIN anio an ON an.id = m.anio_id
    $whereSql
) sub_count";
$stmtCount = $pdo->prepare($countSql);
$stmtCount->execute($params);
$totalFiltrados = (int) $stmtCount->fetchColumn();

$totalPaginas = max(1, (int) ceil($totalFiltrados / $porPagina));
if ($paginaActual > $totalPaginas) {
    $paginaActual = $totalPaginas;
}
$offset = ($paginaActual - 1) * $porPagina;

$sql = "SELECT * FROM (
            SELECT DISTINCT ON (al.id)
                al.id, al.dni, al.nombres, al.apellido_paterno, al.apellido_materno,
                al.estado_academico, n.nombre AS nivel, g.nombre AS grado, s.nombre AS seccion,
                an.anio, m.estado
            FROM matricula m
            JOIN estudiante al ON al.id = m.estudiante_id
            JOIN nivel n ON n.id = m.nivel_id
            JOIN grado_seccion gs ON gs.id = m.grado_seccion_id
            JOIN grado g ON g.id = gs.grado_id
            JOIN seccion s ON s.id = gs.seccion_id
            JOIN anio an ON an.id = m.anio_id
            $whereSql
            ORDER BY al.id, an.anio DESC NULLS LAST, m.id DESC
        ) sub
        ORDER BY LOWER(TRIM(sub.apellido_paterno)) ASC,
                 LOWER(TRIM(COALESCE(sub.apellido_materno, ''))) ASC,
                 LOWER(TRIM(sub.nombres)) ASC
        LIMIT :limite OFFSET :offset";
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':limite', $porPagina, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$estudiantes = $stmt->fetchAll();
$renderEstudianteRows = static function (array $rows, int $offset = 0) use ($puedeCambiarEstado, $puedeGestionarEstudiantes, $csrfToken): void {
    if (!$rows) {
        $columnCount = 7 + (int) $puedeCambiarEstado + (int) $puedeGestionarEstudiantes;
        echo '<tr><td colspan="' . $columnCount . '" class="text-center py-5">
            <div class="py-4">
                <i class="bi bi-search text-muted" style="font-size: 2.2rem;"></i>
                <h5 class="mt-3 text-secondary fw-semibold">No se encontraron estudiantes</h5>
                <p class="text-muted small mb-0">Intenta con otro término de búsqueda o cambia los filtros de nivel o estado.</p>
            </div>
        </td></tr>';
        return;
    }
    $secuencia = $offset + 1;
    $avatarColors = [
        ['bg' => '#eff6ff', 'text' => '#1d4ed8'],
        ['bg' => '#f0fdf4', 'text' => '#15803d'],
        ['bg' => '#fdf2f8', 'text' => '#be185d'],
        ['bg' => '#fefce8', 'text' => '#a16207'],
        ['bg' => '#f5f3ff', 'text' => '#6d28d9'],
        ['bg' => '#ecfeff', 'text' => '#0e7490']
    ];
    foreach ($rows as $estudiante) {
        $inicial1 = mb_substr(trim($estudiante['apellido_paterno'] ?? ''), 0, 1);
        $inicial2 = mb_substr(trim($estudiante['nombres'] ?? ''), 0, 1);
        $iniciales = mb_strtoupper($inicial1 . $inicial2);
        if ($iniciales === '') $iniciales = 'AL';
        $col = $avatarColors[$estudiante['id'] % count($avatarColors)];
        $avatarStyle = 'background-color:' . $col['bg'] . ';color:' . $col['text'] . ';';

        $nivLow = mb_strtolower($estudiante['nivel'] ?? '', 'UTF-8');
        $dotCol = str_contains($nivLow, 'inic') ? '#eab308' : (str_contains($nivLow, 'prim') ? '#ef4444' : (str_contains($nivLow, 'secu') ? '#2563eb' : '#94a3b8'));

        $nombreCompleto = $estudiante['apellido_paterno'] . ' ' . $estudiante['apellido_materno'] . ', ' . $estudiante['nombres'];

        echo '<tr data-student-row="1">';
        echo '<td class="text-center"><span class="badge-secuencia">#' . $secuencia++ . '</span></td>';
        echo '<td><span class="font-monospace fw-bold text-dark" style="font-size: 0.96rem;">' . htmlspecialchars($estudiante['dni']) . '</span></td>';
        echo '<td>
                <div class="d-flex align-items-center gap-2">
                    <div class="avatar-circle" style="' . $avatarStyle . '">' . htmlspecialchars($iniciales) . '</div>
                    <div style="min-width: 0; max-width: 280px;">
                        <a href="ver_estudiante.php?id=' . (int) $estudiante['id'] . '" class="student-link d-inline-flex align-items-center gap-1 text-decoration-none">
                            <i class="bi bi-mortarboard text-secondary" style="font-size: 0.95rem;"></i> <span style="word-break: break-word;">' . htmlspecialchars($nombreCompleto) . '</span>
                        </a>
                    </div>
                </div>
              </td>';
        echo '<td>
                <div class="fw-semibold text-dark d-flex align-items-center gap-1.5" style="font-size: 0.95rem;">
                    <span class="d-inline-block rounded-circle flex-shrink-0" style="width: 8px; height: 8px; background-color: ' . $dotCol . ';"></span>
                    <span>' . htmlspecialchars($estudiante['nivel'] . ' ' . $estudiante['grado'] . ' "' . $estudiante['seccion'] . '"') . '</span>
                </div>
              </td>';
        echo '<td><span class="badge bg-light text-secondary border px-2 py-1" style="font-size: 0.85rem;"><i class="bi bi-calendar-check me-1"></i>' . htmlspecialchars((string) $estudiante['anio']) . '</span></td>';
        
        $estado = $estudiante['estado_academico'];
        if ($estado === 'Activo') {
            echo '<td><span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1" style="font-size: 0.84rem;"><i class="bi bi-check-circle-fill me-1"></i>Activo</span></td>';
        } elseif ($estado === 'Promovido') {
            echo '<td><span class="badge text-white border px-2 py-1" style="font-size: 0.84rem; background-color: #059669; border-color: #047857;"><i class="bi bi-check2-circle me-1"></i>Promovido</span></td>';
        } elseif ($estado === 'Repitente') {
            echo '<td><span class="badge text-white border px-2 py-1" style="font-size: 0.84rem; background-color: #dc2626; border-color: #b91c1c;"><i class="bi bi-arrow-repeat me-1"></i>Repitente</span></td>';
        } elseif ($estado === 'Promoción') {
            echo '<td><span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1" style="font-size: 0.84rem;"><i class="bi bi-mortarboard-fill me-1"></i>Promoción</span></td>';
        } elseif ($estado === 'Recuperación') {
            echo '<td><span class="badge text-dark border px-2 py-1" style="font-size: 0.84rem; background-color: #fef08a; border-color: #fde047;"><i class="bi bi-clock-history me-1"></i>Recuperación</span></td>';
        } elseif ($estado === 'Retirado') {
            echo '<td><span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1" style="font-size: 0.84rem;"><i class="bi bi-x-circle-fill me-1"></i>Retirado</span></td>';
        } else {
            echo '<td><span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-2 py-1" style="font-size: 0.84rem;"><i class="bi bi-arrow-left-right me-1"></i>Trasladado</span></td>';
        }

        if (mb_strtolower($estudiante['estado'] ?? '') === 'activa') {
            echo '<td><span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1" style="font-size: 0.84rem;"><i class="bi bi-check2 me-1"></i>Activa</span></td>';
        } else {
            echo '<td><span class="badge bg-light text-muted border px-2 py-1" style="font-size: 0.84rem;">' . htmlspecialchars($estudiante['estado'] ?? '-') . '</span></td>';
        }

        if ($puedeCambiarEstado) {
            echo '<td><form class="academic-status-form d-flex gap-1" method="POST">'
                . '<input type="hidden" name="action" value="actualizar_estado">'
                . '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') . '">'
                . '<input type="hidden" name="estudiante_id" value="' . (int) $estudiante['id'] . '">'
                . '<select class="form-select form-select-sm" name="estado_academico" aria-label="Estado académico" style="font-size: 0.88rem; min-width: 130px;">'
                . '<option value="Activo"' . ($estudiante['estado_academico'] === 'Activo' ? ' selected' : '') . '>Activo</option>'
                . '<option value="Promovido"' . ($estudiante['estado_academico'] === 'Promovido' ? ' selected' : '') . '>Promovido</option>'
                . '<option value="Repitente"' . ($estudiante['estado_academico'] === 'Repitente' ? ' selected' : '') . '>Repitente</option>'
                . '<option value="Promoción"' . ($estudiante['estado_academico'] === 'Promoción' ? ' selected' : '') . '>Promoción</option>'
                . '<option value="Recuperación"' . ($estudiante['estado_academico'] === 'Recuperación' ? ' selected' : '') . '>Recuperación</option>'
                . '<option value="Retirado"' . ($estudiante['estado_academico'] === 'Retirado' ? ' selected' : '') . '>Retirado</option>'
                . '<option value="Trasladado"' . ($estudiante['estado_academico'] === 'Trasladado' ? ' selected' : '') . '>Trasladado</option>'
                . '</select><button class="btn btn-sm btn-outline-primary d-inline-flex align-items-center gap-1" type="submit" title="Actualizar estado" style="font-size: 0.88rem;"><i class="bi bi-arrow-repeat"></i></button></form></td>';
        }
        if ($puedeGestionarEstudiantes) {
            echo '<td class="text-end text-nowrap">'
                . '<a class="btn btn-sm btn-outline-primary d-inline-flex align-items-center gap-1 me-1" style="font-size: 0.88rem; padding: 0.35rem 0.65rem;" href="ficha_matricula.php?estudiante_id=' . (int) $estudiante['id'] . '" target="_blank" rel="opener" title="Imprimir Ficha de Matrícula"><i class="bi bi-printer"></i></a>'
                . '<a class="btn btn-sm btn-outline-info d-inline-flex align-items-center gap-1 me-1" style="font-size: 0.88rem; padding: 0.35rem 0.65rem;" href="ver_estudiante.php?id=' . (int) $estudiante['id'] . '" title="Ver expediente"><i class="bi bi-eye"></i></a>'
                . '<a class="btn btn-sm btn-outline-primary d-inline-flex align-items-center gap-1 me-1" style="font-size: 0.88rem; padding: 0.35rem 0.65rem;" href="editar_estudiante.php?id=' . (int) $estudiante['id'] . '"><i class="bi bi-pencil-square"></i> Editar</a>'
                . '<form class="student-delete-form d-inline" method="POST" action="eliminar_estudiante.php" onsubmit="return confirm(\'¿Eliminar este estudiante? Si tiene historial vinculado, no se eliminará.\')">'
                . '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') . '">'
                . '<input type="hidden" name="estudiante_id" value="' . (int) $estudiante['id'] . '">'
                . '<button class="btn btn-sm btn-outline-danger d-inline-flex align-items-center gap-1" style="font-size: 0.88rem; padding: 0.35rem 0.65rem;" type="submit" title="Eliminar"><i class="bi bi-trash"></i></button></form></td>';
        }
        echo '</tr>';
    }
};
if (($_GET['ajax'] ?? '') === 'tabla') {
    header('Content-Type: text/html; charset=utf-8');
    header('X-Total-Operaciones: ' . $totalFiltrados);
    $renderEstudianteRows($estudiantes, $offset);
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Estudiantes - Sistema Escolar</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../assets/css/app.css">
    <link rel="stylesheet" href="../assets/css/estudiantes.css">
</head>
<body>
<?php render_app_header(panel_home_path(), 'logout.php'); ?>
<main class="container-fluid px-3 px-md-4 px-xl-5 py-4">
    <!-- Encabezado y Breadcrumbs -->
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1 small">
                    <li class="breadcrumb-item"><a href="<?= htmlspecialchars(panel_home_path()) ?>" class="text-decoration-none text-muted"><i class="bi bi-house-door"></i> Inicio</a></li>
                    <li class="breadcrumb-item active text-primary fw-semibold" aria-current="page">Estudiantes</li>
                </ol>
            </nav>
            <h1 class="h3 fw-bold mb-1 text-dark d-flex align-items-center gap-2">
                Directorio de Estudiantes
                <span class="badge bg-primary-subtle text-primary border border-primary-subtle fs-6 fw-semibold">Padrón 2026</span>
            </h1>
            <p class="text-muted mb-0 small">Consulta y administración por matrícula, nivel y sección académica institucional.</p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <a href="nomina_matriculas.php" class="btn btn-outline-primary d-inline-flex align-items-center gap-2 px-3 py-2 fw-semibold" style="border-radius: 10px;">
                <i class="bi bi-printer"></i> Nómina y Reportes
            </a>
            <a href="matriculas.php" class="btn btn-primary d-inline-flex align-items-center gap-2 shadow-sm px-3 py-2 fw-semibold" style="border-radius: 10px;">
                <i class="bi bi-person-plus-fill"></i> + Registrar Matrícula
            </a>
            <a href="<?= htmlspecialchars(panel_home_path()) ?>" class="btn btn-outline-secondary d-inline-flex align-items-center gap-2 px-3 py-2" style="border-radius: 10px;">
                <i class="bi bi-arrow-left"></i> Volver al panel
            </a>
        </div>
    </div>

    <?php if (isset($_GET['actualizado'])): ?>
        <div class="alert alert-success d-flex align-items-center gap-2 mb-3 shadow-sm border-0 alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle-fill fs-5"></i>
            <div>Estudiante actualizado correctamente.</div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (($_GET['error'] ?? '') === 'not_found'): ?>
        <div class="alert alert-warning d-flex align-items-center gap-2 mb-3 shadow-sm border-0 alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill fs-5"></i>
            <div>No se encontró el estudiante solicitado o fue eliminado.</div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- KPI Cards Métricas Gráficas -->
    <div class="row g-3 mb-4">
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="kpi-card h-100 d-flex flex-column justify-content-between">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted text-uppercase fw-semibold" style="font-size: 0.85rem; letter-spacing: 0.03em;">Total Estudiantes</span>
                        <h2 class="display-6 fw-bold text-dark mt-1 mb-0" style="font-size: 1.85rem;"><?= number_format($totalEstudiantes) ?></h2>
                    </div>
                    <div class="kpi-icon" style="background-color: #eff6ff; color: #2563eb;">
                        <i class="bi bi-mortarboard-fill"></i>
                    </div>
                </div>
                <div class="mt-3 pt-2 border-top d-flex align-items-center justify-content-between">
                    <span class="text-muted" style="font-size: 0.88rem;">Base de datos escolar</span>
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle" style="font-size: 0.84rem; padding: 0.35rem 0.65rem;">100% Alumnos</span>
                </div>
            </div>
        </div>

        <div class="col-12 col-sm-6 col-xl-3">
            <div class="kpi-card h-100 d-flex flex-column justify-content-between">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted text-uppercase fw-semibold" style="font-size: 0.85rem; letter-spacing: 0.03em;">Estudiantes Activos</span>
                        <h2 class="display-6 fw-bold text-success mt-1 mb-0" style="font-size: 1.85rem;"><?= number_format($activosEstudiantes) ?></h2>
                    </div>
                    <div class="kpi-icon" style="background-color: #ecfdf5; color: #059669;">
                        <i class="bi bi-person-check-fill"></i>
                    </div>
                </div>
                <div class="mt-3 pt-2 border-top d-flex align-items-center justify-content-between">
                    <span class="text-muted" style="font-size: 0.88rem;"><?= $pctActivos ?>% del total</span>
                    <span class="badge bg-success-subtle text-success border border-success-subtle" style="font-size: 0.84rem; padding: 0.35rem 0.65rem;">Vigentes</span>
                </div>
            </div>
        </div>

        <div class="col-12 col-sm-6 col-xl-3">
            <div class="kpi-card h-100 d-flex flex-column justify-content-between">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted text-uppercase fw-semibold" style="font-size: 0.85rem; letter-spacing: 0.03em;">Con Matrícula 2026</span>
                        <h2 class="display-6 fw-bold text-primary mt-1 mb-0" style="font-size: 1.85rem;"><?= number_format($matriculados2026) ?></h2>
                    </div>
                    <div class="kpi-icon" style="background-color: #f0f9ff; color: #0284c7;">
                        <i class="bi bi-journal-check"></i>
                    </div>
                </div>
                <div class="mt-3 pt-2 border-top d-flex align-items-center justify-content-between">
                    <span class="text-muted" style="font-size: 0.88rem;"><?= $pctMatriculados ?>% del total</span>
                    <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle" style="font-size: 0.84rem; padding: 0.35rem 0.65rem;">Matriculados</span>
                </div>
            </div>
        </div>

        <div class="col-12 col-sm-6 col-xl-3">
            <div class="kpi-card h-100 d-flex flex-column justify-content-between">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted text-uppercase fw-semibold" style="font-size: 0.85rem; letter-spacing: 0.03em;">Inactivos / Retirados</span>
                        <h2 class="display-6 fw-bold text-warning-emphasis mt-1 mb-0" style="font-size: 1.85rem;"><?= number_format($inactivosEstudiantes) ?></h2>
                    </div>
                    <div class="kpi-icon" style="background-color: #fffbeb; color: #d97706;">
                        <i class="bi bi-person-dash-fill"></i>
                    </div>
                </div>
                <div class="mt-3 pt-2 border-top d-flex align-items-center justify-content-between">
                    <span class="text-muted" style="font-size: 0.88rem;"><?= $pctInactivos ?>% inactivos</span>
                    <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle" style="font-size: 0.84rem; padding: 0.35rem 0.65rem;">Retirados / Trasl.</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Distribución Gráfica por Niveles -->
    <div class="distribution-card mb-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
            <div class="d-flex align-items-center gap-2">
                <i class="bi bi-bar-chart-fill text-primary"></i>
                <span class="fw-bold text-dark text-uppercase" style="font-size: 0.88rem; letter-spacing: 0.03em;">Distribución de Estudiantes por Nivel Educativo</span>
            </div>
            <div class="d-flex flex-wrap gap-3" style="font-size: 0.92rem;">
                <span class="d-flex align-items-center gap-1 text-muted">
                    <span class="d-inline-block rounded-circle" style="width: 10px; height: 10px; background-color: #eab308;"></span>
                    Inicial: <strong class="text-dark"><?= $porNivel['Inicial'] ?></strong> (<?= $pctInicial ?>%)
                </span>
                <span class="d-flex align-items-center gap-1 text-muted">
                    <span class="d-inline-block rounded-circle" style="width: 10px; height: 10px; background-color: #ef4444;"></span>
                    Primaria: <strong class="text-dark"><?= $porNivel['Primaria'] ?></strong> (<?= $pctPrimaria ?>%)
                </span>
                <span class="d-flex align-items-center gap-1 text-muted">
                    <span class="d-inline-block rounded-circle" style="width: 10px; height: 10px; background-color: #2563eb;"></span>
                    Secundaria: <strong class="text-dark"><?= $porNivel['Secundaria'] ?></strong> (<?= $pctSecundaria ?>%)
                </span>
                <?php if ($porNivel['Sin Asignar'] > 0): ?>
                <span class="d-flex align-items-center gap-1 text-muted">
                    <span class="d-inline-block rounded-circle" style="width: 10px; height: 10px; background-color: #cbd5e1;"></span>
                    Sin asignar: <strong class="text-dark"><?= $porNivel['Sin Asignar'] ?></strong> (<?= $pctSinAsignar ?>%)
                </span>
                <?php endif; ?>
            </div>
        </div>
        <div class="progress" style="height: 10px; border-radius: 6px; background-color: #f1f5f9;">
            <div class="progress-bar" style="background-color: #eab308; width: <?= $pctInicial ?>%;" title="Inicial: <?= $porNivel['Inicial'] ?>"></div>
            <div class="progress-bar" style="background-color: #ef4444; width: <?= $pctPrimaria ?>%;" title="Primaria: <?= $porNivel['Primaria'] ?>"></div>
            <div class="progress-bar" style="background-color: #2563eb; width: <?= $pctSecundaria ?>%;" title="Secundaria: <?= $porNivel['Secundaria'] ?>"></div>
            <div class="progress-bar" style="background-color: #cbd5e1; width: <?= $pctSinAsignar ?>%;" title="Sin Asignar: <?= $porNivel['Sin Asignar'] ?>"></div>
        </div>
    </div>

    <!-- Barra de Búsqueda y Filtros -->
    <div class="filter-card p-3 mb-4">
        <!-- Pestañas de Estado Académico con Iconografía -->
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3 pb-2 border-bottom">
            <ul class="nav nav-pills gap-1" id="academic-status-tabs" role="tablist">
                <?php foreach ([
                    'Activo' => ['Activos', 'bi-person-check-fill'],
                    'Promovido' => ['Promovidos', 'bi-check2-circle'],
                    'Repitente' => ['Repitentes', 'bi-arrow-repeat'],
                    'Promoción' => ['Promoción', 'bi-mortarboard-fill'],
                    'Retirado' => ['Retirados', 'bi-person-x-fill'],
                    'Trasladado' => ['Trasladados', 'bi-arrow-left-right']
                ] as $status => [$label, $icon]): ?>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link <?= $estadoAcademico === $status ? 'active' : '' ?> d-inline-flex align-items-center gap-1 px-3 py-1.5 fw-semibold small" type="button" role="tab" data-academic-status="<?= htmlspecialchars($status) ?>" aria-selected="<?= $estadoAcademico === $status ? 'true' : 'false' ?>" style="border-radius: 8px;">
                            <i class="bi <?= $icon ?>"></i> <?= htmlspecialchars($label) ?>
                        </button>
                    </li>
                <?php endforeach; ?>
            </ul>
            <span class="small text-muted"><i class="bi bi-funnel me-1"></i>Filtros de consulta rápida</span>
        </div>

        <form id="student-filters" class="row g-2 align-items-center">
            <input type="hidden" name="pagina" value="1">
            <input type="hidden" name="estado" id="filtro-estado-academico" value="<?= htmlspecialchars($estadoAcademico) ?>">
            <div class="col-lg-4 col-md-12">
                <div class="input-group">
                    <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-search"></i></span>
                    <input class="form-control border-start-0 ps-0" id="filtro-busqueda" type="search" name="q" placeholder="Buscar por DNI o nombres de estudiante, padre o apoderado..." value="<?= htmlspecialchars($busqueda) ?>">
                </div>
            </div>
            <div class="col-sm-4 col-lg-3">
                <label class="visually-hidden" for="filtro-nivel">Nivel</label>
                <select id="filtro-nivel" name="nivel" class="form-select">
                    <option value="">Todos los niveles</option>
                    <?php foreach ($niveles as $nivel): ?>
                        <option value="<?= (int) $nivel['id'] ?>" <?= $nivelId === (int) $nivel['id'] ? 'selected' : '' ?>><?= htmlspecialchars($nivel['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-4 col-lg-2">
                <label class="visually-hidden" for="filtro-grado">Grado</label>
                <select id="filtro-grado" name="grado" class="form-select" <?= $nivelId === null ? 'disabled' : '' ?>>
                    <option value="">Todos los grados</option>
                </select>
            </div>
            <div class="col-sm-4 col-lg-2">
                <label class="visually-hidden" for="filtro-seccion">Sección</label>
                <select id="filtro-seccion" name="seccion" class="form-select" <?= $gradoId === null ? 'disabled' : '' ?>>
                    <option value="">Todas las secciones</option>
                </select>
            </div>
            <div class="col-lg-1 col-md-12">
                <button class="btn btn-outline-secondary w-100 d-inline-flex align-items-center justify-content-center gap-1" type="reset">
                    <i class="bi bi-arrow-counterclockwise"></i> Limpiar
                </button>
            </div>
        </form>
    </div>

    <!-- Indicador de Resultados y Tabla Moderna -->
    <div class="d-flex justify-content-between align-items-center mb-2 px-1">
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-primary fs-6 px-3 py-2" id="students-result-count" aria-live="polite"><?= number_format($totalFiltrados) ?> <?= $totalFiltrados === 1 ? 'estudiante' : 'estudiantes' ?></span>
            <span class="small text-muted" id="students-loading" hidden><i class="bi bi-arrow-repeat spin me-1"></i>Actualizando…</span>
        </div>
        <span class="small text-muted"><i class="bi bi-info-circle me-1"></i>Listado en tiempo real</span>
    </div>

    <div class="table-card mb-5">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-dark">
                    <tr>
                        <th style="width: 65px;" class="text-center">#</th>
                        <th style="min-width: 115px;">DNI</th>
                        <th style="min-width: 230px;">Estudiante</th>
                        <th style="min-width: 170px;">Nivel / Grado / Sec.</th>
                        <th style="min-width: 85px;">Año</th>
                        <th style="min-width: 140px;">Estado Académico</th>
                        <th style="min-width: 115px;">Matrícula</th>
                        <?php if ($puedeCambiarEstado): ?><th style="min-width: 195px;">Acción</th><?php endif; ?>
                        <?php if ($puedeGestionarEstudiantes): ?><th style="min-width: 180px;" class="text-end">Opciones</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody id="students-rows"><?php $renderEstudianteRows($estudiantes, $offset); ?></tbody>
            </table>
        </div>
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 p-3 border-top bg-light-subtle rounded-bottom">
            <div class="text-muted small">
                <?php if ($totalFiltrados > 0): ?>
                    Mostrando <strong><?= $offset + 1 ?></strong> a <strong><?= min($offset + count($estudiantes), $totalFiltrados) ?></strong> de <strong><?= number_format($totalFiltrados) ?></strong> estudiantes
                <?php else: ?>
                    Mostrando 0 estudiantes
                <?php endif; ?>
            </div>
            <?php if ($totalPaginas > 1): ?>
            <nav aria-label="Navegación de páginas">
                <ul class="pagination pagination-sm mb-0">
                    <?php
                    $queryBase = $_GET;
                    unset($queryBase['pagina'], $queryBase['ajax']);
                    $buildPageUrl = static function (int $p) use ($queryBase): string {
                        $params = array_merge($queryBase, ['pagina' => $p]);
                        return 'estudiantes.php?' . http_build_query($params);
                    };
                    ?>
                    <li class="page-item <?= $paginaActual <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= $paginaActual <= 1 ? '#' : htmlspecialchars($buildPageUrl($paginaActual - 1)) ?>" aria-label="Anterior">
                            <i class="bi bi-chevron-left"></i> Anterior
                        </a>
                    </li>
                    <?php
                    $startPage = max(1, $paginaActual - 2);
                    $endPage = min($totalPaginas, $paginaActual + 2);
                    if ($startPage > 1) {
                        echo '<li class="page-item"><a class="page-link" href="' . htmlspecialchars($buildPageUrl(1)) . '">1</a></li>';
                        if ($startPage > 2) {
                            echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                        }
                    }
                    for ($i = $startPage; $i <= $endPage; $i++):
                    ?>
                        <li class="page-item <?= $i === $paginaActual ? 'active' : '' ?>">
                            <a class="page-link" href="<?= htmlspecialchars($buildPageUrl($i)) ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>
                    <?php if ($endPage < $totalPaginas): ?>
                        <?php if ($endPage < $totalPaginas - 1): ?>
                            <li class="page-item disabled"><span class="page-link">…</span></li>
                        <?php endif; ?>
                        <li class="page-item"><a class="page-link" href="<?= htmlspecialchars($buildPageUrl($totalPaginas)) ?>"><?= $totalPaginas ?></a></li>
                    <?php endif; ?>
                    <li class="page-item <?= $paginaActual >= $totalPaginas ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= $paginaActual >= $totalPaginas ? '#' : htmlspecialchars($buildPageUrl($paginaActual + 1)) ?>" aria-label="Siguiente">
                            Siguiente <i class="bi bi-chevron-right"></i>
                        </a>
                    </li>
                </ul>
            </nav>
            <?php endif; ?>
        </div>
    </div>
</main>
<script id="estudiantes-config-data" type="application/json">
    <?= json_encode(['gradoId' => $gradoId, 'seccionId' => $seccionId]) ?>
</script>
<script src="../assets/js/estudiantes.js" defer></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
