<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/header.php';

require_auth(['asistente', 'director', 'hiperadmin', 'administrador']);
require_permission('p_estudiantes');

$csrfToken = $_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));

$id = filter_var($_GET['id'] ?? ($_POST['id'] ?? null), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false || $id === null) {
    header('Location: estudiantes.php');
    exit;
}

// 1. Obtener datos del estudiante
$stmt = $pdo->prepare('SELECT * FROM estudiante WHERE id = :id');
$stmt->execute(['id' => $id]);
$estudiante = $stmt->fetch();
if (!$estudiante) {
    header('Location: estudiantes.php?error=not_found');
    exit;
}

// 2. Obtener su matrícula más reciente
$stmtMatricula = $pdo->prepare(
    "SELECT m.id AS matricula_id, m.nivel_id, m.grado_seccion_id, m.anio_id, a.anio,
            gs.grado_id, gs.seccion_id, n.nombre AS nivel_nombre, g.nombre AS grado_nombre, s.nombre AS seccion_nombre
     FROM matricula m
     LEFT JOIN anio a ON a.id = m.anio_id
     LEFT JOIN nivel n ON n.id = m.nivel_id
     LEFT JOIN grado_seccion gs ON gs.id = m.grado_seccion_id
     LEFT JOIN grado g ON g.id = gs.grado_id
     LEFT JOIN seccion s ON s.id = gs.seccion_id
     WHERE m.estudiante_id = :id
     ORDER BY a.anio DESC NULLS LAST, m.id DESC
     LIMIT 1"
);
$stmtMatricula->execute(['id' => $id]);
$matriculaActual = $stmtMatricula->fetch();

// 3. Obtener niveles, grados y secciones para selección
$niveles = $pdo->query('SELECT id, nombre FROM nivel ORDER BY id')->fetchAll();
$currentNivelId = $matriculaActual['nivel_id'] ?? null;
$currentGradoId = $matriculaActual['grado_id'] ?? null;
$currentSeccionId = $matriculaActual['seccion_id'] ?? null;

// Grados correspondientes al nivel actual
$gradosDisponibles = [];
if ($currentNivelId) {
    $gStmt = $pdo->prepare('SELECT id, nombre FROM grado WHERE nivel_id = :nivel_id AND activo = TRUE ORDER BY numero');
    $gStmt->execute(['nivel_id' => $currentNivelId]);
    $gradosDisponibles = $gStmt->fetchAll();
}

// Secciones correspondientes al grado actual
$seccionesDisponibles = [];
if ($currentGradoId) {
    $sStmt = $pdo->prepare('SELECT DISTINCT s.id, s.nombre FROM grado_seccion gs JOIN seccion s ON s.id = gs.seccion_id WHERE gs.grado_id = :grado_id AND gs.activo = TRUE AND s.activo = TRUE ORDER BY s.nombre');
    $sStmt->execute(['grado_id' => $currentGradoId]);
    $seccionesDisponibles = $sStmt->fetchAll();
}

