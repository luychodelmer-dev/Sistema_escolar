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
        echo '<tr><td colspan="6" class="text-center text-muted py-4">No hay matrículas para mostrar.</td></tr>';
        return;
    }
    foreach ($rows as $matricula) {
        echo '<tr><td>' . htmlspecialchars($matricula['nombres'] . ' ' . $matricula['apellido_paterno'] . ' ' . $matricula['apellido_materno']) . '</td>';
        echo '<td>' . htmlspecialchars($matricula['dni']) . '</td><td>' . htmlspecialchars($matricula['nivel']) . '</td>';
        echo '<td>' . htmlspecialchars((string) $matricula['grado']) . '</td><td>' . htmlspecialchars($matricula['seccion']) . '</td>';
        echo '<td>' . htmlspecialchars((string) $matricula['anio']) . '</td></tr>';
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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>body { background: #f4f7fb; color: #172b4d; } .page-header { background: #172b4d; color: #fff; } .card { border: 0; border-radius: 12px; } .required::after { content: ' *'; color: #dc3545; } .institution-brand { display: flex; align-items: center; gap: .6rem; white-space: nowrap; } .institution-brand img { width: 42px; height: 42px; object-fit: contain; border-radius: 50%; background: #fff; } .institution-brand span { font-size: 1.1rem; white-space: nowrap; } .header-user-photo { width: 52px; height: 52px; object-fit: cover; border: 2px solid rgba(255, 255, 255, .85); border-radius: 50%; } @media (max-width: 768px) { .institution-brand span { font-size: .95rem; } }</style>
</head>
<body>
<?php render_app_header(panel_home_path(), 'logout.php'); ?>
<main class="container py-4">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4"><div><h1 class="h3 mb-1">Matrículas</h1><p class="text-muted mb-0">Registra matrículas por nivel, grado y sección.</p></div><a href="<?= htmlspecialchars(panel_home_path()) ?>" class="btn btn-outline-primary">Volver al panel</a></div>
    <?php if ($mensaje !== ''): ?><div class="alert alert-success"><?= htmlspecialchars($mensaje) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert alert-danger" role="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($puedeMatricular): ?><section class="card shadow-sm mb-4"><div class="card-body p-4"><h2 class="h5 mb-3">Nueva matrícula</h2><form method="POST" class="row g-3">
        <div class="col-12"><fieldset><legend class="form-label fs-6">Tipo de estudiante</legend><div class="d-flex gap-4"><label class="form-check"><input class="form-check-input" type="radio" name="modo_estudiante" value="nuevo" <?= $estudianteId === null ? 'checked' : '' ?>><span class="form-check-label">Nuevo Estudiante</span></label><label class="form-check"><input class="form-check-input" type="radio" name="modo_estudiante" value="existente" <?= $estudianteId !== null ? 'checked' : '' ?>><span class="form-check-label">Estudiante Existente</span></label></div></fieldset></div>
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
            <h3 class="h6 border-bottom pb-2 mt-3">Padres o contactos de emergencia</h3>
            <?php foreach ([
                'padre' => ['Padre', 'padre'],
                'madre' => ['Madre', 'madre'],
                'emergencia' => ['Contacto de emergencia', 'emergencia'],
            ] as $prefix => [$label, $type]): ?>
                <fieldset class="col-12 row g-3 border rounded p-3 mx-0">
                    <legend class="float-none w-auto px-2 fs-6"><?= htmlspecialchars($label) ?></legend>
                    <div class="col-12">
                        <label class="form-check-label">
                            <input class="form-check-input me-2" type="radio" name="apoderado_principal"
                                   value="<?= htmlspecialchars($type) ?>" required
                                   <?= (($_POST['apoderado_principal'] ?? '') === $type) ? 'checked' : '' ?>>
                            Responsable de la matrícula actual
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
        <div class="col-12"><button class="btn btn-success" type="submit">Guardar matrícula</button></div>
    </form></div></section><?php endif; ?>
    <section class="card shadow-sm"><div class="card-body p-4"><form method="GET" class="row g-2 mb-3" data-live-filter data-live-target="#matriculas-rows"><div class="col-sm-9"><label class="visually-hidden" for="buscar">Buscar estudiante</label><input class="form-control" id="buscar" name="buscar" value="<?= htmlspecialchars($busqueda) ?>" placeholder="Buscar por nombre o DNI"></div><div class="col-sm-3"><button class="btn btn-outline-primary w-100" type="submit">Buscar</button></div></form><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th>Estudiante</th><th>DNI</th><th>Nivel</th><th>Grado</th><th>Sección</th><th>Año</th></tr></thead><tbody id="matriculas-rows"><?php $renderMatriculaRows($matriculas); ?></tbody></table></div></div></section>
</main>
<script>
const estudianteIdInput = document.getElementById('estudiante_id');
const buscarEstudianteInput = document.getElementById('buscar-estudiante');
const estudianteSugerencias = document.getElementById('estudiante-sugerencias');
const buscarEstudianteWrap = document.getElementById('buscar-estudiante-wrap');
const buscarHermanoInput = document.getElementById('buscar-hermano');
const buscarHermanoWrap = document.getElementById('buscar-hermano-wrap');
const hermanoIdInput = document.getElementById('hermano_id');
const hermanoSugerencias = document.getElementById('hermano-sugerencias');
const familyContactFields = document.getElementById('family-contact-fields');
const familyPrefixes = ['padre', 'madre', 'emergencia'];
const studentDataInputs = ['nombres', 'apellido_paterno', 'apellido_materno', 'dni'].map((id) => document.getElementById(id));
const studentModeRadios = document.querySelectorAll('input[name="modo_estudiante"]');
let studentSearchTimer;
let studentSearchRequest;
let studentDataRequest;
let siblingSearchTimer;
let siblingSearchRequest;

function clearFamilyData() {
    familyPrefixes.forEach((prefix) => {
        ['dni', 'nombres', 'apellido_paterno', 'apellido_materno', 'celular'].forEach((field) => {
            document.getElementById(`${prefix}_${field}`).value = '';
        });
    });
    document.getElementById('emergencia_parentesco').value = '';
    document.querySelectorAll('input[name="apoderado_principal"]').forEach((radio) => {
        radio.checked = false;
    });
}

function fillFamilyData(family, lastMatricula) {
    clearFamilyData();
    const selectedResponsibleId = String(lastMatricula?.apoderado_principal_id ?? '');
    familyPrefixes.forEach((prefix) => {
        const contact = family?.[prefix];
        if (!contact) return;
        ['dni', 'nombres', 'apellido_paterno', 'apellido_materno', 'celular'].forEach((field) => {
            document.getElementById(`${prefix}_${field}`).value = contact[field] ?? '';
        });
        if (prefix === 'emergencia') {
            document.getElementById('emergencia_parentesco').value = contact.parentesco ?? '';
        }
        const radio = document.querySelector(`input[name="apoderado_principal"][value="${prefix}"]`);
        if (radio && (String(contact.id) === selectedResponsibleId || contact.es_apoderado === true || contact.es_apoderado === 't')) {
            radio.checked = true;
        }
    });
}

function setStudentMode(mode, clear = true) {
    const existing = mode === 'existente';
    buscarEstudianteWrap.classList.toggle('d-none', !existing);
    buscarHermanoWrap.classList.toggle('d-none', existing);
    familyContactFields.classList.remove('d-none');
    studentDataInputs.forEach((input) => {
        input.readOnly = existing;
        if (clear) input.value = '';
    });
    if (!existing && clear) {
        estudianteIdInput.value = '';
        clearFamilyData();
        buscarEstudianteInput.value = '';
        estudianteSugerencias.replaceChildren();
    }
    if (existing && clear) {
        hermanoIdInput.value = '';
        buscarHermanoInput.value = '';
        hermanoSugerencias.replaceChildren();
    }
}

studentModeRadios.forEach((radio) => {
    radio.addEventListener('change', () => setStudentMode(radio.value));
});
setStudentMode(estudianteIdInput.value ? 'existente' : 'nuevo', false);

buscarEstudianteInput.addEventListener('input', () => {
    estudianteIdInput.value = '';
    studentDataRequest?.abort();
    familyContactFields.classList.remove('d-none');
    studentDataInputs.forEach((input) => { input.value = ''; });
    clearFamilyData();
    estudianteSugerencias.replaceChildren();
    clearTimeout(studentSearchTimer);
    studentSearchRequest?.abort();
    const term = buscarEstudianteInput.value.trim();
    if (term.length < 2) return;

    studentSearchTimer = setTimeout(async () => {
        studentSearchRequest = new AbortController();
        try {
            const query = new URLSearchParams({ ajax: 'buscar_estudiantes', q: term });
            const response = await fetch(`matriculas.php?${query}`, { signal: studentSearchRequest.signal });
            if (!response.ok) throw new Error('No se pudieron buscar estudiantes.');
            const estudiantes = await response.json();
            estudiantes.forEach((estudiante) => {
                const opcion = document.createElement('button');
                opcion.type = 'button';
                opcion.className = 'list-group-item list-group-item-action';
                opcion.textContent = `${estudiante.apellido_paterno} ${estudiante.apellido_materno}, ${estudiante.nombres} · DNI ${estudiante.dni}`;
                opcion.addEventListener('click', () => {
                    estudianteIdInput.value = estudiante.id;
                    studentDataInputs[0].value = estudiante.nombres;
                    studentDataInputs[1].value = estudiante.apellido_paterno;
                    studentDataInputs[2].value = estudiante.apellido_materno;
                    studentDataInputs[3].value = estudiante.dni;
                    buscarEstudianteInput.value = opcion.textContent;
                    estudianteSugerencias.replaceChildren();
                    familyContactFields.classList.remove('d-none');
                    loadExistingStudent(estudiante.id);
                });
                estudianteSugerencias.append(opcion);
            });
            if (!estudiantes.length) {
                const empty = document.createElement('div');
                empty.className = 'list-group-item text-muted';
                empty.textContent = 'No se encontraron estudiantes.';
                estudianteSugerencias.append(empty);
            }
        } catch (error) {
            if (error.name !== 'AbortError') console.error(error);
        }
    }, 250);
});

async function loadExistingStudent(id, { populateStudent = true, populateHistory = true } = {}) {
    studentDataRequest?.abort();
    studentDataRequest = new AbortController();
    try {
        const response = await fetch(`matriculas.php?ajax=cargar_estudiante&id=${encodeURIComponent(id)}`, {
            signal: studentDataRequest.signal
        });
        const data = await response.json();
        if (!response.ok) throw new Error(data.error || 'No se pudieron cargar los datos del estudiante.');

        const student = data.student;
        if (populateStudent) {
            studentDataInputs[0].value = student.nombres;
            studentDataInputs[1].value = student.apellido_paterno;
            studentDataInputs[2].value = student.apellido_materno;
            studentDataInputs[3].value = student.dni;
        }
        fillFamilyData(data.family, data.last_matricula);
        if (populateHistory) populateAcademicHistory(data.last_matricula);
    } catch (error) {
        if (error.name === 'AbortError') return;
        console.error(error);
        const message = document.createElement('div');
        message.className = 'alert alert-danger mt-2';
        message.textContent = error.message;
        const target = populateStudent ? estudianteSugerencias : hermanoSugerencias;
        target.replaceChildren(message);
        if (!populateStudent) hermanoIdInput.value = '';
    }
}

buscarHermanoInput.addEventListener('input', () => {
    hermanoIdInput.value = '';
    clearTimeout(siblingSearchTimer);
    siblingSearchRequest?.abort();
    studentDataRequest?.abort();
    hermanoSugerencias.replaceChildren();
    const term = buscarHermanoInput.value.trim();
    if (term.length < 2) return;

    siblingSearchTimer = setTimeout(async () => {
        siblingSearchRequest = new AbortController();
        try {
            const query = new URLSearchParams({ ajax: 'buscar_estudiantes', q: term });
            const response = await fetch(`matriculas.php?${query}`, { signal: siblingSearchRequest.signal });
            if (!response.ok) throw new Error('No se pudieron buscar hermanos.');
            const estudiantes = await response.json();
            estudiantes.forEach((hermano) => {
                const opcion = document.createElement('button');
                opcion.type = 'button';
                opcion.className = 'list-group-item list-group-item-action';
                opcion.textContent = `${hermano.apellido_paterno} ${hermano.apellido_materno}, ${hermano.nombres} · DNI ${hermano.dni}`;
                opcion.addEventListener('click', async () => {
                    hermanoIdInput.value = hermano.id;
                    buscarHermanoInput.value = opcion.textContent;
                    hermanoSugerencias.replaceChildren();
                    estudianteIdInput.value = '';
                    studentModeRadios.forEach((radio) => { radio.checked = radio.value === 'nuevo'; });
                    setStudentMode('nuevo', false);
                    try {
                        await loadExistingStudent(hermano.id, { populateStudent: false, populateHistory: false });
                    } catch (error) {
                        hermanoIdInput.value = '';
                        console.error(error);
                    }
                });
                hermanoSugerencias.append(opcion);
            });
            if (!estudiantes.length) {
                const empty = document.createElement('div');
                empty.className = 'list-group-item text-muted';
                empty.textContent = 'No se encontraron estudiantes.';
                hermanoSugerencias.append(empty);
            }
        } catch (error) {
            if (error.name !== 'AbortError') console.error(error);
        }
    }, 250);
});

const levelSelect = document.getElementById('nivel_id');
const gradeSelect = document.getElementById('grado_id');
const sectionSelect = document.getElementById('seccion_id');
const gradeSectionInput = document.getElementById('grado_seccion_id');
const updateGrades = () => {
    Array.from(gradeSelect.options).forEach((option) => {
        option.hidden = option.value !== '' && option.dataset.nivelId !== levelSelect.value;
    });
    if (gradeSelect.selectedOptions[0]?.hidden) {
        gradeSelect.value = '';
    }
    updateSections();
};
const updateSections = () => {
    Array.from(sectionSelect.options).forEach((option) => {
        option.hidden = option.value !== '' && option.dataset.gradoId !== gradeSelect.value;
    });
    const selected = sectionSelect.selectedOptions[0];
    if (!selected || selected.hidden) {
        sectionSelect.value = '';
        gradeSectionInput.value = '';
    } else {
        gradeSectionInput.value = selected.dataset.gradoSeccionId;
    }
};
levelSelect.addEventListener('change', updateGrades);
gradeSelect.addEventListener('change', updateSections);
sectionSelect.addEventListener('change', updateSections);
updateGrades();

function populateAcademicHistory(lastMatricula) {
    if (!lastMatricula?.nivel_id) {
        levelSelect.value = '';
        gradeSelect.value = '';
        sectionSelect.value = '';
        gradeSectionInput.value = '';
        updateGrades();
        return;
    }
    gradeSelect.value = '';
    sectionSelect.value = '';
    gradeSectionInput.value = '';
    levelSelect.value = String(lastMatricula.nivel_id);
    updateGrades();

    const gradeOptions = Array.from(gradeSelect.options).filter((option) => !option.hidden && option.value);
    const nextGrade = gradeOptions.find((option) =>
        Number(option.dataset.numero) === Number(lastMatricula.grado_numero) + 1
    );
    const previousGrade = gradeOptions.find((option) => option.value === String(lastMatricula.grado_id));
    const chosenGrade = nextGrade || previousGrade;
    if (!chosenGrade) return;
    gradeSelect.value = chosenGrade.value;
    updateSections();

    const visibleSections = Array.from(sectionSelect.options).filter((option) => !option.hidden && option.value);
    const previousSection = visibleSections.find((option) => option.value === String(lastMatricula.seccion_id));
    sectionSelect.value = (previousSection || visibleSections[0])?.value || '';
    updateSections();
}
</script>
</body>
</html>
