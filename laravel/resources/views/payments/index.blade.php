@extends('layouts.app')
@section('title', 'Control de pagos')
@section('content')
<main class="container py-5">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4"><div><h1 class="h3 mb-1">Control de pagos</h1><p class="text-muted mb-0">Consulta pagos por alumno, concepto o estado.</p></div><a href="{{ route('payments.create') }}" class="btn btn-warning">Registrar pago</a></div>
    @if (session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    <form method="GET" class="module-search mb-4"><span class="module-search-icon" aria-hidden="true">&#128269;</span><label class="visually-hidden" for="buscar">Buscar pago</label><input class="form-control" id="buscar" name="buscar" value="{{ $search }}" placeholder="Buscar por DNI, alumno, concepto o estado"><button class="btn btn-primary" type="submit">Buscar</button></form>
    <section class="card shadow-sm border-0"><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th>Alumno</th><th>Concepto</th><th>Monto</th><th>Recibo</th><th>Estado</th><th>Fecha</th></tr></thead><tbody>
    @forelse ($pagos as $pago)<tr><td>{{ $pago->nombres }} {{ $pago->apellido_paterno }}<br><small class="text-muted">DNI: {{ $pago->dni }}</small></td><td>{{ $pago->concepto }}</td><td>S/ {{ number_format((float) $pago->monto, 2) }}</td><td>{{ $pago->nro_recibo ?: '-' }}</td><td><span class="badge {{ $pago->estado_pago === 'pagado' ? 'bg-success' : ($pago->estado_pago === 'anulado' ? 'bg-danger' : 'bg-warning text-dark') }}">{{ ucfirst($pago->estado_pago ?: 'pendiente') }}</span></td><td>{{ $pago->fecha_pago }}</td></tr>@empty<tr><td colspan="6" class="text-center text-muted py-4">No hay pagos registrados.</td></tr>@endforelse
    </tbody></table></div></section><div class="mt-3">{{ $pagos->links() }}</div>
</main>
@endsection
