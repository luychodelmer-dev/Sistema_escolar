<?php
/**
 * Servicio de Lógica de Negocio y Base de Datos para Calificaciones
 * /app/services/calificaciones_service.php
 */

/**
 * Asegura la estructura de tabla y la restricción para la escala literal (AD, A, B, C).
 */
function asegurar_esquema_calificaciones(PDO $pdo): void
{
    try {
        $pdo->exec(
            'ALTER TABLE calificacion ADD COLUMN IF NOT EXISTS actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP;
             ALTER TABLE calificacion DROP CONSTRAINT IF EXISTS calificacion_nota_check;
             ALTER TABLE calificacion ALTER COLUMN nota TYPE VARCHAR(10) USING nota::text;
             ALTER TABLE calificacion ADD CONSTRAINT calificacion_nota_check CHECK (nota IN (\'AD\', \'A\', \'B\', \'C\'));'
        );
    } catch (Throwable) {
        // Estructura ya actualizada
    }
}

/**
 * Responde a solicitudes AJAX de cascading selects (grados, secciones, cursos).
 */
function manejar_ajax_calificaciones(PDO $pdo): void
{
    if (!isset($_GET['ajax']) || !in_array($_GET['ajax'], ['grados', 'secciones', 'cursos'], true)) {
        return;
    }

    header('Content-Type: application/json; charset=utf-8');
    $parentId = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    if ($_GET['ajax'] === 'grados') {
        if ($parentId !== false) {
            $stmt = $pdo->prepare('SELECT id, nombre FROM grado WHERE nivel_id = :id AND activo = TRUE ORDER BY numero');
            $stmt->execute(['id' => $parentId]);
            echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE);
        } else {
            $stmt = $pdo->query('
                SELECT g.id, (n.nombre || \' - \' || g.nombre) AS nombre 
                FROM grado g 
                JOIN nivel n ON n.id = g.nivel_id 
                WHERE g.activo = TRUE 
                ORDER BY n.id, g.numero
            ');
            echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    if ($parentId === false) {
        exit('[]');
    }

    if ($_GET['ajax'] === 'secciones') {
        $stmt = $pdo->prepare(
            'SELECT DISTINCT s.id, s.nombre
             FROM grado_seccion gs
             JOIN seccion s ON s.id = gs.seccion_id
             WHERE gs.grado_id = :id AND gs.activo = TRUE AND s.activo = TRUE
             ORDER BY s.nombre'
        );
        $stmt->execute(['id' => $parentId]);
        echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($_GET['ajax'] === 'cursos') {
        $gradoStmt = $pdo->prepare('
            SELECT g.id, g.nombre AS grado_nombre, n.nombre AS nivel_nombre 
            FROM grado g 
            JOIN nivel n ON n.id = g.nivel_id 
            WHERE g.id = :id
        ');
        $gradoStmt->execute(['id' => $parentId]);
        $gInfo = $gradoStmt->fetch();
        if (!$gInfo) {
            exit('[]');
        }
        $search1 = $gInfo['nivel_nombre'] . ' - ' . $gInfo['grado_nombre'];
        $search2 = '%' . $gInfo['nivel_nombre'] . '%' . $gInfo['grado_nombre'] . '%';
        $cStmt = $pdo->prepare(
            'SELECT id, nombre, grado 
             FROM curso 
             WHERE activo = TRUE AND (grado ILIKE :s1 OR grado ILIKE :s2) 
             ORDER BY nombre'
        );
        $cStmt->execute(['s1' => $search1, 's2' => $search2]);
        echo json_encode($cStmt->fetchAll(), JSON_UNESCAPED_UNICODE);
        exit;
    }
}

/**
 * Mapea los cursos con su grado y nivel correspondientes en la base de datos.
 */
function obtener_cursos_mapeados(array $cursos, array $gradosDb): array
{
    $cursosMapeados = [];
    foreach ($cursos as $c) {
        $cId = (int) $c['id'];
        $cursoGradoRaw = trim((string) $c['grado']);
        $cursoGradoLow = mb_strtolower($cursoGradoRaw, 'UTF-8');

        $detected = null;
        foreach ($gradosDb as $g) {
            $nombreCompleto = mb_strtolower($g['nivel_nombre'] . ' - ' . $g['grado_nombre'], 'UTF-8');
            $nombreGrado = mb_strtolower($g['grado_nombre'], 'UTF-8');
            if ($cursoGradoLow === $nombreCompleto || $cursoGradoLow === $nombreGrado) {
                $detected = $g;
                break;
            }
        }
        if ($detected === null) {
            foreach ($gradosDb as $g) {
                $nivelLow = mb_strtolower($g['nivel_nombre'], 'UTF-8');
                $gradoLow = mb_strtolower($g['grado_nombre'], 'UTF-8');
                if (str_contains($cursoGradoLow, $nivelLow) && str_contains($cursoGradoLow, $gradoLow)) {
                    $detected = $g;
                    break;
                }
            }
        }

        $cursosMapeados[$cId] = [
            'id' => $cId,
            'nombre' => $c['nombre'],
            'grado' => $c['grado'],
            'grado_id' => $detected ? (int) $detected['grado_id'] : null,
            'grado_nombre' => $detected ? $detected['grado_nombre'] : '',
            'nivel_id' => $detected ? (int) $detected['nivel_id'] : null,
            'nivel_nombre' => $detected ? $detected['nivel_nombre'] : '',
            'aula_etiqueta' => $detected ? ($detected['nivel_nombre'] . ' - ' . $detected['grado_nombre']) : $c['grado'],
        ];
    }
    return $cursosMapeados;
}

/**
 * Procesa las acciones enviadas por POST (guardar notas, vaciar curso, eliminar seleccionados).
 */
function procesar_post_calificaciones(PDO $pdo, array $post, ?int $cursoId, ?int $periodoId, string $csrfToken): array
{
    $submittedToken = $post['csrf_token'] ?? '';
    $action = $post['action'] ?? 'guardar';

    if (!is_string($submittedToken) || !hash_equals($csrfToken, $submittedToken)) {
        return ['error' => 'Solicitud inválida. Recarga la página e inténtalo nuevamente.', 'mensaje' => ''];
    }

    if ($action === 'vaciar_notas') {
        if ($cursoId === null || $periodoId === null) {
            return ['error' => 'Selecciona un curso y un periodo académico para vaciar sus notas.', 'mensaje' => ''];
        }
        try {
            $stmtDel = $pdo->prepare('DELETE FROM calificacion WHERE curso_id = :cid AND periodo_id = :pid');
            $stmtDel->execute(['cid' => $cursoId, 'pid' => $periodoId]);
            $countBorrados = $stmtDel->rowCount();
            registrar_auditoria('vaciar_calificaciones', "Eliminó {$countBorrados} notas del curso ID {$cursoId} periodo ID {$periodoId}.");
            return ['error' => '', 'mensaje' => "Se eliminaron correctamente {$countBorrados} calificaciones registradas para este curso y periodo."];
        } catch (Throwable $e) {
            return ['error' => 'No se pudieron eliminar las calificaciones: ' . $e->getMessage(), 'mensaje' => ''];
        }
    }

    if ($action === 'eliminar_seleccionados') {
        $seleccionados = $post['matriculas_seleccionadas'] ?? [];
        if ($cursoId === null || $periodoId === null) {
            return ['error' => 'Selecciona un curso y un periodo académico válidos.', 'mensaje' => ''];
        }
        if (!is_array($seleccionados) || empty($seleccionados)) {
            return ['error' => 'No seleccionaste ningún estudiante para eliminar sus calificaciones.', 'mensaje' => ''];
        }

        $idsValidos = [];
        foreach ($seleccionados as $rawMid) {
            $vMid = filter_var($rawMid, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($vMid !== false) {
                $idsValidos[] = $vMid;
            }
        }

        if (empty($idsValidos)) {
            return ['error' => 'No se recibieron identificadores de matrícula válidos.', 'mensaje' => ''];
        }

        try {
            $placeholders = implode(',', array_fill(0, count($idsValidos), '?'));
            $stmtDelSel = $pdo->prepare("DELETE FROM calificacion WHERE curso_id = ? AND periodo_id = ? AND matricula_id IN ($placeholders)");
            $params = array_merge([$cursoId, $periodoId], $idsValidos);
            $stmtDelSel->execute($params);
            $countBorrados = $stmtDelSel->rowCount();

            registrar_auditoria('eliminar_calificaciones_seleccionadas', "Eliminó {$countBorrados} calificaciones seleccionadas para el curso ID {$cursoId} periodo ID {$periodoId}.");
            return ['error' => '', 'mensaje' => "Se eliminaron las calificaciones de {$countBorrados} estudiante(s) seleccionado(s) correctamente."];
        } catch (Throwable $e) {
            return ['error' => 'No se pudieron eliminar las notas de los estudiantes seleccionados: ' . $e->getMessage(), 'mensaje' => ''];
        }
    }

    // Guardado manual de notas
    $notasRecibidas = $post['notas'] ?? null;
    $notasValidadas = [];
    $notasAEliminar = [];

    if ($cursoId === null || $periodoId === null) {
        return ['error' => 'Selecciona un curso y un periodo académico válidos.', 'mensaje' => ''];
    }
    if (!is_array($notasRecibidas)) {
        return ['error' => 'No se recibieron notas válidas.', 'mensaje' => ''];
    }

    $chkStmt = $pdo->query("SELECT id FROM matricula WHERE estado = 'Activa'");
    $matriculasActivas = array_fill_keys(array_map('intval', $chkStmt->fetchAll(PDO::FETCH_COLUMN)), true);

    foreach ($notasRecibidas as $matriculaIdEntrada => $notaEntrada) {
        if (!is_string($matriculaIdEntrada) && !is_int($matriculaIdEntrada)) {
            return ['error' => 'Se recibió una matrícula inválida.', 'mensaje' => ''];
        }
        $matriculaId = filter_var($matriculaIdEntrada, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($matriculaId === false || !isset($matriculasActivas[$matriculaId])) {
            return ['error' => 'Una matrícula no está activa o no es válida.', 'mensaje' => ''];
        }
        if (!is_string($notaEntrada)) {
            return ['error' => 'Una de las calificaciones no tiene un formato válido.', 'mensaje' => ''];
        }
        $nota = strtoupper(trim($notaEntrada));
        if ($nota === '') {
            $notasAEliminar[] = $matriculaId;
            continue;
        }
        if (!in_array($nota, ['AD', 'A', 'B', 'C'], true)) {
            return ['error' => 'Las calificaciones deben ser únicamente AD, A, B o C.', 'mensaje' => ''];
        }
        $notasValidadas[$matriculaId] = $nota;
    }

    try {
        $pdo->beginTransaction();
        $upsertNota = $pdo->prepare(
            'INSERT INTO calificacion (matricula_id, curso_id, periodo_id, nota)
             VALUES (:matricula_id, :curso_id, :periodo_id, :nota)
             ON CONFLICT (matricula_id, curso_id, periodo_id)
             DO UPDATE SET nota = EXCLUDED.nota, actualizado_en = CURRENT_TIMESTAMP'
        );
        foreach ($notasValidadas as $mId => $nota) {
            $upsertNota->execute([
                'matricula_id' => $mId,
                'curso_id' => $cursoId,
                'periodo_id' => $periodoId,
                'nota' => $nota,
            ]);
        }

        if (!empty($notasAEliminar)) {
            $delStmt = $pdo->prepare('DELETE FROM calificacion WHERE matricula_id = :mid AND curso_id = :cid AND periodo_id = :pid');
            foreach ($notasAEliminar as $delMid) {
                $delStmt->execute(['mid' => $delMid, 'cid' => $cursoId, 'pid' => $periodoId]);
            }
        }

        $pdo->commit();
        $cantGuardadas = count($notasValidadas);
        $cantEliminadas = count($notasAEliminar);
        registrar_auditoria('guardar_calificaciones', "Guardó {$cantGuardadas} y eliminó {$cantEliminadas} calificaciones para el curso ID {$cursoId} en periodo ID {$periodoId}.");

        $mensaje = 'Las calificaciones se guardaron correctamente en escala oficial (C, B, A, AD).';
        if ($cantEliminadas > 0 && $cantGuardadas > 0) {
            $mensaje = "Calificaciones actualizadas: {$cantGuardadas} guardadas/modificadas y {$cantEliminadas} eliminadas correctamente.";
        } elseif ($cantEliminadas > 0) {
            $mensaje = "Se eliminaron {$cantEliminadas} calificaciones correctamente.";
        }
        return ['error' => '', 'mensaje' => $mensaje];
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Error al guardar calificaciones: ' . $exception->getMessage());
        return ['error' => 'No se pudieron guardar las notas. Error en base de datos.', 'mensaje' => ''];
    }
}

/**
 * Consulta la lista de alumnos según aula y sus calificaciones actuales.
 */
function obtener_alumnos_y_notas(PDO $pdo, ?int $nivelId, ?int $gradoId, ?int $seccionId, ?int $cursoId, ?int $periodoId): array
{
    $alumnos = [];
    $notasActuales = [];

    if ($cursoId === null || $periodoId === null) {
        return ['alumnos' => [], 'notasActuales' => []];
    }

    $conditions = ["m.estado = 'Activa'"];
    $queryParams = [];

    if ($nivelId !== null) {
        $conditions[] = 'm.nivel_id = :nivel_id';
        $queryParams['nivel_id'] = $nivelId;
    }
    if ($gradoId !== null) {
        $conditions[] = 'gs.grado_id = :grado_id';
        $queryParams['grado_id'] = $gradoId;
    }
    if ($seccionId !== null) {
        $conditions[] = 'gs.seccion_id = :seccion_id';
        $queryParams['seccion_id'] = $seccionId;
    }

    $whereSql = implode(' AND ', $conditions);
    $stmtAlumnos = $pdo->prepare(
        "SELECT m.id AS matricula_id, e.id AS estudiante_id, e.dni,
                e.apellido_paterno, e.apellido_materno, e.nombres,
                e.estado_academico,
                n.nombre AS nivel, g.nombre AS grado, s.nombre AS seccion
         FROM matricula m
         JOIN estudiante e ON e.id = m.estudiante_id
         LEFT JOIN nivel n ON n.id = m.nivel_id
         LEFT JOIN grado_seccion gs ON gs.id = m.grado_seccion_id
         LEFT JOIN grado g ON g.id = gs.grado_id
         LEFT JOIN seccion s ON s.id = gs.seccion_id
         WHERE $whereSql
         ORDER BY LOWER(TRIM(e.apellido_paterno)) ASC, LOWER(TRIM(COALESCE(e.apellido_materno, ''))) ASC, LOWER(TRIM(e.nombres)) ASC"
    );
    $stmtAlumnos->execute($queryParams);
    $alumnos = $stmtAlumnos->fetchAll();

    $stmtNotas = $pdo->prepare(
        'SELECT matricula_id, nota
         FROM calificacion
         WHERE curso_id = :curso_id AND periodo_id = :periodo_id'
    );
    $stmtNotas->execute(['curso_id' => $cursoId, 'periodo_id' => $periodoId]);
    foreach ($stmtNotas->fetchAll() as $notaRow) {
        $notasActuales[(int) $notaRow['matricula_id']] = strtoupper(trim((string) $notaRow['nota']));
    }

    return ['alumnos' => $alumnos, 'notasActuales' => $notasActuales];
}

/**
 * Calcula las métricas del aula (AD, A, B, C, Promovidos, Evaluados).
 */
function calcular_metricas_aula(array $alumnos, array $notasActuales): array
{
    $calificadosCount = 0;
    $countAD = 0;
    $countA = 0;
    $countB = 0;
    $countC = 0;

    foreach ($alumnos as $al) {
        $mId = (int) $al['matricula_id'];
        if (isset($notasActuales[$mId]) && $notasActuales[$mId] !== '') {
            $calificadosCount++;
            $val = $notasActuales[$mId];
            if ($val === 'AD') $countAD++;
            elseif ($val === 'A') $countA++;
            elseif ($val === 'B') $countB++;
            elseif ($val === 'C') $countC++;
        }
    }

    return [
        'totalCargados' => count($alumnos),
        'calificadosCount' => $calificadosCount,
        'countAD' => $countAD,
        'countA' => $countA,
        'countB' => $countB,
        'countC' => $countC,
        'aprobadosCount' => ($countAD + $countA + $countB),
        'desaprobadosCount' => $countC,
    ];
}

/**
 * Genera el consolidado institucional y los datos para la gráfica interactiva de Chart.js.
 */
function obtener_consolidado_grafica(PDO $pdo, ?int $nivelId, ?int $gradoId, ?int $seccionId, ?int $periodoId, array $cursosDisponibles): array
{
    $consolidadoAula = [];
    $labelsGrafica = [];
    $dataGraficaAD = [];
    $dataGraficaA = [];
    $dataGraficaB = [];
    $dataGraficaC = [];
    $totalGeneralAD = 0;
    $totalGeneralA = 0;
    $totalGeneralB = 0;
    $totalGeneralC = 0;
    $totalGeneralEvaluados = 0;

    if ($periodoId === null || empty($cursosDisponibles)) {
        return compact(
            'consolidadoAula', 'labelsGrafica', 'dataGraficaAD', 'dataGraficaA',
            'dataGraficaB', 'dataGraficaC', 'totalGeneralAD', 'totalGeneralA',
            'totalGeneralB', 'totalGeneralC', 'totalGeneralEvaluados'
        );
    }

    $reemplazosNombres = [
        'Educación Física' => 'ED. FÍSICA',
        'Educación Religiosa' => 'ED. RELIG.',
        'Ciencia y Tecnología' => 'CIENCIA Y TEC.',
        'Arte y Cultura' => 'ARTE Y CULT.',
        'Personal Social' => 'PERS. SOCIAL',
        'Ciencias Sociales' => 'CC. SOCIALES',
        'Desarrollo Personal, Ciudadanía y Cívica' => 'DPCC',
        'Educación para el Trabajo' => 'ED. TRABAJO',
        'Tutoría y Orientación Educativa' => 'TUTORÍA',
        'Competencias Transversales' => 'TRANSVERSAL',
    ];

    if ($gradoId !== null) {
        $sql = "
            SELECT 
                cal.curso_id,
                COUNT(CASE WHEN UPPER(TRIM(cal.nota)) = 'AD' THEN 1 END) AS count_ad,
                COUNT(CASE WHEN UPPER(TRIM(cal.nota)) = 'A' THEN 1 END) AS count_a,
                COUNT(CASE WHEN UPPER(TRIM(cal.nota)) = 'B' THEN 1 END) AS count_b,
                COUNT(CASE WHEN UPPER(TRIM(cal.nota)) = 'C' THEN 1 END) AS count_c,
                COUNT(cal.id) AS total_con_nota
            FROM calificacion cal
            JOIN matricula m ON m.id = cal.matricula_id
            JOIN grado_seccion gs ON gs.id = m.grado_seccion_id
            WHERE m.estado = 'Activa'
              AND gs.grado_id = :gid
              AND cal.periodo_id = :pid
        ";
        $params = ['gid' => $gradoId, 'pid' => $periodoId];
        if ($seccionId !== null) {
            $sql .= " AND gs.seccion_id = :sec";
            $params['sec'] = $seccionId;
        }
        $sql .= " GROUP BY cal.curso_id";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $notasPorCurso = $stmt->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);

        foreach ($cursosDisponibles as $cur) {
            $cid = (int) $cur['id'];
            $info = $notasPorCurso[$cid] ?? [
                'count_ad' => 0, 'count_a' => 0, 'count_b' => 0, 'count_c' => 0, 'total_con_nota' => 0,
            ];
            $cAD = (int) $info['count_ad'];
            $cA = (int) $info['count_a'];
            $cB = (int) $info['count_b'];
            $cC = (int) $info['count_c'];
            $cTotal = $cAD + $cA + $cB + $cC;

            $totalGeneralAD += $cAD;
            $totalGeneralA += $cA;
            $totalGeneralB += $cB;
            $totalGeneralC += $cC;
            $totalGeneralEvaluados += $cTotal;

            $nombreCorto = $cur['nombre'];
            if (mb_strlen($nombreCorto) > 16) {
                $nombreCorto = $reemplazosNombres[$nombreCorto] ?? (mb_substr($nombreCorto, 0, 14) . '…');
            }

            $labelsGrafica[] = $nombreCorto;
            $dataGraficaAD[] = $cAD;
            $dataGraficaA[] = $cA;
            $dataGraficaB[] = $cB;
            $dataGraficaC[] = $cC;

            $consolidadoAula[] = [
                'id' => $cid,
                'nombre' => $cur['nombre'],
                'nombre_corto' => $nombreCorto,
                'ad' => $cAD,
                'a' => $cA,
                'b' => $cB,
                'c' => $cC,
                'total' => $cTotal,
            ];
        }
    } else {
        $sql = "
            SELECT 
                TRIM(c.nombre) AS curso_nombre,
                COUNT(CASE WHEN UPPER(TRIM(cal.nota)) = 'AD' THEN 1 END) AS count_ad,
                COUNT(CASE WHEN UPPER(TRIM(cal.nota)) = 'A' THEN 1 END) AS count_a,
                COUNT(CASE WHEN UPPER(TRIM(cal.nota)) = 'B' THEN 1 END) AS count_b,
                COUNT(CASE WHEN UPPER(TRIM(cal.nota)) = 'C' THEN 1 END) AS count_c,
                COUNT(cal.id) AS total_con_nota
            FROM calificacion cal
            JOIN curso c ON c.id = cal.curso_id
            JOIN matricula m ON m.id = cal.matricula_id
            WHERE m.estado = 'Activa'
              AND cal.periodo_id = :pid
        ";
        $params = ['pid' => $periodoId];
        if ($nivelId !== null) {
            $sql .= " AND m.nivel_id = :nid";
            $params['nid'] = $nivelId;
        }
        $sql .= " GROUP BY TRIM(c.nombre)";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $notasPorCurso = $stmt->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);

        foreach ($cursosDisponibles as $cur) {
            $nombreCur = trim((string)$cur['nombre']);
            $info = $notasPorCurso[$nombreCur] ?? [
                'count_ad' => 0, 'count_a' => 0, 'count_b' => 0, 'count_c' => 0, 'total_con_nota' => 0,
            ];
            $cAD = (int) $info['count_ad'];
            $cA = (int) $info['count_a'];
            $cB = (int) $info['count_b'];
            $cC = (int) $info['count_c'];
            $cTotal = $cAD + $cA + $cB + $cC;

            $totalGeneralAD += $cAD;
            $totalGeneralA += $cA;
            $totalGeneralB += $cB;
            $totalGeneralC += $cC;
            $totalGeneralEvaluados += $cTotal;

            $nombreCorto = $nombreCur;
            if (mb_strlen($nombreCorto) > 16) {
                $nombreCorto = $reemplazosNombres[$nombreCorto] ?? (mb_substr($nombreCorto, 0, 14) . '…');
            }

            $labelsGrafica[] = $nombreCorto;
            $dataGraficaAD[] = $cAD;
            $dataGraficaA[] = $cA;
            $dataGraficaB[] = $cB;
            $dataGraficaC[] = $cC;

            $consolidadoAula[] = [
                'id' => null,
                'nombre' => $nombreCur,
                'nombre_corto' => $nombreCorto,
                'ad' => $cAD,
                'a' => $cA,
                'b' => $cB,
                'c' => $cC,
                'total' => $cTotal,
            ];
        }
    }

    return compact(
        'consolidadoAula', 'labelsGrafica', 'dataGraficaAD', 'dataGraficaA',
        'dataGraficaB', 'dataGraficaC', 'totalGeneralAD', 'totalGeneralA',
        'totalGeneralB', 'totalGeneralC', 'totalGeneralEvaluados'
    );
}
