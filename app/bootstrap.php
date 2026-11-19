<?php
/**
 * Bootstrap de la Aplicación
 * Inicializa la sesión, la zona horaria y la conexión central a la base de datos.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('America/Lima');

require_once __DIR__ . '/../config/database.php';
