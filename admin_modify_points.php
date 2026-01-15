<?php
session_start();
require_once 'functions.php';

// 非管理员则跳转
if (!is_admin()) {
    header("Location: index.php");
    exit;
}

$error = '';
$success = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $points = intval($_POST['points']);
    $remark = trim($_POST['remark']);
    
    if (empty($username) || $points == 0) {
        $error = '用户名不能为空，积分变动值不能为0';
    } else {
        // 查询用户
        $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows == 1) {
            $user = $result->fetch_assoc();
            $user_id = $user['id'];
            
            // 开启事务
            $conn->begin_transaction();
            try {
                // 更新用户积分
                $stmt1 = $conn->prepare("UPDATE users SET points = points + ? WHERE id = ?");
                $stmt1->bind_param("ii", $points, $user_id);
                $stmt1->execute();
                
                // 记录积分变动
                $stmt2 = $conn->prepare("INSERT INTO point_records (user_id, type, points, remark) VALUES (?, 'admin_modify', ?, ?)");
                $stmt2->bind_param("iis", $user_id, $points, $remark);
                $stmt2->execute();
                
                $conn->commit();
                $success = "积分修改成功！用户 $username 的积分变动: $points";
            } catch (Exception $e) {
                $conn->rollback();
                $error = '修改失败: ' . $e->getMessage();
            }
        } else {
            $error = '用户不存在';
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>修改用户积分</title>
</head>
<body>
    <h1>修改用户积分</h1>
    <?php if ($error): ?>
        <p style="color: red;"><?php echo $error; ?></p>
    <?php endif; ?>
    <?php if ($success): ?>
        <p style="color: green;"><?php echo $success; ?></p>
    <?php endif; ?>
    <form method="post">
        <div>
            <label>用户名:</label>
            <input type="text" name="username" required>
        </div>
        <div>
            <label>积分变动(正数增加，负数减少):</label>
            <input type="number" name="points" required>
        </div>
        <div>
            <label>备注:</label>
            <input type="text" name="remark" placeholder="可选">
        </div>
        <div>
            <button type="submit">提交</button>
        </div>
    </form>
    <p><a href="admin_panel.php">返回管理员面板</a></p>
    <p><a href="index.php">返回首页</a></p>
</body>
</html>