<?php
session_start();
require_once 'functions.php';

// 非管理员则跳转
if (!is_admin()) {
    header("Location: index.php");
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>管理员面板</title>
</head>
<body>
    <h1>管理员面板</h1>
    <div>
        <ul>
            <li><a href="admin_modify_points.php">修改用户积分</a></li>
            <li><a href="admin_generate_cdk.php">生成CDK</a></li>
            <li><a href="admin_create_category.php">分类管理</a></li>
            <li><a href="admin_create_mc_version.php">MC版本管理</a></li>
            <li><a href="admin_create_platform.php">运行平台管理</a></li>
            <li><a href="admin_manage_files.php">资源管理</a></li>
            <li><a href="admin_upload_file.php">上传新资源</a></li>
        </ul>
    </div>
    <p><a href="index.php">返回首页</a></p>
</body>
</html>