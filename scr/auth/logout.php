<?php
require_once '../config/session.php';

logout();

// Xóa remember me cookie
if (isset($_COOKIE['remember_token'])) {
    setcookie('remember_token', '', time() - 3600, '/', '', false, true);
}

header('Location: ../index.php');
exit;
?>
