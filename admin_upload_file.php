<?php
session_start();
require_once 'functions.php';

// 非管理员则跳转
if (!is_admin()) {
    header("Location: index.php");
    exit;
}

// 处理富文本编辑器的图片上传（单独接口）
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'upload_image') {
    ob_clean();
    $title = trim($_POST['title']);
    if (empty($title)) {
        echo json_encode([
            'errno' => 1,
            'message' => '请先填写资源标题再上传图片'
        ]);
        exit;
    }
    $target_dir = 'uploads/' . $title . '/images/';
    if (!is_dir($target_dir)) {
        mkdir($target_dir, 0777, true);
    }
    $file = $_FILES['file'];
    $file_ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    if (!in_array(strtolower($file_ext), $allowed_ext)) {
        echo json_encode([
            'errno' => 1,
            'message' => '仅支持jpg/png/gif/webp格式图片'
        ]);
        exit;
    }
    $new_filename = uniqid() . '.' . $file_ext;
    $target_path = $target_dir . $new_filename;
    if (move_uploaded_file($file['tmp_name'], $target_path)) {
        echo json_encode([
            'errno' => 0,
            'data' => [
                '/' . $target_path
            ]
        ]);
    } else {
        echo json_encode([
            'errno' => 1,
            'message' => '图片上传失败'
        ]);
    }
    exit;
}

$error = '';
$success = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !isset($_POST['action'])) {
    // 验证表单数据
    $title = trim($_POST['title']);
    $brief = trim($_POST['brief']);
    $description = $_POST['description'];
    $price = intval($_POST['price']);
    $version = trim($_POST['version']);
    $category_id = intval($_POST['category_id']);
    $mc_version_ids = isset($_POST['mc_version_ids']) ? $_POST['mc_version_ids'] : [];
    $platform_ids = isset($_POST['platform_ids']) ? $_POST['platform_ids'] : [];
    
    // 验证必填项
    $required_empty = empty($title) || empty($brief) || empty($description) || $price <= 0 || empty($version) || empty($mc_version_ids) || empty($platform_ids);
    if ($required_empty) {
        $error = '标题、简介、描述、价格、版本号、MC版本、运行平台不能为空';
    } else {
        $file_error = $_FILES['file']['error'];
        $icon_error = $_FILES['icon']['error'];
        if ($file_error != UPLOAD_ERR_OK) {
            $error = '资源文件上传失败';
        } elseif ($icon_error != UPLOAD_ERR_OK) {
            $error = '图标文件上传失败';
        } else {
            // 处理文件上传
            $target_dir = 'uploads/' . $title . '/' . $version;
            $file_path = upload_file($_FILES['file'], $target_dir);
            $icon_path = upload_file($_FILES['icon'], 'uploads/' . $title);
            
            if ($file_path && $icon_path) {
                $conn->begin_transaction();
                try {
                    // 1. 插入资源主表
                    $stmt1 = $conn->prepare("INSERT INTO files (title, icon, brief, description, price, category_id) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt1->bind_param("ssssii", $title, $icon_path, $brief, $description, $price, $category_id);
                    $stmt1->execute();
                    $file_id = $conn->insert_id;
                    
                    // 2. 插入资源版本表
                    $filename = basename($_FILES['file']['name']);
                    $stmt2 = $conn->prepare("INSERT INTO file_versions (file_id, version, filename, file_path, is_latest) VALUES (?, ?, ?, ?, 1)");
                    $stmt2->bind_param("isss", $file_id, $version, $filename, $file_path);
                    $stmt2->execute();
                    
                    // 3. 插入MC版本多对多关联
                    $stmt3 = $conn->prepare("INSERT IGNORE INTO file_mc_relations (file_id, mc_version_id) VALUES (?, ?)");
                    foreach ($mc_version_ids as $mv_id) {
                        $stmt3->bind_param("ii", $file_id, $mv_id);
                        $stmt3->execute();
                    }
                    
                    // 4. 插入运行平台多对多关联
                    $stmt4 = $conn->prepare("INSERT IGNORE INTO file_platform_relations (file_id, platform_id) VALUES (?, ?)");
                    foreach ($platform_ids as $p_id) {
                        $stmt4->bind_param("ii", $file_id, $p_id);
                        $stmt4->execute();
                    }
                    
                    $conn->commit();
                    $success = '资源及版本上传成功！<a href="file_detail.php?id=' . $file_id . '">查看资源详情</a>';
                } catch (Exception $e) {
                    $conn->rollback();
                    unlink($file_path);
                    unlink($icon_path);
                    $error = '上传失败: ' . $e->getMessage();
                }
            } else {
                $error = '文件保存失败';
            }
        }
    }
}

// 获取下拉列表数据
$categories = get_all_categories();
$mc_versions = get_all_mc_versions();
$platforms = get_all_platforms();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>上传新资源</title>
    <script src="https://cdn.jsdelivr.net/npm/wangeditor@4/dist/wangEditor.min.js"></script>
    <style>
        #editor-container {
            border: 1px solid #ccc;
            z-index: 100;
            margin-top: 10px;
        }
        .form-group {
            margin: 10px 0;
        }
        .multi-select {
            height: 100px;
            width: 300px;
        }
    </style>
