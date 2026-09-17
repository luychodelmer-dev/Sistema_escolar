@extends('layouts.app')
@section('title', 'Padres y apoderados')
@section('content')
<main class="container py-5">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4"><div><h1 class="h3 mb-1">Padres y apoderados</h1><p class="text-muted mb-0">Consulta la información de los responsables.</p></div>@if ($puedeEditar)<a href="{{ route('parents.create') }}" class="btn btn-primary">Registrar apoderado</a>@endif</div>
    @if (session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    <form method="GET" class="module-search mb-4"><span class="module-search-icon" aria-hidden="true">&#128269;</span><label class="visually-hidden" for="buscar">Buscar apoderado</label><input class="form-control" id="buscar" name="buscar" value="{{ $buscar }}" placeholder="Buscar por DNI o nombre"><button class="btn btn-primary" type="submit">Buscar</button></form>
    <section class="card shadow-sm border-0"><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th>Nombre</th><th>DNI</th><th>Vínculo</th><th>Teléfonos</th><th>Dirección</th>@if ($puedeEditar)<th>Acciones</th>@endif</tr></thead><tbody>
    @forelse ($padres as $padre)<tr><td>{{ $padre->nombres }} {{ $padre->apellido_paterno }} {{ $padre->apellido_materno }}</td><td>{{ $padre->dni }}</td><td>{{ $padre->vinculo }}</td><td>{{ $padre->celular_actual ?: '-' }}<br><small class="text-muted">P: {{ $padre->celular_padre ?: '-' }} · M: {{ $padre->celular_madre ?: '-' }}</small></td><td>{{ $padre->direccion }}</td>@if ($puedeEditar)<td><a href="{{ route('parents.edit', $padre->id) }}" class="btn btn-sm btn-outline-warning">Editar</a></td>@endif</tr>@empty<tr><td colspan="6" class="text-center text-muted py-4">No hay registros para mostrar.</td></tr>@endforelse
    </tbody></table></div></section>
    <div class="mt-3">{{ $padres->links() }}</div>
</main>
@endsection
