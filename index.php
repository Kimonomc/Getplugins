<?php
session_start();
require_once "functions.php";

$cookie_expire = time() + 7 * 24 * 60 * 60;

$default_filter = [
    "sort_by" => "update_time",
    "sort_order" => "desc",
    "per_page" => 10,
    "page" => 1,
    "search" => "",
];

$filter = $default_filter;
if (isset($_COOKIE["mc_resource_filter"])) {
    $cookie_filter = json_decode($_COOKIE["mc_resource_filter"], true);
    if (is_array($cookie_filter)) {
        $filter["sort_by"] = in_array($cookie_filter["sort_by"] ?? "", [
            "update_time",
            "download_count",
            "like_count",
        ])
            ? $cookie_filter["sort_by"]
            : $default_filter["sort_by"];

        $filter["sort_order"] = in_array($cookie_filter["sort_order"] ?? "", [
            "asc",
            "desc",
        ])
            ? $cookie_filter["sort_order"]
            : $default_filter["sort_order"];

        $filter["per_page"] = in_array($cookie_filter["per_page"] ?? "", [
            5,
            10,
            20,
            50,
        ])
            ? (int) $cookie_filter["per_page"]
            : $default_filter["per_page"];

        $filter["search"] = isset($cookie_filter["search"])
            ? trim($cookie_filter["search"])
            : "";
    }
}

