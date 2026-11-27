<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/auth.php';

$publicPath = defined('PUBLIC_PATH') ? PUBLIC_PATH : '';

$error = '';
$errorField = '';
$correo = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $correo = trim($_POST['correo'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($correo === '' || $password === '') {
        $error = 'Por favor completa tu correo electrónico y contraseña.';
        if ($correo === '' && $password !== '') {
            $errorField = 'correo';
        } elseif ($correo !== '' && $password === '') {
            $errorField = 'password';
        } else {
            $errorField = 'both';
        }
    } elseif (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        $error = 'El formato del correo electrónico no es válido.';
        $errorField = 'correo';
    } else {
        // Buscamos al usuario por su correo insensible a mayúsculas
        $stmt = $pdo->prepare("SELECT * FROM usuario WHERE LOWER(TRIM(correo)) = LOWER(:correo) LIMIT 1");
        $stmt->execute(['correo' => $correo]);
        $usuario = $stmt->fetch();

        if (!$usuario) {
            $error = 'La cuenta de correo ingresada no existe o no está asociada al sistema.';
            $errorField = 'correo';
        } else {
            $estadoActivo = $usuario['estado'] === true
                || $usuario['estado'] === 't'
                || $usuario['estado'] === 1
                || $usuario['estado'] === '1';

            if (!$estadoActivo) {
                $error = 'Esta cuenta se encuentra desactivada o revocada. Comunícate con la Dirección.';
                $errorField = 'correo';
            } elseif (!password_verify($password, $usuario['password_hash'])) {
                $error = 'La contraseña ingresada es incorrecta. Verifica e inténtalo nuevamente.';
                $errorField = 'password';
            } else {
                // Guardamos los datos clave en la sesión del navegador
                $_SESSION['user_id'] = $usuario['id'];
                $_SESSION['nombres'] = $usuario['nombres'];
                $_SESSION['rol'] = $usuario['rol'];
                $_SESSION['p_matricular'] = !empty($usuario['permiso_matricular'] ?? false);
                $_SESSION['p_cobrar'] = !empty($usuario['permiso_cobrar'] ?? false);
                $_SESSION['p_editar'] = !empty($usuario['permiso_editar'] ?? false);
                registrar_auditoria('inicio_sesion', 'Inicio de sesión correcto.');

                // Persistir inmediatamente la sesión en la cookie firmada (Serverless)
                save_stateless_session();

                // Redirigimos según el rol (Director o Asistente)
                if (in_array($usuario['rol'], ['director', 'hiperadmin'], true)) {
                    header("Location: /director/panel.php");
                } else {
                    header("Location: /asistente/panel.php");
                }
                exit;
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Iniciar Sesión - Sistema Escolar</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= htmlspecialchars($publicPath) ?>assets/css/login.css?v=2">
</head>
<body class="login-page d-flex align-items-center justify-content-center py-4">
    <main class="login-shell">
        <header class="institution-header">
            <img class="institution-logo" src="<?= htmlspecialchars($publicPath) ?>assets/images/logo-institucion.jpg.jpg" alt="Logo de la Institución Educativa Divino Maestro">
            <h1 class="institution-name">Institución Educativa &quot;Divino Maestro&quot; de Mollepampa-Cajamarca</h1>
        </header>
        <div class="login-card p-3 p-sm-4">
            <div class="text-center mb-4">
                <span class="brand-mark mb-3">DM</span>
                <h1 class="login-title h3 fw-bold mb-2">Divino Maestro</h1>
                <p class="login-subtitle mb-0">Ingresa para continuar con tu jornada</p>
            </div>

            <?php if (empty($error) && isset($_GET['recuperado'])): ?>
                <div class="alert alert-success login-alert py-2 px-3 small mt-2 mb-3 border-0 shadow-sm" role="alert">
                    ¡Contraseña restablecida exitosamente! Ya puedes ingresar con tu nueva contraseña.
                </div>
            <?php endif; ?>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger login-alert py-2 px-3 small mt-2 mb-3 border-0 shadow-sm" role="alert">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>
        
            <form method="POST">
                <div class="mb-1">
                    <label for="correo" class="visually-hidden">Correo electrónico</label>
                    <input type="email" class="form-control<?= in_array($errorField, ['correo', 'both'], true) ? ' is-invalid' : '' ?>" id="correo" name="correo" value="<?= htmlspecialchars($correo ?? '') ?>" placeholder="Correo electrónico" autocomplete="email"<?= in_array($errorField, ['correo', 'both'], true) ? ' autofocus' : '' ?> required>
                </div>
                <div class="mb-2">
                    <label for="password" class="visually-hidden">Contraseña</label>
                    <div class="password-wrap">
                        <input type="password" class="form-control<?= in_array($errorField, ['password', 'both'], true) ? ' is-invalid' : '' ?>" id="password" name="password" placeholder="Contraseña" autocomplete="current-password"<?= $errorField === 'password' ? ' autofocus' : '' ?> required>
                        <button type="button" class="password-toggle" id="togglePassword">Mostrar</button>
                    </div>
                </div>
                <button type="submit" class="login-button btn btn-primary w-100 fw-semibold">Ingresar</button>
                <a class="forgot-link" href="<?= htmlspecialchars($publicPath) ?>recuperar_password.php">¿Olvidaste tu contraseña?</a>
            </form>
        </div>
    </main>

    <script src="<?= htmlspecialchars($publicPath) ?>assets/js/login.js" defer></script>
</body>
</html>