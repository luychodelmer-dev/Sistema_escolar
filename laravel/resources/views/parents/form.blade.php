@if ($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<form method="POST" action="{{ $action }}" class="row g-3">@csrf @if ($method !== 'POST') @method($method) @endif
@if (!($editOnly ?? false))
<div class="col-md-4"><label class="form-label" for="nombres">Nombres</label><input class="form-control" id="nombres" name="nombres" value="{{ old('nombres', $padre->nombres ?? '') }}" required></div>
<div class="col-md-4"><label class="form-label" for="apellido_paterno">Apellido paterno</label><input class="form-control" id="apellido_paterno" name="apellido_paterno" value="{{ old('apellido_paterno', $padre->apellido_paterno ?? '') }}" required></div>
<div class="col-md-4"><label class="form-label" for="apellido_materno">Apellido materno</label><input class="form-control" id="apellido_materno" name="apellido_materno" value="{{ old('apellido_materno', $padre->apellido_materno ?? '') }}" required></div>
<div class="col-md-3"><label class="form-label" for="dni">DNI</label><input class="form-control" id="dni" name="dni" maxlength="8" value="{{ old('dni', $padre->dni ?? '') }}" required></div>
<div class="col-md-3"><label class="form-label" for="vinculo">Vínculo</label><select class="form-select" id="vinculo" name="vinculo" required><option value="">Seleccionar</option>@foreach (['Padre', 'Madre', 'Apoderado', 'Tutor'] as $vinculo)<option @selected(old('vinculo', $padre->vinculo ?? '') === $vinculo)>{{ $vinculo }}</option>@endforeach</select></div>
<div class="col-md-3"><label class="form-label" for="numero_tarjeta">Tarjeta APAFA</label><input class="form-control" id="numero_tarjeta" name="numero_tarjeta" value="{{ old('numero_tarjeta', $padre->numero_tarjeta ?? '') }}"></div>
<div class="col-md-3"><label class="form-label" for="cuota_apafa">Cuota APAFA</label><input class="form-control" id="cuota_apafa" name="cuota_apafa" type="number" min="0" step="0.01" value="{{ old('cuota_apafa', $padre->cuota_apafa ?? 0) }}"></div>
@endif
<div class="col-12"><label class="form-label" for="direccion">Dirección</label><input class="form-control" id="direccion" name="direccion" value="{{ old('direccion', $padre->direccion ?? '') }}" required></div>
<div class="col-md-4"><label class="form-label" for="celular_actual">Celular actual</label><input class="form-control" id="celular_actual" name="celular_actual" value="{{ old('celular_actual', $padre->celular_actual ?? '') }}"></div>
<div class="col-md-4"><label class="form-label" for="celular_padre">Celular padre</label><input class="form-control" id="celular_padre" name="celular_padre" value="{{ old('celular_padre', $padre->celular_padre ?? '') }}"></div>
<div class="col-md-4"><label class="form-label" for="celular_madre">Celular madre</label><input class="form-control" id="celular_madre" name="celular_madre" value="{{ old('celular_madre', $padre->celular_madre ?? '') }}"></div>
<div class="col-12 d-flex justify-content-end gap-2"><a href="{{ route('parents.index') }}" class="btn btn-secondary">Cancelar</a><button class="btn btn-primary" type="submit">Guardar</button></div>
</form>
