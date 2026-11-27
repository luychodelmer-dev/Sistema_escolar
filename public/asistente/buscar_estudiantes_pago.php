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
         LEFT JOIN responsable resp ON resp.id = m.apoderado_principal_id
         WHERE m.estado = 'Activa'
           AND (
               CONCAT_WS(' ', a.dni, a.nombres, a.apellido_paterno, a.apellido_materno) ILIKE :term
               OR CONCAT_WS(' ', a.apellido_paterno, a.apellido_materno, a.nombres) ILIKE :term
               OR (resp.id IS NOT NULL AND (
                   CONCAT_WS(' ', resp.dni, resp.nombres, resp.apellido_paterno, resp.apellido_materno) ILIKE :term
                   OR CONCAT_WS(' ', resp.apellido_paterno, resp.apellido_materno, resp.nombres) ILIKE :term
               ))
               OR EXISTS (
                   SELECT 1 FROM estudiante_responsable er
                   JOIN responsable r ON r.id = er.responsable_id
                   WHERE er.estudiante_id = a.id
                     AND (
                         r.dni ILIKE :term
                         OR CONCAT_WS(' ', r.nombres, r.apellido_paterno, r.apellido_materno) ILIKE :term
                         OR CONCAT_WS(' ', r.apellido_paterno, r.apellido_materno, r.nombres) ILIKE :term
                     )
               )
           )
         ORDER BY m.estudiante_id, an.anio DESC NULLS LAST, m.id DESC
    ) sub
    ORDER BY LOWER(TRIM(sub.apellido_paterno)) ASC,
             LOWER(TRIM(COALESCE(sub.apellido_materno, ''))) ASC,
             LOWER(TRIM(sub.nombres)) ASC
    LIMIT 20"
);
$stmt->execute(['term' => '%' . $term . '%']);
echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE);
