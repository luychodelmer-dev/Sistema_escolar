<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/header.php';
require_once __DIR__ . '/../../app/dashboard_metrics.php';

require_auth(['director', 'hiperadmin']);
$metrics = get_dashboard_metrics($pdo);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Panel del Director - Sistema Escolar</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f4f7fb; color: #172b4d; }
        .page-header { background: #172b4d; color: #fff; }
        .institution-brand { display: flex; align-items: center; gap: .6rem; white-space: nowrap; }
        .institution-brand img { width: 42px; height: 42px; object-fit: contain; border-radius: 50%; background: #fff; }
        .institution-brand span { font-size: 1.1rem; white-space: nowrap; }
        .header-user-photo { width: 52px; height: 52px; object-fit: cover; border: 2px solid rgba(255, 255, 255, .85); border-radius: 50%; }
        @media (max-width: 768px) { .institution-brand span { font-size: .95rem; } }
        .module-card { height: 100%; border: 0; border-radius: 12px; transition: transform .2s ease, box-shadow .2s ease; }
        .module-card:hover { transform: translateY(-3px); box-shadow: 0 .75rem 1.5rem rgba(23, 43, 77, .12) !important; }
    </style>
</head>
<body>
<?php render_app_header(panel_home_path(), '../asistente/logout.php'); ?>
<main class="container py-5">
    <div class="mb-4"><h1 class="h3 mb-1">Panel del Director</h1><p class="text-muted mb-0">Supervisa y gestiona la información principal de la institución.</p></div>
    <div class="row g-3 mb-4">
        <div class="col-md-4"><div class="card shadow-sm border-0 h-100"><div class="card-body"><h2 class="h6 text-primary">Estudiantes activos</h2><p class="display-5 fw-bold text-primary mb-0"><?= number_format($metrics['active_students']) ?></p></div></div></div>
        <div class="col-md-4"><div class="card shadow-sm border-0 h-100"><div class="card-body"><h2 class="h6 text-success">Ingresos del mes</h2><p class="display-5 fw-bold text-success mb-0">S/ <?= number_format($metrics['monthly_income'], 2) ?></p></div></div></div>
        <div class="col-md-4"><div class="card shadow-sm border-0 h-100"><div class="card-body"><h2 class="h6 text-warning">Deudas pendientes</h2><p class="display-5 fw-bold text-warning mb-0"><?= number_format($metrics['pending_debts']) ?></p></div></div></div>
    </div>
    <div class="row g-4">
        <div class="col-md-4"><div class="card module-card shadow-sm"><div class="card-body p-4 d-flex flex-column"><h2 class="h5 text-primary">Padres y apoderados</h2><p class="text-muted flex-grow-1">Consulta y registra la información de los responsables.</p><a href="../asistente/padres.php" class="btn btn-primary">Gestionar padres</a></div></div></div>
        <div class="col-md-4"><div class="card module-card shadow-sm"><div class="card-body p-4 d-flex flex-column"><h2 class="h5 text-success">Estudiantes</h2><p class="text-muted flex-grow-1">Consulta estudiantes y filtra por nivel, grado y sección.</p><a href="../asistente/estudiantes.php" class="btn btn-success">Gestionar estudiantes</a></div></div></div>
        <div class="col-md-4"><div class="card module-card shadow-sm"><div class="card-body p-4 d-flex flex-column"><h2 class="h5 text-info">Matrículas</h2><p class="text-muted flex-grow-1">Registra y consulta matrículas por nivel, grado y sección.</p><a href="../asistente/matriculas.php" class="btn btn-info">Gestionar matrículas</a></div></div></div>
        <div class="col-md-4"><div class="card module-card shadow-sm"><div class="card-body p-4 d-flex flex-column"><h2 class="h5 text-primary">Cursos y periodos</h2><p class="text-muted flex-grow-1">Administra la estructura académica para registrar calificaciones.</p><a href="../asistente/cursos_periodos.php" class="btn btn-primary">Gestionar estructura</a></div></div></div>
        <div class="col-md-4"><div class="card module-card shadow-sm"><div class="card-body p-4 d-flex flex-column"><h2 class="h5 text-warning">Control de pagos</h2><p class="text-muted flex-grow-1">Consulta y registra cuotas y otros pagos escolares.</p><a href="../asistente/pagos.php" class="btn btn-warning">Gestionar pagos</a></div></div></div>
        <div class="col-md-4"><div class="card module-card shadow-sm"><div class="card-body p-4 d-flex flex-column"><h2 class="h5 text-dark">Permisos</h2><p class="text-muted flex-grow-1">Otorga o revoca permisos de los asistentes.</p><a href="permisos.php" class="btn btn-dark">Gestionar permisos</a></div></div></div>
        <div class="col-md-4"><div class="card module-card shadow-sm"><div class="card-body p-4 d-flex flex-column"><h2 class="h5 text-secondary">Auditoría</h2><p class="text-muted flex-grow-1">Consulta accesos y cambios realizados.</p><a href="auditoria.php" class="btn btn-secondary">Ver auditoría</a></div></div></div>
    </div>
</main>
</body>
</html>
