<?php
if (!function_exists('render_floating_dock')) {
    function render_floating_dock(string $activeModule = ''): void
    {
        // Función obsoleta mantenida por retrocompatibilidad.
    }
}

function panel_home_path(): string
{
    $role = $_SESSION['rol'] ?? '';
    return in_array($role, ['director', 'hiperadmin'], true) ? '../director/panel.php' : 'panel.php';
}

function render_app_header(string $homePath, string $logoutPath, string $activeModule = ''): void
{
    $role = $_SESSION['rol'] ?? 'director';
    $roleLabel = $role === 'hiperadmin' ? 'Hiperadministrador' : ($role === 'director' ? 'Dirección' : 'Asistente');
    $userName = $_SESSION['nombres'] ?? ($role === 'director' ? 'Director Prueba' : 'Usuario');

    // Determinar la ubicación de la vista actual
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $isDirectorFolder = strpos($_SERVER['SCRIPT_NAME'] ?? '', '/director/') !== false;

    // Detectar módulo activo si no se especificó
    if ($activeModule === '') {
        if (strpos($script, 'panel') !== false) {
            $activeModule = 'panel';
        } elseif (strpos($script, 'estudiante') !== false) {
            $activeModule = 'estudiantes';
        } elseif (strpos($script, 'nomina') !== false) {
            $activeModule = 'nomina';
        } elseif (strpos($script, 'matricula') !== false) {
            $activeModule = 'matriculas';
        } elseif (strpos($script, 'padre') !== false || strpos($script, 'apoderado') !== false) {
            $activeModule = 'familias';
        } elseif (strpos($script, 'curso') !== false) {
            $activeModule = 'cursos';
        } elseif (strpos($script, 'reporte_calificacion') !== false) {
            $activeModule = 'reporte_calificaciones';
        } elseif (strpos($script, 'calificacion') !== false) {
            $activeModule = 'calificaciones';
        } elseif (strpos($script, 'cierre') !== false || strpos($script, 'recuperacion') !== false) {
            $activeModule = 'cierre_academico';
        } elseif (strpos($script, 'reporte_pago') !== false) {
            $activeModule = 'reporte_pagos';
        } elseif (strpos($script, 'pago') !== false) {
            $activeModule = 'pagos';
        } elseif (strpos($script, 'permiso') !== false) {
            $activeModule = 'permisos';
        } elseif (strpos($script, 'auditoria') !== false) {
            $activeModule = 'auditoria';
        }
    }

    // Rutas relativas consistentes
    $prefix = $isDirectorFolder ? '../asistente/' : '';
    $dirPrefix = $isDirectorFolder ? '' : '../director/';

    $urlPanel = in_array($role, ['director', 'hiperadmin'], true)
        ? ($isDirectorFolder ? 'panel.php' : '../director/panel.php')
        : ($isDirectorFolder ? '../asistente/panel.php' : 'panel.php');

    $urlEstudiantes    = $prefix . 'estudiantes.php';
    $urlMatriculas     = $prefix . 'matriculas.php';
    $urlNomina         = $prefix . 'nomina_matriculas.php';
    $urlFamilias       = $prefix . 'padres.php';
    $urlCursos         = $prefix . 'cursos_periodos.php';
    $urlCalificaciones = $prefix . 'calificaciones.php';
    $urlReporteCalificaciones = $prefix . 'reporte_calificaciones.php';
    $urlCierreAcademico = $prefix . 'cierre_academico.php';
    $urlPagos          = $prefix . 'pagos.php';
    $urlReportePagos   = $prefix . 'reporte_pagos.php';

    $urlPermisos       = $dirPrefix . 'permisos.php';
    $urlAuditoria      = $dirPrefix . 'auditoria.php';

    $logoImg = '../assets/images/logo-institucion.jpg.jpg';
    $userImg = '../assets/images/imagen-directora.jpg.png';
    ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/app.css">

    <!-- Barra Lateral de Navegación Persistente -->
    <aside class="app-sidebar" id="appSidebar">
        <!-- Logo y Nombre Institucional -->
        <a class="sidebar-brand-wrapper" href="<?= htmlspecialchars($urlPanel) ?>">
            <img class="sidebar-brand-logo" src="<?= htmlspecialchars($logoImg) ?>" alt="Logo I.E. Divino Maestro">
            <div class="sidebar-brand-text">
                <div class="sidebar-brand-name">I.E. Divino Maestro</div>
                <div class="sidebar-brand-sub">Sistema escolar</div>
            </div>
        </a>

        <!-- Sección GENERAL -->
        <div class="sidebar-nav-group">
            <div class="sidebar-nav-title">GENERAL</div>
            <a href="<?= htmlspecialchars($urlPanel) ?>" class="sidebar-nav-item <?= $activeModule === 'panel' ? 'active' : '' ?>">
                <i class="bi bi-grid<?= $activeModule === 'panel' ? '-fill' : '' ?>"></i>
                <span>Panel</span>
            </a>
        </div>

        <!-- Sección GESTIÓN ACADÉMICA Y ESCOLAR -->
        <div class="sidebar-nav-group">
            <div class="sidebar-nav-title">GESTIÓN ESCOLAR</div>

            <a href="<?= htmlspecialchars($urlEstudiantes) ?>" class="sidebar-nav-item <?= $activeModule === 'estudiantes' ? 'active' : '' ?>">
                <i class="bi bi-mortarboard<?= $activeModule === 'estudiantes' ? '-fill' : '' ?>"></i>
                <span>Estudiantes</span>
            </a>

            <a href="<?= htmlspecialchars($urlMatriculas) ?>" class="sidebar-nav-item <?= $activeModule === 'matriculas' ? 'active' : '' ?>">
                <i class="bi bi-journal-bookmark<?= $activeModule === 'matriculas' ? '-fill' : '' ?>"></i>
                <span>Matrículas</span>
            </a>

            <a href="<?= htmlspecialchars($urlNomina) ?>" class="sidebar-nav-item <?= $activeModule === 'nomina' ? 'active' : '' ?>">
                <i class="bi bi-file-earmark-ruled<?= $activeModule === 'nomina' ? '-fill' : '' ?>"></i>
                <span>Nómina y Reportes</span>
            </a>

            <a href="<?= htmlspecialchars($urlFamilias) ?>" class="sidebar-nav-item <?= $activeModule === 'familias' ? 'active' : '' ?>">
                <i class="bi bi-people<?= $activeModule === 'familias' ? '-fill' : '' ?>"></i>
                <span>Padres y Familias</span>
            </a>

            <a href="<?= htmlspecialchars($urlCursos) ?>" class="sidebar-nav-item <?= $activeModule === 'cursos' ? 'active' : '' ?>">
                <i class="bi bi-book<?= $activeModule === 'cursos' ? '-half' : '' ?>"></i>
                <span>Cursos y Periodos</span>
            </a>

            <a href="<?= htmlspecialchars($urlCalificaciones) ?>" class="sidebar-nav-item <?= $activeModule === 'calificaciones' ? 'active' : '' ?>">
                <i class="bi bi-pencil-square"></i>
                <span>Calificaciones y Notas</span>
            </a>

            <a href="<?= htmlspecialchars($urlReporteCalificaciones) ?>" class="sidebar-nav-item <?= $activeModule === 'reporte_calificaciones' ? 'active' : '' ?>">
                <i class="bi bi-graph-up-arrow"></i>
                <span>Gráficas de Calificaciones</span>
            </a>

            <a href="<?= htmlspecialchars($urlCierreAcademico) ?>" class="sidebar-nav-item <?= $activeModule === 'cierre_academico' ? 'active' : '' ?>">
                <i class="bi bi-award<?= $activeModule === 'cierre_academico' ? '-fill' : '' ?>"></i>
                <span>Cierre y Vacacional</span>
            </a>
        </div>

        <!-- Sección FINANZAS Y CAJA -->
        <div class="sidebar-nav-group">
            <div class="sidebar-nav-title">FINANZAS Y CAJA</div>

            <a href="<?= htmlspecialchars($urlPagos) ?>" class="sidebar-nav-item <?= $activeModule === 'pagos' ? 'active' : '' ?>">
                <i class="bi bi-credit-card-2-back<?= $activeModule === 'pagos' ? '-fill' : '' ?>"></i>
                <span>Control de Pagos</span>
            </a>

            <a href="<?= htmlspecialchars($urlReportePagos) ?>" class="sidebar-nav-item <?= $activeModule === 'reporte_pagos' ? 'active' : '' ?>">
                <i class="bi bi-cash-stack<?= $activeModule === 'reporte_pagos' ? '-fill' : '' ?>"></i>
                <span>Reporte de Caja</span>
            </a>
        </div>

        <!-- Sección DIRECCIÓN (Solo Director / Hiperadmin) -->
        <?php if (in_array($role, ['director', 'hiperadmin'], true)): ?>
        <div class="sidebar-nav-group">
            <div class="sidebar-nav-title">DIRECCIÓN</div>

            <a href="<?= htmlspecialchars($urlPermisos) ?>" class="sidebar-nav-item <?= $activeModule === 'permisos' ? 'active' : '' ?>">
                <i class="bi bi-shield-lock<?= $activeModule === 'permisos' ? '-fill' : '' ?>"></i>
                <span>Permisos Asistentes</span>
            </a>

            <a href="<?= htmlspecialchars($urlAuditoria) ?>" class="sidebar-nav-item <?= $activeModule === 'auditoria' ? 'active' : '' ?>">
                <i class="bi bi-clock-history"></i>
                <span>Auditoría de Sistema</span>
            </a>
        </div>
        <?php endif; ?>
    </aside>

    <!-- Fondo de overlay para móvil -->
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <!-- Barra Superior (Topbar) con perfil de usuario -->
    <header class="app-topbar">
        <!-- Botón toggle de menú móvil -->
        <button type="button" class="btn btn-sm btn-light border d-lg-none sidebar-mobile-toggle" id="sidebarMobileBtn" aria-label="Abrir menú">
            <i class="bi bi-list fs-5"></i>
        </button>

        <!-- Menú de Usuario a la derecha -->
        <div class="user-menu-wrapper ms-auto">
            <button type="button" class="user-menu-btn" id="userMenuBtn" aria-expanded="false" aria-label="Menú de usuario">
                <img class="user-menu-avatar" src="<?= htmlspecialchars($userImg) ?>" alt="<?= htmlspecialchars($userName) ?>">
                <div class="user-menu-meta d-none d-sm-block">
                    <div class="user-menu-name"><?= htmlspecialchars($userName) ?></div>
                    <div class="user-menu-role"><?= htmlspecialchars($roleLabel) ?></div>
                </div>
                <i class="bi bi-chevron-down user-menu-chevron ms-1"></i>
            </button>

            <!-- Menú desplegable -->
            <div class="user-dropdown-menu" id="userDropdownMenu">
                <div class="user-dropdown-header">
                    <div class="fw-bold text-dark text-truncate" style="font-size: 0.9rem;"><?= htmlspecialchars($userName) ?></div>
                    <div class="text-muted text-truncate" style="font-size: 0.78rem;"><?= htmlspecialchars($roleLabel) ?></div>
                </div>
                <a href="<?= htmlspecialchars($urlPanel) ?>" class="user-dropdown-item">
                    <i class="bi bi-house-door text-muted"></i>
                    <span>Panel Principal</span>
                </a>
                <?php if (in_array($role, ['director', 'hiperadmin'], true)): ?>
                <a href="<?= htmlspecialchars($urlAuditoria) ?>" class="user-dropdown-item">
                    <i class="bi bi-clock-history text-muted"></i>
                    <span>Auditoría</span>
                </a>
                <?php endif; ?>
                <div class="border-top my-1"></div>
                <a href="<?= htmlspecialchars($logoutPath) ?>" class="user-dropdown-item text-danger">
                    <i class="bi bi-box-arrow-right"></i>
                    <span>Cerrar sesión</span>
                </a>
            </div>
        </div>
    </header>

    <script src="../assets/js/header.js" defer></script>
    <script src="../assets/js/app_filtros.js" defer></script>
    <?php
}
