<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/header.php';

require_auth(['asistente', 'director', 'hiperadmin']);
if (isset($_GET['ajax']) && $_GET['ajax'] === 'cargar_estudiante') {
    require_permission('p_matricular');
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) {
        http_response_code(400);
        echo json_encode(['error' => 'Estudiante inválido.']);
        exit;
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT e.id, e.dni, e.nombres, e.apellido_paterno, e.apellido_materno,
                    familia.padre_id, familia.padre_dni, familia.padre_nombres,
                    familia.padre_apellido_paterno, familia.padre_apellido_materno,
                    familia.padre_celular, familia.padre_es_apoderado,
                    familia.madre_id, familia.madre_dni, familia.madre_nombres,
                    familia.madre_apellido_paterno, familia.madre_apellido_materno,
                    familia.madre_celular, familia.madre_es_apoderado,
                    familia.emergencia_id, familia.emergencia_dni, familia.emergencia_nombres,
                    familia.emergencia_apellido_paterno, familia.emergencia_apellido_materno,
                    familia.emergencia_parentesco, familia.emergencia_celular,
                    familia.emergencia_es_apoderado,
                    ultima.nivel_id, ultima.grado_id, ultima.grado_numero, ultima.seccion_id,
                    ultima.apoderado_principal_id
             FROM estudiante e
             LEFT JOIN LATERAL (
                 SELECT m.apoderado_principal_id, COALESCE(m.nivel_id, g.nivel_id) AS nivel_id,
                        gs.grado_id, g.numero AS grado_numero, gs.seccion_id
                 FROM matricula m
                 LEFT JOIN anio a ON a.id = m.anio_id
                 LEFT JOIN grado_seccion gs ON gs.id = m.grado_seccion_id
                 LEFT JOIN grado g ON g.id = gs.grado_id
                 WHERE m.estudiante_id = e.id
                 ORDER BY a.anio DESC NULLS LAST, m.id DESC
                 LIMIT 1
             ) ultima ON TRUE
             LEFT JOIN LATERAL (
                 SELECT
                     padre.id AS padre_id, padre.dni AS padre_dni, padre.nombres AS padre_nombres,
                     padre.apellido_paterno AS padre_apellido_paterno,
                     padre.apellido_materno AS padre_apellido_materno, padre.celular AS padre_celular,
                     padre.es_apoderado AS padre_es_apoderado,
                     madre.id AS madre_id, madre.dni AS madre_dni, madre.nombres AS madre_nombres,
                     madre.apellido_paterno AS madre_apellido_paterno,
                     madre.apellido_materno AS madre_apellido_materno, madre.celular AS madre_celular,
                     madre.es_apoderado AS madre_es_apoderado,
                     emergencia.id AS emergencia_id, emergencia.dni AS emergencia_dni,
                     emergencia.nombres AS emergencia_nombres,
                     emergencia.apellido_paterno AS emergencia_apellido_paterno,
                     emergencia.apellido_materno AS emergencia_apellido_materno,
                     emergencia.parentesco AS emergencia_parentesco,
                     emergencia.celular AS emergencia_celular,
                     emergencia.es_apoderado AS emergencia_es_apoderado
                 FROM (SELECT 1) base
                 LEFT JOIN LATERAL (
                     SELECT r.id, r.dni, r.nombres, r.apellido_paterno, r.apellido_materno,
                            r.celular, er.es_apoderado
                     FROM estudiante_responsable er
                     JOIN responsable r ON r.id = er.responsable_id
                     WHERE er.estudiante_id = e.id AND er.tipo = 'padre'
                     ORDER BY (r.id = ultima.apoderado_principal_id) DESC, er.es_apoderado DESC, er.id
                     LIMIT 1
                 ) padre ON TRUE
                 LEFT JOIN LATERAL (
                     SELECT r.id, r.dni, r.nombres, r.apellido_paterno, r.apellido_materno,
                            r.celular, er.es_apoderado
                     FROM estudiante_responsable er
                     JOIN responsable r ON r.id = er.responsable_id
                     WHERE er.estudiante_id = e.id AND er.tipo = 'madre'
                     ORDER BY (r.id = ultima.apoderado_principal_id) DESC, er.es_apoderado DESC, er.id
                     LIMIT 1
                 ) madre ON TRUE
                 LEFT JOIN LATERAL (
                     SELECT r.id, r.dni, r.nombres, r.apellido_paterno, r.apellido_materno,
                            er.parentesco, r.celular, er.es_apoderado
                     FROM estudiante_responsable er
                     JOIN responsable r ON r.id = er.responsable_id
                     WHERE er.estudiante_id = e.id AND er.tipo IN ('emergencia', 'otro')
                     ORDER BY (r.id = ultima.apoderado_principal_id) DESC, er.es_apoderado DESC, er.id
                     LIMIT 1
                 ) emergencia ON TRUE
             ) familia ON TRUE
             WHERE e.id = :id"
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            http_response_code(404);
            echo json_encode(['error' => 'No se encontró el estudiante.']);
            exit;
        }

        $student = array_intersect_key($row, array_flip(['id', 'dni', 'nombres', 'apellido_paterno', 'apellido_materno']));
        $family = [];
        foreach (['padre', 'madre', 'emergencia'] as $type) {
            $contact = [];
            foreach (['id', 'dni', 'nombres', 'apellido_paterno', 'apellido_materno', 'celular', 'parentesco', 'es_apoderado'] as $field) {
                $key = $type . '_' . $field;
                if (array_key_exists($key, $row)) {
                    $contact[$field] = $row[$key];
                }
            }
            $family[$type] = $contact['id'] === null ? null : $contact;
        }

        echo json_encode([
            'student' => $student,
            'family' => $family,
            'last_matricula' => [
                'nivel_id' => $row['nivel_id'],
                'grado_id' => $row['grado_id'],
                'grado_numero' => $row['grado_numero'],
                'seccion_id' => $row['seccion_id'],
                'apoderado_principal_id' => $row['apoderado_principal_id'],
            ],
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $exception) {
        error_log('Error al cargar estudiante para matrícula: ' . $exception->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'No se pudieron cargar los datos del estudiante.']);
    }
    exit;
}
if (isset($_GET['ajax']) && $_GET['ajax'] === 'buscar_estudiantes') {
    require_permission('p_matricular');
    header('Content-Type: application/json; charset=utf-8');
    $termino = trim($_GET['q'] ?? '');
    if (strlen($termino) < 2) {
        echo '[]';
        exit;
    }
    $stmt = $pdo->prepare(
        'SELECT estudiante.id, estudiante.dni, estudiante.nombres,
                estudiante.apellido_paterno, estudiante.apellido_materno
         FROM estudiante
         WHERE estudiante.dni ILIKE :dni
            OR CONCAT_WS(\' \', estudiante.nombres, estudiante.apellido_paterno, estudiante.apellido_materno) ILIKE :nombre
         ORDER BY estudiante.apellido_paterno, estudiante.nombres
         LIMIT 15'
    );
    $stmt->execute(['dni' => '%' . $termino . '%', 'nombre' => '%' . $termino . '%']);
    echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE);
    exit;
}
$pdo->exec('ALTER TABLE matricula ADD COLUMN IF NOT EXISTS apoderado_principal_id BIGINT');
$pdo->exec(<<<'SQL'
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'matricula_apoderado_principal_id_fkey') THEN
        ALTER TABLE matricula ADD CONSTRAINT matricula_apoderado_principal_id_fkey
            FOREIGN KEY (apoderado_principal_id) REFERENCES responsable(id) ON DELETE SET NULL;
    END IF;
