<?php
require_once __DIR__ . '/../../app/bootstrap.php';
destroy_stateless_session();
header("Location: /login.php");
exit;
?>