<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/header.php';

require_auth(['asistente', 'director', 'hiperadmin']);

// Opciones de conceptos para el filtro
$conceptos = $pdo->query('SELECT id, descripcion FROM concepto_pago ORDER BY descripcion ASC')->fetchAll();

// Filtros
$fechaInicio = trim($_GET['fecha_inicio'] ?? date('Y-m-01'));
$fechaFin = trim($_GET['fecha_fin'] ?? date('Y-m-d'));
$filtroConcepto = filter_var($_GET['concepto_id'] ?? null, FILTER_VALIDATE_INT);
$filtroEstado = trim($_GET['estado'] ?? 'todos'); // 'todos', 'pagados', 'deuda'
$busqueda = trim($_GET['buscar'] ?? '');

$sql = "
    SELECT p.*,
           COALESCE(cuenta.monto_total, p.monto_total, p.monto) AS total_cuenta,
           COALESCE(cuenta.monto_pagado, p.monto_pagado, p.monto) AS acumulado_cuenta,
           COALESCE(cuenta.saldo_restante, p.saldo_restante, 0) AS saldo_cuenta,
           e.id AS estudiante_id, e.dni AS estudiante_dni,
           e.nombres AS estudiante_nombres, e.apellido_paterno AS estudiante_apellido_paterno, e.apellido_materno AS estudiante_apellido_materno,
           an.anio, n.nombre AS nivel_nombre, g.nombre AS grado_nombre, s.nombre AS seccion_nombre,
           r.nombres AS apoderado_nombres, r.apellido_paterno AS apoderado_apellido_paterno,
           u.nombres AS cajero_nombre,
           COALESCE(NULLIF(p.concepto, ''), cp.descripcion, 'Aporte') AS concepto_nombre
    FROM pago p
    LEFT JOIN matricula m ON m.id = p.matricula_id
    LEFT JOIN anio an ON an.id = m.anio_id
    LEFT JOIN nivel n ON n.id = m.nivel_id
    LEFT JOIN grado_seccion gs ON gs.id = m.grado_seccion_id
    LEFT JOIN grado g ON g.id = gs.grado_id
    LEFT JOIN seccion s ON s.id = gs.seccion_id
    LEFT JOIN estudiante e ON e.id = m.estudiante_id
    LEFT JOIN responsable r ON r.id = COALESCE(p.responsable_id, m.apoderado_principal_id)
    LEFT JOIN cuenta_pago cuenta ON cuenta.id = p.cuenta_pago_id
    LEFT JOIN concepto_pago cp ON cp.id = p.concepto_id
    LEFT JOIN usuario u ON u.id = p.registrado_por
    WHERE p.fecha_pago >= :fecha_inicio AND p.fecha_pago <= :fecha_fin
";
$params = [
    'fecha_inicio' => $fechaInicio,
    'fecha_fin' => $fechaFin,
];

if ($filtroConcepto) {
    $sql .= " AND p.concepto_id = :concepto_id";
    $params['concepto_id'] = $filtroConcepto;
}

if ($filtroEstado === 'pagados') {
    $sql .= " AND COALESCE(cuenta.saldo_restante, p.saldo_restante, 0) = 0";
} elseif ($filtroEstado === 'deuda') {
    $sql .= " AND COALESCE(cuenta.saldo_restante, p.saldo_restante, 0) > 0";
}

if ($busqueda !== '') {
    $sql .= " AND (
        e.nombres ILIKE :busq OR e.apellido_paterno ILIKE :busq OR e.apellido_materno ILIKE :busq OR e.dni ILIKE :busq
        OR p.entregado_por ILIKE :busq OR p.responsable ILIKE :busq OR p.nro_recibo ILIKE :busq
    )";
    $params['busq'] = "%$busqueda%";
}

$sql .= " ORDER BY p.fecha_pago DESC, p.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$pagos = $stmt->fetchAll();

