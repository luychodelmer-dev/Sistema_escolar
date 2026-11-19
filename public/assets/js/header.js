/**
 * Sistema Escolar - Script de Navegación y Cabecera (Header / Sidebar)
 */
document.addEventListener('DOMContentLoaded', () => {
    // Manejador del menú móvil de barra lateral
    const mobileBtn = document.getElementById('sidebarMobileBtn');
    const sidebar = document.getElementById('appSidebar');
    const backdrop = document.getElementById('sidebarBackdrop');

    if (mobileBtn && sidebar && backdrop) {
        mobileBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            sidebar.classList.toggle('show');
            backdrop.classList.toggle('show');
        });

        backdrop.addEventListener('click', function() {
            sidebar.classList.remove('show');
            backdrop.classList.remove('show');
        });
    }

    // Manejador del dropdown de perfil de usuario
    const userBtn = document.getElementById('userMenuBtn');
    const userMenu = document.getElementById('userDropdownMenu');

    if (userBtn && userMenu) {
        userBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            const isShown = userMenu.classList.contains('show');
            userMenu.classList.toggle('show', !isShown);
            userBtn.setAttribute('aria-expanded', !isShown);
        });

        document.addEventListener('click', function(e) {
            if (!userMenu.contains(e.target) && !userBtn.contains(e.target)) {
                userMenu.classList.remove('show');
                userBtn.setAttribute('aria-expanded', 'false');
            }
        });
    }
});
