<?php
// 数据库配置
$host = 'DB_IP';
$dbname = 'DB_NAME';
$username = 'DB_USER';
$password = 'DB_PASSWORD';

// 创建连接
$conn = new mysqli($host, $username, $password, $dbname);

// 检查连接
if ($conn->connect_error) {
    die("数据库连接失败: " . $conn->connect_error);
}

// 设置字符集
$conn->set_charset("utf8mb4");

// 1. 用户表（不变）
$create_users_table = "
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    points INT DEFAULT 0,
    is_admin TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
$conn->query($create_users_table);

// 2. CDK表（不变）
$create_cdks_table = "
CREATE TABLE IF NOT EXISTS cdks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cdk VARCHAR(50) NOT NULL UNIQUE,
    points INT NOT NULL,
    used TINYINT(1) DEFAULT 0,
    used_by INT NULL,
    used_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (used_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
$conn->query($create_cdks_table);

// 3. 积分记录表（不变）
$create_point_records_table = "
CREATE TABLE IF NOT EXISTS point_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type ENUM('recharge', 'consume', 'admin_modify') NOT NULL,
    points INT NOT NULL,
    remark VARCHAR(255) DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
$conn->query($create_point_records_table);

// 4. 资源分类表（不变）
$create_categories_table = "
CREATE TABLE IF NOT EXISTS categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
$conn->query($create_categories_table);

// 5. Minecraft版本表（修改：增加层级、手动创建）
$create_mc_versions_table = "
CREATE TABLE IF NOT EXISTS mc_versions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    parent_type ENUM('java', 'bedrock') NOT NULL COMMENT 'java=Java版, bedrock=基岩版',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
$conn->query($create_mc_versions_table);

// 6. 运行平台表（修改：手动创建）
$create_platforms_table = "
CREATE TABLE IF NOT EXISTS platforms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
$conn->query($create_platforms_table);

// 7. 资源主表（不变）
$create_files_table = "
CREATE TABLE IF NOT EXISTS files (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(100) NOT NULL,
    icon VARCHAR(255) NOT NULL,
    brief VARCHAR(255) NOT NULL,
    description LONGTEXT NOT NULL,
    price INT NOT NULL,
    category_id INT NULL,
    download_count INT DEFAULT 0,
    update_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
$conn->query($create_files_table);

// 8. 资源版本表（不变）
$create_file_versions_table = "
CREATE TABLE IF NOT EXISTS file_versions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    file_id INT NOT NULL,
    version VARCHAR(50) NOT NULL,
    filename VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    is_latest TINYINT(1) DEFAULT 0,
    upload_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
$conn->query($create_file_versions_table);

// 9. 资源-MC版本 多对多关联表（新增）
$create_file_mc_rel_table = "
CREATE TABLE IF NOT EXISTS file_mc_relations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    file_id INT NOT NULL,
    mc_version_id INT NOT NULL,
    UNIQUE KEY unique_file_mc (file_id, mc_version_id),
    FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE,
    FOREIGN KEY (mc_version_id) REFERENCES mc_versions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
$conn->query($create_file_mc_rel_table);

// 10. 资源-运行平台 多对多关联表（新增）
$create_file_platform_rel_table = "
CREATE TABLE IF NOT EXISTS file_platform_relations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    file_id INT NOT NULL,
    platform_id INT NOT NULL,
    UNIQUE KEY unique_file_platform (file_id, platform_id),
    FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE,
    FOREIGN KEY (platform_id) REFERENCES platforms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
$conn->query($create_file_platform_rel_table);

// 11. 用户购买记录表（不变）
$create_purchase_table = "
CREATE TABLE IF NOT EXISTS purchases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    file_id INT NOT NULL,
    purchase_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expire_time TIMESTAMP NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
$conn->query($create_purchase_table);

// 12. 点赞记录表（不变）
$create_likes_table = "
CREATE TABLE IF NOT EXISTS likes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    file_id INT NOT NULL,
    user_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_like (file_id, user_id),
    FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
$conn->query($create_likes_table);

// 初始化管理员账号（admin/admin123，首次登录后请修改密码）
$admin_check = $conn->query("SELECT id FROM users WHERE username = 'admin'");
if ($admin_check->num_rows == 0) {
    $hashed_pwd = password_hash('admin123', PASSWORD_DEFAULT);
    $conn->query("INSERT INTO users (username, password, is_admin) VALUES ('admin', '$hashed_pwd', 1)");
}
?>