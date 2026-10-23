<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SESSION['rol'] ?? null) !== 'hiperadmin') {
    http_response_code(403);
    echo json_encode(['error' => 'No tienes permiso para eliminar estudiantes.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'Método no permitido.']);
    exit;
}

$csrfToken = $_SESSION['csrf_token'] ?? '';
$submittedToken = $_POST['csrf_token'] ?? '';
if (!is_string($csrfToken) || $csrfToken === '' ||
    !is_string($submittedToken) || !hash_equals($csrfToken, $submittedToken)) {
    http_response_code(400);
    echo json_encode(['error' => 'Solicitud inválida. Recarga la página e inténtalo nuevamente.']);
    exit;
}

$studentId = filter_var($_POST['estudiante_id'] ?? '', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
if ($studentId === false) {
    http_response_code(400);
    echo json_encode(['error' => 'El ID del estudiante no es válido.']);
    exit;
}

try {
    $pdo->beginTransaction();

    $studentStmt = $pdo->prepare(
        "SELECT id, CONCAT_WS(' ', nombres, apellido_paterno, apellido_materno) AS nombre
         FROM estudiante
         WHERE id = :id
         FOR UPDATE"
    );
    $studentStmt->execute(['id' => $studentId]);
    $student = $studentStmt->fetch();
    if (!$student) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['error' => 'No se encontró al estudiante.']);
        exit;
    }

    $deletePayments = $pdo->prepare(
        'DELETE FROM pago
         WHERE matricula_id IN (
             SELECT id FROM matricula WHERE estudiante_id = :student_id
         )
         OR cuenta_pago_id IN (
             SELECT cuenta.id
             FROM cuenta_pago cuenta
             JOIN matricula ON matricula.id = cuenta.matricula_id
             WHERE matricula.estudiante_id = :account_student_id
         )'
    );
    $deletePayments->execute([
        'student_id' => $studentId,
        'account_student_id' => $studentId,
    ]);

    $deleteAccounts = $pdo->prepare(
        'DELETE FROM cuenta_pago
         WHERE matricula_id IN (
             SELECT id FROM matricula WHERE estudiante_id = :student_id
         )'
    );
    $deleteAccounts->execute(['student_id' => $studentId]);

    $deleteEnrollments = $pdo->prepare('DELETE FROM matricula WHERE estudiante_id = :student_id');
    $deleteEnrollments->execute(['student_id' => $studentId]);

    $deleteFamilyLinks = $pdo->prepare(
        'DELETE FROM estudiante_responsable WHERE estudiante_id = :student_id'
    );
    $deleteFamilyLinks->execute(['student_id' => $studentId]);

    $deleteStudent = $pdo->prepare('DELETE FROM estudiante WHERE id = :id');
    $deleteStudent->execute(['id' => $studentId]);
    if ($deleteStudent->rowCount() !== 1) {
        throw new RuntimeException('El estudiante ya no existe.');
    }

    registrar_auditoria(
        'eliminar_estudiante',
        'Eliminó al estudiante: ' . $student['nombre'] . ' (ID ' . $studentId . ').'
    );
    $pdo->commit();

    echo json_encode(['ok' => true, 'mensaje' => 'Estudiante eliminado correctamente.']);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Error al eliminar estudiante ID ' . $studentId . ': ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'No se pudo eliminar al estudiante. No se aplicaron cambios.']);
}
