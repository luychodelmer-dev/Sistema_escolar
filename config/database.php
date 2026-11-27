<?php
/**
 * Configuración de Conexión a la Base de Datos (PostgreSQL)
 * Compatible con entornos locales, Vercel Serverless, Neon (con soporte SNI automático), Supabase, etc.
 */

$localConfigPath = __DIR__ . '/database.local.php';

$localConfig = [];
if (file_exists($localConfigPath)) {
    $localConfig = require $localConfigPath;
}

// Función auxiliar para buscar entre múltiples alias y fuentes (ENV, SERVER, getenv)
$findEnv = function(array $aliases, string $default = '') use ($localConfig): string {
    foreach ($aliases as $alias) {
        $val = $localConfig[$alias] 
            ?? $_ENV[$alias] 
            ?? $_SERVER[$alias] 
            ?? getenv($alias);

        if ($val !== false && $val !== null && trim((string)$val) !== '') {
            return trim((string)$val);
        }
    }
    return $default;
};

// 1. Comprobar si existe una URL de conexión completa (ej. DATABASE_URL, POSTGRES_URL)
$connUrl = $findEnv(['DATABASE_URL', 'POSTGRES_URL', 'POSTGRES_PRISMA_URL', 'NEON_DATABASE_URL']);
$options = '';

if (!empty($connUrl)) {
    $parsed = parse_url($connUrl);
    if ($parsed) {
        $host     = $parsed['host'] ?? 'localhost';
        $port     = (string)($parsed['port'] ?? '5432');
        $username = isset($parsed['user']) ? urldecode($parsed['user']) : 'postgres';
        $password = isset($parsed['pass']) ? urldecode($parsed['pass']) : '';
        $dbname   = ltrim($parsed['path'] ?? 'neondb', '/');
        
        parse_str($parsed['query'] ?? '', $queryParams);
        $sslmode  = $queryParams['sslmode'] ?? 'require';
        if (!empty($queryParams['options'])) {
            $options = $queryParams['options'];
        }
    }
}

// 2. Si no viene en formato URL, buscar variable por variable
if (empty($host)) {
    $host     = $findEnv(['SISTEMA_ESCOLAR_DB_HOST', 'DB_HOST', 'POSTGRES_HOST', 'PGHOST'], $localConfig['host'] ?? 'localhost');
    $port     = $findEnv(['SISTEMA_ESCOLAR_DB_PORT', 'DB_PORT', 'POSTGRES_PORT', 'PGPORT'], $localConfig['port'] ?? '5432');
    $dbname   = $findEnv(['SISTEMA_ESCOLAR_DB_NAME', 'DB_NAME', 'POSTGRES_DATABASE', 'PGDATABASE'], $localConfig['dbname'] ?? 'db_apafa_colegio');
    $username = $findEnv(['SISTEMA_ESCOLAR_DB_USER', 'DB_USER', 'POSTGRES_USER', 'PGUSER'], $localConfig['username'] ?? 'postgres');
    $password = $findEnv(['SISTEMA_ESCOLAR_DB_PASSWORD', 'DB_PASSWORD', 'POSTGRES_PASSWORD', 'PGPASSWORD'], $localConfig['password'] ?? '');
    $defaultSsl = ($host === 'localhost' || $host === '127.0.0.1') ? 'prefer' : 'require';
    $sslmode  = $findEnv(['SISTEMA_ESCOLAR_DB_SSLMODE', 'DB_SSLMODE', 'PGSSLMODE'], $localConfig['sslmode'] ?? $defaultSsl);
}

// 3. Soporte de respaldo para SNI de Neon cuando libpq no lo soporta nativamente
$endpointId = '';
if (strpos($host, '.neon.tech') !== false) {
    $parts = explode('.', $host);
    $rawEndpoint = $parts[0] ?? '';
    // Neon espera el endpoint ID sin el sufijo "-pooler" cuando se pasa vía parámetro de contraseña
    $endpointId = preg_replace('/-pooler$/', '', $rawEndpoint);
} elseif (!empty($options) && preg_match('/endpoint=([a-zA-Z0-9\-]+)/', $options, $m)) {
    $endpointId = $m[1];
}

try {
    $dsn = "pgsql:host={$host};port={$port};dbname={$dbname}";
    if (!empty($sslmode)) {
        $dsn .= ";sslmode={$sslmode}";
    }

    $pdoOptions = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT            => 10,
    ];

    try {
        $pdo = new PDO($dsn, $username, $password, $pdoOptions);
    } catch (PDOException $primaryException) {
        $msg = $primaryException->getMessage();
        $needsEndpointFallback = !empty($endpointId)
            && strpos($password, 'endpoint=') === false
            && (stripos($msg, 'Endpoint ID is not specified') !== false || stripos($msg, 'SNI') !== false);

        if (!$needsEndpointFallback) {
            throw $primaryException;
        }

        $fallbackPassword = "endpoint={$endpointId}$" . $password;
        $pdo = new PDO($dsn, $username, $fallbackPassword, $pdoOptions);
    }
} catch (PDOException $exception) {
    http_response_code(500);
    $maskedHost = htmlspecialchars($host);
    $maskedUser = htmlspecialchars($username);
    $maskedDb   = htmlspecialchars($dbname);
    $errorMsg   = htmlspecialchars($exception->getMessage());
    exit("No se pudo conectar con la base de datos.<br><small style='color:#666;'>Detalle: {$errorMsg} (Host intentado: {$maskedHost}, Base de datos: {$maskedDb}, Usuario: {$maskedUser})</small>");
}
