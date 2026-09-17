@extends('layouts.app')
@section('title', 'Permisos de asistentes')
@section('content')
<main class="container py-5"><div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4"><div><h1 class="h3 mb-1">Permisos de asistentes</h1><p class="text-muted mb-0">El director otorga o revoca permisos de operación.</p></div><a href="{{ route('director.dashboard') }}" class="btn btn-outline-primary">Volver al panel</a></div>
@if (session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
<section class="card shadow-sm border-0"><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Asistente</th>@foreach ($permissions as $label)<th>{{ $label }}</th>@endforeach<th>Guardar</th></tr></thead><tbody>
@forelse ($assistants as $assistant)<tr><form method="POST" action="{{ route('director.permissions.update') }}">@csrf<input type="hidden" name="usuario_id" value="{{ $assistant->id }}"><td><strong>{{ $assistant->nombres }}</strong><br><small class="text-muted">{{ $assistant->correo }}</small></td>@foreach ($permissions as $permission => $label)<td><input class="form-check-input" type="checkbox" name="permisos[{{ $permission }}]" @checked($permissionMap[$assistant->id][$permission] ?? false) aria-label="{{ $label }} para {{ $assistant->nombres }}"></td>@endforeach<td><button class="btn btn-primary btn-sm" type="submit">Guardar</button></td></form></tr>@empty<tr><td colspan="5" class="text-center text-muted py-4">No hay asistentes activos.</td></tr>@endforelse
</tbody></table></div></section></main>
@endsection
