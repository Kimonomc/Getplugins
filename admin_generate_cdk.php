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
$generated_cdks = []; // 改为数组存储多个CDK
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $points = intval($_POST['points']);
    $count = intval($_POST['count']); // 获取生成数量
    
    // 验证积分和数量
    if ($points <= 0) {
        $error = '积分必须大于0';
    } elseif ($count <= 0 || $count > 100) { // 限制数量范围，防止恶意生成
        $error = '生成数量必须在1-100之间';
    } else {
        // 开启事务，确保批量插入的原子性
        $conn->begin_transaction();
        try {
            for ($i = 0; $i < $count; $i++) {
                // ========== 主要改动1：重写CDK生成逻辑 ==========
                // 定义CDK字符集：大写字母+数字
                $charset = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
                $cdk_segments = []; // 存储4个分段
                // 生成4个分段，每个分段4个字符
                for ($j = 0; $j < 4; $j++) {
                    $segment = '';
                    // 每个分段生成4个随机字符
                    for ($k = 0; $k < 4; $k++) {
                        $segment .= $charset[rand(0, strlen($charset) - 1)];
                    }
                    $cdk_segments[] = $segment;
                }
                // 用连字符连接4个分段，形成XXXX-XXXX-XXXX-XXXX格式
                $cdk = implode('-', $cdk_segments);
                // ========== 改动1结束 ==========
                
                // 插入CDK
                $stmt = $conn->prepare("INSERT INTO cdks (cdk, points) VALUES (?, ?)");
                $stmt->bind_param("si", $cdk, $points);
                $stmt->execute();
                $stmt->close();
                
                $generated_cdks[] = $cdk; // 将生成的CDK存入数组
            }
            
            // 提交事务
            $conn->commit();
            $success = "成功生成 {$count} 个CDK！";
        } catch (Exception $e) {
            // 回滚事务
            $conn->rollback();
            $error = '生成失败：' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>生成CDK</title>
    <style>
        .cdk-list {
            margin: 10px 0;
            padding: 10px;
            background-color: #f5f5f5;
            border-radius: 4px;
        }
        .cdk-item {
            padding: 5px 0;
            /* ========== 次要优化：CDK样式加粗，提升可读性 ========== */
            font-weight: bold;
            color: #2c3e50;
        }
    </style>
</head>
<body>
    <h1>生成CDK</h1>
    <?php if ($error): ?>
        <p style="color: red;"><?php echo $error; ?></p>
    <?php endif; ?>
    <?php if ($success): ?>
        <p style="color: green;"><?php echo $success; ?></p>
        <div class="cdk-list">
            <strong>生成的CDK列表：</strong>
            <?php foreach ($generated_cdks as $cdk): ?>
                <div class="cdk-item"><?php echo $cdk; ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <form method="post">
        <div style="margin-bottom: 10px;">
            <label>CDK对应的积分:</label>
            <input type="number" name="points" min="1" required>
        </div>
        <div style="margin-bottom: 10px;">
            <label>生成数量:</label>
            <input type="number" name="count" min="1" max="100" value="1" required>
        </div>
        <div>
            <button type="submit">批量生成</button>
        </div>
    </form>
    <p><a href="admin_panel.php">返回管理员面板</a></p>
    <p><a href="index.php">返回首页</a></p>
</body>
</html>