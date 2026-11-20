<?php
/**
 * Gestor de Sesiones Persistentes para Entornos Serverless y Tradicionales
 * Almacena las sesiones en PostgreSQL (Neon) para que persistan entre invocaciones lambda en Vercel.
 */

class PdoSessionHandler implements SessionHandlerInterface
{
    private PDO $pdo;
    private string $table;
    private static bool $tableReady = false;

    public function __construct(PDO $pdo, string $table = 'session_storage')
    {
        $this->pdo = $pdo;
        $this->table = $table;
        $this->ensureTable();
    }

    private function ensureTable(): void
    {
        if (self::$tableReady) {
            return;
        }

        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS session_storage (
                    id VARCHAR(128) PRIMARY KEY,
                    data TEXT NOT NULL,
                    last_activity BIGINT NOT NULL
                )
            ");
            self::$tableReady = true;
        } catch (\Throwable $e) {
            // Silencioso si la tabla ya existe o se carece de permisos DDL
        }
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        try {
            $expire = time() - (86400 * 7); // Expira a los 7 días de inactividad
            $stmt = $this->pdo->prepare("SELECT data FROM session_storage WHERE id = :id AND last_activity > :expire");
            $stmt->execute(['id' => $id, 'expire' => $expire]);
            $data = $stmt->fetchColumn();
            return $data !== false ? (string)$data : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    public function write(string $id, string $data): bool
    {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO session_storage (id, data, last_activity)
                VALUES (:id, :data, :time)
                ON CONFLICT (id) DO UPDATE 
                SET data = EXCLUDED.data, last_activity = EXCLUDED.last_activity
            ");
            return $stmt->execute([
                'id' => $id,
                'data' => $data,
                'time' => time(),
            ]);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function destroy(string $id): bool
    {
        try {
            $stmt = $this->pdo->prepare("DELETE FROM session_storage WHERE id = :id");
            return $stmt->execute(['id' => $id]);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function gc(int $max_lifetime): int|false
    {
        try {
            $expire = time() - $max_lifetime;
            $stmt = $this->pdo->prepare("DELETE FROM session_storage WHERE last_activity < :expire");
            $stmt->execute(['expire' => $expire]);
            return (int)$stmt->rowCount();
        } catch (\Throwable $e) {
            return false;
        }
    }
}

/**
 * Inicializa la sesión con cookies seguras y almacenamiento en base de datos.
 */
function init_app_session(?PDO $pdo = null): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);

    // Configurar parámetros de cookie seguros para compatibilidad con Vercel
    session_set_cookie_params([
        'lifetime' => 86400 * 7,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    // Registrar manejador de sesión persistente en PostgreSQL si PDO está disponible
    if ($pdo instanceof PDO) {
        $handler = new PdoSessionHandler($pdo);
        session_set_save_handler($handler, true);
    }

    session_start();
}
