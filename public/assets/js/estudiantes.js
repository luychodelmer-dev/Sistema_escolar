/**
 * Sistema Escolar - Script de Lista de Estudiantes y Filtros
 */
document.addEventListener('DOMContentLoaded', () => {
    const configEl = document.getElementById('estudiantes-config-data');
    let selectedGrade = null;
    let selectedSection = null;
    if (configEl) {
        try {
            const config = JSON.parse(configEl.textContent || '{}');
            selectedGrade = config.gradoId ?? null;
            selectedSection = config.seccionId ?? null;
        } catch (e) {
            console.error('Error parseando configuración de estudiantes:', e);
        }
    }

    const nivelSelect = document.getElementById('filtro-nivel');
    const gradoSelect = document.getElementById('filtro-grado');
    const seccionSelect = document.getElementById('filtro-seccion');
    const studentFilters = document.getElementById('student-filters');
    const studentsRows = document.getElementById('students-rows');
    const studentsCount = document.getElementById('students-result-count');
    const studentsLoading = document.getElementById('students-loading');
    const academicStatusInput = document.getElementById('filtro-estado-academico');
    const academicStatusTabs = document.getElementById('academic-status-tabs');
    let filterTimer;
    let filterRequest;

    if (!studentFilters || !studentsRows) return;

    async function refreshStudents() {
        filterRequest?.abort();
        filterRequest = new AbortController();
        const parameters = new URLSearchParams(new FormData(studentFilters));
        parameters.set('ajax', 'tabla');
        if (studentsLoading) studentsLoading.hidden = false;
        try {
            const response = await fetch(`estudiantes.php?${parameters}`, {
                headers: { Accept: 'text/html' },
                signal: filterRequest.signal
            });
            if (!response.ok) throw new Error('No se pudo actualizar la lista de estudiantes.');
            studentsRows.innerHTML = await response.text();
            if (studentsCount) {
                const totalOps = response.headers.get('X-Total-Operaciones');
                if (totalOps !== null) {
                    const totalNum = parseInt(totalOps, 10);
                    studentsCount.textContent = `${totalNum.toLocaleString()} ${totalNum === 1 ? 'estudiante' : 'estudiantes'}`;
                } else {
                    studentsCount.textContent = `${studentsRows.querySelectorAll('[data-student-row]').length} estudiantes`;
                }
            }
        } catch (error) {
            if (error.name !== 'AbortError') {
                const headRow = studentsRows.closest('table')?.querySelector('thead tr');
                const columnCount = headRow ? headRow.children.length : 6;
                studentsRows.innerHTML = `<tr><td colspan="${columnCount}" class="text-center text-danger py-4">Error al cargar estudiantes.</td></tr>`;
                console.error(error);
            }
        } finally {
            if (studentsLoading && !filterRequest.signal.aborted) {
                studentsLoading.hidden = true;
            }
        }
    }

    if (academicStatusTabs) {
        academicStatusTabs.addEventListener('click', (event) => {
            const tab = event.target.closest('[data-academic-status]');
            if (!tab) return;
            if (academicStatusInput) academicStatusInput.value = tab.dataset.academicStatus;
            academicStatusTabs.querySelectorAll('[data-academic-status]').forEach((item) => {
                const selected = item === tab;
                item.classList.toggle('active', selected);
                item.setAttribute('aria-selected', String(selected));
            });
            refreshStudents();
        });
    }

    studentFilters.addEventListener('input', (event) => {
        if (event.target.matches('input[type="search"]')) {
            clearTimeout(filterTimer);
            filterTimer = setTimeout(refreshStudents, 250);
        }
    });

    studentFilters.addEventListener('change', (event) => {
        if (event.target.matches('select')) refreshStudents();
    });

    studentFilters.addEventListener('reset', () => {
        setTimeout(() => {
            const activeTab = academicStatusTabs?.querySelector('[data-academic-status].active');
            if (academicStatusInput) {
                academicStatusInput.value = activeTab?.dataset.academicStatus || 'Activo';
            }
            refreshStudents();
        }, 0);
    });

    studentsRows.addEventListener('submit', async (event) => {
        const deleteForm = event.target.closest('.student-delete-form');
        if (deleteForm) {
            event.preventDefault();
            const button = deleteForm.querySelector('button[type="submit"]');
            if (button) button.disabled = true;
            try {
                const response = await fetch(deleteForm.action, {
                    method: 'POST',
                    headers: { Accept: 'application/json' },
                    body: new FormData(deleteForm)
                });
                const result = await response.json();
                if (!response.ok) throw new Error(result.error || 'No se pudo eliminar el estudiante.');
                await refreshStudents();
            } catch (error) {
                window.alert(error.message);
            } finally {
                if (button) button.disabled = false;
            }
            return;
        }

        const form = event.target.closest('.academic-status-form');
        if (!form) return;
        event.preventDefault();
        const submitButton = form.querySelector('button[type="submit"]');
        if (submitButton) submitButton.disabled = true;
        try {
            const response = await fetch('estudiantes.php', {
                method: 'POST',
                headers: { Accept: 'application/json' },
                body: new FormData(form)
            });
            const result = await response.json();
            if (!response.ok) throw new Error(result.error || 'No se pudo actualizar el estado.');
            await refreshStudents();
        } catch (error) {
            window.alert(error.message);
        } finally {
            if (submitButton) submitButton.disabled = false;
        }
    });

    async function loadOptions(select, action, parentId, placeholder, selected = '') {
        if (!select) return;
        select.replaceChildren(new Option(placeholder, ''));
        select.disabled = !parentId;
        if (!parentId) return;

        const response = await fetch(`estudiantes.php?ajax=${action}&id=${encodeURIComponent(parentId)}`, {
            headers: { Accept: 'application/json' }
        });
        if (!response.ok) throw new Error('No se pudieron cargar las opciones.');

        const options = await response.json();
        options.forEach((item) => {
            const option = new Option(item.nombre, item.id);
            option.selected = String(item.id) === String(selected);
            select.add(option);
        });
        select.disabled = false;
    }

    if (nivelSelect && gradoSelect && seccionSelect) {
        nivelSelect.addEventListener('change', async () => {
            seccionSelect.replaceChildren(new Option('Todas las secciones', ''));
            seccionSelect.disabled = true;
            await loadOptions(gradoSelect, 'grados', nivelSelect.value, 'Todos los grados');
        });

        gradoSelect.addEventListener('change', async () => {
            await loadOptions(seccionSelect, 'secciones', gradoSelect.value, 'Todas las secciones');
        });

        loadOptions(gradoSelect, 'grados', nivelSelect.value, 'Todos los grados', selectedGrade)
            .then(() => loadOptions(seccionSelect, 'secciones', gradoSelect.value, 'Todas las secciones', selectedSection))
            .catch((error) => console.error(error));
    }
});
