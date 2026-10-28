<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_permission('p_matricular');

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
} catch (PDOException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ((string) $exception->getCode() === '23503') {
        http_response_code(409);
        echo json_encode([
            'error' => 'No se puede eliminar: el estudiante tiene pagos, matrículas o notas vinculadas. Elimine ese historial primero.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    error_log('Error al eliminar estudiante ID ' . $studentId . ': ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'No se pudo eliminar al estudiante. No se aplicaron cambios.']);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Error al eliminar estudiante ID ' . $studentId . ': ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'No se pudo eliminar al estudiante. No se aplicaron cambios.']);
}
