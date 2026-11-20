<?php
session_start();

// 1. Requerimos la configuración de la base de datos
require_once '../../config/database.php';
require_once '../../app/auth.php';
require_once __DIR__ . '/../../app/header.php';

// Validar seguridad básica
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}
require_permission('p_padres');

$csrfToken = $_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));
$mensaje_exito = '';
$mensaje_error = '';

// 2. Procesar el formulario cuando se envía por POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!is_string($submittedToken) || !hash_equals($csrfToken, $submittedToken)) {
        $mensaje_error = 'Solicitud inválida o sesión expirada. Recarga la página e inténtalo nuevamente.';
    } else {
        $dni = trim($_POST['dni'] ?? '');
        $nombres = trim($_POST['nombres'] ?? '');
        $apellido_paterno = trim($_POST['apellido_paterno'] ?? '');
        $apellido_materno = trim($_POST['apellido_materno'] ?? '');
        $nombre_padre_adicional = trim($_POST['nombre_padre_adicional'] ?? '');
        $direccion = trim($_POST['direccion'] ?? '');
        $celular_actual = trim($_POST['celular_actual'] ?? '');
        $celular_padre = trim($_POST['celular_padre'] ?? '');
        $celular_madre = trim($_POST['celular_madre'] ?? '');
        $nro_tarjeta_apafa = trim($_POST['nro_tarjeta_apafa'] ?? '') ?: null; // Si está vacío, se guarda como NULL
    $nombres = mb_strtoupper($nombres, 'UTF-8');
    $apellido_paterno = mb_strtoupper($apellido_paterno, 'UTF-8');
    $apellido_materno = mb_strtoupper($apellido_materno, 'UTF-8');
    $nombre_padre_adicional = mb_strtoupper($nombre_padre_adicional, 'UTF-8');
    $direccion = mb_strtoupper($direccion, 'UTF-8');
    $nro_tarjeta_apafa = $nro_tarjeta_apafa !== null ? mb_strtoupper($nro_tarjeta_apafa, 'UTF-8') : null;

    $celularesValidos = true;
    foreach ([$celular_actual, $celular_padre, $celular_madre] as $celular) {
        if ($celular !== '' && !preg_match('/^[0-9]{9}$/', $celular)) {
            $celularesValidos = false;
            break;
        }
    }

    if (!empty($dni) && !empty($nombres) && !empty($apellido_paterno) && $celularesValidos) {
        try {
            $sql = "INSERT INTO responsable (
                        dni, nombres, apellido_paterno, apellido_materno, 
                        nombre_padre_adicional, direccion, celular_actual, 
                        celular_padre, celular_madre, nro_tarjeta_apafa
                    ) VALUES (
                        :dni, :nombres, :ap, :am, :padre_adic, :dir, :cel_act, :cel_p, :cel_m, :tarjeta
                    )";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                'dni' => $dni,
                'nombres' => $nombres,
                'ap' => $apellido_paterno,
                'am' => $apellido_materno,
                'padre_adic' => $nombre_padre_adicional,
                'dir' => $direccion,
                'cel_act' => $celular_actual,
                'cel_p' => $celular_padre,
                'cel_m' => $celular_madre,
                'tarjeta' => $nro_tarjeta_apafa
            ]);

            registrar_auditoria('crear_apoderado', 'Registró el apoderado con DNI ' . $dni . '.');

            $mensaje_exito = "¡Apoderado registrado correctamente!";
        } catch (PDOException $e) {
            // Manejo de errores (ej. si el DNI o Tarjeta APAFA ya existe)
            if ($e->getCode() == 23505) { // Código de error de violación de unicidad en PostgreSQL
                $mensaje_error = "Error: El DNI o el Número de Tarjeta APAFA ya están registrados en el sistema.";
            } else {
                $mensaje_error = "Error en la base de datos: " . $e->getMessage();
            }
        }
    } else {
        $mensaje_error = $celularesValidos
            ? "Por favor, completa los campos obligatorios (DNI, Nombres y Apellido Paterno)."
            : "Cada celular debe contener exactamente 9 dígitos.";
    }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Nuevo Apoderado - Sistema APAFA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../assets/css/app.css">
    <link rel="stylesheet" href="../assets/css/nuevo_padre.css">
