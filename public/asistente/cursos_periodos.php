<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/header.php';

require_auth(['asistente', 'director', 'hiperadmin', 'administrador']);
require_permission('p_cursos_periodos');

$csrfToken = $_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));
$error = '';
$mensaje = '';
$tabActiva = $_GET['tab'] ?? ($_POST['tab'] ?? 'cursos');
if (!in_array($tabActiva, ['cursos', 'periodos', 'secciones'], true)) {
    $tabActiva = 'cursos';
}

$validDate = static function (mixed $value): bool {
    if (!is_string($value)) {
        return false;
    }
    $date = DateTime::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
};

// Cargar grados y niveles disponibles
$gradosDb = $pdo->query("
    SELECT g.id, g.numero, g.nombre AS grado_nombre, n.id AS nivel_id, n.nombre AS nivel_nombre
    FROM grado g
    JOIN nivel n ON n.id = g.nivel_id
    WHERE g.activo = TRUE
    ORDER BY n.id, g.numero
")->fetchAll();

if (empty($gradosDb)) {
    $gradosDb = [
        ['nivel_id' => 1, 'nivel_nombre' => 'Inicial', 'grado_nombre' => '3 años', 'numero' => 3],
        ['nivel_id' => 1, 'nivel_nombre' => 'Inicial', 'grado_nombre' => '4 años', 'numero' => 4],
        ['nivel_id' => 1, 'nivel_nombre' => 'Inicial', 'grado_nombre' => '5 años', 'numero' => 5],
        ['nivel_id' => 2, 'nivel_nombre' => 'Primaria', 'grado_nombre' => '1ro', 'numero' => 1],
        ['nivel_id' => 2, 'nivel_nombre' => 'Primaria', 'grado_nombre' => '2do', 'numero' => 2],
        ['nivel_id' => 2, 'nivel_nombre' => 'Primaria', 'grado_nombre' => '3ro', 'numero' => 3],
        ['nivel_id' => 2, 'nivel_nombre' => 'Primaria', 'grado_nombre' => '4to', 'numero' => 4],
        ['nivel_id' => 2, 'nivel_nombre' => 'Primaria', 'grado_nombre' => '5to', 'numero' => 5],
        ['nivel_id' => 2, 'nivel_nombre' => 'Primaria', 'grado_nombre' => '6to', 'numero' => 6],
        ['nivel_id' => 3, 'nivel_nombre' => 'Secundaria', 'grado_nombre' => '1ro', 'numero' => 1],
        ['nivel_id' => 3, 'nivel_nombre' => 'Secundaria', 'grado_nombre' => '2do', 'numero' => 2],
        ['nivel_id' => 3, 'nivel_nombre' => 'Secundaria', 'grado_nombre' => '3ro', 'numero' => 3],
        ['nivel_id' => 3, 'nivel_nombre' => 'Secundaria', 'grado_nombre' => '4to', 'numero' => 4],
        ['nivel_id' => 3, 'nivel_nombre' => 'Secundaria', 'grado_nombre' => '5to', 'numero' => 5],
    ];
}

$gradosPorNivel = [];
foreach ($gradosDb as $g) {
    $gradosPorNivel[$g['nivel_nombre']][] = $g;
}

// PROCESAMIENTO DE ACCIONES (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    $action = $_POST['action'] ?? '';

    if (!is_string($submittedToken) || !hash_equals($csrfToken, $submittedToken)) {
        $error = 'Solicitud inválida. Recarga la página e inténtalo nuevamente.';
    } elseif (!is_string($action)) {
        $error = 'La acción solicitada no es válida.';
    } else {
        try {
            // 1. GESTIÓN DE CURSOS
            if ($action === 'crear_curso' || $action === 'actualizar_curso') {
                $tabActiva = 'cursos';
                $nombre = trim(is_string($_POST['nombre'] ?? null) ? $_POST['nombre'] : '');
                $grado = trim(is_string($_POST['grado'] ?? null) ? $_POST['grado'] : '');
                $aplicarNivel = isset($_POST['aplicar_nivel']) && $_POST['aplicar_nivel'] === '1';

                if ($nombre === '' || strlen($nombre) > 100 || $grado === '' || strlen($grado) > 50) {
                    throw new InvalidArgumentException('Ingresa el nombre del curso y selecciona un grado válido.');
                }

                if ($action === 'crear_curso') {
                    if ($aplicarNivel) {
                        $partes = explode('-', $grado);
                        $nivelDetectado = trim($partes[0]);
                        $gradosObjetivo = $gradosPorNivel[$nivelDetectado] ?? [];

                        if (empty($gradosObjetivo)) {
                            $stmt = $pdo->prepare('INSERT INTO curso (nombre, grado, activo) VALUES (:nombre, :grado, TRUE)');
                            $stmt->execute(['nombre' => $nombre, 'grado' => $grado]);
                            $mensaje = "Curso '{$nombre}' registrado correctamente para {$grado}.";
                        } else {
                            $checkStmt = $pdo->prepare('SELECT 1 FROM curso WHERE LOWER(nombre) = LOWER(:nombre) AND LOWER(grado) = LOWER(:grado) AND activo = TRUE');
                            $insertStmt = $pdo->prepare('INSERT INTO curso (nombre, grado, activo) VALUES (:nombre, :grado, TRUE)');
                            $creados = 0;
                            foreach ($gradosObjetivo as $gObj) {
                                $gradoTexto = $gObj['nivel_nombre'] . ' - ' . $gObj['grado_nombre'];
                                $checkStmt->execute(['nombre' => $nombre, 'grado' => $gradoTexto]);
                                if (!$checkStmt->fetchColumn()) {
                                    $insertStmt->execute(['nombre' => $nombre, 'grado' => $gradoTexto]);
                                    $creados++;
                                }
                            }
                            $mensaje = "Curso '{$nombre}' registrado exitosamente en {$creados} grados de {$nivelDetectado}.";
                        }
                    } else {
                        $checkStmt = $pdo->prepare('SELECT 1 FROM curso WHERE LOWER(nombre) = LOWER(:nombre) AND LOWER(grado) = LOWER(:grado) AND activo = TRUE');
                        $checkStmt->execute(['nombre' => $nombre, 'grado' => $grado]);
                        if ($checkStmt->fetchColumn()) {
                            throw new InvalidArgumentException("El curso '{$nombre}' ya existe para el grado {$grado}.");
                        }
                        $stmt = $pdo->prepare('INSERT INTO curso (nombre, grado, activo) VALUES (:nombre, :grado, TRUE)');
                        $stmt->execute(['nombre' => $nombre, 'grado' => $grado]);
                        $mensaje = "Curso '{$nombre}' registrado correctamente para {$grado}.";
                    }
                } else {
                    $id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                    if ($id === false) {
                        throw new InvalidArgumentException('El curso seleccionado no es válido.');
                    }
                    $stmt = $pdo->prepare('UPDATE curso SET nombre = :nombre, grado = :grado WHERE id = :id AND activo = TRUE');
                    $stmt->execute(['nombre' => $nombre, 'grado' => $grado, 'id' => $id]);
                    if ($stmt->rowCount() === 0) {
                        $exists = $pdo->prepare('SELECT 1 FROM curso WHERE id = :id AND activo = TRUE');
                        $exists->execute(['id' => $id]);
                        if (!$exists->fetchColumn()) {
                            throw new InvalidArgumentException('No se encontró el curso activo.');
                        }
                    }
                    $mensaje = 'Curso actualizado correctamente.';
                }
            } elseif ($action === 'desactivar_curso') {
                $tabActiva = 'cursos';
                $id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($id === false) {
                    throw new InvalidArgumentException('El curso seleccionado no es válido.');
                }
                $stmt = $pdo->prepare('UPDATE curso SET activo = FALSE WHERE id = :id AND activo = TRUE');
                $stmt->execute(['id' => $id]);
                $mensaje = $stmt->rowCount() > 0 ? 'Curso desactivado correctamente.' : 'El curso ya no está activo.';
            } elseif ($action === 'cargar_malla_minedu') {
                $tabActiva = 'cursos';
                $nivelMalla = trim(is_string($_POST['nivel_malla'] ?? null) ? $_POST['nivel_malla'] : '');
                $alcanceGrado = trim(is_string($_POST['alcance_grado'] ?? null) ? $_POST['alcance_grado'] : 'todos');

                $mallasPorNivel = [
                    'Primaria' => [
                        'Matemática', 'Comunicación', 'Ciencia y Tecnología', 'Personal Social',
                        'Arte y Cultura', 'Educación Física', 'Educación Religiosa', 'Inglés', 'Tutoría y Orientación Educativa'
                    ],
                    'Secundaria' => [
                        'Matemática', 'Comunicación', 'Ciencia y Tecnología', 'Ciencias Sociales',
                        'Desarrollo Personal, Ciudadanía y Cívica', 'Arte y Cultura', 'Educación Física',
                        'Educación Religiosa', 'Inglés', 'Educación para el Trabajo', 'Tutoría y Orientación Educativa'
                    ],
                    'Inicial' => [
                        'Comunicación', 'Matemática', 'Personal Social', 'Ciencia y Tecnología', 'Psicomotricidad'
                    ]
                ];

                if (!isset($mallasPorNivel[$nivelMalla])) {
                    throw new InvalidArgumentException('Selecciona un nivel educativo válido para cargar la malla.');
                }

                $gradosObj = $gradosPorNivel[$nivelMalla] ?? [];
                if ($alcanceGrado !== 'todos') {
                    $gradosObj = array_values(array_filter($gradosObj, function ($g) use ($alcanceGrado) {
                        return ($g['nivel_nombre'] . ' - ' . $g['grado_nombre']) === $alcanceGrado || $g['grado_nombre'] === $alcanceGrado;
                    }));
                }

                if (empty($gradosObj)) {
                    throw new InvalidArgumentException('No se encontraron grados activos para el nivel seleccionado.');
                }

                $insertStmt = $pdo->prepare('INSERT INTO curso (nombre, grado, activo) VALUES (:nombre, :grado, TRUE)');
                $checkStmt = $pdo->prepare('SELECT 1 FROM curso WHERE LOWER(nombre) = LOWER(:nombre) AND LOWER(grado) = LOWER(:grado) AND activo = TRUE');

                $cursosInsertados = 0;
                foreach ($gradosObj as $g) {
                    $nombreGradoCompleto = $g['nivel_nombre'] . ' - ' . $g['grado_nombre'];
                    foreach ($mallasPorNivel[$nivelMalla] as $cursoNombre) {
                        $checkStmt->execute(['nombre' => $cursoNombre, 'grado' => $nombreGradoCompleto]);
                        if (!$checkStmt->fetchColumn()) {
                            $insertStmt->execute(['nombre' => $cursoNombre, 'grado' => $nombreGradoCompleto]);
                            $cursosInsertados++;
                        }
                    }
                }

                $mensaje = $cursosInsertados > 0
                    ? "¡Malla curricular oficial de {$nivelMalla} cargada con éxito! Se registraron {$cursosInsertados} asignaturas."
                    : "Las materias de la malla oficial de {$nivelMalla} ya se encontraban registradas previamente.";

            // 2. GESTIÓN DE PERIODOS ACADÉMICOS
            } elseif ($action === 'autogenerar_bimestres') {
                $tabActiva = 'periodos';
                $anioActual = (int) date('Y');
                if ($anioActual < 2026) {
                    $anioActual = 2026;
                }

                $bimestres = [
                    ['nombre' => 'I Bimestre', 'inicio' => "{$anioActual}-03-02", 'fin' => "{$anioActual}-05-15"],
                    ['nombre' => 'II Bimestre', 'inicio' => "{$anioActual}-05-18", 'fin' => "{$anioActual}-07-24"],
                    ['nombre' => 'III Bimestre', 'inicio' => "{$anioActual}-08-10", 'fin' => "{$anioActual}-10-16"],
                    ['nombre' => 'IV Bimestre', 'inicio' => "{$anioActual}-10-19", 'fin' => "{$anioActual}-12-22"],
                ];

                $checkStmt = $pdo->prepare('SELECT id FROM periodo_academico WHERE LOWER(nombre) = LOWER(:nombre) AND activo = TRUE');
                $insertStmt = $pdo->prepare('INSERT INTO periodo_academico (nombre, fecha_inicio, fecha_fin, activo) VALUES (:nombre, :inicio, :fin, TRUE)');
                $updateStmt = $pdo->prepare('UPDATE periodo_academico SET fecha_inicio = :inicio, fecha_fin = :fin WHERE id = :id');

                $creados = 0;
                $actualizados = 0;
                foreach ($bimestres as $b) {
                    $checkStmt->execute(['nombre' => $b['nombre']]);
                    $existingId = $checkStmt->fetchColumn();
                    if ($existingId) {
                        $updateStmt->execute(['inicio' => $b['inicio'], 'fin' => $b['fin'], 'id' => $existingId]);
                        $actualizados++;
                    } else {
                        $insertStmt->execute(['nombre' => $b['nombre'], 'inicio' => $b['inicio'], 'fin' => $b['fin']]);
                        $creados++;
                    }
                }

                $mensaje = "¡Se configuraron los 4 bimestres escolares del año {$anioActual} correctamente! ({$creados} nuevos, {$actualizados} actualizados).";
            } elseif ($action === 'crear_periodo' || $action === 'actualizar_periodo') {
                $tabActiva = 'periodos';
                $nombre = trim(is_string($_POST['nombre'] ?? null) ? $_POST['nombre'] : '');
                $fechaInicio = $_POST['fecha_inicio'] ?? null;
                $fechaFin = $_POST['fecha_fin'] ?? null;
                if ($nombre === '' || strlen($nombre) > 50 ||
                    !$validDate($fechaInicio) || !$validDate($fechaFin) || $fechaInicio > $fechaFin) {
                    throw new InvalidArgumentException('Ingresa un nombre y un rango de fechas válido (inicio menor o igual a fin).');
                }

                if ($action === 'crear_periodo') {
                    $stmt = $pdo->prepare(
                        'INSERT INTO periodo_academico (nombre, fecha_inicio, fecha_fin, activo)
                         VALUES (:nombre, :fecha_inicio, :fecha_fin, TRUE)'
                    );
                    $stmt->execute(['nombre' => $nombre, 'fecha_inicio' => $fechaInicio, 'fecha_fin' => $fechaFin]);
                    $mensaje = "Periodo académico '{$nombre}' registrado correctamente.";
                } else {
                    $id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                    if ($id === false) {
                        throw new InvalidArgumentException('El periodo seleccionado no es válido.');
                    }
                    $stmt = $pdo->prepare(
                        'UPDATE periodo_academico
                         SET nombre = :nombre, fecha_inicio = :fecha_inicio, fecha_fin = :fecha_fin
                         WHERE id = :id AND activo = TRUE'
                    );
                    $stmt->execute([
                        'nombre' => $nombre,
                        'fecha_inicio' => $fechaInicio,
                        'fecha_fin' => $fechaFin,
                        'id' => $id,
                    ]);
                    if ($stmt->rowCount() === 0) {
                        $exists = $pdo->prepare('SELECT 1 FROM periodo_academico WHERE id = :id AND activo = TRUE');
                        $exists->execute(['id' => $id]);
                        if (!$exists->fetchColumn()) {
                            throw new InvalidArgumentException('No se encontró el periodo académico activo.');
                        }
                    }
                    $mensaje = 'Periodo académico actualizado correctamente.';
                }
            } elseif ($action === 'desactivar_periodo') {
                $tabActiva = 'periodos';
                $id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($id === false) {
                    throw new InvalidArgumentException('El periodo seleccionado no es válido.');
                }
                $stmt = $pdo->prepare('UPDATE periodo_academico SET activo = FALSE WHERE id = :id AND activo = TRUE');
                $stmt->execute(['id' => $id]);
                $mensaje = $stmt->rowCount() > 0 ? 'Periodo académico desactivado correctamente.' : 'El periodo ya no está activo.';

            // 3. GESTIÓN DE SECCIONES DE GRADOS (AÑADIR Y ELIMINAR)
            } elseif ($action === 'agregar_seccion') {
                $tabActiva = 'secciones';
                $gradoId = filter_var($_POST['grado_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $seccionNombre = mb_strtoupper(trim(is_string($_POST['seccion_nombre'] ?? null) ? $_POST['seccion_nombre'] : ''), 'UTF-8');

                if ($gradoId === false) {
                    throw new InvalidArgumentException('Selecciona un grado escolar válido.');
                }
                if ($seccionNombre === '' || mb_strlen($seccionNombre) > 10) {
                    throw new InvalidArgumentException('Ingresa un nombre de sección válido (máximo 10 caracteres, ej: A, B, C, D, Única).');
                }

                // Obtener o insertar la sección en la tabla general de secciones
                $sStmt = $pdo->prepare('
                    INSERT INTO seccion (nombre, activo) 
                    VALUES (:nombre, TRUE) 
                    ON CONFLICT (nombre) 
                    DO UPDATE SET activo = TRUE 
                    RETURNING id
                ');
                $sStmt->execute(['nombre' => $seccionNombre]);
                $seccionId = $sStmt->fetchColumn();
                if (!$seccionId) {
                    $sGet = $pdo->prepare('SELECT id FROM seccion WHERE nombre = :nombre');
                    $sGet->execute(['nombre' => $seccionNombre]);
                    $seccionId = $sGet->fetchColumn();
                }

                // Vincular la sección al grado específico
                $gsStmt = $pdo->prepare('
                    INSERT INTO grado_seccion (grado_id, seccion_id, activo) 
                    VALUES (:grado_id, :seccion_id, TRUE) 
                    ON CONFLICT (grado_id, seccion_id) 
                    DO UPDATE SET activo = TRUE
                ');
                $gsStmt->execute(['grado_id' => $gradoId, 'seccion_id' => $seccionId]);

                $infoGrado = $pdo->prepare('SELECT g.nombre AS grado, n.nombre AS nivel FROM grado g JOIN nivel n ON n.id = g.nivel_id WHERE g.id = :id');
                $infoGrado->execute(['id' => $gradoId]);
                $ig = $infoGrado->fetch();
                $nombreAula = $ig ? "{$ig['nivel']} {$ig['grado']}" : "grado escolar";

                registrar_auditoria('agregar_seccion', "Añadió la sección '{$seccionNombre}' al grado {$nombreAula}.");
                $mensaje = "¡Sección '{$seccionNombre}' añadida con éxito a {$nombreAula}!";
            } elseif ($action === 'eliminar_seccion') {
                $tabActiva = 'secciones';
                $gsId = filter_var($_POST['grado_seccion_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($gsId === false) {
                    throw new InvalidArgumentException('La sección seleccionada no es válida.');
                }

                // Verificar si tiene estudiantes matriculados
                $chkMatriculas = $pdo->prepare('
                    SELECT COUNT(*) 
                    FROM matricula 
                    WHERE grado_seccion_id = :id AND estado = \'Activa\'
                ');
                $chkMatriculas->execute(['id' => $gsId]);
                $cantAlumnos = (int) $chkMatriculas->fetchColumn();
                if ($cantAlumnos > 0) {
                    throw new InvalidArgumentException("No se puede eliminar esta sección porque tiene {$cantAlumnos} estudiante(s) matriculado(s). Para darla de baja, primero debes reasignar o trasladar a los estudiantes.");
                }

                $infoStmt = $pdo->prepare('
                    SELECT g.nombre AS grado, n.nombre AS nivel, s.nombre AS seccion 
                    FROM grado_seccion gs 
                    JOIN grado g ON g.id = gs.grado_id 
                    JOIN nivel n ON n.id = g.nivel_id 
                    JOIN seccion s ON s.id = gs.seccion_id 
                    WHERE gs.id = :id
                ');
                $infoStmt->execute(['id' => $gsId]);
                $inf = $infoStmt->fetch();

                $delStmt = $pdo->prepare('DELETE FROM grado_seccion WHERE id = :id');
                $delStmt->execute(['id' => $gsId]);

                $nombreSec = $inf ? "Sección '{$inf['seccion']}' de {$inf['nivel']} {$inf['grado']}" : "Sección #{$gsId}";
                registrar_auditoria('eliminar_seccion', "Eliminó la {$nombreSec} (0 alumnos matriculados).");
                $mensaje = "¡{$nombreSec} eliminada exitosamente del grado!";
            } elseif ($action === 'limpiar_secciones_vacias') {
                $tabActiva = 'secciones';
                $stmtVacias = $pdo->query('
                    SELECT gs.id, g.nombre AS grado, n.nombre AS nivel, s.nombre AS seccion
                    FROM grado_seccion gs
                    JOIN grado g ON g.id = gs.grado_id
                    JOIN nivel n ON n.id = g.nivel_id
                    JOIN seccion s ON s.id = gs.seccion_id
                    WHERE gs.activo = TRUE 
                      AND NOT EXISTS (
                          SELECT 1 FROM matricula m WHERE m.grado_seccion_id = gs.id
                      )
                ')->fetchAll();

                $totalEliminadas = 0;
                $delStmt = $pdo->prepare('DELETE FROM grado_seccion WHERE id = :id');
                foreach ($stmtVacias as $v) {
                    // Mantener la sección A por defecto para que el grado no quede vacío
                    if ($v['seccion'] !== 'A') {
                        $delStmt->execute(['id' => $v['id']]);
                        $totalEliminadas++;
                    }
                }

                registrar_auditoria('limpiar_secciones', "Limpieza automática: eliminó {$totalEliminadas} secciones vacías sin alumnos.");
                $mensaje = "¡Limpieza completada! Se eliminaron {$totalEliminadas} secciones que no tenían ningún estudiante matriculado.";
            } else {
                throw new InvalidArgumentException('La acción solicitada no está permitida.');
            }
        } catch (InvalidArgumentException $exception) {
            $error = $exception->getMessage();
        } catch (PDOException $exception) {
            error_log('Error al gestionar cursos, periodos o secciones: ' . $exception->getMessage());
            $error = 'No se pudo guardar el cambio. Verifica los datos e inténtalo nuevamente.';
        }
    }
}

$cursos = $pdo->query('SELECT id, nombre, grado FROM curso WHERE activo = TRUE ORDER BY grado, nombre')->fetchAll();
$periodos = $pdo->query(
    'SELECT id, nombre, fecha_inicio, fecha_fin
     FROM periodo_academico
     WHERE activo = TRUE
     ORDER BY fecha_inicio ASC, nombre'
)->fetchAll();

// Cargar estructura completa de Grados y Secciones con conteo de alumnos
$seccionesPorGradoRaw = $pdo->query('
    SELECT gs.id AS grado_seccion_id, gs.grado_id, gs.seccion_id, gs.activo,
           g.nombre AS grado_nombre, g.numero AS grado_numero,
           n.id AS nivel_id, n.nombre AS nivel_nombre,
           s.nombre AS seccion_nombre,
           (SELECT COUNT(*) FROM matricula m WHERE m.grado_seccion_id = gs.id AND m.estado = \'Activa\') AS matriculados
    FROM grado_seccion gs
    JOIN grado g ON g.id = gs.grado_id
    JOIN nivel n ON n.id = g.nivel_id
    JOIN seccion s ON s.id = gs.seccion_id
    WHERE gs.activo = TRUE
    ORDER BY n.id, g.numero, s.nombre
')->fetchAll();

$arbolSecciones = [];
$totalSeccionesActivas = count($seccionesPorGradoRaw);
$totalSeccionesVacias = 0;
foreach ($seccionesPorGradoRaw as $sg) {
    $nivel = $sg['nivel_nombre'];
    $grado = $sg['grado_nombre'];
    $arbolSecciones[$nivel][$grado]['grado_id'] = $sg['grado_id'];
    $arbolSecciones[$nivel][$grado]['grado_numero'] = $sg['grado_numero'];
    $arbolSecciones[$nivel][$grado]['secciones'][] = $sg;
    if ((int)$sg['matriculados'] === 0 && $sg['seccion_nombre'] !== 'A') {
        $totalSeccionesVacias++;
    }
}

// Conteo de cursos por nivel
$conteoCursos = [
    'todos' => count($cursos),
    'Primaria' => 0,
    'Secundaria' => 0,
    'Inicial' => 0,
    'otros' => 0,
];
foreach ($cursos as $c) {
    $gradoLow = strtolower($c['grado']);
    if (str_contains($gradoLow, 'primaria')) {
        $conteoCursos['Primaria']++;
    } elseif (str_contains($gradoLow, 'secundaria')) {
        $conteoCursos['Secundaria']++;
    } elseif (str_contains($gradoLow, 'inicial')) {
        $conteoCursos['Inicial']++;
    } else {
        $conteoCursos['otros']++;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Estructura Escolar: Cursos, Periodos y Secciones</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../assets/css/app.css">
    <link rel="stylesheet" href="../assets/css/cursos_periodos.css">
</head>
<body>
<?php render_app_header(panel_home_path(), 'logout.php'); ?>

<main class="container-fluid px-3 px-md-4 px-xl-5 py-4">
    <!-- Encabezado Principal -->
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1 small">
                    <li class="breadcrumb-item"><a href="<?= htmlspecialchars(panel_home_path()) ?>" class="text-decoration-none text-muted"><i class="bi bi-house-door"></i> Inicio</a></li>
                    <li class="breadcrumb-item active text-primary fw-semibold" aria-current="page">Estructura Escolar</li>
                </ol>
            </nav>
            <h1 class="h3 fw-bold mb-1 text-dark d-flex align-items-center gap-2">
                Estructura Escolar: Cursos, Periodos y Secciones
                <span class="badge bg-primary-subtle text-primary border border-primary-subtle fs-6 fw-semibold">Gestión 2026</span>
            </h1>
            <p class="text-muted mb-0" style="font-size: 0.96rem;">Configura la estructura curricular de materias, los periodos bimestrales y las secciones (A, B, C...) por cada grado escolar.</p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <a href="calificaciones.php" class="btn btn-primary d-inline-flex align-items-center gap-2 px-3 py-2 fw-semibold shadow-sm" style="border-radius: 10px; font-size: 0.95rem;">
                <i class="bi bi-pencil-square"></i> Subir Calificaciones &rarr;
            </a>
            <a href="<?= htmlspecialchars(panel_home_path()) ?>" class="btn btn-outline-secondary d-inline-flex align-items-center gap-2 px-3 py-2" style="border-radius: 10px; font-size: 0.95rem;">
                <i class="bi bi-arrow-left"></i> Volver al panel
            </a>
        </div>
    </div>

    <!-- Guía Visual de Flujo de Trabajo -->
    <div class="row g-3 mb-4">
        <div class="col-12 col-md-4">
            <div class="step-pill h-100 d-flex align-items-center gap-3">
                <span class="badge bg-primary text-white rounded-circle p-2 fs-6 d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">1</span>
                <div>
                    <div class="fw-bold text-dark" style="font-size: 0.95rem;">1. Bimestres / Periodos</div>
                    <div class="text-muted small">Crea los 4 bimestres con 1 clic para calendarizar notas.</div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="step-pill h-100 d-flex align-items-center gap-3">
                <span class="badge bg-info text-white rounded-circle p-2 fs-6 d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">2</span>
                <div>
                    <div class="fw-bold text-dark" style="font-size: 0.95rem;">2. Cursos y Secciones</div>
                    <div class="text-muted small">Carga la malla oficial y configura qué secciones existen por aula.</div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <a href="calificaciones.php" class="step-pill h-100 d-flex align-items-center gap-3 text-decoration-none border-success-subtle bg-success-subtle bg-opacity-25">
                <span class="badge bg-success text-white rounded-circle p-2 fs-6 d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">3</span>
                <div>
                    <div class="fw-bold text-success" style="font-size: 0.95rem;">3. Calificar Alumnos &rarr;</div>
                    <div class="text-muted small">Coloca notas literales (C, B, A, AD) en cada salón.</div>
                </div>
            </a>
        </div>
    </div>

    <!-- Alertas -->
    <?php if ($mensaje !== ''): ?>
        <div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2 mb-4" role="alert" style="border-radius: 12px;">
            <i class="bi bi-check-circle-fill fs-5 text-success"></i>
            <div><?= htmlspecialchars($mensaje) ?></div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
        </div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="alert alert-danger alert-dismissible fade show d-flex align-items-center gap-2 mb-4" role="alert" style="border-radius: 12px;">
            <i class="bi bi-exclamation-triangle-fill fs-5 text-danger"></i>
            <div><?= htmlspecialchars($error) ?></div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
        </div>
    <?php endif; ?>

    <!-- PESTAÑAS PRINCIPALES DEL MÓDULO -->
    <ul class="nav nav-pills nav-fill nav-main-tabs mb-4 p-1.5 bg-white border rounded-3 shadow-sm" id="moduleNavTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link <?= $tabActiva === 'cursos' ? 'active' : '' ?>" id="tab-btn-cursos" data-bs-toggle="pill" data-bs-target="#pane-cursos" type="button" role="tab">
                <i class="bi bi-book-half me-1"></i> 1. Asignaturas y Cursos
                <span class="badge <?= $tabActiva === 'cursos' ? 'bg-light text-dark' : 'bg-secondary' ?> ms-1"><?= $conteoCursos['todos'] ?></span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link <?= $tabActiva === 'periodos' ? 'active' : '' ?>" id="tab-btn-periodos" data-bs-toggle="pill" data-bs-target="#pane-periodos" type="button" role="tab">
                <i class="bi bi-calendar3 me-1"></i> 2. Periodos Académicos
                <span class="badge <?= $tabActiva === 'periodos' ? 'bg-light text-dark' : 'bg-secondary' ?> ms-1"><?= count($periodos) ?></span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link <?= $tabActiva === 'secciones' ? 'active' : '' ?>" id="tab-btn-secciones" data-bs-toggle="pill" data-bs-target="#pane-secciones" type="button" role="tab">
                <i class="bi bi-diagram-3-fill me-1"></i> 3. Secciones de Grados
                <span class="badge bg-primary ms-1"><?= $totalSeccionesActivas ?> activas</span>
            </button>
        </li>
    </ul>

    <div class="tab-content" id="moduleNavTabsContent">
        <!-- PESTAÑA 1: ASIGNATURAS Y CURSOS -->
        <div class="tab-pane fade <?= $tabActiva === 'cursos' ? 'show active' : '' ?>" id="pane-cursos" role="tabpanel">
            <div class="form-card p-4">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                    <div>
                        <h2 class="h5 fw-bold text-dark mb-0 d-flex align-items-center gap-2" style="font-size: 1.2rem;">
                            <i class="bi bi-book-half text-primary"></i> Asignaturas y Cursos
                        </h2>
                        <span class="text-muted small">Materias escolares asignadas a cada grado académico.</span>
                    </div>
                    <button type="button" class="btn btn-outline-primary btn-sm d-inline-flex align-items-center gap-2 fw-semibold px-3 py-2" style="border-radius: 8px;" data-bs-toggle="modal" data-bs-target="#modalMallaMinedu">
                        <i class="bi bi-magic text-primary"></i> Cargar Malla Oficial MINEDU
                    </button>
                </div>

                <!-- Formulario Amigable de Registro con Desplegable -->
                <div class="p-3 bg-light rounded-3 border mb-4">
                    <div class="fw-semibold text-dark small mb-2 d-flex align-items-center gap-1">
                        <i class="bi bi-plus-circle-fill text-primary"></i> Registrar Nueva Asignatura:
                    </div>
                    <form method="POST" class="row g-2">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="tab" value="cursos">
                        <input type="hidden" name="action" value="crear_curso">

                        <div class="col-12 col-md-5">
                            <label class="form-label small fw-semibold text-secondary mb-1" for="curso-nombre">Nombre de la Asignatura</label>
                            <input class="form-control" id="curso-nombre" name="nombre" list="cursosSugeridos" placeholder="Ej. Matemática, Comunicación..." maxlength="100" autocomplete="off" required>
                        </div>

                        <div class="col-12 col-md-4">
                            <label class="form-label small fw-semibold text-secondary mb-1" for="curso-grado">Grado Escolar</label>
                            <select class="form-select" id="curso-grado" name="grado" required>
                                <option value="" selected disabled>-- Selecciona un grado --</option>
                                <?php foreach ($gradosPorNivel as $nivelNombre => $gradosLista): ?>
                                    <optgroup label="Nivel <?= htmlspecialchars($nivelNombre) ?>">
                                        <?php foreach ($gradosLista as $g): ?>
                                            <?php $valGrado = $nivelNombre . ' - ' . $g['grado_nombre']; ?>
                                            <option value="<?= htmlspecialchars($valGrado) ?>">
                                                <?= htmlspecialchars($nivelNombre . ' - ' . $g['grado_nombre']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12 col-md-3 d-flex align-items-end">
                            <button class="btn btn-primary w-100 d-inline-flex align-items-center justify-content-center gap-2 fw-semibold" type="submit">
                                <i class="bi bi-plus-lg"></i> Registrar
                            </button>
                        </div>

                        <div class="col-12 pt-1">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="aplicarNivelCheck" name="aplicar_nivel" value="1">
                                <label class="form-check-label small text-muted" for="aplicarNivelCheck">
                                    <strong>Asignar a todo el nivel</strong> (Crea este curso automáticamente en todos los grados del nivel seleccionado).
                                </label>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Barra de Búsqueda y Pestañas de Filtrado por Nivel -->
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                    <ul class="nav nav-pills" id="pillsNivel" role="tablist">
                        <li class="nav-item">
                            <button class="nav-link active filter-tab-btn" data-nivel="todos" type="button">
                                Todos <span class="badge bg-secondary ms-1"><?= $conteoCursos['todos'] ?></span>
                            </button>
                        </li>
                        <li class="nav-item">
                            <button class="nav-link filter-tab-btn" data-nivel="Primaria" type="button">
                                Primaria <span class="badge bg-primary ms-1"><?= $conteoCursos['Primaria'] ?></span>
                            </button>
                        </li>
                        <li class="nav-item">
                            <button class="nav-link filter-tab-btn" data-nivel="Secundaria" type="button">
                                Secundaria <span class="badge bg-info text-dark ms-1"><?= $conteoCursos['Secundaria'] ?></span>
                            </button>
                        </li>
                        <li class="nav-item">
                            <button class="nav-link filter-tab-btn" data-nivel="Inicial" type="button">
                                Inicial <span class="badge bg-success ms-1"><?= $conteoCursos['Inicial'] ?></span>
                            </button>
                        </li>
                    </ul>

                    <div class="search-wrap" style="min-width: 200px;">
                        <input type="search" id="busquedaCurso" class="form-control form-control-sm" placeholder="🔍 Filtrar cursos..." autocomplete="off">
                    </div>
                </div>

                <!-- Tabla de Cursos Activos -->
                <div class="table-responsive rounded-3 border">
                    <table class="table table-hover align-middle mb-0" id="tablaCursos">
                        <thead>
                            <tr>
                                <th style="width: 40%;">Curso / Asignatura</th>
                                <th style="width: 35%;">Grado y Nivel</th>
                                <th style="width: 25%; text-align: right;">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!$cursos): ?>
                            <tr id="filaSinCursos">
                                <td colspan="3" class="text-center py-5 text-muted">
                                    <i class="bi bi-journal-x fs-1 d-block mb-2 text-secondary opacity-50"></i>
                                    <strong>No hay cursos registrados todavía.</strong>
                                    <p class="small text-muted mb-3">Puedes cargar la malla sugerida del MINEDU o agregar materias una a una.</p>
                                    <button type="button" class="btn btn-outline-primary btn-sm d-inline-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#modalMallaMinedu">
                                        <i class="bi bi-magic"></i> Cargar Malla Curricular MINEDU
                                    </button>
                                </td>
                            </tr>
                        <?php else: foreach ($cursos as $curso): ?>
                            <?php
                            $gradoStr = $curso['grado'];
                            $nivelDetect = 'otros';
                            if (stripos($gradoStr, 'primaria') !== false) {
                                $nivelDetect = 'Primaria';
                            } elseif (stripos($gradoStr, 'secundaria') !== false) {
                                $nivelDetect = 'Secundaria';
                            } elseif (stripos($gradoStr, 'inicial') !== false) {
                                $nivelDetect = 'Inicial';
                            }
                            ?>
                            <tr class="curso-fila" data-nivel="<?= htmlspecialchars($nivelDetect) ?>" data-texto="<?= htmlspecialchars(strtolower($curso['nombre'] . ' ' . $curso['grado'])) ?>">
                                <td colspan="3" class="p-2">
                                    <form method="POST" class="row g-2 align-items-center m-0">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="tab" value="cursos">
                                        <input type="hidden" name="id" value="<?= (int) $curso['id'] ?>">

                                        <div class="col-md-5">
                                            <input class="form-control form-control-sm fw-semibold text-dark" aria-label="Nombre del curso" name="nombre" maxlength="100" value="<?= htmlspecialchars($curso['nombre']) ?>" required>
                                        </div>
                                        <div class="col-md-4">
                                            <select class="form-select form-select-sm" name="grado" aria-label="Grado del curso" required>
                                                <?php foreach ($gradosPorNivel as $nivelNombre => $gradosLista): ?>
                                                    <optgroup label="Nivel <?= htmlspecialchars($nivelNombre) ?>">
                                                        <?php foreach ($gradosLista as $g): ?>
                                                            <?php $valGrado = $nivelNombre . ' - ' . $g['grado_nombre']; ?>
                                                            <option value="<?= htmlspecialchars($valGrado) ?>" <?= $valGrado === $curso['grado'] || $g['grado_nombre'] === $curso['grado'] ? 'selected' : '' ?>>
                                                                <?= htmlspecialchars($valGrado) ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </optgroup>
                                                <?php endforeach; ?>
                                                <?php if (!in_array($curso['grado'], array_column($gradosDb, 'grado_nombre'), true)): ?>
                                                    <option value="<?= htmlspecialchars($curso['grado']) ?>" selected><?= htmlspecialchars($curso['grado']) ?></option>
                                                <?php endif; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-3 d-flex justify-content-end gap-2">
                                            <button class="btn btn-sm btn-outline-primary d-inline-flex align-items-center gap-1" name="action" value="actualizar_curso" type="submit" title="Guardar cambios">
                                                <i class="bi bi-check-lg"></i> Guardar
                                            </button>
                                            <button class="btn btn-sm btn-outline-danger d-inline-flex align-items-center gap-1" name="action" value="desactivar_curso" type="submit" formnovalidate onclick="return confirm('¿Desactivar este curso escolar?')" title="Desactivar">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- PESTAÑA 2: PERIODOS ACADÉMICOS -->
        <div class="tab-pane fade <?= $tabActiva === 'periodos' ? 'show active' : '' ?>" id="pane-periodos" role="tabpanel">
            <div class="form-card p-4">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                    <div>
                        <h2 class="h5 fw-bold text-dark mb-0 d-flex align-items-center gap-2" style="font-size: 1.2rem;">
                            <i class="bi bi-calendar3 text-primary"></i> Periodos Académicos
                        </h2>
                        <span class="text-muted small">Bimestres o trimestres de evaluación escolar.</span>
                    </div>
                    <form method="POST" onsubmit="return confirm('¿Deseas autogenerar los 4 Bimestres del año escolar 2026? Se configurarán con las fechas oficiales sugeridas.')">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="tab" value="periodos">
                        <input type="hidden" name="action" value="autogenerar_bimestres">
                        <button type="submit" class="btn btn-outline-success btn-sm d-inline-flex align-items-center gap-1 fw-semibold px-3 py-2" style="border-radius: 8px;">
                            <i class="bi bi-lightning-charge-fill text-success"></i> Autogenerar 4 Bimestres 2026
                        </button>
                    </form>
                </div>

                <!-- Formulario de Periodo -->
                <div class="p-3 bg-light rounded-3 border mb-4">
                    <div class="fw-semibold text-dark small mb-2 d-flex align-items-center gap-1">
                        <i class="bi bi-plus-circle-fill text-primary"></i> Registrar Nuevo Periodo:
                    </div>
                    <form method="POST" class="row g-2">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="tab" value="periodos">
                        <input type="hidden" name="action" value="crear_periodo">

                        <div class="col-12 col-md-4">
                            <label class="form-label small fw-semibold text-secondary mb-1" for="periodo-nombre">Nombre del Periodo</label>
                            <input class="form-control" id="periodo-nombre" name="nombre" list="periodosSugeridos" placeholder="Ej. I Bimestre, II Bimestre..." maxlength="50" autocomplete="off" required>
                        </div>
                        <div class="col-12 col-sm-4">
                            <label class="form-label small fw-semibold text-secondary mb-1" for="fecha-inicio">Fecha de Inicio</label>
                            <input class="form-control" id="fecha-inicio" type="date" name="fecha_inicio" required>
                        </div>
                        <div class="col-12 col-sm-4">
                            <label class="form-label small fw-semibold text-secondary mb-1" for="fecha-fin">Fecha de Fin</label>
                            <input class="form-control" id="fecha-fin" type="date" name="fecha_fin" required>
                        </div>
                        <div class="col-12 pt-1 text-end">
                            <button class="btn btn-primary d-inline-flex align-items-center justify-content-center gap-2 fw-semibold px-4" type="submit">
                                <i class="bi bi-plus-lg"></i> Registrar Periodo
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Tabla de Periodos -->
                <div class="table-responsive rounded-3 border">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th style="width: 35%;">Periodo</th>
                                <th style="width: 25%;">Inicio</th>
                                <th style="width: 25%;">Fin</th>
                                <th style="width: 15%; text-align: right;">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!$periodos): ?>
                            <tr>
                                <td colspan="4" class="text-center py-5 text-muted">
                                    <i class="bi bi-calendar-x fs-1 d-block mb-2 text-secondary opacity-50"></i>
                                    <strong>No hay periodos configurados.</strong>
                                    <p class="small text-muted mb-3">Puedes generar automáticamente los 4 bimestres escolares con un solo clic.</p>
                                </td>
                            </tr>
                        <?php else: foreach ($periodos as $periodo): ?>
                            <tr>
                                <td colspan="4" class="p-2">
                                    <form method="POST" class="row g-2 align-items-center m-0">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="tab" value="periodos">
                                        <input type="hidden" name="id" value="<?= (int) $periodo['id'] ?>">

                                        <div class="col-12 col-md-4">
                                            <input class="form-control form-control-sm fw-semibold text-dark" aria-label="Nombre del periodo" name="nombre" maxlength="50" value="<?= htmlspecialchars($periodo['nombre']) ?>" required>
                                        </div>
                                        <div class="col-6 col-md-3">
                                            <input class="form-control form-control-sm" aria-label="Fecha de inicio" type="date" name="fecha_inicio" value="<?= htmlspecialchars($periodo['fecha_inicio']) ?>" required>
                                        </div>
                                        <div class="col-6 col-md-3">
                                            <input class="form-control form-control-sm" aria-label="Fecha de fin" type="date" name="fecha_fin" value="<?= htmlspecialchars($periodo['fecha_fin']) ?>" required>
                                        </div>
                                        <div class="col-12 col-md-2 d-flex justify-content-end gap-1">
                                            <button class="btn btn-sm btn-outline-primary" name="action" value="actualizar_periodo" type="submit" title="Guardar cambios">
                                                <i class="bi bi-check-lg"></i>
                                            </button>
                                            <button class="btn btn-sm btn-outline-danger" name="action" value="desactivar_periodo" type="submit" formnovalidate onclick="return confirm('¿Desactivar este periodo académico?')" title="Desactivar">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- PESTAÑA 3: SECCIONES DE GRADOS (AÑADIR Y ELIMINAR) -->
        <div class="tab-pane fade <?= $tabActiva === 'secciones' ? 'show active' : '' ?>" id="pane-secciones" role="tabpanel">
            <div class="form-card p-4">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
                    <div>
                        <h2 class="h5 fw-bold text-dark mb-0 d-flex align-items-center gap-2" style="font-size: 1.25rem;">
                            <i class="bi bi-diagram-3-fill text-primary"></i> Estructura de Secciones por Grado
                        </h2>
                        <span class="text-muted small">Configura qué secciones (A, B, C, D, Única...) están habilitadas en cada grado escolar.</span>
                    </div>

                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <?php if ($totalSeccionesVacias > 0): ?>
                            <!-- Botón de limpieza de secciones vacías -->
                            <form method="POST" onsubmit="return confirm('¿Deseas eliminar las <?= $totalSeccionesVacias ?> secciones vacías (B o C con 0 alumnos)? Las secciones que ya tienen alumnos NO serán afectadas.')">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="tab" value="secciones">
                                <input type="hidden" name="action" value="limpiar_secciones_vacias">
                                <button type="submit" class="btn btn-outline-danger btn-sm d-inline-flex align-items-center gap-1.5 fw-semibold px-3 py-2" style="border-radius: 8px;">
                                    <i class="bi bi-trash3-fill"></i> Limpiar <?= $totalSeccionesVacias ?> Secciones Vacías (0 alumnos)
                                </button>
                            </form>
                        <?php endif; ?>

                        <button type="button" class="btn btn-primary btn-sm d-inline-flex align-items-center gap-1.5 fw-semibold px-3 py-2 shadow-sm" style="border-radius: 8px;" data-bs-toggle="modal" data-bs-target="#modalNuevaSeccion">
                            <i class="bi bi-plus-circle-fill"></i> Añadir Sección a un Grado
                        </button>
                    </div>
                </div>

                <!-- Resumen Rápido -->
                <div class="alert alert-light border d-flex align-items-center gap-2 mb-4 py-2 px-3 text-muted small" style="border-radius: 10px;">
                    <i class="bi bi-shield-check text-success fs-5"></i>
                    <div>
                        <strong>Protección de Matrículas:</strong> Solo se pueden eliminar las secciones que tienen <strong>0 estudiantes matriculados</strong>. Las secciones con alumnos están protegidas automáticamente para no alterar los registros.
                    </div>
                </div>

                <!-- Visualización por Niveles Educativos -->
                <?php foreach ($arbolSecciones as $nivelNombre => $gradosLista): ?>
                    <div class="mb-4">
                        <div class="d-flex align-items-center gap-2 mb-3 pb-2 border-bottom">
                            <?php 
                            $nivelIcon = match ($nivelNombre) {
                                'Inicial' => 'bi-balloon-heart text-success',
                                'Primaria' => 'bi-backpack text-primary',
                                'Secundaria' => 'bi-mortarboard-fill text-info',
                                default => 'bi-building text-secondary'
                            };
                            ?>
                            <i class="bi <?= $nivelIcon ?> fs-4"></i>
                            <h3 class="h6 fw-bold text-dark mb-0 text-uppercase" style="letter-spacing: 0.04em;">Nivel <?= htmlspecialchars($nivelNombre) ?></h3>
                            <span class="badge bg-secondary-subtle text-secondary border fs-6 ms-2"><?= count($gradosLista) ?> grados</span>
                        </div>

                        <div class="row g-3">
                            <?php foreach ($gradosLista as $gradoNombre => $gradoInfo): ?>
                                <div class="col-12 col-md-6 col-xl-4">
                                    <div class="grado-box-card d-flex flex-column justify-content-between">
                                        <div>
                                            <div class="d-flex justify-content-between align-items-center mb-2.5">
                                                <h4 class="h6 fw-bold text-dark mb-0 d-flex align-items-center gap-1.5">
                                                    <i class="bi bi-chevron-right text-primary small"></i>
                                                    <?= htmlspecialchars($gradoNombre) ?>
                                                </h4>
                                                <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2 fw-semibold" style="font-size: 0.8rem; border-radius: 6px;" onclick="abrirModalAgregarSeccion(<?= (int)$gradoInfo['grado_id'] ?>, '<?= htmlspecialchars($nivelNombre . ' - ' . $gradoNombre, ENT_QUOTES) ?>')">
                                                    <i class="bi bi-plus-lg"></i> Sección
                                                </button>
                                            </div>

                                            <div class="d-flex flex-wrap gap-2 mb-2">
                                                <?php foreach ($gradoInfo['secciones'] as $sec): ?>
                                                    <?php 
                                                    $matriculados = (int) $sec['matriculados'];
                                                    $tieneAlumnos = $matriculados > 0;
                                                    ?>
                                                    <div class="seccion-pill-badge <?= $tieneAlumnos ? 'has-students' : 'empty-sec' ?>">
                                                        <span>Sección <strong>"<?= htmlspecialchars($sec['seccion_nombre']) ?>"</strong></span>
                                                        
                                                        <?php if ($tieneAlumnos): ?>
                                                            <span class="badge bg-success text-white rounded-pill px-2 py-0.5" style="font-size: 0.76rem;" title="Alumnos matriculados en esta aula">
                                                                <i class="bi bi-people-fill me-0.5"></i><?= $matriculados ?>
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="badge bg-secondary-subtle text-muted rounded-pill px-1.5 py-0.5" style="font-size: 0.74rem;">0 alumnos</span>
                                                            <!-- Botón de eliminación directa si tiene 0 alumnos -->
                                                            <form method="POST" class="d-inline m-0" onsubmit="return confirm('¿Eliminar la Sección \'<?= htmlspecialchars($sec['seccion_nombre'], ENT_QUOTES) ?>\' de <?= htmlspecialchars($gradoNombre, ENT_QUOTES) ?>? Dejará de aparecer en las listas de matrículas y calificaciones.')">
                                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                                                <input type="hidden" name="tab" value="secciones">
                                                                <input type="hidden" name="action" value="eliminar_seccion">
                                                                <input type="hidden" name="grado_seccion_id" value="<?= (int)$sec['grado_seccion_id'] ?>">
                                                                <button type="submit" class="btn btn-link text-danger p-0 ms-1" style="font-size: 0.85rem;" title="Eliminar sección vacía">
                                                                    <i class="bi bi-x-circle-fill"></i>
                                                                </button>
                                                            </form>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>

                                        <div class="pt-2 border-top text-muted d-flex justify-content-between align-items-center" style="font-size: 0.82rem;">
                                            <span>Total secciones: <?= count($gradoInfo['secciones']) ?></span>
                                            <span>
                                                <?php 
                                                $sumaMat = array_sum(array_column($gradoInfo['secciones'], 'matriculados'));
                                                ?>
                                                <strong><?= $sumaMat ?></strong> alumnos en grado
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</main>

<!-- MODAL: AÑADIR NUEVA SECCIÓN A UN GRADO -->
<div class="modal fade" id="modalNuevaSeccion" tabindex="-1" aria-labelledby="modalNuevaSeccionLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius: 16px; border: 0; box-shadow: 0 10px 30px rgba(0,0,0,0.15);">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="tab" value="secciones">
                <input type="hidden" name="action" value="agregar_seccion">

                <div class="modal-header border-0 pb-0 pt-4 px-4">
                    <div class="d-flex align-items-center gap-3">
                        <div class="p-3 rounded-circle bg-primary-subtle text-primary d-flex align-items-center justify-content-center" style="width: 48px; height: 48px;">
                            <i class="bi bi-diagram-3 fs-4"></i>
                        </div>
                        <div>
                            <h5 class="modal-title fw-bold text-dark mb-0" id="modalNuevaSeccionLabel">Añadir Sección a un Grado</h5>
                            <span class="text-muted small">Habilita una nueva aula para matrículas y calificaciones.</span>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>

                <div class="modal-body p-4">
                    <!-- Selector de Grado -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold small text-dark mb-1" for="modalGradoSelect">Grado Escolar <span class="text-danger">*</span></label>
                        <select class="form-select" id="modalGradoSelect" name="grado_id" required>
                            <option value="" selected disabled>-- Selecciona el grado --</option>
                            <?php foreach ($gradosPorNivel as $nivelNombre => $gradosLista): ?>
                                <optgroup label="Nivel <?= htmlspecialchars($nivelNombre) ?>">
                                    <?php foreach ($gradosLista as $g): ?>
                                        <option value="<?= (int)$g['id'] ?>">
                                            <?= htmlspecialchars($nivelNombre . ' - ' . $g['grado_nombre']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Nombre de la Sección con sugerencias rápidas -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold small text-dark mb-1" for="modalSeccionNombre">Letra o Nombre de la Sección <span class="text-danger">*</span></label>
                        <input type="text" class="form-control text-uppercase font-monospace fw-bold" id="modalSeccionNombre" name="seccion_nombre" placeholder="Ej. A, B, C, D, Única..." maxlength="10" required>
                        <div class="d-flex gap-1.5 mt-2 flex-wrap">
                            <span class="small text-muted me-1">Sugerencias rápidas:</span>
                            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" onclick="setSeccionValor('A')">A</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" onclick="setSeccionValor('B')">B</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" onclick="setSeccionValor('C')">C</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" onclick="setSeccionValor('D')">D</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" onclick="setSeccionValor('E')">E</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" onclick="setSeccionValor('Única')">Única</button>
                        </div>
                    </div>
                </div>

                <div class="modal-footer border-0 pt-0 pb-4 px-4">
                    <button type="button" class="btn btn-outline-secondary px-3" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary px-4 fw-semibold d-inline-flex align-items-center gap-2">
                        <i class="bi bi-check2-circle"></i> Guardar Sección
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- DATALISTS PARA SUGERENCIAS RÁPIDAS -->
<datalist id="cursosSugeridos">
    <option value="Matemática">
    <option value="Comunicación">
    <option value="Ciencia y Tecnología">
    <option value="Personal Social">
    <option value="Ciencias Sociales">
    <option value="Desarrollo Personal, Ciudadanía y Cívica">
    <option value="Educación Física">
    <option value="Arte y Cultura">
    <option value="Inglés">
    <option value="Educación Religiosa">
    <option value="Educación para el Trabajo">
    <option value="Tutoría y Orientación Educativa">
    <option value="Psicomotricidad">
</datalist>

<datalist id="periodosSugeridos">
    <option value="I Bimestre">
    <option value="II Bimestre">
    <option value="III Bimestre">
    <option value="IV Bimestre">
    <option value="I Trimestre">
    <option value="II Trimestre">
    <option value="III Trimestre">
    <option value="Periodo Vacacional / Recuperación">
</datalist>

<!-- MODAL PARA CARGAR MALLA CURRICULAR OFICIAL DEL MINEDU -->
<div class="modal fade" id="modalMallaMinedu" tabindex="-1" aria-labelledby="modalMallaMineduLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius: 16px; border: 0; box-shadow: 0 10px 30px rgba(0,0,0,0.15);">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="tab" value="cursos">
                <input type="hidden" name="action" value="cargar_malla_minedu">

                <div class="modal-header border-0 pb-0 pt-4 px-4">
                    <div class="d-flex align-items-center gap-3">
                        <div class="p-3 rounded-circle bg-primary-subtle text-primary d-flex align-items-center justify-content-center" style="width: 48px; height: 48px;">
                            <i class="bi bi-magic fs-4"></i>
                        </div>
                        <div>
                            <h5 class="modal-title fw-bold text-dark mb-0" id="modalMallaMineduLabel">Cargar Malla Curricular MINEDU</h5>
                            <span class="text-muted small">Currículo Nacional Oficial de Educación Básica Regular.</span>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>

                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-semibold small text-dark mb-1">Nivel Educativo</label>
                        <select class="form-select" name="nivel_malla" id="nivelMallaSelect" required>
                            <option value="Primaria" selected>Primaria (Matemática, Comunicación, Personal Social, etc.)</option>
                            <option value="Secundaria">Secundaria (Matemática, Comunicación, CC.SS., DPCC, etc.)</option>
                            <option value="Inicial">Inicial (Comunicación, Matemática, Psicomotricidad, etc.)</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold small text-dark mb-1">Alcance de Grados</label>
                        <select class="form-select" name="alcance_grado" id="alcanceGradoSelect" required>
                            <option value="todos" selected>⚡ Todos los grados de este nivel (Recomendado)</option>
                            <option value="1ro">Solo 1° Grado</option>
                            <option value="2do">Solo 2° Grado</option>
                            <option value="3ro">Solo 3° Grado</option>
                            <option value="4to">Solo 4° Grado</option>
                            <option value="5to">Solo 5° Grado</option>
                            <option value="6to">Solo 6° Grado</option>
                        </select>
                    </div>

                    <div class="p-3 bg-light rounded-3 border">
                        <div class="small fw-semibold text-dark mb-1"><i class="bi bi-info-circle text-primary"></i> Asignaturas que se registrarán:</div>
                        <ul class="small text-muted mb-0 ps-3" id="listaPreviewCursos">
                            <li>Matemática</li>
                            <li>Comunicación</li>
                            <li>Ciencia y Tecnología</li>
                            <li>Personal Social</li>
                            <li>Arte y Cultura</li>
                            <li>Educación Física</li>
                            <li>Educación Religiosa</li>
                            <li>Inglés</li>
                            <li>Tutoría y Orientación Educativa</li>
                        </ul>
                    </div>
                </div>

                <div class="modal-footer border-0 pt-0 pb-4 px-4">
                    <button type="button" class="btn btn-outline-secondary px-3" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary px-4 fw-semibold d-inline-flex align-items-center gap-2">
                        <i class="bi bi-check2-circle"></i> Cargar Asignaturas
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/cursos_periodos.js" defer></script>

</body>
</html>
