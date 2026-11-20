<?php
/**
 * Configuración de Conexión a la Base de Datos (PostgreSQL)
 */

$localConfigPath = __DIR__ . '/database.local.php';

$localConfig = [];
if (file_exists($localConfigPath)) {
    $localConfig = require $localConfigPath;
}

$getVal = function(string $key, string $default = '') use ($localConfig): string {
    $candidates = [
        $localConfig[$key] ?? null,
        $_ENV[$key] ?? null,
        $_SERVER[$key] ?? null,
        getenv($key) ?: null,
    ];
    foreach ($candidates as $cand) {
        if ($cand !== null && $cand !== false && $cand !== '') {
            return (string)$cand;
        }
    }
    return $default;
};

$host     = $getVal('SISTEMA_ESCOLAR_DB_HOST', $localConfig['host'] ?? 'localhost');
$port     = $getVal('SISTEMA_ESCOLAR_DB_PORT', $localConfig['port'] ?? '5432');
$dbname   = $getVal('SISTEMA_ESCOLAR_DB_NAME', $localConfig['dbname'] ?? 'db_apafa_colegio');
$username = $getVal('SISTEMA_ESCOLAR_DB_USER', $localConfig['username'] ?? 'postgres');
$password = $getVal('SISTEMA_ESCOLAR_DB_PASSWORD', $localConfig['password'] ?? '');
$sslmode  = $getVal('SISTEMA_ESCOLAR_DB_SSLMODE', $localConfig['sslmode'] ?? '');

try {
    $dsn = "pgsql:host={$host};port={$port};dbname={$dbname}";
    if (!empty($sslmode)) {
        $dsn .= ";sslmode={$sslmode}";
    }

    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $exception) {
    http_response_code(500);
    $maskedHost = htmlspecialchars($host);
    $maskedUser = htmlspecialchars($username);
    $errorMsg = htmlspecialchars($exception->getMessage());
    exit("No se pudo conectar con la base de datos.<br><small style='color:#666;'>Detalle: {$errorMsg} (Host intentado: {$maskedHost}, Usuario: {$maskedUser})</small>");
}
