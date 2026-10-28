<?php
function get_dashboard_metrics(PDO $pdo): array
{
    $activeStudents = (int) $pdo->query(
        "SELECT COUNT(DISTINCT estudiante_id)
         FROM matricula
         WHERE estado = 'Activa'"
    )->fetchColumn();

    $monthlyIncome = (float) $pdo->query(
        "SELECT COALESCE(SUM(monto), 0)
         FROM pago
         WHERE fecha_pago >= date_trunc('month', CURRENT_DATE)::date
           AND fecha_pago < (date_trunc('month', CURRENT_DATE) + INTERVAL '1 month')::date"
    )->fetchColumn();

    $pendingDebts = (int) $pdo->query(
        'SELECT COUNT(*)
         FROM cuenta_pago
         WHERE saldo_restante > 0'
    )->fetchColumn();

    return [
        'active_students' => $activeStudents,
        'monthly_income' => $monthlyIncome,
        'pending_debts' => $pendingDebts,
    ];
}
