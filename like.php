<?php
session_start();
require_once 'functions.php';

// 未登录则返回错误
if (!is_logged_in()) {
    echo json_encode([
        'success' => false,
        'message' => '请先登录'
    ]);
    exit;
}

$file_id = intval($_POST['file_id']);
if ($file_id <= 0) {
    echo json_encode([
        'success' => false,
        'message' => '无效的资源ID'
    ]);
    exit;
}

// 检查是否已点赞
if (has_liked($file_id, $_SESSION['user_id'])) {
    echo json_encode([
        'success' => false,
        'message' => '您已点过赞'
    ]);
    exit;
}

// 执行点赞
$stmt = $conn->prepare("INSERT INTO likes (file_id, user_id) VALUES (?, ?)");
$stmt->bind_param("ii", $file_id, $_SESSION['user_id']);
if ($stmt->execute()) {
    $count = get_like_count($file_id);
    echo json_encode([
        'success' => true,
        'count' => $count,
        'message' => '点赞成功'
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => '点赞失败：' . $conn->error
    ]);
}
?>