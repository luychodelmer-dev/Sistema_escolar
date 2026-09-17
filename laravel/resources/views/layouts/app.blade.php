<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Sistema Escolar')</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f4f7fb; color: #172b4d; }
        .page-header { background: #172b4d; color: #fff; }
        .institution-brand { display: flex; align-items: center; gap: .6rem; white-space: nowrap; }
        .institution-brand img { width: 42px; height: 42px; object-fit: contain; border-radius: 50%; background: #fff; }
        .institution-brand span { font-size: 1.1rem; white-space: nowrap; }
        .header-user-photo { width: 52px; height: 52px; object-fit: cover; border: 2px solid rgba(255, 255, 255, .85); border-radius: 50%; }
        .module-card { height: 100%; border: 0; border-radius: 12px; transition: transform .2s ease, box-shadow .2s ease; }
        .module-card:hover { transform: translateY(-3px); box-shadow: 0 .75rem 1.5rem rgba(23, 43, 77, .12) !important; }
        .stat-card { display: flex; align-items: center; justify-content: space-between; min-height: 92px; padding: 1.25rem; border-radius: 8px; color: #fff; box-shadow: 0 4px 12px rgba(23, 43, 77, .12); }
        .stat-card strong { font-size: 2rem; line-height: 1; }
        .module-search { display: flex; gap: .5rem; padding: .4rem; background: #fff; border: 1px solid #e2e2e2; border-radius: 6px; box-shadow: 0 2px 8px rgba(23, 43, 77, .08); }
        .module-search-icon { display: flex; align-items: center; padding: 0 .8rem; color: #1261a0; font-size: 1.3rem; }
        .module-search input { min-height: 48px; border: 0; box-shadow: none; }
        .module-search button { min-width: 110px; }
        @media (max-width: 768px) { .institution-brand span { font-size: .95rem; } }
    </style>
</head>
<body>
<header class="page-header py-3">
    <div class="container d-flex flex-wrap align-items-center justify-content-between gap-3">
        <a class="institution-brand text-white text-decoration-none fw-semibold" href="{{ auth()->user()->rol === 'director' ? route('director.dashboard') : route('assistant.dashboard') }}">
            <img src="{{ asset('assets/images/logo-institucion.jpg.jpg') }}" alt="Logo de la Institución Educativa Divino Maestro">
            <span>Institución Educativa &quot;Divino Maestro&quot; de Mollepampa-Cajamarca</span>
        </a>
        <div class="header-user d-flex align-items-center gap-3">
            <img class="header-user-photo" src="{{ asset('assets/images/imagen-directora.jpg.png') }}" alt="Imagen institucional">
            <span class="badge bg-secondary">{{ auth()->user()->rol === 'director' ? 'Director' : 'Asistente' }}</span>
            <form method="POST" action="{{ route('logout') }}">@csrf<button class="btn btn-outline-light btn-sm" type="submit">Cerrar sesión</button></form>
        </div>
    </div>
</header>
@yield('content')
</body>
</html>
