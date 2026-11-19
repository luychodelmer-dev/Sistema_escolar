<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/auth.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}
require_permission('p_editar');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: padres.php');
    exit;
}

$csrfToken = $_SESSION['csrf_token'] ?? '';
$submittedToken = $_POST['csrf_token'] ?? '';
if ($csrfToken === '' || !is_string($submittedToken) || !hash_equals($csrfToken, $submittedToken)) {
    http_response_code(400);
    exit('Solicitud inválida. Recarga la página e inténtalo nuevamente.');
}

$readId = static function (string $key): int|false|null {
    $rawValue = $_POST[$key] ?? '';
    if (!is_string($rawValue)) {
        return false;
    }
    $value = trim($rawValue);
    if ($value === '') {
        return null;
    }
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $id === false ? false : $id;
};
$readText = static function (string $key): string {
    $value = $_POST[$key] ?? '';
    return is_string($value) ? mb_strtoupper(trim($value), 'UTF-8') : '';
};

$id = $readId('id');
$relacionId = $readId('relacion_id');
$studentId = $readId('student_id');
$emergencyId = $readId('emergency_id');
$emergencyRelacionId = $readId('emergency_relacion_id');
$fatherId = $readId('father_id');
$fatherRelacionId = $readId('father_relacion_id');
$motherId = $readId('mother_id');
$motherRelacionId = $readId('mother_relacion_id');
$dni = $readText('dni');
$nombres = $readText('nombres');
$apellidoPaterno = $readText('apellido_paterno');
$apellidoMaterno = $readText('apellido_materno');
$parentesco = $readText('parentesco');
$direccion = $readText('direccion');
$celular = $readText('celular');
$emergencyDni = $readText('emergency_dni');
$emergencyNombres = $readText('emergency_nombres');
$emergencyApellidoPaterno = $readText('emergency_apellido_paterno');
$emergencyApellidoMaterno = $readText('emergency_apellido_materno');
$emergencyParentesco = $readText('emergency_parentesco');
$emergencyCelular = $readText('emergency_celular');
$familyContacts = [
    'padre' => [
        'id' => $fatherId, 'relation_id' => $fatherRelacionId,
        'dni' => $readText('father_dni'), 'nombres' => $readText('father_nombres'),
        'apellido_paterno' => $readText('father_apellido_paterno'),
        'apellido_materno' => $readText('father_apellido_materno'),
        'parentesco' => $readText('father_parentesco'), 'celular' => $readText('father_celular'),
    ],
    'madre' => [
        'id' => $motherId, 'relation_id' => $motherRelacionId,
        'dni' => $readText('mother_dni'), 'nombres' => $readText('mother_nombres'),
        'apellido_paterno' => $readText('mother_apellido_paterno'),
        'apellido_materno' => $readText('mother_apellido_materno'),
        'parentesco' => $readText('mother_parentesco'), 'celular' => $readText('mother_celular'),
    ],
];
$familyContactHasData = static fn (array $contact): bool => in_array(true, array_map(
    static fn (string $value): bool => $value !== '',
    [$contact['dni'], $contact['nombres'], $contact['apellido_paterno'], $contact['apellido_materno'], $contact['parentesco'], $contact['celular']]
), true);
$emergencyHasData = in_array(true, array_map(
    static fn (string $value): bool => $value !== '',
    [$emergencyDni, $emergencyNombres, $emergencyApellidoPaterno, $emergencyApellidoMaterno, $emergencyParentesco, $emergencyCelular]
), true);
$isValidCellular = static fn (string $value): bool => $value === '' || preg_match('/^[0-9]{9}$/', $value) === 1;

if ($id === null || $id === false || $relacionId === false || $studentId === false ||
    $emergencyId === false || $emergencyRelacionId === false ||
    $fatherId === false || $fatherRelacionId === false || $motherId === false || $motherRelacionId === false ||
    !preg_match('/^\d{8}$/', $dni) || $nombres === '' || strlen($nombres) > 100 ||
    $apellidoPaterno === '' || strlen($apellidoPaterno) > 100 || strlen($apellidoMaterno) > 100 ||
    strlen($parentesco) > 60 || strlen($direccion) > 500 || !$isValidCellular($celular)) {
    http_response_code(400);
    exit('Verifica DNI, nombres, apellidos y datos de contacto del apoderado.');
}

