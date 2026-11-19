/**
 * Sistema Escolar - Script de Gráfica Consolidada de Calificaciones y Filtros
 */

// Manejo reactivo de filtro de Nivel (llamado desde onchange en select)
async function onNivelChange(nivelId) {
    const gradoSelect = document.getElementById('grado_id');
    if (!gradoSelect) return;

    try {
        gradoSelect.replaceChildren(new Option('Cargando grados...', ''));
        const url = nivelId 
            ? `reporte_calificaciones.php?ajax=grados&id=${encodeURIComponent(nivelId)}`
            : `reporte_calificaciones.php?ajax=grados`;
        const res = await fetch(url, {
            headers: { Accept: 'application/json' }
        });
        const grados = await res.json();
        gradoSelect.replaceChildren(new Option('-- Todos los grados --', ''));
        grados.forEach(g => {
            gradoSelect.add(new Option(g.nombre, g.id));
        });

        // Consolidar todos los grados del nivel o de la institución
        gradoSelect.value = '';
        const form = document.getElementById('formFiltroReporte');
        if (form) form.submit();
    } catch (err) {
        console.error('Error al cargar grados:', err);
    }
}
window.onNivelChange = onNivelChange;

document.addEventListener('DOMContentLoaded', () => {
    // Registrar plugin de etiquetas de datos de Chart.js si está presente
    if (typeof Chart !== 'undefined' && typeof ChartDataLabels !== 'undefined') {
        Chart.register(ChartDataLabels);
    }

    const configEl = document.getElementById('reporte-calificaciones-data');
    let chartConfig = { labelsCursos: [], dataAD: [], dataA: [], dataB: [], dataC: [] };
    if (configEl) {
        try {
            chartConfig = JSON.parse(configEl.textContent || '{}');
        } catch (e) {
            console.error('Error al parsear datos de reporte_calificaciones:', e);
        }
    }

    const labelsCursos = chartConfig.labelsCursos || [];
    const dataAD = chartConfig.dataAD || [];
    const dataA = chartConfig.dataA || [];
    const dataB = chartConfig.dataB || [];
    const dataC = chartConfig.dataC || [];

    const canvas = document.getElementById('chartCalificacionesConsolidado');
    if (!canvas || typeof Chart === 'undefined') return;

    const ctx = canvas.getContext('2d');
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: labelsCursos,
            datasets: [
                {
                    label: 'AD (Logro Destacado)',
                    data: dataAD,
                    borderColor: '#2563eb', // Azul
                    backgroundColor: '#2563eb',
                    borderWidth: 2.5,
                    tension: 0.35,
                    pointRadius: 4.5,
                    pointHoverRadius: 7,
                    pointBackgroundColor: '#ffffff',
                    pointBorderColor: '#2563eb',
                    pointBorderWidth: 2.5,
                    datalabels: {
                        align: 'top',
                        anchor: 'center',
                        offset: 4,
                        color: '#1d4ed8',
                        font: { weight: 'bold', size: 11 },
                        formatter: function(val) { return val > 0 ? val : ''; }
                    }
                },
                {
                    label: 'A (Logro Esperado)',
                    data: dataA,
                    borderColor: '#16a34a', // Verde
                    backgroundColor: '#16a34a',
                    borderWidth: 2.5,
                    tension: 0.35,
                    pointRadius: 4.5,
                    pointHoverRadius: 7,
                    pointBackgroundColor: '#ffffff',
                    pointBorderColor: '#16a34a',
                    pointBorderWidth: 2.5,
                    datalabels: {
                        align: 'top',
                        anchor: 'center',
                        offset: 4,
                        color: '#15803d',
                        font: { weight: 'bold', size: 11 },
                        formatter: function(val) { return val > 0 ? val : ''; }
                    }
                },
                {
                    label: 'B (En Proceso)',
                    data: dataB,
                    borderColor: '#f97316', // Naranja/Melón idéntico al Excel
                    backgroundColor: '#f97316',
                    borderWidth: 2.5,
                    tension: 0.35,
                    pointRadius: 4.5,
                    pointHoverRadius: 7,
                    pointBackgroundColor: '#ffffff',
                    pointBorderColor: '#f97316',
                    pointBorderWidth: 2.5,
                    datalabels: {
                        align: 'top',
                        anchor: 'center',
                        offset: 4,
                        color: '#c2410c',
                        font: { weight: 'bold', size: 11 },
                        formatter: function(val) { return val > 0 ? val : ''; }
                    }
                },
                {
                    label: 'C (En Inicio)',
                    data: dataC,
                    borderColor: '#dc2626', // Rojo
                    backgroundColor: '#dc2626',
                    borderWidth: 2.5,
                    tension: 0.35,
                    pointRadius: 4.5,
                    pointHoverRadius: 7,
                    pointBackgroundColor: '#ffffff',
                    pointBorderColor: '#dc2626',
                    pointBorderWidth: 2.5,
                    datalabels: {
                        align: 'bottom',
                        anchor: 'center',
                        offset: 4,
                        color: '#b91c1c',
                        font: { weight: 'bold', size: 11 },
                        formatter: function(val) { return val > 0 ? val : ''; }
                    }
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                mode: 'index',
                intersect: false,
            },
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        usePointStyle: true,
                        boxWidth: 9,
                        padding: 20,
                        font: { family: 'Plus Jakarta Sans', weight: '600', size: 12 }
                    }
                },
                tooltip: {
                    backgroundColor: 'rgba(15, 23, 42, 0.95)',
                    titleFont: { family: 'Plus Jakarta Sans', size: 13, weight: 'bold' },
                    bodyFont: { family: 'Plus Jakarta Sans', size: 12 },
                    padding: 12,
                    cornerRadius: 8,
                    callbacks: {
                        footer: function(tooltipItems) {
                            let sum = 0;
                            tooltipItems.forEach(function(item) {
                                sum += item.parsed.y;
                            });
                            return 'Total Evaluados: ' + sum + ' alumnos';
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    suggestedMax: 10,
                    grid: { color: '#f1f5f9' },
                    ticks: {
                        precision: 0,
                        font: { family: 'Plus Jakarta Sans', size: 11 }
                    },
                    title: {
                        display: true,
                        text: 'N° de Estudiantes',
                        font: { weight: 'bold', size: 11 }
                    }
                },
                x: {
                    grid: { color: '#f8fafc' },
                    ticks: {
                        font: { family: 'Plus Jakarta Sans', weight: '600', size: 11 },
                        maxRotation: 35,
                        minRotation: 0
                    }
                }
            }
        }
    });
});