// 4. AJAX para cascading dropdowns
if (isset($_GET['ajax']) && in_array($_GET['ajax'], ['grados', 'secciones'], true)) {
    header('Content-Type: application/json; charset=utf-8');
    $parentId = filter_var($_GET['parent_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($parentId === false) {
        exit('[]');
    }
    if ($_GET['ajax'] === 'grados') {
        $optStmt = $pdo->prepare('SELECT id, nombre FROM grado WHERE nivel_id = :id AND activo = TRUE ORDER BY numero');
    } else {
        $optStmt = $pdo->prepare('SELECT DISTINCT s.id, s.nombre FROM grado_seccion gs JOIN seccion s ON s.id = gs.seccion_id WHERE gs.grado_id = :id AND gs.activo = TRUE AND s.activo = TRUE ORDER BY s.nombre');
    }
    $optStmt->execute(['id' => $parentId]);
    echo json_encode($optStmt->fetchAll(), JSON_UNESCAPED_UNICODE);
    exit;
}

// 5. Obtener apoderados / familia vinculada
$famStmt = $pdo->prepare(
    "SELECT ar.tipo, ar.parentesco, ar.es_apoderado, r.id, r.dni, r.nombres, r.apellido_paterno, r.apellido_materno, r.celular
     FROM estudiante_responsable ar
     JOIN responsable r ON r.id = ar.responsable_id
     WHERE ar.estudiante_id = :id
     ORDER BY CASE ar.tipo WHEN 'padre' THEN 1 WHEN 'madre' THEN 2 ELSE 3 END, r.apellido_paterno"
);
$famStmt->execute(['id' => $id]);
$familiares = $famStmt->fetchAll();

// 6. Procesar formulario POST
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!is_string($submittedToken) || !hash_equals($csrfToken, $submittedToken)) {
        $error = 'Solicitud inválida. Recarga la página e inténtalo nuevamente.';
    } else {
        $dni = trim($_POST['dni'] ?? '');
        $nombres = mb_strtoupper(trim($_POST['nombres'] ?? ''), 'UTF-8');
        $apellidoPaterno = mb_strtoupper(trim($_POST['apellido_paterno'] ?? ''), 'UTF-8');
        $apellidoMaterno = mb_strtoupper(trim($_POST['apellido_materno'] ?? ''), 'UTF-8');
        $fechaNacimiento = trim($_POST['fecha_nacimiento'] ?? '') ?: null;
        $estadoAcademico = trim($_POST['estado_academico'] ?? 'Activo');

        $nuevoNivelId = filter_var($_POST['nivel_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        $nuevoGradoId = filter_var($_POST['grado_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        $nuevaSeccionId = filter_var($_POST['seccion_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;

        if (!preg_match('/^\d{8}$/', $dni)) {
            $error = 'El DNI debe tener exactamente 8 dígitos numéricos.';
        } elseif ($nombres === '' || $apellidoPaterno === '' || $apellidoMaterno === '') {
            $error = 'Nombres y apellidos son obligatorios.';
        } elseif (!in_array($estadoAcademico, ['Activo', 'Promovido', 'Repitente', 'Promoción', 'Retirado', 'Trasladado'], true)) {
            $error = 'Estado académico no válido.';
        } else {
            // Verificar si el DNI ya lo tiene otro estudiante
            $dniCheck = $pdo->prepare('SELECT id FROM estudiante WHERE dni = :dni AND id <> :id');
            $dniCheck->execute(['dni' => $dni, 'id' => $id]);
            if ($dniCheck->fetchColumn()) {
                $error = 'El DNI ingresado ya está asignado a otro estudiante.';
            } else {
                try {
                    $pdo->beginTransaction();

                    $updEst = $pdo->prepare(
                        'UPDATE estudiante
                         SET dni = :dni,
                             nombres = :nombres,
                             apellido_paterno = :apellido_paterno,
                             apellido_materno = :apellido_materno,
                             fecha_nacimiento = :fecha_nacimiento,
                             estado_academico = :estado_academico,
                             actualizado_en = CURRENT_TIMESTAMP
                         WHERE id = :id'
                    );
                    $updEst->execute([
                        'dni' => $dni,
                        'nombres' => $nombres,
                        'apellido_paterno' => $apellidoPaterno,
                        'apellido_materno' => $apellidoMaterno,
                        'fecha_nacimiento' => $fechaNacimiento,
                        'estado_academico' => $estadoAcademico,
                        'id' => $id,
                    ]);

                    // Actualizar aula/matrícula si se proporcionaron datos
                    if ($matriculaActual && $nuevoNivelId && $nuevoGradoId && $nuevaSeccionId) {
                        $gsStmt = $pdo->prepare('SELECT id FROM grado_seccion WHERE grado_id = :grado_id AND seccion_id = :seccion_id');
                        $gsStmt->execute(['grado_id' => $nuevoGradoId, 'seccion_id' => $nuevaSeccionId]);
                        $gsId = $gsStmt->fetchColumn();
                        if (!$gsId) {
                            $insGs = $pdo->prepare('INSERT INTO grado_seccion (grado_id, seccion_id, activo) VALUES (:grado_id, :seccion_id, TRUE) RETURNING id');
                            $insGs->execute(['grado_id' => $nuevoGradoId, 'seccion_id' => $nuevaSeccionId]);
                            $gsId = $insGs->fetchColumn();
                        }

                        $updMat = $pdo->prepare(
                            'UPDATE matricula
                             SET nivel_id = :nivel_id,
                                 grado_seccion_id = :gs_id
                             WHERE id = :matricula_id'
                        );
                        $updMat->execute([
                            'nivel_id' => $nuevoNivelId,
                            'gs_id' => $gsId,
                            'matricula_id' => $matriculaActual['matricula_id']
                        ]);
                    }

                    registrar_auditoria('editar_estudiante', "Actualizó los datos del estudiante {$nombres} {$apellidoPaterno} {$apellidoMaterno} (DNI {$dni}, ID {$id}).");
                    $pdo->commit();

                    header('Location: estudiantes.php?actualizado=1');
                    exit;
                } catch (PDOException $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('Error al actualizar estudiante: ' . $e->getMessage());
                    $error = 'Error en base de datos: ' . $e->getMessage();
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Editar Estudiante - Sistema Escolar</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../assets/css/app.css">
    <link rel="stylesheet" href="../assets/css/editar_estudiante.css">
</head>
<body>
<?php render_app_header(panel_home_path(), 'logout.php'); ?>

<main class="container-fluid px-3 px-md-4 px-xl-5 py-4">
    <!-- Breadcrumb y Título -->
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1 small">
                    <li class="breadcrumb-item"><a href="<?= htmlspecialchars(panel_home_path()) ?>" class="text-decoration-none text-muted"><i class="bi bi-house-door"></i> Inicio</a></li>
                    <li class="breadcrumb-item"><a href="estudiantes.php" class="text-decoration-none text-muted">Estudiantes</a></li>
                    <li class="breadcrumb-item active text-primary fw-semibold" aria-current="page">Editar Estudiante</li>
                </ol>
            </nav>
            <h1 class="h3 fw-bold mb-1 text-dark d-flex align-items-center gap-2">
                Editar Datos del Estudiante
                <span class="badge bg-primary-subtle text-primary border border-primary-subtle fs-6 fw-semibold">ID: <?= (int)$estudiante['id'] ?></span>
            </h1>
            <p class="text-muted mb-0 small">Modifica información personal, estado académico y asignación de aula.</p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <a href="ver_estudiante.php?id=<?= (int)$estudiante['id'] ?>" class="btn btn-outline-info d-inline-flex align-items-center gap-1 px-3 py-2" style="border-radius: 10px;">
                <i class="bi bi-eye"></i> Ver Expediente
            </a>
            <a href="estudiantes.php" class="btn btn-outline-secondary d-inline-flex align-items-center gap-1 px-3 py-2" style="border-radius: 10px;">
                <i class="bi bi-arrow-left"></i> Volver a la lista
            </a>
        </div>
    </div>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger d-flex align-items-center gap-2 shadow-sm border-0 mb-4">
            <i class="bi bi-exclamation-triangle-fill fs-5"></i>
            <div><?= htmlspecialchars($error) ?></div>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- Formulario Principal -->
        <div class="col-lg-8">
            <div class="form-card p-4">
                <form method="POST" action="editar_estudiante.php?id=<?= (int)$estudiante['id'] ?>">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="id" value="<?= (int)$estudiante['id'] ?>">

                    <div class="section-title">
                        <i class="bi bi-person-badge me-1"></i> Datos Personales del Alumno
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" for="dni">DNI <span class="text-danger">*</span></label>
                            <input type="text" class="form-control font-monospace" id="dni" name="dni" maxlength="8" pattern="\d{8}" inputmode="numeric" value="<?= htmlspecialchars($_POST['dni'] ?? $estudiante['dni']) ?>" required>
                            <div class="form-text">Exactamente 8 dígitos.</div>
                        </div>

                        <div class="col-md-8">
                            <label class="form-label fw-semibold" for="nombres">Nombres <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="nombres" name="nombres" maxlength="100" style="text-transform: uppercase;" value="<?= htmlspecialchars($_POST['nombres'] ?? $estudiante['nombres']) ?>" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="apellido_paterno">Apellido Paterno <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="apellido_paterno" name="apellido_paterno" maxlength="100" style="text-transform: uppercase;" value="<?= htmlspecialchars($_POST['apellido_paterno'] ?? $estudiante['apellido_paterno']) ?>" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="apellido_materno">Apellido Materno <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="apellido_materno" name="apellido_materno" maxlength="100" style="text-transform: uppercase;" value="<?= htmlspecialchars($_POST['apellido_materno'] ?? $estudiante['apellido_materno']) ?>" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="fecha_nacimiento">Fecha de Nacimiento</label>
                            <input type="date" class="form-control" id="fecha_nacimiento" name="fecha_nacimiento" value="<?= htmlspecialchars($_POST['fecha_nacimiento'] ?? ($estudiante['fecha_nacimiento'] ?? '')) ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="estado_academico">Estado Académico <span class="text-danger">*</span></label>
                            <?php $currEstado = $_POST['estado_academico'] ?? $estudiante['estado_academico']; ?>
                            <select class="form-select" id="estado_academico" name="estado_academico" required>
                                <option value="Activo" <?= $currEstado === 'Activo' ? 'selected' : '' ?>>Activo</option>
                                <option value="Promovido" <?= $currEstado === 'Promovido' ? 'selected' : '' ?>>Promovido</option>
                                <option value="Repitente" <?= $currEstado === 'Repitente' ? 'selected' : '' ?>>Repitente</option>
                                <option value="Promoción" <?= $currEstado === 'Promoción' ? 'selected' : '' ?>>Promoción</option>
                                <option value="Retirado" <?= $currEstado === 'Retirado' ? 'selected' : '' ?>>Retirado</option>
                                <option value="Trasladado" <?= $currEstado === 'Trasladado' ? 'selected' : '' ?>>Trasladado</option>
                            </select>
                        </div>
                    </div>

                    <div class="section-title">
                        <i class="bi bi-mortarboard me-1"></i> Asignación de Aula y Matrícula Actual
                    </div>

                    <?php if ($matriculaActual): ?>
                        <div class="alert alert-light border d-flex align-items-center justify-content-between mb-3 py-2 px-3">
                            <span class="small text-muted">Matrícula vigente: <strong><?= htmlspecialchars($matriculaActual['nivel_nombre'] . ' - ' . $matriculaActual['grado_nombre'] . ' "' . $matriculaActual['seccion_nombre'] . '" (Año ' . $matriculaActual['anio'] . ')') ?></strong></span>
                            <span class="badge bg-success-subtle text-success border border-success-subtle">Matriculado</span>
                        </div>
                        <div class="row g-3 mb-4">
                            <div class="col-md-4">
                                <label class="form-label fw-semibold" for="nivel_id">Nivel</label>
                                <select class="form-select" id="nivel_id" name="nivel_id">
                                    <option value="">Seleccionar Nivel</option>
                                    <?php foreach ($niveles as $niv): ?>
                                        <option value="<?= (int)$niv['id'] ?>" <?= ((int)($_POST['nivel_id'] ?? $currentNivelId) === (int)$niv['id']) ? 'selected' : '' ?>><?= htmlspecialchars($niv['nombre']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-semibold" for="grado_id">Grado</label>
                                <select class="form-select" id="grado_id" name="grado_id">
                                    <option value="">Seleccionar Grado</option>
                                    <?php foreach ($gradosDisponibles as $gr): ?>
                                        <option value="<?= (int)$gr['id'] ?>" <?= ((int)($_POST['grado_id'] ?? $currentGradoId) === (int)$gr['id']) ? 'selected' : '' ?>><?= htmlspecialchars($gr['nombre']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-semibold" for="seccion_id">Sección</label>
                                <select class="form-select" id="seccion_id" name="seccion_id">
                                    <option value="">Seleccionar Sección</option>
                                    <?php foreach ($seccionesDisponibles as $sec): ?>
                                        <option value="<?= (int)$sec['id'] ?>" <?= ((int)($_POST['seccion_id'] ?? $currentSeccionId) === (int)$sec['id']) ? 'selected' : '' ?>><?= htmlspecialchars($sec['nombre']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-warning py-2 mb-4 small">
                            <i class="bi bi-info-circle me-1"></i> Este estudiante no tiene una matrícula registrada aún. Para asignarle aula y cuotas, utiliza el módulo de <a href="matriculas.php" class="alert-link">Matrículas</a>.
                        </div>
                    <?php endif; ?>

                    <div class="d-flex align-items-center justify-content-end gap-2 pt-3 border-top">
                        <a href="estudiantes.php" class="btn btn-outline-secondary px-4">Cancelar</a>
                        <button type="submit" class="btn btn-primary d-inline-flex align-items-center gap-2 px-4 shadow-sm fw-semibold">
                            <i class="bi bi-save"></i> Guardar Cambios
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Columna Lateral Informativa -->
        <div class="col-lg-4">
            <!-- Tarjeta Familiares Vinculados -->
            <div class="form-card p-4 mb-4">
                <div class="section-title">
                    <i class="bi bi-people me-1"></i> Responsables Vinculados
                </div>

                <?php if ($familiares): ?>
                    <div class="list-group list-group-flush mb-0">
                        <?php foreach ($familiares as $fam): ?>
                            <div class="list-group-item px-0 py-2 border-bottom">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="fw-bold text-dark fs-6">
                                            <a href="ver_padre.php?id=<?= (int)$fam['id'] ?>" class="text-decoration-none text-dark hover-primary">
                                                <?= htmlspecialchars($fam['apellido_paterno'] . ' ' . ($fam['apellido_materno'] ?? '') . ', ' . $fam['nombres']) ?>
                                            </a>
                                        </div>
                                        <div class="text-muted small mt-1">
                                            <span class="badge bg-light text-secondary border"><?= htmlspecialchars(ucfirst($fam['tipo'])) ?></span>
                                            DNI: <?= htmlspecialchars($fam['dni']) ?>
                                        </div>
                                    </div>
                                    <?php if ($fam['es_apoderado']): ?>
                                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle" style="font-size: 0.82rem;">Principal</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="text-muted small mb-0">No hay responsables asociados directamente.</p>
                <?php endif; ?>
            </div>

            <!-- Ayuda / Instrucciones -->
            <div class="alert alert-info border-0 shadow-sm p-3 small mb-0" style="background-color: #f0f9ff; color: #0369a1; border-radius: 14px;">
                <h6 class="fw-bold d-flex align-items-center gap-1 mb-2"><i class="bi bi-lightbulb"></i> Recomendación</h6>
                <p class="mb-0">Si necesitas reasignar o cambiar los apoderados legales de este estudiante, puedes gestionarlo desde el módulo de <a href="padres.php" class="fw-bold text-decoration-underline" style="color: #0369a1;">Padres y Apoderados</a>.</p>
            </div>
        </div>
    </div>
</main>

<script id="editar-estudiante-config" type="application/json">
    <?= json_encode(['estudianteId' => (int)$estudiante['id']]) ?>
</script>
<script src="../assets/js/editar_estudiante.js" defer></script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
