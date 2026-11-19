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
    "SELECT * FROM (
        SELECT DISTINCT ON (m.estudiante_id)
                m.id AS matricula_id, a.id AS estudiante_id, a.dni,
                a.apellido_paterno, a.apellido_materno, a.nombres,
                CONCAT_WS(' ', a.nombres, a.apellido_paterno, a.apellido_materno) AS nombre
         FROM matricula m
         JOIN estudiante a ON a.id = m.estudiante_id
         LEFT JOIN anio an ON an.id = m.anio_id
         WHERE m.estado = 'Activa'
           AND CONCAT_WS(' ', a.dni, a.nombres, a.apellido_paterno, a.apellido_materno) ILIKE :term
         ORDER BY m.estudiante_id, an.anio DESC NULLS LAST, m.id DESC
    ) sub
    ORDER BY LOWER(TRIM(sub.apellido_paterno)) ASC,
             LOWER(TRIM(COALESCE(sub.apellido_materno, ''))) ASC,
             LOWER(TRIM(sub.nombres)) ASC
    LIMIT 20"
);
$stmt->execute(['term' => '%' . $term . '%']);
echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE);
