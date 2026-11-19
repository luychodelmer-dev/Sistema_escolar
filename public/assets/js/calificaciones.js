/**
 * Sistema Escolar - I.E. Divino Maestro
 * Módulo de Calificaciones: Lógica Interactiva, AJAX y Gráficos
 * /assets/js/calificaciones.js
 */

async function onNivelChange(nivelId) {
    const gradoSelect = document.getElementById('grado_id');
    const seccionSelect = document.getElementById('seccion_id');
    const cursoSelect = document.getElementById('curso_id');
    const badgeCursos = document.getElementById('badge-cursos-count');

    if (!nivelId) {
        // Modo "Todos los niveles" (Consolidado institucional)
        if (seccionSelect) {
            seccionSelect.replaceChildren(new Option('Todas las secciones', ''));
            seccionSelect.disabled = true;
        }
        if (cursoSelect) {
            cursoSelect.replaceChildren(new Option('-- Todos los cursos (Consolidado) --', ''));
            cursoSelect.disabled = true;
            cursoSelect.removeAttribute('required');
        }
        if (badgeCursos) {
            badgeCursos.innerHTML = '<i class="bi bi-bezier2 me-1"></i> Modo Consolidado (Todos los niveles)';
        }

        // Cargar todos los grados de todos los niveles en el select
        if (gradoSelect) {
            gradoSelect.replaceChildren(new Option('Cargando grados...', ''));
            try {
                const res = await fetch(`calificaciones.php?ajax=grados`, {
                    headers: { Accept: 'application/json' }
                });
                const grados = await res.json();
                gradoSelect.replaceChildren(new Option('-- Todos los grados --', ''));
                grados.forEach(g => {
                    gradoSelect.add(new Option(g.nombre, g.id));
                });
            } catch (e) {
                gradoSelect.replaceChildren(new Option('-- Todos los grados --', ''));
            }
            gradoSelect.value = '';
        }

        const form = document.getElementById('formFiltroCalificaciones');
        if (form) {
            form.submit();
        }
        return;
    }

    try {
        if (gradoSelect) gradoSelect.replaceChildren(new Option('Cargando grados...', ''));
        const res = await fetch(`calificaciones.php?ajax=grados&id=${encodeURIComponent(nivelId)}`, {
            headers: { Accept: 'application/json' }
        });
        const grados = await res.json();
        if (gradoSelect) {
            gradoSelect.replaceChildren(new Option('-- Todos los grados --', ''));
            grados.forEach(g => {
                gradoSelect.add(new Option(g.nombre, g.id));
            });
        }

        if (grados.length > 0 && gradoSelect) {
            gradoSelect.value = grados[0].id;
            await onGradoChange(grados[0].id);
        } else {
            if (seccionSelect) {
                seccionSelect.replaceChildren(new Option('Todas las secciones', ''));
                seccionSelect.disabled = true;
            }
            if (cursoSelect) {
                cursoSelect.replaceChildren(new Option('-- No hay cursos para este nivel --', ''));
                cursoSelect.disabled = true;
            }
            if (badgeCursos) badgeCursos.innerHTML = '<i class="bi bi-book me-1"></i> 0 cursos disponibles';
        }
    } catch (err) {
        console.error('Error al cargar grados:', err);
    }
}

