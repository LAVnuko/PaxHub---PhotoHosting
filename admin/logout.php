<?php
session_start();
unset($_SESSION['admin_logged_in']);
unset($_SESSION['admin_user_id']);
unset($_SESSION['admin_username']);
header('Location: /admin/login.php');
exit;