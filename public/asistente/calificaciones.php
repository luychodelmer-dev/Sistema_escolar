<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/header.php';

require_auth(['asistente', 'director', 'hiperadmin', 'administrador']);
require_permission('p_matricular');

$pdo->exec(
    'ALTER TABLE calificacion
     ADD COLUMN IF NOT EXISTS actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP'
);

$csrfToken = $_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));
$cursos = $pdo->query('SELECT id, nombre, grado FROM curso WHERE activo = TRUE ORDER BY grado, nombre')->fetchAll();
$periodos = $pdo->query('SELECT id, nombre FROM periodo_academico WHERE activo = TRUE ORDER BY fecha_inicio DESC, nombre')->fetchAll();
$cursoIds = array_map('intval', array_column($cursos, 'id'));
$periodoIds = array_map('intval', array_column($periodos, 'id'));

$cursoEntrada = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['curso_id'] ?? '') : ($_GET['curso_id'] ?? '');
$periodoEntrada = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['periodo_id'] ?? '') : ($_GET['periodo_id'] ?? '');
$cursoId = is_string($cursoEntrada) || is_int($cursoEntrada)
    ? filter_var($cursoEntrada, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : false;
$periodoId = is_string($periodoEntrada) || is_int($periodoEntrada)
    ? filter_var($periodoEntrada, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : false;
$cursoId = $cursoId !== false && in_array($cursoId, $cursoIds, true) ? $cursoId : null;
$periodoId = $periodoId !== false && in_array($periodoId, $periodoIds, true) ? $periodoId : null;

$error = '';
$mensaje = '';
$alumnos = [];
$notasActuales = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    $notasRecibidas = $_POST['notas'] ?? null;
    $notasValidadas = [];

    if (!is_string($submittedToken) || !hash_equals($csrfToken, $submittedToken)) {
        $error = 'Solicitud inválida. Recarga la página e inténtalo nuevamente.';
    } elseif ($cursoId === null || $periodoId === null) {
        $error = 'Selecciona un curso y un periodo académico válidos.';
    } elseif (!is_array($notasRecibidas)) {
        $error = 'No se recibieron notas válidas.';
    } else {
        $stmtAlumnos = $pdo->prepare(
            "SELECT m.id AS matricula_id, e.dni,
                    CONCAT_WS(' ', e.apellido_paterno, e.apellido_materno, e.nombres) AS estudiante
             FROM matricula m
             JOIN estudiante e ON e.id = m.estudiante_id
             WHERE m.estado = 'Activa'
             ORDER BY e.apellido_paterno, e.apellido_materno, e.nombres"
        );
        $stmtAlumnos->execute();
        $alumnos = $stmtAlumnos->fetchAll();
        $matriculasActivas = array_fill_keys(array_map(
            static fn (array $alumno): int => (int) $alumno['matricula_id'],
            $alumnos
        ), true);

        foreach ($notasRecibidas as $matriculaIdEntrada => $notaEntrada) {
            if (!is_string($matriculaIdEntrada) && !is_int($matriculaIdEntrada)) {
                $error = 'Se recibió una matrícula inválida.';
                break;
            }
            $matriculaId = filter_var($matriculaIdEntrada, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($matriculaId === false || !isset($matriculasActivas[$matriculaId])) {
                $error = 'Una matrícula no está activa o no es válida.';
                break;
            }
            if (!is_string($notaEntrada) && !is_int($notaEntrada) && !is_float($notaEntrada)) {
                $error = 'Una de las notas no es válida.';
                break;
            }
            $nota = trim((string) $notaEntrada);
            if ($nota === '') {
                continue;
            }
            if (!preg_match('/^\d{1,2}(?:\.\d{1,2})?$/', $nota) || (float) $nota > 20) {
                $error = 'Las notas deben estar entre 0 y 20, con un máximo de dos decimales.';
                break;
            }
            $notasValidadas[$matriculaId] = $nota;
        }

        if ($error === '') {
            try {
                $pdo->beginTransaction();
                $upsertNota = $pdo->prepare(
                    'INSERT INTO calificacion (matricula_id, curso_id, periodo_id, nota)
                     VALUES (:matricula_id, :curso_id, :periodo_id, :nota)
                     ON CONFLICT (matricula_id, curso_id, periodo_id)
                     DO UPDATE SET nota = EXCLUDED.nota, actualizado_en = CURRENT_TIMESTAMP'
                );
                foreach ($notasValidadas as $matriculaId => $nota) {
                    $upsertNota->execute([
                        'matricula_id' => $matriculaId,
                        'curso_id' => $cursoId,
                        'periodo_id' => $periodoId,
                        'nota' => $nota,
                    ]);
                }
                $pdo->commit();
                $mensaje = 'Las notas se guardaron correctamente.';
            } catch (PDOException $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Error al guardar calificaciones: ' . $exception->getMessage());
                $error = 'No se pudieron guardar las notas. No se aplicaron cambios.';
            }
        }
    }
}

if ($cursoId !== null && $periodoId !== null && !$alumnos) {
    $stmtAlumnos = $pdo->prepare(
        "SELECT m.id AS matricula_id, e.dni,
                CONCAT_WS(' ', e.apellido_paterno, e.apellido_materno, e.nombres) AS estudiante
         FROM matricula m
         JOIN estudiante e ON e.id = m.estudiante_id
         WHERE m.estado = 'Activa'
         ORDER BY e.apellido_paterno, e.apellido_materno, e.nombres"
    );
    $stmtAlumnos->execute();
    $alumnos = $stmtAlumnos->fetchAll();
}

if ($cursoId !== null && $periodoId !== null) {
    $stmtNotas = $pdo->prepare(
        'SELECT matricula_id, nota
         FROM calificacion
         WHERE curso_id = :curso_id AND periodo_id = :periodo_id'
    );
    $stmtNotas->execute(['curso_id' => $cursoId, 'periodo_id' => $periodoId]);
    foreach ($stmtNotas->fetchAll() as $nota) {
        $notasActuales[(int) $nota['matricula_id']] = $nota['nota'];
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Calificaciones - Sistema Escolar</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php render_app_header(panel_home_path(), 'logout.php'); ?>
<main class="container py-4">
    <h1 class="h3 mb-4">Registro de calificaciones</h1>
    <?php if ($mensaje !== ''): ?><div class="alert alert-success"><?= htmlspecialchars($mensaje) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert alert-danger" role="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <form method="GET" class="card card-body shadow-sm border-0 mb-4">
        <div class="row g-3 align-items-end">
            <div class="col-md-5">
                <label class="form-label" for="curso_id">Curso</label>
                <select class="form-select" id="curso_id" name="curso_id" required>
                    <option value="">Seleccionar curso</option>
                    <?php foreach ($cursos as $curso): ?>
                        <option value="<?= (int) $curso['id'] ?>" <?= $cursoId === (int) $curso['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($curso['nombre'] . ($curso['grado'] ? ' - ' . $curso['grado'] : '')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-5">
                <label class="form-label" for="periodo_id">Periodo académico</label>
                <select class="form-select" id="periodo_id" name="periodo_id" required>
                    <option value="">Seleccionar periodo</option>
                    <?php foreach ($periodos as $periodo): ?>
                        <option value="<?= (int) $periodo['id'] ?>" <?= $periodoId === (int) $periodo['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($periodo['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <button class="btn btn-primary w-100" type="submit">Cargar Alumnos</button>
            </div>
        </div>
    </form>

    <?php if ($cursoId !== null && $periodoId !== null): ?>
        <form method="POST" class="card shadow-sm border-0">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="curso_id" value="<?= $cursoId ?>">
            <input type="hidden" name="periodo_id" value="<?= $periodoId ?>">
            <div class="table-responsive">
                <table class="table table-striped table-hover align-middle mb-0">
                    <thead><tr><th>Matrícula</th><th>DNI</th><th>Estudiante</th><th>Nota (0–20)</th></tr></thead>
                    <tbody>
                    <?php if (!$alumnos): ?>
                        <tr><td colspan="4" class="text-center text-muted py-4">No hay matrículas activas.</td></tr>
                    <?php else: foreach ($alumnos as $alumno): ?>
                        <?php $matriculaId = (int) $alumno['matricula_id']; ?>
                        <tr>
                            <td><?= $matriculaId ?></td>
                            <td><?= htmlspecialchars($alumno['dni']) ?></td>
                            <td><?= htmlspecialchars($alumno['estudiante']) ?></td>
                            <td>
                                <input class="form-control" type="number" name="notas[<?= $matriculaId ?>]"
                                       min="0" max="20" step="0.01" value="<?= htmlspecialchars((string) ($notasActuales[$matriculaId] ?? '')) ?>">
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($alumnos): ?>
                <div class="card-body text-end">
                    <button class="btn btn-success" type="submit">Guardar calificaciones</button>
                </div>
            <?php endif; ?>
        </form>
    <?php endif; ?>
</main>
</body>
</html>
