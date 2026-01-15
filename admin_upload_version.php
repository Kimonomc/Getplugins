<?php
session_start();
require_once 'functions.php';

// 非管理员则跳转
if (!is_admin()) {
    header("Location: index.php");
    exit;
}

// 验证资源ID
if (!isset($_GET['file_id']) || !is_numeric($_GET['file_id'])) {
    header("Location: admin_panel.php");
    exit;
}
$file_id = intval($_GET['file_id']);

// 查询资源基础信息
$stmt = $conn->prepare("SELECT title FROM files WHERE id = ?");
$stmt->bind_param("i", $file_id);
$stmt->execute();
$file = $stmt->get_result()->fetch_assoc();
if (!$file) {
    header("Location: admin_panel.php");
    exit;
}

$error = '';
$success = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $version = trim($_POST['version']);
    $file_error = $_FILES['file']['error'];
    
    if (empty($version)) {
        $error = '版本号不能为空';
    } elseif ($file_error != UPLOAD_ERR_OK) {
        $error = '资源文件上传失败';
    } else {
        // 处理文件上传（按标题+版本号建目录）
            $target_dir = 'uploads/' . $file['title'] . '/' . $version;
            
            // 允许的资源文件类型
            $allowed_file_ext = ['zip', 'jar', 'rar', '7z'];
            // 文件大小限制（50MB）
            $max_file_size = 50 * 1024 * 1024;
            
            $file_path = upload_file($_FILES['file'], $target_dir, $allowed_file_ext, $max_file_size);
        
        if ($file_path) {
            // 开启事务
            $conn->begin_transaction();
            try {
                // 1. 取消旧版本的最新标识
                unset_old_latest($file_id);
                
                // 2. 插入新版本，设为最新版
                $filename = basename($_FILES['file']['name']);
                $stmt2 = $conn->prepare("INSERT INTO file_versions (file_id, version, filename, file_path, is_latest) VALUES (?, ?, ?, ?, 1)");
                $stmt2->bind_param("isss", $file_id, $version, $filename, $file_path);
                $stmt2->execute();
                
                // 3. 更新资源主表的更新时间
                $stmt3 = $conn->prepare("UPDATE files SET update_time = NOW() WHERE id = ?");
                $stmt3->bind_param("i", $file_id);
                $stmt3->execute();
                
                $conn->commit();
                $success = '新版本上传成功！<a href="file_detail.php?id=' . $file_id . '">返回资源详情</a>';
            } catch (Exception $e) {
                $conn->rollback();
                unlink($file_path);
                $error = '上传失败: ' . $e->getMessage();
            }
        } else {
            $error = '文件保存失败';
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>上传新版本 - <?php echo $file['title']; ?></title>
</head>
<body>
    <h1>上传新版本 - <?php echo $file['title']; ?></h1>
    <?php if ($error): ?>
        <p style="color: red;"><?php echo $error; ?></p>
    <?php endif; ?>
    <?php if ($success): ?>
        <p style="color: green;"><?php echo $success; ?></p>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data">
        <div>
            <label>资源标题:</label>
            <input type="text" value="<?php echo $file['title']; ?>" disabled>
            <input type="hidden" name="file_id" value="<?php echo $file_id; ?>">
        </div>
        <div>
            <label>新版本号:</label>
            <input type="text" name="version" required placeholder="如2.17.1">
        </div>
        <div>
            <label>新版本文件:</label>
            <input type="file" name="file" required>
        </div>
        <div>
            <button type="submit">上传新版本</button>
        </div>
    </form>
    <p><a href="file_detail.php?id=<?php echo $file_id; ?>">返回资源详情</a></p>
    <p><a href="admin_panel.php">返回管理员面板</a></p>
</body>
</html>