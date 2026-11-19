<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/auth.php';

$publicPath = defined('PUBLIC_PATH') ? PUBLIC_PATH : '';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $correo = trim($_POST['correo'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!empty($correo) && !empty($password)) {
        // Buscamos al usuario por su correo en la base de datos
        $stmt = $pdo->prepare("SELECT * FROM usuario WHERE correo = :correo AND estado = TRUE");
        $stmt->execute(['correo' => $correo]);
        $usuario = $stmt->fetch();

        // Verificamos si el usuario existe y si la contraseña coincide con el hash seguro
        if ($usuario && password_verify($password, $usuario['password_hash'])) {
            // Guardamos los datos clave en la sesión del navegador
            $_SESSION['user_id'] = $usuario['id'];
            $_SESSION['nombres'] = $usuario['nombres'];
            $_SESSION['rol'] = $usuario['rol'];
            $_SESSION['p_matricular'] = !empty($usuario['permiso_matricular'] ?? false);
            $_SESSION['p_cobrar'] = !empty($usuario['permiso_cobrar'] ?? false);
            $_SESSION['p_editar'] = !empty($usuario['permiso_editar'] ?? false);
            registrar_auditoria('inicio_sesion', 'Inicio de sesión correcto.');

            // Redirigimos según el rol (Director o Asistente)
            if (in_array($usuario['rol'], ['director', 'hiperadmin'], true)) {
                $publicPath = defined('APP_PUBLIC_PATH') ? APP_PUBLIC_PATH : '';
                header("Location: {$publicPath}director/panel.php");
            } else {
                $publicPath = defined('APP_PUBLIC_PATH') ? APP_PUBLIC_PATH : '';
                header("Location: {$publicPath}asistente/panel.php");
            }
            exit;
        } else {
            $error = "Correo o contraseña incorrectos.";
        }
    } else {
        $error = "Por favor completa todos los campos.";
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Iniciar Sesión - Sistema Escolar</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= htmlspecialchars($publicPath) ?>assets/css/login.css">
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

            <?php if (isset($_GET['recuperado'])): ?>
                <div class="alert alert-success text-center py-2 px-3 small mb-3 border-0 shadow-sm" role="alert">
                    ¡Contraseña restablecida exitosamente! Ya puedes ingresar con tu nueva contraseña.
                </div>
            <?php endif; ?>

            <?php if (!empty($error)): ?>
                <p class="text-danger text-center small mb-3" role="alert"><?= htmlspecialchars($error) ?></p>
            <?php endif; ?>
        
            <form method="POST">
                <div class="mb-1">
                    <label for="correo" class="visually-hidden">Correo electrónico</label>
                    <input type="email" class="form-control" id="correo" name="correo" placeholder="Correo electrónico" autocomplete="email" required>
                </div>
                <div class="mb-2">
                    <label for="password" class="visually-hidden">Contraseña</label>
                    <div class="password-wrap">
                        <input type="password" class="form-control" id="password" name="password" placeholder="Contraseña" autocomplete="current-password" required>
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