if (
    isset($_SERVER["HTTP_X_REQUESTED_WITH"]) &&
    strtolower($_SERVER["HTTP_X_REQUESTED_WITH"]) === "xmlhttprequest"
) {
    $filter["page"] =
        isset($_POST["page"]) && is_numeric($_POST["page"])
            ? max(1, (int) $_POST["page"])
            : 1;
    $filter["sort_by"] = in_array($_POST["sort_by"] ?? "", [
        "update_time",
        "download_count",
        "like_count",
    ])
        ? $_POST["sort_by"]
        : $filter["sort_by"];
    $filter["sort_order"] = in_array($_POST["sort_order"] ?? "", [
        "asc",
        "desc",
    ])
        ? $_POST["sort_order"]
        : $filter["sort_order"];
    $filter["per_page"] = in_array($_POST["per_page"] ?? "", [5, 10, 20, 50])
        ? (int) $_POST["per_page"]
        : $filter["per_page"];
    $filter["search"] = isset($_POST["search"])
        ? trim($_POST["search"])
        : $filter["search"];

    $offset = ($filter["page"] - 1) * $filter["per_page"];

    $where_clause = "";
    $params = [];
    if (!empty($filter["search"])) {
        $where_clause = "WHERE title LIKE ? OR brief LIKE ?";
        $search_param = "%{$filter["search"]}%";
        $params = [$search_param, $search_param];
    }

    $count_sql = "SELECT COUNT(*) AS total FROM files {$where_clause}";
    $count_stmt = $conn->prepare($count_sql);
    if (!empty($params)) {
        $count_stmt->bind_param("ss", ...$params);
    }
    $count_stmt->execute();
    $total_records = $count_stmt->get_result()->fetch_assoc()["total"];
    $total_pages = ceil($total_records / $filter["per_page"]);

    $files_sql = "SELECT f.*, COALESCE(l.like_count, 0) AS like_count 
                  FROM files f 
                  LEFT JOIN (SELECT file_id, COUNT(*) AS like_count FROM likes GROUP BY file_id) l ON f.id = l.file_id
                  {$where_clause}
                  ORDER BY {$filter["sort_by"]} {$filter["sort_order"]}
                  LIMIT ? OFFSET ?";

    $files_stmt = $conn->prepare($files_sql);
    $types = "";
    $bind_params = $params;
    $bind_params[] = $filter["per_page"];
    $bind_params[] = $offset;

    for ($i = 0; $i < count($bind_params); $i++) {
        $types .= $i < count($params) ? "s" : "i";
    }

    if (!empty($bind_params)) {
        $files_stmt->bind_param($types, ...$bind_params);
    }
    $files_stmt->execute();
    $files_result = $files_stmt->get_result();

    $html = "";
    if ($files_result->num_rows > 0) {
        while ($file = $files_result->fetch_assoc()) {
            $html .= '<div class="project-card">';
            $html .= '<div class="card-left">';
            $html .= '<div class="card-icon-wrapper">';
            $html .=
                '<img class="card-icon" src="' .
                htmlspecialchars($file["icon"]) .
                '" alt="' .
                htmlspecialchars($file["title"]) .
                '图标">';
            $html .= "</div>";
            $html .= '<div class="card-content">';
            $html .=
                '<h2 class="card-title"><a href="file_detail.php?id=' .
                $file["id"] .
                '">' .
                htmlspecialchars($file["title"]) .
                "</a></h2>";
            $html .=
                '<p class="card-desc">' .
                htmlspecialchars($file["brief"]) .
                "</p>";
            $html .= '<div class="card-tags">';

            $cat_stmt = $conn->prepare(
                "SELECT name FROM categories WHERE id = ?"
            );
            $cat_stmt->bind_param("i", $file["category_id"]);
            $cat_stmt->execute();
            $category = $cat_stmt->get_result()->fetch_assoc();
            if ($category) {
                $html .=
                    '<span class="card-tag">' .
                    htmlspecialchars($category["name"]) .
                    "</span>";
            }

            $mc_versions = get_file_mc_versions($file["id"]);
            if ($mc_versions->num_rows > 0) {
                while ($mv = $mc_versions->fetch_assoc()) {
                    $type = $mv["parent_type"] == "java" ? "Java" : "基岩";
                    $html .=
                        '<span class="card-tag">' .
                        htmlspecialchars($type . "-" . $mv["name"]) .
                        "</span>";
                }
            }

            $platforms = get_file_platforms($file["id"]);
            if ($platforms->num_rows > 0) {
                while ($p = $platforms->fetch_assoc()) {
                    $html .=
                        '<span class="card-tag"><i class="fas fa-thread"></i>' .
                        htmlspecialchars($p["name"]) .
                        "</span>";
                }
            }

            $html .= "</div>";
            $html .= "</div></div>";

            $html .= '<div class="card-stats">';
            $html .=
                '<div class="card-stat"><i class="fas fa-download"></i><strong>' .
                number_format($file["download_count"]) .
                "</strong><span>下载</span></div>";
            $html .=
                '<div class="card-stat"><i class="fas fa-heart"></i><strong>' .
                $file["like_count"] .
                "</strong><span>点赞</span></div>";
            $html .= "</div>";

            $html .= '<div class="update-time-desktop">';
            $html .=
                '<i class="fas fa-sync-alt"></i><span>更新于' .
                date("Y-m-d", strtotime($file["update_time"])) .
                "</span>";
            $html .= "</div>";

            $html .= '<div class="mobile-stats-wrapper">';
            $html .= '<div class="mobile-stats-left">';
            $html .=
                '<div class="card-stat"><i class="fas fa-download"></i><strong>' .
                number_format($file["download_count"]) .
                "</strong><span>下载</span></div>";
            $html .=
                '<div class="card-stat"><i class="fas fa-heart"></i><strong>' .
                $file["like_count"] .
                "</strong><span>点赞</span></div>";
            $html .= "</div>";
            $html .= '<div class="update-time-mobile">';
            $html .=
                '<i class="fas fa-sync-alt"></i><span>更新于' .
                date("Y-m-d", strtotime($file["update_time"])) .
                "</span>";
            $html .= "</div></div></div>";
        }
    } else {
        $html .=
            '<div class="project-card"><div class="card-left"><div class="card-content"><h2 class="card-title">暂无符合条件的资源</h2></div></div></div>';
    }

    $pagination = "";
    if ($total_pages > 1) {
        $pagination .= '<div class="pagination">';

        if ($filter["page"] > 1) {
            $pagination .=
                '<button class="page-btn" onclick="loadResources(' .
                ($filter["page"] - 1) .
                ')">&lt;</button>';
        } else {
            $pagination .= '<button class="page-btn" disabled>&lt;</button>';
        }

        $start_page = max(1, $filter["page"] - 2);
        $end_page = min($total_pages, $filter["page"] + 2);

        if ($start_page > 1) {
            $pagination .=
                '<button class="page-btn" onclick="loadResources(1)">1</button>';
            if ($start_page > 2) {
                $pagination .= '<span class="page-ellipsis">...</span>';
            }
        }

        for ($i = $start_page; $i <= $end_page; $i++) {
            $active = $i == $filter["page"] ? "active" : "";
            $pagination .=
                '<button class="page-btn ' .
                $active .
                '" onclick="loadResources(' .
                $i .
                ')">' .
                $i .
                "</button>";
        }

        if ($end_page < $total_pages) {
            if ($end_page < $total_pages - 1) {
                $pagination .= '<span class="page-ellipsis">...</span>';
            }
            $pagination .=
                '<button class="page-btn" onclick="loadResources(' .
                $total_pages .
                ')">' .
                $total_pages .
                "</button>";
        }

        if ($filter["page"] < $total_pages) {
            $pagination .=
                '<button class="page-btn" onclick="loadResources(' .
                ($filter["page"] + 1) .
                ')">&gt;</button>';
        } else {
            $pagination .= '<button class="page-btn" disabled>&gt;</button>';
        }

        $pagination .= "</div>";
    }

    echo json_encode([
        "success" => true,
        "html" => $html,
        "pagination" => $pagination,
        "total_pages" => $total_pages,
        "current_page" => $filter["page"],
    ]);
    exit();
}