async function onGradoChange(gradoId) {
    const seccionSelect = document.getElementById('seccion_id');
    const cursoSelect = document.getElementById('curso_id');
    const badgeCursos = document.getElementById('badge-cursos-count');

    if (!gradoId) {
        if (seccionSelect) {
            seccionSelect.replaceChildren(new Option('Todas las secciones', ''));
            seccionSelect.disabled = true;
        }
        if (cursoSelect) {
            cursoSelect.replaceChildren(new Option('-- Todos los cursos (Consolidado) --', ''));
            cursoSelect.disabled = true;
            cursoSelect.removeAttribute('required');
        }
        if (badgeCursos) {
            badgeCursos.innerHTML = '<i class="bi bi-bezier2 me-1"></i> Modo Consolidado (Todos los grados)';
        }
        const form = document.getElementById('formFiltroCalificaciones');
        if (form) {
            form.submit();
        }
        return;
    }

    try {
        if (seccionSelect) seccionSelect.disabled = false;
        if (cursoSelect) {
            cursoSelect.disabled = false;
            cursoSelect.setAttribute('required', 'required');
            cursoSelect.replaceChildren(new Option('Buscando cursos del aula...', ''));
        }
        const [resSec, resCur] = await Promise.all([
            fetch(`calificaciones.php?ajax=secciones&id=${encodeURIComponent(gradoId)}`, { headers: { Accept: 'application/json' } }),
            fetch(`calificaciones.php?ajax=cursos&id=${encodeURIComponent(gradoId)}`, { headers: { Accept: 'application/json' } })
        ]);

        const secciones = await resSec.json();
        const cursos = await resCur.json();

        // 1. Actualizar Secciones
        if (seccionSelect) {
            seccionSelect.replaceChildren(new Option('Todas las secciones', ''));
            secciones.forEach(s => {
                seccionSelect.add(new Option(`Sección "${s.nombre}"`, s.id));
            });
        }

        // 2. Actualizar Cursos
        if (cursoSelect) {
            cursoSelect.replaceChildren(new Option('-- Selecciona el curso --', ''));
            cursos.forEach(c => {
                cursoSelect.add(new Option(c.nombre, c.id));
            });
        }

        // 3. Actualizar Badge Contador de cursos existentes
        const totalCursos = cursos.length;
        if (badgeCursos) {
            badgeCursos.innerHTML = `<i class="bi bi-book me-1"></i> ${totalCursos} ${totalCursos === 1 ? 'curso disponible' : 'cursos disponibles'} para esta aula`;
        }

        if (totalCursos > 0 && cursoSelect) {
            cursoSelect.value = cursos[0].id;
        }

        // 4. Enviar formulario automáticamente para mostrar al instante a los alumnos y sus notas
        const form = document.getElementById('formFiltroCalificaciones');
        if (form) {
            form.submit();
        }
    } catch (err) {
        console.error('Error al cargar cursos o secciones:', err);
    }
}

function updateLiteralBadge(select) {
    const val = (select.value || '').toUpperCase();
    const matriculaId = select.getAttribute('data-matricula-id');
    const badgeContainer = document.getElementById('badge-' + matriculaId);

    select.classList.remove('nota-ad', 'nota-a', 'nota-b', 'nota-c');
    if (val === 'AD') select.classList.add('nota-ad');
    else if (val === 'A') select.classList.add('nota-a');
    else if (val === 'B') select.classList.add('nota-b');
    else if (val === 'C') select.classList.add('nota-c');

    if (badgeContainer) {
        if (val === 'AD') {
            badgeContainer.innerHTML = '<span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1.5 fs-6"><i class="bi bi-star-fill me-1 text-primary"></i>Logro Destacado (Promovido)</span>';
        } else if (val === 'A') {
            badgeContainer.innerHTML = '<span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1.5 fs-6"><i class="bi bi-check-circle-fill me-1 text-success"></i>Logro Esperado (Promovido)</span>';
        } else if (val === 'B') {
            badgeContainer.innerHTML = '<span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-2 py-1.5 fs-6"><i class="bi bi-clock-history me-1 text-warning-emphasis"></i>En Proceso (Regular)</span>';
        } else if (val === 'C') {
            badgeContainer.innerHTML = '<span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1.5 fs-6"><i class="bi bi-arrow-repeat me-1 text-danger"></i>En Inicio (Repitente)</span>';
        } else {
            badgeContainer.innerHTML = '<span class="badge bg-light text-muted border px-2 py-1">Sin calificar</span>';
        }
    }

    recalcularMetricas();
}