END $$
SQL);

$csrfToken = $_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));
$error = '';
$mensaje = '';
$busqueda = trim($_GET['buscar'] ?? '');
$puedeMatricular = has_permission('p_matricular');
$estudianteId = null;
$estudianteExistente = null;
$levelOptions = $pdo->query('SELECT id, nombre FROM nivel ORDER BY id')->fetchAll();
$gradeOptions = $pdo->query(
    "SELECT g.id, n.nombre AS nivel, g.nombre AS grado, g.nivel_id, g.numero
     FROM grado g
     JOIN nivel n ON n.id = g.nivel_id
     WHERE g.activo = TRUE
     ORDER BY n.id, g.numero"
)->fetchAll();
$sectionOptions = $pdo->query(
    "SELECT gs.id AS grado_seccion_id, gs.grado_id, s.id AS seccion_id, s.nombre AS seccion
     FROM grado_seccion gs
     JOIN seccion s ON s.id = gs.seccion_id
     WHERE gs.activo = TRUE AND s.activo = TRUE
     ORDER BY s.nombre"
)->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_permission('p_matricular');
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!is_string($submittedToken) || !hash_equals($csrfToken, $submittedToken)) {
        $error = 'Solicitud inválida o sesión expirada. Recarga la página e inténtalo nuevamente.';
    } else {
        $estudianteIdRaw = trim($_POST['estudiante_id'] ?? '');
    $estudianteId = $estudianteIdRaw === '' ? null : filter_var($estudianteIdRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($estudianteId === false) {
        $error = 'Selecciona un estudiante existente válido.';
        $estudianteId = null;
    }
    $siblingIdRaw = $_POST['hermano_id'] ?? '';
    $siblingId = !is_string($siblingIdRaw)
        ? false
        : ($siblingIdRaw === '' ? null : filter_var($siblingIdRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]));
    if ($siblingId === false) {
        $error = 'Selecciona un hermano registrado válido.';
        $siblingId = null;
    }
    $datos = [
        'nombres' => trim($_POST['nombres'] ?? ''),
        'apellido_paterno' => trim($_POST['apellido_paterno'] ?? ''),
        'apellido_materno' => trim($_POST['apellido_materno'] ?? ''),
        'dni' => trim($_POST['dni'] ?? ''),
        'nivel_id' => (int) ($_POST['nivel_id'] ?? 0),
        'grado_id' => (int) ($_POST['grado_id'] ?? 0),
        'seccion_id' => (int) ($_POST['seccion_id'] ?? 0),
        'grado_seccion_id' => (int) ($_POST['grado_seccion_id'] ?? 0),
        'anio' => (int) ($_POST['anio'] ?? date('Y')),
        'padre_dni' => trim($_POST['padre_dni'] ?? ''),
        'padre_nombres' => trim($_POST['padre_nombres'] ?? ''),
        'padre_apellido_paterno' => trim($_POST['padre_apellido_paterno'] ?? ''),
        'padre_apellido_materno' => trim($_POST['padre_apellido_materno'] ?? ''),
        'padre_celular' => trim($_POST['padre_celular'] ?? ''),
        'madre_dni' => trim($_POST['madre_dni'] ?? ''),
        'madre_nombres' => trim($_POST['madre_nombres'] ?? ''),
        'madre_apellido_paterno' => trim($_POST['madre_apellido_paterno'] ?? ''),
        'madre_apellido_materno' => trim($_POST['madre_apellido_materno'] ?? ''),
        'madre_celular' => trim($_POST['madre_celular'] ?? ''),
        'emergencia_dni' => trim($_POST['emergencia_dni'] ?? ''),
        'emergencia_nombres' => trim($_POST['emergencia_nombres'] ?? ''),
        'emergencia_apellido_paterno' => trim($_POST['emergencia_apellido_paterno'] ?? ''),
        'emergencia_apellido_materno' => trim($_POST['emergencia_apellido_materno'] ?? ''),
        'emergencia_parentesco' => trim($_POST['emergencia_parentesco'] ?? ''),
        'emergencia_celular' => trim($_POST['emergencia_celular'] ?? ''),
    ];
    foreach ([
        'nombres', 'apellido_paterno', 'apellido_materno',
        'padre_nombres', 'padre_apellido_paterno', 'padre_apellido_materno',
        'madre_nombres', 'madre_apellido_paterno', 'madre_apellido_materno',
        'emergencia_nombres', 'emergencia_apellido_paterno', 'emergencia_apellido_materno',
        'emergencia_parentesco',
    ] as $textField) {
        $datos[$textField] = mb_strtoupper($datos[$textField], 'UTF-8');
    }
    $primaryTypeRaw = $_POST['apoderado_principal'] ?? '';
    $primaryType = is_string($primaryTypeRaw) ? $primaryTypeRaw : '';
    if ($siblingId !== null && $estudianteId !== null) {
        $error = 'El vínculo con un hermano se usa al registrar un estudiante nuevo.';
    }
    $estudianteExistente = null;
    if ($estudianteId !== null) {
        $existingStudentStmt = $pdo->prepare('SELECT id, dni, nombres, apellido_paterno, apellido_materno FROM estudiante WHERE id = :id');
        $existingStudentStmt->execute(['id' => $estudianteId]);
        $estudianteExistente = $existingStudentStmt->fetch();
        if (!$estudianteExistente) {
            $error = 'El estudiante seleccionado ya no existe.';
        } else {
            foreach (['dni', 'nombres', 'apellido_paterno', 'apellido_materno'] as $campo) {
                $datos[$campo] = $estudianteExistente[$campo];
            }
        }
    }

    $hasFamilyContact = in_array(true, array_map(
        fn (string $field): bool => $datos[$field] !== '',
        [
            'padre_dni', 'padre_nombres', 'padre_apellido_paterno', 'padre_apellido_materno', 'padre_celular',
            'madre_dni', 'madre_nombres', 'madre_apellido_paterno', 'madre_apellido_materno', 'madre_celular',
            'emergencia_dni', 'emergencia_nombres', 'emergencia_apellido_paterno', 'emergencia_apellido_materno', 'emergencia_parentesco', 'emergencia_celular',
        ]
    ), true);
    $familyContacts = [
        ['label' => 'El padre', 'prefix' => 'padre', 'extra' => []],
        ['label' => 'La madre', 'prefix' => 'madre', 'extra' => []],
        ['label' => 'El contacto de emergencia', 'prefix' => 'emergencia', 'extra' => ['parentesco', 'celular']],
    ];
    $contactError = '';
    foreach ($familyContacts as $contact) {
        $prefix = $contact['prefix'];
        $contactFields = array_merge(
            [$prefix . '_nombres', $prefix . '_apellido_paterno', $prefix . '_apellido_materno'],
            array_map(fn (string $field): string => $prefix . '_' . $field, $contact['extra'])
        );
        $hasContactData = $datos[$prefix . '_dni'] !== '' || in_array(true, array_map(
            fn (string $field): bool => $datos[$field] !== '',
            $contactFields
        ), true);
        if (!$hasContactData) {
            continue;
        }
        $requiredContactFields = [$prefix . '_dni', $prefix . '_nombres', $prefix . '_apellido_paterno', ...array_map(fn (string $field): string => $prefix . '_' . $field, $contact['extra'])];
        if (in_array('', array_map(fn (string $field): string => $datos[$field], $requiredContactFields), true)) {
            $contactError = $contact['label'] . ': completa DNI, nombres y apellido paterno' . ($contact['extra'] ? ', parentesco y celular.' : '.');
            break;
        }
        if (!preg_match('/^\d{8}$/', $datos[$prefix . '_dni'])) {
            $contactError = $contact['label'] . ': el DNI debe tener 8 números.';
            break;
        }
        if ($datos[$prefix . '_celular'] !== '' && !preg_match('/^[0-9]{9}$/', $datos[$prefix . '_celular'])) {
            $contactError = $contact['label'] . ': el celular debe tener exactamente 9 dígitos.';
            break;
        }
    }
    if ($error !== '') {
    } elseif (!$estudianteExistente && in_array('', [$datos['nombres'], $datos['apellido_paterno'], $datos['apellido_materno'], $datos['dni']], true)) {
        $error = 'Completa todos los campos obligatorios.';
    } elseif (!$estudianteExistente && !$hasFamilyContact) {
        $error = 'Registra al menos un padre o un contacto de emergencia.';
    } elseif ($contactError !== '') {
        $error = $contactError;
    } elseif (!in_array($primaryType, ['padre', 'madre', 'emergencia'], true) || $datos[$primaryType . '_dni'] === '') {
        $error = 'Selecciona un padre, madre o contacto de emergencia como responsable de esta matrícula.';
    } elseif (!preg_match('/^\d{8}$/', $datos['dni'])) {
        $error = 'El DNI debe tener 8 números.';
    } elseif ($datos['anio'] < 2020 || $datos['anio'] > 2100) {
        $error = 'El año de matrícula no es válido.';
    } else {
        try {
            $yearStmt = $pdo->prepare('SELECT id FROM anio WHERE anio = :anio AND activo = TRUE');
            $yearStmt->execute(['anio' => $datos['anio']]);
            $yearId = $yearStmt->fetchColumn();
            if (!$yearId) {
                throw new RuntimeException('El año de matrícula no está habilitado.');
            }

            $combinationStmt = $pdo->prepare(
                'SELECT gs.id, gs.grado_id, gs.seccion_id, n.id AS nivel_id, n.nombre AS nivel
                 FROM grado_seccion gs
                 JOIN grado g ON g.id = gs.grado_id
                 JOIN nivel n ON n.id = g.nivel_id
                 JOIN seccion s ON s.id = gs.seccion_id
                 WHERE gs.id = :grado_seccion_id AND gs.activo = TRUE AND g.activo = TRUE AND s.activo = TRUE'
            );
            $combinationStmt->execute(['grado_seccion_id' => $datos['grado_seccion_id']]);
            $academic = $combinationStmt->fetch();
            if (!$academic || (int) $academic['id'] !== $datos['grado_seccion_id'] || (int) $academic['nivel_id'] !== $datos['nivel_id'] || (int) $academic['grado_id'] !== $datos['grado_id'] || (int) $academic['seccion_id'] !== $datos['seccion_id']) {
                throw new RuntimeException('El grado o la sección no están habilitados.');
            }

            $pdo->beginTransaction();
            if ($estudianteExistente) {
                $studentId = (int) $estudianteExistente['id'];
            } else {
                $studentStmt = $pdo->prepare('INSERT INTO estudiante (dni, nombres, apellido_paterno, apellido_materno) VALUES (:dni, :nombres, :apellido_paterno, :apellido_materno) RETURNING id');
                $studentStmt->execute([
                    'dni' => $datos['dni'],
                    'nombres' => $datos['nombres'],
                    'apellido_paterno' => $datos['apellido_paterno'],
                    'apellido_materno' => $datos['apellido_materno'],
                ]);
                $studentId = $studentStmt->fetchColumn();
            }
            $primaryResponsibleId = null;
            $clearPrimaryStmt = $pdo->prepare(
                'UPDATE estudiante_responsable SET es_apoderado = FALSE WHERE estudiante_id = :estudiante_id'
            );
            $clearPrimaryStmt->execute(['estudiante_id' => $studentId]);

            foreach ([
                ['dni' => 'padre_dni', 'nombres' => 'padre_nombres', 'apellido_paterno' => 'padre_apellido_paterno', 'apellido_materno' => 'padre_apellido_materno', 'celular' => 'padre_celular', 'tipo' => 'padre', 'parentesco' => 'Padre'],
                ['dni' => 'madre_dni', 'nombres' => 'madre_nombres', 'apellido_paterno' => 'madre_apellido_paterno', 'apellido_materno' => 'madre_apellido_materno', 'celular' => 'madre_celular', 'tipo' => 'madre', 'parentesco' => 'Madre'],
                ['dni' => 'emergencia_dni', 'nombres' => 'emergencia_nombres', 'apellido_paterno' => 'emergencia_apellido_paterno', 'apellido_materno' => 'emergencia_apellido_materno', 'celular' => 'emergencia_celular', 'tipo' => 'emergencia', 'parentesco' => $datos['emergencia_parentesco']],
            ] as $contact) {
                if ($datos[$contact['dni']] === '') {
                    continue;
                }
                $responsibleStmt = $pdo->prepare('INSERT INTO responsable (dni, nombres, apellido_paterno, apellido_materno, celular) VALUES (:dni, :nombres, :apellido_paterno, :apellido_materno, :celular) ON CONFLICT (dni) DO UPDATE SET nombres = EXCLUDED.nombres, apellido_paterno = EXCLUDED.apellido_paterno, apellido_materno = EXCLUDED.apellido_materno, celular = EXCLUDED.celular RETURNING id');
                $responsibleStmt->execute(['dni' => $datos[$contact['dni']], 'nombres' => $datos[$contact['nombres']], 'apellido_paterno' => $datos[$contact['apellido_paterno']], 'apellido_materno' => $datos[$contact['apellido_materno']], 'celular' => $datos[$contact['celular']]]);
                $responsibleId = $responsibleStmt->fetchColumn();
                $esPrincipal = $contact['tipo'] === $primaryType;
                $linkStmt = $pdo->prepare('INSERT INTO estudiante_responsable (estudiante_id, responsable_id, parentesco, tipo, es_apoderado) VALUES (:estudiante_id, :responsable_id, :parentesco, :tipo, :es_apoderado) ON CONFLICT (estudiante_id, responsable_id) DO UPDATE SET parentesco = EXCLUDED.parentesco, tipo = EXCLUDED.tipo, es_apoderado = EXCLUDED.es_apoderado');
                $linkStmt->execute(['estudiante_id' => $studentId, 'responsable_id' => $responsibleId, 'parentesco' => mb_strtoupper($contact['parentesco'] ?: 'Contacto de emergencia', 'UTF-8'), 'tipo' => $contact['tipo'], 'es_apoderado' => $esPrincipal]);
                if ($esPrincipal) {
                    $primaryResponsibleId = $responsibleId;
                }
            }

            if (!$primaryResponsibleId) {
                throw new RuntimeException('El estudiante debe tener un apoderado principal para esta matrícula.');
            }
            if ($siblingId !== null) {
                if ((int) $siblingId === (int) $studentId) {
                    throw new RuntimeException('El estudiante no puede vincularse como su propio hermano.');
                }
                $siblingFamilyStmt = $pdo->prepare(
                    'SELECT 1 FROM estudiante_responsable
                     WHERE estudiante_id = :hermano_id AND responsable_id = :responsable_id'
                );
                $siblingFamilyStmt->execute([
                    'hermano_id' => $siblingId,
                    'responsable_id' => $primaryResponsibleId,
                ]);
                if (!$siblingFamilyStmt->fetchColumn()) {
                    throw new RuntimeException('El responsable principal no coincide con la familia del hermano seleccionado.');
                }
            }

            $stmt = $pdo->prepare('INSERT INTO matricula (estudiante_id, anio_id, nivel_id, grado_seccion_id, apoderado_principal_id) VALUES (:estudiante_id, :anio_id, :nivel_id, :grado_seccion_id, :apoderado_principal_id)');
            $stmt->execute(['estudiante_id' => $studentId, 'anio_id' => $yearId, 'nivel_id' => $academic['nivel_id'], 'grado_seccion_id' => $academic['id'], 'apoderado_principal_id' => $primaryResponsibleId]);
            $pdo->commit();
            registrar_auditoria('crear_matricula', 'Registró matrícula para DNI ' . $datos['dni'] . ' del año ' . $datos['anio'] . '.');
            header('Location: matriculas.php?guardado=1');
            exit;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $detail = trim($exception->getMessage());
            error_log('Error al guardar matrícula: ' . $detail);
            $error = str_contains(strtolower($detail), 'duplicate key') || $exception->getCode() === '23505'
                ? 'Ese estudiante ya tiene una matrícula registrada para ese año.'
                : 'No se pudo guardar la matrícula. Detalle: ' . $detail;
        }
    }
    }
}

