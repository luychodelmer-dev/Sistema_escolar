<?php
function panel_home_path(): string
{
	$role = $_SESSION['rol'] ?? '';
	return in_array($role, ['director', 'hiperadmin'], true) ? '../director/panel.php' : 'panel.php';
}

function render_app_header(string $homePath, string $logoutPath): void
{
	$role = $_SESSION['rol'] ?? '';
	$roleLabel = $role === 'hiperadmin' ? 'Hiperadministrador' : ($role === 'director' ? 'Director' : 'Asistente');
	?>
	<style>
		.page-header { background: #fff; border-bottom: 1px solid #e3e9ed; color: #19324a; box-shadow: 0 3px 12px rgba(25, 50, 74, .05); }
		.page-header-inner { min-height: 72px; display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 1rem; }
		.institution-brand { display: flex; align-items: center; gap: .8rem; color: inherit; text-decoration: none; font-weight: 700; }
		.institution-brand img { width: auto; max-height: 50px; object-fit: contain; }
		.institution-brand span { font-size: 1rem; }
		.header-user { display: flex; align-items: center; gap: .75rem; }
		.header-user-photo { width: 42px; height: 42px; object-fit: cover; border: 2px solid #dce5e9; border-radius: 50%; }
		.is-updating { opacity: .55; transition: opacity .15s ease; }
		@media (max-width: 575px) { .page-header-inner { min-height: 64px; } .institution-brand span { max-width: 13rem; font-size: .88rem; } .header-user .logout-link { display: none; } }
	</style>
	<header class="page-header">
		<div class="container page-header-inner">
			<a class="institution-brand" href="<?= htmlspecialchars($homePath) ?>">
				<img src="../assets/images/logo-institucion.jpg.jpg" alt="Logo de la Institución Educativa Divino Maestro">
				<span>Institución Educativa &quot;Divino Maestro&quot; de Mollepampa-Cajamarca</span>
			</a>
			<div class="header-user">
				<img class="header-user-photo" src="../assets/images/imagen-directora.jpg.png" alt="Imagen institucional">
				<span class="badge text-bg-success"><?= htmlspecialchars($roleLabel) ?></span>
				<a href="<?= htmlspecialchars($logoutPath) ?>" class="btn btn-outline-secondary btn-sm logout-link">Cerrar sesión</a>
			</div>
		</div>
	</header>
	<script src="../assets/js/app_filtros.js" defer></script>
	<?php
}