// Métricas de resumen
$totalRecaudado = 0.0;
$totalSaldoPendiente = 0.0;
$totalOperaciones = count($pagos);

foreach ($pagos as $row) {
    $abono = (float)($row['abono_hoy'] ?: $row['monto']);
    $totalRecaudado += $abono;
    $totalSaldoPendiente += (float)$row['saldo_cuenta'];
}

$nombreConceptoFiltro = 'TODOS LOS CONCEPTOS';
if ($filtroConcepto) {
    foreach ($conceptos as $c) {
        if ((int)$c['id'] === $filtroConcepto) {
            $nombreConceptoFiltro = mb_strtoupper($c['descripcion'], 'UTF-8');
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reporte de Caja y Pagos - I.E. Divino Maestro</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background-color: #f8fafc;
            color: #1e293b;
        }

        .filter-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04);
            padding: 1.25rem 1.5rem;
            margin-bottom: 1.5rem;
        }

        .reporte-document {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 12px;
            box-shadow: 0 4px 16px rgba(15, 23, 42, 0.06);
            padding: 2.2rem 2.5rem;
            margin-bottom: 3rem;
            color: #0f172a;
        }

        .doc-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 2.5px solid #1e3a8a;
            padding-bottom: 1rem;
            margin-bottom: 1.25rem;
        }

        .doc-logo {
            width: 75px;
            height: 75px;
            object-fit: contain;
        }

        .doc-title-block {
            text-align: center;
            flex-grow: 1;
            padding: 0 1rem;
        }

        .doc-sup-title {
            font-size: 0.72rem;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 2px;
        }

        .doc-main-title {
            font-size: 1.25rem;
            font-weight: 800;
            color: #1e3a8a;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            margin: 0;
        }

        .doc-sub-title {
            font-size: 0.82rem;
            font-weight: 600;
            color: #475569;
        }

        .doc-banner {
            background: #1e3a8a;
            color: #ffffff;
            font-weight: 800;
            font-size: 1rem;
            text-align: center;
            padding: 0.45rem 1rem;
            border-radius: 6px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            margin-bottom: 1.25rem;
        }

        .meta-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
            gap: 0.75rem;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 0.85rem 1.25rem;
            margin-bottom: 1.5rem;
            font-size: 0.88rem;
        }

        .meta-item strong {
            color: #475569;
            text-transform: uppercase;
            font-size: 0.74rem;
            display: block;
            margin-bottom: 2px;
        }

        .meta-item span {
            font-weight: 700;
            color: #0f172a;
            font-size: 0.95rem;
        }

        /* Tabla de Pagos */
        .reporte-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
            margin-bottom: 1.5rem;
        }

        .reporte-table th, .reporte-table td {
            border: 1px solid #cbd5e1;
            padding: 0.45rem 0.6rem;
            vertical-align: middle;
        }

        .reporte-table thead th {
            background-color: #0f172a;
            color: #ffffff;
            font-weight: 700;
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            text-align: left;
        }

        .reporte-table tbody tr:nth-child(even) {
            background-color: #f8fafc;
        }

        .reporte-table tfoot td {
            background-color: #f1f5f9;
            font-weight: 800;
            border-top: 2px solid #0f172a;
        }

        .num-col {
            text-align: center;
            font-weight: 700;
            color: #64748b;
            width: 35px;
        }

        .money-col {
            font-family: monospace;
            font-weight: 700;
            text-align: right;
            white-space: nowrap;
        }

        /* Firmas */
        .doc-signatures {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 3rem;
            margin-top: 3.5rem;
            padding: 0 2rem;
        }

        .sig-box {
            text-align: center;
        }

        .sig-border {
            border-top: 1.5px dashed #475569;
            margin-bottom: 0.4rem;
        }

        .sig-title {
            font-size: 0.86rem;
            font-weight: 700;
            color: #0f172a;
        }

        .sig-subtitle {
            font-size: 0.76rem;
            color: #64748b;
        }

        /* ESTILOS DE IMPRESIÓN (A4 Landscape) */
        @media print {
            body {
                background: #ffffff !important;
                color: #000000 !important;
            }

            .no-print,
            .app-sidebar,
            .app-topbar,
            .sidebar-backdrop,
            .floating-dock-container {
                display: none !important;
            }

            main {
                margin: 0 !important;
                padding: 0 !important;
                max-width: 100% !important;
            }

            .reporte-document {
                border: none !important;
                box-shadow: none !important;
                padding: 0 !important;
                margin: 0 !important;
                width: 100% !important;
            }

            .reporte-table thead th {
                background-color: #1e3a8a !important;
                color: #ffffff !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .doc-banner {
                background: #1e3a8a !important;
                color: #ffffff !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .meta-grid {
                background: #ffffff !important;
                border: 1px solid #94a3b8 !important;
            }

            .btn-recibo {
                display: none !important;
            }

            @page {
                size: A4 landscape;
                margin: 10mm 10mm 10mm 10mm;
            }
        }
    </style>
