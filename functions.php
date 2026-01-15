<?php
require_once 'db_connect.php';

// 登录状态检查
function is_logged_in() {
    return isset($_SESSION['user_id']);
}

// 管理员权限检查
function is_admin() {
    return isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1;
}

// 验证购买权限
function has_purchase_access($user_id, $file_id) {
    global $conn;
    $stmt = $conn->prepare("SELECT expire_time FROM purchases WHERE user_id = ? AND file_id = ? AND expire_time > NOW()");
    $stmt->bind_param("ii", $user_id, $file_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->num_rows > 0;
}

// 生成随机CDK
function generate_cdk($length = 16) {
    $chars = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    $cdk = '';
    for ($i = 0; $i < $length; $i++) {
        $cdk .= $chars[rand(0, strlen($chars) - 1)];
    }
    return $cdk;
}

// 计算30天后的时间
function get_expire_time() {
    return date('Y-m-d H:i:s', strtotime('+30 days'));
}

// 上传文件处理
function upload_file($file, $target_dir, $allowed_ext = [], $max_size = 50 * 1024 * 1024) {
    // 验证文件大小
    if ($file['size'] > $max_size) {
        return false;
    }
    
    // 验证文件类型
    $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!empty($allowed_ext) && !in_array($file_ext, $allowed_ext)) {
        return false;
    }
    
    // 安全处理目录路径（防止路径遍历）
    $target_dir = preg_replace('/[^\w\-\/]+/', '', $target_dir);
    $target_dir = rtrim($target_dir, '/');
    
    // 创建目录
    if (!is_dir($target_dir)) {
        mkdir($target_dir, 0755, true);
    }
    
    // 生成唯一文件名
    $new_filename = uniqid() . '.' . $file_ext;
    $target_path = $target_dir . '/' . $new_filename;
    
    // 移动文件
    if (move_uploaded_file($file['tmp_name'], $target_path)) {
        return $target_path;
    } else {
        return false;
    }
}

// 获取资源最新版本
function get_latest_version($file_id) {
    global $conn;
    $stmt = $conn->prepare("SELECT * FROM file_versions WHERE file_id = ? AND is_latest = 1 LIMIT 1");
    $stmt->bind_param("i", $file_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

// 获取资源所有版本
function get_all_versions($file_id) {
    global $conn;
    $stmt = $conn->prepare("SELECT * FROM file_versions WHERE file_id = ? ORDER BY upload_time DESC");
    $stmt->bind_param("i", $file_id);
    $stmt->execute();
    return $stmt->get_result();
}

// 取消旧版本的最新标识
function unset_old_latest($file_id) {
    global $conn;
    $stmt = $conn->prepare("UPDATE file_versions SET is_latest = 0 WHERE file_id = ?");
    $stmt->bind_param("i", $file_id);
    $stmt->execute();
}

// ========== 分类相关函数 ==========
function get_all_categories() {
    global $conn;
    $result = $conn->query("SELECT * FROM categories ORDER BY name ASC");
    return $result;
}

// ========== MC版本相关函数 ==========
// 获取所有MC版本（按类型分组）
function get_all_mc_versions($type = '') {
    global $conn;
    $sql = "SELECT * FROM mc_versions ORDER BY parent_type ASC, name ASC";
    if (!empty($type)) {
        $sql = "SELECT * FROM mc_versions WHERE parent_type = ? ORDER BY name ASC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $type);
        $stmt->execute();
        return $stmt->get_result();
    }
    return $conn->query($sql);
}

// 获取资源关联的MC版本
function get_file_mc_versions($file_id) {
    global $conn;
    $sql = "
        SELECT mv.* FROM file_mc_relations fmr
        LEFT JOIN mc_versions mv ON fmr.mc_version_id = mv.id
        WHERE fmr.file_id = ?
        ORDER BY mv.parent_type ASC, mv.name ASC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $file_id);
    $stmt->execute();
    return $stmt->get_result();
}

// ========== 运行平台相关函数 ==========
function get_all_platforms() {
    global $conn;
    $result = $conn->query("SELECT * FROM platforms ORDER BY name ASC");
    return $result;
}

// 获取资源关联的运行平台
function get_file_platforms($file_id) {
    global $conn;
    $sql = "
        SELECT p.* FROM file_platform_relations fpr
        LEFT JOIN platforms p ON fpr.platform_id = p.id
        WHERE fpr.file_id = ?
        ORDER BY p.name ASC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $file_id);
    $stmt->execute();
    return $stmt->get_result();
}

// ========== 点赞/下载量相关函数 ==========
function get_like_count($file_id) {
    global $conn;
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM likes WHERE file_id = ?");
    $stmt->bind_param("i", $file_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc()['count'];
}

function has_liked($file_id, $user_id) {
    global $conn;
    $stmt = $conn->prepare("SELECT id FROM likes WHERE file_id = ? AND user_id = ?");
    $stmt->bind_param("ii", $file_id, $user_id);
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
}

function increment_download_count($file_id) {
    global $conn;
    $stmt = $conn->prepare("UPDATE files SET download_count = download_count + 1 WHERE id = ?");
    $stmt->bind_param("i", $file_id);
    $stmt->execute();
}
?>