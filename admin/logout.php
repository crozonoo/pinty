<?php
session_start();
$_SESSION = array(); // 清空 session 数组
session_destroy(); // 销毁 session
header('Location: index.php'); // 重定向到登录页面
exit;
?>
