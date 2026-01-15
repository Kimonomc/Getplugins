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
    $cat_id = intval($_GET['id']);
    $conn->begin_transaction();
    try {
        // 1. 将关联资源的分类设为NULL（不删除资源）
        $conn->query("UPDATE files SET category_id = NULL WHERE category_id = $cat_id");
        // 2. 删除分类
        $conn->query("DELETE FROM categories WHERE id = $cat_id");
        $conn->commit();
        $success = '分类删除成功，关联资源已设为未分类！';
    } catch (Exception $e) {
        $conn->rollback();
        $error = '删除失败：' . $e->getMessage();
    }
}

// 处理创建请求
$error_create = '';
$success_create = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $category_name = trim($_POST['name']);
    if (empty($category_name)) {
        $error_create = '分类名称不能为空';
    } else {
        $stmt = $conn->prepare("INSERT IGNORE INTO categories (name) VALUES (?)");
        $stmt->bind_param("s", $category_name);
        if ($stmt->execute()) {
            if ($conn->affected_rows > 0) {
                $success_create = '分类创建成功！';
            } else {
                $error_create = '该分类已存在';
            }
        } else {
            $error_create = '创建失败：' . $conn->error;
        }
    }
}

// 获取所有分类
$categories = get_all_categories();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>分类管理</title>
    <style>
        table { border-collapse: collapse; width: 100%; margin: 20px 0; }
        th, td { border: 1px solid #ccc; padding: 8px; text-align: left; }
        .delete-btn { color: #dc3545; text-decoration: none; }
    </style>
</head>
<body>
    <h1>分类管理</h1>
    <?php if (isset($success)): ?><p style="color: green;"><?php echo $success; ?></p><?php endif; ?>
    <?php if (isset($error)): ?><p style="color: red;"><?php echo $error; ?></p><?php endif; ?>
    
    <!-- 创建分类表单 -->
    <div>
        <h3>创建新分类</h3>
        <?php if ($error_create): ?><p style="color: red;"><?php echo $error_create; ?></p><?php endif; ?>
        <?php if ($success_create): ?><p style="color: green;"><?php echo $success_create; ?></p><?php endif; ?>
        <form method="post">
            <div>
                <label>分类名称:</label>
                <input type="text" name="name" required placeholder="如插件、模组、地图">
                <button type="submit">创建</button>
            </div>
        </form>
    </div>

    <!-- 分类列表 -->
    <h3>已创建的分类</h3>
    <table>
        <tr>
            <th>ID</th>
            <th>分类名称</th>
            <th>操作</th>
        </tr>
        <?php if ($categories->num_rows > 0): ?>
            <?php while ($cat = $categories->fetch_assoc()): ?>
                <tr>
                    <td><?php echo $cat['id']; ?></td>
                    <td><?php echo $cat['name']; ?></td>
                    <td>
                        <a href="?action=delete&id=<?php echo $cat['id']; ?>" class="delete-btn" onclick="return confirm('确定删除该分类吗？关联资源将设为未分类！')">删除</a>
                    </td>
                </tr>
            <?php endwhile; ?>
        <?php else: ?>
            <tr>
                <td colspan="3" style="text-align: center;">暂无分类</td>
            </tr>
        <?php endif; ?>
    </table>

    <p><a href="admin_panel.php">返回管理员面板</a></p>
</body>
</html>