</head>
<body>
    <h1>上传新资源</h1>
    <?php if ($error): ?>
        <p style="color: red;"><?php echo $error; ?></p>
    <?php endif; ?>
    <?php if ($success): ?>
        <p style="color: green;"><?php echo $success; ?></p>
    <?php endif; ?>
    
    <form method="post" enctype="multipart/form-data" id="uploadForm">
        <div class="form-group">
            <label>标题:</label>
            <input type="text" name="title" id="title" required>
            <p style="color:#999;">提示：请先填写标题，再在描述中上传/粘贴图片</p>
        </div>
        
        <div class="form-group">
            <label>资源版本号:</label>
            <input type="text" name="version" required placeholder="如2.17.0">
        </div>
        
        <div class="form-group">
            <label>资源分类:</label>
            <select name="category_id" required>
                <option value="">请选择分类</option>
                <?php while ($cat = $categories->fetch_assoc()): ?>
                    <option value="<?php echo $cat['id']; ?>"><?php echo $cat['name']; ?></option>
                <?php endwhile; ?>
            </select>
            <p style="color:#999;">无分类请先<a href="admin_create_category.php">创建分类</a></p>
        </div>
        
        <div class="form-group">
            <label>适配MC版本 (可多选):</label>
            <select name="mc_version_ids[]" class="multi-select" multiple required>
                <?php 
                $java_versions = get_all_mc_versions('java');
                $bedrock_versions = get_all_mc_versions('bedrock');
                ?>
                <optgroup label="Java版">
                    <?php while ($mv = $java_versions->fetch_assoc()): ?>
                        <option value="<?php echo $mv['id']; ?>"><?php echo $mv['name']; ?></option>
                    <?php endwhile; ?>
                </optgroup>
                <optgroup label="基岩版">
                    <?php while ($mv = $bedrock_versions->fetch_assoc()): ?>
                        <option value="<?php echo $mv['id']; ?>"><?php echo $mv['name']; ?></option>
                    <?php endwhile; ?>
                </optgroup>
            </select>
            <p style="color:#999;">无版本请先<a href="admin_create_mc_version.php">创建MC版本</a></p>
        </div>
        
        <div class="form-group">
            <label>适配运行平台 (可多选):</label>
            <select name="platform_ids[]" class="multi-select" multiple required>
                <?php while ($plat = $platforms->fetch_assoc()): ?>
                    <option value="<?php echo $plat['id']; ?>"><?php echo $plat['name']; ?></option>
                <?php endwhile; ?>
            </select>
            <p style="color:#999;">无平台请先<a href="admin_create_platform.php">创建运行平台</a></p>
        </div>
        
        <div class="form-group">
            <label>简介:</label>
            <input type="text" name="brief" required>
        </div>
        
        <div class="form-group">
            <label>描述(支持粘贴图片、字体格式):</label>
            <div id="editor-container"></div>
            <textarea name="description" id="description" style="display: none;" required></textarea>
        </div>
        
        <div class="form-group">
            <label>价格(积分):</label>
            <input type="number" name="price" min="1" required>
        </div>
        
        <div class="form-group">
            <label>资源文件:</label>
            <input type="file" name="file" required>
        </div>
        
        <div class="form-group">
            <label>图标文件:</label>
            <input type="file" name="icon" required>
        </div>
        
        <div class="form-group">
            <button type="submit">上传</button>
        </div>
    </form>

    <script>
        const E = window.wangEditor
        const editor = new E('#editor-container')
        const $text1 = document.getElementById('description')

        editor.config.uploadImgServer = 'admin_upload_file.php'
        editor.config.uploadImgParams = { action: 'upload_image' }
        editor.config.uploadImgParamsWithUrl = true
        editor.config.beforeUploadImg = function (files) {
            const title = document.getElementById('title').value.trim()
            if (!title) {
                alert('请先填写资源标题，再上传/粘贴图片！')
                return false
            }
            this.uploadImgParams.title = title
            return true
        }
        editor.config.uploadImgAccept = ['jpg', 'jpeg', 'png', 'gif', 'webp']
        editor.config.pasteFilterStyle = false
        editor.config.pasteIgnoreImg = false
        editor.config.uploadImgMaxSize = 5 * 1024 * 1024
        editor.config.uploadFileName = 'file'

        editor.config.onchange = function (html) {
            $text1.value = html
        }

        editor.create()

        document.getElementById('uploadForm').addEventListener('submit', function () {
            $text1.value = editor.txt.html()
        })
    </script>

    <p><a href="admin_panel.php">返回管理员面板</a></p>
    <p><a href="index.php">返回首页</a></p>
</body>
</html>