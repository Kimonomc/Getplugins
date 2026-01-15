<?php
session_start();
require_once 'functions.php';

// 验证文件ID
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: index.php");
    exit;
}
$file_id = intval($_GET['id']);

// 查询文件信息
$file_sql = "
SELECT f.*, c.name as category_name 
FROM files f 
LEFT JOIN categories c ON f.category_id = c.id 
WHERE f.id = ?
";
$stmt = $conn->prepare($file_sql);
$stmt->bind_param("i", $file_id);
$stmt->execute();
$file = $stmt->get_result()->fetch_assoc();

if (!$file) {
    header("Location: index.php");
    exit;
}

// 获取关联数据
$latest_version = get_latest_version($file_id);
$all_versions = get_all_versions($file_id);
$like_count = get_like_count($file_id);
$mc_versions = get_file_mc_versions($file_id); // 关联的MC版本（多个）
$platforms = get_file_platforms($file_id);     // 关联的运行平台（多个）

// 新增：处理最新版本号，用于标题拼接
$latest_version_number = $latest_version ? $latest_version['version'] : '未知版本';

$error = '';
$success = '';
$has_access = false;
if (is_logged_in()) {
    $has_access = has_purchase_access($_SESSION['user_id'], $file_id);
    $has_liked = has_liked($file_id, $_SESSION['user_id']);
    
    // 处理购买请求
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && !$has_access) {
        $user_id = $_SESSION['user_id'];
        $user_points = $_SESSION['points'];
        $price = $file['price'];
        
        if ($user_points < $price) {
            $error = '积分不足，无法购买';
        } else {
            $conn->begin_transaction();
            try {
                // 扣除积分
                $stmt1 = $conn->prepare("UPDATE users SET points = points - ? WHERE id = ?");
                $stmt1->bind_param("ii", $price, $user_id);
                $stmt1->execute();
                
                // 记录积分消费
                $remark = "购买资源: {$file['title']}";
                $stmt2 = $conn->prepare("INSERT INTO point_records (user_id, type, points, remark) VALUES (?, 'consume', -?, ?)");
                $stmt2->bind_param("iis", $user_id, $price, $remark);
                $stmt2->execute();
                
                // 记录购买记录
                $expire_time = get_expire_time();
                $stmt3 = $conn->prepare("INSERT INTO purchases (user_id, file_id, expire_time) VALUES (?, ?, ?)");
                $stmt3->bind_param("iis", $user_id, $file_id, $expire_time);
                $stmt3->execute();
                
                $conn->commit();
                $_SESSION['points'] -= $price;
                $success = '购买成功！30天内可重复下载';
                $has_access = true;
            } catch (Exception $e) {
                $conn->rollback();
                $error = '购买失败: ' . $e->getMessage();
            }
        }
    }
}

