<?php
/**
 * Gestor de Sesiones Stateless y Persistentes para Serverless (Vercel)
 * Utiliza cookies firmadas criptográficamente con HMAC-SHA256
 * para garantizar latencia cero y persistencia absoluta entre funciones lambda.
 */

const SESSION_COOKIE_NAME = 'sistema_auth_token';
const SESSION_SECRET_SALT = 'colegio_divino_maestro_sec_2026_x89a';

/**
 * Inicializa la sesión PHP estándar con atributos de seguridad.
 */
function init_app_session(?PDO $pdo = null): void
{
    if (session_status() === PHP_SESSION_NONE) {
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
            || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);

        session_set_cookie_params([
            'lifetime' => 86400 * 7,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);

        session_start();
    }

    // Cargar automáticamente los datos desde la cookie firmada
    load_stateless_session();
}

/**
 * Genera la clave secreta para firmar la cookie.
 */
function get_session_secret(): string
{
    $envSecret = getenv('DATABASE_URL') ?: getenv('SISTEMA_ESCOLAR_DB_PASSWORD') ?: 'default_salt_key_dm_2026';
    return hash('sha256', $envSecret . SESSION_SECRET_SALT);
}

/**
 * Carga los datos de sesión desde la cookie firmada si existen.
 */
function load_stateless_session(): void
{
    if (empty($_COOKIE[SESSION_COOKIE_NAME])) {
        return;
    }

    $raw = (string)$_COOKIE[SESSION_COOKIE_NAME];
    $parts = explode('.', $raw, 2);
    if (count($parts) !== 2) {
        return;
    }

    [$payloadB64, $signature] = $parts;
    $expectedSig = hash_hmac('sha256', $payloadB64, get_session_secret());

    if (!hash_equals($expectedSig, $signature)) {
        return;
    }

    $json = base64_decode($payloadB64, true);
    if (!$json) {
        return;
    }

    $data = json_decode($json, true);
    if (!is_array($data)) {
        return;
    }

    // Verificar expiración (7 días)
    if (isset($data['_exp']) && time() > (int)$data['_exp']) {
        return;
    }

    // Hidratar $_SESSION
    foreach ($data as $key => $val) {
        if ($key !== '_exp') {
            $_SESSION[$key] = $val;
        }
    }
}

/**
 * Guarda los datos de sesión actuales en la cookie firmada.
 */
function save_stateless_session(): void
{
    $data = $_SESSION ?? [];
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);

    if (empty($data)) {
        if (isset($_COOKIE[SESSION_COOKIE_NAME])) {
            setcookie(SESSION_COOKIE_NAME, '', [
                'expires'  => time() - 3600,
                'path'     => '/',
                'secure'   => $isHttps,
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
        }
        return;
    }

    $data['_exp'] = time() + (86400 * 7); // 7 días de validez
    $payloadB64 = base64_encode(json_encode($data));
    $signature = hash_hmac('sha256', $payloadB64, get_session_secret());
    $cookieValue = $payloadB64 . '.' . $signature;

    setcookie(SESSION_COOKIE_NAME, $cookieValue, [
        'expires'  => time() + (86400 * 7),
        'path'     => '/',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
}

/**
 * Destruye la sesión firmada y la sesión PHP.
 */
function destroy_stateless_session(): void
{
    $_SESSION = [];
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);

    setcookie(SESSION_COOKIE_NAME, '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}
