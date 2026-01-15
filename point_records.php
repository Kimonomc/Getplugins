<?php
session_start();
require_once 'functions.php';

// 未登录则跳转
if (!is_logged_in()) {
    header("Location: login.php");
    exit;
}

// 查询积分记录
$user_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT * FROM point_records WHERE user_id = ? ORDER BY created_at DESC");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$records = $stmt->get_result();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>积分记录查询</title>
</head>
<body>
    <h1>积分记录查询</h1>
    <p>当前积分: <?php echo $_SESSION['points']; ?></p>
    <table border="1">
        <tr>
            <th>类型</th>
            <th>积分变动</th>
            <th>备注</th>
            <th>时间</th>
        </tr>
        <?php if ($records->num_rows > 0): ?>
            <?php while ($record = $records->fetch_assoc()): ?>
                <tr>
                    <td><?php 
                        switch ($record['type']) {
                            case 'recharge': echo '充值(CDK)'; break;
                            case 'consume': echo '消费(购买)'; break;
                            case 'admin_modify': echo '管理员修改'; break;
                            default: echo '未知';
                        }
                    ?></td>
                    <td><?php echo $record['points']; ?></td>
                    <td><?php echo $record['remark']; ?></td>
                    <td><?php echo $record['created_at']; ?></td>
                </tr>
            <?php endwhile; ?>
        <?php else: ?>
            <tr>
                <td colspan="4">暂无积分记录</td>
            </tr>
        <?php endif; ?>
    </table>
    <p><a href="index.php">返回首页</a></p>
</body>
</html>