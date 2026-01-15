<?php
session_start();
require_once 'functions.php';

// 非管理员则跳转
if (!is_admin()) {
    header("Location: index.php");
    exit;
}

// 验证资源ID
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: admin_manage_files.php");
    exit;
}
$file_id = intval($_GET['id']);

// 查询资源基础信息
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
    header("Location: admin_manage_files.php");
    exit;
}

// 查询资源已关联的MC版本和平台
$selected_mc_ids = [];
$mc_result = get_file_mc_versions($file_id);
while ($mv = $mc_result->fetch_assoc()) {
    $selected_mc_ids[] = $mv['id'];
}

$selected_platform_ids = [];
$platform_result = get_file_platforms($file_id);
while ($p = $platform_result->fetch_assoc()) {
    $selected_platform_ids[] = $p['id'];
}

$error = '';
$success = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // 接收表单数据
    $title = trim($_POST['title']);
    $brief = trim($_POST['brief']);
    $description = $_POST['description'];
    $price = intval($_POST['price']);
    $category_id = intval($_POST['category_id']);
    $mc_version_ids = isset($_POST['mc_version_ids']) ? $_POST['mc_version_ids'] : [];
    $platform_ids = isset($_POST['platform_ids']) ? $_POST['platform_ids'] : [];
    
    // 验证必填项
    if (empty($title) || empty($brief) || empty($description) || $price <= 0) {
        $error = '标题、简介、描述、价格不能为空，价格必须大于0';
    } else {
        $conn->begin_transaction();
        try {
            // 1. 更新资源基础信息
            $update_stmt = $conn->prepare("
                UPDATE files 
                SET title = ?, brief = ?, description = ?, price = ?, category_id = ?, update_time = NOW() 
                WHERE id = ?
            ");
            $update_stmt->bind_param("ssssii", $title, $brief, $description, $price, $category_id, $file_id);
            $update_stmt->execute();
            
            // 2. 清空原有MC版本关联
            $del_mc_stmt = $conn->prepare("DELETE FROM file_mc_relations WHERE file_id = ?");
            $del_mc_stmt->bind_param("i", $file_id);
            $del_mc_stmt->execute();
            
            // 3. 重新插入MC版本关联
            if (!empty($mc_version_ids)) {
                $ins_mc_stmt = $conn->prepare("INSERT INTO file_mc_relations (file_id, mc_version_id) VALUES (?, ?)");
                foreach ($mc_version_ids as $mv_id) {
                    $ins_mc_stmt->bind_param("ii", $file_id, $mv_id);
                    $ins_mc_stmt->execute();
                }
            }
            
            // 4. 清空原有平台关联
            $del_platform_stmt = $conn->prepare("DELETE FROM file_platform_relations WHERE file_id = ?");
            $del_platform_stmt->bind_param("i", $file_id);
            $del_platform_stmt->execute();
            
            // 5. 重新插入平台关联
            if (!empty($platform_ids)) {
                $ins_platform_stmt = $conn->prepare("INSERT INTO file_platform_relations (file_id, platform_id) VALUES (?, ?)");
                foreach ($platform_ids as $p_id) {
                    $ins_platform_stmt->bind_param("ii", $file_id, $p_id);
                    $ins_platform_stmt->execute();
                }
            }
            
            $conn->commit();
            $success = '资源编辑成功！';
            
            // 刷新数据
            $stmt->execute();
            $file = $stmt->get_result()->fetch_assoc();
            $mc_result = get_file_mc_versions($file_id);
            $selected_mc_ids = [];
            while ($mv = $mc_result->fetch_assoc()) {
                $selected_mc_ids[] = $mv['id'];
            }
            $platform_result = get_file_platforms($file_id);
            $selected_platform_ids = [];
            while ($p = $platform_result->fetch_assoc()) {
                $selected_platform_ids[] = $p['id'];
            }
        } catch (Exception $e) {
            $conn->rollback();
            $error = '编辑失败：' . $e->getMessage();
        }
    }
}

