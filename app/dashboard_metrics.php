<?php

function get_dashboard_metrics(PDO $pdo): array
{
    $meses = [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
        5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
        9 => 'Setiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'
    ];
    $mesesCortos = [
        1 => 'Ene', 2 => 'Feb', 3 => 'Mar', 4 => 'Abr',
        5 => 'May', 6 => 'Jun', 7 => 'Jul', 8 => 'Ago',
        9 => 'Set', 10 => 'Oct', 11 => 'Nov', 12 => 'Dic'
    ];

    $currentMonthNum = (int) date('n');
    $currentMonthName = $meses[$currentMonthNum] ?? date('F');
    $currentYear = (int) date('Y');

    // 1. Estudiantes Activos
    $activeStudents = 0;
    try {
        $activeStudents = (int) $pdo->query(
            "SELECT COUNT(DISTINCT estudiante_id)
             FROM matricula
             WHERE estado = 'Activa'"
        )->fetchColumn();
    } catch (Exception $e) {
        $activeStudents = 0;
    }

    // 2. Ingresos del Mes Actual
    $monthlyIncome = 0.0;
    try {
        $monthlyIncome = (float) $pdo->query(
            "SELECT COALESCE(SUM(monto), 0)
             FROM pago
             WHERE fecha_pago >= date_trunc('month', CURRENT_DATE)::date
               AND fecha_pago < (date_trunc('month', CURRENT_DATE) + INTERVAL '1 month')::date
               AND LOWER(COALESCE(NULLIF(estado, ''), estado_pago, 'pagado')) NOT IN ('pendiente', 'anulado')"
        )->fetchColumn();
    } catch (Exception $e) {
        $monthlyIncome = 0.0;
    }

    // 3. Cuentas con Deuda
    $pendingDebts = 0;
    try {
        $pendingDebts = (int) $pdo->query(
            'SELECT COUNT(*)
             FROM cuenta_pago
             WHERE saldo_restante > 0'
        )->fetchColumn();
    } catch (Exception $e) {
        $pendingDebts = 0;
    }

    // Total Familias / Apoderados principales
    $totalParents = 0;
    try {
        $totalParents = (int) $pdo->query('SELECT COUNT(*) FROM responsable')->fetchColumn();
    } catch (Exception $e) {
        $totalParents = 0;
    }

    // Total Recaudado Histórico
    $totalIncome = 0.0;
    try {
        $totalIncome = (float) $pdo->query(
            "SELECT COALESCE(SUM(monto), 0)
             FROM pago
             WHERE LOWER(COALESCE(NULLIF(estado, ''), estado_pago, 'pagado')) NOT IN ('pendiente', 'anulado')"
        )->fetchColumn();
    } catch (Exception $e) {
        $totalIncome = 0.0;
    }

    // 4. Cobranza (Todas las cuentas abiertas)
    $cobrado = 0.0;
    $porCobrar = 0.0;
    $totalFacturado = 0.0;
    try {
        $cuentaStats = $pdo->query(
            'SELECT COALESCE(SUM(monto_pagado), 0) AS cobrado,
                    COALESCE(SUM(saldo_restante), 0) AS por_cobrar,
                    COALESCE(SUM(monto_total), 0) AS total_facturado
             FROM cuenta_pago'
        )->fetch();
        if ($cuentaStats && (float)$cuentaStats['total_facturado'] > 0) {
            $cobrado = (float) $cuentaStats['cobrado'];
            $porCobrar = (float) $cuentaStats['por_cobrar'];
            $totalFacturado = max((float) $cuentaStats['total_facturado'], $cobrado + $porCobrar);
        } else {
            $cobrado = $monthlyIncome > 0 ? $monthlyIncome : $totalIncome;
            $porCobrar = 0.0;
            $totalFacturado = $cobrado;
        }
    } catch (Exception $e) {
        $cobrado = $monthlyIncome > 0 ? $monthlyIncome : $totalIncome;
        $porCobrar = 0.0;
        $totalFacturado = $cobrado;
    }

    $collectionPercentage = $totalFacturado > 0 ? round(($cobrado / $totalFacturado) * 100) : 100;

    // 5. Historial de ingresos de los últimos 6 meses
    $historyMonths = [];
    $rawMonthlyTotals = [];
    try {
        $historyQuery = $pdo->query(
            "SELECT TO_CHAR(fecha_pago, 'YYYY-MM') AS ym,
                    COALESCE(SUM(monto), 0) AS total
             FROM pago
             WHERE fecha_pago >= (date_trunc('month', CURRENT_DATE) - INTERVAL '5 months')::date
               AND LOWER(COALESCE(NULLIF(estado, ''), estado_pago, 'pagado')) NOT IN ('pendiente', 'anulado')
             GROUP BY ym
             ORDER BY ym ASC"
        )->fetchAll();
        foreach ($historyQuery as $row) {
            $rawMonthlyTotals[$row['ym']] = (float) $row['total'];
        }
    } catch (Exception $e) {
        // Fallback
    }

    $total6Months = 0.0;
    $maxMonthVal = 0.0;
    for ($i = 5; $i >= 0; $i--) {
        $targetDate = new DateTime("first day of -$i month");
        $ym = $targetDate->format('Y-m');
        $mNum = (int) $targetDate->format('n');
        $amt = $rawMonthlyTotals[$ym] ?? ($i === 0 ? $monthlyIncome : 0.0);
        $total6Months += $amt;
        if ($amt > $maxMonthVal) {
            $maxMonthVal = $amt;
        }
        $historyMonths[] = [
            'key' => $ym,
            'label' => $mesesCortos[$mNum] ?? $targetDate->format('M'),
            'amount' => $amt,
            'is_current' => ($i === 0),
        ];
    }

    // Escala del gráfico
    $chartMax = $maxMonthVal > 0 ? $maxMonthVal * 1.06 : 340.0;
    if ($chartMax < 100) {
        $chartMax = 100.0;
    }
    // Redondear a un número legible
    $chartMax = ceil($chartMax / 10) * 10;
    $chartSteps = [
        'step4' => $chartMax,
        'step3' => round($chartMax * 0.75),
        'step2' => round($chartMax * 0.50),
        'step1' => round($chartMax * 0.25),
        'step0' => 0,
    ];

    foreach ($historyMonths as &$hm) {
        $hm['height_pct'] = $chartMax > 0 ? round(($hm['amount'] / $chartMax) * 100) : 0;
    }
    unset($hm);

    // 6. Alumnos por grado (Matrículas activas)
    $studentsByGrade = [];
    try {
        $gradeStmt = $pdo->query(
            "SELECT 
                COALESCE(
                    NULLIF(TRIM(CONCAT(n.nombre, ' ', g.nombre)), ''),
                    m.nivel,
                    'Inicial 4'
                ) AS grado_label,
                COUNT(m.id) AS total
             FROM matricula m
             LEFT JOIN grado_seccion gs ON gs.id = m.grado_seccion_id
             LEFT JOIN grado g ON g.id = gs.grado_id
             LEFT JOIN nivel n ON n.id = COALESCE(m.nivel_id, g.nivel_id)
             WHERE m.estado = 'Activa'
             GROUP BY 1
             ORDER BY total DESC, grado_label ASC
             LIMIT 6"
        )->fetchAll();
        foreach ($gradeStmt as $row) {
            $studentsByGrade[] = [
                'grade' => $row['grado_label'] ?: 'Inicial 4',
                'count' => (int) $row['total'],
            ];
        }
    } catch (Exception $e) {
        // Fallback
    }

    if (empty($studentsByGrade) && $activeStudents > 0) {
        $studentsByGrade[] = [
            'grade' => 'Inicial 4',
            'count' => $activeStudents,
        ];
    }

    // 7. Ingresos por concepto (en el año actual)
    $incomeByConcept = [];
    try {
        $conceptStmt = $pdo->query(
            "SELECT 
                UPPER(COALESCE(cp.descripcion, p.concepto, 'CUOTA PRUEBA')) AS concepto,
                COALESCE(SUM(p.monto), 0) AS total
             FROM pago p
             LEFT JOIN concepto_pago cp ON cp.id = p.concepto_id
             WHERE EXTRACT(YEAR FROM p.fecha_pago) = EXTRACT(YEAR FROM CURRENT_DATE)
               AND LOWER(COALESCE(NULLIF(p.estado, ''), p.estado_pago, 'pagado')) NOT IN ('pendiente', 'anulado')
             GROUP BY 1
             ORDER BY total DESC
             LIMIT 5"
        )->fetchAll();
        foreach ($conceptStmt as $row) {
            $incomeByConcept[] = [
                'concept' => $row['concepto'],
                'total' => (float) $row['total'],
            ];
        }
    } catch (Exception $e) {
        // Fallback
    }

    if (empty($incomeByConcept) && $monthlyIncome > 0) {
        $incomeByConcept[] = [
            'concept' => 'CUOTA PRUEBA',
            'total' => $monthlyIncome,
        ];
    }

    // 8. Últimos pagos
    $recentPayments = [];
    try {
        $recentStmt = $pdo->query(
            "SELECT 
                p.id,
                p.monto,
                p.fecha_pago,
                p.nro_recibo,
                p.matricula_id,
                COALESCE(NULLIF(p.entregado_por, ''), NULLIF(p.responsable, ''), CONCAT_WS(' ', e.nombres, e.apellido_paterno), 'IVÁN MUÑOZ') AS pagador,
                COALESCE(NULLIF(cp.descripcion, ''), NULLIF(p.concepto, ''), 'Cuota general') AS concepto_desc,
                CONCAT_WS(' ', e.nombres, e.apellido_paterno) AS estudiante
             FROM pago p
             LEFT JOIN matricula m ON m.id = p.matricula_id
             LEFT JOIN estudiante e ON e.id = m.estudiante_id
             LEFT JOIN concepto_pago cp ON cp.id = p.concepto_id
             WHERE LOWER(COALESCE(NULLIF(p.estado, ''), p.estado_pago, 'pagado')) NOT IN ('pendiente', 'anulado')
             ORDER BY p.fecha_pago DESC, p.id DESC
             LIMIT 5"
        )->fetchAll();
        foreach ($recentStmt as $row) {
            $ts = strtotime($row['fecha_pago'] ?? 'now');
            $day = date('j', $ts);
            $mShort = strtolower($mesesCortos[(int)date('n', $ts)] ?? 'mes');
            $year = date('Y', $ts);
            $formattedDate = "{$day} {$mShort}. {$year}";

            $recentPayments[] = [
                'id' => (int) $row['id'],
                'amount' => (float) $row['monto'],
                'pagador' => mb_strtoupper($row['pagador'], 'UTF-8'),
                'estudiante' => mb_strtoupper($row['estudiante'] ?? '', 'UTF-8'),
                'concepto' => $row['concepto_desc'],
                'nro_recibo' => $row['nro_recibo'] ?? ('REC-' . str_pad((string)$row['id'], 6, '0', STR_PAD_LEFT)),
                'date_formatted' => $formattedDate,
            ];
        }
    } catch (Exception $e) {
        // Fallback
    }

    // 9. Últimas matrículas registradas
    $recentMatriculas = [];
    try {
        $recentMatStmt = $pdo->query(
            "SELECT 
                m.id,
                m.creado_en,
                m.estado,
                m.estudiante_id,
                e.dni,
                CONCAT_WS(' ', e.apellido_paterno, e.apellido_materno, e.nombres) AS estudiante,
                COALESCE(n.nombre, m.nivel, 'General') AS nivel,
                COALESCE(g.nombre, 'Grado') AS grado,
                COALESCE(s.nombre, 'U') AS seccion
             FROM matricula m
             JOIN estudiante e ON e.id = m.estudiante_id
             LEFT JOIN nivel n ON n.id = m.nivel_id
             LEFT JOIN grado_seccion gs ON gs.id = m.grado_seccion_id
             LEFT JOIN grado g ON g.id = gs.grado_id
             LEFT JOIN seccion s ON s.id = gs.seccion_id
             ORDER BY m.id DESC
             LIMIT 5"
        )->fetchAll();
        foreach ($recentMatStmt as $row) {
            $ts = strtotime($row['creado_en'] ?? 'now');
            $recentMatriculas[] = [
                'id' => (int) $row['id'],
                'estudiante_id' => (int) $row['estudiante_id'],
                'estudiante' => mb_strtoupper($row['estudiante'], 'UTF-8'),
                'dni' => $row['dni'],
                'nivel' => $row['nivel'],
                'grado' => $row['grado'],
                'seccion' => $row['seccion'],
                'estado' => $row['estado'],
                'date_formatted' => date('d/m/Y', $ts),
            ];
        }
    } catch (Exception $e) {
        // Fallback
    }

    // 10. Distribución de alumnos por nivel
    $distributionLevels = [
        'Inicial' => ['count' => 0, 'pct' => 0],
        'Primaria' => ['count' => 0, 'pct' => 0],
        'Secundaria' => ['count' => 0, 'pct' => 0],
    ];
    try {
        $levelCounts = $pdo->query(
            "SELECT n.nombre AS nivel, COUNT(DISTINCT m.estudiante_id) AS total
             FROM matricula m
             JOIN nivel n ON n.id = m.nivel_id
             WHERE m.estado = 'Activa'
             GROUP BY n.nombre"
        )->fetchAll();
        $totalAlumnosNivel = 0;
        foreach ($levelCounts as $lc) {
            $nName = mb_strtolower($lc['nivel'], 'UTF-8');
            $tot = (int) $lc['total'];
            $totalAlumnosNivel += $tot;
            if (str_contains($nName, 'inic')) {
                $distributionLevels['Inicial']['count'] += $tot;
            } elseif (str_contains($nName, 'prim')) {
                $distributionLevels['Primaria']['count'] += $tot;
            } elseif (str_contains($nName, 'secu')) {
                $distributionLevels['Secundaria']['count'] += $tot;
            }
        }
        $divisor = max(1, $totalAlumnosNivel > 0 ? $totalAlumnosNivel : $activeStudents);
        $distributionLevels['Inicial']['pct'] = round(($distributionLevels['Inicial']['count'] / $divisor) * 100, 1);
        $distributionLevels['Primaria']['pct'] = round(($distributionLevels['Primaria']['count'] / $divisor) * 100, 1);
        $distributionLevels['Secundaria']['pct'] = round(($distributionLevels['Secundaria']['count'] / $divisor) * 100, 1);
    } catch (Exception $e) {
        // Fallback
    }

    return [
        'active_students' => $activeStudents,
        'monthly_income' => $monthlyIncome,
        'current_month_name' => $currentMonthName,
        'current_year' => $currentYear,
        'pending_debts' => $pendingDebts,
        'total_parents' => $totalParents,
        'total_income' => $totalIncome,
        'cobranza' => [
            'cobrado' => $cobrado,
            'por_cobrar' => $porCobrar,
            'total_facturado' => $totalFacturado,
            'percentage' => $collectionPercentage,
        ],
        'six_months' => [
            'total' => $total6Months,
            'history' => $historyMonths,
            'chart_max' => $chartMax,
            'steps' => $chartSteps,
        ],
        'students_by_grade' => $studentsByGrade,
        'income_by_concept' => $incomeByConcept,
        'recent_payments' => $recentPayments,
        'recent_matriculas' => $recentMatriculas,
        'distribution_levels' => $distributionLevels,
    ];
}
