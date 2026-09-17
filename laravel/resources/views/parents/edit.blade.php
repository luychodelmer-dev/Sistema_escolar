@extends('layouts.app')
@section('title', 'Editar apoderado')
@section('content')
<main class="container py-5"><div class="card shadow-sm border-0 mx-auto" style="max-width: 760px"><div class="card-body p-4"><h1 class="h4 mb-1">Editar contacto</h1><p class="text-muted mb-4">{{ $padre->nombres }} {{ $padre->apellido_paterno }} {{ $padre->apellido_materno }}</p>@include('parents.form', ['action' => route('parents.update', $padre->id), 'method' => 'PUT', 'padre' => $padre, 'editOnly' => true])</div></div></main>
@endsection
