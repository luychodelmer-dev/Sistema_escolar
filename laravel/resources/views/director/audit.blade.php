@extends('layouts.app')
@section('title', 'Auditoría')
@section('content')
<main class="container py-5"><div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4"><div><h1 class="h3 mb-1">Auditoría de movimientos</h1><p class="text-muted mb-0">Registro de accesos y cambios del sistema.</p></div><a href="{{ route('director.dashboard') }}" class="btn btn-outline-primary">Volver al panel</a></div>
<section class="card shadow-sm border-0"><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th>Fecha</th><th>Usuario</th><th>Acción</th><th>Detalles</th><th>IP</th></tr></thead><tbody>@forelse ($movements as $movement)<tr><td>{{ $movement->creado_en }}</td><td>{{ $movement->usuario ?? 'Usuario eliminado' }}</td><td>{{ $movement->accion }}</td><td>{{ $movement->detalles }}</td><td>{{ $movement->ip_origen ?: '-' }}</td></tr>@empty<tr><td colspan="5" class="text-center text-muted py-4">No hay movimientos registrados.</td></tr>@endforelse</tbody></table></div></section><div class="mt-3">{{ $movements->links() }}</div></main>
@endsection
