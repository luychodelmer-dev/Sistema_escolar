<?php
/**
 * Router y Front-Controller para despliegue Serverless en Vercel
 * Redirige dinámicamente las solicitudes hacia las vistas en public/
 */

$rawUri = $_SERVER['REQUEST_URI'] ?? '/';
$uriPath = parse_url($rawUri, PHP_URL_PATH) ?: '/';

// Normalizar la ruta raíz a login.php
if ($uriPath === '/' || $uriPath === '' || $uriPath === '/index.php') {
    $targetRelPath = 'login.php';
} else {
    // Quitar prefijo /public/ si viene incluido
    $cleanUri = preg_replace('#^/public/#', '/', $uriPath);
    $cleanUri = ltrim($cleanUri, '/');
    
    if (is_file(__DIR__ . '/../public/' . $cleanUri) && pathinfo($cleanUri, PATHINFO_EXTENSION) === 'php') {
        $targetRelPath = $cleanUri;
    } elseif (is_file(__DIR__ . '/../public/' . $cleanUri . '.php')) {
        $targetRelPath = $cleanUri . '.php';
    } else {
        $targetRelPath = null;
    }
}

if ($targetRelPath) {
    $targetFile = realpath(__DIR__ . '/../public/' . $targetRelPath);
    $publicRoot = realpath(__DIR__ . '/../public');

    // Validar seguridad para evitar Directory Traversal fuera de public/
    if ($targetFile && strpos($targetFile, $publicRoot) === 0 && is_file($targetFile)) {
        // Establecer el directorio de trabajo actual para que las rutas relativas funcionen
        chdir(dirname($targetFile));

        // Ajustar variables de servidor de manera transparente
        $normalizedScriptName = '/' . str_replace('\\', '/', $targetRelPath);
        $_SERVER['SCRIPT_NAME'] = $normalizedScriptName;
        $_SERVER['PHP_SELF'] = $normalizedScriptName;
        $_SERVER['SCRIPT_FILENAME'] = $targetFile;

        require $targetFile;
        exit;
    }
}

// Manejo de 404
http_response_code(404);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>404 - Página no encontrada</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="bg-light d-flex align-items-center justify-content-center vh-100">
    <div class="text-center p-4 bg-white rounded shadow-sm" style="max-width: 480px;">
        <h1 class="display-4 text-primary fw-bold">404</h1>
        <h4 class="mb-3">Página no encontrada</h4>
        <p class="text-muted mb-4">El recurso solicitado no existe o fue movido dentro del sistema escolar.</p>
        <a href="/login.php" class="btn btn-primary px-4 fw-semibold">Ir al Inicio de Sesión</a>
    </div>
</body>
</html>
