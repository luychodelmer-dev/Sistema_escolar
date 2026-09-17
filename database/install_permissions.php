<?php
require __DIR__ . '/../config/database.php';
$sql = file_get_contents(__DIR__ . '/permissions.sql');
$pdo->exec($sql);
echo "PERMISSIONS_SCHEMA_READY\n";
