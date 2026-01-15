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
    $mv_id = intval($_GET['id']);
    $conn->begin_transaction();
    try {
        // 1. 解除与资源的关联（不删除资源）
        $conn->query("DELETE FROM file_mc_relations WHERE mc_version_id = $mv_id");
        // 2. 删除版本
        $conn->query("DELETE FROM mc_versions WHERE id = $mv_id");
        $conn->commit();
        $success = 'MC版本删除成功，已解除与所有资源的关联！';
    } catch (Exception $e) {
        $conn->rollback();
        $error = '删除失败：' . $e->getMessage();
    }
}

// 处理创建请求
$error_create = '';
$success_create = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $version_name = trim($_POST['name']);
    $parent_type = $_POST['parent_type'];
    
    if (empty($version_name)) {
        $error_create = '版本名称不能为空';
    } else {
        $stmt = $conn->prepare("INSERT IGNORE INTO mc_versions (name, parent_type) VALUES (?, ?)");
        $stmt->bind_param("ss", $version_name, $parent_type);
        if ($stmt->execute()) {
            if ($conn->affected_rows > 0) {
                $success_create = 'MC版本创建成功！';
            } else {
                $error_create = '该版本已存在';
            }
        } else {
            $error_create = '创建失败：' . $conn->error;
        }
    }
}

// 获取所有MC版本
$mc_versions = get_all_mc_versions();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>MC版本管理</title>
    <style>
        table { border-collapse: collapse; width: 100%; margin: 20px 0; }
        th, td { border: 1px solid #ccc; padding: 8px; text-align: left; }
        .delete-btn { color: #dc3545; text-decoration: none; }
    </style>
</head>
<body>
    <h1>MC版本管理</h1>
    <?php if (isset($success)): ?><p style="color: green;"><?php echo $success; ?></p><?php endif; ?>
    <?php if (isset($error)): ?><p style="color: red;"><?php echo $error; ?></p><?php endif; ?>
    
    <!-- 创建版本表单 -->
    <div>
        <h3>创建新MC版本</h3>
        <?php if ($error_create): ?><p style="color: red;"><?php echo $error_create; ?></p><?php endif; ?>
        <?php if ($success_create): ?><p style="color: green;"><?php echo $success_create; ?></p><?php endif; ?>
        <form method="post">
            <div>
                <label>版本类型:</label>
                <select name="parent_type" required>
                    <option value="java">Java版</option>
                    <option value="bedrock">基岩版</option>
                </select>
            </div>
            <div>
                <label>版本名称:</label>
                <input type="text" name="name" required placeholder="如1.21.x、1.20.80">
                <button type="submit">创建</button>
            </div>
        </form>
    </div>

    <!-- 版本列表 -->
    <h3>已创建的MC版本</h3>
    <table>
        <tr>
            <th>ID</th>
            <th>版本类型</th>
            <th>版本名称</th>
            <th>操作</th>
        </tr>
        <?php if ($mc_versions->num_rows > 0): ?>
            <?php while ($v = $mc_versions->fetch_assoc()): ?>
                <tr>
                    <td><?php echo $v['id']; ?></td>
                    <td><?php echo $v['parent_type'] == 'java' ? 'Java版' : '基岩版'; ?></td>
                    <td><?php echo $v['name']; ?></td>
                    <td>
                        <a href="?action=delete&id=<?php echo $v['id']; ?>" class="delete-btn" onclick="return confirm('确定删除该版本吗？将解除与所有资源的关联！')">删除</a>
                    </td>
                </tr>
            <?php endwhile; ?>
        <?php else: ?>
            <tr>
                <td colspan="4" style="text-align: center;">暂无版本</td>
            </tr>
        <?php endif; ?>
    </table>

    <p><a href="admin_panel.php">返回管理员面板</a></p>
</body>
</html>