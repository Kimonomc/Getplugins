<?php
session_start();
require_once 'functions.php';

// 关键改动1：开启输出缓冲并清空，避免意外输出破坏文件流
ob_start();
ob_clean();

// 未登录则跳转
if (!is_logged_in()) {
    header("Location: login.php");
    exit;
}

// 验证版本ID
if (!isset($_GET['version_id']) || !is_numeric($_GET['version_id'])) {
    header("Location: index.php");
    exit;
}
$version_id=intval($_GET['version_id']);

// 查询版本信息及关联资源ID
$stmt = $conn->prepare("SELECT v.*, f.id as file_id FROM file_versions v LEFT JOIN files f ON v.file_id = f.id WHERE v.id = ?");
$stmt->bind_param("i", $version_id);
$stmt->execute();
$version = $stmt->get_result()->fetch_assoc();

if (!$version || !file_exists($version['file_path'])) {
    die("文件版本不存在");
}

// 检查该资源的购买权限
if (!has_purchase_access($_SESSION['user_id'], $version['file_id'])) {
    die("您没有该文件的下载权限，或权限已过期");
}

// 增加下载量
increment_download_count($version['file_id']);

// ========== 主要改动开始（修复压缩包损坏） ==========
// 1. 获取文件相关信息
$file_path = $version['file_path'];
$file_name = $version['filename']; // 使用数据库中存储的原始文件名
$file_ext=strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
$username = $_SESSION['username']; // 从session获取登录用户名
$download_time=date('Y-m-d H:i:s'); // 获取当前下载时间

// 2. 定义压缩包注释内容（替换占位符）
$comment_template = "   _____      _   _____  _             _        __   ____     ________
  / ____|    | | |  __ \| |           (_)       \ \ / /\ \   / /___  /
 | |  __  ___| |_| |__) | |_   _  __ _ _ _ __    \ V /  \ \_/ /   / / 
 | | |_ |/ _ \ __|  ___/| | | | |/ _` | | '_ \    > <    \   /   / /  
 | |__| |  __/ |_| |    | | |_| | (_| | | | | |_ / . \    | |   / /__ 
  \_____|\___|\__|_|    |_|\__,_|\__, |_|_| |_(_)_/ \_\   |_|  /_____|
                                  __/ |                               
                                 |___/                                


======================================================================
下载项目：{文件名}
下载用户：{用户名}
下载时间：{0000-00-00 00:00}

使用须知：
① 本站所有资源均由热心网友上传，平台不对资源的真实性、合法性等持认可
   态度，请理性判断是否保留资源。
② 您从本网站下载的所有内容均不受中国法律保护。
③ 本资源仅供学习与鉴赏，请自下载之日起24小时内删除。

更多资源请访问 http://getplugins.xyz 获取
======================================================================";

// 替换占位符为实际值
$comment=str_replace(
    ['{文件名}', '{用户名}', '{0000-00-00 00:00}'],
    [$file_name, $username, $download_time],
    $comment_template
);

// 3. 处理jar/zip文件的注释添加（修复核心：修改ZipArchive打开模式）
$temp_file=null;
$use_temp=false;
if (in_array($file_ext, ['jar', 'zip'])) {
    $use_temp=true;
    // 生成临时文件路径，避免文件名冲突
    $temp_dir=sys_get_temp_dir();
    $temp_file = $temp_dir . DIRECTORY_SEPARATOR . uniqid('dl_', true) . '.' . $file_ext;
    
    // 关键改动2：检查文件复制是否成功
    if (!copy($file_path, $temp_file)) {
        $use_temp=false;
        error_log("下载临时文件复制失败: $file_path -> $temp_file");
    } else {
        $zip=new ZipArchive();
        // 关键改动3：使用 ZipArchive::CHECKCONS | ZipArchive::OVERWRITE 而非 CREATE+OVERWRITE；仅打开已存在的文件
        $zip_open_result = $zip->open($temp_file, ZipArchive::CHECKCONS);
        if ($zip_open_result === true) {
            $zip->setArchiveComment($comment);
            // 关键改动4：必须调用 close() 才能写入磁盘
            $zip->close();
        } else {
            // 失败回退，删除无效临时文件
            $use_temp=false;
            unlink($temp_file);
            $temp_file=null;
            error_log("Zip注释添加失败，错误码: $zip_open_result, 文件: $temp_file");
        }
    }
}

// 4. 确定最终下载文件路径
$download_file = $use_temp && $temp_file && file_exists($temp_file) ? $temp_file : $file_path;
// ========== 主要改动结束 ==========

// 执行下载：必须确保header前无任何输出
header('Content-Description: File Transfer');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="'.$file_name.'"');
header('Expires: 0');
header('Cache-Control: must-revalidate');
header('Pragma: public');
header('Content-Length: ' . filesize($download_file));

// 关键改动5：清空输出缓冲区，避免header前有残留输出
ob_end_clean();
flush();

// 输出文件内容（优先用readfile，效率更高）
readfile($download_file);

// 关键改动6：下载完成后，立即删除临时文件
if ($use_temp && $temp_file && file_exists($temp_file)) {
    unlink($temp_file);
}

// 终止脚本，避免后续输出污染文件流
exit(0);
?>