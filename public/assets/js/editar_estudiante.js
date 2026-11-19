/**
 * Sistema Escolar - Script de Edición de Estudiante
 */
document.addEventListener('DOMContentLoaded', () => {
    const configEl = document.getElementById('editar-estudiante-config');
    let estudianteId = '';
    if (configEl) {
        try {
            const parsed = JSON.parse(configEl.textContent || '{}');
            estudianteId = parsed.estudianteId || '';
        } catch (e) {
            console.error('Error parseando configuración:', e);
        }
    }
    if (!estudianteId) {
        estudianteId = new URLSearchParams(window.location.search).get('id') || '';
    }

    const nivelSelect = document.getElementById('nivel_id');
    const gradoSelect = document.getElementById('grado_id');
    const seccionSelect = document.getElementById('seccion_id');

    async function loadDynamicOptions(select, action, parentId, placeholder) {
        if (!select) return;
        select.replaceChildren(new Option(placeholder, ''));
        if (!parentId) {
            select.disabled = true;
            return;
        }
        select.disabled = true;
        try {
            const response = await fetch(`editar_estudiante.php?id=${encodeURIComponent(estudianteId)}&ajax=${action}&parent_id=${encodeURIComponent(parentId)}`, {
                headers: { Accept: 'application/json' }
            });
            if (!response.ok) throw new Error('Error al cargar opciones.');
            const options = await response.json();
            options.forEach(item => {
                select.add(new Option(item.nombre, item.id));
            });
            select.disabled = false;
        } catch (err) {
            console.error(err);
        }
    }

    nivelSelect?.addEventListener('change', async () => {
        if (seccionSelect) {
            seccionSelect.replaceChildren(new Option('Seleccionar Sección', ''));
            seccionSelect.disabled = true;
        }
        await loadDynamicOptions(gradoSelect, 'grados', nivelSelect.value, 'Seleccionar Grado');
    });

    gradoSelect?.addEventListener('change', async () => {
        await loadDynamicOptions(seccionSelect, 'secciones', gradoSelect.value, 'Seleccionar Sección');
    });
});
