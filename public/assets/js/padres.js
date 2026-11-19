/**
 * Sistema Escolar - I.E. Divino Maestro
 * Módulo de Padres y Apoderados: Lógica Interactiva, Modales y Filtros AJAX
 * /assets/js/padres.js
 */

document.addEventListener('DOMContentLoaded', () => {
    const nivelSelect = document.getElementById('filtro-nivel');
    const gradoSelect = document.getElementById('filtro-grado');
    const seccionSelect = document.getElementById('filtro-seccion');

    const configEl = document.getElementById('padres-config-data');
    let selectedGrade = '';
    let selectedSection = '';
    if (configEl) {
        try {
            const parsed = JSON.parse(configEl.textContent || '{}');
            selectedGrade = parsed.gradoId || '';
            selectedSection = parsed.seccionId || '';
        } catch (e) {}
    }

    // Modal de edición de contacto
    document.addEventListener('click', (event) => {
        const button = event.target.closest('.edit-contact');
        if (!button) return;
        const fields = {
            'edit-contact-id': 'id',
            'edit-contact-relacion-id': 'relacionId',
            'edit-contact-student-id': 'studentId',
            'edit-contact-dni': 'dni',
            'edit-contact-nombres': 'nombres',
            'edit-contact-apellido-paterno': 'apellidoPaterno',
            'edit-contact-apellido-materno': 'apellidoMaterno',
            'edit-contact-parentesco': 'parentesco',
            'edit-contact-direccion': 'direccion',
            'edit-contact-celular': 'celular',
            'edit-father-id': 'fatherId',
            'edit-father-relacion-id': 'fatherRelacionId',
            'edit-father-dni': 'fatherDni',
            'edit-father-nombres': 'fatherNombres',
            'edit-father-apellido-paterno': 'fatherApellidoPaterno',
            'edit-father-apellido-materno': 'fatherApellidoMaterno',
            'edit-father-parentesco': 'fatherParentesco',
            'edit-father-celular': 'fatherCelular',
            'edit-mother-id': 'motherId',
            'edit-mother-relacion-id': 'motherRelacionId',
            'edit-mother-dni': 'motherDni',
            'edit-mother-nombres': 'motherNombres',
            'edit-mother-apellido-paterno': 'motherApellidoPaterno',
            'edit-mother-apellido-materno': 'motherApellidoMaterno',
            'edit-mother-parentesco': 'motherParentesco',
            'edit-mother-celular': 'motherCelular',
            'edit-emergency-id': 'emergencyId',
            'edit-emergency-relacion-id': 'emergencyRelacionId',
            'edit-emergency-dni': 'emergencyDni',
            'edit-emergency-nombres': 'emergencyNombres',
            'edit-emergency-apellido-paterno': 'emergencyApellidoPaterno',
            'edit-emergency-apellido-materno': 'emergencyApellidoMaterno',
            'edit-emergency-parentesco': 'emergencyParentesco',
            'edit-emergency-celular': 'emergencyCelular'
        };
        Object.entries(fields).forEach(([id, key]) => {
            const el = document.getElementById(id);
            if (el) {
                const value = button.dataset[key] || '';
                el.value = value === '0' ? '' : value;
            }
        });
    });

    // Reemplazo de Apoderado Principal
    let principalDeleteForm = null;
    const modalEl = document.getElementById('principalModal');
    const principalModal = modalEl ? new bootstrap.Modal(modalEl) : null;
    const replacementSelect = document.getElementById('replacement-select');
    const principalModalError = document.getElementById('principal-modal-error');
    const confirmPrincipalDelete = document.getElementById('confirm-principal-delete');

    document.addEventListener('submit', async (event) => {
        const form = event.target;
        if (!form.matches('.delete-parent-form')) return;

        if (form.dataset.principal !== '1') {
            if (!window.confirm('¿Seguro que deseas eliminar este apoderado? Esta acción no se puede deshacer.')) {
                event.preventDefault();
            }
            return;
        }

        if (form.dataset.confirmed === '1') {
            delete form.dataset.confirmed;
            return;
        }
        event.preventDefault();

        principalDeleteForm = form;
        if (replacementSelect) {
            replacementSelect.replaceChildren(new Option('Cargando apoderados...', ''));
            replacementSelect.disabled = true;
        }
        if (confirmPrincipalDelete) confirmPrincipalDelete.disabled = true;
        if (principalModalError) principalModalError.classList.add('d-none');
        if (principalModal) principalModal.show();

        try {
            const url = new URL(window.location.href);
            url.search = '';
            url.searchParams.set('ajax', 'principal_candidates');
            url.searchParams.set('student_id', form.elements.student_id.value);
            url.searchParams.set('responsable_id', form.elements.id.value);
            const response = await fetch(url, { headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error('No se pudieron cargar los apoderados vinculados.');
            const candidates = await response.json();
            if (replacementSelect) {
                replacementSelect.replaceChildren(new Option('Selecciona un apoderado', ''));
                candidates.forEach((candidate) => {
                    const fullName = [candidate.apellido_paterno, candidate.apellido_materno, candidate.nombres]
                        .filter(Boolean).join(' ');
                    replacementSelect.add(new Option(fullName, candidate.id));
                });
                replacementSelect.disabled = candidates.length === 0;
            }
            if (confirmPrincipalDelete) {
                confirmPrincipalDelete.disabled = candidates.length === 0;
            }
            if (candidates.length === 0 && principalModalError) {
                principalModalError.textContent = 'No hay otro apoderado vinculado que pueda ser Principal.';
                principalModalError.classList.remove('d-none');
            }
        } catch (error) {
            if (principalModalError) {
                principalModalError.textContent = error.message;
                principalModalError.classList.remove('d-none');
            }
        }
    });

    if (replacementSelect && confirmPrincipalDelete) {
        replacementSelect.addEventListener('change', () => {
            confirmPrincipalDelete.disabled = replacementSelect.value === '';
        });
        confirmPrincipalDelete.addEventListener('click', () => {
            if (!principalDeleteForm || replacementSelect.value === '') return;
            principalDeleteForm.elements.replacement_id.value = replacementSelect.value;
            principalDeleteForm.dataset.confirmed = '1';
            if (principalModal) principalModal.hide();
            principalDeleteForm.requestSubmit();
        });
    }

    async function loadOptions(select, action, parentId, placeholder, selected = '') {
        if (!select) return;
        select.replaceChildren(new Option(placeholder, ''));
        select.disabled = !parentId;
        if (!parentId) return;

        const response = await fetch(`padres.php?ajax=${action}&id=${encodeURIComponent(parentId)}`, {
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
            await loadOptions(seccionSelect, 'secciones', '', 'Todas las secciones');
        });

        gradoSelect.addEventListener('change', () =>
            loadOptions(seccionSelect, 'secciones', gradoSelect.value, 'Todas las secciones')
        );

        loadOptions(gradoSelect, 'grados', nivelSelect.value, 'Todos los grados', selectedGrade)
            .then(() => loadOptions(seccionSelect, 'secciones', gradoSelect.value, 'Todas las secciones', selectedSection))
            .catch((error) => console.error(error));
    }
});
