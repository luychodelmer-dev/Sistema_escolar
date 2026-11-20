<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/header.php';

require_auth(['asistente', 'director', 'hiperadmin', 'administrador']);
require_permission('p_cierre_academico');

$csrfToken = $_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));
$error = '';
$mensaje = '';

// AJAX para cascading dropdowns
if (isset($_GET['ajax']) && in_array($_GET['ajax'], ['grados', 'secciones'], true)) {
    header('Content-Type: application/json; charset=utf-8');
    $parentId = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($_GET['ajax'] === 'grados') {
        if ($parentId !== false) {
            $optionsStmt = $pdo->prepare('SELECT id, nombre FROM grado WHERE nivel_id = :id AND activo = TRUE ORDER BY numero');
            $optionsStmt->execute(['id' => $parentId]);
            echo json_encode($optionsStmt->fetchAll(), JSON_UNESCAPED_UNICODE);
        } else {
            $optionsStmt = $pdo->query('
                SELECT g.id, (n.nombre || \' - \' || g.nombre) AS nombre 
                FROM grado g 
                JOIN nivel n ON n.id = g.nivel_id 
                WHERE g.activo = TRUE 
                ORDER BY n.id, g.numero
            ');
            echo json_encode($optionsStmt->fetchAll(), JSON_UNESCAPED_UNICODE);
        }
        exit;
    } else {
        if ($parentId === false) {
            exit('[]');
        }
        $optionsStmt = $pdo->prepare(
            'SELECT DISTINCT s.id, s.nombre
             FROM grado_seccion gs
             JOIN seccion s ON s.id = gs.seccion_id
             WHERE gs.grado_id = :id AND gs.activo = TRUE AND s.activo = TRUE
             ORDER BY s.nombre'
        );
        $optionsStmt->execute(['id' => $parentId]);
        echo json_encode($optionsStmt->fetchAll(), JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// 1. Cargar Años Escolares disponibles
$anios = $pdo->query('SELECT id, anio, activo FROM anio ORDER BY anio DESC')->fetchAll();
$niveles = $pdo->query('SELECT id, nombre FROM nivel ORDER BY id')->fetchAll();
$periodos = $pdo->query('SELECT id, nombre, fecha_inicio, fecha_fin FROM periodo_academico WHERE activo = TRUE ORDER BY fecha_inicio ASC, id ASC')->fetchAll();

// Parámetros de búsqueda y filtros
$anioId = filter_var($_GET['anio_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
$nivelId = filter_var($_GET['nivel_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
$gradoId = filter_var($_GET['grado_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
$seccionId = filter_var($_GET['seccion_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
$tabActiva = trim($_GET['tab'] ?? 'diagnostico');
if (!in_array($tabActiva, ['diagnostico', 'vacacional', 'promocion'], true)) {
    $tabActiva = 'diagnostico';
}

// Por defecto año actual o año activo más reciente
if ($anioId === null && !empty($anios)) {
    $anioActualNum = (int) date('Y');
    foreach ($anios as $a) {
        if ((int)$a['anio'] === $anioActualNum) {
            $anioId = (int)$a['id'];
            break;
        }
    }
    if ($anioId === null) {
        $anioId = (int)$anios[0]['id'];
    }
}

$anioActualInfo = null;
foreach ($anios as $a) {
    if ((int)$a['id'] === $anioId) {
        $anioActualInfo = $a;
        break;
    }
}

// Grados disponibles
$gradosDisponibles = [];
if ($nivelId !== null) {
    $gStmt = $pdo->prepare('SELECT id, nombre, numero FROM grado WHERE nivel_id = :nid AND activo = TRUE ORDER BY numero');
    $gStmt->execute(['nid' => $nivelId]);
    $gradosDisponibles = $gStmt->fetchAll();

    $gradoIds = array_map('intval', array_column($gradosDisponibles, 'id'));
    if ($gradoId !== null && !in_array($gradoId, $gradoIds, true)) {
        $gradoId = null;
    }
} else {
    $gradosDisponibles = $pdo->query('
        SELECT g.id, (n.nombre || \' - \' || g.nombre) AS nombre, g.numero 
        FROM grado g 
        JOIN nivel n ON n.id = g.nivel_id 
        WHERE g.activo = TRUE 
        ORDER BY n.id, g.numero
    ')->fetchAll();
}

// Secciones disponibles
$seccionesDisponibles = [];
if ($gradoId !== null) {
    $sStmt = $pdo->prepare('
        SELECT DISTINCT s.id, s.nombre 
        FROM grado_seccion gs 
        JOIN seccion s ON s.id = gs.seccion_id 
        WHERE gs.grado_id = :gid AND gs.activo = TRUE AND s.activo = TRUE 
        ORDER BY s.nombre
    ');
    $sStmt->execute(['gid' => $gradoId]);
    $seccionesDisponibles = $sStmt->fetchAll();
    $seccIds = array_map('intval', array_column($seccionesDisponibles, 'id'));
    if ($seccionId !== null && !in_array($seccionId, $seccIds, true)) {
        $seccionId = null;
    }
}

// -------------------------------------------------------------
// PROCESAMIENTO DE ACCIONES POR POST
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    $action = $_POST['action'] ?? '';

    if (!is_string($submittedToken) || !hash_equals($csrfToken, $submittedToken)) {
        $error = 'Solicitud inválida. Recarga la página e inténtalo nuevamente.';
    } elseif ($action === 'inscribir_vacacional_masivo') {
        // Inscribir a todos los estudiantes diagnosticados en Recuperación
        $postMatriculas = $_POST['matriculas_recuperacion'] ?? [];
        $postCursos = $_POST['cursos_recuperacion'] ?? []; // Array [matricula_id => [curso_id1, curso_id2]]

        if (empty($postMatriculas) || !is_array($postMatriculas)) {
            $error = 'No se seleccionó ningún estudiante para el programa de recuperación vacacional.';
        } else {
            try {
                $pdo->beginTransaction();
                $stmtInsertRec = $pdo->prepare('
                    INSERT INTO recuperacion_academica (matricula_id, curso_id, nota_final_regular, estado, evaluado_por)
                    VALUES (:mid, :cid, :nota_reg, \'Pendiente\', :uid)
                    ON CONFLICT (matricula_id, curso_id) DO UPDATE 
                    SET nota_final_regular = EXCLUDED.nota_final_regular, actualizado_en = CURRENT_TIMESTAMP
                ');

                $stmtUpdateEst = $pdo->prepare('
                    UPDATE estudiante 
                    SET estado_academico = \'Recuperación\', actualizado_en = CURRENT_TIMESTAMP
                    WHERE id = (SELECT estudiante_id FROM matricula WHERE id = :mid)
                ');

                $totalInscritos = 0;
                $userId = $_SESSION['user_id'] ?? null;

                foreach ($postMatriculas as $midRaw) {
                    $mId = filter_var($midRaw, FILTER_VALIDATE_INT);
                    if (!$mId) continue;

                    $cursosAlumno = $postCursos[$mId] ?? [];
                    if (is_array($cursosAlumno)) {
                        foreach ($cursosAlumno as $cIdRaw) {
                            $cId = filter_var($cIdRaw, FILTER_VALIDATE_INT);
                            if (!$cId) continue;

                            $stmtInsertRec->execute([
                                'mid' => $mId,
                                'cid' => $cId,
                                'nota_reg' => 'C',
                                'uid' => $userId
                            ]);
                            $totalInscritos++;
                        }
                    }

                    $stmtUpdateEst->execute(['mid' => $mId]);
                }

                $pdo->commit();
                registrar_auditoria('inscribir_vacacional', "Inscribió a {$totalInscritos} asignaturas en el programa de recuperación vacacional.");
                $mensaje = "¡Se inscribieron con éxito {$totalInscritos} asignaturas en el Programa de Recuperación Vacacional! El estado de los alumnos pasó a 'Recuperación'.";
                $tabActiva = 'vacacional';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Error en vacacional masivo: ' . $e->getMessage());
                $error = 'Ocurrió un error al registrar en vacacional: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'guardar_notas_vacacional') {
        // Guardar calificaciones del programa de recuperación
        $notasVacacional = $_POST['nota_vacacional'] ?? []; // Array [recuperacion_id => nota]
        $obsVacacional = $_POST['observacion_vacacional'] ?? [];
        $userId = $_SESSION['user_id'] ?? null;

        if (!is_array($notasVacacional) || empty($notasVacacional)) {
            $error = 'No se enviaron calificaciones para guardar.';
        } else {
            try {
                $pdo->beginTransaction();
                $stmtUpdRec = $pdo->prepare('
                    UPDATE recuperacion_academica 
                    SET nota_recuperacion = :nota, 
                        estado = :estado, 
                        observacion = :obs,
                        evaluado_por = :uid,
                        actualizado_en = CURRENT_TIMESTAMP
                    WHERE id = :id
                ');

                $actualizados = 0;
                foreach ($notasVacacional as $recIdRaw => $notaRaw) {
                    $recId = filter_var($recIdRaw, FILTER_VALIDATE_INT);
                    $nota = strtoupper(trim((string)$notaRaw));
                    $obs = trim((string)($obsVacacional[$recIdRaw] ?? ''));

                    if (!$recId || $nota === '') continue;

                    if (!in_array($nota, ['AD', 'A', 'B', 'C'], true)) {
                        throw new InvalidArgumentException("Calificación inválida: {$nota}. Debe ser AD, A, B o C.");
                    }

                    // En vacacional: AD, A y B aprueban la recuperación; C desaprueba
                    $estadoRec = in_array($nota, ['AD', 'A', 'B'], true) ? 'Aprobado' : 'Desaprobado';

                    $stmtUpdRec->execute([
                        'nota' => $nota,
                        'estado' => $estadoRec,
                        'obs' => $obs !== '' ? $obs : null,
                        'uid' => $userId,
                        'id' => $recId
                    ]);
                    $actualizados++;
                }

                $pdo->commit();
                registrar_auditoria('guardar_notas_vacacional', "Guardó {$actualizados} calificaciones de recuperación vacacional.");
                $mensaje = "Se guardaron correctamente {$actualizados} calificaciones de recuperación pedagógica vacacional.";
                $tabActiva = 'vacacional';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = 'Error al guardar notas de vacacional: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'aplicar_cierre_definitivo') {
        // Cierre anual definitivo: actualiza estado académico de los estudiantes
        $alumnosFinales = $_POST['estado_final_alumno'] ?? []; // Array [estudiante_id => estado]

        if (!is_array($alumnosFinales) || empty($alumnosFinales)) {
            $error = 'No se recibieron datos de estudiantes para aplicar el cierre anual.';
        } else {
            try {
                $pdo->beginTransaction();
                $stmtUpdEstFinal = $pdo->prepare('
                    UPDATE estudiante 
                    SET estado_academico = :estado, actualizado_en = CURRENT_TIMESTAMP
                    WHERE id = :id
                ');

                $cantPromovidos = 0;
                $cantRepitentes = 0;
                $cantPromocion = 0;

                foreach ($alumnosFinales as $estIdRaw => $estadoFinal) {
                    $estId = filter_var($estIdRaw, FILTER_VALIDATE_INT);
                    $estadoFinal = trim((string)$estadoFinal);

                    if (!$estId || !in_array($estadoFinal, ['Promovido', 'Repitente', 'Promoción'], true)) {
                        continue;
                    }

                    $stmtUpdEstFinal->execute(['estado' => $estadoFinal, 'id' => $estId]);

                    if ($estadoFinal === 'Promovido') $cantPromovidos++;
                    elseif ($estadoFinal === 'Repitente') $cantRepitentes++;
                    elseif ($estadoFinal === 'Promoción') $cantPromocion++;
                }

                $pdo->commit();
                $totalCerrados = $cantPromovidos + $cantRepitentes + $cantPromocion;
                registrar_auditoria('cierre_anual_definitivo', "Aplicó cierre anual a {$totalCerrados} alumnos ({$cantPromovidos} Promovidos, {$cantPromocion} Promoción, {$cantRepitentes} Repitentes).");
                $mensaje = "¡Cierre académico anual aplicado con éxito! Se actualizaron {$totalCerrados} estudiantes: {$cantPromovidos} Promovidos, {$cantPromocion} Egresados de Promoción y {$cantRepitentes} Repitentes.";
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = 'Error al aplicar el cierre definitivo: ' . $e->getMessage();
            }
        }
    }
}

// -------------------------------------------------------------
// CONSULTA Y EVALUACIÓN ANUAL CNEB DE ESTUDIANTES
// -------------------------------------------------------------
$whereMat = ["m.estado = 'Activa'"];
$paramsMat = [];

if ($anioId !== null) {
    $whereMat[] = "m.anio_id = :aid";
    $paramsMat['aid'] = $anioId;
}
if ($nivelId !== null) {
    $whereMat[] = "m.nivel_id = :nid";
    $paramsMat['nid'] = $nivelId;
}
if ($gradoId !== null) {
    $whereMat[] = "gs.grado_id = :gid";
    $paramsMat['gid'] = $gradoId;
}
if ($seccionId !== null) {
    $whereMat[] = "gs.seccion_id = :sec";
    $paramsMat['sec'] = $seccionId;
}

$sqlAlumnosMatriculados = "
    SELECT 
        m.id AS matricula_id,
        m.anio_id,
        m.nivel_id,
        m.grado_seccion_id,
        e.id AS estudiante_id,
        e.dni,
        e.apellido_paterno,
        e.apellido_materno,
        e.nombres,
        e.estado_academico,
        n.nombre AS nivel_nombre,
        g.id AS grado_id,
        g.nombre AS grado_nombre,
        g.numero AS grado_numero,
        s.id AS seccion_id,
        s.nombre AS seccion_nombre,
        a.anio AS anio_lectivo
    FROM matricula m
    JOIN estudiante e ON e.id = m.estudiante_id
    JOIN anio a ON a.id = m.anio_id
    JOIN nivel n ON n.id = m.nivel_id
    JOIN grado_seccion gs ON gs.id = m.grado_seccion_id
    JOIN grado g ON g.id = gs.grado_id
    JOIN seccion s ON s.id = gs.seccion_id
    WHERE " . implode(' AND ', $whereMat) . "
    ORDER BY n.id, g.numero, s.nombre, LOWER(TRIM(e.apellido_paterno)), LOWER(TRIM(COALESCE(e.apellido_materno, ''))), LOWER(TRIM(e.nombres))
";

$stmtAlumnosMat = $pdo->prepare($sqlAlumnosMatriculados);
$stmtAlumnosMat->execute($paramsMat);
$alumnosMatriculados = $stmtAlumnosMat->fetchAll();

$matriculaIds = array_map('intval', array_column($alumnosMatriculados, 'matricula_id'));

// 2. Cargar Calificaciones de los Alumnos Matriculados
$calificacionesPorMatricula = [];
if (!empty($matriculaIds)) {
    $placeholders = implode(',', array_fill(0, count($matriculaIds), '?'));
    $sqlCalif = "
        SELECT 
            cal.matricula_id,
            cal.curso_id,
            cal.periodo_id,
            cal.nota,
            c.nombre AS curso_nombre,
            c.grado AS curso_grado,
            pa.nombre AS periodo_nombre
        FROM calificacion cal
        JOIN curso c ON c.id = cal.curso_id
        JOIN periodo_academico pa ON pa.id = cal.periodo_id
        WHERE cal.matricula_id IN ($placeholders)
        ORDER BY cal.matricula_id, c.nombre, pa.fecha_inicio ASC
    ";
    $stmtCalif = $pdo->prepare($sqlCalif);
    $stmtCalif->execute($matriculaIds);
    $rawCalif = $stmtCalif->fetchAll();

    foreach ($rawCalif as $rc) {
        $mid = (int) $rc['matricula_id'];
        $cid = (int) $rc['curso_id'];
        $pid = (int) $rc['periodo_id'];
        $calificacionesPorMatricula[$mid][$cid][$pid] = strtoupper(trim((string)$rc['nota']));
    }
}

// 3. Cargar Cursos activos por Aula
$cursosPorGrado = [];
$cStmt = $pdo->query('SELECT id, nombre, grado FROM curso WHERE activo = TRUE ORDER BY grado, nombre');
foreach ($cStmt->fetchAll() as $cur) {
    $cursosPorGrado[trim($cur['grado'])][] = $cur;
}

// 4. Cargar Registros de Vacacional existentes
$recuperacionesExistentes = [];
if (!empty($matriculaIds)) {
    $placeholders = implode(',', array_fill(0, count($matriculaIds), '?'));
    $sqlRec = "
        SELECT 
            rec.id,
            rec.matricula_id,
            rec.curso_id,
            rec.nota_final_regular,
            rec.nota_recuperacion,
            rec.estado,
            rec.observacion,
            c.nombre AS curso_nombre,
            c.grado AS curso_grado
        FROM recuperacion_academica rec
        JOIN curso c ON c.id = rec.curso_id
        WHERE rec.matricula_id IN ($placeholders)
        ORDER BY rec.matricula_id, c.nombre
    ";
    $stmtRec = $pdo->prepare($sqlRec);
    $stmtRec->execute($matriculaIds);
    foreach ($stmtRec->fetchAll() as $r) {
        $mid = (int) $r['matricula_id'];
        $cid = (int) $r['curso_id'];
        $recuperacionesExistentes[$mid][$cid] = $r;
    }
}

// -------------------------------------------------------------
// MOTOR DE EVALUACIÓN Y DIAGNÓSTICO CNEB
// -------------------------------------------------------------
$escalaPuntos = ['AD' => 4, 'A' => 3, 'B' => 2, 'C' => 1];
$puntosEscala = function(float $promedio): string {
    if ($promedio >= 3.5) return 'AD';
    if ($promedio >= 2.5) return 'A';
    if ($promedio >= 1.7) return 'B';
    return 'C';
};

$estudiantesEvaluados = [];
$totalPromovidosDirectos = 0;
$totalARecuperacion = 0;
$totalRepitentesDirectos = 0;
$totalPromocionQuinto = 0;
$totalAlumnosAnalizados = count($alumnosMatriculados);

foreach ($alumnosMatriculados as $alumno) {
    $mid = (int) $alumno['matricula_id'];
    $eid = (int) $alumno['estudiante_id'];
    $nivel = trim($alumno['nivel_nombre']);
    $gradoNumero = (int) $alumno['grado_numero'];
    $gradoNombre = trim($alumno['grado_nombre']);
    $aulaEtiqueta = $nivel . ' - ' . $gradoNombre;

    $cursosDelAula = $cursosPorGrado[$aulaEtiqueta] ?? [];
    if (empty($cursosDelAula)) {
        // Buscar por nivel y grado si la etiqueta varía
        foreach ($cursosPorGrado as $etiqueta => $curList) {
            if (mb_stripos($etiqueta, $nivel) !== false && mb_stripos($etiqueta, $gradoNombre) !== false) {
                $cursosDelAula = $curList;
                break;
            }
        }
    }

    $resumenCursos = [];
    $cursosEnC = [];
    $cursosEnB = [];
    $totalMaterias = count($cursosDelAula);
    $materiasEvaluadas = 0;

    foreach ($cursosDelAula as $cur) {
        $cid = (int) $cur['id'];
        $notasBimestrales = $calificacionesPorMatricula[$mid][$cid] ?? [];

        // Calcular promedio del curso
        $sumaPuntos = 0;
        $cantNotas = 0;
        $ultimaNota = '';

        foreach ($periodos as $per) {
            $pid = (int) $per['id'];
            if (isset($notasBimestrales[$pid])) {
                $valNota = $notasBimestrales[$pid];
                $sumaPuntos += ($escalaPuntos[$valNota] ?? 2);
                $cantNotas++;
                $ultimaNota = $valNota;
            }
        }

        if ($cantNotas > 0) {
            $materiasEvaluadas++;
            $promedio = $sumaPuntos / $cantNotas;
            $notaFinalCurso = $puntosEscala($promedio);

            // Si en el último bimestre tiene C, prevalece dificultad
            if ($ultimaNota === 'C' && $promedio < 2.0) {
                $notaFinalCurso = 'C';
            }
        } else {
            $notaFinalCurso = '-';
        }

        if ($notaFinalCurso === 'C') {
            $cursosEnC[] = [
                'curso_id' => $cid,
                'nombre' => $cur['nombre'],
                'nota_final' => $notaFinalCurso
            ];
        } elseif ($notaFinalCurso === 'B') {
            $cursosEnB[] = [
                'curso_id' => $cid,
                'nombre' => $cur['nombre'],
                'nota_final' => $notaFinalCurso
            ];
        }

        $resumenCursos[$cid] = [
            'nombre' => $cur['nombre'],
            'notas_bimestres' => $notasBimestrales,
            'nota_final' => $notaFinalCurso
        ];
    }

    $cantC = count($cursosEnC);

    // DETERMINACIÓN DE LA SITUACIÓN FINAL SEGÚN NORMA MINEDU / CNEB
    $situacionFinal = 'Promovido';
    $codigoSituacion = 'PROMOVIDO';
    $badgeSituacion = 'bg-success text-white';

    if ($nivel === 'Inicial') {
        // En Inicial la promoción es 100% automática según RVM 094-2020-MINEDU
        $situacionFinal = 'Promovido';
        $codigoSituacion = 'PROMOVIDO';
        $badgeSituacion = 'bg-success text-white';
        $totalPromovidosDirectos++;
    } elseif ($nivel === 'Primaria') {
        if ($gradoNumero <= 2) {
            // 1° y 2° de Primaria: Promoción automática oficial
            $situacionFinal = 'Promovido';
            $codigoSituacion = 'PROMOVIDO';
            $badgeSituacion = 'bg-success text-white';
            $totalPromovidosDirectos++;
        } else {
            // 3° a 6° de Primaria
            if ($cantC === 0) {
                $situacionFinal = 'Promovido';
                $codigoSituacion = 'PROMOVIDO';
                $badgeSituacion = 'bg-success text-white';
                $totalPromovidosDirectos++;
            } elseif ($cantC >= 1 && $cantC <= 3) {
                $situacionFinal = 'Recuperación';
                $codigoSituacion = 'RECUPERACION';
                $badgeSituacion = 'bg-warning text-dark';
                $totalARecuperacion++;
            } else {
                $situacionFinal = 'Repitente';
                $codigoSituacion = 'REPITENTE';
                $badgeSituacion = 'bg-danger text-white';
                $totalRepitentesDirectos++;
            }
        }
    } elseif ($nivel === 'Secundaria') {
        if ($gradoNumero === 5) {
            // 5to de Secundaria (Promoción egresada)
            if ($cantC === 0) {
                $situacionFinal = 'Promoción';
                $codigoSituacion = 'PROMOCION';
                $badgeSituacion = 'bg-primary text-white';
                $totalPromocionQuinto++;
            } elseif ($cantC >= 1 && $cantC <= 3) {
                $situacionFinal = 'Recuperación';
                $codigoSituacion = 'RECUPERACION';
                $badgeSituacion = 'bg-warning text-dark';
                $totalARecuperacion++;
            } else {
                $situacionFinal = 'Repitente';
                $codigoSituacion = 'REPITENTE';
                $badgeSituacion = 'bg-danger text-white';
                $totalRepitentesDirectos++;
            }
        } else {
            // 1° a 4° de Secundaria
            if ($cantC === 0) {
                $situacionFinal = 'Promovido';
                $codigoSituacion = 'PROMOVIDO';
                $badgeSituacion = 'bg-success text-white';
                $totalPromovidosDirectos++;
            } elseif ($cantC >= 1 && $cantC <= 3) {
                $situacionFinal = 'Recuperación';
                $codigoSituacion = 'RECUPERACION';
                $badgeSituacion = 'bg-warning text-dark';
                $totalARecuperacion++;
            } else {
                $situacionFinal = 'Repitente';
                $codigoSituacion = 'REPITENTE';
                $badgeSituacion = 'bg-danger text-white';
                $totalRepitentesDirectos++;
            }
        }
    }

    // Verificar si el alumno ya está en el registro de recuperación y cómo va
    $recuperacionesAlumno = $recuperacionesExistentes[$mid] ?? [];
    $totalRecuperando = count($recuperacionesAlumno);
    $recuperadosAprobados = 0;
    $recuperadosDesaprobados = 0;
    foreach ($recuperacionesAlumno as $rInfo) {
        if ($rInfo['estado'] === 'Aprobado') $recuperadosAprobados++;
        elseif ($rInfo['estado'] === 'Desaprobado') $recuperadosDesaprobados++;
    }

    $estadoFinalPropuesto = $situacionFinal;
    if ($totalRecuperando > 0) {
        if ($recuperadosAprobados === $totalRecuperando) {
            $estadoFinalPropuesto = ($nivel === 'Secundaria' && $gradoNumero === 5) ? 'Promoción' : 'Promovido';
        } elseif ($recuperadosDesaprobados > 0) {
            $estadoFinalPropuesto = 'Repitente';
        }
    }

    $estudiantesEvaluados[] = [
        'matricula_id' => $mid,
        'estudiante_id' => $eid,
        'dni' => $alumno['dni'],
        'nombres' => $alumno['nombres'],
        'apellido_paterno' => $alumno['apellido_paterno'],
        'apellido_materno' => $alumno['apellido_materno'] ?? '',
        'nombre_completo' => $alumno['apellido_paterno'] . ' ' . ($alumno['apellido_materno'] ?? '') . ', ' . $alumno['nombres'],
        'nivel_nombre' => $nivel,
        'grado_nombre' => $gradoNombre,
        'grado_numero' => $gradoNumero,
        'seccion_nombre' => $alumno['seccion_nombre'],
        'estado_academico_actual' => $alumno['estado_academico'],
        'total_materias' => $totalMaterias,
        'materias_evaluadas' => $materiasEvaluadas,
        'cant_c' => $cantC,
        'cursos_desaprobados' => $cursosEnC,
        'situacion_final' => $situacionFinal,
        'codigo_situacion' => $codigoSituacion,
        'badge_situacion' => $badgeSituacion,
        'recuperaciones' => $recuperacionesAlumno,
        'estado_final_propuesto' => $estadoFinalPropuesto,
        'resumen_cursos' => $resumenCursos,
    ];
}

// Alumnos que van a Vacacional / Recuperación
$alumnosParaVacacional = array_filter($estudiantesEvaluados, fn($e) => $e['codigo_situacion'] === 'RECUPERACION' || !empty($e['recuperaciones']));
// Alumnos de 5to de Secundaria (Promoción)
$alumnosPromocion = array_filter($estudiantesEvaluados, fn($e) => $e['nivel_nombre'] === 'Secundaria' && $e['grado_numero'] === 5);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cierre Académico y Programa Vacacional - Sistema Escolar</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../assets/css/app.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/cierre_academico.css">
</head>
<body>
<?php render_app_header(panel_home_path(), 'logout.php'); ?>

<main class="container-fluid px-3 px-md-4 px-xl-5 py-4">
    <!-- Encabezado Membretado Oficial para Impresión -->
    <div class="print-only mb-4 border-bottom pb-3">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h3 class="fw-bold mb-0 text-dark">I.E. DIVINO MAESTRO</h3>
                <span class="text-muted small">Sistema de Gestión Académica y Cierre de Año Escolar</span>
            </div>
            <div class="text-end">
                <h5 class="fw-bold mb-0 text-primary">ACTA OFICIAL DE SITUACIÓN FINAL Y PROGRAMA VACACIONAL</h5>
                <small class="text-muted">Año Lectivo: <?= htmlspecialchars($anioActualInfo['anio'] ?? date('Y')) ?> | Fecha: <?= date('d/m/Y H:i') ?></small>
            </div>
        </div>
    </div>

    <!-- Encabezado de Página -->
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4 no-print">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1 small">
                    <li class="breadcrumb-item"><a href="<?= htmlspecialchars(panel_home_path()) ?>" class="text-decoration-none text-muted"><i class="bi bi-house-door"></i> Inicio</a></li>
                    <li class="breadcrumb-item active text-primary fw-semibold" aria-current="page">Cierre y Vacacional</li>
                </ol>
            </nav>
            <h1 class="h3 fw-bold mb-1 text-dark d-flex align-items-center gap-2">
                <i class="bi bi-award-fill text-primary"></i> Cierre Académico y Programa Vacacional
                <span class="badge bg-primary-subtle text-primary border border-primary-subtle fs-6 fw-semibold">Año Lectivo <?= htmlspecialchars((string)($anioActualInfo['anio'] ?? date('Y'))) ?></span>
            </h1>
            <p class="text-muted mb-0 small">Evaluación final oficial del CNEB, generación del padrón de recuperación vacacional y promoción automática de alumnos.</p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <button onclick="window.print()" class="btn btn-outline-secondary d-inline-flex align-items-center gap-1.5 px-3 py-2 fw-semibold" style="border-radius: 10px;">
                <i class="bi bi-printer-fill"></i> Imprimir Reporte
            </button>
            <a href="reporte_calificaciones.php" class="btn btn-outline-primary d-inline-flex align-items-center gap-1.5 px-3 py-2 fw-semibold" style="border-radius: 10px;">
                <i class="bi bi-graph-up-arrow"></i> Gráficas de Notas
            </a>
            <a href="matriculas.php" class="btn btn-primary d-inline-flex align-items-center gap-1.5 px-3 py-2 fw-semibold" style="border-radius: 10px;">
                <i class="bi bi-journal-bookmark-fill"></i> Ir a Matrículas
            </a>
        </div>
    </div>

    <?php if ($mensaje !== ''): ?>
        <div class="alert alert-success d-flex align-items-center gap-2 shadow-sm border-0 mb-4 fs-6" style="border-radius: 12px;">
            <i class="bi bi-check-circle-fill fs-5"></i>
            <div><?= htmlspecialchars($mensaje) ?></div>
        </div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="alert alert-danger d-flex align-items-center gap-2 shadow-sm border-0 mb-4 fs-6" style="border-radius: 12px;">
            <i class="bi bi-exclamation-triangle-fill fs-5"></i>
            <div><?= htmlspecialchars($error) ?></div>
        </div>
    <?php endif; ?>

    <!-- Formulario de Filtros de Aula y Año Lectivo -->
    <form method="GET" class="card main-card mb-4 no-print" id="formFiltroCierre">
        <div class="card-body p-3 p-md-4">
            <div class="row g-3 align-items-end">
                <!-- 1. Año Lectivo -->
                <div class="col-12 col-sm-6 col-md-2">
                    <label class="form-label fw-semibold small text-muted" for="anio_id">Año Lectivo</label>
                    <select class="form-select fw-bold text-primary" id="anio_id" name="anio_id" onchange="this.form.submit()">
                        <?php foreach ($anios as $a): ?>
                            <option value="<?= (int) $a['id'] ?>" <?= $anioId === (int) $a['id'] ? 'selected' : '' ?>>
                                Año <?= htmlspecialchars((string) $a['anio']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- 2. Nivel Educativo -->
                <div class="col-12 col-sm-6 col-md-3">
                    <label class="form-label fw-semibold small text-muted" for="nivel_id">Nivel Educativo</label>
                    <select class="form-select fw-semibold" id="nivel_id" name="nivel_id" onchange="onNivelChange(this.value)">
                        <option value="" <?= $nivelId === null ? 'selected' : '' ?>>-- Todos los niveles --</option>
                        <?php foreach ($niveles as $niv): ?>
                            <option value="<?= (int) $niv['id'] ?>" <?= $nivelId === (int) $niv['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($niv['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- 3. Grado -->
                <div class="col-12 col-sm-6 col-md-3">
                    <label class="form-label fw-semibold small text-muted" for="grado_id">Grado</label>
                    <select class="form-select fw-semibold" id="grado_id" name="grado_id" onchange="onGradoChange(this.value)">
                        <option value="" <?= $gradoId === null ? 'selected' : '' ?>>-- Todos los grados --</option>
                        <?php foreach ($gradosDisponibles as $grd): ?>
                            <option value="<?= (int) $grd['id'] ?>" <?= $gradoId === (int) $grd['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($grd['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- 4. Sección -->
                <div class="col-12 col-sm-6 col-md-2">
                    <label class="form-label fw-semibold small text-muted" for="seccion_id">Sección</label>
                    <select class="form-select" id="seccion_id" name="seccion_id" onchange="this.form.submit()" <?= $gradoId === null ? 'disabled' : '' ?>>
                        <option value="">Todas las secciones</option>
                        <?php foreach ($seccionesDisponibles as $sec): ?>
                            <option value="<?= (int) $sec['id'] ?>" <?= $seccionId === (int) $sec['id'] ? 'selected' : '' ?>>
                                Sección "<?= htmlspecialchars($sec['nombre']) ?>"
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Botón Actualizar -->
                <div class="col-12 col-sm-6 col-md-2 d-flex gap-2">
                    <input type="hidden" name="tab" value="<?= htmlspecialchars($tabActiva) ?>">
                    <button class="btn btn-primary w-100 fw-semibold" type="submit" style="min-height: 42px; border-radius: 9px;">
                        <i class="bi bi-funnel-fill me-1"></i> Filtrar
                    </button>
                    <a href="cierre_academico.php" class="btn btn-outline-secondary fw-semibold px-3" style="min-height: 42px; border-radius: 9px;" title="Restablecer">
                        <i class="bi bi-x-lg"></i>
                    </a>
                </div>
            </div>
        </div>
    </form>

    <!-- Resumen Superior en Tarjetas KPIs -->
    <div class="row g-3 mb-4">
        <!-- 1. Total Evaluados -->
        <div class="col-6 col-md-3 col-xl-2">
            <div class="kpi-card border-start border-4 border-secondary">
                <span class="text-muted small text-uppercase fw-bold d-block">Total Alumnos</span>
                <h3 class="fw-bold text-dark mb-0 mt-1"><?= $totalAlumnosAnalizados ?></h3>
                <small class="text-muted">Matriculados en el año</small>
            </div>
        </div>
        <!-- 2. Promovidos Directos -->
        <div class="col-6 col-md-3 col-xl-2">
            <div class="kpi-card border-start border-4 border-success">
                <span class="text-success small text-uppercase fw-bold d-block">Promovidos</span>
                <h3 class="fw-bold text-success mb-0 mt-1"><?= $totalPromovidosDirectos ?></h3>
                <small class="text-muted"><?= $totalAlumnosAnalizados > 0 ? round(($totalPromovidosDirectos / $totalAlumnosAnalizados) * 100, 1) : 0 ?>% del total</small>
            </div>
        </div>
        <!-- 3. A Recuperación Vacacional -->
        <div class="col-6 col-md-3 col-xl-3">
            <div class="kpi-card border-start border-4 border-warning">
                <span class="text-warning-emphasis small text-uppercase fw-bold d-block">A Recuperación Vacacional</span>
                <h3 class="fw-bold text-warning-emphasis mb-0 mt-1"><?= $totalARecuperacion ?></h3>
                <small class="text-warning-emphasis fw-semibold"><?= count($alumnosParaVacacional) ?> en padrón de verano</small>
            </div>
        </div>
        <!-- 4. Repitentes Directos -->
        <div class="col-6 col-md-3 col-xl-2">
            <div class="kpi-card border-start border-4 border-danger">
                <span class="text-danger small text-uppercase fw-bold d-block">Repitencia</span>
                <h3 class="fw-bold text-danger mb-0 mt-1"><?= $totalRepitentesDirectos ?></h3>
                <small class="text-danger fw-semibold">4 o más áreas en C</small>
            </div>
        </div>
        <!-- 5. Promoción (5to de Secundaria) -->
        <div class="col-12 col-md-12 col-xl-3">
            <div class="kpi-card border-start border-4 border-primary">
                <span class="text-primary small text-uppercase fw-bold d-block">Promoción 5to Secundaria</span>
                <h3 class="fw-bold text-primary mb-0 mt-1"><?= $totalPromocionQuinto ?></h3>
                <small class="text-muted">Egresados que culminan EBR</small>
            </div>
        </div>
    </div>

    <!-- Navegación por Pestañas del Cierre -->
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 no-print">
        <ul class="nav nav-pills custom-pills p-1 bg-white border rounded-3 shadow-sm" role="tablist">
            <li class="nav-item">
                <a class="nav-link <?= $tabActiva === 'diagnostico' ? 'active' : '' ?>" href="?anio_id=<?= $anioId ?>&nivel_id=<?= $nivelId ?>&grado_id=<?= $gradoId ?>&seccion_id=<?= $seccionId ?>&tab=diagnostico">
                    <i class="bi bi-file-earmark-spreadsheet me-1"></i> 1. Diagnóstico y Situación Final
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $tabActiva === 'vacacional' ? 'active' : '' ?>" href="?anio_id=<?= $anioId ?>&nivel_id=<?= $nivelId ?>&grado_id=<?= $gradoId ?>&seccion_id=<?= $seccionId ?>&tab=vacacional">
                    <i class="bi bi-sun-fill text-warning me-1"></i> 2. Programa Vacacional (<?= count($alumnosParaVacacional) ?>)
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $tabActiva === 'promocion' ? 'active' : '' ?>" href="?anio_id=<?= $anioId ?>&nivel_id=<?= $nivelId ?>&grado_id=<?= $gradoId ?>&seccion_id=<?= $seccionId ?>&tab=promocion">
                    <i class="bi bi-mortarboard-fill text-primary me-1"></i> 3. Egresados Promoción 5to (<?= count($alumnosPromocion) ?>)
                </a>
            </li>
        </ul>

        <div class="d-flex align-items-center gap-2">
            <?php if ($tabActiva === 'diagnostico' && $totalARecuperacion > 0): ?>
                <button type="button" class="btn btn-warning text-dark fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#modalEnviarVacacional">
                    <i class="bi bi-send-check-fill me-1"></i> Inscribir a Vacacional (<?= $totalARecuperacion ?>)
                </button>
            <?php endif; ?>

            <button type="button" class="btn btn-success fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#modalCierreDefinitivo">
                <i class="bi bi-check2-all me-1"></i> Aplicar Cierre Anual Definitivo
            </button>
        </div>
    </div>

    <!-- PESTAÑA 1: DIAGNÓSTICO Y ACTA DE SITUACIÓN FINAL -->
    <?php if ($tabActiva === 'diagnostico'): ?>
        <div class="card main-card overflow-hidden">
            <div class="card-header bg-white py-3 px-4 border-bottom d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <h5 class="fw-bold text-dark mb-0 d-flex align-items-center gap-2">
                        <i class="bi bi-table text-primary"></i> Nómina de Evaluación de Situación Final CNEB
                    </h5>
                    <small class="text-muted">Cálculo de materias aprobadas y desaprobadas para determinar automáticamente quién promueve, va a vacacional o repite.</small>
                </div>
                <div>
                    <span class="badge bg-light text-secondary border px-3 py-1.5 fw-semibold">
                        <?= count($estudiantesEvaluados) ?> Estudiantes Listados
                    </span>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th class="text-center" style="width: 45px;">#</th>
                            <th style="min-width: 95px;">DNI</th>
                            <th style="min-width: 240px;">Estudiante</th>
                            <th style="min-width: 150px;">Aula / Grado</th>
                            <th class="text-center" style="min-width: 130px;">Áreas Desaprobadas</th>
                            <th style="min-width: 220px;">Cursos a Nivelar (en C)</th>
                            <th class="text-center" style="min-width: 160px;">Situación Calculada</th>
                            <th class="text-center" style="min-width: 130px;">Estado en BD</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($estudiantesEvaluados)): ?>
                        <tr>
                            <td colspan="8" class="text-center py-5 text-muted">
                                <i class="bi bi-inbox fs-1 d-block mb-2 text-secondary"></i>
                                No se encontraron estudiantes matriculados para los filtros seleccionados.
                            </td>
                        </tr>
                    <?php else: 
                        $i = 1;
                        foreach ($estudiantesEvaluados as $est): 
                    ?>
                        <tr>
                            <td class="text-center text-muted fw-semibold"><?= $i++ ?></td>
                            <td><span class="font-monospace fw-bold text-dark"><?= htmlspecialchars($est['dni']) ?></span></td>
                            <td>
                                <a href="ver_estudiante.php?id=<?= $est['estudiante_id'] ?>" class="text-decoration-none fw-bold text-dark hover-primary">
                                    <?= htmlspecialchars($est['nombre_completo']) ?>
                                </a>
                            </td>
                            <td>
                                <span class="badge bg-light text-secondary border">
                                    <?= htmlspecialchars($est['nivel_nombre'] . ' - ' . $est['grado_nombre'] . ' "' . $est['seccion_nombre'] . '"') ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <?php if ($est['cant_c'] === 0): ?>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle px-2.5 py-1">
                                        <i class="bi bi-check-circle-fill me-1"></i> 0 cursos en C
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-danger text-white px-2.5 py-1 fw-bold">
                                        <?= $est['cant_c'] ?> <?= $est['cant_c'] === 1 ? 'curso en C' : 'cursos en C' ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (empty($est['cursos_desaprobados'])): ?>
                                    <span class="text-muted small"><i class="bi bi-check2 text-success me-1"></i>Todas las áreas aprobadas</span>
                                <?php else: ?>
                                    <div class="d-flex flex-wrap gap-1">
                                        <?php foreach ($est['cursos_desaprobados'] as $cDes): ?>
                                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle fw-semibold" style="font-size: 0.78rem;">
                                                <?= htmlspecialchars($cDes['nombre']) ?> (C)
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ($est['codigo_situacion'] === 'PROMOVIDO'): ?>
                                    <span class="badge bg-success text-white px-3 py-1.5 fw-bold" style="font-size: 0.88rem;">
                                        <i class="bi bi-check2-circle me-1"></i> Promovido
                                    </span>
                                <?php elseif ($est['codigo_situacion'] === 'RECUPERACION'): ?>
                                    <span class="badge bg-warning text-dark px-3 py-1.5 fw-bold" style="font-size: 0.88rem;">
                                        <i class="bi bi-clock-history me-1"></i> Vacacional
                                    </span>
                                <?php elseif ($est['codigo_situacion'] === 'PROMOCION'): ?>
                                    <span class="badge bg-primary text-white px-3 py-1.5 fw-bold" style="font-size: 0.88rem;">
                                        <i class="bi bi-mortarboard-fill me-1"></i> Promoción (5to)
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-danger text-white px-3 py-1.5 fw-bold" style="font-size: 0.88rem;">
                                        <i class="bi bi-arrow-repeat me-1"></i> Repitente
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-light text-secondary border px-2 py-1">
                                    <?= htmlspecialchars($est['estado_academico_actual']) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <!-- PESTAÑA 2: PROGRAMA DE RECUPERACIÓN PEDAGÓGICA (VACACIONAL) -->
    <?php elseif ($tabActiva === 'vacacional'): ?>
        <div class="card main-card mb-4 overflow-hidden">
            <div class="card-header bg-white py-3 px-4 border-bottom d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <h5 class="fw-bold text-dark mb-0 d-flex align-items-center gap-2">
                        <i class="bi bi-sun-fill text-warning"></i> Padrón Oficial y Registro de Notas de Vacacional
                    </h5>
                    <small class="text-muted">Ingresa la calificación obtenida en el curso de verano (AD, A, B, C). Si aprueban las materias, pasan automáticamente a Promovidos.</small>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <button onclick="window.print()" class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1">
                        <i class="bi bi-printer-fill"></i> Imprimir Padrón Vacacional
                    </button>
                </div>
            </div>

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="guardar_notas_vacacional">

                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th class="text-center" style="width: 45px;">#</th>
                                <th style="min-width: 95px;">DNI</th>
                                <th style="min-width: 220px;">Estudiante</th>
                                <th style="min-width: 140px;">Grado / Sección</th>
                                <th style="min-width: 200px;">Asignatura a Recuperar</th>
                                <th class="text-center" style="width: 110px;">Nota Regular</th>
                                <th style="min-width: 160px;">Nota Vacacional</th>
                                <th class="text-center" style="min-width: 130px;">Estado Vacacional</th>
                                <th style="min-width: 180px;">Observación</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php 
                        $totalFilasVacacional = 0;
                        if (!empty($alumnosParaVacacional)):
                            $vIdx = 1;
                            foreach ($alumnosParaVacacional as $alVac):
                                $mid = (int) $alVac['matricula_id'];
                                $recList = $alVac['recuperaciones'];

                                // Si aún no está en la tabla de recuperaciones, mostrar de su diagnóstico
                                if (empty($recList)) {
                                    foreach ($alVac['cursos_desaprobados'] as $cDes) {
                                        $totalFilasVacacional++;
                                        ?>
                                        <tr class="table-warning bg-opacity-25">
                                            <td class="text-center text-muted fw-semibold"><?= $vIdx++ ?></td>
                                            <td><span class="font-monospace fw-bold text-dark"><?= htmlspecialchars($alVac['dni']) ?></span></td>
                                            <td>
                                                <strong><?= htmlspecialchars($alVac['nombre_completo']) ?></strong>
                                            </td>
                                            <td><?= htmlspecialchars($alVac['grado_nombre'] . ' "' . $alVac['seccion_nombre'] . '"') ?></td>
                                            <td><span class="fw-bold text-danger"><?= htmlspecialchars($cDes['nombre']) ?></span></td>
                                            <td class="text-center"><span class="nota-badge nota-c">C</span></td>
                                            <td colspan="3">
                                                <span class="badge bg-secondary-subtle text-secondary border">
                                                    <i class="bi bi-info-circle me-1"></i> Pulsa "Inscribir a Vacacional" para abrir el registro de notas
                                                </span>
                                            </td>
                                        </tr>
                                        <?php
                                    }
                                } else {
                                    foreach ($recList as $cid => $rInfo):
                                        $totalFilasVacacional++;
                                        $recId = (int) $rInfo['id'];
                                        $notaRec = $rInfo['nota_recuperacion'] ?? '';
                                        $estRec = $rInfo['estado'] ?? 'Pendiente';
                                        ?>
                                        <tr>
                                            <td class="text-center text-muted fw-semibold"><?= $vIdx++ ?></td>
                                            <td><span class="font-monospace fw-bold text-dark"><?= htmlspecialchars($alVac['dni']) ?></span></td>
                                            <td>
                                                <a href="ver_estudiante.php?id=<?= $alVac['estudiante_id'] ?>" class="text-decoration-none fw-bold text-dark hover-primary">
                                                    <?= htmlspecialchars($alVac['nombre_completo']) ?>
                                                </a>
                                            </td>
                                            <td><?= htmlspecialchars($alVac['grado_nombre'] . ' "' . $alVac['seccion_nombre'] . '"') ?></td>
                                            <td><span class="fw-bold text-primary"><?= htmlspecialchars($rInfo['curso_nombre']) ?></span></td>
                                            <td class="text-center"><span class="nota-badge nota-c"><?= htmlspecialchars($rInfo['nota_final_regular']) ?></span></td>
                                            <td>
                                                <select class="form-select form-select-sm fw-bold" name="nota_vacacional[<?= $recId ?>]" style="border-radius: 8px;">
                                                    <option value="">-- Sin nota --</option>
                                                    <option value="AD" <?= $notaRec === 'AD' ? 'selected' : '' ?> class="text-primary">AD · Destacado (Aprueba)</option>
                                                    <option value="A" <?= $notaRec === 'A' ? 'selected' : '' ?> class="text-success">A · Esperado (Aprueba)</option>
                                                    <option value="B" <?= $notaRec === 'B' ? 'selected' : '' ?> class="text-warning-emphasis">B · En Proceso (Aprueba)</option>
                                                    <option value="C" <?= $notaRec === 'C' ? 'selected' : '' ?> class="text-danger">C · En Inicio (Desaprueba)</option>
                                                </select>
                                            </td>
                                            <td class="text-center">
                                                <?php if ($estRec === 'Aprobado'): ?>
                                                    <span class="badge bg-success text-white px-2.5 py-1">
                                                        <i class="bi bi-check-lg me-1"></i> Aprobado
                                                    </span>
                                                <?php elseif ($estRec === 'Desaprobado'): ?>
                                                    <span class="badge bg-danger text-white px-2.5 py-1">
                                                        <i class="bi bi-x-lg me-1"></i> Desaprobado
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-2.5 py-1">
                                                        <i class="bi bi-clock me-1"></i> Pendiente
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <input type="text" class="form-control form-control-sm" name="observacion_vacacional[<?= $recId ?>]" value="<?= htmlspecialchars($rInfo['observacion'] ?? '') ?>" placeholder="Ej: Carpeta / Examen">
                                            </td>
                                        </tr>
                                        <?php
                                    endforeach;
                                }
                            endforeach;
                        endif;
                        if ($totalFilasVacacional === 0):
                        ?>
                            <tr>
                                <td colspan="9" class="text-center py-5 text-muted">
                                    <i class="bi bi-emoji-smile fs-1 d-block mb-2 text-success"></i>
                                    ¡Excelente! No hay alumnos que requieran recuperación vacacional para este filtro.
                                </td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($totalFilasVacacional > 0): ?>
                    <div class="card-footer bg-white py-3 px-4 border-top d-flex justify-content-between align-items-center">
                        <small class="text-muted">Total de asignaturas en vacacional: <strong><?= $totalFilasVacacional ?></strong></small>
                        <button type="submit" class="btn btn-primary fw-semibold px-4 py-2" style="border-radius: 9px;">
                            <i class="bi bi-check-lg me-1"></i> Guardar Calificaciones de Vacacional
                        </button>
                    </div>
                <?php endif; ?>
            </form>
        </div>

    <!-- PESTAÑA 3: EGRESADOS DE PROMOCIÓN (5TO DE SECUNDARIA) -->
    <?php elseif ($tabActiva === 'promocion'): ?>
        <div class="card main-card overflow-hidden">
            <div class="card-header bg-white py-3 px-4 border-bottom d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <h5 class="fw-bold text-dark mb-0 d-flex align-items-center gap-2">
                        <i class="bi bi-mortarboard-fill text-primary"></i> Padrón Oficial de Egresados - Promoción 5to de Secundaria
                    </h5>
                    <small class="text-muted">Estudiantes de último grado que culminan su Educación Básica Regular (EBR) satisfactoriamente.</small>
                </div>
                <div>
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-3 py-1.5 fw-semibold">
                        <?= count($alumnosPromocion) ?> Estudiantes de 5to de Secundaria
                    </span>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th class="text-center" style="width: 45px;">#</th>
                            <th style="min-width: 95px;">DNI</th>
                            <th style="min-width: 250px;">Estudiante</th>
                            <th style="min-width: 140px;">Sección</th>
                            <th class="text-center" style="min-width: 150px;">Áreas Desaprobadas</th>
                            <th class="text-center" style="min-width: 180px;">Condición de Egreso</th>
                            <th class="text-center" style="min-width: 140px;">Estado en BD</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($alumnosPromocion)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-5 text-muted">
                                <i class="bi bi-mortarboard fs-1 d-block mb-2 text-secondary"></i>
                                No se encontraron estudiantes matriculados en 5to de Secundaria para los filtros actuales.
                            </td>
                        </tr>
                    <?php else: 
                        $pIdx = 1;
                        foreach ($alumnosPromocion as $estP): 
                    ?>
                        <tr>
                            <td class="text-center text-muted fw-semibold"><?= $pIdx++ ?></td>
                            <td><span class="font-monospace fw-bold text-dark"><?= htmlspecialchars($estP['dni']) ?></span></td>
                            <td>
                                <a href="ver_estudiante.php?id=<?= $estP['estudiante_id'] ?>" class="text-decoration-none fw-bold text-dark hover-primary">
                                    <?= htmlspecialchars($estP['nombre_completo']) ?>
                                </a>
                            </td>
                            <td>Sección "<?= htmlspecialchars($estP['seccion_nombre']) ?>"</td>
                            <td class="text-center">
                                <?php if ($estP['cant_c'] === 0): ?>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle px-2.5 py-1">
                                        <i class="bi bi-check-circle-fill me-1"></i> Aprobado en Todo
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-danger text-white px-2.5 py-1">
                                        <?= $estP['cant_c'] ?> en C
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ($estP['codigo_situacion'] === 'PROMOCION'): ?>
                                    <span class="badge bg-primary text-white px-3 py-1.5 fw-bold" style="font-size: 0.88rem;">
                                        <i class="bi bi-award-fill me-1"></i> Promoción (Egresado)
                                    </span>
                                <?php elseif ($estP['codigo_situacion'] === 'RECUPERACION'): ?>
                                    <span class="badge bg-warning text-dark px-3 py-1.5 fw-bold" style="font-size: 0.88rem;">
                                        <i class="bi bi-clock-history me-1"></i> Requiere Vacacional
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-danger text-white px-3 py-1.5 fw-bold" style="font-size: 0.88rem;">
                                        <i class="bi bi-arrow-repeat me-1"></i> Repitente
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-light text-secondary border px-2 py-1">
                                    <?= htmlspecialchars($estP['estado_academico_actual']) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <!-- Inclusión de Modales Modulares -->
    <?php require __DIR__ . '/../../app/views/partials/modal_cierre_vacacional.php'; ?>
    <?php require __DIR__ . '/../../app/views/partials/modal_cierre_definitivo.php'; ?>

</main>

<script src="../assets/js/cierre_academico.js"></script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
