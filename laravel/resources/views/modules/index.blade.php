@extends('layouts.app')
@section('title', $title)
@section('content')
<main class="container py-5"><div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4"><div><h1 class="h3 mb-1">{{ $title }}</h1><p class="text-muted mb-0">{{ $description }}</p></div><a href="{{ auth()->user()->rol === 'director' ? route('director.dashboard') : route('assistant.dashboard') }}" class="btn btn-outline-primary">Volver al panel</a></div><section class="card shadow-sm border-0"><div class="card-body p-4"><p class="mb-0">Este módulo ya está conectado a Laravel. La migración del formulario y sus operaciones de base de datos continuará aquí.</p></div></section></main>
@endsection
