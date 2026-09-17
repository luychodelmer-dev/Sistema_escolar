<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Iniciar sesión - Sistema Escolar</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { min-height: 100vh; overflow: auto; background: linear-gradient(rgba(255,255,255,.48), rgba(255,255,255,.48)), url("{{ asset('assets/images/institucion.jpg.jfif') }}") center / cover fixed; color: #172b4d; }
        .login-shell { width: min(100% - 2rem, 490px); }
        .institution-header { display: flex; align-items: center; gap: .9rem; }
        .brand-logo { flex: 0 0 112px; width: 112px; height: 112px; object-fit: contain; border-radius: 50%; box-shadow: 0 6px 14px rgba(23,43,77,.18); }
        .institution-name { margin: 0; font-size: 1.28rem; line-height: 1.3; }
        .login-card { margin-top: 1.35rem; width: 100%; background: rgba(247,247,247,.94); border-radius: 4px; box-shadow: 0 14px 30px rgba(23,43,77,.15); }
        .form-control { min-height: 52px; font-size: 1rem; border-radius: 2px; }
        .login-button { min-height: 52px; font-size: 1rem; }
        .forgot-link { display: block; margin-top: .75rem; color: #1264c7; text-align: center; text-decoration: none; }
        .password-wrap { position: relative; }
        .password-wrap input { padding-right: 78px; }
        .password-toggle { position: absolute; top: 50%; right: 10px; transform: translateY(-50%); border: 0; color: #6b7a90; background: transparent; font-weight: 700; }
        @media (max-width: 420px) { .brand-logo { flex-basis: 86px; width: 86px; height: 86px; } .institution-name { font-size: 1rem; } }
    </style>
</head>
<body class="d-flex align-items-center justify-content-center py-4">
<main class="login-shell">
    <header class="institution-header"><img class="brand-logo" src="{{ asset('assets/images/logo-institucion.jpg.jpg') }}" alt="Logo institucional"><h1 class="institution-name fw-bold">Institución Educativa &quot;Divino Maestro&quot; de Mollepampa-Cajamarca</h1></header>
    <div class="login-card p-4">
    @if ($errors->any())<div class="alert alert-danger py-2">{{ $errors->first() }}</div>@endif
    <form method="POST" action="{{ route('login.store') }}">@csrf
        <div class="mb-3"><label class="visually-hidden" for="correo">Correo electrónico</label><input class="form-control" id="correo" name="correo" type="email" value="{{ old('correo') }}" placeholder="Correo electrónico" required autofocus></div>
        <div class="mb-3"><label class="visually-hidden" for="password">Contraseña</label><div class="password-wrap"><input class="form-control" id="password" name="password" type="password" placeholder="Contraseña" required><button class="password-toggle" type="button" id="togglePassword">Mostrar</button></div></div>
        <button class="login-button btn btn-primary w-100 fw-semibold" type="submit">Ingresar</button>
        <a class="forgot-link" href="mailto:administracion@colegio.com?subject=Recuperar%20contraseña">¿Olvidaste tu contraseña?</a>
    </form>
    </div>
</main>
<script>const passwordInput = document.getElementById('password'); const togglePassword = document.getElementById('togglePassword'); togglePassword.addEventListener('click', () => { const visible = passwordInput.type === 'password'; passwordInput.type = visible ? 'text' : 'password'; togglePassword.textContent = visible ? 'Ocultar' : 'Mostrar'; });</script>
</body>
</html>
