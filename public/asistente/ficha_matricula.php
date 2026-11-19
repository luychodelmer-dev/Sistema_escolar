<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/auth.php';

require_auth(['asistente', 'director', 'hiperadmin']);

$matriculaId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$estudianteId = filter_var($_GET['estudiante_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

if (!$matriculaId && !$estudianteId) {
    http_response_code(400);
    exit('Debe especificar un ID de matrícula o ID de estudiante.');
}

// Si se recibe estudiante_id pero no matricula_id, buscar la matrícula más reciente
if (!$matriculaId && $estudianteId) {
    $findStmt = $pdo->prepare('SELECT id FROM matricula WHERE estudiante_id = :eid ORDER BY id DESC LIMIT 1');
    $findStmt->execute(['eid' => $estudianteId]);
    $foundId = $findStmt->fetchColumn();
    if (!$foundId) {
        http_response_code(404);
        exit('El estudiante seleccionado no cuenta con matrículas registradas.');
    }
    $matriculaId = (int) $foundId;
}

// Consultar los datos completos de la matrícula y estudiante
$query = "
    SELECT m.id AS matricula_id, m.creado_en AS fecha_matricula, m.estado AS estado_matricula,
           m.es_estudiante_nuevo, m.apoderado_principal_id,
           e.id AS estudiante_id, e.dni, e.nombres, e.apellido_paterno, e.apellido_materno,
           e.fecha_nacimiento, e.estado_academico,
           a.anio,
           n.nombre AS nivel_nombre,
           g.nombre AS grado_nombre, g.numero AS grado_numero,
           s.nombre AS seccion_nombre,
           u.nombres AS usuario_matriculador
    FROM matricula m
    JOIN estudiante e ON e.id = m.estudiante_id
    LEFT JOIN anio a ON a.id = m.anio_id
    LEFT JOIN nivel n ON n.id = m.nivel_id
    LEFT JOIN grado_seccion gs ON gs.id = m.grado_seccion_id
    LEFT JOIN grado g ON g.id = gs.grado_id
    LEFT JOIN seccion s ON s.id = gs.seccion_id
    LEFT JOIN usuario u ON u.id = m.registrado_por
    WHERE m.id = :mid
    LIMIT 1
";
$stmt = $pdo->prepare($query);
$stmt->execute(['mid' => $matriculaId]);
$data = $stmt->fetch();

if (!$data) {
    http_response_code(404);
    exit('Matrícula no encontrada.');
}

// Consultar los familiares y contactos asociados al estudiante
$famStmt = $pdo->prepare("
    SELECT er.tipo, er.parentesco, er.es_apoderado,
           r.id AS responsable_id, r.dni, r.nombres, r.apellido_paterno, r.apellido_materno,
           r.celular, r.correo, r.direccion
    FROM estudiante_responsable er
    JOIN responsable r ON r.id = er.responsable_id
    WHERE er.estudiante_id = :eid
    ORDER BY CASE er.tipo WHEN 'padre' THEN 1 WHEN 'madre' THEN 2 ELSE 3 END, r.id ASC
");
$famStmt->execute(['eid' => $data['estudiante_id']]);
$responsables = $famStmt->fetchAll();

// Clasificar familiares
$padre = null;
$madre = null;
$apoderado = null;
$emergencia = null;

foreach ($responsables as $resp) {
    if ($resp['tipo'] === 'padre' && !$padre) {
        $padre = $resp;
    } elseif ($resp['tipo'] === 'madre' && !$madre) {
        $madre = $resp;
    } elseif (in_array($resp['tipo'], ['emergencia', 'otro'], true) && !$emergencia) {
        $emergencia = $resp;
    }

    // Comprobar si es el apoderado principal
    if (
        ($data['apoderado_principal_id'] && (int)$resp['responsable_id'] === (int)$data['apoderado_principal_id']) ||
        (!$data['apoderado_principal_id'] && ($resp['es_apoderado'] === true || $resp['es_apoderado'] === 't'))
    ) {
        $apoderado = $resp;
    }
}

// Si no se designó formalmente apoderado, tomar padre, madre o primer responsable
if (!$apoderado) {
    $apoderado = $padre ?? $madre ?? ($responsables[0] ?? null);
}

// Cálculo de edad
$edadTexto = '-';
if (!empty($data['fecha_nacimiento'])) {
    try {
        $fn = new DateTime($data['fecha_nacimiento']);
        $ahora = new DateTime();
        $edadTexto = $fn->diff($ahora)->y . ' años';
    } catch (Exception $e) {
        $edadTexto = '-';
    }
}

$nombreCompletoEstudiante = trim($data['apellido_paterno'] . ' ' . $data['apellido_materno'] . ', ' . $data['nombres']);
$codigoMatricula = 'MAT-' . ($data['anio'] ?? date('Y')) . '-' . str_pad((string)$data['matricula_id'], 5, '0', STR_PAD_LEFT);
$fechaRegistro = !empty($data['fecha_matricula']) ? date('d/m/Y', strtotime($data['fecha_matricula'])) : date('d/m/Y');
$fechaEmision = date('d/m/Y H:i');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ficha de Matrícula - <?= htmlspecialchars($nombreCompletoEstudiante) ?> (<?= htmlspecialchars($data['anio'] ?? '') ?>)</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../assets/css/app.css">
    <link rel="stylesheet" href="../assets/css/reports-print.css">
    <link rel="stylesheet" href="../assets/css/ficha_matricula.css">
</head>
<body>

    <!-- BARRA SUPERIOR DE ACCIONES (SOLO PANTALLA) -->
    <div class="no-print-toolbar">
        <div class="toolbar-title">
            <i class="bi bi-file-earmark-person"></i>
            <span>Ficha de Matrícula: <strong><?= htmlspecialchars($nombreCompletoEstudiante) ?></strong> (<?= htmlspecialchars($data['anio'] ?? '') ?>)</span>
        </div>
        <div class="toolbar-actions">
            <button type="button" class="btn btn-print" onclick="window.print();">
                <i class="bi bi-printer-fill"></i> Imprimir / Guardar como PDF
            </button>
            <button type="button" class="btn btn-back" onclick="cerrarYRegresar('estudiantes.php');" title="Volver a Estudiantes y cerrar esta pestaña">
                <i class="bi bi-arrow-left"></i> Volver a Estudiantes
            </button>
            <button type="button" class="btn btn-back" onclick="cerrarYRegresar('ver_estudiante.php?id=<?= (int)$data['estudiante_id'] ?>');" title="Ver expediente y cerrar esta pestaña">
                <i class="bi bi-person-badge"></i> Ver Estudiante
            </button>
            <button type="button" class="btn btn-close-red" onclick="cerrarEstaPestana();" title="Cerrar esta pestaña">
                <i class="bi bi-x-lg"></i> Cerrar Pestaña
            </button>
        </div>
    </div>

    <!-- HOJA A4 IMPRIMIBLE -->
    <div class="sheet-container">

        <!-- 1. Encabezado Institucional -->
        <header class="inst-header">
            <img class="inst-logo" src="../assets/images/logo-institucion.jpg.jpg" alt="Logo Institución">
            <div class="inst-titles">
                <div class="sup-title">República del Perú &bull; Ministerio de Educación</div>
                <div class="sup-title">DRE Cajamarca &bull; UGEL Cajamarca</div>
                <h1 class="main-title">I.E. &quot;Divino Maestro&quot;</h1>
                <div class="sub-title">Mollepampa &bull; Cajamarca</div>
            </div>
            <div class="code-box">
                <div class="code-label">N° de Matrícula</div>
                <div class="code-value"><?= htmlspecialchars($codigoMatricula) ?></div>
                <div style="font-size: 8.5px; color: #64748b; margin-top: 3px;">
                    F. Registro: <?= htmlspecialchars($fechaRegistro) ?>
                </div>
            </div>
        </header>

        <!-- 2. Título Oficial del Documento -->
        <div class="doc-banner">
            Ficha Única de Matrícula &bull; Año Lectivo <?= htmlspecialchars((string) ($data['anio'] ?? date('Y'))) ?>
        </div>

        <!-- 3. Datos del Estudiante -->
        <section class="report-section">
            <div class="section-header">
                <i class="bi bi-person-fill"></i> I. Datos Generales del Estudiante
            </div>
            <table class="data-table">
                <tr>
                    <th>Apellidos y Nombres</th>
                    <td colspan="3" class="val-highlight" style="font-size: 12.5px; color: var(--primary);">
                        <?= htmlspecialchars($nombreCompletoEstudiante) ?>
                    </td>
                </tr>
                <tr>
                    <th>N° Documento (DNI)</th>
                    <td class="val-highlight" style="font-family: monospace; font-size: 12px;">
                        <?= htmlspecialchars($data['dni'] ?? '-') ?>
                    </td>
                    <th>Fecha de Nacimiento</th>
                    <td>
                        <?= !empty($data['fecha_nacimiento']) ? date('d/m/Y', strtotime($data['fecha_nacimiento'])) : '-' ?>
                        <span style="color: #64748b; margin-left: 6px;">(Edad: <?= htmlspecialchars($edadTexto) ?>)</span>
                    </td>
                </tr>
                <tr>
                    <th>Estado Académico</th>
                    <td>
                        <span class="badge-inline"><?= htmlspecialchars($data['estado_academico'] ?? 'Activo') ?></span>
                    </td>
                    <th>Condición de Matrícula</th>
                    <td>
                        <span class="val-highlight"><?= !empty($data['es_estudiante_nuevo']) ? 'Estudiante Nuevo' : 'Ratificación / Continuo' ?></span>
                    </td>
                </tr>
            </table>
        </section>

        <!-- 4. Ubicación y Asignación Académica -->
        <section class="report-section">
            <div class="section-header">
                <i class="bi bi-journal-bookmark-fill"></i> II. Información y Asignación Académica
            </div>
            <table class="data-table">
                <tr>
                    <th>Nivel Educativo</th>
                    <td class="val-highlight" style="font-size: 12px;">
                        <?= htmlspecialchars($data['nivel_nombre'] ?? 'No especificado') ?>
                    </td>
                    <th>Año Lectivo</th>
                    <td class="val-highlight font-monospace">
                        <?= htmlspecialchars((string) ($data['anio'] ?? date('Y'))) ?>
                    </td>
                </tr>
                <tr>
                    <th>Grado Asignado</th>
                    <td class="val-highlight">
                        <?= htmlspecialchars((string) ($data['grado_nombre'] ?? $data['grado_numero'] ?? '-')) ?>
                    </td>
                    <th>Sección</th>
                    <td class="val-highlight" style="font-size: 13px; color: var(--primary);">
                        &quot;<?= htmlspecialchars($data['seccion_nombre'] ?? 'Única') ?>&quot;
                    </td>
                </tr>
                <tr>
                    <th>Estado de la Matrícula</th>
                    <td>
                        <span class="badge-inline" style="background: #dcfce7; color: #166534;">
                            <?= htmlspecialchars($data['estado_matricula'] ?? 'Activa') ?>
                        </span>
                    </td>
                    <th>Registrado por</th>
                    <td style="color: #475569;">
                        <?= htmlspecialchars($data['usuario_matriculador'] ?? 'Sistema Académico') ?>
                    </td>
                </tr>
            </table>
        </section>

        <!-- 5. Datos Familiares y Apoderado Legal -->
        <section class="report-section">
            <div class="section-header">
                <i class="bi bi-people-fill"></i> III. Datos Familiares y Apoderado Principal
            </div>
            <table class="data-table">
                <tr>
                    <th style="background-color: #eff6ff; color: var(--primary);">Apoderado Principal</th>
                    <td colspan="3" class="val-highlight" style="font-size: 12px;">
                        <?php if ($apoderado): ?>
                            <?= htmlspecialchars(trim($apoderado['apellido_paterno'] . ' ' . ($apoderado['apellido_materno'] ?? '') . ', ' . $apoderado['nombres'])) ?>
                            <span style="font-size: 10px; font-weight: normal; color: #475569; margin-left: 8px;">
                                (Parentesco: <strong><?= htmlspecialchars(ucfirst($apoderado['parentesco'] ?? $apoderado['tipo'] ?? 'Apoderado')) ?></strong>)
                            </span>
                        <?php else: ?>
                            <span style="color: #94a3b8; font-style: italic;">No registrado</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th>DNI del Apoderado</th>
                    <td class="font-monospace">
                        <?= htmlspecialchars($apoderado['dni'] ?? '-') ?>
                    </td>
                    <th>Celular / Contacto</th>
                    <td class="font-monospace val-highlight">
                        <?= htmlspecialchars($apoderado['celular'] ?? '-') ?>
                    </td>
                </tr>
                <tr>
                    <th>Dirección / Domicilio</th>
                    <td colspan="3">
                        <?= htmlspecialchars($apoderado['direccion'] ?? 'No registrada') ?>
                    </td>
                </tr>
                <tr>
                    <th>Datos del Padre</th>
                    <td>
                        <?php if ($padre): ?>
                            <?= htmlspecialchars($padre['apellido_paterno'] . ' ' . $padre['nombres']) ?><br>
                            <small class="text-muted">DNI: <?= htmlspecialchars($padre['dni'] ?? '-') ?> | Cel: <?= htmlspecialchars($padre['celular'] ?? '-') ?></small>
                        <?php else: ?>
                            <span style="color: #94a3b8;">No registrado</span>
                        <?php endif; ?>
                    </td>
                    <th>Datos de la Madre</th>
                    <td>
                        <?php if ($madre): ?>
                            <?= htmlspecialchars($madre['apellido_paterno'] . ' ' . $madre['nombres']) ?><br>
                            <small class="text-muted">DNI: <?= htmlspecialchars($madre['dni'] ?? '-') ?> | Cel: <?= htmlspecialchars($madre['celular'] ?? '-') ?></small>
                        <?php else: ?>
                            <span style="color: #94a3b8;">No registrado</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php if ($emergencia): ?>
                <tr>
                    <th>Contacto de Emergencia</th>
                    <td colspan="3">
                        <?= htmlspecialchars($emergencia['apellido_paterno'] . ' ' . $emergencia['nombres']) ?>
                        (Parentesco: <?= htmlspecialchars($emergencia['parentesco'] ?? 'Contacto') ?>) &bull;
                        <strong>Teléfono: <?= htmlspecialchars($emergencia['celular'] ?? '-') ?></strong>
                    </td>
                </tr>
                <?php endif; ?>
            </table>
        </section>

        <!-- 6. Declaración y Compromiso del Apoderado -->
        <div class="commitment-box">
            <strong>DECLARACIÓN JURADA Y COMPROMISO DEL APODERADO:</strong><br>
            Yo, <strong><?= htmlspecialchars($apoderado ? trim($apoderado['nombres'] . ' ' . $apoderado['apellido_paterno'] . ' ' . ($apoderado['apellido_materno'] ?? '')) : '....................................................................') ?></strong>,
            identificado(a) con DNI N° <strong><?= htmlspecialchars($apoderado['dni'] ?? '....................') ?></strong>, en mi condición de apoderado(a) legal del estudiante, declaro bajo juramento que los datos contenidos en esta ficha son fidedignos.
            Asimismo, me comprometo formalmente a velar por la puntualidad, asistencia diaria, disciplina y formación integral de mi menor pupilo(a), acatando el Reglamento Interno y las disposiciones de la Institución Educativa &quot;Divino Maestro&quot;.
        </div>

        <!-- 7. Recuadros de Firmas Oficiales -->
        <div class="signatures-grid">
            <div class="sig-card">
                <div class="sig-line"></div>
                <div class="sig-name">Firma del Padre / Madre / Apoderado</div>
                <div class="sig-title">DNI N°: <?= htmlspecialchars($apoderado['dni'] ?? '____________________') ?></div>
                <div class="huella-box">
                    Huella<br>Dactilar
                </div>
            </div>
            <div class="sig-card">
                <div class="sig-line"></div>
                <div class="sig-name">Dirección / Secretaría Académica</div>
                <div class="sig-title">I.E. &quot;Divino Maestro&quot; - Mollepampa</div>
                <div style="font-size: 8.5px; color: #94a3b8; margin-top: 15px;">(Sello y Firma Institucional)</div>
            </div>
        </div>

        <!-- 8. Pie de Página del Documento -->
        <footer class="sheet-footer">
            <div>Sistema Integrado de Gestión Escolar &bull; Documento Oficial</div>
            <div>Fecha de Emisión: <?= htmlspecialchars($fechaEmision) ?></div>
        </footer>

    </div>

    <script src="../assets/js/ficha_matricula.js" defer></script>
</body>
</html>
