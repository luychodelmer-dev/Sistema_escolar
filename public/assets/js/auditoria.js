/**
 * Sistema Escolar - Script de Panel de Auditoría
 */
document.addEventListener('DOMContentLoaded', () => {
    const auditFilters = document.getElementById('audit-filters');
    const auditRows = document.getElementById('audit-rows');
    const activitySummary = document.getElementById('activitySummary');
    let auditRequest;
    let auditTimer;

    async function loadActivityChart() {
        const canvas = document.getElementById('activityChart');
        if (!canvas || typeof Chart === 'undefined') return;

        try {
            const response = await fetch('auditoria.php?ajax=grafico', {
                headers: { Accept: 'application/json' }
            });
            if (!response.ok) throw new Error('No se pudo cargar el gráfico de actividad.');
            const activity = await response.json();
            const highestIndex = activity.data.reduce(
                (highest, count, index) => highest === -1 || count > activity.data[highest] ? index : highest,
                -1
            );
            if (activitySummary) {
                activitySummary.textContent = highestIndex >= 0 && activity.data[highestIndex] > 0
                    ? `Más cambios: ${activity.labels[highestIndex]} (${activity.data[highestIndex]})`
                    : 'Aún no hay cambios registrados.';
            }
            new Chart(canvas, {
                type: 'bar',
                data: {
                    labels: activity.labels,
                    datasets: [{
                        label: 'Acciones registradas',
                        data: activity.data,
                        backgroundColor: '#3b82f6'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
                }
            });
        } catch (error) {
            if (activitySummary) {
                activitySummary.textContent = 'Error al cargar el gráfico de actividad.';
            }
            console.error(error);
        }
    }

    async function refreshAudit() {
        if (!auditFilters || !auditRows) return;
        auditRequest?.abort();
        auditRequest = new AbortController();
        const query = new URLSearchParams(new FormData(auditFilters));
        query.set('ajax', 'tabla');
        try {
            const response = await fetch(`auditoria.php?${query}`, {
                headers: { Accept: 'text/html' },
                signal: auditRequest.signal
            });
            if (!response.ok) throw new Error('No se pudo actualizar la auditoría.');
            auditRows.innerHTML = await response.text();
        } catch (error) {
            if (error.name !== 'AbortError') {
                auditRows.innerHTML = '<tr><td colspan="6" class="text-danger text-center">Error al cargar los registros.</td></tr>';
                console.error(error);
            }
        }
    }

    loadActivityChart();

    if (auditFilters) {
        auditFilters.addEventListener('input', () => {
            clearTimeout(auditTimer);
            auditTimer = setTimeout(refreshAudit, 250);
        });
        auditFilters.addEventListener('change', refreshAudit);
    }
});
