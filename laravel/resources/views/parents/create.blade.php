@extends('layouts.app')
@section('title', 'Registrar apoderado')
@section('content')
<main class="container py-5"><div class="card shadow-sm border-0 mx-auto" style="max-width: 980px"><div class="card-body p-4"><h1 class="h4 mb-4">Registrar apoderado</h1>@include('parents.form', ['action' => route('parents.store'), 'method' => 'POST', 'padre' => null])</div></div></main>
@endsection