function recalcularMetricas() {
    const selects = document.querySelectorAll('.nota-literal-select');
    let countAD = 0, countA = 0, countB = 0, countC = 0, calificados = 0;

    selects.forEach(s => {
        const v = s.value;
        if (v === 'AD') { countAD++; calificados++; }
        else if (v === 'A') { countA++; calificados++; }
        else if (v === 'B') { countB++; calificados++; }
        else if (v === 'C') { countC++; calificados++; }
    });

    const statAD = document.getElementById('stat-ad');
    const statA = document.getElementById('stat-a');
    const statB = document.getElementById('stat-b');
    const statC = document.getElementById('stat-c');
    const statEval = document.getElementById('stat-evaluados');
    const statProm = document.getElementById('stat-promovidos');

    if (statAD) statAD.textContent = countAD;
    if (statA) statA.textContent = countA;
    if (statB) statB.textContent = countB;
    if (statC) statC.textContent = countC;
    if (statEval) statEval.textContent = calificados + ' calificados';
    if (statProm) statProm.innerHTML = '<i class="bi bi-check2-circle"></i> ' + (countAD + countA + countB);
}

function borrarNotaFila(matriculaId) {
    const sel = document.getElementById('select-nota-' + matriculaId);
    if (sel) {
        sel.value = '';
        updateLiteralBadge(sel);
    }
}

function asignarRapido(valor) {
    const selects = document.querySelectorAll('.nota-literal-select');
    let cambiados = 0;
    selects.forEach(s => {
        if (!s.value || s.value === '') {
            s.value = valor;
            updateLiteralBadge(s);
            cambiados++;
        }
    });
}

function toggleSelectAllBtn() {
    const masterCheckbox = document.getElementById('checkAllAlumnos');
    if (masterCheckbox) {
        masterCheckbox.checked = true;
        toggleSelectAll(masterCheckbox);
    }
}

function toggleSelectAll(masterCheckbox) {
    const isChecked = masterCheckbox.checked;
    const checkboxes = document.querySelectorAll('.check-alumno');
    checkboxes.forEach(cb => {
        cb.checked = isChecked;
        const row = document.getElementById('row-alumno-' + cb.value);
        if (row) {
            if (isChecked) {
                row.classList.add('table-primary');
            } else {
                row.classList.remove('table-primary');
            }
        }
    });
    actualizarBarraAcciones();
}

function onAlumnoCheckChange() {
    const checkboxes = document.querySelectorAll('.check-alumno');
    const masterCheckbox = document.getElementById('checkAllAlumnos');
    let total = checkboxes.length;
    let checkedCount = 0;

    checkboxes.forEach(cb => {
        const row = document.getElementById('row-alumno-' + cb.value);
        if (cb.checked) {
            checkedCount++;
            if (row) row.classList.add('table-primary');
        } else {
            if (row) row.classList.remove('table-primary');
        }
    });

    if (masterCheckbox) {
        masterCheckbox.checked = (total > 0 && checkedCount === total);
        masterCheckbox.indeterminate = (checkedCount > 0 && checkedCount < total);
    }

    actualizarBarraAcciones();
}

function deseleccionarTodos() {
    const masterCheckbox = document.getElementById('checkAllAlumnos');
    if (masterCheckbox) {
        masterCheckbox.checked = false;
        masterCheckbox.indeterminate = false;
    }
    const checkboxes = document.querySelectorAll('.check-alumno');
    checkboxes.forEach(cb => {
        cb.checked = false;
        const row = document.getElementById('row-alumno-' + cb.value);
        if (row) row.classList.remove('table-primary');
    });
    actualizarBarraAcciones();
}

