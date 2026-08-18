<?php
$role = $_GET['role'] ?? null;
require_once 'config.php';
destroyRoleSession(in_array($role, ['kasir', 'pemilik']) ? $role : null);
header("Location: login.php");
exit();
?>
