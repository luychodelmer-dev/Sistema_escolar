<?php
/**
 * Bootstrap de la Aplicación
 * Inicializa la zona horaria, la conexión central a la base de datos y la sesión persistente.
 */

date_default_timezone_set('America/Lima');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/session.php';

// Inicializar sesión persistente (compatible con Vercel Serverless y Local)
init_app_session($pdo ?? null);
