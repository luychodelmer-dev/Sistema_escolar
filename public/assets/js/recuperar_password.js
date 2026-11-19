/**
 * Sistema Escolar - Script de Recuperación de Contraseña
 */
document.addEventListener('DOMContentLoaded', () => {
    const setupToggle = (inputId, btnId) => {
        const input = document.getElementById(inputId);
        const btn = document.getElementById(btnId);
        if (input && btn) {
            btn.addEventListener('click', () => {
                const isPass = input.type === 'password';
                input.type = isPass ? 'text' : 'password';
                btn.textContent = isPass ? 'Ocultar' : 'Mostrar';
            });
        }
    };
    setupToggle('password', 'togglePassword');
    setupToggle('password_confirm', 'togglePasswordConfirm');
});
