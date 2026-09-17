@extends('layouts.app')
@section('title', 'Matrículas')
@section('content')
<main class="container py-5">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4"><div><h1 class="h3 mb-1">Alumnos y matrículas</h1><p class="text-muted mb-0">Inicial, Primaria y Secundaria.</p></div><a href="{{ route('enrollments.create') }}" class="btn btn-success">Registrar matrícula</a></div>
    @if (session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    <form method="GET" class="module-search mb-4"><span class="module-search-icon" aria-hidden="true">&#128269;</span><label class="visually-hidden" for="buscar">Buscar alumno</label><input class="form-control" id="buscar" name="buscar" value="{{ $search }}" placeholder="Buscar por DNI o nombre"><button class="btn btn-primary" type="submit">Buscar</button></form>
    <section class="card shadow-sm border-0"><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th>Alumno</th><th>DNI</th><th>Nivel</th><th>Grado</th><th>Sección</th><th>Año</th></tr></thead><tbody>
    @forelse ($matriculas as $matricula)<tr><td>{{ $matricula->nombres }} {{ $matricula->apellido_paterno }} {{ $matricula->apellido_materno }}</td><td>{{ $matricula->dni }}</td><td>{{ ucfirst($matricula->nivel) }}</td><td>{{ $matricula->grado }}</td><td>{{ $matricula->seccion }}</td><td>{{ $matricula->anio }}</td></tr>@empty<tr><td colspan="6" class="text-center text-muted py-4">No hay matrículas registradas.</td></tr>@endforelse
    </tbody></table></div></section><div class="mt-3">{{ $matriculas->links() }}</div>
</main>
@endsection
