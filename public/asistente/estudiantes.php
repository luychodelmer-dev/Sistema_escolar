<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/header.php';

require_auth(['asistente', 'director', 'hiperadmin', 'administrador']);
$csrfToken = $_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));
$puedeCambiarEstado = has_permission('p_matricular');
$puedeGestionarEstudiantes = has_permission('p_matricular');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'actualizar_estado') {
    require_permission('p_matricular');
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
        !in_array($academicStatus, ['Activo', 'Retirado', 'Trasladado'], true)) {
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
$academicStatuses = ['Activo', 'Retirado', 'Trasladado'];
$estadoAcademico = $_GET['estado'] ?? 'Activo';
if (!is_string($estadoAcademico) || !in_array($estadoAcademico, $academicStatuses, true)) {
    $estadoAcademico = 'Activo';
}
$nivelId = $getFilterId('nivel');
$gradoId = $getFilterId('grado');
$seccionId = $getFilterId('seccion');
$niveles = $pdo->query('SELECT id, nombre FROM nivel ORDER BY id')->fetchAll();

$conditions = [];
$params = [];
$conditions[] = 'al.estado_academico = :estado_academico';
$params['estado_academico'] = $estadoAcademico;
if ($busqueda !== '') {
    $conditions[] = "CONCAT_WS(' ', al.dni, al.nombres, al.apellido_paterno, al.apellido_materno) ILIKE :busqueda";
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

$sql = "SELECT DISTINCT ON (al.id)
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
        ORDER BY al.id, an.anio DESC NULLS LAST, m.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$estudiantes = $stmt->fetchAll();
$renderEstudianteRows = static function (array $rows) use ($puedeCambiarEstado, $puedeGestionarEstudiantes, $csrfToken): void {
    if (!$rows) {
        $columnCount = 8 + (int) $puedeCambiarEstado + (int) $puedeGestionarEstudiantes;
        echo '<tr><td colspan="' . $columnCount . '" class="text-center text-muted py-4">No hay estudiantes para mostrar.</td></tr>';
        return;
    }
    foreach ($rows as $estudiante) {
        echo '<tr data-student-row="1"><td>' . htmlspecialchars($estudiante['dni']) . '</td>';
        echo '<td><a href="ver_estudiante.php?id=' . (int) $estudiante['id'] . '">' . htmlspecialchars($estudiante['apellido_paterno'] . ' ' . $estudiante['apellido_materno'] . ', ' . $estudiante['nombres']) . '</a></td>';
        echo '<td>' . htmlspecialchars($estudiante['nivel']) . '</td><td>' . htmlspecialchars($estudiante['grado']) . '</td>';
        echo '<td>' . htmlspecialchars($estudiante['seccion']) . '</td><td>' . htmlspecialchars((string) $estudiante['anio']) . '</td>';
        echo '<td>' . htmlspecialchars($estudiante['estado_academico']) . '</td>';
        echo '<td>' . htmlspecialchars($estudiante['estado']) . '</td>';
        if ($puedeCambiarEstado) {
            echo '<td><form class="academic-status-form d-flex gap-2" method="POST">'
                . '<input type="hidden" name="action" value="actualizar_estado">'
                . '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') . '">'
                . '<input type="hidden" name="estudiante_id" value="' . (int) $estudiante['id'] . '">'
                . '<select class="form-select form-select-sm" name="estado_academico" aria-label="Estado académico">'
                . '<option value="Activo"' . ($estudiante['estado_academico'] === 'Activo' ? ' selected' : '') . '>Activo</option>'
                . '<option value="Retirado"' . ($estudiante['estado_academico'] === 'Retirado' ? ' selected' : '') . '>Retirado</option>'
                . '<option value="Trasladado"' . ($estudiante['estado_academico'] === 'Trasladado' ? ' selected' : '') . '>Trasladado</option>'
                . '</select><button class="btn btn-sm btn-outline-primary" type="submit">Actualizar</button></form></td>';
        }
        if ($puedeGestionarEstudiantes) {
            echo '<td class="text-nowrap">'
                . '<a class="btn btn-sm btn-outline-primary" href="editar_estudiante.php?id=' . (int) $estudiante['id'] . '">Editar</a> '
                . '<form class="student-delete-form d-inline" method="POST" action="eliminar_estudiante.php" onsubmit="return confirm(\'¿Eliminar este estudiante? Si tiene historial vinculado, no se eliminará.\')">'
                . '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') . '">'
                . '<input type="hidden" name="estudiante_id" value="' . (int) $estudiante['id'] . '">'
                . '<button class="btn btn-sm btn-outline-danger" type="submit">Eliminar</button></form></td>';
        }
        echo '</tr>';
    }
};
if (($_GET['ajax'] ?? '') === 'tabla') {
    header('Content-Type: text/html; charset=utf-8');
    $renderEstudianteRows($estudiantes);
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Estudiantes - Sistema Escolar</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root { --ink: #19324a; --muted: #687b8c; --line: #e0e7eb; --surface: #fff; --page: #f4f6f9; --teal: #167b78; }
        body { min-height: 100vh; color: var(--ink); background: var(--page); }
        .directory-card { background: var(--surface); border: 1px solid rgba(25,50,74,.06); border-radius: 10px; box-shadow: 0 8px 28px rgba(28,52,70,.07); overflow: hidden; }
        .filter-bar { padding: 1.25rem; border-bottom: 1px solid var(--line); }
        .filter-bar .form-control, .filter-bar .form-select { min-height: 44px; border-color: #d5dfe5; }
        .student-table thead th { color: #536979; background: #f7f9fa; font-size: .78rem; text-transform: uppercase; }
        .student-table td, .student-table th { padding: .9rem 1rem; }
        .student-table tbody tr { animation: row-in .2s ease-out both; }
        .student-link { color: var(--teal); font-weight: 650; text-decoration: none; }
        .student-link:hover { text-decoration: underline; }
        @keyframes row-in { from { opacity: .5; } to { opacity: 1; } }
    </style>
</head>
<body>
<?php render_app_header(panel_home_path(), 'logout.php'); ?>
<main class="container py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div><h1 class="h3 mb-1">Directorio de estudiantes</h1><p class="text-muted mb-0">Consulta por matrícula, nivel y sección.</p></div>
        <a href="matriculas.php" class="btn btn-success">Registrar matrícula</a>
    </div>
    <section class="directory-card">
    <ul class="nav nav-tabs px-3 pt-3" id="academic-status-tabs" role="tablist">
        <?php foreach (['Activo' => 'Activos', 'Retirado' => 'Retirados', 'Trasladado' => 'Trasladados'] as $status => $label): ?>
            <li class="nav-item" role="presentation"><button class="nav-link <?= $estadoAcademico === $status ? 'active' : '' ?>" type="button" role="tab" data-academic-status="<?= htmlspecialchars($status) ?>" aria-selected="<?= $estadoAcademico === $status ? 'true' : 'false' ?>"><?= htmlspecialchars($label) ?></button></li>
        <?php endforeach; ?>
    </ul>
    <form id="student-filters" class="filter-bar row g-2 align-items-center">
        <input type="hidden" name="estado" id="filtro-estado-academico" value="<?= htmlspecialchars($estadoAcademico) ?>">
        <div class="col-lg-5"><label class="visually-hidden" for="filtro-busqueda">Buscar estudiantes</label><input class="form-control" id="filtro-busqueda" type="search" name="q" placeholder="Buscar por nombre o DNI" value="<?= htmlspecialchars($busqueda) ?>"></div>
        <div class="col-sm-4 col-lg-2"><label class="visually-hidden" for="filtro-nivel">Nivel</label><select id="filtro-nivel" name="nivel" class="form-select"><option value="">Todos los niveles</option><?php foreach ($niveles as $nivel): ?><option value="<?= (int) $nivel['id'] ?>" <?= $nivelId === (int) $nivel['id'] ? 'selected' : '' ?>><?= htmlspecialchars($nivel['nombre']) ?></option><?php endforeach; ?></select></div>
        <div class="col-sm-4 col-lg-2"><label class="visually-hidden" for="filtro-grado">Grado</label><select id="filtro-grado" name="grado" class="form-select" <?= $nivelId === null ? 'disabled' : '' ?>><option value="">Todos los grados</option></select></div>
        <div class="col-sm-4 col-lg-2"><label class="visually-hidden" for="filtro-seccion">Sección</label><select id="filtro-seccion" name="seccion" class="form-select" <?= $gradoId === null ? 'disabled' : '' ?>><option value="">Todas las secciones</option></select></div>
        <div class="col-lg-1"><button class="btn btn-outline-secondary w-100" type="reset">Limpiar</button></div>
    </form>
    <div class="d-flex justify-content-between px-3 pt-3"><span class="small text-muted" id="students-result-count" aria-live="polite"><?= count($estudiantes) ?> estudiantes</span><span class="small text-muted" id="students-loading" hidden>Actualizando…</span></div>
    <div class="table-responsive">
        <table class="table table-striped table-hover align-middle mb-0">
            <thead><tr><th>DNI</th><th>Estudiante</th><th>Nivel</th><th>Grado</th><th>Sección</th><th>Año</th><th>Estado académico</th><th>Matrícula</th><?php if ($puedeCambiarEstado): ?><th>Acción</th><?php endif; ?><?php if ($puedeGestionarEstudiantes): ?><th>Opciones</th><?php endif; ?></tr></thead>
            <tbody id="students-rows"><?php $renderEstudianteRows($estudiantes); ?></tbody>
        </table>
    </div>
    </section>
</main>
<script>
const nivelSelect = document.getElementById('filtro-nivel');
const gradoSelect = document.getElementById('filtro-grado');
const seccionSelect = document.getElementById('filtro-seccion');
const studentFilters = document.getElementById('student-filters');
const studentsRows = document.getElementById('students-rows');
const studentsCount = document.getElementById('students-result-count');
const studentsLoading = document.getElementById('students-loading');
const academicStatusInput = document.getElementById('filtro-estado-academico');
const academicStatusTabs = document.getElementById('academic-status-tabs');
const selectedGrade = <?= json_encode($gradoId) ?>;
const selectedSection = <?= json_encode($seccionId) ?>;
let filterTimer;
let filterRequest;

async function refreshStudents() {
    filterRequest?.abort();
    filterRequest = new AbortController();
    const parameters = new URLSearchParams(new FormData(studentFilters));
    parameters.set('ajax', 'tabla');
    studentsLoading.hidden = false;
    try {
        const response = await fetch(`estudiantes.php?${parameters}`, {
            headers: { Accept: 'text/html' },
            signal: filterRequest.signal
        });
        if (!response.ok) throw new Error('No se pudo actualizar la lista de estudiantes.');
        studentsRows.innerHTML = await response.text();
        studentsCount.textContent = `${studentsRows.querySelectorAll('[data-student-row]').length} estudiantes`;
    } catch (error) {
        if (error.name !== 'AbortError') {
            const columnCount = studentsRows.closest('table').querySelector('thead tr').children.length;
            studentsRows.innerHTML = `<tr><td colspan="${columnCount}" class="text-center text-danger py-4">Error al cargar estudiantes.</td></tr>`;
            console.error(error);
        }
    } finally {
        if (!filterRequest.signal.aborted) studentsLoading.hidden = true;
    }
}

academicStatusTabs.addEventListener('click', (event) => {
    const tab = event.target.closest('[data-academic-status]');
    if (!tab) return;
    academicStatusInput.value = tab.dataset.academicStatus;
    academicStatusTabs.querySelectorAll('[data-academic-status]').forEach((item) => {
        const selected = item === tab;
        item.classList.toggle('active', selected);
        item.setAttribute('aria-selected', String(selected));
    });
    refreshStudents();
});

studentFilters.addEventListener('input', (event) => {
    if (event.target.matches('input[type="search"]')) {
        clearTimeout(filterTimer);
        filterTimer = setTimeout(refreshStudents, 250);
    }
});
studentFilters.addEventListener('change', (event) => {
    if (event.target.matches('select')) refreshStudents();
});
studentFilters.addEventListener('reset', () => {
    setTimeout(() => {
        const activeTab = academicStatusTabs.querySelector('[data-academic-status].active');
        academicStatusInput.value = activeTab?.dataset.academicStatus || 'Activo';
        refreshStudents();
    }, 0);
});

studentsRows.addEventListener('submit', async (event) => {
    const deleteForm = event.target.closest('.student-delete-form');
    if (deleteForm) {
        event.preventDefault();
        const button = deleteForm.querySelector('button[type="submit"]');
        button.disabled = true;
        try {
            const response = await fetch(deleteForm.action, {
                method: 'POST',
                headers: { Accept: 'application/json' },
                body: new FormData(deleteForm)
            });
            const result = await response.json();
            if (!response.ok) throw new Error(result.error || 'No se pudo eliminar el estudiante.');
            await refreshStudents();
        } catch (error) {
            window.alert(error.message);
        } finally {
            button.disabled = false;
        }
        return;
    }
    const form = event.target.closest('.academic-status-form');
    if (!form) return;
    event.preventDefault();
    const submitButton = form.querySelector('button[type="submit"]');
    submitButton.disabled = true;
    try {
        const response = await fetch('estudiantes.php', {
            method: 'POST',
            headers: { Accept: 'application/json' },
            body: new FormData(form)
        });
        const result = await response.json();
        if (!response.ok) throw new Error(result.error || 'No se pudo actualizar el estado.');
        await refreshStudents();
    } catch (error) {
        window.alert(error.message);
    } finally {
        submitButton.disabled = false;
    }
});

async function loadOptions(select, action, parentId, placeholder, selected = '') {
    select.replaceChildren(new Option(placeholder, ''));
    select.disabled = !parentId;
    if (!parentId) return;

    const response = await fetch(`estudiantes.php?ajax=${action}&id=${encodeURIComponent(parentId)}`, {
        headers: { Accept: 'application/json' }
    });
    if (!response.ok) throw new Error('No se pudieron cargar las opciones.');

    const options = await response.json();
    options.forEach((item) => {
        const option = new Option(item.nombre, item.id);
        option.selected = String(item.id) === String(selected);
        select.add(option);
    });
    select.disabled = false;
}

nivelSelect.addEventListener('change', async () => {
    seccionSelect.replaceChildren(new Option('Todas las secciones', ''));
    seccionSelect.disabled = true;
    await loadOptions(gradoSelect, 'grados', nivelSelect.value, 'Todos los grados');
});

gradoSelect.addEventListener('change', async () => {
    await loadOptions(seccionSelect, 'secciones', gradoSelect.value, 'Todas las secciones');
});

loadOptions(gradoSelect, 'grados', nivelSelect.value, 'Todos los grados', selectedGrade)
    .then(() => loadOptions(seccionSelect, 'secciones', gradoSelect.value, 'Todas las secciones', selectedSection))
    .catch((error) => console.error(error));
</script>
</body>
</html>