if (isset($_GET['guardado'])) {
    $mensaje = 'Matrícula registrada correctamente.';
}

$sql = 'SELECT m.*, a.anio AS anio, al.nombres, al.apellido_paterno, al.apellido_materno, al.dni, n.nombre AS nivel, g.numero AS grado, s.nombre AS seccion
    FROM matricula m
    JOIN anio a ON a.id = m.anio_id
    JOIN estudiante al ON al.id = m.estudiante_id
    JOIN nivel n ON n.id = m.nivel_id
    JOIN grado_seccion gs ON gs.id = m.grado_seccion_id
    JOIN grado g ON g.id = gs.grado_id
    JOIN seccion s ON s.id = gs.seccion_id';
$params = [];
if ($busqueda !== '') {
    $sql .= ' WHERE al.nombres ILIKE :busqueda OR al.apellido_paterno ILIKE :busqueda OR al.apellido_materno ILIKE :busqueda OR al.dni ILIKE :busqueda';
    $params['busqueda'] = "%$busqueda%";
}
$sql .= ' ORDER BY a.anio DESC, m.id DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$matriculas = $stmt->fetchAll();
$renderMatriculaRows = static function (array $rows): void {
    if (!$rows) {
        echo '<tr><td colspan="8" class="text-center py-5">
            <div class="py-4">
                <i class="bi bi-search text-muted" style="font-size: 2.2rem;"></i>
                <h5 class="mt-3 text-secondary fw-semibold">No se encontraron matrículas</h5>
                <p class="text-muted small mb-0">Intenta con otro término de búsqueda por DNI o nombres.</p>
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
    foreach ($rows as $matricula) {
        $inicial1 = mb_substr(trim($matricula['apellido_paterno'] ?? ''), 0, 1);
        $inicial2 = mb_substr(trim($matricula['nombres'] ?? ''), 0, 1);
        $iniciales = mb_strtoupper($inicial1 . $inicial2);
        if ($iniciales === '') $iniciales = 'AL';
        $col = $avatarColors[$secuencia % count($avatarColors)];
        $avatarStyle = 'background-color:' . $col['bg'] . ';color:' . $col['text'] . ';';

        $nivLow = mb_strtolower($matricula['nivel'] ?? '', 'UTF-8');
        $dotCol = str_contains($nivLow, 'inic') ? '#eab308' : (str_contains($nivLow, 'prim') ? '#ef4444' : (str_contains($nivLow, 'secu') ? '#2563eb' : '#94a3b8'));

        $nombreCompleto = $matricula['apellido_paterno'] . ' ' . $matricula['apellido_materno'] . ', ' . $matricula['nombres'];

        echo '<tr><td class="text-center"><span class="badge-secuencia">#' . $secuencia++ . '</span></td>';
        echo '<td>
                <div class="d-flex align-items-center gap-2">
                    <div class="avatar-circle" style="' . $avatarStyle . '">' . htmlspecialchars($iniciales) . '</div>
                    <span class="fw-bold text-dark" style="font-size: 1.02rem;"><i class="bi bi-mortarboard text-secondary me-1" style="font-size: 0.95rem;"></i>' . htmlspecialchars($nombreCompleto) . '</span>
                </div>
              </td>';
        echo '<td><span class="font-monospace fw-bold text-dark" style="font-size: 0.96rem;">' . htmlspecialchars($matricula['dni']) . '</span></td>';
        echo '<td>
                <div class="fw-semibold text-dark d-flex align-items-center gap-1.5" style="font-size: 0.95rem;">
                    <span class="d-inline-block rounded-circle flex-shrink-0" style="width: 8px; height: 8px; background-color: ' . $dotCol . ';"></span>
                    ' . htmlspecialchars($matricula['nivel']) . '
                </div>
              </td>';
        echo '<td style="font-size: 0.96rem;">' . htmlspecialchars((string) $matricula['grado']) . '</td><td style="font-size: 0.96rem;">' . htmlspecialchars($matricula['seccion']) . '</td>';
        echo '<td><span class="badge bg-light text-secondary border px-2 py-1" style="font-size: 0.85rem;"><i class="bi bi-calendar-check me-1"></i>' . htmlspecialchars((string) $matricula['anio']) . '</span></td>';
        echo '<td class="text-end">
                <div class="d-inline-flex align-items-center gap-1">
                    <a href="ficha_matricula.php?id=' . (int)$matricula['id'] . '" target="_blank" rel="opener" class="btn btn-sm btn-outline-primary d-inline-flex align-items-center gap-1" title="Imprimir Ficha de Matrícula" style="border-radius: 7px; padding: 0.25rem 0.55rem; font-size: 0.82rem;">
                        <i class="bi bi-printer"></i> Ficha
                    </a>
                    <a href="ver_estudiante.php?id=' . (int)$matricula['estudiante_id'] . '" class="btn btn-sm btn-light border text-secondary" title="Ver perfil del estudiante" style="border-radius: 7px; padding: 0.25rem 0.45rem; font-size: 0.82rem;">
                        <i class="bi bi-eye"></i>
                    </a>
                </div>
              </td></tr>';
    }
};
if (($_GET['ajax'] ?? '') === 'tabla') {
    header('Content-Type: text/html; charset=utf-8');
    $renderMatriculaRows($matriculas);
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Matrículas - Sistema Escolar</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../assets/css/app.css">
    <link rel="stylesheet" href="../assets/css/matriculas.css">
</head>
<body>
<?php render_app_header(panel_home_path(), 'logout.php'); ?>
<main class="container-fluid px-3 px-md-4 px-xl-5 py-4">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1 small">
                    <li class="breadcrumb-item"><a href="<?= htmlspecialchars(panel_home_path()) ?>" class="text-decoration-none text-muted"><i class="bi bi-house-door"></i> Inicio</a></li>
                    <li class="breadcrumb-item active text-primary fw-semibold" aria-current="page">Matrículas</li>
                </ol>
            </nav>
            <h1 class="h3 fw-bold mb-1 text-dark d-flex align-items-center gap-2">
                Matrículas Escolares
                <span class="badge bg-primary-subtle text-primary border border-primary-subtle fs-6 fw-semibold">Gestión 2026</span>
            </h1>
            <p class="text-muted mb-0 small">Registro y asignación de vacantes por nivel, grado y sección.</p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <a href="<?= htmlspecialchars(panel_home_path()) ?>" class="btn btn-outline-secondary d-inline-flex align-items-center gap-2 px-3 py-2" style="border-radius: 10px;">
                <i class="bi bi-arrow-left"></i> Volver al panel
            </a>
        </div>
    </div>
    <?php if ($mensaje !== ''): ?><div class="alert alert-success d-flex align-items-center gap-2 mb-3 shadow-sm border-0"><i class="bi bi-check-circle-fill fs-5"></i> <?= htmlspecialchars($mensaje) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert alert-danger d-flex align-items-center gap-2 mb-3 shadow-sm border-0" role="alert"><i class="bi bi-exclamation-triangle-fill fs-5"></i> <?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($puedeMatricular): ?><section class="form-card mb-4"><div class="p-4"><h2 class="h5 mb-3 fw-bold text-dark d-flex align-items-center gap-2"><i class="bi bi-journal-plus text-primary"></i> Nueva matrícula</h2><form method="POST" class="row g-3">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
        <div class="col-12"><fieldset><legend class="form-label fs-6 fw-semibold">Tipo de estudiante</legend><div class="d-flex gap-4"><label class="form-check"><input class="form-check-input" type="radio" name="modo_estudiante" value="nuevo" <?= $estudianteId === null ? 'checked' : '' ?>><span class="form-check-label fw-medium">Nuevo Estudiante</span></label><label class="form-check"><input class="form-check-input" type="radio" name="modo_estudiante" value="existente" <?= $estudianteId !== null ? 'checked' : '' ?>><span class="form-check-label fw-medium">Estudiante Existente</span></label></div></fieldset></div>
        <input type="hidden" id="estudiante_id" name="estudiante_id" value="<?= htmlspecialchars((string) ($estudianteId ?? '')) ?>">
        <div class="col-12 d-none" id="buscar-estudiante-wrap"><label class="form-label" for="buscar-estudiante">Buscar por DNI o nombre</label><input class="form-control" id="buscar-estudiante" autocomplete="off"><div id="estudiante-sugerencias" class="list-group mt-1"></div></div>
        <input type="hidden" id="hermano_id" name="hermano_id" value="<?= htmlspecialchars((string) ($_POST['hermano_id'] ?? '')) ?>">
        <div class="col-12" id="buscar-hermano-wrap"><label class="form-label" for="buscar-hermano">¿Vincular con hermano ya registrado? (opcional)</label><input class="form-control" id="buscar-hermano" autocomplete="off" placeholder="Buscar por DNI o nombre del hermano"><div id="hermano-sugerencias" class="list-group mt-1"></div><div class="form-text">Se completarán sus padres y responsables; el nuevo estudiante compartirá el responsable principal seleccionado.</div></div>
        <div class="col-md-4"><label class="form-label required" for="nombres">Nombres</label><input class="form-control" id="nombres" name="nombres" style="text-transform: uppercase;" value="<?= htmlspecialchars($_POST['nombres'] ?? '') ?>" required></div>
        <div class="col-md-4"><label class="form-label required" for="apellido_paterno">Apellido paterno</label><input class="form-control" id="apellido_paterno" name="apellido_paterno" style="text-transform: uppercase;" value="<?= htmlspecialchars($_POST['apellido_paterno'] ?? '') ?>" required></div>
        <div class="col-md-4"><label class="form-label required" for="apellido_materno">Apellido materno</label><input class="form-control" id="apellido_materno" name="apellido_materno" style="text-transform: uppercase;" value="<?= htmlspecialchars($_POST['apellido_materno'] ?? '') ?>" required></div>
        <div class="col-md-3"><label class="form-label required" for="dni">DNI</label><input class="form-control" id="dni" name="dni" inputmode="numeric" maxlength="8" style="text-transform: uppercase;" value="<?= htmlspecialchars($_POST['dni'] ?? '') ?>" required></div>
        <div class="col-md-3"><label class="form-label required" for="nivel_id">Nivel</label><select class="form-select" id="nivel_id" name="nivel_id" required><option value="">Seleccionar</option><?php foreach ($levelOptions as $option): ?><option value="<?= (int) $option['id'] ?>" <?= (($_POST['nivel_id'] ?? '') == (string) $option['id']) ? 'selected' : '' ?>><?= htmlspecialchars($option['nombre']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><label class="form-label required" for="grado_id">Grado</label><select class="form-select" id="grado_id" name="grado_id" required><option value="">Seleccionar</option><?php foreach ($gradeOptions as $option): ?><option value="<?= (int) $option['id'] ?>" data-nivel-id="<?= (int) $option['nivel_id'] ?>" data-numero="<?= (int) $option['numero'] ?>" <?= (($_POST['grado_id'] ?? '') == (string) $option['id']) ? 'selected' : '' ?>><?= htmlspecialchars($option['grado']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><label class="form-label required" for="seccion_id">Sección</label><select class="form-select" id="seccion_id" name="seccion_id" required><option value="">Seleccionar</option><?php foreach ($sectionOptions as $option): ?><option value="<?= (int) $option['seccion_id'] ?>" data-grado-id="<?= (int) $option['grado_id'] ?>" data-grado-seccion-id="<?= (int) $option['grado_seccion_id'] ?>" <?= (($_POST['seccion_id'] ?? '') == (string) $option['seccion_id']) ? 'selected' : '' ?>><?= htmlspecialchars($option['seccion']) ?></option><?php endforeach; ?></select></div>
        <input type="hidden" id="grado_seccion_id" name="grado_seccion_id" value="<?= htmlspecialchars($_POST['grado_seccion_id'] ?? '') ?>">
        <div class="col-md-2"><label class="form-label required" for="anio">Año</label><input class="form-control" id="anio" name="anio" type="number" min="2020" max="2100" value="<?= htmlspecialchars((string) ($_POST['anio'] ?? date('Y'))) ?>" required></div>
        <div class="col-12 row g-3" id="family-contact-fields">
            <h3 class="h6 border-bottom pb-2 mt-3 fw-bold text-dark d-flex align-items-center gap-2"><i class="bi bi-people-fill text-primary"></i> Padres o contactos de emergencia</h3>
            <?php foreach ([
                'padre' => ['Padre', 'padre'],
                'madre' => ['Madre', 'madre'],
                'emergencia' => ['Contacto de emergencia', 'emergencia'],
            ] as $prefix => [$label, $type]): ?>
                <fieldset class="col-12 row g-3 border rounded p-3 mx-0">
                    <legend class="float-none w-auto px-2 fs-6 fw-semibold text-secondary"><?= htmlspecialchars($label) ?></legend>
                    <div class="col-12">
                        <label class="form-check-label fw-semibold text-primary">
                            <input class="form-check-input me-2" type="radio" name="apoderado_principal"
                                   value="<?= htmlspecialchars($type) ?>" required
                                   <?= (($_POST['apoderado_principal'] ?? '') === $type) ? 'checked' : '' ?>>
                            Responsable de la matrícula actual (Apoderado Principal)
                        </label>
                    </div>
                    <div class="col-md-3"><label class="form-label" for="<?= $prefix ?>_dni">DNI</label><input class="form-control" id="<?= $prefix ?>_dni" name="<?= $prefix ?>_dni" maxlength="8" inputmode="numeric" style="text-transform: uppercase;" value="<?= htmlspecialchars($_POST[$prefix . '_dni'] ?? '') ?>"></div>
                    <div class="col-md-3"><label class="form-label" for="<?= $prefix ?>_nombres">Nombres</label><input class="form-control" id="<?= $prefix ?>_nombres" name="<?= $prefix ?>_nombres" style="text-transform: uppercase;" value="<?= htmlspecialchars($_POST[$prefix . '_nombres'] ?? '') ?>"></div>
                    <div class="col-md-3"><label class="form-label" for="<?= $prefix ?>_apellido_paterno">Apellido paterno</label><input class="form-control" id="<?= $prefix ?>_apellido_paterno" name="<?= $prefix ?>_apellido_paterno" style="text-transform: uppercase;" value="<?= htmlspecialchars($_POST[$prefix . '_apellido_paterno'] ?? '') ?>"></div>
                    <div class="col-md-3"><label class="form-label" for="<?= $prefix ?>_apellido_materno">Apellido materno</label><input class="form-control" id="<?= $prefix ?>_apellido_materno" name="<?= $prefix ?>_apellido_materno" style="text-transform: uppercase;" value="<?= htmlspecialchars($_POST[$prefix . '_apellido_materno'] ?? '') ?>"></div>
                    <?php if ($prefix === 'emergencia'): ?>
                        <div class="col-md-3"><label class="form-label" for="emergencia_parentesco">Parentesco</label><input class="form-control" id="emergencia_parentesco" name="emergencia_parentesco" style="text-transform: uppercase;" placeholder="Abuela, tío..." value="<?= htmlspecialchars($_POST['emergencia_parentesco'] ?? '') ?>"></div>
                    <?php endif; ?>
                    <div class="col-md-3"><label class="form-label" for="<?= $prefix ?>_celular">Celular</label><input class="form-control" id="<?= $prefix ?>_celular" name="<?= $prefix ?>_celular" minlength="9" maxlength="9" pattern="[0-9]{9}" style="text-transform: uppercase;" value="<?= htmlspecialchars($_POST[$prefix . '_celular'] ?? '') ?>"></div>
                </fieldset>
            <?php endforeach; ?>
        </div>
        <div class="col-12 pt-2"><button class="btn btn-primary d-inline-flex align-items-center gap-2 px-4 shadow-sm fw-semibold" style="border-radius: 10px;" type="submit"><i class="bi bi-check-circle-fill"></i> Guardar matrícula</button></div>
    </form></div></section><?php endif; ?>
    <section class="table-card p-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <h3 class="h5 mb-0 fw-bold text-dark d-flex align-items-center gap-2"><i class="bi bi-clock-history text-primary"></i> Historial de Matrículas</h3>
            <span class="badge bg-primary px-3 py-2 fs-6" id="matriculas-count-badge"><?= count($matriculas) ?> matrículas</span>
        </div>
        <form method="GET" class="row g-2 mb-3" data-live-filter data-live-target="#matriculas-rows" data-live-count-target="#matriculas-count-badge" data-live-count-singular="matrícula" data-live-count-plural="matrículas">
            <div class="col-sm-9">
                <div class="input-group">
                    <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-search"></i></span>
                    <input class="form-control border-start-0 ps-0" id="buscar" name="buscar" value="<?= htmlspecialchars($busqueda) ?>" placeholder="Buscar por DNI o nombres de estudiante...">
                </div>
            </div>
            <div class="col-sm-3">
                <button class="btn btn-outline-primary w-100 d-inline-flex align-items-center justify-content-center gap-1" type="submit">
                    <i class="bi bi-search"></i> Buscar
                </button>
            </div>
        </form>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-dark">
                    <tr>
                        <th style="width: 65px;" class="text-center">#</th>
                        <th style="min-width: 230px;">Estudiante</th>
                        <th style="min-width: 115px;">DNI</th>
                        <th style="min-width: 130px;">Nivel</th>
                        <th style="min-width: 90px;">Grado</th>
                        <th style="min-width: 90px;">Sección</th>
                        <th style="min-width: 85px;">Año</th>
                        <th style="min-width: 140px;" class="text-end">Acciones</th>
                    </tr>
                </thead>
                <tbody id="matriculas-rows"><?php $renderMatriculaRows($matriculas); ?></tbody>
            </table>
        </div>
    </section>
</main>
<script src="../assets/js/matriculas.js" defer></script>
</body>
</html>