$filter["page"] =
    isset($_GET["page"]) && is_numeric($_GET["page"])
        ? max(1, (int) $_GET["page"])
        : 1;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (isset($_POST["action"]) && $_POST["action"] === "search") {
        $filter["search"] = trim($_POST["search"]);
        $filter["page"] = 1;
        setcookie(
            "mc_resource_filter",
            json_encode($filter),
            $cookie_expire,
            "/"
        );
        header("Location: " . $_SERVER["PHP_SELF"]);
        exit();
    }
    if (isset($_POST["action"]) && $_POST["action"] === "filter") {
        $filter["sort_by"] = in_array($_POST["sort_by"] ?? "", [
            "update_time",
            "download_count",
            "like_count",
        ])
            ? $_POST["sort_by"]
            : $filter["sort_by"];

        $filter["sort_order"] = in_array($_POST["sort_order"] ?? "", [
            "asc",
            "desc",
        ])
            ? $_POST["sort_order"]
            : $filter["sort_order"];

        $filter["per_page"] = in_array($_POST["per_page"] ?? "", [
            5,
            10,
            20,
            50,
        ])
            ? (int) $_POST["per_page"]
            : $filter["per_page"];

        $filter["page"] = 1;
        setcookie(
            "mc_resource_filter",
            json_encode($filter),
            $cookie_expire,
            "/"
        );
        header("Location: " . $_SERVER["PHP_SELF"]);
        exit();
    }
}

$offset = ($filter["page"] - 1) * $filter["per_page"];

$where_clause = "";
$params = [];
if (!empty($filter["search"])) {
    $where_clause = "WHERE title LIKE ? OR brief LIKE ?";
    $search_param = "%{$filter["search"]}%";
    $params = [$search_param, $search_param];
}

$count_sql = "SELECT COUNT(*) AS total FROM files {$where_clause}";
$count_stmt = $conn->prepare($count_sql);
if (!empty($params)) {
    $count_stmt->bind_param("ss", ...$params);
}
$count_stmt->execute();
$total_records = $count_stmt->get_result()->fetch_assoc()["total"];
$total_pages = ceil($total_records / $filter["per_page"]);

$files_sql = "SELECT f.*, COALESCE(l.like_count, 0) AS like_count 
              FROM files f 
              LEFT JOIN (SELECT file_id, COUNT(*) AS like_count FROM likes GROUP BY file_id) l ON f.id = l.file_id
              {$where_clause}
              ORDER BY {$filter["sort_by"]} {$filter["sort_order"]}
              LIMIT ? OFFSET ?";

$files_stmt = $conn->prepare($files_sql);
$types = "";
$bind_params = $params;
$bind_params[] = $filter["per_page"];
$bind_params[] = $offset;

for ($i = 0; $i < count($bind_params); $i++) {
    $types .= $i < count($params) ? "s" : "i";
}

