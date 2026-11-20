/**
 * Sistema Escolar - Script de Gestión de Permisos y Usuarios
 */

function abrirModalReset(id, nombre, correo) {
    const idInput = document.getElementById('modalResetUsuarioId');
    const nomEl = document.getElementById('modalResetNombre');
    const corEl = document.getElementById('modalResetCorreo');
    const newPass = document.getElementById('modalNewPassword');
    const confPass = document.getElementById('modalConfirmPassword');
    const modalEl = document.getElementById('resetPasswordModal');

    if (idInput) idInput.value = id;
    if (nomEl) nomEl.textContent = nombre;
    if (corEl) corEl.textContent = correo;
    if (newPass) newPass.value = '';
    if (confPass) confPass.value = '';

    if (modalEl && typeof bootstrap !== 'undefined') {
        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        modal.show();
    }
}
window.abrirModalReset = abrirModalReset;

function generarPasswordSegura() {
    const chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789!@#$';
    let pass = '';
    for (let i = 0; i < 10; i++) {
        pass += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    const newPass = document.getElementById('modalNewPassword');
    const confPass = document.getElementById('modalConfirmPassword');
    if (newPass) newPass.value = pass;
    if (confPass) confPass.value = pass;
}
window.generarPasswordSegura = generarPasswordSegura;

function copiarCodigo(codigo, btn) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(codigo).then(() => {
            const original = btn.innerHTML;
            btn.innerHTML = '<i class="bi bi-check2"></i> Copiado!';
            setTimeout(() => { btn.innerHTML = original; }, 2000);
        });
    } else {
        prompt('Copia el código:', codigo);
    }
}
window.copiarCodigo = copiarCodigo;

/**
 * Plantillas predefinidas de permisos por rol institucional
 */
const PLANTILLAS_PERMISOS = {
    docente: ['p_calificar'],
    caja: ['p_cobrar', 'p_reportes_caja', 'p_padres', 'p_editar'],
    secretaria: ['p_estudiantes', 'p_padres', 'p_editar', 'p_matricular', 'p_cobrar'],
    coordinacion: ['p_estudiantes', 'p_matricular', 'p_cursos_periodos', 'p_calificar', 'p_cierre_academico'],
    todos: ['p_estudiantes', 'p_padres', 'p_editar', 'p_matricular', 'p_cursos_periodos', 'p_calificar', 'p_cierre_academico', 'p_cobrar', 'p_reportes_caja', 'p_auditoria'],
    ninguno: []
};

function aplicarPlantilla(tipo, form) {
    if (!form) return;
    const targets = PLANTILLAS_PERMISOS[tipo] || [];
    const checkboxes = form.querySelectorAll('input[type="checkbox"][data-codigo]');
    checkboxes.forEach(cb => {
        const codigo = cb.getAttribute('data-codigo');
        cb.checked = targets.includes(codigo);
    });
}
window.aplicarPlantilla = aplicarPlantilla;

function alternarModulo(checkboxModulo, modulo) {
    const contenedor = checkboxModulo.closest('.modulo-card');
    if (!contenedor) return;
    const checkboxes = contenedor.querySelectorAll('input[type="checkbox"][data-codigo]');
    checkboxes.forEach(cb => {
        cb.checked = checkboxModulo.checked;
    });
}
window.alternarModulo = alternarModulo;

