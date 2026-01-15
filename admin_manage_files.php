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
    $file_id = intval($_GET['id']);
    $conn->begin_transaction();
    try {
        // 先删除关联数据
        $conn->query("DELETE FROM file_mc_relations WHERE file_id = $file_id");
        $conn->query("DELETE FROM file_platform_relations WHERE file_id = $file_id");
        $conn->query("DELETE FROM file_versions WHERE file_id = $file_id");
        $conn->query("DELETE FROM likes WHERE file_id = $file_id");
        $conn->query("DELETE FROM purchases WHERE file_id = $file_id");
        // 删除资源主表
        $conn->query("DELETE FROM files WHERE id = $file_id");
        $conn->commit();
        $success = '资源删除成功！';
    } catch (Exception $e) {
        $conn->rollback();
        $error = '删除失败：' . $e->getMessage();
    }
}

// 查询所有资源
$files_sql = "SELECT f.*, c.name as category_name FROM files f LEFT JOIN categories c ON f.category_id = c.id ORDER BY f.update_time DESC";
$files_result = $conn->query($files_sql);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>资源管理</title>
    <style>
        table { border-collapse: collapse; width: 100%; margin: 20px 0; }
        th, td { border: 1px solid #ccc; padding: 8px; text-align: left; }
        .btn { padding: 5px 10px; text-decoration: none; margin: 0 5px; }
        .edit-btn { background: #007bff; color: white; }
        .delete-btn { background: #dc3545; color: white; }
    </style>
</head>
<body>
    <h1>资源管理</h1>
    <?php if (isset($success)): ?><p style="color: green;"><?php echo $success; ?></p><?php endif; ?>
    <?php if (isset($error)): ?><p style="color: red;"><?php echo $error; ?></p><?php endif; ?>
    
    <p><a href="admin_upload_file.php">上传新资源</a> | <a href="admin_panel.php">返回管理员面板</a></p>
    
    <table>
        <tr>
            <th>ID</th>
            <th>标题</th>
            <th>分类</th>
            <th>价格(积分)</th>
            <th>下载量</th>
            <th>操作</th>
        </tr>
        <?php if ($files_result->num_rows > 0): ?>
            <?php while ($file = $files_result->fetch_assoc()): ?>
                <tr>
                    <td><?php echo $file['id']; ?></td>
                    <td><?php echo $file['title']; ?></td>
                    <td><?php echo $file['category_name'] ?: '未分类'; ?></td>
                    <td><?php echo $file['price']; ?></td>
                    <td><?php echo $file['download_count']; ?></td>
                    <td>
                        <a href="admin_edit_file.php?id=<?php echo $file['id']; ?>" class="btn edit-btn">编辑</a>
                        <a href="?action=delete&id=<?php echo $file['id']; ?>" class="btn delete-btn" onclick="return confirm('确定删除该资源吗？删除后不可恢复！')">删除</a>
                    </td>
                </tr>
            <?php endwhile; ?>
        <?php else: ?>
            <tr>
                <td colspan="6" style="text-align: center;">暂无资源</td>
            </tr>
        <?php endif; ?>
    </table>
</body>
</html>