function actualizarBarraAcciones() {
    const checked = document.querySelectorAll('.check-alumno:checked');
    const countSpan = document.getElementById('bulkSelectedCount');
    const badge = document.getElementById('bulkBadgeContador');
    const btnDeselect = document.getElementById('btnDeselectAllHeader');
    const actionBtns = document.querySelectorAll('.bulk-requires-select');

    const totalSelected = checked.length;
    if (countSpan) countSpan.textContent = totalSelected;

    if (totalSelected > 0) {
        if (badge) {
            badge.classList.remove('bg-secondary');
            badge.classList.add('bg-primary');
        }
        if (btnDeselect) btnDeselect.disabled = false;
        actionBtns.forEach(b => b.disabled = false);
    } else {
        if (badge) {
            badge.classList.remove('bg-primary');
            badge.classList.add('bg-secondary');
        }
        if (btnDeselect) btnDeselect.disabled = true;
        actionBtns.forEach(b => b.disabled = true);
    }
}

function asignarNotaSeleccionados(valor) {
    const checked = document.querySelectorAll('.check-alumno:checked');
    if (checked.length === 0) return;
    checked.forEach(cb => {
        const matId = cb.value;
        const sel = document.getElementById('select-nota-' + matId);
        if (sel) {
            sel.value = valor;
            updateLiteralBadge(sel);
        }
    });
}

function limpiarSeleccionadosEnTabla() {
    const checked = document.querySelectorAll('.check-alumno:checked');
    if (checked.length === 0) return;
    checked.forEach(cb => {
        const matId = cb.value;
        borrarNotaFila(matId);
    });
}

function confirmarBorrarSeleccionados() {
    const checked = document.querySelectorAll('.check-alumno:checked');
    if (checked.length === 0) {
        alert('Por favor selecciona al menos un estudiante con el casillero de verificación.');
        return;
    }
    const container = document.getElementById('containerIdsBorrar');
    if (container) {
        container.innerHTML = '';
        checked.forEach(cb => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'matriculas_seleccionadas[]';
            input.value = cb.value;
            container.appendChild(input);
        });
    }
    const lblModal = document.getElementById('lblModalCantSeleccionados');
    if (lblModal) {
        lblModal.textContent = checked.length + (checked.length === 1 ? ' estudiante' : ' estudiantes');
    }
    const modalEl = document.getElementById('modalBorrarSeleccionados');
    if (modalEl) {
        const modal = new bootstrap.Modal(modalEl);
        modal.show();
    }
}

// Gráfica de Rendimiento por Curso (Chart.js con Datalabels)
let chartCalificacionesAulaInstance = null;

function initChartCalificacionesAula() {
    const canvas = document.getElementById('chartCalificacionesAula');
    if (!canvas) return;

    if (chartCalificacionesAulaInstance) {
        chartCalificacionesAulaInstance.resize();
        return;
    }

    if (typeof ChartDataLabels !== 'undefined') {
        Chart.register(ChartDataLabels);
    }

    const payloadEl = document.getElementById('chart-calificaciones-data');
    let labels = [], dataAD = [], dataA = [], dataB = [], dataC = [];
    if (payloadEl) {
        try {
            const payload = JSON.parse(payloadEl.textContent || '{}');
            labels = payload.labels || [];
            dataAD = payload.dataAD || [];
            dataA = payload.dataA || [];
            dataB = payload.dataB || [];
            dataC = payload.dataC || [];
        } catch (e) {
            console.error('Error parseando datos de la gráfica:', e);
        }
    }

    chartCalificacionesAulaInstance = new Chart(canvas.getContext('2d'), {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'AD (Logro Destacado)',
                    data: dataAD,
                    borderColor: '#2563eb',
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
                    borderColor: '#16a34a',
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
                    borderColor: '#f97316',
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
                    borderColor: '#dc2626',
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
                        padding: 18,
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
}

document.addEventListener('DOMContentLoaded', function() {
    const tabGraficaBtn = document.getElementById('tab-btn-grafica');
    if (tabGraficaBtn) {
        tabGraficaBtn.addEventListener('shown.bs.tab', function() {
            initChartCalificacionesAula();
        });
    }

    const payloadEl = document.getElementById('chart-calificaciones-data');
    if (payloadEl) {
        try {
            const payload = JSON.parse(payloadEl.textContent || '{}');
            if (payload.tabActiva === 'grafica') {
                initChartCalificacionesAula();
            }
        } catch (e) {}
    }
});
