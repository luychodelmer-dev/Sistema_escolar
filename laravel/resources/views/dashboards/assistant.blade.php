@extends('layouts.app')
@section('title', 'Panel de Asistente')
@section('content')
<main class="container py-5">
    <div class="mb-4"><h1 class="h3 mb-1">Panel de Asistente</h1><p class="text-muted mb-0">Gestiona la información escolar autorizada.</p></div>
    <div class="row g-3 mb-4">
        <div class="col-md-4"><div class="stat-card bg-primary"><span>Padres registrados</span><strong>{{ $stats['padres'] }}</strong></div></div>
        <div class="col-md-4"><div class="stat-card bg-success"><span>Matrículas</span><strong>{{ $stats['matriculas'] }}</strong></div></div>
        <div class="col-md-4"><div class="stat-card bg-warning text-dark"><span>Pagos registrados</span><strong>{{ $stats['pagos'] }}</strong></div></div>
    </div>
    <form class="module-search mb-4" id="moduleSearchForm">
        <span class="module-search-icon" aria-hidden="true">&#128269;</span>
        <label class="visually-hidden" for="moduleSearch">Buscar módulo</label>
        <input class="form-control" id="moduleSearch" type="search" placeholder="Buscar módulos: padres, matrículas o pagos" autocomplete="off">
        <button class="btn btn-primary" type="submit">Buscar</button>
    </form>
    <div class="row g-4">
        <div class="col-lg-8"><div class="row g-4">
            <div class="col-md-6 module-item" data-search="padres apoderados responsables contactos"><div class="card module-card shadow-sm"><div class="card-body p-4 d-flex flex-column"><h2 class="h5 text-primary">Padres y apoderados</h2><p class="text-muted flex-grow-1">Consulta responsables y, si tienes permiso, edita sus contactos.</p><a href="{{ route('parents.index') }}" class="btn btn-primary">Gestionar padres</a></div></div></div>
            @if ($permissions['matricular'])<div class="col-md-6 module-item" data-search="alumnos matrículas matriculas niveles grados secciones"><div class="card module-card shadow-sm"><div class="card-body p-4 d-flex flex-column"><h2 class="h5 text-success">Alumnos y matrículas</h2><p class="text-muted flex-grow-1">Gestiona matrículas por nivel, grado y sección.</p><a href="{{ route('enrollments.index') }}" class="btn btn-success">Gestionar matrículas</a></div></div></div>@endif
            @if ($permissions['cobrar'])<div class="col-md-6 module-item" data-search="pagos cuotas apafa qaliwarma multas"><div class="card module-card shadow-sm"><div class="card-body p-4 d-flex flex-column"><h2 class="h5 text-warning">Control de pagos</h2><p class="text-muted flex-grow-1">Registra cuotas y otros pagos escolares.</p><a href="{{ route('payments.index') }}" class="btn btn-warning">Gestionar pagos</a></div></div></div>@endif
        </div></div>
        <div class="col-lg-4"><section class="card shadow-sm border-0 h-100"><div class="card-body p-4"><h2 class="h5 mb-3">Mi actividad reciente</h2>@forelse ($recentActivity as $event)<div class="border-bottom py-2"><strong class="small">{{ $event->accion }}</strong><div class="text-muted small">{{ $event->creado_en }}</div></div>@empty<p class="text-muted small mb-0">Aún no tienes movimientos registrados.</p>@endforelse</div></section></div>
    </div>
    <p class="text-center text-muted mt-4 d-none" id="noResults">No encontramos un módulo para esa búsqueda.</p>
</main>
<script>const searchForm = document.getElementById('moduleSearchForm'); const searchInput = document.getElementById('moduleSearch'); const moduleItems = [...document.querySelectorAll('.module-item')]; const noResults = document.getElementById('noResults'); const filterModules = () => { const query = searchInput.value.trim().toLocaleLowerCase(); let visible = 0; moduleItems.forEach((item) => { const matches = !query || item.dataset.search.includes(query); item.classList.toggle('d-none', !matches); if (matches) visible++; }); noResults.classList.toggle('d-none', visible > 0); }; searchInput.addEventListener('input', filterModules); searchForm.addEventListener('submit', (event) => { event.preventDefault(); filterModules(); });</script>
@endsection
