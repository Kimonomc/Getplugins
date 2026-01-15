<?php
session_start();
// 清除session
session_unset();
session_destroy();
header("Location: index.php");
exit;
?>