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
        $username = $parsed['user'] ?? 'postgres';
        $password = $parsed['pass'] ?? '';
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
    $sslmode  = $findEnv(['SISTEMA_ESCOLAR_DB_SSLMODE', 'DB_SSLMODE', 'PGSSLMODE'], $localConfig['sslmode'] ?? 'require');
}

// 3. Soporte automático para SNI de Neon si el host pertenece a neon.tech
if (empty($options) && strpos($host, '.neon.tech') !== false) {
    $parts = explode('.', $host);
    $firstPart = $parts[0] ?? '';
    $endpointId = preg_replace('/-pooler$/', '', $firstPart);
    if (!empty($endpointId)) {
        $options = 'endpoint=' . $endpointId;
    }
}

try {
    $dsn = "pgsql:host={$host};port={$port};dbname={$dbname}";
    if (!empty($sslmode)) {
        $dsn .= ";sslmode={$sslmode}";
    }
    if (!empty($options)) {
        // En DSN de PDO PostgreSQL el parámetro de options requiere codificación
        $dsn .= ";options=" . urlencode($options);
    }

    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT            => 10,
    ]);
} catch (PDOException $exception) {
    http_response_code(500);
    $maskedHost = htmlspecialchars($host);
    $maskedUser = htmlspecialchars($username);
    $maskedDb   = htmlspecialchars($dbname);
    $errorMsg   = htmlspecialchars($exception->getMessage());
    exit("No se pudo conectar con la base de datos.<br><small style='color:#666;'>Detalle: {$errorMsg} (Host intentado: {$maskedHost}, Base de datos: {$maskedDb}, Usuario: {$maskedUser})</small>");
}
