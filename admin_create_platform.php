<?php
session_start();
require_once 'functions.php';

// 非管理员则跳转
if (!is_admin()) {
    header("Location: index.php");
    exit;
}

// 处理删除请求
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
    $p_id = intval($_GET['id']);
    $conn->begin_transaction();
    try {
        // 1. 解除与资源的关联（不删除资源）
        $conn->query("DELETE FROM file_platform_relations WHERE platform_id = $p_id");
        // 2. 删除平台
        $conn->query("DELETE FROM platforms WHERE id = $p_id");
        $conn->commit();
        $success = '运行平台删除成功，已解除与所有资源的关联！';
    } catch (Exception $e) {
        $conn->rollback();
        $error = '删除失败：' . $e->getMessage();
    }
}

// 处理创建请求
$error_create = '';
$success_create = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $platform_name = trim($_POST['name']);
    
    if (empty($platform_name)) {
        $error_create = '平台名称不能为空';
    } else {
        $stmt = $conn->prepare("INSERT IGNORE INTO platforms (name) VALUES (?)");
        $stmt->bind_param("s", $platform_name);
        if ($stmt->execute()) {
            if ($conn->affected_rows > 0) {
                $success_create = '运行平台创建成功！';
            } else {
                $error_create = '该平台已存在';
            }
        } else {
            $error_create = '创建失败：' . $conn->error;
        }
    }
}

// 获取所有运行平台
$platforms = get_all_platforms();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>运行平台管理</title>
    <style>
        table { border-collapse: collapse; width: 100%; margin: 20px 0; }
        th, td { border: 1px solid #ccc; padding: 8px; text-align: left; }
        .delete-btn { color: #dc3545; text-decoration: none; }
    </style>
</head>
<body>
    <h1>运行平台管理</h1>
    <?php if (isset($success)): ?><p style="color: green;"><?php echo $success; ?></p><?php endif; ?>
    <?php if (isset($error)): ?><p style="color: red;"><?php echo $error; ?></p><?php endif; ?>
    
    <!-- 创建平台表单 -->
    <div>
        <h3>创建新运行平台</h3>
        <?php if ($error_create): ?><p style="color: red;"><?php echo $error_create; ?></p><?php endif; ?>
        <?php if ($success_create): ?><p style="color: green;"><?php echo $success_create; ?></p><?php endif; ?>
        <form method="post">
            <div>
                <label>平台名称:</label>
                <input type="text" name="name" required placeholder="如Forge、Fabric、Paper">
                <button type="submit">创建</button>
            </div>
        </form>
    </div>

    <!-- 平台列表 -->
    <h3>已创建的运行平台</h3>
    <table>
        <tr>
            <th>ID</th>
            <th>平台名称</th>
            <th>操作</th>
        </tr>
        <?php if ($platforms->num_rows > 0): ?>
            <?php while ($p = $platforms->fetch_assoc()): ?>
                <tr>
                    <td><?php echo $p['id']; ?></td>
                    <td><?php echo $p['name']; ?></td>
                    <td>
                        <a href="?action=delete&id=<?php echo $p['id']; ?>" class="delete-btn" onclick="return confirm('确定删除该平台吗？将解除与所有资源的关联！')">删除</a>
                    </td>
                </tr>
            <?php endwhile; ?>
        <?php else: ?>
            <tr>
                <td colspan="3" style="text-align: center;">暂无平台</td>
            </tr>
        <?php endif; ?>
    </table>

    <p><a href="admin_panel.php">返回管理员面板</a></p>
</body>
</html>