<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/auth.php';

require_auth(['asistente', 'director']);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$term = $_GET['q'] ?? '';
$term = is_string($term) ? trim($term) : '';
if (strlen($term) < 2) {
    echo '[]';
    exit;
}

$stmt = $pdo->prepare(
    "SELECT id, dni,
            CONCAT_WS(' ', nombres, apellido_paterno, apellido_materno) AS nombre
     FROM responsable
     WHERE CONCAT_WS(' ', nombres, apellido_paterno, apellido_materno, dni) ILIKE :term
     ORDER BY LOWER(TRIM(apellido_paterno)) ASC, LOWER(TRIM(COALESCE(apellido_materno, ''))) ASC, LOWER(TRIM(nombres)) ASC
     LIMIT 10"
);
$stmt->execute(['term' => '%' . $term . '%']);
echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE);