</head>
<body class="bg-light">

<?php render_app_header(panel_home_path(), 'logout.php'); ?>

<main class="container-fluid px-3 px-md-4 px-xl-5 py-4">

    <!-- BARRA SUPERIOR Y TÍTULO (SOLO PANTALLA) -->
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4 no-print">
        <div>
            <h1 class="h3 fw-bold mb-1 text-dark d-flex align-items-center gap-2">
                <i class="bi bi-cash-stack text-primary"></i> Reporte Consolidado de Pagos y Caja
            </h1>
            <p class="text-muted mb-0 small">Control de ingresos, balances por concepto, abonos y arqueo de caja institucional.</p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <button type="button" class="btn btn-primary d-inline-flex align-items-center gap-2 px-3 py-2 fw-semibold shadow-sm" onclick="window.print();" style="border-radius: 10px;">
                <i class="bi bi-printer-fill"></i> Imprimir Reporte / Guardar PDF
            </button>
            <a href="pagos.php" class="btn btn-outline-secondary d-inline-flex align-items-center gap-1 px-3 py-2" style="border-radius: 10px;">
                <i class="bi bi-arrow-left"></i> Volver a Pagos
            </a>
        </div>
    </div>

    <!-- TARJETA DE FILTROS (SOLO PANTALLA) -->
    <section class="filter-card no-print">
        <form method="GET" class="row g-3 align-items-end" id="filter-form">
            <div class="col-md-2">
                <label class="form-label small fw-bold text-secondary" for="fecha_inicio">Desde</label>
                <input type="date" class="form-control" id="fecha_inicio" name="fecha_inicio" value="<?= htmlspecialchars($fechaInicio) ?>" required>
            </div>

            <div class="col-md-2">
                <label class="form-label small fw-bold text-secondary" for="fecha_fin">Hasta</label>
                <input type="date" class="form-control" id="fecha_fin" name="fecha_fin" value="<?= htmlspecialchars($fechaFin) ?>" required>
            </div>

            <div class="col-md-3">
                <label class="form-label small fw-bold text-secondary" for="concepto_id">Concepto de Cobro</label>
                <select class="form-select" id="concepto_id" name="concepto_id">
                    <option value="">-- Todos los conceptos --</option>
                    <?php foreach ($conceptos as $c): ?>
                        <option value="<?= (int)$c['id'] ?>" <?= $filtroConcepto === (int)$c['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['descripcion']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label small fw-bold text-secondary" for="estado">Estado</label>
                <select class="form-select" id="estado" name="estado">
                    <option value="todos" <?= $filtroEstado === 'todos' ? 'selected' : '' ?>>Todos</option>
                    <option value="pagados" <?= $filtroEstado === 'pagados' ? 'selected' : '' ?>>Completamente Pagados</option>
                    <option value="deuda" <?= $filtroEstado === 'deuda' ? 'selected' : '' ?>>Con Saldo Pendiente</option>
                </select>
            </div>

            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary w-100 fw-semibold d-inline-flex align-items-center justify-content-center gap-1">
                    <i class="bi bi-funnel-fill"></i> Filtrar
                </button>
                <a href="reporte_pagos.php" class="btn btn-light border text-secondary" title="Limpiar filtros">
                    <i class="bi bi-x-circle"></i>
                </a>
            </div>
        </form>
    </section>

    <!-- DOCUMENTO OFICIAL DE REPORTE DE CAJA (IMPRIMIBLE / PDF) -->
    <article class="reporte-document">

        <!-- 1. Encabezado Institucional -->
        <header class="doc-header">
            <img class="doc-logo" src="../assets/images/logo-institucion.jpg.jpg" alt="Logo Institucional">
            <div class="doc-title-block">
                <div class="doc-sup-title">República del Perú &bull; Ministerio de Educación &bull; DRE Cajamarca &bull; UGEL Cajamarca</div>
                <h2 class="doc-main-title">Institución Educativa &quot;Divino Maestro&quot;</h2>
                <div class="doc-sub-title">Mollepampa &bull; Cajamarca &bull; R.D. N° 0124-ED</div>
            </div>
            <div style="width: 75px; text-align: right;" class="d-none d-sm-block">
                <span class="badge bg-light text-secondary border p-2 font-monospace" style="font-size: 0.72rem;">
                    Caja
                </span>
            </div>
        </header>

        <!-- 2. Banner del Reporte -->
        <div class="doc-banner">
            Reporte Consolidado de Ingresos y Arqueo de Pagos
        </div>

        <!-- 3. Parámetros y Resumen Financiero -->
        <div class="meta-grid">
            <div class="meta-item">
                <strong>Periodo Consultado:</strong>
                <span><?= date('d/m/Y', strtotime($fechaInicio)) ?> al <?= date('d/m/Y', strtotime($fechaFin)) ?></span>
            </div>
            <div class="meta-item">
                <strong>Concepto:</strong>
                <span><?= $nombreConceptoFiltro ?></span>
            </div>
            <div class="meta-item">
                <strong>Total Operaciones:</strong>
                <span style="color: #1e3a8a;"><?= $totalOperaciones ?> pagos</span>
            </div>
            <div class="meta-item">
                <strong>Total Recaudado (Abonos):</strong>
                <span style="color: #166534; font-size: 1.05rem;">S/ <?= number_format($totalRecaudado, 2) ?></span>
            </div>
            <div class="meta-item">
                <strong>Saldo Pendiente:</strong>
                <span style="color: <?= $totalSaldoPendiente > 0 ? '#b91c1c' : '#475569' ?>;">S/ <?= number_format($totalSaldoPendiente, 2) ?></span>
            </div>
        </div>

        <!-- 4. Tabla de Transacciones -->
        <div class="table-responsive">
            <table class="reporte-table">
                <thead>
                    <tr>
                        <th style="width: 35px;" class="text-center">#</th>
                        <th style="width: 85px;">Fecha</th>
                        <th style="width: 95px;">N° Recibo</th>
                        <th>Estudiante / Aula</th>
                        <th>Entregado por / Apoderado</th>
                        <th>Concepto</th>
                        <th style="width: 95px;" class="text-end">Abono (S/)</th>
                        <th style="width: 85px;" class="text-end">Saldo (S/)</th>
                        <th style="width: 95px;">Cajero</th>
                        <th style="width: 70px;" class="text-center no-print">Recibo</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$pagos): ?>
                        <tr>
                            <td colspan="10" class="text-center py-4 text-muted">
                                <i class="bi bi-info-circle fs-4 d-block mb-1"></i>
                                No se encontraron registros de pago en el rango de fechas seleccionado.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php $corr = 1; ?>
                        <?php foreach ($pagos as $row): ?>
                            <?php
                            $abonoRow = (float)($row['abono_hoy'] ?: $row['monto']);
                            $saldoRow = (float)$row['saldo_cuenta'];
                            $estudianteNombre = trim(($row['estudiante_apellido_paterno'] ?? '') . ' ' . ($row['estudiante_apellido_materno'] ?? '') . ', ' . ($row['estudiante_nombres'] ?? ''));
                            $aula = trim(($row['grado_nombre'] ?? '') . ' "' . ($row['seccion_nombre'] ?? '') . '"');
                            $reciboCodigo = !empty($row['nro_recibo']) ? $row['nro_recibo'] : ('REC-' . date('Y', strtotime($row['fecha_pago'])) . '-' . str_pad((string)$row['id'], 6, '0', STR_PAD_LEFT));
                            ?>
                            <tr>
                                <td class="num-col"><?= $corr++ ?></td>
                                <td class="font-monospace" style="font-size: 0.8rem;"><?= date('d/m/Y', strtotime($row['fecha_pago'])) ?></td>
                                <td class="font-monospace fw-bold" style="font-size: 0.8rem; color: #1e3a8a;"><?= htmlspecialchars($reciboCodigo) ?></td>
                                <td>
                                    <strong><?= htmlspecialchars($estudianteNombre ?: 'Sin estudiante') ?></strong>
                                    <?php if ($aula): ?>
                                        <small class="text-muted d-block"><?= htmlspecialchars($aula) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= htmlspecialchars($row['entregado_por'] ?: ($row['responsable'] ?: '-')) ?>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border"><?= htmlspecialchars($row['concepto_nombre']) ?></span>
                                </td>
                                <td class="money-col text-success">
                                    S/ <?= number_format($abonoRow, 2) ?>
                                </td>
                                <td class="money-col" style="color: <?= $saldoRow > 0 ? '#b91c1c' : '#64748b' ?>;">
                                    S/ <?= number_format($saldoRow, 2) ?>
                                </td>
                                <td class="small text-muted"><?= htmlspecialchars($row['cajero_nombre'] ?? 'Caja') ?></td>
                                <td class="text-center no-print">
                                    <a href="recibo_pago.php?id=<?= (int)$row['id'] ?>" target="_blank" rel="opener" class="btn btn-sm btn-outline-primary btn-recibo py-0 px-1" title="Ver Recibo Individual">
                                        <i class="bi bi-printer"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="6" class="text-end text-uppercase">TOTAL RECAUDADO EN EL PERIODO:</td>
                        <td class="money-col text-success" style="font-size: 0.95rem;">S/ <?= number_format($totalRecaudado, 2) ?></td>
                        <td class="money-col" style="color: <?= $totalSaldoPendiente > 0 ? '#b91c1c' : '#64748b' ?>;">S/ <?= number_format($totalSaldoPendiente, 2) ?></td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <!-- 5. Resumen al Pie -->
        <div class="d-flex justify-content-between align-items-center small text-muted border-top pt-2 mb-4">
            <div>
                <strong>Total Operaciones:</strong> <?= $totalOperaciones ?> &bull;
                <strong>Monto Total Liquidado:</strong> S/ <?= number_format($totalRecaudado, 2) ?> &bull;
                Fecha de Emisión: <?= date('d/m/Y H:i') ?>
            </div>
            <div>
                Sistema Integrado de Finanzas &bull; I.E. &quot;Divino Maestro&quot;
            </div>
        </div>

        <!-- 6. Firmas Oficiales -->
        <div class="doc-signatures">
            <div class="sig-box">
                <div class="sig-border"></div>
                <div class="sig-title">Tesorería / Responsable de Caja</div>
                <div class="sig-subtitle">Firma y Sello</div>
            </div>
            <div class="sig-box">
                <div class="sig-border"></div>
                <div class="sig-title">Dirección Institucional</div>
                <div class="sig-subtitle">V°B° y Conformidad</div>
            </div>
        </div>

    </article>

</main>

<?php render_floating_dock('pagos'); ?>

</body>
</html>