if (($emergencyHasData || $emergencyId !== null) && (
    !preg_match('/^\d{8}$/', $emergencyDni) || $emergencyNombres === '' ||
    $emergencyApellidoPaterno === '' || strlen($emergencyNombres) > 100 ||
    strlen($emergencyApellidoPaterno) > 100 || strlen($emergencyApellidoMaterno) > 100 ||
    $emergencyParentesco === '' || strlen($emergencyParentesco) > 60 || !$isValidCellular($emergencyCelular)
)) {
    http_response_code(400);
    exit('Completa DNI, nombres, apellido paterno y parentesco del contacto de emergencia.');
}

foreach (['padre', 'madre'] as $type) {
    $contact = $familyContacts[$type];
    if ($contact['id'] === null && !$familyContactHasData($contact)) {
        continue;
    }
    if ($studentId === null || $relacionId === null || !preg_match('/^\d{8}$/', $contact['dni']) ||
        $contact['nombres'] === '' || strlen($contact['nombres']) > 100 ||
        $contact['apellido_paterno'] === '' || strlen($contact['apellido_paterno']) > 100 ||
        strlen($contact['apellido_materno']) > 100 || strlen($contact['parentesco']) > 60 ||
        !$isValidCellular($contact['celular'])) {
        http_response_code(400);
        exit('Completa DNI, nombres y apellido paterno válidos para el padre y la madre que estés agregando o editando.');
    }
}