// 准备资源ID和永久链接（根据实际业务调整）
$RESOURCE_ID = 'resource-' . $file_id;
$PERMANENT_LINK = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- 改动：标题改为“标题 - 最新版本号”格式 -->
    <title>GetPlugins.XYZ - 资源 - <?php echo $file['title']; ?> - <?php echo $latest_version_number; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
        }

        body {
            background-color: #ebebeb;
            padding: 40px 20px;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .main-container {
            width: 100%;
            max-width: 1200px;
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .main-card {
            display: flex;
            align-items: flex-start;
            padding: 24px;
            border-radius: 16px;
            width: 100%;
            background-color: transparent;
            justify-content: space-between;
            box-shadow: none;
            flex-wrap: wrap;
        }

        .info-wrapper {
            display: flex;
            align-items: center;
            width: 100%;
            max-width: 800px;
            flex: 1;
        }

        .icon-container {
            margin-right: 24px;
            position: relative;
            width: 80px;
            flex-shrink: 0;
        }

        .plugin-icon {
            width: 80px;
            height: 80px;
            border-radius: 16px;
            background-color: #fff;
            object-fit: cover;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        .info-container {
            flex: 1;
        }

        .plugin-title {
            font-size: 28px;
            font-weight: 700;
            color: #1a202c;
            margin-bottom: 8px;
        }

        .plugin-desc {
            font-size: 16px;
            color: #282e3c;
            margin-bottom: 12px;
        }

        .stats-tags-container {
            display: flex;
            align-items: center;
            gap: 0;
            flex-wrap: wrap;
        }

        .stat-item {
            display: flex;
            align-items: center;
            font-size: 16px;
            color: #1a202c;
            padding: 0 16px;
            position: relative;
            font-weight: 700;
        }

        .stat-item:not(:first-child)::before,
        .tags-wrapper::before {
            content: '';
            position: absolute;
            left: 0;
            top: 50%;
            transform: translateY(-50%);
            width: 1px;
            height: 60%;
            background-color: #dcdcdc;
        }

        .stat-item:first-child {
            padding-left: 0;
        }

        .stat-icon {
            margin-right: 8px;
            width: 20px;
            height: 20px;
            color: #1a202c;
            stroke-width: 2.5;
        }

        .tags-wrapper {
            display: flex;
            align-items: center;
            padding: 0 16px;
            position: relative;
        }

        .tags-container {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .tag-icon {
            width: 20px;
            height: 20px;
            color: #1a202c;
            margin-right: 8px;
            stroke-width: 2.5;
        }

        .tag {
            padding: 3px 8px;
            background-color: #ffffff;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            color: #1a202c;
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
            line-height: 1.2;
            display: inline-block;
            white-space: nowrap;
            max-width: max-content;
        }

        /* 新增标签容器样式，支持横向排布自动换行 */
        .tag-flow-container {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
        }

        .actions-container {
            display: flex;
            align-items: center;
            gap: 16px;
            position: relative;
            margin-left: auto;
        }

        .download-btn {
            padding: 14px 28px;
            background-color: #00af5c;
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 10px;
            transition: all 0.2s ease;
            transform: translateY(0);
            position: relative;
            z-index: 1;
        }

        .download-btn svg {
            width: 20px;
            height: 20px;
            color: white;
            stroke-width: 2.5;
        }

        .download-btn:hover {
            background-color: #00964e;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }

        .download-btn:active {
            transform: translateY(2px);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .action-btn {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            border: none;
            background-color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
            transform: translateY(0);
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        .action-btn svg {
            width: 22px;
            height: 22px;
            color: #1a202c;
            stroke-width: 2;
        }

        /* 点赞按钮选中样式 */
        .action-btn.liked svg {
            color: #e53e3e;
            fill: #e53e3e;
        }
        
        .action-btn:hover {
            background-color: #f5f5f5;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }

        .action-btn:active {
            transform: translateY(2px);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .more-btn {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            border: none;
            background-color: transparent;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 24px;
            color: #1a202c;
            font-weight: 900;
            transition: all 0.2s ease;
            transform: translateY(0);
        }

        .more-btn:hover {
            background-color: rgba(0, 0, 0, 0.05);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.08);
        }

        .more-btn:active {
            transform: translateY(2px);
            background-color: rgba(0, 0, 0, 0.1);
        }

        .more-menu {
            display: none;
            position: absolute;
            top: 48px;
            right: 0;
            width: 220px;
            background-color: white;
            border-radius: 12px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.12);
            z-index: 1000;
            padding: 8px 0;
            opacity: 0;
            transform: scale(0.8);
            transform-origin: top right;
            transition: all 0.2s cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        .more-menu.show {
            display: block;
            opacity: 1;
            transform: scale(1);
        }

        .more-menu::before {
            content: '';
            position: absolute;
            top: -8px;
            right: 16px;
            width: 16px;
            height: 16px;
            background-color: white;
            transform: rotate(45deg);
            z-index: -1;
        }

        .menu-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 18px;
            font-size: 16px;
            font-weight: 600;
            color: #1a202c;
            cursor: pointer;
            transition: background-color 0.2s ease;
        }

        .menu-item:hover {
            background-color: #f5f5f5;
        }

        .menu-item.report svg {
            color: #e53e3e;
        }

        .menu-item svg {
            width: 20px;
            height: 20px;
            color: #1a202c;
            stroke-width: 2;
            flex-shrink: 0;
        }

        .divider-line {
            width: 100%;
            height: 1px;
            background-color: #e8e8e8;
            margin-top: 10px;
        }

        /* 改动1：重构布局容器样式，改为多卡片垂直排列 */
        .content-container {
            display: flex;
            gap: 20px;
            width: 100%;
        }

        .left-column {
            width: 75%;
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .right-column {
            width: 25%;
        }

        /* 通用卡片样式 */
        .card {
            padding: 20px;
            background-color: #f8f8f8;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            width: 100%;
        }

        /* 适配性卡片样式 - 自适应高度 */
        .adaptability-card {
            /* 只保留必要的内边距，高度自适应内容 */
            padding: 20px;
            background-color: #f8f8f8;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            width: 100%;
            /* 确保高度只容纳内容 */
            height: fit-content;
        }

        .sub-card-title {
            font-size: 18px;
            font-weight: 600;
            color: #1a202c;
            margin-bottom: 10px;
        }

        .sub-card-content {
            font-size: 14px;
            color: #666666;
            line-height: 1.8;
        }
        
        /* 描述内容的样式适配 */
        .description-content img {max-width: 100%; height: auto;}
        .description-content h1, .description-content h2, .description-content h3 {margin: 10px 0;}
        .description-content p {margin: 8px 0;}
        .description-content strong {font-weight: bold;}
        .description-content em {font-style: italic;}

        .info-item {
            margin-bottom: 10px;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        
        .info-label {
            font-size: 14px;
            color: #1a202c;
            font-weight: 600;
        }

        .copy-toast {
            position: fixed;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%) translateY(100px);
            padding: 12px 24px;
            background-color: #1a202c;
            color: white;
            border-radius: 8px;
            font-size: 14px;
            opacity: 0;
            transition: all 0.3s ease;
            z-index: 2000;
        }

        .copy-toast.show {
            transform: translateX(-50%) translateY(0);
            opacity: 1;
        }

        /* 提示信息样式 */
        .alert-message {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-size: 14px;
            font-weight: 600;
        }
        
        .alert-error {
            background-color: #fee2e2;
            color: #b91c1c;
        }
        
        .alert-success {
            background-color: #dcfce7;
            color: #166534;
        }
        
        /* 版本列表样式 */
        .version-list {
            margin: 16px 0;
            padding: 16px;
            background-color: #fefefe;
            border-radius: 8px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
        }
        
        .version-item {
            margin: 8px 0;
            padding: 8px;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .latest-version {
            color: #00964e;
            font-weight: 700;
            margin: 8px 0;
        }
        
        /* 管理员链接样式 */
        .admin-link {
            display: inline-block;
            margin-bottom: 16px;
            color: #e53e3e;
            font-weight: 600;
            text-decoration: none;
        }
        
        .admin-link:hover {
            text-decoration: underline;
        }
        
        /* 购买按钮样式 */
        .buy-btn {
            display: block;
            width: 200px;
            padding: 12px 0;
            background-color: #00af5c;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            text-align: center;
            margin: 16px 0;
        }
        
        .buy-btn:hover {
            background-color: #00964e;
        }
        
        /* 权限有效期样式 */
        .expire-info {
            color: #666;
            font-size: 14px;
            margin: 8px 0;
        }

        @media (max-width: 1023px) {
            /* 移动端布局调整 */
            .content-container {
                flex-direction: column;
                gap: 15px;
            }
            .left-column, .right-column {
                width: 100%;
            }
        }

        @media (max-width: 799px) {
            .main-card {
                justify-content: flex-start;
                gap: 16px;
            }
            
            .info-wrapper {
                max-width: 100%;
                width: 100%;
                flex-direction: row;
                align-items: center;
            }
            
            .icon-container {
                margin-right: 24px;
                width: 80px;
                flex-shrink: 0;
            }
            
            .actions-container {
                width: 100%;
                margin-left: 0;
                padding-left: 0;
                justify-content: flex-start;
                padding-top: 8px;
                border-top: 1px solid #e8e8e8;
                margin-left: 0;
            }
        
            .stats-tags-container {
                justify-content: flex-start;
            }
            
            .tags-wrapper {
                padding-left: 16px;
                padding-right: 0;
                margin-top: 8px;
            }
        
            .more-menu {
                top: 48px;
                /* 修改：调整right值，让菜单整体右移，小尾巴能对准三个点 */
                right: calc(100% - 210px);
                transform-origin: calc(100% - 16px) top;
            }
        
            .more-menu::before {
                /* 修改：将right从16px改为20px，精准对准三个点按钮的中心 */
                right: 20px;
                top: -8px;
                /* 新增：确保小尾巴在z轴层级正确 */
                z-index: 1001;
            }
        }

        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
            z-index: 2000;
            justify-content: center;
            align-items: center;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .modal-overlay.show {
            display: flex;
            opacity: 1;
        }

        .download-card {
            width: 500px;
            background-color: white;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
            padding: 25px;
            transform-origin: left bottom;
            transform: scale(0.2) translate(-50%, 50%);
            opacity: 0;
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }

        .modal-overlay.show .download-card {
            transform: scale(1) translate(0, 0);
            opacity: 1;
        }

        .download-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }

        .download-card-title {
            font-size: 24px;
            font-weight: 700;
            color: #1a202c;
        }

        .close-btn {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            border: none;
            background-color: transparent;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 28px;
            color: #333;
            font-weight: 900;
            line-height: 1;
        }

        .points-requirement {
            text-align: center;
            font-size: 22px;
            color: #1a202c;
            margin-bottom: 20px;
            font-weight: 600;
        }

        .buy-download-btn {
            display: block;
            width: 200px;
            margin: 0 auto 25px;
            padding: 15px 0;
            background-color: #00af5c;
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 18px;
            font-weight: 700;
            cursor: pointer;
            text-align: center;
        }

        .history-version-title {
            font-size: 20px;
            font-weight: 700;
            color: #1a202c;
            margin-bottom: 15px;
        }

        .version-row {
            display: flex;
            align-items: center;
            background-color: #f8f8f8;
            border-radius: 12px;
            padding: 15px 20px;
            margin-bottom: 10px;
        }

        .version-tag {
            background-color: #e5f0ff;
            color: #2d7dff;
            padding: 6px 10px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 700;
            margin-right: 15px;
        }

        .version-info {
            flex: 1;
            font-size: 18px;
            color: #1a202c;
            font-weight: 500;
        }

        .version-full-info {
            font-size: 14px;
            color: #666;
            margin-top: 6px;
        }

        .version-download-btn {
            padding: 10px 20px;
            background-color: #00af5c;
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .version-download-btn svg {
            width: 18px;
            height: 18px;
        }

        /* 页眉、页脚、加载动画相关CSS */
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: #1a202c;
            margin: 0;
            padding: 0;
            background-color: #ebebeb;
        }
        
        /* 页眉样式 */
        .header-fixed {
            position: static;
            width: 100%;
        }
        
        /* 下拉菜单样式 */
        .dropdown-menu {
            display: none;
            position: absolute;
            top: 48px;
            right: 0;
            width: 220px;
            background-color: white;
            border-radius: 12px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.12);
            z-index: 1000;
            padding: 8px 0;
            opacity: 0;
            transform: scale(0.8);
            transform-origin: top right;
            transition: all 0.2s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        .dropdown-menu::before {
            content: '';
            position: absolute;
            top: -8px;
            right: 16px;
            width: 16px;
            height: 16px;
            background-color: white;
            transform: rotate(45deg);
            z-index: -1;
        }
        .dropdown-menu.active {
            display: block;
            opacity: 1;
            transform: scale(1);
        }
        
        .dropdown-btn {
            transition: all 0.1s ease;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 1px;
        }
        .dropdown-btn:hover {
            color: #1a1b1d;
        }
        .dropdown-btn:active {
            transform: scale(0.98);
        }
        .dropdown-btn .arrow {
            transition: transform 0.2s ease;
        }
        .dropdown-btn .arrow.rotate {
            transform: rotate(180deg);
        }
        
        .dropdown-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 18px;
            font-size: 16px;
            font-weight: 600;
            color: #1a202c;
            text-decoration: none;
            transition: background-color 0.2s ease;
        }
        .dropdown-item:hover {
            background-color: #f5f5f5;
        }
        .dropdown-item svg {
            width: 20px;
            height: 20px;
            color: #1a202c;
            stroke-width: 2;
            flex-shrink: 0;
        }
        .dropdown-item.red svg {
            color: #e53e3e;
        }
        
        /* 积分下拉菜单样式 */
        .points-container {
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            padding: 4px 8px;
            border-radius: 12px;
            position: relative;
        }
        .points-container:hover {
            background-color: rgba(0, 0, 0, 0.05);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }
        .points-container:active {
            transform: scale(0.98);
            background-color: rgba(0, 0, 0, 0.1);
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.05);
        }

        .points-dropdown {
            display: none;
            position: absolute;
            top: 48px;
            right: 0;
            width: 220px;
            background-color: white;
            border-radius: 12px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.12);
            z-index: 1001;
            padding: 8px 0;
            opacity: 0;
            transform: scale(0.8);
            transform-origin: top right;
            transition: all 0.2s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        .points-dropdown::before {
            content: '';
            position: absolute;
            top: -8px;
            right: 16px;
            width: 16px;
            height: 16px;
            background-color: white;
            transform: rotate(45deg);
            z-index: -1;
        }
        .points-dropdown.active {
            display: block;
            opacity: 1;
            transform: scale(1);
        }
        .points-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 18px;
            font-size: 16px;
            font-weight: 600;
            color: #1a202c;
            text-decoration: none;
            transition: background-color 0.2s ease;
        }
        .points-item:hover {
            background-color: #f5f5f5;
        }
        .points-item svg {
            width: 20px;
            height: 20px;
            color: #1a202c;
            stroke-width: 2;
            flex-shrink: 0;
        }
        
        /* 加载进度条样式 */
        .progress-container {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 3px;
            background: transparent;
            z-index: 9999;
            opacity: 0;
            transition: opacity 0.2s;
        }
        .progress-bar {
            height: 100%;
            width: 0%;
            background: linear-gradient(90deg, #2d9d69, #4caf50);
            transition: width 0.3s ease;
        }
        .progress-container.show {
            opacity: 1;
        }
        
        /* 正文和页脚之间的过渡细线 */
        .content-footer-divider {
            width: 100%;
            height: 1px;
            background-color: #b7cebe;
            margin: 0 auto;
            max-width: 5xl;
        }
        
        /* 页脚样式 */
        .footer-container {
            background-color: #d3eadb;
            width: 100%;
            color: #2c2e31;
            box-sizing: border-box;
        }
        
        /* 桌面版页脚样式 */
        @media (min-width: 768px) {
            .footer-container {
                max-height: 350px;
            }
        }
        
        /* 手机版页脚样式 */
        @media (max-width: 767px) {
            .footer-container {
                max-height: none;
                min-height: auto;
                padding: 20px 0;
            }
            .footer-container .grid {
                gap: 10px;
            }
            .footer-container ul {
                margin-bottom: 15px;
            }
        }
    </style>
</head>
<body>
    <header class="header-fixed py-3">
        <div class="max-w-5xl mx-auto px-6 flex justify-between items-center">
            <div class="flex items-center gap-2">
                <span class="text-2xl font-bold text-[#2c2e31]">GetPlugins</span>
            </div>
            
            <div class="flex items-center gap-5 text-[#2c2e31]">
                <div class="relative">
                    <div class="points-container" id="pointsContainer">
                        <span class="text-xl">
                            <!-- PHP动态显示积分 -->
                            <span class="font-extrabold"><?php echo is_logged_in() ? ($_SESSION['points'] ?? '0') : '0'; ?></span>
                            可用积分 +
                        </span>
                    </div>
                    
                    <div class="points-dropdown" id="pointsDropdown">
                        <a href="cdk_exchange.php" class="points-item">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m6-6H6"></path>
                            </svg>
                            添加积分序列号
                        </a>
                        <a href="cdk_check.php" class="points-item">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                            </svg>
                            序列号查询
                        </a>
                    </div>
                </div>
                
                <div class="relative dropdown">
                    <div class="dropdown-btn flex items-center gap-1" id="dropdownBtn">
                        <!-- PHP动态显示用户信息 -->
                        <?php if (is_logged_in()): ?>
                            <div class="w-8 h-8 rounded-full bg-purple-600 flex items-center justify-center text-white text-base">
                                <?php echo strtoupper(substr($_SESSION['username'], 0, 1)); ?>
                            </div>
                        <?php else: ?>
                            <div class="w-8 h-8 rounded-full bg-purple-600 flex items-center justify-center text-white text-base">?</div>
                        <?php endif; ?>
                        <i class="fa-solid fa-chevron-down text-sm arrow" id="dropdownArrow"></i>
                    </div>
                    
                    <div class="dropdown-menu" id="dropdownMenu">
                        <?php if (is_logged_in()): ?>
                            <a href="#" class="dropdown-item">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                </svg>
                                个人中心
                            </a>
                            <a href="point_records.php" class="dropdown-item">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                                我的积分
                            </a>
                            <a href="#" class="dropdown-item">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                </svg>
                                设置
                            </a>
                            <?php if (is_admin()): ?>
                                <a href="admin_panel.php" class="dropdown-item">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                    </svg>
                                    管理员面板
                                </a>
                            <?php endif; ?>
                            <hr class="my-1 border-gray-200 mx-4">
                            <a href="logout.php" class="dropdown-item red">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"></path>
                                </svg>
                                退出登录
                            </a>
                        <?php else: ?>
                            <a href="login.php" class="dropdown-item">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"></path>
                                </svg>
                                去登录
                            </a>
                            <a href="register.php" class="dropdown-item">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 004 1.732V21a2 2 0 002 2h4a2 2 0 002-2v-.268A6 6 0 0021 20H3z"></path>
                                </svg>
                                去注册
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </header>
    <br>
    <!-- ========== 加载动画组件（放在主要内容区域顶部） ========== -->
    <div class="progress-container" id="progressContainer">
        <div class="progress-bar" id="progressBar"></div>
    </div>
    <div class="main-container">
        <!-- 提示信息 -->
        <?php if ($error): ?>
            <div class="alert-message alert-error"><?php echo $error; ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert-message alert-success"><?php echo $success; ?></div>
        <?php endif; ?>
        
        <!-- 管理员上传新版本入口 -->
        <?php if (is_admin()): ?>
            <a href="admin_upload_version.php?file_id=<?php echo $file_id; ?>" class="admin-link">上传新版本</a>
        <?php endif; ?>
        
        <div class="main-card">
            <div class="info-wrapper">
                <div class="icon-container">
                    <img 
                        src="<?php echo $file['icon'] ?: 'https://p26-flow-imagex-download-sign.byteimg.com/tos-cn-i-a9rns2rl98/c30483d070bb4f23b7952afdb3fff541.png~tplv-a9rns2rl98-24:720:720.png'; ?>" 
                        alt="<?php echo $file['title']; ?> 图标" 
                        class="plugin-icon"
                    >
                </div>

                <div class="info-container">
                    <!-- 页面内标题改为“标题 - 最新版本号”格式 -->
                    <h1 class="plugin-title"><?php echo $file['title']; ?> - <?php echo $latest_version_number; ?></h1>
                    <p class="plugin-desc"><?php echo $file['brief'] ?: '暂无简介'; ?></p>
                    
                    <div class="stats-tags-container">
                        <div class="stat-item">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" class="stat-icon">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 0 0 3 3h10a3 3 0 0 0 3-3v-1m-4-4-4 4m0 0-4-4m4 4V4"></path>
                            </svg>
                            <span><?php echo $file['download_count']; ?></span>
                        </div>
                        <div class="stat-item">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor" viewBox="0 0 24 24" class="stat-icon">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 0 0 0 6.364L12 20.364l7.682-7.682a4.5 4.5 0 0 0-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 0 0-6.364 0"></path>
                            </svg>
                            <span id="like-count"><?php echo $like_count; ?></span>
                        </div>
                        <div class="tags-wrapper">
                            <div class="tags-container">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewBox="0 0 24 24" class="tag-icon">
                                    <path d="M9 5H2v7l6.29 6.29c.94.94 2.48.94 3.42 0l3.58-3.58c.94-.94.94-2.48 0-3.42zM6 9.01V9"></path>
                                    <path d="m15 5 6.3 6.3a2.4 2.4 0 0 1 0 3.4L17 19"></path>
                                </svg>
                                <!-- 标签部分只保留分类和平台，移除价格标签 -->
                                <span class="tag"><?php echo $file['category_name'] ?: '未分类'; ?></span>
                                <?php
                                if ($platforms && $platforms->num_rows > 0) {
                                    $platforms->data_seek(0);
                                    while ($p = $platforms->fetch_assoc()) {
                                        echo "<span class='tag'>{$p['name']}</span>";
                                    }
                                }
                                ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="actions-container">
                <!-- 下载/购买按钮 -->
                <?php if (is_logged_in()): ?>
                    <?php if ($has_access): ?>
                        <?php if ($latest_version): ?>
                            <a href="download.php?version_id=<?php echo $latest_version['id']; ?>" class="download-btn">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 0 0 3 3h10a3 3 0 0 0 3-3v-1m-4-4-4 4m0 0-4-4m4 4V4"></path>
                                </svg>
                                获取最新版本
                            </a>
                        <?php else: ?>
                            <button class="download-btn" disabled>暂无可用版本</button>
                        <?php endif; ?>
                    <?php else: ?>
                        <form method="post" style="margin:0;">
                            <button type="submit" class="download-btn">
                                立即使用 <?php echo $file['price']; ?>积分 购买
                            </button>
                        </form>
                    <?php endif; ?>
                <?php else: ?>
                    <a href="login.php" class="download-btn">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"></path>
                        </svg>
                        登录后购买下载
                    </a>
                <?php endif; ?>
                
                <!-- 点赞按钮 -->
                <?php if (is_logged_in()): ?>
                    <button class="action-btn <?php echo $has_liked ? 'liked' : ''; ?>" 
                            onclick="likeResource(<?php echo $file_id; ?>)">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="<?php echo $has_liked ? 'currentColor' : 'none'; ?>" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 0 0 0 6.364L12 20.364l7.682-7.682a4.5 4.5 0 0 0-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 0 0-6.364 0"></path>
                        </svg>
                    </button>
                <?php else: ?>
                    <button class="action-btn" disabled title="登录后点赞">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 0 0 0 6.364L12 20.364l7.682-7.682a4.5 4.5 0 0 0-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 0 0-6.364 0"></path>
                        </svg>
                    </button>
                <?php endif; ?>
                
                <button class="more-btn" id="moreBtn">⋮</button>
                
                <div class="more-menu" id="moreMenu">
                    <div class="menu-item report" id="reportItem">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9.75 9.75l4.5 4.5m0-4.5l-4.5 4.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        反馈
                    </div>
                    <div class="menu-item" id="copyIdItem">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2"></path>
                        </svg>
                        复制资源ID
                    </div>
                    <div class="menu-item" id="copyLinkItem">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2"></path>
                        </svg>
                       复制永久链接
                    </div>
                    <div class="menu-item" id="copyLinkItem">
                        <svg class="icon" xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2v-5m-1.414-9.414a2 2 0 1 1 2.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                        </svg>
                       催促更新
                    </div>
                </div>
            </div>
        </div>

        <div class="divider-line"></div>

        <!-- 改动2：重构布局结构，分为左右两列 -->
        <div class="content-container">
            <!-- 左侧列：描述卡片 + 版本信息卡片 -->
            <div class="left-column">
                <!-- 描述卡片：只保留资源描述 -->
                <div class="card">
                    <div class="sub-card-content description-content">
                        <?php echo $file['description'] ?: '无'; ?>
                    </div>
                </div>
                <div class="card">
                    <?php if ($all_versions && $all_versions->num_rows > 1): ?>
                        <h3 class="sub-card-title" style="margin-top:16px; font-size:16px;">历史版本</h3>
                        <div class="version-list">
                            <?php while ($version = $all_versions->fetch_assoc()): ?>
                                <?php if (!$version['is_latest']): ?>
                                    <div class="version-item">
                                        v<?php echo $version['version']; ?> (上传时间: <?php echo $version['upload_time']; ?>)
                                        <?php if (is_logged_in() && $has_access): ?>
                                            <a href="download.php?version_id=<?php echo $version['id']; ?>">下载</a>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            <?php endwhile; ?>
                        </div>
                    <?php endif; ?>
                    
                    <!-- 权限有效期 -->
                    <?php if (is_logged_in() && $has_access): ?>
                        <?php
                            $expire_stmt = $conn->prepare("SELECT expire_time FROM purchases WHERE user_id = ? AND file_id = ? ORDER BY purchase_time DESC LIMIT 1");
                            $expire_stmt->bind_param("ii", $_SESSION['user_id'], $file_id);
                            $expire_stmt->execute();
                            $expire_data = $expire_stmt->get_result()->fetch_assoc();
                        ?>
                        <p class="expire-info">您的下载权限将在 <?php echo $expire_data ? $expire_data['expire_time'] : '未知'; ?> 过期</p>
                    <?php endif; ?>
                    
                    <!-- 未购买时的购买按钮 -->
                    <?php if (is_logged_in() && !$has_access): ?>
                        <form method="post">
                            <button type="submit" class="buy-btn">立即使用 <?php echo $file['price']; ?>积分 购买 </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 右侧列：适配性卡片（自适应高度） -->
            <div class="right-column">
                <div class="adaptability-card">
                    <h3 class="sub-card-title">适配性</h3>
                    <div class="sub-card-content">
                        <!-- 分类信息 -->
                        <div class="info-item">
                            <span class="info-label">分类</span>
                            <div class="tag-flow-container">
                                <span class="tag"><?php echo $file['category_name'] ?: '未分类'; ?></span>
                            </div>
                        </div>
                        
                        <!-- MC版本 -->
                        <div class="info-item">
                            <span class="info-label">MC版本</span>
                            <div class="tag-flow-container">
                                <?php
                                if ($mc_versions && $mc_versions->num_rows > 0) {
                                    $mc_versions->data_seek(0);
                                    while ($mv = $mc_versions->fetch_assoc()) {
                                        $type_text = $mv['parent_type'] == 'java' ? 'Java版' : '基岩版';
                                        echo "<span class='tag'>{$type_text}-{$mv['name']}</span>";
                                    }
                                } else {
                                    echo '<span class="tag">暂无适配版本</span>';
                                }
                                ?>
                            </div>
                        </div>
                        
                        <!-- 运行平台 -->
                        <div class="info-item">
                            <span class="info-label">运行平台</span>
                            <div class="tag-flow-container">
                                <?php
                                if ($platforms && $platforms->num_rows > 0) {
                                    $platforms->data_seek(0);
                                    while ($p = $platforms->fetch_assoc()) {
                                        echo "<span class='tag'>{$p['name']}</span>";
                                    }
                                } else {
                                    echo '<span class="tag">暂无适配平台</span>';
                                }
                                ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="copy-toast" id="copyToast">复制成功！</div>

    <div class="modal-overlay" id="modalOverlay">
        <div class="download-card">
            <div class="download-card-header">
                <div class="download-card-title">下载 <?php echo $file['title']; ?> - <?php echo $latest_version_number; ?></div>
                <button class="close-btn" id="closeDownloadCard">×</button>
            </div>
            <div class="points-requirement">需要<?php echo $file['price']; ?>积分</div>
            <?php if (is_logged_in()): ?>
                <form method="post">
                    <button type="submit" class="buy-download-btn">购买后下载</button>
                </form>
            <?php else: ?>
                <a href="login.php" class="buy-download-btn" style="text-decoration:none;">登录后购买</a>
            <?php endif; ?>
            
            <div class="history-version-title">历史版本</div>
            <?php if ($latest_version): ?>
                <div class="version-row">
                    <div class="version-tag">最新</div>
                    <div class="version-info">
                        <?php echo $latest_version['version']; ?>
                        <div class="version-full-info">[<?php echo $latest_version['upload_time']; ?>] <?php echo $latest_version['filename']; ?></div>
                    </div>
                    <?php if (is_logged_in() && $has_access): ?>
                        <a href="download.php?version_id=<?php echo $latest_version['id']; ?>" class="version-download-btn">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 0 0 3 3h10a3 3 0 0 0 3-3v-1m-4-4-4 4m0 0-4-4m4 4V4"></path>
                            </svg>
                            下载
                        </a>
                    <?php else: ?>
                        <button class="version-download-btn" disabled>没有下载权限</button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <br>
    <!-- ========== 正文和页脚之间的过渡细线 ========== -->
    <div class="content-footer-divider"></div>

    <!-- ========== 页脚组件 ========== -->
    <footer class="footer-container w-full text-[#2c2e31]">
        <div class="max-w-5xl mx-auto p-6">
            <div class="flex flex-col md:flex-row justify-center md:space-x-12 gap-6">
                <div class="flex flex-col items-center md:items-start flex-shrink-0">
                    <div class="flex items-center gap-3 mb-4">
                        <span class="text-3xl font-bold">GetPlugins</span>
                    </div>
                    <div class="flex gap-4 mb-4 text-[#2c2e31]">
                        <i class="fa-brands fa-discord text-lg"></i>
                        <i class="fa-brands fa-twitter text-lg"></i>
                        <i class="fa-brands fa-mastodon text-lg"></i>
                        <i class="fa-brands fa-x-twitter text-lg"></i>
                        <i class="fa-brands fa-github text-lg"></i>
                    </div>
                    <p class="mb-2 text-base text-center md:text-left">GetPlugins 是一个<span class="text-green-600 font-medium">开源项目</span>。</p>
                    <p class="text-base text-center md:text-left">© 2026 GetPlugins</p>
                </div>

                <div class="grid grid-cols-2 md:grid-cols-4 gap-6 w-full max-w-3xl">
                    <div>
                        <h3 class="font-bold mb-3 text-lg">关于</h3>
                        <ul class="space-y-2 text-base">
                            <li>新闻</li>
                            <li>更新日志</li>
                            <li>服务状态</li>
                            <li>招贤纳士</li>
                            <li>激励计划</li>
                        </ul>
                    </div>
                    <div>
                        <h3 class="font-bold mb-3 text-lg">产品</h3>
                    </div>
                    <div>
                        <h3 class="font-bold mb-3 text-lg">资源</h3>
                        <ul class="space-y-2 text-base">
                            <li>帮助中心</li>
                            <li>翻译</li>
                            <li>报告问题</li>
                            <li>API 文档</li>
                        </ul>
                    </div>
                    <div>
                        <h3 class="font-bold mb-3 text-lg">法律文件</h3>
                        <ul class="space-y-2 text-base">
                            <li>内容规范</li>
                            <li>使用条款</li>
                            <li>隐私政策</li>
                            <li>安全声明</li>
                            <li>版权政策与 DMCA</li>
                        </ul>
                    </div>
                </div>
            </div>
            <div class="mt-6 text-xs text-gray-400 text-center">
                非官方 Minecraft 产品，未经 Mojang 或 Microsoft 批准或关联。
            </div>
        </div>
    </footer>

    <!-- ========== 页眉、页脚、加载动画相关JS ========== -->
    <script>
        // ========== 下拉菜单功能 ==========
        const dropdownBtn = document.getElementById('dropdownBtn');
        const dropdownMenu = document.getElementById('dropdownMenu');
        const dropdownArrow = document.getElementById('dropdownArrow');
        const pointsContainer = document.getElementById('pointsContainer');
        const pointsDropdown = document.getElementById('pointsDropdown');
        
        dropdownBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            pointsDropdown.classList.remove('active');
            
            if (dropdownMenu.classList.contains('active')) {
                dropdownMenu.classList.remove('active');
                dropdownArrow.classList.remove('rotate');
                setTimeout(() => {
                    dropdownMenu.style.display = 'none';
                }, 200);
            } else {
                dropdownMenu.style.display = 'block';
                setTimeout(() => {
                    dropdownMenu.classList.add('active');
                    dropdownArrow.classList.add('rotate');
                }, 10);
            }
        });
        
        pointsContainer.addEventListener('click', (e) => {
            e.stopPropagation();
            dropdownMenu.classList.remove('active');
            dropdownArrow.classList.remove('rotate');
            setTimeout(() => {
                dropdownMenu.style.display = 'none';
            }, 200);
            
            if (pointsDropdown.classList.contains('active')) {
                pointsDropdown.classList.remove('active');
                setTimeout(() => {
                    pointsDropdown.style.display = 'none';
                }, 200);
            } else {
                pointsDropdown.style.display = 'block';
                setTimeout(() => {
                    pointsDropdown.classList.add('active');
                }, 10);
            }
        });
        
        document.addEventListener('click', () => {
            dropdownMenu.classList.remove('active');
            dropdownArrow.classList.remove('rotate');
            setTimeout(() => {
                dropdownMenu.style.display = 'none';
            }, 200);
            
            pointsDropdown.classList.remove('active');
            setTimeout(() => {
                pointsDropdown.style.display = 'none';
            }, 200);
        });
        
        dropdownMenu.addEventListener('click', (e) => {
            e.stopPropagation();
        });
        pointsDropdown.addEventListener('click', (e) => {
            e.stopPropagation();
        });

        // ========== 加载动画功能 ==========
        function showProgress() {
            const progressContainer = document.getElementById('progressContainer');
            const progressBar = document.getElementById('progressBar');
            
            // 立即显示进度条
            progressContainer.classList.add('show');
            progressBar.style.width = '30%';
            
            // 模拟逐步加载
            let progress = 30;
            const interval = setInterval(() => {
                progress += 5;
                progressBar.style.width = progress + '%';
                
                if (progress >= 90) {
                    clearInterval(interval);
                }
            }, 50);
        }
        
        function hideProgress() {
            const progressContainer = document.getElementById('progressContainer');
            const progressBar = document.getElementById('progressBar');
            
            // 完成加载
            progressBar.style.width = '100%';
            
            // 延迟隐藏
            setTimeout(() => {
                progressContainer.classList.remove('show');
                setTimeout(() => {
                    progressBar.style.width = '0%';
                }, 300);
            }, 300);
        }
        
        // 页面加载完成后初始化
        document.addEventListener('DOMContentLoaded', function() {
            // 如果你需要在页面加载时显示加载动画，可以调用：
            // showProgress();
            // 然后在内容加载完成后调用：
            // hideProgress();
        });
    </script>
    <script>
        const moreBtn = document.getElementById('moreBtn');
        const moreMenu = document.getElementById('moreMenu');
        const copyIdItem = document.getElementById('copyIdItem');
        const copyLinkItem = document.getElementById('copyLinkItem');
        const reportItem = document.getElementById('reportItem');
        const copyToast = document.getElementById('copyToast');
        const mainDownloadBtn = document.querySelector('.download-btn');
        const modalOverlay = document.getElementById('modalOverlay');
        const closeDownloadCard = document.getElementById('closeDownloadCard');

        // 从PHP变量获取资源ID和链接
        const RESOURCE_ID = '<?php echo $RESOURCE_ID; ?>';
        const PERMANENT_LINK = '<?php echo $PERMANENT_LINK; ?>';

        moreBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            if (moreMenu.classList.contains('show')) {
                moreMenu.classList.remove('show');
                setTimeout(() => {
                    moreMenu.style.display = 'none';
                }, 200);
            } else {
                moreMenu.style.display = 'block';
                setTimeout(() => {
                    moreMenu.classList.add('show');
                }, 10);
            }
        });

        document.addEventListener('click', (e) => {
            if (!moreMenu.contains(e.target) && e.target !== moreBtn) {
                moreMenu.classList.remove('show');
                setTimeout(() => {
                    moreMenu.style.display = 'none';
                }, 200);
            }
            
            if (!modalOverlay.contains(e.target) && e.target !== mainDownloadBtn) {
                modalOverlay.classList.remove('show');
            }
        });

        copyIdItem.addEventListener('click', (e) => {
            e.stopPropagation();
            navigator.clipboard.writeText(RESOURCE_ID).then(() => {
                showToast();
                moreMenu.classList.remove('show');
                setTimeout(() => {
                    moreMenu.style.display = 'none';
                }, 200);
            }).catch(err => {
                console.error('Failed to copy ID:', err);
                alert('复制失败！请重试。');
            });
        });

        copyLinkItem.addEventListener('click', (e) => {
            e.stopPropagation();
            navigator.clipboard.writeText(PERMANENT_LINK).then(() => {
                showToast();
                moreMenu.classList.remove('show');
                setTimeout(() => {
                    moreMenu.style.display = 'none';
                }, 200);
            }).catch(err => {
                console.error('Failed to copy link:', err);
                alert('复制失败！请重试。');
            });
        });

        reportItem.addEventListener('click', (e) => {
            e.stopPropagation();
            alert('反馈功能即将上线！');
            moreMenu.classList.remove('show');
            setTimeout(() => {
                moreMenu.style.display = 'none';
            }, 200);
        });

        function showToast() {
            copyToast.classList.add('show');
            setTimeout(() => {
                copyToast.classList.remove('show');
            }, 2000);
        }

        // 只有未购买状态才绑定弹窗事件
        <?php if (is_logged_in() && !$has_access || !is_logged_in()): ?>
        if (mainDownloadBtn && !mainDownloadBtn.disabled) {
            mainDownloadBtn.addEventListener('click', (e) => {
                // 如果是购买按钮，不阻止默认行为
                if (mainDownloadBtn.textContent.includes('购买')) return;
                
                e.stopPropagation();
                e.preventDefault();
                const btnRect = mainDownloadBtn.getBoundingClientRect();
                modalOverlay.style.setProperty('--origin-x', `${btnRect.left}px`);
                modalOverlay.style.setProperty('--origin-y', `${btnRect.bottom}px`);
                modalOverlay.classList.add('show');
            });
        }
        <?php endif; ?>

        closeDownloadCard.addEventListener('click', (e) => {
            e.stopPropagation();
            modalOverlay.classList.remove('show');
        });
        
        // 点赞功能
        function likeResource(fileId) {
            const btn = document.querySelector(`.action-btn[onclick="likeResource(${fileId})"]`);
            const countEl = document.getElementById('like-count');
            
            if (!btn) return;
            
            if (btn.classList.contains('liked')) {
                // 取消点赞
                const xhr = new XMLHttpRequest();
                xhr.open('POST', 'unlike.php', true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onreadystatechange = function() {
                    if (xhr.readyState === 4) {
                        if (xhr.status === 200) {
                            const res = JSON.parse(xhr.responseText);
                            if (res.success) {
                                btn.classList.remove('liked');
                                btn.querySelector('svg').setAttribute('fill', 'none');
                                countEl.textContent = res.count;
                            } else {
                                alert(res.message);
                            }
                        } else {
                            alert('取消点赞失败，请重试');
                        }
                    }
                };
                xhr.send(`file_id=${fileId}`);
            } else {
                // 点赞
                const xhr = new XMLHttpRequest();
                xhr.open('POST', 'like.php', true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onreadystatechange = function() {
                    if (xhr.readyState === 4) {
                        if (xhr.status === 200) {
                            const res = JSON.parse(xhr.responseText);
                            if (res.success) {
                                btn.classList.add('liked');
                                btn.querySelector('svg').setAttribute('fill', 'currentColor');
                                countEl.textContent = res.count;
                            } else {
                                alert(res.message);
                            }
                        } else {
                            alert('点赞失败，请重试');
                        }
                    }
                };
                xhr.send(`file_id=${fileId}`);
            }
        }
    </script>
</body>
</html>