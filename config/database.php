<?php
/**
 * Configuración de Conexión a la Base de Datos (PostgreSQL)
 */

$localConfigPath = __DIR__ . '/database.local.php';

$localConfig = [];
if (file_exists($localConfigPath)) {
    $localConfig = require $localConfigPath;
}

$host     = $localConfig['host'] ?? getenv('SISTEMA_ESCOLAR_DB_HOST') ?: 'localhost';
$port     = $localConfig['port'] ?? getenv('SISTEMA_ESCOLAR_DB_PORT') ?: '5432';
$dbname   = $localConfig['dbname'] ?? getenv('SISTEMA_ESCOLAR_DB_NAME') ?: 'db_apafa_colegio';
$username = $localConfig['username'] ?? getenv('SISTEMA_ESCOLAR_DB_USER') ?: 'postgres';
$password = $localConfig['password'] ?? getenv('SISTEMA_ESCOLAR_DB_PASSWORD') ?: '';
$sslmode  = $localConfig['sslmode'] ?? getenv('SISTEMA_ESCOLAR_DB_SSLMODE') ?: '';

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
    exit('No se pudo conectar con la base de datos.');
}
