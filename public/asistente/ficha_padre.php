<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/auth.php';

require_auth(['asistente', 'director', 'hiperadmin']);

$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) {
    http_response_code(400);
    exit('Debe especificar un ID de responsable válido.');
}

$stmt = $pdo->prepare('SELECT * FROM responsable WHERE id = :id');
$stmt->execute(['id' => $id]);
$responsable = $stmt->fetch();

if (!$responsable) {
    http_response_code(404);
    exit('Apoderado no encontrado.');
}

// Estudiantes a cargo del responsable
$studentsStmt = $pdo->prepare('
    SELECT e.id, e.dni, e.nombres, e.apellido_paterno, e.apellido_materno,
           e.fecha_nacimiento, e.estado_academico,
           er.tipo, er.parentesco, er.es_apoderado,
           matricula.anio, matricula.nivel, matricula.grado, matricula.seccion
    FROM estudiante_responsable er
    JOIN estudiante e ON e.id = er.estudiante_id
    LEFT JOIN LATERAL (
        SELECT an.anio, n.nombre AS nivel, g.nombre AS grado, s.nombre AS seccion
        FROM matricula m
        LEFT JOIN anio an ON an.id = m.anio_id
        LEFT JOIN nivel n ON n.id = m.nivel_id
        LEFT JOIN grado_seccion gs ON gs.id = m.grado_seccion_id
        LEFT JOIN grado g ON g.id = gs.grado_id
        LEFT JOIN seccion s ON s.id = gs.seccion_id
        WHERE m.estudiante_id = e.id
        ORDER BY an.anio DESC NULLS LAST, m.id DESC
        LIMIT 1
    ) matricula ON TRUE
    WHERE er.responsable_id = :id
    ORDER BY e.apellido_paterno, e.apellido_materno, e.nombres
');
$studentsStmt->execute(['id' => $id]);
$estudiantes = $studentsStmt->fetchAll();

// Otros familiares vinculados a los mismos estudiantes (cónyuge / segundo padre)
$conyugeStmt = $pdo->prepare('
    SELECT DISTINCT r2.id, r2.dni, r2.nombres, r2.apellido_paterno, r2.apellido_materno,
           r2.celular, r2.direccion, er2.parentesco, er2.tipo
    FROM estudiante_responsable er1
    JOIN estudiante_responsable er2 ON er2.estudiante_id = er1.estudiante_id AND er2.responsable_id <> er1.responsable_id
    JOIN responsable r2 ON r2.id = er2.responsable_id
    WHERE er1.responsable_id = :id
    ORDER BY r2.apellido_paterno, r2.nombres
');
$conyugeStmt->execute(['id' => $id]);
$otrosFamiliares = $conyugeStmt->fetchAll();

// Historial reciente de pagos
$nombreCompleto = trim($responsable['nombres'] . ' ' . $responsable['apellido_paterno'] . ' ' . ($responsable['apellido_materno'] ?? ''));
$paymentStmt = $pdo->prepare("
    SELECT p.id, p.fecha_pago, p.monto, p.nro_recibo, p.observacion,
           COALESCE(NULLIF(p.concepto, ''), cp.descripcion, 'Aporte') AS concepto,
           COALESCE(NULLIF(p.estado, ''), p.estado_pago, 'Pagado') AS estado
    FROM pago p
    LEFT JOIN concepto_pago cp ON cp.id = p.concepto_id
    WHERE p.responsable_id = :direct_id
       OR LOWER(TRIM(COALESCE(p.responsable, ''))) = LOWER(TRIM(:legacy_name))
    ORDER BY p.fecha_pago DESC, p.id DESC
    LIMIT 6
");
$paymentStmt->execute([
    'direct_id' => $id,
    'legacy_name' => $nombreCompleto,
]);
$pagos = $paymentStmt->fetchAll();

$totalAbonado = 0.0;
foreach ($pagos as $p) {
    if (strtolower($p['estado']) === 'pagado') {
        $totalAbonado += (float)$p['monto'];
    }
}

$codigoFamilia = 'FAM-' . str_pad((string)$responsable['id'], 5, '0', STR_PAD_LEFT);
$celular = $responsable['celular_actual'] ?: ($responsable['celular'] ?? '-');
$fechaEmision = date('d/m/Y H:i');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ficha Familiar - <?= htmlspecialchars($nombreCompleto) ?> (<?= htmlspecialchars($codigoFamilia) ?>)</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../assets/css/app.css">
    <link rel="stylesheet" href="../assets/css/reports-print.css">
    <link rel="stylesheet" href="../assets/css/ficha_padre.css">
</head>
<body>

    <!-- BARRA SUPERIOR DE ACCIONES (SOLO PANTALLA) -->
    <div class="no-print-toolbar">
        <div class="toolbar-title">
            <i class="bi bi-people-fill"></i>
            <span>Ficha Familiar: <strong><?= htmlspecialchars($nombreCompleto) ?></strong> (<?= htmlspecialchars($codigoFamilia) ?>)</span>
        </div>
        <div class="toolbar-actions">
            <button type="button" class="btn btn-print" onclick="window.print();">
                <i class="bi bi-printer-fill"></i> Imprimir / Guardar como PDF
            </button>
            <button type="button" class="btn btn-back" onclick="cerrarYRegresar('padres.php');" title="Volver a Padres y cerrar esta pestaña">
                <i class="bi bi-arrow-left"></i> Volver a Padres
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
                <div class="code-label">Código Familiar</div>
                <div class="code-value"><?= htmlspecialchars($codigoFamilia) ?></div>
                <div style="font-size: 8.5px; color: #64748b; margin-top: 3px;">
                    APAFA: <?= htmlspecialchars($responsable['nro_tarjeta_apafa'] ?? 'S/A') ?>
                </div>
            </div>
        </header>

        <!-- 2. Título Oficial del Documento -->
        <div class="doc-banner">
            Ficha Oficial de Registro Familiar y Apoderado
        </div>

        <!-- 3. Datos del Apoderado / Padre -->
        <section class="report-section">
            <div class="section-header">
                <i class="bi bi-person-badge-fill"></i> I. Datos del Apoderado / Responsable Principal
            </div>
            <table class="data-table">
                <tr>
                    <th>Apellidos y Nombres</th>
                    <td colspan="3" class="val-highlight" style="font-size: 12.5px; color: var(--primary);">
                        <?= htmlspecialchars($nombreCompleto) ?>
                    </td>
                </tr>
                <tr>
                    <th>N° Documento (DNI)</th>
                    <td class="val-highlight font-monospace" style="font-size: 12px;">
                        <?= htmlspecialchars($responsable['dni'] ?? '-') ?>
                    </td>
                    <th>Celular / Contacto</th>
                    <td class="val-highlight font-monospace">
                        <?= htmlspecialchars($celular) ?>
                    </td>
                </tr>
                <tr>
                    <th>Dirección Domiciliaria</th>
                    <td colspan="3">
                        <?= htmlspecialchars($responsable['direccion'] ?? 'No registrada') ?>
                    </td>
                </tr>
                <tr>
                    <th>Correo Electrónico</th>
                    <td>
                        <?= htmlspecialchars($responsable['correo'] ?? '-') ?>
                    </td>
                    <th>Tarjeta APAFA</th>
                    <td class="font-monospace">
                        <?= htmlspecialchars($responsable['nro_tarjeta_apafa'] ?? 'Sin asignar') ?>
                    </td>
                </tr>
            </table>
        </section>

        <!-- 4. Estudiantes / Hijos a cargo -->
        <section class="report-section">
            <div class="section-header">
                <i class="bi bi-mortarboard-fill"></i> II. Estudiantes / Hijos a Cargo Matriculados en el Plantel
            </div>
            <table class="data-table" style="text-align: left;">
                <thead>
                    <tr style="background-color: #f1f5f9;">
                        <th style="width: 35px; text-align: center;">N°</th>
                        <th style="width: 85px;">DNI</th>
                        <th>Apellidos y Nombres</th>
                        <th style="width: 120px;">Nivel / Grado / Sec.</th>
                        <th style="width: 85px;">Año</th>
                        <th style="width: 95px;">Parentesco</th>
                        <th style="width: 90px;">Rol</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$estudiantes): ?>
                        <tr><td colspan="7" style="text-align: center; color: #94a3b8; padding: 10px;">Sin estudiantes registrados a cargo.</td></tr>
                    <?php else: ?>
                        <?php $c = 1; ?>
                        <?php foreach ($estudiantes as $est): ?>
                            <tr>
                                <td style="text-align: center; font-weight: 700;"><?= $c++ ?></td>
                                <td class="font-monospace"><?= htmlspecialchars($est['dni']) ?></td>
                                <td class="val-highlight"><?= htmlspecialchars($est['apellido_paterno'] . ' ' . $est['apellido_materno'] . ', ' . $est['nombres']) ?></td>
                                <td><?= htmlspecialchars(($est['nivel'] ?? '') . ' ' . ($est['grado'] ?? '') . ' "' . ($est['seccion'] ?? '') . '"') ?></td>
                                <td class="font-monospace"><?= htmlspecialchars((string) ($est['anio'] ?? '-')) ?></td>
                                <td><?= htmlspecialchars(ucfirst($est['parentesco'] ?? 'Hijo(a)')) ?></td>
                                <td>
                                    <?php if (!empty($est['es_apoderado'])): ?>
                                        <strong style="color: var(--primary);">Principal</strong>
                                    <?php else: ?>
                                        <span style="color: #64748b;">Secundario</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </section>

        <!-- 5. Otros Familiares Vinculados al Hogar -->
        <?php if (!empty($otrosFamiliares)): ?>
        <section class="report-section">
            <div class="section-header">
                <i class="bi bi-people-fill"></i> III. Otros Familiares / Cónyuge Vinculados al Estudiante
            </div>
            <table class="data-table">
                <?php foreach ($otrosFamiliares as $of): ?>
                    <tr>
                        <th><?= htmlspecialchars(ucfirst($of['parentesco'] ?? 'Familiar')) ?></th>
                        <td class="val-highlight">
                            <?= htmlspecialchars($of['apellido_paterno'] . ' ' . ($of['apellido_materno'] ?? '') . ', ' . $of['nombres']) ?>
                            <small class="text-muted d-block font-monospace">DNI: <?= htmlspecialchars($of['dni'] ?? '-') ?></small>
                        </td>
                        <th>Celular</th>
                        <td class="font-monospace">
                            <?= htmlspecialchars($of['celular'] ?? '-') ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </section>
        <?php endif; ?>

        <!-- 6. Resumen de Aportes Económicos y Pagos -->
        <section class="report-section">
            <div class="section-header">
                <i class="bi bi-wallet2"></i> IV. Estado y Registro de Aportes Económicos
            </div>
            <table class="data-table">
                <tr>
                    <th>Total Registrado Abonado</th>
                    <td class="val-highlight" style="font-size: 12px; color: #166534;">
                        S/ <?= number_format($totalAbonado, 2) ?>
                    </td>
                    <th>Cantidad de Recibos</th>
                    <td>
                        <?= count($pagos) ?> recibos registrados
                    </td>
                </tr>
            </table>
        </section>

        <!-- 7. Declaración Jurada y Compromiso -->
        <div class="commitment-box">
            <strong>COMPROMISO Y CONFORMIDAD DE LA FAMILIA:</strong><br>
            El suscrito padre / madre / apoderado se compromete a velar por el cumplimiento del deber escolar de sus hijos o pupilos, acudir puntualmente a las citaciones y asambleas convocadas por los docentes tutores y la Dirección, y respetar el Reglamento Interno y las normas de convivencia de la I.E. &quot;Divino Maestro&quot;.
        </div>

        <!-- 8. Firmas Oficiales -->
        <div class="signatures-grid">
            <div class="sig-card">
                <div class="sig-line"></div>
                <div class="sig-name">Firma del Padre / Madre / Apoderado</div>
                <div class="sig-title">DNI N°: <?= htmlspecialchars($responsable['dni'] ?? '____________________') ?></div>
                <div class="huella-box">
                    Huella<br>Dactilar
                </div>
            </div>
            <div class="sig-card">
                <div class="sig-line"></div>
                <div class="sig-name">Dirección Institucional / APAFA</div>
                <div class="sig-title">I.E. &quot;Divino Maestro&quot; - Mollepampa</div>
                <div style="font-size: 8.5px; color: #94a3b8; margin-top: 15px;">(Sello y Firma Institucional)</div>
            </div>
        </div>

        <!-- 9. Pie de Página del Documento -->
        <footer class="sheet-footer">
            <div>Sistema Integrado de Gestión Escolar &bull; Registro de Familias</div>
            <div>Fecha de Emisión: <?= htmlspecialchars($fechaEmision) ?></div>
        </footer>

    </div>

    <script src="../assets/js/ficha_padre.js" defer></script>
</body>
</html>
