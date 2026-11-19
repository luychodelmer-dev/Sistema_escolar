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
