<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/auth.php';

$publicPath = defined('PUBLIC_PATH') ? PUBLIC_PATH : '';

// Asegurar existencia de tabla en la base de datos
try {
    $pdo->exec(<<<SQL
    CREATE TABLE IF NOT EXISTS solicitud_reseteo_password (
        id BIGSERIAL PRIMARY KEY,
        usuario_id BIGINT NOT NULL REFERENCES usuario(id) ON DELETE CASCADE,
        codigo VARCHAR(10) NOT NULL,
        token VARCHAR(64) NOT NULL UNIQUE,
        estado VARCHAR(20) NOT NULL DEFAULT 'pendiente' CHECK (estado IN ('pendiente', 'aprobada', 'completada', 'rechazada')),
        ip_solicitud VARCHAR(45),
        creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        expira_en TIMESTAMP NOT NULL,
        completado_en TIMESTAMP
    );
    CREATE INDEX IF NOT EXISTS idx_solicitud_reseteo_codigo ON solicitud_reseteo_password(codigo);
    CREATE INDEX IF NOT EXISTS idx_solicitud_reseteo_token ON solicitud_reseteo_password(token);
SQL);
} catch (Throwable $e) {
    // Si ya existe continúa normalmente
}

$error = '';
$mensaje = '';
$step = 1;
$correoConfirm = trim($_GET['correo'] ?? '');
$codigoGenerado = '';

if ($correoConfirm !== '') {
    $step = 2;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'solicitar') {
        $correo = trim($_POST['correo'] ?? '');
        if (empty($correo) || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            $error = 'Por favor ingresa un correo electrónico institucional válido.';
        } else {
            $stmt = $pdo->prepare('SELECT id, nombres, correo, estado FROM usuario WHERE LOWER(correo) = LOWER(:correo)');
            $stmt->execute(['correo' => $correo]);
            $usuario = $stmt->fetch();

            if ($usuario && !empty($usuario['estado'])) {
                // Invalidar solicitudes anteriores pendientes
                $cancelOld = $pdo->prepare("UPDATE solicitud_reseteo_password SET estado = 'rechazada' WHERE usuario_id = :uid AND estado = 'pendiente'");
                $cancelOld->execute(['uid' => $usuario['id']]);

                // Generar código numérico de 6 dígitos y token único
                $codigo = str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
                $token = bin2hex(random_bytes(32));
                $expiraEn = date('Y-m-d H:i:s', strtotime('+2 hours'));
                $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

                $insertStmt = $pdo->prepare(
                    'INSERT INTO solicitud_reseteo_password (usuario_id, codigo, token, expira_en, ip_solicitud)
                     VALUES (:usuario_id, :codigo, :token, :expira_en, :ip_solicitud)'
                );
                $insertStmt->execute([
                    'usuario_id' => $usuario['id'],
                    'codigo' => $codigo,
                    'token' => $token,
                    'expira_en' => $expiraEn,
                    'ip_solicitud' => $ip,
                ]);

                registrar_auditoria('solicitud_reseteo', "Generó solicitud de reseteo de contraseña para {$usuario['correo']}.", (int)$usuario['id']);

                $step = 2;
                $correoConfirm = $usuario['correo'];
                $codigoGenerado = $codigo;
                $mensaje = 'Solicitud registrada correctamente. Ingresa el código otorgado por la Dirección para cambiar tu contraseña.';
            } else {
                $error = 'No se encontró ninguna cuenta activa con ese correo electrónico.';
            }
        }
    } elseif ($action === 'cambiar') {
        $correo = trim($_POST['correo'] ?? '');
        $codigo = trim($_POST['codigo'] ?? '');
        $password = $_POST['password'] ?? '';
        $passwordConfirm = $_POST['password_confirm'] ?? '';

        $step = 2;
        $correoConfirm = $correo;

        if (empty($correo) || empty($codigo) || empty($password)) {
            $error = 'Por favor completa todos los campos requeridos.';
        } elseif (strlen($codigo) !== 6 || !ctype_digit($codigo)) {
            $error = 'El código de autorización debe ser de 6 números.';
        } elseif (strlen($password) < 8) {
            $error = 'La nueva contraseña debe tener al menos 8 caracteres.';
        } elseif ($password !== $passwordConfirm) {
            $error = 'Las contraseñas ingresadas no coinciden.';
        } else {
            $stmt = $pdo->prepare('SELECT id, nombres, correo, estado FROM usuario WHERE LOWER(correo) = LOWER(:correo)');
            $stmt->execute(['correo' => $correo]);
            $usuario = $stmt->fetch();

            if (!$usuario || empty($usuario['estado'])) {
                $error = 'La cuenta no es válida o está desactivada.';
            } else {
                // Verificar código pendiente y vigente
                $checkStmt = $pdo->prepare(
                    "SELECT id FROM solicitud_reseteo_password
                     WHERE usuario_id = :uid AND codigo = :codigo AND estado = 'pendiente' AND expira_en > CURRENT_TIMESTAMP
                     ORDER BY id DESC LIMIT 1"
                );
                $checkStmt->execute([
                    'uid' => $usuario['id'],
                    'codigo' => $codigo,
                ]);
                $solicitudId = $checkStmt->fetchColumn();

                if (!$solicitudId) {
                    $error = 'El código de autorización es inválido o ha expirado. Verifica con la Dirección o solicita uno nuevo.';
                } else {
                    // Actualizar contraseña
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $updateUser = $pdo->prepare('UPDATE usuario SET password_hash = :hash, actualizado_en = CURRENT_TIMESTAMP WHERE id = :uid');
                    $updateUser->execute([
                        'hash' => $hash,
                        'uid' => $usuario['id'],
                    ]);

                    // Marcar solicitud como completada
                    $updateReq = $pdo->prepare("UPDATE solicitud_reseteo_password SET estado = 'completada', completado_en = CURRENT_TIMESTAMP WHERE id = :id");
                    $updateReq->execute(['id' => $solicitudId]);

                    registrar_auditoria('password_restablecida', "Contraseña restablecida exitosamente para {$usuario['correo']}.", (int)$usuario['id']);

                    header("Location: {$publicPath}login.php?recuperado=1");
                    exit;
                }
            }
        }
    }
}

