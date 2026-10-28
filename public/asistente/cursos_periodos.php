<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/header.php';

require_auth(['asistente', 'director', 'hiperadmin', 'administrador']);
require_permission('p_matricular');

$csrfToken = $_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));
$error = '';
$mensaje = '';

$validDate = static function (mixed $value): bool {
    if (!is_string($value)) {
        return false;
    }
    $date = DateTime::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    $action = $_POST['action'] ?? '';

    if (!is_string($submittedToken) || !hash_equals($csrfToken, $submittedToken)) {
        $error = 'Solicitud inválida. Recarga la página e inténtalo nuevamente.';
    } elseif (!is_string($action)) {
        $error = 'La acción solicitada no es válida.';
    } else {
        try {
            if ($action === 'crear_curso' || $action === 'actualizar_curso') {
                $nombre = trim(is_string($_POST['nombre'] ?? null) ? $_POST['nombre'] : '');
                $grado = trim(is_string($_POST['grado'] ?? null) ? $_POST['grado'] : '');
                if ($nombre === '' || strlen($nombre) > 100 || $grado === '' || strlen($grado) > 50) {
                    throw new InvalidArgumentException('Ingresa el nombre del curso (máximo 100 caracteres) y el grado (máximo 50).');
                }

                if ($action === 'crear_curso') {
                    $stmt = $pdo->prepare('INSERT INTO curso (nombre, grado, activo) VALUES (:nombre, :grado, TRUE)');
                    $stmt->execute(['nombre' => $nombre, 'grado' => $grado]);
                    $mensaje = 'Curso registrado correctamente.';
                } else {
                    $id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                    if ($id === false) {
                        throw new InvalidArgumentException('El curso seleccionado no es válido.');
                    }
                    $stmt = $pdo->prepare('UPDATE curso SET nombre = :nombre, grado = :grado WHERE id = :id AND activo = TRUE');
                    $stmt->execute(['nombre' => $nombre, 'grado' => $grado, 'id' => $id]);
                    if ($stmt->rowCount() === 0) {
                        $exists = $pdo->prepare('SELECT 1 FROM curso WHERE id = :id AND activo = TRUE');
                        $exists->execute(['id' => $id]);
                        if (!$exists->fetchColumn()) {
                            throw new InvalidArgumentException('No se encontró el curso activo.');
                        }
                    }
                    $mensaje = 'Curso actualizado correctamente.';
                }
            } elseif ($action === 'desactivar_curso') {
                $id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($id === false) {
                    throw new InvalidArgumentException('El curso seleccionado no es válido.');
                }
                $stmt = $pdo->prepare('UPDATE curso SET activo = FALSE WHERE id = :id AND activo = TRUE');
                $stmt->execute(['id' => $id]);
                $mensaje = $stmt->rowCount() > 0 ? 'Curso desactivado.' : 'El curso ya no está activo.';
            } elseif ($action === 'crear_periodo' || $action === 'actualizar_periodo') {
                $nombre = trim(is_string($_POST['nombre'] ?? null) ? $_POST['nombre'] : '');
                $fechaInicio = $_POST['fecha_inicio'] ?? null;
                $fechaFin = $_POST['fecha_fin'] ?? null;
                if ($nombre === '' || strlen($nombre) > 50 ||
                    !$validDate($fechaInicio) || !$validDate($fechaFin) || $fechaInicio > $fechaFin) {
                    throw new InvalidArgumentException('Ingresa el nombre y un rango de fechas válido (máximo 50 caracteres).');
                }

                if ($action === 'crear_periodo') {
                    $stmt = $pdo->prepare(
                        'INSERT INTO periodo_academico (nombre, fecha_inicio, fecha_fin, activo)
                         VALUES (:nombre, :fecha_inicio, :fecha_fin, TRUE)'
                    );
                    $stmt->execute(['nombre' => $nombre, 'fecha_inicio' => $fechaInicio, 'fecha_fin' => $fechaFin]);
                    $mensaje = 'Periodo académico registrado correctamente.';
                } else {
                    $id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                    if ($id === false) {
                        throw new InvalidArgumentException('El periodo seleccionado no es válido.');
                    }
                    $stmt = $pdo->prepare(
                        'UPDATE periodo_academico
                         SET nombre = :nombre, fecha_inicio = :fecha_inicio, fecha_fin = :fecha_fin
                         WHERE id = :id AND activo = TRUE'
                    );
                    $stmt->execute([
                        'nombre' => $nombre,
                        'fecha_inicio' => $fechaInicio,
                        'fecha_fin' => $fechaFin,
                        'id' => $id,
                    ]);
                    if ($stmt->rowCount() === 0) {
                        $exists = $pdo->prepare('SELECT 1 FROM periodo_academico WHERE id = :id AND activo = TRUE');
                        $exists->execute(['id' => $id]);
                        if (!$exists->fetchColumn()) {
                            throw new InvalidArgumentException('No se encontró el periodo académico activo.');
                        }
                    }
                    $mensaje = 'Periodo académico actualizado correctamente.';
                }
            } elseif ($action === 'desactivar_periodo') {
                $id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($id === false) {
                    throw new InvalidArgumentException('El periodo seleccionado no es válido.');
                }
                $stmt = $pdo->prepare('UPDATE periodo_academico SET activo = FALSE WHERE id = :id AND activo = TRUE');
                $stmt->execute(['id' => $id]);
                $mensaje = $stmt->rowCount() > 0 ? 'Periodo académico desactivado.' : 'El periodo ya no está activo.';
            } else {
                throw new InvalidArgumentException('La acción solicitada no está permitida.');
            }
        } catch (InvalidArgumentException $exception) {
            $error = $exception->getMessage();
        } catch (PDOException $exception) {
            error_log('Error al gestionar cursos o periodos académicos: ' . $exception->getMessage());
            $error = 'No se pudo guardar el cambio. Verifica los datos e inténtalo nuevamente.';
        }
    }
}