if (!empty($bind_params)) {
    $files_stmt->bind_param($types, ...$bind_params);
}
$files_stmt->execute();
$files_result = $files_stmt->get_result();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GetPlugins.XYZ 立即获取Minecraft插件、地图、模型等资源</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        /* ========== 原有HTML模板样式 ========== */
        body {
            background-color: #ebebeb;
        }
        
        .header-fixed {
            position: static;
            width: 100%;
        }
        
        /* 新增：手机端页眉顶部增加内边距，避免靠近顶端 */
        /* 基础页脚样式（所有设备） */
        .footer-container {
            background-color: #d3eadb; /* 所有设备都有背景色 */
            width: 100%;
            color: #2c2e31;
            /* 移除全局的 max-height，只在桌面版设置 */
            width: 100%;
            box-sizing: border-box; /* 确保内边距不会超出宽度 */
        }
        
        /* 桌面版页脚样式 */
        @media (min-width: 768px) {
            .footer-container {
                max-height: 350px; /* 仅桌面版限制最大高度 */
            }
        }
        
        /* 手机版页脚样式 - 关键修复 */
        @media (max-width: 767px) {
            .footer-container {
                max-height: none; /* 取消手机版高度限制，让高度自适应内容 */
                min-height: auto; /* 确保高度跟随内容 */
                padding: 20px 0; /* 优化手机版内边距，提升显示效果 */
            }
            /* 可选：优化手机版页脚内的布局间距 */
            .footer-container .grid {
                gap: 10px; /* 减小手机版列间距 */
            }
            .footer-container ul {
                margin-bottom: 15px; /* 增加列表底部间距 */
            }
        }
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
        
        /* 修改1：将电脑版页脚最大高度从320改为350，并添加背景颜色设置 */
        @media (min-width: 768px) {
            .footer-container {
                max-height: 350px;
                background-color: #d3eadb;
            }
        }

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

        /* 新增：正文和页脚之间的过渡细线样式 */
        .content-footer-divider {
            width: 100%;
            height: 1px; /* 细线高度 */
            background-color: #b7cebe; /* 指定的过渡颜色 */
            margin: 0 auto;
            max-width: 5xl; /* 与正文/页脚容器宽度保持一致 */
        }

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
        
        /* 重写body样式以适配新布局 */
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: #1a202c;
            margin: 0;
            padding: 0; /* 移除原有padding，由header/main/footer控制 */
            background-color: #ebebeb;
            display: block; /* 取消原有flex布局 */
        }
        
        /* 适配新的main容器宽度 */
        .main-container {
            display: flex;
            gap: 16px;
            align-items: flex-start;
            max-width: 100%; /* 改为100%适配新布局 */
            width: 100%;
            padding: 0;
        }
        
        .main-cards-column {
            display: flex;
            flex-direction: column;
            gap: 0;
            width: 100%;
        }
        
        .search-box {
            width: 100%;
            position: relative;
            margin-bottom: 12px;
        }
        
        .search-input {
            width: 100%;
            padding: 12px 16px 12px 40px;
            border: none;
            border-radius: 16px;
            background: #ffffff;
            font-size: 1rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            box-sizing: border-box;
            outline: none;
        }
        
        .search-input:focus {
            box-shadow: 0 0 0 2px rgba(45, 157, 105, 0.2);
        }
        
        .search-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #718096;
            font-size: 1rem;
        }
        
        .filters-row {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 12px;
            flex-wrap: wrap;
        }
        
        .custom-select-container {
            width: fit-content;
            position: relative;
            flex-shrink: 0;
        }
        
        .select-trigger {
            padding: 8px 16px;
            border: none;
            border-radius: 16px;
            background: #ffffff;
            font-size: 0.95rem;
            font-weight: 500;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            outline: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            min-width: 140px;
            justify-content: space-between;
        }
        
        .select-trigger:focus {
            box-shadow: 0 0 0 2px rgba(45, 157, 105, 0.2);
        }
        
        .select-arrow {
            transition: transform 0.3s ease;
            font-size: 0.8rem;
        }
        
        .select-arrow.open {
            transform: rotate(180deg);
        }
        
        .select-options {
            position: absolute;
            top: calc(100% + 8px);
            left: 0;
            width: 100%;
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            overflow: hidden;
            max-height: 0;
            opacity: 0;
            transition: max-height 0.3s ease, opacity 0.3s ease;
            z-index: 10;
            transform-origin: top center;
        }
        
        .select-options.open {
            max-height: 200px;
            opacity: 1;
        }
        
        .select-option {
            padding: 8px 16px;
            cursor: pointer;
            font-size: 0.95rem;
        }
        
        .select-option:hover {
            background-color: #f5f5f5;
        }
        
        .select-option.selected {
            background-color: #baf1d7;
            color: #1a202c;
            font-weight: 500;
        }
        
        .pagination {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
            justify-content: flex-end;
        }
        
        .page-btn {
            width: 32px;
            height: 32px;
            padding: 0;
            border: none;
            background: transparent;
            font-size: 1.1rem;
            font-weight: 500;
            box-shadow: none;
            outline: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #1a202c;
            border-radius: 50%;
            transition: all 0.2s ease;
        }
        
        .page-btn:hover {
            background: #2d9d69;
            color: white;
            font-weight: 700;
        }
        
        .page-btn.active {
            background: transparent;
            color: #2d9d69;
            font-weight: 700;
        }
        
        .page-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            background: transparent;
            color: #718096;
            font-weight: 500;
        }
        
        .page-ellipsis {
            font-size: 1.1rem;
            color: #718096;
            padding: 0 4px;
        }
        
        .project-card {
            background: #f8f8f8;
            border-radius: 12px;
            padding: 20px;
            max-width: 100%; /* 适配新布局宽度 */
            min-height: 135px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            display: flex;
            align-items: flex-start;
            gap: 20px;
            position: relative;
            width: 100%;
            box-sizing: border-box;
            margin-bottom: 20px;
        }
        
        .project-card:last-child {
            margin-bottom: 0;
        }
        
        .card-left {
            flex: 1;
            display: flex;
            align-items: center;
            gap: 16px;
        }
        
        .card-icon-wrapper {
            flex-shrink: 0;
            display: flex;
            justify-content: flex-start;
            align-items: center;
        }
        
        .card-icon {
            width: 96px;
            height: 96px;
            border-radius: 8px;
            object-fit: cover;
        }
        
        .card-content {
            flex: 1;
            margin: 0;
            padding: 0;
        }
        
        .card-title {
            font-size: 1.5rem;
            font-weight: 700;
            margin: 0 0 8px 0;
        }
        
        .card-title a {
            text-decoration: none;
            color: #1a202c;
        }
        
        .card-title a:hover {
            color: #2d9d69;
        }
        
        .card-desc {
            margin: 0 0 12px 0;
            line-height: 1.5;
            color: #4a5568;
        }
        
        .card-tags {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .card-tag {
            display: flex;
            align-items: center;
            gap: 4px;
            font-size: 0.9rem;
            padding: 4px 8px;
            background: #ffffff;
            border-radius: 8px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.1);
        }
        
        .card-stats {
            flex-shrink: 0;
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 12px;
        }
        
        .card-stat {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 1.1rem;
            white-space: nowrap;
        }
        
        .card-stat strong {
            font-weight: 700;
            font-size: 1.3rem;
        }
        
        .update-time-desktop {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 1.1rem;
            color: #4a5568;
            white-space: nowrap;
            position: absolute;
            right: 20px;
            bottom: 20px;
        }
        
        .mobile-stats-wrapper {
            display: none;
            align-items: center;
            justify-content: space-between;
            width: 100%;
            margin-top: 8px;
        }
        
        .mobile-stats-left {
            display: flex;
            gap: 20px;
        }
        
        .update-time-mobile {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 1.1rem;
            color: #4a5568;
            white-space: nowrap;
        }
        
        /* 移除原有user-info样式，改为使用新页眉 */
        .user-info {
            display: none;
        }
        
        /* 响应式适配 */
        @media (max-width: 767px) {
            .project-card {
                flex-direction: column;
                align-items: flex-start;
                position: static;
                gap: 0;
                min-height: 135px;
                margin-bottom: 16px;
            }
            
            .card-left {
                width: 100%;
            }
            
            .card-stats {
                display: none;
            }
            
            .update-time-desktop {
                display: none;
            }
            
            .mobile-stats-wrapper {
                display: flex;
            }
            
            .filters-row {
                flex-direction: column;
                gap: 8px;
            }
            
            .custom-select-container {
                width: 100%;
            }
            
            .select-trigger {
                width: 100%;
                min-width: unset;
            }
            
            .pagination {
                justify-content: center;
            }
        }
        
        @media (max-width: 550px) {
            .card-stats .card-stat span {
                display: none;
            }
            
            .mobile-stats-left .card-stat span {
                display: none;
            }
            
            .card-stats .card-stat,
            .mobile-stats-left .card-stat {
                gap: 4px;
            }
            
            .pagination {
                flex-wrap: wrap;
            }
            
            .project-card {
                margin-bottom: 12px;
            }
        }
    </style>
