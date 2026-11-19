/**
 * Sistema Escolar - I.E. Divino Maestro
 * Módulo de Cierre Académico: Lógica Interactiva y AJAX
 * /assets/js/cierre_academico.js
 */

async function onNivelChange(nivelId) {
    const gradoSelect = document.getElementById('grado_id');
    const seccionSelect = document.getElementById('seccion_id');
    if (!gradoSelect) return;

    try {
        gradoSelect.replaceChildren(new Option('Cargando grados...', ''));
        const url = nivelId 
            ? `cierre_academico.php?ajax=grados&id=${encodeURIComponent(nivelId)}`
            : `cierre_academico.php?ajax=grados`;
        const res = await fetch(url, { headers: { Accept: 'application/json' } });
        const grados = await res.json();
        gradoSelect.replaceChildren(new Option('-- Todos los grados --', ''));
        grados.forEach(g => {
            gradoSelect.add(new Option(g.nombre, g.id));
        });

        if (seccionSelect) {
            seccionSelect.replaceChildren(new Option('Todas las secciones', ''));
            seccionSelect.disabled = true;
        }

        const form = document.getElementById('formFiltroCierre');
        if (form) form.submit();
    } catch (err) {
        console.error('Error al cargar grados:', err);
    }
}

async function onGradoChange(gradoId) {
    const seccionSelect = document.getElementById('seccion_id');
    if (!seccionSelect) return;

    if (!gradoId) {
        seccionSelect.replaceChildren(new Option('Todas las secciones', ''));
        seccionSelect.disabled = true;
        const form = document.getElementById('formFiltroCierre');
        if (form) form.submit();
        return;
    }

    try {
        seccionSelect.disabled = false;
        const res = await fetch(`cierre_academico.php?ajax=secciones&id=${encodeURIComponent(gradoId)}`, {
            headers: { Accept: 'application/json' }
        });
        const secciones = await res.json();
        seccionSelect.replaceChildren(new Option('Todas las secciones', ''));
        secciones.forEach(s => {
            seccionSelect.add(new Option(`Sección "${s.nombre}"`, s.id));
        });
        const form = document.getElementById('formFiltroCierre');
        if (form) form.submit();
    } catch (err) {
        console.error('Error al cargar secciones:', err);
    }
}
