<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/header.php';
require_once __DIR__ . '/../../app/dashboard_metrics.php';

require_auth(['asistente', 'director', 'hiperadmin']);
$metrics = get_dashboard_metrics($pdo);

$dist = $metrics['distribution_levels'] ?? [
    'Inicial' => ['count' => 0, 'pct' => 0],
    'Primaria' => ['count' => 0, 'pct' => 0],
    'Secundaria' => ['count' => 0, 'pct' => 0],
];
$cobranza = $metrics['cobranza'] ?? ['cobrado' => 0, 'por_cobrar' => 0, 'total_facturado' => 0, 'percentage' => 100];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Panel de Asistente - Sistema Escolar</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../assets/css/app.css">
    <link rel="stylesheet" href="../assets/css/panel.css">
</head>
<body>
<?php render_app_header(panel_home_path(), 'logout.php', 'panel'); ?>

<main class="container-fluid py-4">
    <!-- Encabezado Principal -->
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <h1 class="h3 fw-bold mb-1 text-dark">Panel de Asistente</h1>
            <p class="text-muted mb-0" style="font-size: 0.95rem;">Acceso rápido a matrícula, directorio escolar y gestión diaria de caja.</p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <a href="matriculas.php" class="btn btn-outline-primary d-inline-flex align-items-center gap-2 px-3 py-2" style="border-radius: 10px;">
                <i class="bi bi-person-plus-fill"></i> Registrar Matrícula
            </a>
            <a href="pagos.php" class="btn btn-primary d-inline-flex align-items-center gap-2 px-3 py-2" style="border-radius: 10px;">
                <i class="bi bi-wallet2"></i> Registrar Pago
            </a>
        </div>
    </div>

    <!-- Fila 1: Tarjetas Métricas KPI -->
    <div class="row g-3 mb-4">
        <!-- Estudiantes Activos -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="stat-card">
                <div class="stat-icon" style="background-color: #eff6ff; color: #2563eb;">
                    <i class="bi bi-mortarboard-fill"></i>
                </div>
                <div>
                    <div class="stat-label">Estudiantes Activos</div>
                    <div class="stat-value"><?= number_format($metrics['active_students']) ?></div>
                </div>
            </div>
        </div>

        <!-- Padres y Familias -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="stat-card">
                <div class="stat-icon" style="background-color: #ecfdf5; color: #059669;">
                    <i class="bi bi-people-fill"></i>
                </div>
                <div>
                    <div class="stat-label">Padres y Familias</div>
                    <div class="stat-value"><?= number_format($metrics['total_parents'] ?? 0) ?></div>
                </div>
            </div>
        </div>

        <!-- Ingresos del Mes -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="stat-card">
                <div class="stat-icon" style="background-color: #f0fdf4; color: #16a34a;">
                    <i class="bi bi-wallet2"></i>
                </div>
                <div>
                    <div class="stat-label">Ingresos de <?= htmlspecialchars($metrics['current_month_name']) ?></div>
                    <div class="stat-value">S/ <?= number_format($metrics['monthly_income'], 2) ?></div>
                </div>
            </div>
        </div>

        <!-- Deudas Pendientes -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="stat-card">
                <div class="stat-icon" style="background-color: #fffbeb; color: #d97706;">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                </div>
                <div>
                    <div class="stat-label">Cuentas con Deuda</div>
                    <div class="stat-value"><?= number_format($metrics['pending_debts']) ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Fila 2: Barra Compacta de Acciones Frecuentes (Atajos Rápidos) -->
    <div class="quick-actions-bar">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="d-flex align-items-center gap-2">
                <span class="badge bg-warning-subtle text-warning border border-warning-subtle p-2 rounded-3">
                    <i class="bi bi-lightning-charge-fill fs-6"></i>
                </span>
                <div>
                    <h2 class="h6 fw-bold mb-0 text-dark">Acciones Frecuentes</h2>
                    <span class="text-muted small">Atajos directos para el trabajo operativo</span>
                </div>
            </div>
            <div class="d-flex flex-wrap align-items-center gap-2">
                <a href="matriculas.php" class="btn btn-primary d-inline-flex align-items-center gap-1.5 px-3 py-2 fw-semibold" style="border-radius: 10px; font-size: 0.9rem;">
                    <i class="bi bi-person-plus-fill"></i> Nueva Matrícula
                </a>
                <a href="pagos.php" class="btn btn-success d-inline-flex align-items-center gap-1.5 px-3 py-2 fw-semibold" style="border-radius: 10px; font-size: 0.9rem;">
                    <i class="bi bi-wallet2"></i> Cobrar en Caja
                </a>
                <a href="nomina_matriculas.php" class="btn btn-outline-primary d-inline-flex align-items-center gap-1.5 px-3 py-2 fw-semibold" style="border-radius: 10px; font-size: 0.9rem;">
                    <i class="bi bi-printer"></i> Nómina Oficial
                </a>
                <a href="reporte_pagos.php" class="btn btn-outline-warning text-dark d-inline-flex align-items-center gap-1.5 px-3 py-2 fw-semibold" style="border-radius: 10px; font-size: 0.9rem;">
                    <i class="bi bi-receipt-cutoff"></i> Reporte de Caja
                </a>
                <a href="reporte_calificaciones.php" class="btn btn-outline-info text-dark d-inline-flex align-items-center gap-1.5 px-3 py-2 fw-semibold" style="border-radius: 10px; font-size: 0.9rem;">
                    <i class="bi bi-graph-up-arrow"></i> Gráficas de Notas
                </a>
                <a href="cierre_academico.php" class="btn btn-outline-danger text-dark d-inline-flex align-items-center gap-1.5 px-3 py-2 fw-semibold" style="border-radius: 10px; font-size: 0.9rem;">
                    <i class="bi bi-award"></i> Cierre y Vacacional
                </a>
                <a href="padron_apoderados.php" class="btn btn-outline-secondary d-inline-flex align-items-center gap-1.5 px-3 py-2 fw-semibold" style="border-radius: 10px; font-size: 0.9rem;">
                    <i class="bi bi-people"></i> Padrón Familias
                </a>
            </div>
        </div>
    </div>

    <!-- Fila 3: Gráficos de Resumen Escolar y Financiero -->
    <div class="row g-3 mb-4">
        <!-- Resumen Académico: Distribución por Nivel -->
        <div class="col-12 col-lg-7">
            <div class="dashboard-card">
                <div class="dashboard-card-header">
                    <div>
                        <h2 class="h6 fw-bold mb-0 text-dark d-flex align-items-center gap-2">
                            <i class="bi bi-pie-chart-fill text-primary"></i> Población Escolar por Nivel
                        </h2>
                        <span class="text-muted small">Alumnos matriculados en el año lectivo <?= htmlspecialchars((string)$metrics['current_year']) ?></span>
                    </div>
                    <a href="estudiantes.php" class="btn btn-sm btn-outline-primary d-inline-flex align-items-center gap-1" style="border-radius: 8px;">
                        Ver Directorio &rarr;
                    </a>
                </div>

                <!-- Barra de Distribución Visual -->
                <div class="progress rounded-pill mb-3" style="height: 14px; background-color: #f1f5f9;">
                    <div class="progress-bar bg-warning" role="progressbar" style="width: <?= $dist['Inicial']['pct'] ?>%;" title="Inicial: <?= $dist['Inicial']['count'] ?> (<?= $dist['Inicial']['pct'] ?>%)"></div>
                    <div class="progress-bar bg-danger" role="progressbar" style="width: <?= $dist['Primaria']['pct'] ?>%;" title="Primaria: <?= $dist['Primaria']['count'] ?> (<?= $dist['Primaria']['pct'] ?>%)"></div>
                    <div class="progress-bar bg-primary" role="progressbar" style="width: <?= $dist['Secundaria']['pct'] ?>%;" title="Secundaria: <?= $dist['Secundaria']['count'] ?> (<?= $dist['Secundaria']['pct'] ?>%)"></div>
                </div>

                <!-- Chips de Conteos por Nivel -->
                <div class="row g-2 mb-3">
                    <div class="col-4">
                        <div class="p-2.5 rounded-3 border text-center" style="background-color: #fffbeb; border-color: #fde68a !important;">
                            <span class="badge bg-warning text-dark mb-1">Inicial</span>
                            <div class="fw-bold fs-5 text-dark"><?= number_format($dist['Inicial']['count']) ?></div>
                            <span class="text-muted" style="font-size: 0.78rem;"><?= $dist['Inicial']['pct'] ?>% alumnos</span>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="p-2.5 rounded-3 border text-center" style="background-color: #fef2f2; border-color: #fecaca !important;">
                            <span class="badge bg-danger mb-1">Primaria</span>
                            <div class="fw-bold fs-5 text-dark"><?= number_format($dist['Primaria']['count']) ?></div>
                            <span class="text-muted" style="font-size: 0.78rem;"><?= $dist['Primaria']['pct'] ?>% alumnos</span>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="p-2.5 rounded-3 border text-center" style="background-color: #eff6ff; border-color: #bfdbfe !important;">
                            <span class="badge bg-primary mb-1">Secundaria</span>
                            <div class="fw-bold fs-5 text-dark"><?= number_format($dist['Secundaria']['count']) ?></div>
                            <span class="text-muted" style="font-size: 0.78rem;"><?= $dist['Secundaria']['pct'] ?>% alumnos</span>
                        </div>
                    </div>
                </div>

                <!-- Top Aulas con mayor población -->
                <?php if (!empty($metrics['students_by_grade'])): ?>
                    <div class="pt-2 border-top">
                        <span class="text-muted small fw-bold text-uppercase d-block mb-2">Grados con mayor alumnado activo:</span>
                        <div class="d-flex flex-wrap gap-1.5">
                            <?php foreach (array_slice($metrics['students_by_grade'], 0, 5) as $sg): ?>
                                <span class="badge bg-light text-dark border px-2.5 py-1.5" style="font-size: 0.82rem;">
                                    <strong><?= htmlspecialchars($sg['grade']) ?>:</strong> <?= number_format($sg['count']) ?> alumnos
                                </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Resumen Financiero: Balance de Cobranza y Caja -->
        <div class="col-12 col-lg-5">
            <div class="dashboard-card d-flex flex-column justify-content-between">
                <div>
                    <div class="dashboard-card-header">
                        <div>
                            <h2 class="h6 fw-bold mb-0 text-dark d-flex align-items-center gap-2">
                                <i class="bi bi-cash-stack text-success"></i> Control de Caja y Cobranza
                            </h2>
                            <span class="text-muted small">Estado global de cuentas y recaudación</span>
                        </div>
                        <a href="reporte_pagos.php" class="btn btn-sm btn-outline-warning text-dark d-inline-flex align-items-center gap-1" style="border-radius: 8px;">
                            Arqueo &rarr;
                        </a>
                    </div>

                    <!-- Métricas de cobranza -->
                    <div class="d-flex justify-content-between align-items-end mb-2">
                        <div>
                            <span class="text-muted small d-block">Total Cobrado</span>
                            <span class="fs-4 fw-bold text-success">S/ <?= number_format($cobranza['cobrado'], 2) ?></span>
                        </div>
                        <div class="text-end">
                            <span class="text-muted small d-block">Total Facturado</span>
                            <span class="fs-5 fw-semibold text-dark">S/ <?= number_format($cobranza['total_facturado'], 2) ?></span>
                        </div>
                    </div>

                    <!-- Barra de progreso cobranza -->
                    <div class="progress mb-3" style="height: 10px; border-radius: 6px; background-color: #e2e8f0;">
                        <div class="progress-bar bg-success" role="progressbar" style="width: <?= min(100, $cobranza['percentage']) ?>%;" aria-valuenow="<?= $cobranza['percentage'] ?>" aria-valuemin="0" aria-valuemax="100"></div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center p-2 rounded-3 bg-light border mb-3">
                        <span class="small text-muted"><i class="bi bi-check-circle-fill text-success me-1"></i> Porcentaje Recaudado</span>
                        <span class="badge bg-success fs-6 fw-bold"><?= $cobranza['percentage'] ?>%</span>
                    </div>
                </div>

                <!-- Historial de ingresos de los últimos meses -->
                <?php if (!empty($metrics['six_months']['history'])): ?>
                    <div class="pt-2 border-top">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-muted small fw-bold text-uppercase">Ingresos últimos meses:</span>
                            <span class="text-muted small">Total: <strong>S/ <?= number_format($metrics['six_months']['total'], 2) ?></strong></span>
                        </div>
                        <div class="d-flex justify-content-between align-items-end gap-2 text-center" style="height: 80px;">
                            <?php foreach ($metrics['six_months']['history'] as $hm): 
                                $hPct = max(8, min(100, $hm['height_pct']));
                            ?>
                                <div class="chart-bar-col flex-grow-1" style="height: 80px;">
                                    <span class="text-muted" style="font-size: 0.68rem;">S/ <?= number_format($hm['amount'], 0) ?></span>
                                    <div class="chart-bar-fill <?= !empty($hm['is_current']) ? 'current' : '' ?>" style="height: <?= $hPct ?>%;"></div>
                                    <span class="fw-semibold text-secondary" style="font-size: 0.72rem;"><?= htmlspecialchars($hm['label']) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Fila 4: Actividad Reciente en Tiempo Real -->
    <div class="row g-3 mb-4">
        <!-- Últimos Pagos Registrados -->
        <div class="col-12 col-lg-6">
            <div class="dashboard-card">
                <div class="dashboard-card-header">
                    <div>
                        <h2 class="h6 fw-bold mb-0 text-dark d-flex align-items-center gap-2">
                            <i class="bi bi-clock-history text-success"></i> Últimos Cobros en Caja
                        </h2>
                        <span class="text-muted small">Transacciones recientes registradas</span>
                    </div>
                    <a href="pagos.php" class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1" style="border-radius: 8px;">
                        Ver todos &rarr;
                    </a>
                </div>

                <?php if (!empty($metrics['recent_payments'])): ?>
                    <div class="table-responsive">
                        <table class="table table-hover activity-table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Fecha</th>
                                    <th>Pagador / Estudiante</th>
                                    <th>Concepto</th>
                                    <th class="text-end">Monto</th>
                                    <th class="text-center" style="width: 45px;">Recibo</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($metrics['recent_payments'] as $rp): ?>
                                    <tr>
                                        <td class="text-nowrap text-muted" style="font-size: 0.82rem;">
                                            <?= htmlspecialchars($rp['date_formatted']) ?>
                                        </td>
                                        <td>
                                            <div class="fw-semibold text-dark text-truncate" style="max-width: 170px;">
                                                <?= htmlspecialchars($rp['pagador']) ?>
                                            </div>
                                            <?php if (!empty($rp['estudiante']) && $rp['estudiante'] !== $rp['pagador']): ?>
                                                <small class="text-muted text-truncate d-block" style="max-width: 170px;">
                                                    <?= htmlspecialchars($rp['estudiante']) ?>
                                                </small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-dark border" style="font-size: 0.8rem;">
                                                <?= htmlspecialchars($rp['concepto']) ?>
                                            </span>
                                        </td>
                                        <td class="text-end text-nowrap fw-bold text-success">
                                            S/ <?= number_format($rp['amount'], 2) ?>
                                        </td>
                                        <td class="text-center">
                                            <a href="recibo_pago.php?id=<?= (int)$rp['id'] ?>" target="_blank" rel="opener" class="btn btn-sm btn-outline-primary" style="padding: 2px 7px; border-radius: 6px;" title="Imprimir Recibo de Caja">
                                                <i class="bi bi-printer"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="text-center text-muted py-4">No hay pagos registrados recientemente.</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Últimas Matrículas Registradas -->
        <div class="col-12 col-lg-6">
            <div class="dashboard-card">
                <div class="dashboard-card-header">
                    <div>
                        <h2 class="h6 fw-bold mb-0 text-dark d-flex align-items-center gap-2">
                            <i class="bi bi-mortarboard text-primary"></i> Últimas Matrículas Asentadas
                        </h2>
                        <span class="text-muted small">Inscripciones y ratificaciones del periodo</span>
                    </div>
                    <a href="matriculas.php" class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1" style="border-radius: 8px;">
                        Ver todas &rarr;
                    </a>
                </div>

                <?php if (!empty($metrics['recent_matriculas'])): ?>
                    <div class="table-responsive">
                        <table class="table table-hover activity-table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Fecha</th>
                                    <th>Estudiante</th>
                                    <th>Nivel / Grado</th>
                                    <th>Estado</th>
                                    <th class="text-center" style="width: 45px;">Ficha</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($metrics['recent_matriculas'] as $rm): ?>
                                    <tr>
                                        <td class="text-nowrap text-muted" style="font-size: 0.82rem;">
                                            <?= htmlspecialchars($rm['date_formatted']) ?>
                                        </td>
                                        <td>
                                            <div class="fw-semibold text-dark text-truncate" style="max-width: 170px;">
                                                <?= htmlspecialchars($rm['estudiante']) ?>
                                            </div>
                                            <small class="text-muted">DNI: <?= htmlspecialchars($rm['dni']) ?></small>
                                        </td>
                                        <td class="text-nowrap">
                                            <span class="badge bg-light text-dark border" style="font-size: 0.8rem;">
                                                <?= htmlspecialchars($rm['nivel']) ?> · <?= htmlspecialchars($rm['grado']) ?> "<?= htmlspecialchars($rm['seccion']) ?>"
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle" style="font-size: 0.78rem;">
                                                <?= htmlspecialchars($rm['estado']) ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <a href="ficha_matricula.php?estudiante_id=<?= (int)$rm['estudiante_id'] ?>" target="_blank" rel="opener" class="btn btn-sm btn-outline-primary" style="padding: 2px 7px; border-radius: 6px;" title="Imprimir Ficha de Matrícula">
                                                <i class="bi bi-printer"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="text-center text-muted py-4">No hay matrículas registradas recientemente.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>
</body>
</html>