</head>
<body>
    <!-- ========== 新页眉（替换原有user-info） ========== -->
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
                            <span class="font-extrabold"><?php echo is_logged_in()
                                ? $_SESSION["points"]
                                : "0"; ?></span>
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
                                <?php echo strtoupper(
                                    substr($_SESSION["username"], 0, 1)
                                ); ?>
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

    <!-- ========== 主要内容区域（保留原有功能） ========== -->
    <main class="max-w-5xl mx-auto px-6 py-10 min-h-[500px]">
        <!-- 进度条 -->
        <div class="progress-container" id="progressContainer">
            <div class="progress-bar" id="progressBar"></div>
        </div>
        
        <div class="main-container">
            <div class="main-cards-column">
                <!-- 搜索框 -->
                <div class="search-box">
                    <i class="fas fa-search search-icon"></i>
                    <input type="text" class="search-input" id="searchInput" placeholder="搜索资源名称/简介..." 
                           value="<?php echo htmlspecialchars(
                               $filter["search"]
                           ); ?>">
                </div>
                
                <!-- 筛选栏 -->
                <div class="filters-row">
                    <div class="custom-select-container" id="sortByContainer">
                        <button class="select-trigger" id="sortByTrigger">
                            <span>
                                <?php
                                $sortByText = [
                                    "update_time" => "按更新时间",
                                    "download_count" => "按下载量",
                                    "like_count" => "按点赞数",
                                ];
                                echo "排序: " .
                                    ($sortByText[$filter["sort_by"]] ??
                                        "按更新时间");
                                ?>
                            </span>
                            <i class="fas fa-chevron-down select-arrow"></i>
                        </button>
                        <div class="select-options" id="sortByOptions">
                            <div class="select-option <?php echo $filter[
                                "sort_by"
                            ] == "update_time"
                                ? "selected"
                                : ""; ?>" 
                                 data-value="update_time" onclick="changeSortBy('update_time')">排序: 更新时间</div>
                            <div class="select-option <?php echo $filter[
                                "sort_by"
                            ] == "download_count"
                                ? "selected"
                                : ""; ?>" 
                                 data-value="download_count" onclick="changeSortBy('download_count')">排序: 下载量</div>
                            <div class="select-option <?php echo $filter[
                                "sort_by"
                            ] == "like_count"
                                ? "selected"
                                : ""; ?>" 
                                 data-value="like_count" onclick="changeSortBy('like_count')">排序: 点赞数</div>
                        </div>
                    </div>
                    
                    <div class="custom-select-container" id="sortOrderContainer">
                        <button class="select-trigger" id="sortOrderTrigger">
                            <span>顺序: <?php echo $filter["sort_order"] ==
                            "desc"
                                ? "正序"
                                : "倒序"; ?></span>
                            <i class="fas fa-chevron-down select-arrow"></i>
                        </button>
                        <div class="select-options" id="sortOrderOptions">
                            <div class="select-option <?php echo $filter[
                                "sort_order"
                            ] == "desc"
                                ? "selected"
                                : ""; ?>" 
                                 data-value="desc" onclick="changeSortOrder('desc')">顺序: 正序</div>
                            <div class="select-option <?php echo $filter[
                                "sort_order"
                            ] == "asc"
                                ? "selected"
                                : ""; ?>" 
                                 data-value="asc" onclick="changeSortOrder('asc')">顺序: 倒序</div>
                        </div>
                    </div>
                    
                    <div class="custom-select-container" id="perPageContainer">
                        <button class="select-trigger" id="perPageTrigger">
                            <span>每页: <?php echo $filter[
                                "per_page"
                            ]; ?>条</span>
                            <i class="fas fa-chevron-down select-arrow"></i>
                        </button>
                        <div class="select-options" id="perPageOptions">
                            <div class="select-option <?php echo $filter[
                                "per_page"
                            ] == 5
                                ? "selected"
                                : ""; ?>" 
                                 data-value="5" onclick="changePerPage(5)">每页: 5条</div>
                            <div class="select-option <?php echo $filter[
                                "per_page"
                            ] == 10
                                ? "selected"
                                : ""; ?>" 
                                 data-value="10" onclick="changePerPage(10)">每页: 10条</div>
                            <div class="select-option <?php echo $filter[
                                "per_page"
                            ] == 20
                                ? "selected"
                                : ""; ?>" 
                                 data-value="20" onclick="changePerPage(20)">每页: 20条</div>
                            <div class="select-option <?php echo $filter[
                                "per_page"
                            ] == 50
                                ? "selected"
                                : ""; ?>" 
                                 data-value="50" onclick="changePerPage(50)">每页: 50条</div>
                        </div>
                    </div>
                </div>
                
                <!-- 顶部分页 -->
                <div class="pagination" id="pagination-top">
                    <?php if ($total_pages > 1): ?>
                        <?php if ($filter["page"] > 1): ?>
                            <button class="page-btn" onclick="loadResources(<?php echo $filter[
                                "page"
                            ] - 1; ?>)">&lt;</button>
                        <?php else: ?>
                            <button class="page-btn" disabled>&lt;</button>
                        <?php endif; ?>
                        
                        <?php
                        $start_page = max(1, $filter["page"] - 2);
                        $end_page = min($total_pages, $filter["page"] + 2);

                        if ($start_page > 1): ?>
                            <button class="page-btn" onclick="loadResources(1)">1</button>
                            <?php if ($start_page > 2): ?>
                                <span class="page-ellipsis">...</span>
                            <?php endif; ?>
                        <?php endif;
                        ?>
                        
                        <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                            <button class="page-btn <?php echo $i ==
                            $filter["page"]
                                ? "active"
                                : ""; ?>" 
                                    onclick="loadResources(<?php echo $i; ?>)"><?php echo $i; ?></button>
                        <?php endfor; ?>
                        
                        <?php if ($end_page < $total_pages): ?>
                            <?php if ($end_page < $total_pages - 1): ?>
                                <span class="page-ellipsis">...</span>
                            <?php endif; ?>
                            <button class="page-btn" onclick="loadResources(<?php echo $total_pages; ?>)"><?php echo $total_pages; ?></button>
                        <?php endif; ?>
                        
                        <?php if ($filter["page"] < $total_pages): ?>
                            <button class="page-btn" onclick="loadResources(<?php echo $filter[
                                "page"
                            ] + 1; ?>)">&gt;</button>
                        <?php else: ?>
                            <button class="page-btn" disabled>&gt;</button>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                
                <!-- 资源列表容器 -->
                <div id="resourceListContainer">
                    <?php if ($files_result->num_rows > 0): ?>
                        <?php while ($file = $files_result->fetch_assoc()): ?>
                            <div class="project-card">
                                <div class="card-left">
                                    <div class="card-icon-wrapper">
                                        <img class="card-icon" src="<?php echo htmlspecialchars(
                                            $file["icon"]
                                        ); ?>" alt="<?php echo htmlspecialchars(
    $file["title"]
); ?>图标">
                                    </div>
                                    <div class="card-content">
                                        <h2 class="card-title">
                                            <a href="file_detail.php?id=<?php echo $file[
                                                "id"
                                            ]; ?>"><?php echo htmlspecialchars(
    $file["title"]
); ?></a>
                                        </h2>
                                        <p class="card-desc"><?php echo htmlspecialchars(
                                            $file["brief"]
                                        ); ?></p>
                                        <div class="card-tags">
                                            <?php
                                            $cat_stmt = $conn->prepare(
                                                "SELECT name FROM categories WHERE id = ?"
                                            );
                                            $cat_stmt->bind_param(
                                                "i",
                                                $file["category_id"]
                                            );
                                            $cat_stmt->execute();
                                            $category = $cat_stmt
                                                ->get_result()
                                                ->fetch_assoc();
                                            if ($category) {
                                                echo '<span class="card-tag">' .
                                                    htmlspecialchars(
                                                        $category["name"]
                                                    ) .
                                                    "</span>";
                                            }

                                            $mc_versions = get_file_mc_versions(
                                                $file["id"]
                                            );
                                            if ($mc_versions->num_rows > 0) {
                                                while (
                                                    $mv = $mc_versions->fetch_assoc()
                                                ) {
                                                    $type =
                                                        $mv["parent_type"] ==
                                                        "java"
                                                            ? "Java"
                                                            : "基岩";
                                                    echo '<span class="card-tag">' .
                                                        htmlspecialchars(
                                                            $type .
                                                                "-" .
                                                                $mv["name"]
                                                        ) .
                                                        "</span>";
                                                }
                                            }

                                            $platforms = get_file_platforms(
                                                $file["id"]
                                            );
                                            if ($platforms->num_rows > 0) {
                                                while (
                                                    $p = $platforms->fetch_assoc()
                                                ) {
                                                    echo '<span class="card-tag"><i class="fas fa-thread"></i>' .
                                                        htmlspecialchars(
                                                            $p["name"]
                                                        ) .
                                                        "</span>";
                                                }
                                            }
                                            ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="card-stats">
                                    <div class="card-stat">
                                        <i class="fas fa-download"></i>
                                        <strong><?php echo number_format(
                                            $file["download_count"]
                                        ); ?></strong>
                                        <span>下载</span>
                                    </div>
                                    <div class="card-stat">
                                        <i class="fas fa-heart"></i>
                                        <strong><?php echo $file[
                                            "like_count"
                                        ]; ?></strong>
                                        <span>点赞</span>
                                    </div>
                                </div>
                                <div class="update-time-desktop">
                                    <i class="fas fa-sync-alt"></i>
                                    <span>更新于 <?php echo date(
                                        "Y-m-d",
                                        strtotime($file["update_time"])
                                    ); ?></span>
                                </div>
                                <div class="mobile-stats-wrapper">
                                    <div class="mobile-stats-left">
                                        <div class="card-stat">
                                            <i class="fas fa-download"></i>
                                            <strong><?php echo number_format(
                                                $file["download_count"]
                                            ); ?></strong>
                                            <span>下载</span>
                                        </div>
                                        <div class="card-stat">
                                            <i class="fas fa-heart"></i>
                                            <strong><?php echo $file[
                                                "like_count"
                                            ]; ?></strong>
                                            <span>点赞</span>
                                        </div>
                                    </div>
                                    <div class="update-time-mobile">
                                        <i class="fas fa-sync-alt"></i>
                                        <span>更新于 <?php echo date(
                                            "Y-m-d",
                                            strtotime($file["update_time"])
                                        ); ?></span>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="project-card">
                            <div class="card-left">
                                <div class="card-content">
                                    <h2 class="card-title">暂无符合条件的资源</h2>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                <br>
                <!-- 底部分页 -->
                <div class="pagination" id="pagination-bottom">
                    <?php if ($total_pages > 1): ?>
                        <?php if ($filter["page"] > 1): ?>
                            <button class="page-btn" onclick="loadResources(<?php echo $filter[
                                "page"
                            ] - 1; ?>)">&lt;</button>
                        <?php else: ?>
                            <button class="page-btn" disabled>&lt;</button>
                        <?php endif; ?>
                        
                        <?php
                        $start_page = max(1, $filter["page"] - 2);
                        $end_page = min($total_pages, $filter["page"] + 2);

                        if ($start_page > 1): ?>
                            <button class="page-btn" onclick="loadResources(1)">1</button>
                            <?php if ($start_page > 2): ?>
                                <span class="page-ellipsis">...</span>
                            <?php endif; ?>
                        <?php endif;
                        ?>
                        
                        <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                            <button class="page-btn <?php echo $i ==
                            $filter["page"]
                                ? "active"
                                : ""; ?>" 
                                    onclick="loadResources(<?php echo $i; ?>)"><?php echo $i; ?></button>
                        <?php endfor; ?>
                        
                        <?php if ($end_page < $total_pages): ?>
                            <?php if ($end_page < $total_pages - 1): ?>
                                <span class="page-ellipsis">...</span>
                            <?php endif; ?>
                            <button class="page-btn" onclick="loadResources(<?php echo $total_pages; ?>)"><?php echo $total_pages; ?></button>
                        <?php endif; ?>
                        
                        <?php if ($filter["page"] < $total_pages): ?>
                            <button class="page-btn" onclick="loadResources(<?php echo $filter[
                                "page"
                            ] + 1; ?>)">&gt;</button>
                        <?php else: ?>
                            <button class="page-btn" disabled>&gt;</button>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <!-- 正文和页脚之间的过渡细线 -->
    <div class="content-footer-divider"></div>

    <!-- ========== 新页脚 ========== -->
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

    <!-- ========== 脚本区域（整合所有功能） ========== -->
    <script>
        // 下拉菜单脚本
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

        // 资源列表功能脚本
        let currentFilter = {
            page: <?php echo $filter["page"]; ?>,
            sort_by: '<?php echo $filter["sort_by"]; ?>',
            sort_order: '<?php echo $filter["sort_order"]; ?>',
            per_page: <?php echo $filter["per_page"]; ?>,
            search: '<?php echo addslashes($filter["search"]); ?>'
        };
        
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
        
        document.addEventListener('DOMContentLoaded', function() {
            // 初始化自定义下拉菜单
            initCustomSelect('sortByTrigger', 'sortByOptions', 'sortByContainer');
            initCustomSelect('sortOrderTrigger', 'sortOrderOptions', 'sortOrderContainer');
            initCustomSelect('perPageTrigger', 'perPageOptions', 'perPageContainer');
            
            // 搜索框回车事件
            document.getElementById('searchInput').addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    handleSearch();
                }
            });
        });
        
        // 自定义下拉菜单功能
        function initCustomSelect(triggerId, optionsId, containerId) {
            const trigger = document.getElementById(triggerId);
            const options = document.getElementById(optionsId);
            const arrow = trigger.querySelector('.select-arrow');
            const optionItems = options.querySelectorAll('.select-option');
            
            trigger.addEventListener('click', (e) => {
                e.stopPropagation();
                document.querySelectorAll('.select-options.open').forEach(el => {
                    if (el !== options) {
                        el.classList.remove('open');
                        el.closest('.custom-select-container').querySelector('.select-arrow').classList.remove('open');
                    }
                });
                options.classList.toggle('open');
                arrow.classList.toggle('open');
            });
            
            optionItems.forEach(item => {
                item.addEventListener('click', () => {
                    optionItems.forEach(i => i.classList.remove('selected'));
                    item.classList.add('selected');
                    trigger.querySelector('span').textContent = item.textContent;
                    options.classList.remove('open');
                    arrow.classList.remove('open');
                });
            });
            
            document.addEventListener('click', () => {
                options.classList.remove('open');
                arrow.classList.remove('open');
            });
        }
        
        // 筛选操作立即显示加载动画
        function changeSortBy(value) {
            showProgress();
            currentFilter.sort_by = value;
            currentFilter.page = 1;
            loadResources(1);
        }
        
        function changeSortOrder(value) {
            showProgress();
            currentFilter.sort_order = value;
            currentFilter.page = 1;
            loadResources(1);
        }
        
        function changePerPage(value) {
            showProgress();
            currentFilter.per_page = value;
            currentFilter.page = 1;
            loadResources(1);
        }
        
        function handleSearch() {
            showProgress();
            const searchValue = document.getElementById('searchInput').value.trim();
            currentFilter.search = searchValue;
            currentFilter.page = 1;
            loadResources(1);
        }
        
        function loadResources(page = 1) {
            // 如果从分页按钮调用，也显示加载动画
            if (!document.getElementById('progressContainer').classList.contains('show')) {
                showProgress();
            }
            
            currentFilter.page = page;
            
            const xhr = new XMLHttpRequest();
            xhr.open('POST', window.location.href, true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            
            xhr.onreadystatechange = function() {
                if (xhr.readyState === 4) {
                    hideProgress();
                    
                    if (xhr.status === 200) {
                        try {
                            const res = JSON.parse(xhr.responseText);
                            if (res.success) {
                                document.getElementById('resourceListContainer').innerHTML = res.html;
                                document.getElementById('pagination-top').innerHTML = res.pagination;
                                document.getElementById('pagination-bottom').innerHTML = res.pagination;
                                
                                // 重新绑定分页按钮事件
                                setTimeout(() => {
                                    bindPaginationEvents();
                                }, 100);
                            } else {
                                alert('加载资源失败：' + (res.message || '未知错误'));
                            }
                        } catch (e) {
                            alert('数据解析错误：' + e.message);
                        }
                    } else {
                        alert('加载资源失败，HTTP状态：' + xhr.status);
                    }
                }
            };
            
            const params = new URLSearchParams();
            for (const key in currentFilter) {
                params.append(key, currentFilter[key]);
            }
            params.append('page', page);
            
            xhr.send(params);
        }
        
        // 绑定分页按钮事件
        function bindPaginationEvents() {
            document.querySelectorAll('.page-btn').forEach(btn => {
                if (!btn.disabled) {
                    btn.onclick = function() {
                        const pageText = this.textContent;
                        if (pageText !== '...') {
                            if (pageText === '<' || pageText === '&lt;') {
                                loadResources(currentFilter.page - 1);
                            } else if (pageText === '>' || pageText === '&gt;') {
                                loadResources(currentFilter.page + 1);
                            } else {
                                loadResources(parseInt(pageText));
                            }
                        }
                    };
                }
            });
        }
        
        // 初始绑定分页事件
        bindPaginationEvents();
        </script>
</body>
</html>