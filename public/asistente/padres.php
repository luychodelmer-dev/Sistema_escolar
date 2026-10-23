<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. Requerimos la configuración y la autenticación usando TU estructura de carpetas
require_once '../../config/database.php';
require_once '../../app/auth.php';
require_once __DIR__ . '/../../app/header.php';

// Validar seguridad (ajusta esto si tu auth.php lo maneja distinto)
require_auth(['asistente', 'director']);
$puedeEditar = has_permission('p_editar');
$csrfToken = $_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    require_permission('p_editar');
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!is_string($submittedToken) || !hash_equals($csrfToken, $submittedToken)) {
        http_response_code(400);
        exit('Solicitud inválida. Recarga la página e inténtalo nuevamente.');
    }

    $deleteIdRaw = $_POST['id'] ?? null;
    $deleteId = is_string($deleteIdRaw)
        ? filter_var($deleteIdRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
        : false;
    if ($deleteId === false) {
        header('Location: padres.php?error=invalid');
        exit;
    }

    try {
        $pdo->beginTransaction();
        $responsibleNameStmt = $pdo->prepare(
            "SELECT CONCAT_WS(' ', nombres, apellido_paterno, apellido_materno)
             FROM responsable WHERE id = :id"
        );
        $responsibleNameStmt->execute(['id' => $deleteId]);
        $responsibleName = $responsibleNameStmt->fetchColumn();
        if ($responsibleName === false) {
            throw new RuntimeException('not_found');
        }
        $studentIdRaw = $_POST['student_id'] ?? '';
        $studentId = is_string($studentIdRaw)
            ? filter_var($studentIdRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : false;
        $replacementIdRaw = $_POST['replacement_id'] ?? '';
        $replacementId = is_string($replacementIdRaw) && $replacementIdRaw !== ''
            ? filter_var($replacementIdRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : null;
        if ($studentId === false || $replacementId === false) {
            throw new RuntimeException('invalid');
        }

        if ($studentId !== null) {
            $studentLock = $pdo->prepare('SELECT id FROM estudiante WHERE id = :student_id FOR UPDATE');
            $studentLock->execute(['student_id' => $studentId]);
            if (!$studentLock->fetchColumn()) {
                throw new RuntimeException('not_found');
            }
            $relationStmt = $pdo->prepare(
                'SELECT id, es_apoderado FROM estudiante_responsable
                 WHERE estudiante_id = :student_id AND responsable_id = :responsable_id
                 FOR UPDATE'
            );
            $relationStmt->execute(['student_id' => $studentId, 'responsable_id' => $deleteId]);
            $relation = $relationStmt->fetch();
            if (!$relation) {
                throw new RuntimeException('not_found');
            }

            $countStmt = $pdo->prepare(
                'SELECT COUNT(*) FROM estudiante_responsable WHERE estudiante_id = :student_id'
            );
            $countStmt->execute(['student_id' => $studentId]);
            if ((int) $countStmt->fetchColumn() === 1) {
                throw new RuntimeException('orphan');
            }

            if ($relation['es_apoderado']) {
                if ($replacementId === null || $replacementId === $deleteId) {
                    throw new RuntimeException('replacement_required');
                }
                $candidateStmt = $pdo->prepare(
                    'SELECT id FROM estudiante_responsable
                     WHERE estudiante_id = :student_id AND responsable_id = :replacement_id
                     FOR UPDATE'
                );
                $candidateStmt->execute(['student_id' => $studentId, 'replacement_id' => $replacementId]);
                if (!$candidateStmt->fetchColumn()) {
                    throw new RuntimeException('invalid');
                }
                $unsetPrincipal = $pdo->prepare(
                    'UPDATE estudiante_responsable SET es_apoderado = FALSE,
                     actualizado_en = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
                     WHERE estudiante_id = :student_id AND responsable_id = :responsable_id'
                );
                $unsetPrincipal->execute(['student_id' => $studentId, 'responsable_id' => $deleteId]);
                $setPrincipal = $pdo->prepare(
                    'UPDATE estudiante_responsable SET es_apoderado = TRUE,
                     actualizado_en = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
                     WHERE estudiante_id = :student_id AND responsable_id = :replacement_id'
                );
                $setPrincipal->execute(['student_id' => $studentId, 'replacement_id' => $replacementId]);
                $updateEnrollments = $pdo->prepare(
                    'UPDATE matricula SET apoderado_principal_id = :replacement_id
                     WHERE estudiante_id = :student_id AND apoderado_principal_id = :old_id'
                );
                $updateEnrollments->execute([
                    'replacement_id' => $replacementId,
                    'student_id' => $studentId,
                    'old_id' => $deleteId,
                ]);
            } elseif ($replacementId !== null) {
                throw new RuntimeException('invalid');
            }

            $deleteStmt = $pdo->prepare(
                'DELETE FROM estudiante_responsable
                 WHERE estudiante_id = :student_id AND responsable_id = :responsable_id'
            );
            $deleteStmt->execute(['student_id' => $studentId, 'responsable_id' => $deleteId]);
            if ($deleteStmt->rowCount() !== 1) {
                throw new RuntimeException('not_found');
            }
            $removeUnlinked = $pdo->prepare(
                'DELETE FROM responsable WHERE id = :id
                 AND NOT EXISTS (SELECT 1 FROM estudiante_responsable WHERE responsable_id = :relation_owner_id)'
            );
            $removeUnlinked->execute(['id' => $deleteId, 'relation_owner_id' => $deleteId]);
        } else {
            $deleteStmt = $pdo->prepare('DELETE FROM responsable WHERE id = :id');
            $deleteStmt->execute(['id' => $deleteId]);
            if ($deleteStmt->rowCount() !== 1) {
                throw new RuntimeException('not_found');
            }
        }
        registrar_auditoria('eliminar_apoderado', 'Desvinculó al apoderado ' . $responsibleName . ' (ID ' . $deleteId . ').');
        $pdo->commit();
        header('Location: padres.php?eliminado=1');
        exit;
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Error al eliminar apoderado: ' . $exception->getMessage());
        header('Location: padres.php?error=delete_blocked');
        exit;
    } catch (RuntimeException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = in_array($exception->getMessage(), ['invalid', 'not_found', 'orphan', 'replacement_required'], true)
            ? $exception->getMessage()
            : 'delete_blocked';
        header('Location: padres.php?error=' . $error);
        exit;
    }
}

if (($_GET['ajax'] ?? '') === 'principal_candidates') {
    require_permission('p_editar');
    header('Content-Type: application/json; charset=utf-8');
    $studentId = filter_var($_GET['student_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $responsibleId = filter_var($_GET['responsable_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($studentId === false || $responsibleId === false) {
        http_response_code(400);
        echo json_encode(['error' => 'Solicitud inválida.']);
        exit;
    }
    $candidateStmt = $pdo->prepare(
        'SELECT r.id, r.nombres, r.apellido_paterno, r.apellido_materno
         FROM estudiante_responsable er
         JOIN responsable r ON r.id = er.responsable_id
         WHERE er.estudiante_id = :student_id AND er.responsable_id <> :responsable_id
         ORDER BY r.apellido_paterno, r.nombres'
    );
    $candidateStmt->execute(['student_id' => $studentId, 'responsable_id' => $responsibleId]);
    echo json_encode($candidateStmt->fetchAll(), JSON_UNESCAPED_UNICODE);
    exit;
}

if (isset($_GET['ajax']) && in_array($_GET['ajax'], ['grados', 'secciones'], true)) {
    header('Content-Type: application/json; charset=utf-8');
    $parentId = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($parentId === false) {
        http_response_code(400);
        echo '[]';
        exit;
    }

    if ($_GET['ajax'] === 'grados') {
        $optionsStmt = $pdo->prepare('SELECT id, nombre FROM grado WHERE nivel_id = :id AND activo = TRUE ORDER BY numero');
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
        echo '[]';
        exit;
    }

    $optionsStmt->execute(['id' => $parentId]);
    echo json_encode($optionsStmt->fetchAll(), JSON_UNESCAPED_UNICODE);
    exit;
}

$busqueda = trim($_GET['q'] ?? '');
$getFilterId = static function (string $key): ?int {
    $value = filter_var($_GET[$key] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $value === false ? null : $value;
};
$nivelId = $getFilterId('nivel');
$gradoId = $getFilterId('grado');
$seccionId = $getFilterId('seccion');
$niveles = $pdo->query('SELECT id, nombre FROM nivel ORDER BY id')->fetchAll();

$params = [];
$matriculaFilters = [];
if ($nivelId !== null) {
    $matriculaFilters[] = 'm.nivel_id = :nivel_id';
    $params['nivel_id'] = $nivelId;
}
if ($gradoId !== null) {
    $matriculaFilters[] = 'g.id = :grado_id';
    $params['grado_id'] = $gradoId;
}
if ($seccionId !== null) {
    $matriculaFilters[] = 's.id = :seccion_id';
    $params['seccion_id'] = $seccionId;
}
$matriculaFilterSql = $matriculaFilters ? ' AND ' . implode(' AND ', $matriculaFilters) : '';

$sql = <<<SQL
SELECT r.*, ar.id AS relacion_id, ar.tipo AS relacion_tipo, ar.es_apoderado,
       al.id AS estudiante_id, al.nombres AS estudiante_nombres,
       al.apellido_paterno AS estudiante_apellido_paterno,
       al.apellido_materno AS estudiante_apellido_materno, ar.parentesco,
       padre.relacion_id AS padre_relacion_id, padre.responsable_id AS padre_responsable_id,
       padre.dni AS padre_dni, padre.nombres AS padre_nombres,
       padre.apellido_paterno AS padre_apellido_paterno, padre.apellido_materno AS padre_apellido_materno,
       padre.celular AS padre_celular, padre.parentesco AS padre_parentesco,
       madre.relacion_id AS madre_relacion_id, madre.responsable_id AS madre_responsable_id,
       madre.dni AS madre_dni, madre.nombres AS madre_nombres,
       madre.apellido_paterno AS madre_apellido_paterno, madre.apellido_materno AS madre_apellido_materno,
       madre.celular AS madre_celular, madre.parentesco AS madre_parentesco,
       emergencia.relacion_id AS emergencia_relacion_id,
       emergencia.responsable_id AS emergencia_responsable_id,
       emergencia.dni AS emergencia_dni, emergencia.nombres AS emergencia_nombres,
       emergencia.apellido_paterno AS emergencia_apellido_paterno,
       emergencia.apellido_materno AS emergencia_apellido_materno,
       emergencia.parentesco AS emergencia_parentesco, emergencia.celular AS emergencia_celular,
       matricula.anio, matricula.nivel_id, matricula.nivel,
       matricula.grado_id, matricula.grado, matricula.seccion_id, matricula.seccion,
       COALESCE(pagos.cantidad, 0) AS pagos_cantidad,
       COALESCE(pagos.total, 0) AS pagos_total
FROM responsable r
LEFT JOIN estudiante_responsable ar ON ar.responsable_id = r.id
LEFT JOIN estudiante al ON al.id = ar.estudiante_id
LEFT JOIN LATERAL (
    SELECT er.id AS relacion_id, contacto.id AS responsable_id, contacto.dni,
           contacto.nombres, contacto.apellido_paterno, contacto.apellido_materno,
           contacto.celular, er.parentesco
    FROM estudiante_responsable er
    JOIN responsable contacto ON contacto.id = er.responsable_id
    WHERE er.estudiante_id = al.id AND er.tipo = 'padre'
    ORDER BY er.id
    LIMIT 1
) padre ON TRUE
LEFT JOIN LATERAL (
    SELECT er.id AS relacion_id, contacto.id AS responsable_id, contacto.dni,
           contacto.nombres, contacto.apellido_paterno, contacto.apellido_materno,
           contacto.celular, er.parentesco
    FROM estudiante_responsable er
    JOIN responsable contacto ON contacto.id = er.responsable_id
    WHERE er.estudiante_id = al.id AND er.tipo = 'madre'
    ORDER BY er.id
    LIMIT 1
) madre ON TRUE
LEFT JOIN LATERAL (
    SELECT er.id AS relacion_id, contacto.id AS responsable_id, contacto.dni,
           contacto.nombres, contacto.apellido_paterno, contacto.apellido_materno,
           contacto.celular, er.parentesco
    FROM estudiante_responsable er
    JOIN responsable contacto ON contacto.id = er.responsable_id
    WHERE er.estudiante_id = al.id
      AND er.tipo IN ('emergencia', 'otro')
      AND contacto.id <> r.id
    ORDER BY CASE er.tipo WHEN 'emergencia' THEN 0 ELSE 1 END, er.id
    LIMIT 1
) emergencia ON TRUE
LEFT JOIN LATERAL (
    SELECT m.id, a.anio, n.id AS nivel_id, n.nombre AS nivel,
           g.id AS grado_id, g.nombre AS grado, s.id AS seccion_id, s.nombre AS seccion
    FROM matricula m
    LEFT JOIN anio a ON a.id = m.anio_id
    LEFT JOIN nivel n ON n.id = m.nivel_id
    LEFT JOIN grado_seccion gs ON gs.id = m.grado_seccion_id
    LEFT JOIN grado g ON g.id = gs.grado_id
    LEFT JOIN seccion s ON s.id = gs.seccion_id
    WHERE m.estudiante_id = al.id{$matriculaFilterSql}
    ORDER BY a.anio DESC NULLS LAST, m.id DESC
    LIMIT 1
) matricula ON TRUE
LEFT JOIN LATERAL (
    SELECT COUNT(*) FILTER (
               WHERE LOWER(COALESCE(NULLIF(p.estado, ''), p.estado_pago, 'pagado')) NOT IN ('pendiente', 'anulado')
           ) AS cantidad,
           COALESCE(SUM(p.monto) FILTER (
               WHERE LOWER(COALESCE(NULLIF(p.estado, ''), p.estado_pago, 'pagado')) NOT IN ('pendiente', 'anulado')
           ), 0) AS total
    FROM pago p
    WHERE EXISTS (
        SELECT 1
        FROM matricula pm
        JOIN estudiante_responsable par ON par.estudiante_id = pm.estudiante_id
        WHERE pm.id = p.matricula_id AND par.responsable_id = r.id
    ) OR LOWER(TRIM(COALESCE(p.responsable, ''))) =
         LOWER(TRIM(CONCAT_WS(' ', r.nombres, r.apellido_paterno, r.apellido_materno)))
) pagos ON TRUE
SQL;
$conditions = [];
if ($busqueda !== '') {
    $conditions[] = "CONCAT_WS(' ', r.dni, r.nombres, r.apellido_paterno, r.apellido_materno, al.dni, al.nombres, al.apellido_paterno, al.apellido_materno) ILIKE :busqueda";
    $params['busqueda'] = "%$busqueda%";
}
if ($matriculaFilters) {
    $conditions[] = 'matricula.id IS NOT NULL';
}
if ($conditions) {
    $sql .= ' WHERE ' . implode(' AND ', $conditions);
}
$sql .= ' ORDER BY r.id DESC, al.apellido_paterno, al.nombres';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$apoderados = $stmt->fetchAll();
$renderApoderados = static function (array $rows) use ($puedeEditar, $csrfToken): void {
    if (!$rows) {
        echo '<tr><td colspan="8" class="text-center py-4 text-muted">No se encontraron apoderados registrados.</td></tr>';
        return;
    }
    foreach ($rows as $row) {
        $nombre = $row['apellido_paterno'] . ' ' . ($row['apellido_materno'] ?? '') . ', ' . $row['nombres'];
        echo '<tr><td class="fw-bold">' . htmlspecialchars($row['dni']) . '</td>';
        echo '<td><a href="ver_padre.php?id=' . (int) $row['id'] . '">' . htmlspecialchars($nombre) . '</a></td>';
        echo '<td>' . ($row['estudiante_id'] ? '<a href="ver_estudiante.php?id=' . (int) $row['estudiante_id'] . '">' . htmlspecialchars($row['estudiante_apellido_paterno'] . ' ' . $row['estudiante_apellido_materno'] . ', ' . $row['estudiante_nombres']) . '</a>' : '-') . '</td>';
        echo '<td>' . htmlspecialchars($row['parentesco'] ?? '-') . '</td>';
        echo '<td>' . ($row['nivel'] ? htmlspecialchars($row['nivel'] . ' / ' . $row['grado'] . ' / ' . $row['seccion']) . '<br><small class="text-muted">Año ' . htmlspecialchars((string) $row['anio']) . '</small>' : 'Sin matrícula') . '</td>';
        echo '<td><a class="btn btn-sm btn-outline-primary" href="pagos.php?buscar=' . rawurlencode($row['nombres'] . ' ' . $row['apellido_paterno'] . ' ' . ($row['apellido_materno'] ?? '')) . '">Ver ' . (int) $row['pagos_cantidad'] . ' · S/ ' . htmlspecialchars(number_format((float) $row['pagos_total'], 2)) . '</a></td>';
        echo '<td>' . htmlspecialchars($row['celular_actual'] ?: ($row['celular'] ?? '-')) . '<br><small class="text-muted">APAFA: ' . htmlspecialchars($row['nro_tarjeta_apafa'] ?? 'Sin asignar') . '</small></td><td>';
        if ($puedeEditar) {
            $attribute = static fn ($value): string => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
            echo '<div class="d-flex flex-wrap gap-1">';
            echo '<button type="button" class="btn btn-sm btn-outline-warning edit-contact" data-bs-toggle="modal" data-bs-target="#editContactModal"'
                . ' data-id="' . (int) $row['id'] . '" data-relacion-id="' . (int) ($row['relacion_id'] ?? 0) . '"'
                . ' data-dni="' . $attribute($row['dni']) . '" data-nombres="' . $attribute($row['nombres']) . '"'
                . ' data-apellido-paterno="' . $attribute($row['apellido_paterno']) . '" data-apellido-materno="' . $attribute($row['apellido_materno']) . '"'
                . ' data-parentesco="' . $attribute($row['parentesco']) . '" data-direccion="' . $attribute($row['direccion']) . '"'
                . ' data-celular="' . $attribute($row['celular_actual'] ?: ($row['celular'] ?? '')) . '"'
                . ' data-student-id="' . (int) ($row['estudiante_id'] ?? 0) . '"'
                . ' data-father-id="' . (int) ($row['padre_responsable_id'] ?? 0) . '"'
                . ' data-father-relacion-id="' . (int) ($row['padre_relacion_id'] ?? 0) . '"'
                . ' data-father-dni="' . $attribute($row['padre_dni'] ?? '') . '"'
                . ' data-father-nombres="' . $attribute($row['padre_nombres'] ?? '') . '"'
                . ' data-father-apellido-paterno="' . $attribute($row['padre_apellido_paterno'] ?? '') . '"'
                . ' data-father-apellido-materno="' . $attribute($row['padre_apellido_materno'] ?? '') . '"'
                . ' data-father-parentesco="' . $attribute($row['padre_parentesco'] ?? '') . '"'
                . ' data-father-celular="' . $attribute($row['padre_celular'] ?? '') . '"'
                . ' data-mother-id="' . (int) ($row['madre_responsable_id'] ?? 0) . '"'
                . ' data-mother-relacion-id="' . (int) ($row['madre_relacion_id'] ?? 0) . '"'
                . ' data-mother-dni="' . $attribute($row['madre_dni'] ?? '') . '"'
                . ' data-mother-nombres="' . $attribute($row['madre_nombres'] ?? '') . '"'
                . ' data-mother-apellido-paterno="' . $attribute($row['madre_apellido_paterno'] ?? '') . '"'
                . ' data-mother-apellido-materno="' . $attribute($row['madre_apellido_materno'] ?? '') . '"'
                . ' data-mother-parentesco="' . $attribute($row['madre_parentesco'] ?? '') . '"'
                . ' data-mother-celular="' . $attribute($row['madre_celular'] ?? '') . '"'
                . ' data-emergency-id="' . (int) ($row['emergencia_responsable_id'] ?? 0) . '"'
                . ' data-emergency-relacion-id="' . (int) ($row['emergencia_relacion_id'] ?? 0) . '"'
                . ' data-emergency-dni="' . $attribute($row['emergencia_dni'] ?? '') . '"'
                . ' data-emergency-nombres="' . $attribute($row['emergencia_nombres'] ?? '') . '"'
                . ' data-emergency-apellido-paterno="' . $attribute($row['emergencia_apellido_paterno'] ?? '') . '"'
                . ' data-emergency-apellido-materno="' . $attribute($row['emergencia_apellido_materno'] ?? '') . '"'
                . ' data-emergency-parentesco="' . $attribute($row['emergencia_parentesco'] ?? '') . '"'
                . ' data-emergency-celular="' . $attribute($row['emergencia_celular'] ?? '') . '">Editar</button>';
            echo '<form method="POST" class="delete-parent-form" action="padres.php" data-principal="' . (!empty($row['es_apoderado']) ? '1' : '0') . '">'
                . '<input type="hidden" name="action" value="delete">'
                . '<input type="hidden" name="csrf_token" value="' . $attribute($csrfToken) . '">'
                . '<input type="hidden" name="id" value="' . (int) $row['id'] . '">'
                . '<input type="hidden" name="student_id" value="' . ($row['estudiante_id'] ? (int) $row['estudiante_id'] : '') . '">'
                . '<input type="hidden" name="replacement_id" value="">'
                . '<button type="submit" class="btn btn-sm btn-danger">Eliminar</button></form>';
            echo '</div>';
        }
        echo '</td></tr>';
    }
};
if (($_GET['ajax'] ?? '') === 'tabla') {
    header('Content-Type: text/html; charset=utf-8');
    $renderApoderados($apoderados);
    exit;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestión de Apoderados</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php render_app_header(panel_home_path(), 'logout.php'); ?>

    <div class="container">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2 class="fw-bold">Directorio de Apoderados</h2>
            <?php if ($puedeEditar): ?><a href="nuevo_padre.php" class="btn btn-success">+ Registrar Nuevo</a><?php endif; ?>
        </div>
        <?php if (isset($_GET['eliminado'])): ?><div class="alert alert-success">Apoderado desvinculado correctamente.</div><?php endif; ?>
        <?php if (isset($_GET['actualizado'])): ?><div class="alert alert-success">Apoderado y contacto de emergencia actualizados correctamente.</div><?php endif; ?>
        <?php if (($_GET['error'] ?? '') === 'delete_blocked'): ?><div class="alert alert-danger">No se puede eliminar el apoderado porque tiene relaciones vigentes, como matrículas o pagos.</div><?php elseif (($_GET['error'] ?? '') === 'orphan'): ?><div class="alert alert-danger">No se puede eliminar: el estudiante no puede quedarse sin apoderados.</div><?php elseif (($_GET['error'] ?? '') === 'replacement_required'): ?><div class="alert alert-danger">Debes seleccionar al nuevo apoderado Principal.</div><?php elseif (($_GET['error'] ?? '') === 'not_found'): ?><div class="alert alert-warning">No se encontró el apoderado para eliminar.</div><?php elseif (($_GET['error'] ?? '') === 'invalid'): ?><div class="alert alert-warning">El apoderado seleccionado no es válido.</div><?php endif; ?>

        <!-- Buscador -->
        <div class="card shadow-sm mb-4 border-0">
            <div class="card-body">
                <form method="GET" class="row g-2" data-live-filter data-live-target="#apoderados-rows">
                    <div class="col-lg-4"><input type="search" name="q" class="form-control" placeholder="Buscar apoderado o estudiante" value="<?= htmlspecialchars($busqueda) ?>"></div>
                    <div class="col-sm-4 col-lg-2"><select id="filtro-nivel" name="nivel" class="form-select"><option value="">Todos los niveles</option><?php foreach ($niveles as $nivel): ?><option value="<?= (int) $nivel['id'] ?>" <?= $nivelId === (int) $nivel['id'] ? 'selected' : '' ?>><?= htmlspecialchars($nivel['nombre']) ?></option><?php endforeach; ?></select></div>
                    <div class="col-sm-4 col-lg-2"><select id="filtro-grado" name="grado" class="form-select" <?= $nivelId === null ? 'disabled' : '' ?>><option value="">Todos los grados</option></select></div>
                    <div class="col-sm-4 col-lg-2"><select id="filtro-seccion" name="seccion" class="form-select" <?= $gradoId === null ? 'disabled' : '' ?>><option value="">Todas las secciones</option></select></div>
                    <div class="col-lg-2 d-flex gap-2"><button class="btn btn-outline-secondary flex-grow-1" type="reset">Limpiar</button></div>
                </form>
            </div>
        </div>

        <!-- Tabla de Resultados -->
        <div class="card shadow-sm border-0">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-striped table-hover mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>DNI</th>
                                <th>Apoderado</th>
                                <th>Estudiante</th>
                                <th>Parentesco</th>
                                <th>Nivel / Grado / Sección</th>
                                <th>Pagos</th>
                                <th>Contacto / APAFA</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody id="apoderados-rows"><?php $renderApoderados($apoderados); ?></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="modal fade" id="principalModal" tabindex="-1" aria-labelledby="principalTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
            <div class="modal-header"><h2 class="modal-title fs-5" id="principalTitle">Reasignar apoderado Principal</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
            <div class="modal-body">
                <label class="form-label" for="replacement-select">Selecciona al nuevo Principal</label>
                <select class="form-select" id="replacement-select" required><option value="">Cargando apoderados...</option></select>
                <div class="form-text">Debes elegir a otro apoderado vinculado con el estudiante.</div>
                <div class="alert alert-danger mt-3 d-none" id="principal-modal-error" role="alert"></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-primary" id="confirm-principal-delete" disabled>Reasignar y eliminar</button></div>
        </div></div>
    </div>
    <div class="modal fade" id="editContactModal" tabindex="-1" aria-labelledby="editContactTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
            <form method="POST" action="editar_apoderado.php">
                <div class="modal-header"><h2 class="modal-title fs-5" id="editContactTitle">Editar apoderado y contacto de emergencia</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
                <div class="modal-body row g-3">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="id" id="edit-contact-id">
                    <input type="hidden" name="relacion_id" id="edit-contact-relacion-id">
                    <input type="hidden" name="student_id" id="edit-contact-student-id">
                    <input type="hidden" name="emergency_id" id="edit-emergency-id">
                    <input type="hidden" name="emergency_relacion_id" id="edit-emergency-relacion-id">
                    <input type="hidden" name="father_id" id="edit-father-id">
                    <input type="hidden" name="father_relacion_id" id="edit-father-relacion-id">
                    <input type="hidden" name="mother_id" id="edit-mother-id">
                    <input type="hidden" name="mother_relacion_id" id="edit-mother-relacion-id">
                    <h3 class="h6 border-bottom pb-2">Datos del apoderado</h3>
                    <div class="col-md-4"><label class="form-label" for="edit-contact-dni">DNI</label><input class="form-control" id="edit-contact-dni" name="dni" maxlength="8" pattern="[0-9]{8}" style="text-transform: uppercase;" required></div>
                    <div class="col-md-8"><label class="form-label" for="edit-contact-nombres">Nombres</label><input class="form-control" id="edit-contact-nombres" name="nombres" maxlength="100" style="text-transform: uppercase;" required></div>
                    <div class="col-md-6"><label class="form-label" for="edit-contact-apellido-paterno">Apellido paterno</label><input class="form-control" id="edit-contact-apellido-paterno" name="apellido_paterno" maxlength="100" style="text-transform: uppercase;" required></div>
                    <div class="col-md-6"><label class="form-label" for="edit-contact-apellido-materno">Apellido materno</label><input class="form-control" id="edit-contact-apellido-materno" name="apellido_materno" maxlength="100" style="text-transform: uppercase;"></div>
                    <div class="col-md-6"><label class="form-label" for="edit-contact-parentesco">Parentesco</label><input class="form-control" id="edit-contact-parentesco" name="parentesco" maxlength="60" style="text-transform: uppercase;"></div>
                    <div class="col-md-6"><label class="form-label" for="edit-contact-celular">Celular</label><input class="form-control" id="edit-contact-celular" name="celular" minlength="9" maxlength="9" pattern="[0-9]{9}" style="text-transform: uppercase;"></div>
                    <div class="col-12"><label class="form-label" for="edit-contact-direccion">Dirección</label><input class="form-control" id="edit-contact-direccion" name="direccion" maxlength="500" style="text-transform: uppercase;"></div>
                    <h3 class="h6 border-bottom pb-2 mt-2">Padre (si falta, completa sus datos)</h3>
                    <div class="col-md-4"><label class="form-label" for="edit-father-dni">DNI</label><input class="form-control" id="edit-father-dni" name="father_dni" maxlength="8" pattern="[0-9]{8}" style="text-transform: uppercase;"></div>
                    <div class="col-md-8"><label class="form-label" for="edit-father-nombres">Nombres</label><input class="form-control" id="edit-father-nombres" name="father_nombres" maxlength="100" style="text-transform: uppercase;"></div>
                    <div class="col-md-6"><label class="form-label" for="edit-father-apellido-paterno">Apellido paterno</label><input class="form-control" id="edit-father-apellido-paterno" name="father_apellido_paterno" maxlength="100" style="text-transform: uppercase;"></div>
                    <div class="col-md-6"><label class="form-label" for="edit-father-apellido-materno">Apellido materno</label><input class="form-control" id="edit-father-apellido-materno" name="father_apellido_materno" maxlength="100" style="text-transform: uppercase;"></div>
                    <div class="col-md-6"><label class="form-label" for="edit-father-parentesco">Parentesco</label><input class="form-control" id="edit-father-parentesco" name="father_parentesco" maxlength="60" value="Padre" style="text-transform: uppercase;"></div>
                    <div class="col-md-6"><label class="form-label" for="edit-father-celular">Celular</label><input class="form-control" id="edit-father-celular" name="father_celular" minlength="9" maxlength="9" pattern="[0-9]{9}" style="text-transform: uppercase;"></div>
                    <h3 class="h6 border-bottom pb-2 mt-2">Madre (si falta, completa sus datos)</h3>
                    <div class="col-md-4"><label class="form-label" for="edit-mother-dni">DNI</label><input class="form-control" id="edit-mother-dni" name="mother_dni" maxlength="8" pattern="[0-9]{8}" style="text-transform: uppercase;"></div>
                    <div class="col-md-8"><label class="form-label" for="edit-mother-nombres">Nombres</label><input class="form-control" id="edit-mother-nombres" name="mother_nombres" maxlength="100" style="text-transform: uppercase;"></div>
                    <div class="col-md-6"><label class="form-label" for="edit-mother-apellido-paterno">Apellido paterno</label><input class="form-control" id="edit-mother-apellido-paterno" name="mother_apellido_paterno" maxlength="100" style="text-transform: uppercase;"></div>
                    <div class="col-md-6"><label class="form-label" for="edit-mother-apellido-materno">Apellido materno</label><input class="form-control" id="edit-mother-apellido-materno" name="mother_apellido_materno" maxlength="100" style="text-transform: uppercase;"></div>
                    <div class="col-md-6"><label class="form-label" for="edit-mother-parentesco">Parentesco</label><input class="form-control" id="edit-mother-parentesco" name="mother_parentesco" maxlength="60" value="Madre" style="text-transform: uppercase;"></div>
                    <div class="col-md-6"><label class="form-label" for="edit-mother-celular">Celular</label><input class="form-control" id="edit-mother-celular" name="mother_celular" minlength="9" maxlength="9" pattern="[0-9]{9}" style="text-transform: uppercase;"></div>
                    <h3 class="h6 border-bottom pb-2 mt-2">Contacto de emergencia asociado</h3>
                    <div class="col-md-4"><label class="form-label" for="edit-emergency-dni">DNI</label><input class="form-control" id="edit-emergency-dni" name="emergency_dni" maxlength="8" pattern="[0-9]{8}" style="text-transform: uppercase;"></div>
                    <div class="col-md-8"><label class="form-label" for="edit-emergency-nombres">Nombres</label><input class="form-control" id="edit-emergency-nombres" name="emergency_nombres" maxlength="100" style="text-transform: uppercase;"></div>
                    <div class="col-md-6"><label class="form-label" for="edit-emergency-apellido-paterno">Apellido paterno</label><input class="form-control" id="edit-emergency-apellido-paterno" name="emergency_apellido_paterno" maxlength="100" style="text-transform: uppercase;"></div>
                    <div class="col-md-6"><label class="form-label" for="edit-emergency-apellido-materno">Apellido materno</label><input class="form-control" id="edit-emergency-apellido-materno" name="emergency_apellido_materno" maxlength="100" style="text-transform: uppercase;"></div>
                    <div class="col-md-6"><label class="form-label" for="edit-emergency-parentesco">Parentesco</label><input class="form-control" id="edit-emergency-parentesco" name="emergency_parentesco" maxlength="60" style="text-transform: uppercase;"></div>
                    <div class="col-md-6"><label class="form-label" for="edit-emergency-celular">Celular de emergencia</label><input class="form-control" id="edit-emergency-celular" name="emergency_celular" minlength="9" maxlength="9" pattern="[0-9]{9}" style="text-transform: uppercase;"></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Guardar cambios</button></div>
            </form>
        </div></div>
    </div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const nivelSelect = document.getElementById('filtro-nivel');
const gradoSelect = document.getElementById('filtro-grado');
const seccionSelect = document.getElementById('filtro-seccion');
const selectedGrade = <?= json_encode($gradoId) ?>;
const selectedSection = <?= json_encode($seccionId) ?>;

document.addEventListener('click', (event) => {
    const button = event.target.closest('.edit-contact');
    if (!button) return;
    const fields = {
        'edit-contact-id': 'id',
        'edit-contact-relacion-id': 'relacionId',
        'edit-contact-student-id': 'studentId',
        'edit-contact-dni': 'dni',
        'edit-contact-nombres': 'nombres',
        'edit-contact-apellido-paterno': 'apellidoPaterno',
        'edit-contact-apellido-materno': 'apellidoMaterno',
        'edit-contact-parentesco': 'parentesco',
        'edit-contact-direccion': 'direccion',
        'edit-contact-celular': 'celular',
        'edit-father-id': 'fatherId',
        'edit-father-relacion-id': 'fatherRelacionId',
        'edit-father-dni': 'fatherDni',
        'edit-father-nombres': 'fatherNombres',
        'edit-father-apellido-paterno': 'fatherApellidoPaterno',
        'edit-father-apellido-materno': 'fatherApellidoMaterno',
        'edit-father-parentesco': 'fatherParentesco',
        'edit-father-celular': 'fatherCelular',
        'edit-mother-id': 'motherId',
        'edit-mother-relacion-id': 'motherRelacionId',
        'edit-mother-dni': 'motherDni',
        'edit-mother-nombres': 'motherNombres',
        'edit-mother-apellido-paterno': 'motherApellidoPaterno',
        'edit-mother-apellido-materno': 'motherApellidoMaterno',
        'edit-mother-parentesco': 'motherParentesco',
        'edit-mother-celular': 'motherCelular',
        'edit-emergency-id': 'emergencyId',
        'edit-emergency-relacion-id': 'emergencyRelacionId',
        'edit-emergency-dni': 'emergencyDni',
        'edit-emergency-nombres': 'emergencyNombres',
        'edit-emergency-apellido-paterno': 'emergencyApellidoPaterno',
        'edit-emergency-apellido-materno': 'emergencyApellidoMaterno',
        'edit-emergency-parentesco': 'emergencyParentesco',
        'edit-emergency-celular': 'emergencyCelular'
    };
    Object.entries(fields).forEach(([id, key]) => {
        const value = button.dataset[key] || '';
        document.getElementById(id).value = value === '0' ? '' : value;
    });
});

let principalDeleteForm = null;
const principalModal = new bootstrap.Modal(document.getElementById('principalModal'));
const replacementSelect = document.getElementById('replacement-select');
const principalModalError = document.getElementById('principal-modal-error');
const confirmPrincipalDelete = document.getElementById('confirm-principal-delete');

document.addEventListener('submit', async (event) => {
    const form = event.target;
    if (!form.matches('.delete-parent-form')) return;
    if (form.dataset.confirmed === '1') {
        delete form.dataset.confirmed;
        return;
    }
    event.preventDefault();

    if (form.dataset.principal !== '1') {
        if (window.confirm('¿Seguro que deseas eliminar este apoderado? Esta acción no se puede deshacer.')) {
            form.dataset.confirmed = '1';
            form.requestSubmit();
        }
        return;
    }

    principalDeleteForm = form;
    replacementSelect.replaceChildren(new Option('Cargando apoderados...', ''));
    replacementSelect.disabled = true;
    confirmPrincipalDelete.disabled = true;
    principalModalError.classList.add('d-none');
    principalModal.show();
    try {
        const url = new URL(window.location.href);
        url.search = '';
        url.searchParams.set('ajax', 'principal_candidates');
        url.searchParams.set('student_id', form.elements.student_id.value);
        url.searchParams.set('responsable_id', form.elements.id.value);
        const response = await fetch(url, { headers: { Accept: 'application/json' } });
        if (!response.ok) throw new Error('No se pudieron cargar los apoderados vinculados.');
        const candidates = await response.json();
        replacementSelect.replaceChildren(new Option('Selecciona un apoderado', ''));
        candidates.forEach((candidate) => {
            const fullName = [candidate.apellido_paterno, candidate.apellido_materno, candidate.nombres]
                .filter(Boolean).join(' ');
            replacementSelect.add(new Option(fullName, candidate.id));
        });
        replacementSelect.disabled = candidates.length === 0;
        confirmPrincipalDelete.disabled = candidates.length === 0;
        if (candidates.length === 0) {
            principalModalError.textContent = 'No hay otro apoderado vinculado que pueda ser Principal.';
            principalModalError.classList.remove('d-none');
        }
    } catch (error) {
        principalModalError.textContent = error.message;
        principalModalError.classList.remove('d-none');
    }
});

replacementSelect.addEventListener('change', () => {
    confirmPrincipalDelete.disabled = replacementSelect.value === '';
});
confirmPrincipalDelete.addEventListener('click', () => {
    if (!principalDeleteForm || replacementSelect.value === '') return;
    principalDeleteForm.elements.replacement_id.value = replacementSelect.value;
    principalDeleteForm.dataset.confirmed = '1';
    principalModal.hide();
    principalDeleteForm.requestSubmit();
});

async function loadOptions(select, action, parentId, placeholder, selected = '') {
    select.replaceChildren(new Option(placeholder, ''));
    select.disabled = !parentId;
    if (!parentId) return;

    const response = await fetch(`padres.php?ajax=${action}&id=${encodeURIComponent(parentId)}`, {
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
    await loadOptions(seccionSelect, 'secciones', '', 'Todas las secciones');
});

gradoSelect.addEventListener('change', () =>
    loadOptions(seccionSelect, 'secciones', gradoSelect.value, 'Todas las secciones')
);

loadOptions(gradoSelect, 'grados', nivelSelect.value, 'Todos los grados', selectedGrade)
    .then(() => loadOptions(seccionSelect, 'secciones', gradoSelect.value, 'Todas las secciones', selectedSection))
    .catch((error) => console.error(error));
</script>
</body>
</html>