$cursos = $pdo->query('SELECT id, nombre, grado FROM curso WHERE activo = TRUE ORDER BY grado, nombre')->fetchAll();
$periodos = $pdo->query(
    'SELECT id, nombre, fecha_inicio, fecha_fin
     FROM periodo_academico
     WHERE activo = TRUE
     ORDER BY fecha_inicio DESC, nombre'
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cursos y periodos - Sistema Escolar</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php render_app_header(panel_home_path(), 'logout.php'); ?>
<main class="container py-4">
    <h1 class="h3 mb-4">Cursos y periodos académicos</h1>
    <?php if ($mensaje !== ''): ?><div class="alert alert-success"><?= htmlspecialchars($mensaje) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert alert-danger" role="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="row g-4">
        <section class="col-lg-6">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body">
                    <h2 class="h5 mb-3">Cursos</h2>
                    <form method="POST" class="row g-2 mb-4">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="crear_curso">
                        <div class="col-md-5">
                            <label class="form-label" for="curso-nombre">Nombre</label>
                            <input class="form-control" id="curso-nombre" name="nombre" maxlength="100" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="curso-grado">Grado</label>
                            <input class="form-control" id="curso-grado" name="grado" maxlength="50" required>
                        </div>
                        <div class="col-md-3 d-flex align-items-end">
                            <button class="btn btn-primary w-100" type="submit">Registrar</button>
                        </div>
                    </form>
                    <div class="table-responsive">
                        <table class="table table-striped align-middle">
                            <thead><tr><th>Curso</th><th>Grado</th><th>Acciones</th></tr></thead>
                            <tbody>
                            <?php if (!$cursos): ?>
                                <tr><td colspan="3" class="text-center text-muted">No hay cursos activos.</td></tr>
                            <?php else: foreach ($cursos as $curso): ?>
                                <tr>
                                    <td colspan="3">
                                        <form method="POST" class="row g-2 align-items-center">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="id" value="<?= (int) $curso['id'] ?>">
                                            <div class="col-md-4"><input class="form-control form-control-sm" aria-label="Nombre del curso" name="nombre" maxlength="100" value="<?= htmlspecialchars($curso['nombre']) ?>" required></div>
                                            <div class="col-md-3"><input class="form-control form-control-sm" aria-label="Grado" name="grado" maxlength="50" value="<?= htmlspecialchars($curso['grado']) ?>" required></div>
                                            <div class="col-md-5 d-flex gap-2">
                                                <button class="btn btn-sm btn-outline-primary" name="action" value="actualizar_curso" type="submit">Actualizar</button>
                                                <button class="btn btn-sm btn-outline-danger" name="action" value="desactivar_curso" type="submit" formnovalidate onclick="return confirm('¿Desactivar este curso?')">Desactivar</button>
                                            </div>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>

        <section class="col-lg-6">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body">
                    <h2 class="h5 mb-3">Periodos académicos</h2>
                    <form method="POST" class="row g-2 mb-4">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="crear_periodo">
                        <div class="col-12">
                            <label class="form-label" for="periodo-nombre">Nombre</label>
                            <input class="form-control" id="periodo-nombre" name="nombre" maxlength="50" required>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label" for="fecha-inicio">Fecha de inicio</label>
                            <input class="form-control" id="fecha-inicio" type="date" name="fecha_inicio" required>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label" for="fecha-fin">Fecha de fin</label>
                            <input class="form-control" id="fecha-fin" type="date" name="fecha_fin" required>
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button class="btn btn-primary w-100" type="submit">Registrar</button>
                        </div>
                    </form>
                    <div class="table-responsive">
                        <table class="table table-striped align-middle">
                            <thead><tr><th>Periodo</th><th>Inicio</th><th>Fin</th><th>Acciones</th></tr></thead>
                            <tbody>
                            <?php if (!$periodos): ?>
                                <tr><td colspan="4" class="text-center text-muted">No hay periodos activos.</td></tr>
                            <?php else: foreach ($periodos as $periodo): ?>
                                <tr>
                                    <td colspan="4">
                                        <form method="POST" class="row g-2 align-items-center">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="id" value="<?= (int) $periodo['id'] ?>">
                                            <div class="col-md-3"><input class="form-control form-control-sm" aria-label="Nombre del periodo" name="nombre" maxlength="50" value="<?= htmlspecialchars($periodo['nombre']) ?>" required></div>
                                            <div class="col-md-3"><input class="form-control form-control-sm" aria-label="Fecha de inicio" type="date" name="fecha_inicio" value="<?= htmlspecialchars($periodo['fecha_inicio']) ?>" required></div>
                                            <div class="col-md-3"><input class="form-control form-control-sm" aria-label="Fecha de fin" type="date" name="fecha_fin" value="<?= htmlspecialchars($periodo['fecha_fin']) ?>" required></div>
                                            <div class="col-md-3 d-flex flex-wrap gap-2">
                                                <button class="btn btn-sm btn-outline-primary" name="action" value="actualizar_periodo" type="submit">Actualizar</button>
                                                <button class="btn btn-sm btn-outline-danger" name="action" value="desactivar_periodo" type="submit" formnovalidate onclick="return confirm('¿Desactivar este periodo?')">Desactivar</button>
                                            </div>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>
    </div>
</main>
</body>
</html>
