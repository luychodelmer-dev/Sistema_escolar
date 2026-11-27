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
$puedeEditar = has_permission('p_padres');
$csrfToken = $_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    require_permission('p_padres');
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
        $studentId = is_string($studentIdRaw) && trim($studentIdRaw) !== ''
            ? filter_var($studentIdRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : null;
        $replacementIdRaw = $_POST['replacement_id'] ?? '';
        $replacementId = is_string($replacementIdRaw) && trim($replacementIdRaw) !== ''
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
        $accionAuditoria = $studentId !== null ? 'Desvinculó al apoderado ' : 'Eliminó al apoderado ';
        registrar_auditoria('eliminar_apoderado', $accionAuditoria . $responsibleName . ' (ID ' . $deleteId . ').');
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
    require_permission('p_padres');
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
         ORDER BY LOWER(TRIM(r.apellido_paterno)) ASC, LOWER(TRIM(COALESCE(r.apellido_materno, \'\'))) ASC, LOWER(TRIM(r.nombres)) ASC'
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
    WHERE p.responsable_id = r.id
       OR (p.responsable_id IS NULL AND EXISTS (
           SELECT 1
           FROM matricula pm
           WHERE pm.id = p.matricula_id AND pm.apoderado_principal_id = r.id
       ))
) pagos ON TRUE
SQL;
$conditions = [];
if ($busqueda !== '') {
    $conditions[] = "(
        CONCAT_WS(' ', r.dni, r.nombres, r.apellido_paterno, r.apellido_materno, al.dni, al.nombres, al.apellido_paterno, al.apellido_materno) ILIKE :busqueda
        OR CONCAT_WS(' ', r.apellido_paterno, r.apellido_materno, r.nombres) ILIKE :busqueda
        OR CONCAT_WS(' ', al.apellido_paterno, al.apellido_materno, al.nombres) ILIKE :busqueda
    )";
    $params['busqueda'] = "%$busqueda%";
}
if ($matriculaFilters) {
    $conditions[] = 'matricula.id IS NOT NULL';
}
if ($conditions) {
    $sql .= ' WHERE ' . implode(' AND ', $conditions);
}
$sql .= ' ORDER BY LOWER(TRIM(r.apellido_paterno)) ASC, LOWER(TRIM(COALESCE(r.apellido_materno, \'\'))) ASC, LOWER(TRIM(r.nombres)) ASC, LOWER(TRIM(al.apellido_paterno)) ASC, LOWER(TRIM(COALESCE(al.apellido_materno, \'\'))) ASC, LOWER(TRIM(al.nombres)) ASC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$apoderados = $stmt->fetchAll();

// Contabilizar por apoderado principal / familia única
$familiasUnicas = [];
$conMatricula = 0;
$sinMatricula = 0;
$totalRecaudado = 0.0;
$conPagos = 0;
$porNivel = ['Inicial' => 0, 'Primaria' => 0, 'Secundaria' => 0, 'Sin Asignar' => 0];

foreach ($apoderados as $row) {
    $fId = $row['id'];
    if (!isset($familiasUnicas[$fId])) {
        $familiasUnicas[$fId] = true;

        if (!empty($row['nivel'])) {
            $conMatricula++;
            $nivelStr = mb_strtolower($row['nivel'], 'UTF-8');
            if (str_contains($nivelStr, 'inic')) {
                $porNivel['Inicial']++;
            } elseif (str_contains($nivelStr, 'prim')) {
                $porNivel['Primaria']++;
            } elseif (str_contains($nivelStr, 'secu')) {
                $porNivel['Secundaria']++;
            } else {
                $porNivel['Sin Asignar']++;
            }
        } else {
            $sinMatricula++;
            $porNivel['Sin Asignar']++;
        }

        $total = (float) ($row['pagos_total'] ?? 0);
        $totalRecaudado += $total;
        if ($total > 0 || (int)($row['pagos_cantidad'] ?? 0) > 0) {
            $conPagos++;
        }
    }
}

$totalApoderados = count($familiasUnicas);

$pctMatricula = $totalApoderados > 0 ? round(($conMatricula / $totalApoderados) * 100, 1) : 0;
$pctSinMatricula = $totalApoderados > 0 ? round(($sinMatricula / $totalApoderados) * 100, 1) : 0;
$pctInicial = $totalApoderados > 0 ? round(($porNivel['Inicial'] / $totalApoderados) * 100, 1) : 0;
$pctPrimaria = $totalApoderados > 0 ? round(($porNivel['Primaria'] / $totalApoderados) * 100, 1) : 0;
$pctSecundaria = $totalApoderados > 0 ? round(($porNivel['Secundaria'] / $totalApoderados) * 100, 1) : 0;
$pctSinAsignar = $totalApoderados > 0 ? round(($porNivel['Sin Asignar'] / $totalApoderados) * 100, 1) : 0;

$renderApoderados = static function (array $rows) use ($puedeEditar, $csrfToken): void {
    if (!$rows) {
        echo '<tr><td colspan="9" class="text-center py-5">
            <div class="py-4">
                <i class="bi bi-search text-muted" style="font-size: 2.2rem;"></i>
                <h5 class="mt-3 text-secondary fw-semibold">No se encontraron apoderados</h5>
                <p class="text-muted small mb-0">Intenta con otro término de búsqueda o limpia los filtros.</p>
            </div>
        </td></tr>';
        return;
    }
    $secuencia = 1;
    $avatarColors = [
        ['bg' => '#eff6ff', 'text' => '#1d4ed8'],
        ['bg' => '#f0fdf4', 'text' => '#15803d'],
        ['bg' => '#fdf2f8', 'text' => '#be185d'],
        ['bg' => '#fefce8', 'text' => '#a16207'],
        ['bg' => '#f5f3ff', 'text' => '#6d28d9'],
        ['bg' => '#ecfeff', 'text' => '#0e7490']
    ];
    foreach ($rows as $row) {
        $nombre = $row['apellido_paterno'] . ' ' . ($row['apellido_materno'] ?? '') . ', ' . $row['nombres'];
        $nombresArr = preg_split('/\s+/', trim($row['nombres'] ?? ''));
        $inicial1 = mb_substr($nombresArr[0] ?? '', 0, 1);
        $inicial2 = mb_substr($row['apellido_paterno'] ?? '', 0, 1);
        $iniciales = mb_strtoupper($inicial1 . $inicial2);
        if ($iniciales === '') $iniciales = 'AP';
        $colorIdx = abs(crc32($row['dni'] ?? '0')) % count($avatarColors);
        $avatarStyle = 'background-color: ' . $avatarColors[$colorIdx]['bg'] . '; color: ' . $avatarColors[$colorIdx]['text'] . ';';

        echo '<tr>';
        echo '<td class="text-center align-middle"><span class="badge-secuencia">#' . $secuencia++ . '</span></td>';
        echo '<td class="align-middle"><span class="font-monospace fw-bold text-dark px-1" style="font-size: 0.96rem;">' . htmlspecialchars($row['dni']) . '</span></td>';
        echo '<td class="align-middle">
                <div class="d-flex align-items-center gap-2">
                    <div class="avatar-circle" style="' . $avatarStyle . '">' . htmlspecialchars($iniciales) . '</div>
                    <div style="min-width: 0; max-width: 270px;">
                        <a href="ver_padre.php?id=' . (int) $row['id'] . '" class="fw-bold text-decoration-none text-dark d-block" style="font-size: 1.02rem; word-break: break-word;">' . htmlspecialchars($nombre) . '</a>
                        ' . (!empty($row['es_apoderado']) ? '<span class="badge bg-primary-subtle text-primary border border-primary-subtle" style="font-size: 0.78rem; padding: 0.25rem 0.5rem;">Principal</span>' : '') . '
                    </div>
                </div>
              </td>';
        echo '<td class="align-middle">';
        if ($row['estudiante_id']) {
            $estudianteNombre = $row['estudiante_apellido_paterno'] . ' ' . $row['estudiante_apellido_materno'] . ', ' . $row['estudiante_nombres'];
            echo '<div class="d-flex align-items-center gap-1.5" style="min-width: 0; max-width: 250px;">
                    <i class="bi bi-mortarboard text-secondary" style="font-size: 0.95rem;"></i>
                    <a href="ver_estudiante.php?id=' . (int) $row['estudiante_id'] . '" class="text-decoration-none text-body fw-semibold d-inline-block" style="font-size: 0.95rem; word-break: break-word;" title="' . htmlspecialchars($estudianteNombre) . '">' . htmlspecialchars($estudianteNombre) . '</a>
                  </div>';
        } else {
            echo '<span class="text-muted fst-italic" style="font-size: 0.9rem;">Sin estudiante</span>';
        }
        echo '</td>';
        echo '<td class="align-middle"><span class="badge bg-light text-secondary border px-2 py-1" style="font-size: 0.85rem;">' . htmlspecialchars($row['parentesco'] ?? '-') . '</span></td>';
        echo '<td class="align-middle">';
        if ($row['nivel']) {
            $nivLow = mb_strtolower($row['nivel']);
            $dotCol = str_contains($nivLow, 'inicial') ? '#eab308' : (str_contains($nivLow, 'primaria') ? '#ef4444' : '#2563eb');
            echo '<div class="fw-semibold text-dark d-flex align-items-center gap-1.5" style="font-size: 0.95rem;"><span class="d-inline-block rounded-circle flex-shrink-0" style="width: 8px; height: 8px; background-color: ' . $dotCol . ';"></span>' . htmlspecialchars($row['nivel'] . ' ' . $row['grado'] . ' "' . $row['seccion'] . '"') . '</div>';
            echo '<small class="text-muted d-block mt-0.5" style="font-size: 0.82rem;"><i class="bi bi-calendar-check"></i> ' . htmlspecialchars((string) $row['anio']) . '</small>';
        } else {
            echo '<span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle" style="font-size: 0.82rem; padding: 0.3rem 0.55rem;">Sin matrícula</span>';
        }
        echo '</td>';
        echo '<td class="align-middle">';
        if ((float) $row['pagos_total'] > 0) {
            echo '<a class="btn btn-sm btn-outline-success d-inline-flex align-items-center gap-1 py-1 px-2 fw-semibold" style="font-size: 0.88rem;" href="pagos.php?buscar=' . rawurlencode($row['nombres'] . ' ' . $row['apellido_paterno'] . ' ' . ($row['apellido_materno'] ?? '')) . '">
                    <i class="bi bi-wallet2 text-success"></i> S/ ' . htmlspecialchars(number_format((float) $row['pagos_total'], 2)) . '
                    <span class="badge bg-success text-white ms-1" style="font-size: 0.75rem;">' . (int) $row['pagos_cantidad'] . '</span>
                  </a>';
        } else {
            echo '<a class="btn btn-sm btn-light border text-muted py-1 px-2" style="font-size: 0.85rem;" href="pagos.php?buscar=' . rawurlencode($row['nombres'] . ' ' . $row['apellido_paterno'] . ' ' . ($row['apellido_materno'] ?? '')) . '">
                    <span class="text-secondary opacity-75">Sin pagos</span>
                  </a>';
        }
        echo '</td>';
        echo '<td class="align-middle">';
        $cel = $row['celular_actual'] ?: ($row['celular'] ?? '');
        if ($cel && $cel !== '-') {
            $cleanCel = preg_replace('/\D/', '', $cel);
            echo '<div class="d-flex align-items-center gap-2">
                    <a href="tel:' . htmlspecialchars($cel) . '" class="text-decoration-none text-dark fw-semibold" style="font-size: 0.94rem;" title="Llamar">
                        <i class="bi bi-telephone-fill text-primary" style="font-size: 0.85rem;"></i> ' . htmlspecialchars($cel) . '
                    </a>';
            if (strlen($cleanCel) === 9 && str_starts_with($cleanCel, '9')) {
                echo '<a href="https://wa.me/51' . $cleanCel . '" target="_blank" class="text-success" title="WhatsApp" style="font-size: 1.05rem;">
                        <i class="bi bi-whatsapp"></i>
                      </a>';
            }
            echo '</div>';
        } else {
            echo '<span class="text-muted" style="font-size: 0.9rem;">Sin celular</span>';
        }
        echo '<div class="text-muted mt-1" style="font-size: 0.85rem;">
                <i class="bi bi-card-heading"></i> APAFA: <strong>' . htmlspecialchars($row['nro_tarjeta_apafa'] ?? 'S/A') . '</strong>
              </div>';
        echo '</td>';
        echo '<td class="align-middle text-end">';
        $attribute = static fn ($value): string => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
        echo '<div class="d-inline-flex gap-1">';
        echo '<a href="ficha_padre.php?id=' . (int) $row['id'] . '" target="_blank" rel="opener" class="btn btn-sm btn-outline-primary d-inline-flex align-items-center gap-1" title="Imprimir Ficha Familiar" style="padding: 0.35rem 0.6rem;"><i class="bi bi-printer"></i></a>';
        if ($puedeEditar) {
            echo '<button type="button" class="btn btn-sm btn-outline-primary edit-contact d-inline-flex align-items-center gap-1" data-bs-toggle="modal" data-bs-target="#editContactModal"'
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
                . ' data-emergency-celular="' . $attribute($row['emergencia_celular'] ?? '') . '" title="Editar apoderado"><i class="bi bi-pencil-square"></i> <span class="d-none d-lg-inline">Editar</span></button>';
            echo '<form method="POST" class="delete-parent-form d-inline" action="padres.php" data-principal="' . (!empty($row['es_apoderado']) ? '1' : '0') . '">'
                . '<input type="hidden" name="action" value="delete">'
                . '<input type="hidden" name="csrf_token" value="' . $attribute($csrfToken) . '">'
                . '<input type="hidden" name="id" value="' . (int) $row['id'] . '">'
                . '<input type="hidden" name="student_id" value="' . ($row['estudiante_id'] ? (int) $row['estudiante_id'] : '') . '">'
                . '<input type="hidden" name="replacement_id" value="">'
                . '<button type="submit" class="btn btn-sm btn-outline-danger" title="Eliminar apoderado"><i class="bi bi-trash3"></i></button></form>';
        }
        echo '</div>';
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
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Directorio de Apoderados - Sistema Escolar</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../assets/css/app.css">
    <link rel="stylesheet" href="../assets/css/padres.css">
</head>
<body>
<?php render_app_header(panel_home_path(), 'logout.php'); ?>

    <main class="container-fluid px-3 px-md-4 px-xl-5 py-4">
        <!-- Encabezado del Módulo -->
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
            <div>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb mb-1 small">
                        <li class="breadcrumb-item"><a href="<?= htmlspecialchars(panel_home_path()) ?>" class="text-decoration-none text-muted"><i class="bi bi-house-door"></i> Inicio</a></li>
                        <li class="breadcrumb-item active text-primary fw-semibold" aria-current="page">Padres y Apoderados</li>
                    </ol>
                </nav>
                <h1 class="h3 fw-bold mb-1 text-dark d-flex align-items-center gap-2">
                    Directorio de Apoderados
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle fs-6 fw-semibold">Padrón 2026</span>
                </h1>
                <p class="text-muted mb-0 small">Supervisión integral de familias, vínculos con estudiantes y aportes económicos.</p>
            </div>
            <div class="d-flex align-items-center gap-2">
                <a href="padron_apoderados.php" class="btn btn-outline-primary d-inline-flex align-items-center gap-2 px-3 py-2 fw-semibold shadow-sm" style="border-radius: 10px;">
                    <i class="bi bi-printer"></i> Padrón Oficial
                </a>
                <?php if ($puedeEditar): ?>
                    <a href="nuevo_padre.php" class="btn btn-primary d-inline-flex align-items-center gap-2 shadow-sm px-3 py-2 fw-semibold" style="border-radius: 10px;">
                        <i class="bi bi-person-plus-fill"></i> + Registrar Nuevo
                    </a>
                <?php endif; ?>
                <a href="<?= htmlspecialchars(panel_home_path()) ?>" class="btn btn-outline-secondary d-inline-flex align-items-center gap-1 px-3 py-2" style="border-radius: 10px;">
                    <i class="bi bi-arrow-left"></i> Volver al panel
                </a>
            </div>
        </div>

        <?php if (isset($_GET['eliminado'])): ?><div class="alert alert-success d-flex align-items-center gap-2 shadow-sm border-0"><i class="bi bi-check-circle-fill fs-5"></i> Apoderado desvinculado correctamente.</div><?php endif; ?>
        <?php if (isset($_GET['actualizado'])): ?><div class="alert alert-success d-flex align-items-center gap-2 shadow-sm border-0"><i class="bi bi-check-circle-fill fs-5"></i> Apoderado y contacto de emergencia actualizados correctamente.</div><?php endif; ?>
        <?php if (($_GET['error'] ?? '') === 'delete_blocked'): ?><div class="alert alert-danger d-flex align-items-center gap-2 shadow-sm border-0"><i class="bi bi-shield-lock-fill fs-5"></i> No se puede eliminar el apoderado porque tiene relaciones vigentes, como matrículas o pagos.</div><?php elseif (($_GET['error'] ?? '') === 'orphan'): ?><div class="alert alert-danger d-flex align-items-center gap-2 shadow-sm border-0"><i class="bi bi-exclamation-triangle-fill fs-5"></i> No se puede eliminar: el estudiante no puede quedarse sin apoderados.</div><?php elseif (($_GET['error'] ?? '') === 'replacement_required'): ?><div class="alert alert-danger d-flex align-items-center gap-2 shadow-sm border-0"><i class="bi bi-person-gear fs-5"></i> Debes seleccionar al nuevo apoderado Principal.</div><?php elseif (($_GET['error'] ?? '') === 'not_found'): ?><div class="alert alert-warning d-flex align-items-center gap-2 shadow-sm border-0"><i class="bi bi-question-circle-fill fs-5"></i> No se encontró el apoderado para eliminar.</div><?php elseif (($_GET['error'] ?? '') === 'invalid'): ?><div class="alert alert-warning d-flex align-items-center gap-2 shadow-sm border-0"><i class="bi bi-exclamation-circle-fill fs-5"></i> El apoderado seleccionado no es válido.</div><?php endif; ?>

        <!-- KPI Cards Métricas Gráficas -->
        <div class="row g-3 mb-4">
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="kpi-card h-100 d-flex flex-column justify-content-between">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <span class="text-muted text-uppercase fw-semibold" style="font-size: 0.85rem; letter-spacing: 0.03em;">Total Apoderados</span>
                            <h2 class="display-6 fw-bold text-dark mt-1 mb-0" style="font-size: 1.85rem;"><?= number_format($totalApoderados) ?></h2>
                        </div>
                        <div class="kpi-icon" style="background-color: #eff6ff; color: #2563eb;">
                            <i class="bi bi-people-fill"></i>
                        </div>
                    </div>
                    <div class="mt-3 pt-2 border-top d-flex align-items-center justify-content-between">
                        <span class="text-muted" style="font-size: 0.88rem;">Base de datos escolar</span>
                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle" style="font-size: 0.84rem; padding: 0.35rem 0.65rem;">100% Familias</span>
                    </div>
                </div>
            </div>

            <div class="col-12 col-sm-6 col-xl-3">
                <div class="kpi-card h-100 d-flex flex-column justify-content-between">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <span class="text-muted text-uppercase fw-semibold" style="font-size: 0.85rem; letter-spacing: 0.03em;">Con Matrícula 2026</span>
                            <h2 class="display-6 fw-bold text-success mt-1 mb-0" style="font-size: 1.85rem;"><?= number_format($conMatricula) ?></h2>
                        </div>
                        <div class="kpi-icon" style="background-color: #ecfdf5; color: #059669;">
                            <i class="bi bi-person-check-fill"></i>
                        </div>
                    </div>
                    <div class="mt-3 pt-2 border-top d-flex align-items-center justify-content-between">
                        <span class="text-muted" style="font-size: 0.88rem;"><?= $pctMatricula ?>% del total</span>
                        <span class="badge bg-success-subtle text-success border border-success-subtle" style="font-size: 0.84rem; padding: 0.35rem 0.65rem;">Vigentes</span>
                    </div>
                </div>
            </div>

            <div class="col-12 col-sm-6 col-xl-3">
                <div class="kpi-card h-100 d-flex flex-column justify-content-between">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <span class="text-muted text-uppercase fw-semibold" style="font-size: 0.85rem; letter-spacing: 0.03em;">Sin Matrícula</span>
                            <h2 class="display-6 fw-bold text-warning-emphasis mt-1 mb-0" style="font-size: 1.85rem;"><?= number_format($sinMatricula) ?></h2>
                        </div>
                        <div class="kpi-icon" style="background-color: #fffbeb; color: #d97706;">
                            <i class="bi bi-person-exclamation"></i>
                        </div>
                    </div>
                    <div class="mt-3 pt-2 border-top d-flex align-items-center justify-content-between">
                        <span class="text-muted" style="font-size: 0.88rem;"><?= $pctSinMatricula ?>% pendientes</span>
                        <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle" style="font-size: 0.84rem; padding: 0.35rem 0.65rem;">Por regularizar</span>
                    </div>
                </div>
            </div>

            <div class="col-12 col-sm-6 col-xl-3">
                <div class="kpi-card h-100 d-flex flex-column justify-content-between">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <span class="text-muted text-uppercase fw-semibold" style="font-size: 0.85rem; letter-spacing: 0.03em;">Aportes Económicos</span>
                            <h2 class="display-6 fw-bold text-dark mt-1 mb-0" style="font-size: 1.7rem; white-space: nowrap;">S/ <?= number_format($totalRecaudado, 2) ?></h2>
                        </div>
                        <div class="kpi-icon" style="background-color: #f0fdf4; color: #16a34a;">
                            <i class="bi bi-cash-coin"></i>
                        </div>
                    </div>
                    <div class="mt-3 pt-2 border-top d-flex align-items-center justify-content-between">
                        <span class="text-muted" style="font-size: 0.88rem;"><?= number_format($conPagos) ?> familias con aportes</span>
                        <a href="pagos.php" class="badge bg-success-subtle text-success border border-success-subtle text-decoration-none" style="font-size: 0.84rem; padding: 0.35rem 0.65rem;">Ver pagos &rarr;</a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Contador Gráfico de Distribución por Niveles -->
        <div class="distribution-card mb-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-bar-chart-fill text-primary"></i>
                    <span class="fw-bold text-dark text-uppercase" style="font-size: 0.88rem; letter-spacing: 0.03em;">Distribución de Familias por Nivel Educativo</span>
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
            <form method="GET" class="row g-2 align-items-center" data-live-filter data-live-target="#apoderados-rows" data-live-count-target="#apoderados-count-badge" data-live-count-prefix="Mostrando " data-live-count-singular="apoderado" data-live-count-plural="apoderados">
                <div class="col-lg-4 col-md-12">
                    <div class="input-group">
                        <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-search"></i></span>
                        <input type="search" name="q" class="form-control border-start-0 ps-0" placeholder="Buscar por DNI, nombres de apoderado o alumno..." value="<?= htmlspecialchars($busqueda) ?>">
                    </div>
                </div>
                <div class="col-sm-4 col-lg-2">
                    <select id="filtro-nivel" name="nivel" class="form-select">
                        <option value="">Todos los niveles</option>
                        <?php foreach ($niveles as $nivel): ?>
                            <option value="<?= (int) $nivel['id'] ?>" <?= $nivelId === (int) $nivel['id'] ? 'selected' : '' ?>><?= htmlspecialchars($nivel['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-4 col-lg-2">
                    <select id="filtro-grado" name="grado" class="form-select" <?= $nivelId === null ? 'disabled' : '' ?>>
                        <option value="">Todos los grados</option>
                    </select>
                </div>
                <div class="col-sm-4 col-lg-2">
                    <select id="filtro-seccion" name="seccion" class="form-select" <?= $gradoId === null ? 'disabled' : '' ?>>
                        <option value="">Todas las secciones</option>
                    </select>
                </div>
                <div class="col-lg-2 col-md-12 d-flex gap-2">
                    <button class="btn btn-outline-secondary flex-grow-1 d-inline-flex align-items-center justify-content-center gap-1" type="reset">
                        <i class="bi bi-arrow-counterclockwise"></i> Limpiar
                    </button>
                </div>
            </form>
        </div>

        <!-- Indicador de Resultados y Tabla -->
        <div class="d-flex justify-content-between align-items-center mb-2 px-1">
            <span class="fw-semibold text-secondary" style="font-size: 0.94rem;" id="apoderados-count-badge">
                Mostrando <?= number_format($totalApoderados) ?> <?= $totalApoderados === 1 ? 'apoderado' : 'apoderados' ?>
            </span>
            <span class="small text-muted"><i class="bi bi-info-circle me-1"></i>Listado en tiempo real</span>
        </div>

        <div class="table-card mb-5">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead>
                        <tr>
                            <th style="width: 65px;" class="text-center">#</th>
                            <th style="min-width: 115px;">DNI</th>
                            <th style="min-width: 230px;">Apoderado</th>
                            <th style="min-width: 220px;">Estudiante Vinculado</th>
                            <th style="min-width: 125px;">Parentesco</th>
                            <th style="min-width: 170px;">Nivel / Grado / Sec.</th>
                            <th style="min-width: 160px;">Pagos Registrados</th>
                            <th style="min-width: 170px;">Contacto / APAFA</th>
                            <th style="min-width: 140px;" class="text-end">Acciones</th>
                        </tr>
                    </thead>
                    <tbody id="apoderados-rows"><?php $renderApoderados($apoderados); ?></tbody>
                </table>
            </div>
        </div>
    </main>


        <?php require __DIR__ . '/../../app/views/partials/modal_principal_apoderado.php'; ?>
    <?php require __DIR__ . '/../../app/views/partials/modal_editar_apoderado.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script id="padres-config-data" type="application/json">
<?= json_encode(['gradoId' => $gradoId, 'seccionId' => $seccionId]) ?>
</script>
<script src="../assets/js/padres.js"></script>
</body>
</html>