// 获取下拉列表数据
$categories = get_all_categories();
$all_mc_versions = get_all_mc_versions();
$all_platforms = get_all_platforms();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>编辑资源</title>
    <style>
        .form-group { margin: 10px 0; }
        .multi-select { height: 100px; width: 300px; }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/wangeditor@4/dist/wangEditor.min.js"></script>
</head>
<body>
    <h1>编辑资源：<?php echo $file['title']; ?></h1>
    <?php if ($error): ?><p style="color: red;"><?php echo $error; ?></p><?php endif; ?>
    <?php if ($success): ?><p style="color: green;"><?php echo $success; ?></p><?php endif; ?>
    
    <form method="post" id="editForm">
        <div class="form-group">
            <label>标题:</label>
            <input type="text" name="title" value="<?php echo $file['title']; ?>" required>
        </div>
        
        <div class="form-group">
            <label>资源分类:</label>
            <select name="category_id">
                <option value="">未分类</option>
                <?php while ($cat = $categories->fetch_assoc()): ?>
                    <option value="<?php echo $cat['id']; ?>" <?php echo $file['category_id'] == $cat['id'] ? 'selected' : ''; ?>>
                        <?php echo $cat['name']; ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </div>
        
        <div class="form-group">
            <label>适配MC版本 (可多选):</label>
            <select name="mc_version_ids[]" class="multi-select" multiple>
                <?php 
                $java_versions = get_all_mc_versions('java');
                $bedrock_versions = get_all_mc_versions('bedrock');
                ?>
                <optgroup label="Java版">
                    <?php while ($mv = $java_versions->fetch_assoc()): ?>
                        <option value="<?php echo $mv['id']; ?>" <?php echo in_array($mv['id'], $selected_mc_ids) ? 'selected' : ''; ?>>
                            <?php echo $mv['name']; ?>
                        </option>
                    <?php endwhile; ?>
                </optgroup>
                <optgroup label="基岩版">
                    <?php while ($mv = $bedrock_versions->fetch_assoc()): ?>
                        <option value="<?php echo $mv['id']; ?>" <?php echo in_array($mv['id'], $selected_mc_ids) ? 'selected' : ''; ?>>
                            <?php echo $mv['name']; ?>
                        </option>
                    <?php endwhile; ?>
                </optgroup>
            </select>
        </div>
        
        <div class="form-group">
            <label>适配运行平台 (可多选):</label>
            <select name="platform_ids[]" class="multi-select" multiple>
                <?php while ($plat = $all_platforms->fetch_assoc()): ?>
                    <option value="<?php echo $plat['id']; ?>" <?php echo in_array($plat['id'], $selected_platform_ids) ? 'selected' : ''; ?>>
                        <?php echo $plat['name']; ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </div>
        
        <div class="form-group">
            <label>简介:</label>
            <input type="text" name="brief" value="<?php echo $file['brief']; ?>" required>
        </div>
        
        <div class="form-group">
            <label>描述:</label>
            <div id="editor-container"><?php echo $file['description']; ?></div>
            <textarea name="description" id="description" style="display: none;" required><?php echo $file['description']; ?></textarea>
        </div>
        
        <div class="form-group">
            <label>价格(积分):</label>
            <input type="number" name="price" value="<?php echo $file['price']; ?>" min="1" required>
        </div>
        
        <div class="form-group">
            <button type="submit">保存修改</button>
            <a href="admin_manage_files.php">返回资源管理</a>
        </div>
    </form>

    <script>
        // 初始化富文本编辑器
        const E = window.wangEditor
        const editor = new E('#editor-container')
        const $text1 = document.getElementById('description')
        editor.config.onchange = function (html) {
            $text1.value = html
        }
        editor.create()
        
        // 提交时同步编辑器内容
        document.getElementById('editForm').addEventListener('submit', function () {
            $text1.value = editor.txt.html()
        })
    </script>
</body>
</html>