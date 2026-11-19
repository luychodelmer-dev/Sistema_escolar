/**
 * Sistema Escolar - Script de Cursos y Períodos
 */

function abrirModalAgregarSeccion(gradoId, gradoNombre) {
    const select = document.getElementById('modalGradoSelect');
    if (select) {
        select.value = gradoId;
    }
    const modalEl = document.getElementById('modalNuevaSeccion');
    if (modalEl && typeof bootstrap !== 'undefined') {
        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        modal.show();
    }
}
window.abrirModalAgregarSeccion = abrirModalAgregarSeccion;

function setSeccionValor(valor) {
    const input = document.getElementById('modalSeccionNombre');
    if (input) {
        input.value = valor;
        input.focus();
    }
}
window.setSeccionValor = setSeccionValor;

document.addEventListener('DOMContentLoaded', () => {
    // Filtrado en vivo por texto en tabla de cursos
    const busqueda = document.getElementById('busquedaCurso');
    const filasCursos = document.querySelectorAll('.curso-fila');
    const tabBtns = document.querySelectorAll('.filter-tab-btn');
    let nivelActivo = 'todos';

    function filtrarTabla() {
        const texto = (busqueda.value || '').trim().toLowerCase();
        filasCursos.forEach(fila => {
            const nivelFila = fila.getAttribute('data-nivel') || '';
            const textoFila = fila.getAttribute('data-texto') || '';
            const matchNivel = (nivelActivo === 'todos' || nivelFila.toLowerCase() === nivelActivo.toLowerCase());
            const matchTexto = (texto === '' || textoFila.includes(texto));

            fila.style.display = (matchNivel && matchTexto) ? '' : 'none';
        });
    }

    if (busqueda) {
        busqueda.addEventListener('input', filtrarTabla);
    }

    tabBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            tabBtns.forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            nivelActivo = btn.getAttribute('data-nivel') || 'todos';
            filtrarTabla();
        });
    });

    // Preview de materias en Modal MINEDU
    const nivelSelect = document.getElementById('nivelMallaSelect');
    const previewList = document.getElementById('listaPreviewCursos');
    const mallasPreview = {
        'Primaria': [
            'Matemática', 'Comunicación', 'Ciencia y Tecnología', 'Personal Social',
            'Arte y Cultura', 'Educación Física', 'Educación Religiosa', 'Inglés', 'Tutoría'
        ],
        'Secundaria': [
            'Matemática', 'Comunicación', 'Ciencia y Tecnología', 'Ciencias Sociales',
            'Desarrollo Personal, Ciudadanía y Cívica', 'Arte y Cultura', 'Educación Física',
            'Educación Religiosa', 'Inglés', 'Educación para el Trabajo', 'Tutoría'
        ],
        'Inicial': [
            'Comunicación', 'Matemática', 'Personal Social', 'Ciencia y Tecnología', 'Psicomotricidad'
        ]
    };

    if (nivelSelect && previewList) {
        nivelSelect.addEventListener('change', () => {
            const cursos = mallasPreview[nivelSelect.value] || [];
            previewList.innerHTML = cursos.map(c => `<li>${c}</li>`).join('');
        });
    }
});