try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare(
        'UPDATE responsable
         SET dni = :dni, nombres = :nombres, apellido_paterno = :apellido_paterno,
             apellido_materno = :apellido_materno, direccion = :direccion,
             celular = :celular, celular_actual = :celular_actual, actualizado_en = CURRENT_TIMESTAMP,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = :id'
    );
    $stmt->execute([
        'dni' => $dni,
        'nombres' => $nombres,
        'apellido_paterno' => $apellidoPaterno,
        'apellido_materno' => $apellidoMaterno !== '' ? $apellidoMaterno : null,
        'direccion' => $direccion !== '' ? $direccion : null,
        'celular' => $celular !== '' ? $celular : null,
        'celular_actual' => $celular !== '' ? $celular : null,
        'id' => $id,
    ]);
    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('Apoderado no encontrado o sin cambios.');
    }

    if ($relacionId !== null) {
        $stmt = $pdo->prepare(
            'UPDATE estudiante_responsable
             SET parentesco = :parentesco, actualizado_en = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
             WHERE id = :relacion_id AND responsable_id = :responsable_id'
        );
        $stmt->execute(['parentesco' => $parentesco, 'relacion_id' => $relacionId, 'responsable_id' => $id]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('La relación del apoderado con el estudiante ya no existe.');
        }
    }

    $contactStmt = $pdo->prepare(
        'SELECT 1 FROM estudiante_responsable
         WHERE id = :relacion_id AND estudiante_id = :estudiante_id AND responsable_id = :responsable_id'
    );
    if ($studentId !== null && $relacionId !== null) {
        $contactStmt->execute(['relacion_id' => $relacionId, 'estudiante_id' => $studentId, 'responsable_id' => $id]);
        if (!$contactStmt->fetchColumn()) {
            throw new RuntimeException('La relación familiar ya no existe.');
        }
    }

    $saveParentOrMother = static function (string $type, array $contact) use (
        $pdo, $studentId, $id, $familyContactHasData
    ): void {
        if ($contact['id'] === null && !$familyContactHasData($contact)) {
            return;
        }
        if ($studentId === null) {
            throw new RuntimeException('No se puede guardar padre o madre sin un estudiante vinculado.');
        }

        if ($contact['id'] !== null) {
            if ($contact['relation_id'] === null) {
                throw new RuntimeException('El vínculo familiar no es válido.');
            }
            $stmt = $pdo->prepare(
                'UPDATE responsable
                 SET dni = :dni, nombres = :nombres, apellido_paterno = :apellido_paterno,
                     apellido_materno = :apellido_materno, celular = :celular, celular_actual = :celular_actual,
                     actualizado_en = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id'
            );
            $stmt->execute([
                'dni' => $contact['dni'], 'nombres' => $contact['nombres'],
                'apellido_paterno' => $contact['apellido_paterno'],
                'apellido_materno' => $contact['apellido_materno'] !== '' ? $contact['apellido_materno'] : null,
                'celular' => $contact['celular'] !== '' ? $contact['celular'] : null,
                'celular_actual' => $contact['celular'] !== '' ? $contact['celular'] : null,
                'id' => $contact['id'],
            ]);
            $stmt = $pdo->prepare(
                'UPDATE estudiante_responsable
                 SET parentesco = :parentesco, actualizado_en = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
                 WHERE id = :relation_id AND estudiante_id = :student_id AND responsable_id = :responsable_id AND tipo = :tipo'
            );
            $stmt->execute([
                'parentesco' => $contact['parentesco'] !== '' ? $contact['parentesco'] : mb_strtoupper(ucfirst($type), 'UTF-8'),
                'relation_id' => $contact['relation_id'], 'student_id' => $studentId,
                'responsable_id' => $contact['id'], 'tipo' => $type,
            ]);
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('El vínculo familiar del ' . $type . ' ya no existe.');
            }
            return;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO responsable (dni, nombres, apellido_paterno, apellido_materno, celular, celular_actual)
             VALUES (:dni, :nombres, :apellido_paterno, :apellido_materno, :celular, :celular_actual)
             ON CONFLICT (dni) DO UPDATE
             SET nombres = EXCLUDED.nombres, apellido_paterno = EXCLUDED.apellido_paterno,
                 apellido_materno = EXCLUDED.apellido_materno, celular = EXCLUDED.celular,
                 celular_actual = EXCLUDED.celular_actual, actualizado_en = CURRENT_TIMESTAMP,
                 updated_at = CURRENT_TIMESTAMP
             RETURNING id'
        );
        $stmt->execute([
            'dni' => $contact['dni'], 'nombres' => $contact['nombres'],
            'apellido_paterno' => $contact['apellido_paterno'],
            'apellido_materno' => $contact['apellido_materno'] !== '' ? $contact['apellido_materno'] : null,
            'celular' => $contact['celular'] !== '' ? $contact['celular'] : null,
            'celular_actual' => $contact['celular'] !== '' ? $contact['celular'] : null,
        ]);
        $responsableId = (int) $stmt->fetchColumn();
        $stmt = $pdo->prepare(
            'SELECT tipo FROM estudiante_responsable
             WHERE estudiante_id = :student_id AND responsable_id = :responsable_id'
        );
        $stmt->execute(['student_id' => $studentId, 'responsable_id' => $responsableId]);
        $existingType = $stmt->fetchColumn();
        if ($existingType !== false && $existingType !== $type) {
            throw new RuntimeException('Ese DNI ya está vinculado al estudiante con otro parentesco.');
        }
        $stmt = $pdo->prepare(
            'INSERT INTO estudiante_responsable (estudiante_id, responsable_id, parentesco, tipo)
             VALUES (:student_id, :responsable_id, :parentesco, :tipo)
             ON CONFLICT (estudiante_id, responsable_id) DO UPDATE
             SET parentesco = EXCLUDED.parentesco, actualizado_en = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([
            'student_id' => $studentId, 'responsable_id' => $responsableId,
            'parentesco' => $contact['parentesco'] !== '' ? $contact['parentesco'] : mb_strtoupper(ucfirst($type), 'UTF-8'),
            'tipo' => $type,
        ]);
    };

    $saveParentOrMother('padre', $familyContacts['padre']);
    $saveParentOrMother('madre', $familyContacts['madre']);

    if ($emergencyId !== null) {
        if ($studentId === null || $emergencyRelacionId === null) {
            throw new RuntimeException('El contacto de emergencia no pertenece a esta familia.');
        }
        $stmt = $pdo->prepare(
            'UPDATE responsable
             SET dni = :dni, nombres = :nombres, apellido_paterno = :apellido_paterno,
                 apellido_materno = :apellido_materno, celular = :celular,
                 celular_actual = :celular_actual, actualizado_en = CURRENT_TIMESTAMP,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $stmt->execute([
            'dni' => $emergencyDni,
            'nombres' => $emergencyNombres,
            'apellido_paterno' => $emergencyApellidoPaterno,
            'apellido_materno' => $emergencyApellidoMaterno !== '' ? $emergencyApellidoMaterno : null,
            'celular' => $emergencyCelular !== '' ? $emergencyCelular : null,
            'celular_actual' => $emergencyCelular !== '' ? $emergencyCelular : null,
            'id' => $emergencyId,
        ]);
        $stmt = $pdo->prepare(
            "UPDATE estudiante_responsable
             SET parentesco = :parentesco, actualizado_en = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
             WHERE id = :relacion_id AND estudiante_id = :estudiante_id
               AND responsable_id = :responsable_id AND tipo IN ('emergencia', 'otro')"
        );
        $stmt->execute([
            'parentesco' => $emergencyParentesco,
            'relacion_id' => $emergencyRelacionId,
            'estudiante_id' => $studentId,
            'responsable_id' => $emergencyId,
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('La relación del contacto de emergencia con el estudiante ya no existe.');
        }
    } elseif ($emergencyHasData) {
        if ($studentId === null || $relacionId === null) {
            throw new RuntimeException('No se puede asociar un contacto de emergencia sin un estudiante vinculado.');
        }

        $stmt = $pdo->prepare(
            'SELECT 1 FROM estudiante_responsable
             WHERE id = :relacion_id AND estudiante_id = :estudiante_id AND responsable_id = :responsable_id'
        );
        $stmt->execute(['relacion_id' => $relacionId, 'estudiante_id' => $studentId, 'responsable_id' => $id]);
        if (!$stmt->fetchColumn()) {
            throw new RuntimeException('La relación familiar ya no existe.');
        }

        $stmt = $pdo->prepare(
            'INSERT INTO responsable (dni, nombres, apellido_paterno, apellido_materno, celular, celular_actual)
             VALUES (:dni, :nombres, :apellido_paterno, :apellido_materno, :celular, :celular_actual)
             ON CONFLICT (dni) DO UPDATE
             SET nombres = EXCLUDED.nombres, apellido_paterno = EXCLUDED.apellido_paterno,
                 apellido_materno = EXCLUDED.apellido_materno, celular = EXCLUDED.celular,
                 celular_actual = EXCLUDED.celular_actual, actualizado_en = CURRENT_TIMESTAMP,
                 updated_at = CURRENT_TIMESTAMP
             RETURNING id'
        );
        $stmt->execute([
            'dni' => $emergencyDni,
            'nombres' => $emergencyNombres,
            'apellido_paterno' => $emergencyApellidoPaterno,
            'apellido_materno' => $emergencyApellidoMaterno !== '' ? $emergencyApellidoMaterno : null,
            'celular' => $emergencyCelular !== '' ? $emergencyCelular : null,
            'celular_actual' => $emergencyCelular !== '' ? $emergencyCelular : null,
        ]);
        $newEmergencyId = (int) $stmt->fetchColumn();
        $stmt = $pdo->prepare(
            'SELECT tipo FROM estudiante_responsable
             WHERE estudiante_id = :estudiante_id AND responsable_id = :responsable_id'
        );
        $stmt->execute(['estudiante_id' => $studentId, 'responsable_id' => $newEmergencyId]);
        $existingType = $stmt->fetchColumn();
        if (in_array($existingType, ['padre', 'madre'], true)) {
            throw new RuntimeException('Ese contacto ya está registrado como padre o madre del estudiante.');
        }
        $stmt = $pdo->prepare(
            "INSERT INTO estudiante_responsable (estudiante_id, responsable_id, parentesco, tipo)
             VALUES (:estudiante_id, :responsable_id, :parentesco, 'emergencia')
             ON CONFLICT (estudiante_id, responsable_id) DO UPDATE
             SET parentesco = EXCLUDED.parentesco, tipo = 'emergencia', actualizado_en = CURRENT_TIMESTAMP,
                 updated_at = CURRENT_TIMESTAMP"
        );
        $stmt->execute([
            'estudiante_id' => $studentId,
            'responsable_id' => $newEmergencyId,
            'parentesco' => $emergencyParentesco,
        ]);
    }

    registrar_auditoria('editar_apoderado', 'Actualizó datos del apoderado ID ' . $id . ' y su contacto de emergencia.');
    $pdo->commit();
    header('Location: padres.php?actualizado=1');
    exit;
} catch (PDOException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Error al editar apoderado: ' . $exception->getMessage());
    http_response_code(400);
    exit($exception->getCode() === '23505'
        ? 'El DNI ya está registrado para otra persona.'
        : 'No se pudieron guardar los cambios del apoderado y su contacto de emergencia.');
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Error al editar apoderado: ' . $exception->getMessage());
    http_response_code(400);
    exit($exception instanceof RuntimeException ? $exception->getMessage() : 'No se pudieron guardar los cambios.');
}
