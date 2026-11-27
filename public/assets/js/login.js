/**
 * Sistema Escolar - Script de Login
 */
document.addEventListener('DOMContentLoaded', () => {
    const correoInput = document.getElementById('correo');
    const passwordInput = document.getElementById('password');
    const togglePassword = document.getElementById('togglePassword');

    if (togglePassword && passwordInput) {
        togglePassword.addEventListener('click', () => {
            const isPassword = passwordInput.type === 'password';
            passwordInput.type = isPassword ? 'text' : 'password';
            togglePassword.textContent = isPassword ? 'Ocultar' : 'Mostrar';
        });
    }

    [correoInput, passwordInput].forEach((input) => {
        if (!input) {
            return;
        }
        input.addEventListener('input', () => {
            input.classList.remove('is-invalid');
        });
    });
});