// Detección de entorno local / intranet
$isLocalEnvironment = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1', 'localhost'], true)
    || str_contains($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1')
    || str_contains($_SERVER['HTTP_HOST'] ?? '', 'localhost');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Recuperar Contraseña - Sistema Escolar</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= htmlspecialchars($publicPath) ?>assets/css/recuperar_password.css">
</head>
<body class="recovery-page d-flex align-items-center justify-content-center">
    <main class="recovery-shell">
        <!-- Encabezado Institucional -->
        <header class="institution-header">
            <img class="institution-logo" src="<?= htmlspecialchars($publicPath) ?>assets/images/logo-institucion.jpg.jpg" alt="Logo I.E. Divino Maestro">
            <h1 class="institution-name">Institución Educativa &quot;Divino Maestro&quot; de Mollepampa-Cajamarca</h1>
        </header>

        <!-- Tarjeta Principal -->
        <div class="recovery-card">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <span class="badge-intranet">
                    <i class="bi bi-shield-lock-fill"></i> Red Interna / Intranet
                </span>
                <span class="text-muted small">Paso <?= $step ?> de 2</span>
            </div>

            <h2 class="h4 fw-bold text-dark mb-1">Recuperar Contraseña</h2>
            <p class="text-muted small mb-3">Gestión de reseteo autorizada para personal docente y administrativo.</p>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger py-2 px-3 small mb-3 border-0 shadow-sm" role="alert">
                    <i class="bi bi-exclamation-circle-fill me-1"></i> <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($mensaje)): ?>
                <div class="alert alert-success py-2 px-3 small mb-3 border-0 shadow-sm" role="alert">
                    <i class="bi bi-check-circle-fill me-1"></i> <?= htmlspecialchars($mensaje) ?>
                </div>
            <?php endif; ?>

            <?php if ($step === 1): ?>
                <!-- PASO 1: Ingresar correo registrado -->
                <form method="POST" class="mt-2">
                    <input type="hidden" name="action" value="solicitar">
                    <div class="mb-3">
                        <label for="correo" class="form-label">Correo electrónico registrado</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0 text-muted"><i class="bi bi-envelope"></i></span>
                            <input type="email" class="form-control border-start-0" id="correo" name="correo" placeholder="ejemplo@colegio.com" required autocomplete="email">
                        </div>
                        <div class="form-text small mt-1">El sistema verificará tu cuenta y generará una solicitud de reseteo.</div>
                    </div>

                    <button type="submit" class="btn btn-primary btn-submit w-100">
                        Generar Solicitud de Reseteo &rarr;
                    </button>

                    <div class="text-center mt-3">
                        <a href="<?= htmlspecialchars($publicPath) ?>login.php" class="back-link">
                            <i class="bi bi-arrow-left"></i> Volver a Iniciar Sesión
                        </a>
                    </div>
                </form>

            <?php else: ?>
                <!-- PASO 2: Confirmar código y cambiar contraseña -->
                <div class="alert alert-light border py-2 px-3 small mb-3 text-muted">
                    <i class="bi bi-info-circle-fill text-primary me-1"></i>
                    Para <strong><?= htmlspecialchars($correoConfirm) ?></strong>. Solicita a la <strong>Dirección</strong> tu código de autorización de 6 dígitos.
                </div>

                <?php if ($isLocalEnvironment && !empty($codigoGenerado)): ?>
                    <div class="alert alert-info py-2 px-3 small mb-3 d-flex align-items-center justify-content-between">
                        <div>
                            <i class="bi bi-pc-display me-1"></i> <strong>Modo Intranet Local:</strong> Código generado:
                        </div>
                        <span class="badge bg-primary fs-6 px-2 py-1 font-monospace"><?= htmlspecialchars($codigoGenerado) ?></span>
                    </div>
                <?php endif; ?>

                <form method="POST" class="mt-2">
                    <input type="hidden" name="action" value="cambiar">
                    <input type="hidden" name="correo" value="<?= htmlspecialchars($correoConfirm) ?>">

                    <div class="mb-3">
                        <label for="codigo" class="form-label">Código de Autorización (6 dígitos)</label>
                        <input type="text" class="form-control code-input font-monospace" id="codigo" name="codigo" maxlength="6" pattern="[0-9]{6}" placeholder="------" required autofocus autocomplete="one-time-code" value="<?= htmlspecialchars($codigoGenerado) ?>">
                        <div class="form-text small text-center mt-1">Código otorgado directamente por la Dirección escolar.</div>
                    </div>

                    <div class="mb-3">
                        <label for="password" class="form-label">Nueva Contraseña</label>
                        <div class="password-wrap">
                            <input type="password" class="form-control" id="password" name="password" minlength="8" required placeholder="Mínimo 8 caracteres">
                            <button type="button" class="password-toggle" id="togglePassword">Mostrar</button>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="password_confirm" class="form-label">Confirmar Nueva Contraseña</label>
                        <div class="password-wrap">
                            <input type="password" class="form-control" id="password_confirm" name="password_confirm" minlength="8" required placeholder="Repite la contraseña">
                            <button type="button" class="password-toggle" id="togglePasswordConfirm">Mostrar</button>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary btn-submit w-100">
                        <i class="bi bi-check-lg me-1"></i> Guardar Nueva Contraseña
                    </button>

                    <div class="d-flex justify-content-between align-items-center mt-3">
                        <a href="recuperar_password.php" class="back-link">
                            <i class="bi bi-arrow-repeat"></i> Solicitar otro código
                        </a>
                        <a href="<?= htmlspecialchars($publicPath) ?>login.php" class="back-link">
                            Volver al Login &rarr;
                        </a>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </main>

    <script src="<?= htmlspecialchars($publicPath) ?>assets/js/recuperar_password.js" defer></script>
</body>
</html>
