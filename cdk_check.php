<?php
session_start();
require_once 'functions.php';

// 未登录则跳转
if (!is_logged_in()) {
    header("Location: login.php");
    exit;
}

$cdk_info = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $cdk = trim($_POST['cdk']);
    if (empty($cdk)) {
        $error = 'CDK不能为空';
    } else {
        $stmt = $conn->prepare("SELECT cdk, points, used, used_at, used_by FROM cdks WHERE cdk = ?");
        $stmt->bind_param("s", $cdk);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows == 1) {
            $cdk_data = $result->fetch_assoc();
            $status = $cdk_data['used'] == 1 ? '已使用' : '未使用';
            $used_time = $cdk_data['used_at'] ? $cdk_data['used_at'] : '无';
            $used_user = $cdk_data['used_by'] ? $cdk_data['used_by'] : '无';
            $cdk_info = "CDK: $cdk<br>积分: {$cdk_data['points']}<br>状态: $status<br>使用时间: $used_time<br>使用用户ID: $used_user";
        } else {
            $error = 'CDK不存在';
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>CDK状态查询</title>
</head>
<body>
    <h1>CDK状态查询</h1>
    <?php if ($error): ?>
        <p style="color: red;"><?php echo $error; ?></p>
    <?php endif; ?>
    <?php if ($cdk_info): ?>
        <div style="margin: 10px 0; padding: 10px; border: 1px solid #ccc;">
            <?php echo $cdk_info; ?>
        </div>
    <?php endif; ?>
    <form method="post">
        <div>
            <label>CDK:</label>
            <input type="text" name="cdk" required placeholder="请输入CDK">
        </div>
        <div>
            <button type="submit">查询</button>
        </div>
    </form>
    <p><a href="index.php">返回首页</a></p>
</body>
</html>