</head>
<body class="bg-light">
<?php render_app_header('padres.php', 'logout.php'); ?>

    <div class="container pb-5">
        <div class="card form-card border-0 mx-auto" style="max-width: 900px;">
            <div class="card-header bg-primary text-white py-3 px-4" style="border-top-left-radius: 16px; border-top-right-radius: 16px;">
                <h4 class="mb-0 fw-bold fs-4"><i class="bi bi-person-plus-fill me-2"></i>Registrar Nuevo Apoderado</h4>
            </div>
            <div class="card-body p-4 p-md-5">
                
                <?php if ($mensaje_exito): ?>
                    <div class="alert alert-success alert-dismissible fade show fs-6" role="alert">
                        <strong>¡Éxito!</strong> <?= htmlspecialchars($mensaje_exito) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($mensaje_error): ?>
                    <div class="alert alert-danger alert-dismissible fade show fs-6" role="alert">
                        <strong>Atención:</strong> <?= htmlspecialchars($mensaje_error) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
                    <h5 class="section-header"><i class="bi bi-person-vcard me-2"></i>Datos de Identidad</h5>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">DNI <span class="text-danger">*</span></label>
                            <input type="text" name="dni" class="form-control font-monospace" maxlength="8" pattern="\d{8}" inputmode="numeric" style="text-transform: uppercase;" required placeholder="Ej: 71234567">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Nombres Completos <span class="text-danger">*</span></label>
                            <input type="text" name="nombres" class="form-control" style="text-transform: uppercase;" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Apellido Paterno <span class="text-danger">*</span></label>
                            <input type="text" name="apellido_paterno" class="form-control" style="text-transform: uppercase;" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Apellido Materno</label>
                            <input type="text" name="apellido_materno" class="form-control" style="text-transform: uppercase;">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Nombre Padre Adicional <small class="text-muted fw-normal">(Referencia complementaria)</small></label>
                            <input type="text" name="nombre_padre_adicional" class="form-control" style="text-transform: uppercase;" placeholder="Ej: Juan Perez (Padre biológico)">
                        </div>
                    </div>

                    <h5 class="section-header mt-4 pt-2"><i class="bi bi-telephone-inbound me-2"></i>Datos de Contacto y APAFA</h5>
                    <div class="row g-3">
                        <div class="col-md-12">
                            <label class="form-label">Dirección de Domicilio</label>
                            <input type="text" name="direccion" class="form-control" style="text-transform: uppercase;" placeholder="Ej: Av. Los Rosales 123">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label text-primary">Celular Actual (Principal)</label>
                            <input type="text" name="celular_actual" class="form-control" minlength="9" maxlength="9" pattern="[0-9]{9}" style="text-transform: uppercase;" placeholder="9 dígitos">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Celular Padre</label>
                            <input type="text" name="celular_padre" class="form-control" minlength="9" maxlength="9" pattern="[0-9]{9}" style="text-transform: uppercase;" placeholder="9 dígitos">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Celular Madre</label>
                            <input type="text" name="celular_madre" class="form-control" minlength="9" maxlength="9" pattern="[0-9]{9}" style="text-transform: uppercase;" placeholder="9 dígitos">
                        </div>
                        <div class="col-md-6 mt-4">
                            <label class="form-label text-success">N° Tarjeta APAFA</label>
                            <input type="text" name="nro_tarjeta_apafa" class="form-control border-success" style="text-transform: uppercase;" placeholder="Código de la tarjeta">
                        </div>
                    </div>

                    <div class="mt-5 text-end border-top pt-3">
                        <a href="padres.php" class="btn btn-outline-secondary px-4 py-2 me-2" style="border-radius: 9px; font-size: 1rem;">Cancelar</a>
                        <button type="submit" class="btn btn-primary px-4 py-2 fw-semibold" style="border-radius: 9px; font-size: 1rem;"><i class="bi bi-save me-1"></i> Guardar Apoderado</button>
                    </div>
                </form>

            </div>
        </div>
    </div>

    <!-- Script de Bootstrap para alertas -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>