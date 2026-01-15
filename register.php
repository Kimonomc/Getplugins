<?php
session_start();
require_once 'functions.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // 新增：验证hCaptcha token（关键改动1：增加验证码验证）
    $captcha_token = isset($_POST['h-captcha-response']) ? $_POST['h-captcha-response'] : '';
    if (empty($captcha_token)) {
        $error = '请完成人机验证';
    } else {
        // 验证hCaptcha（使用和登录页相同的Secret Key）
        $secret_key = "your-secret-key"; // 替换为你的Secret Key
        $verify_url = "https://hcaptcha.com/siteverify";
        $data = [
            'secret' => $secret_key,
            'response' => $captcha_token,
            'remoteip' => $_SERVER['REMOTE_ADDR']
        ];
        
        // 使用curl发送请求（禁用SSL验证，和登录页保持一致）
        $ch = curl_init($verify_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // 临时禁用SSL验证
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        $response = curl_exec($ch);
        $curl_error = curl_error($ch);
        curl_close($ch);

        // 处理hCaptcha验证结果
        if (!empty($curl_error)) {
            $error = '验证服务连接失败：' . $curl_error;
        } else {
            $result = json_decode($response);
            if (!$result || !$result->success) {
                $error_codes = isset($result->{'error-codes'}) ? implode(',', $result->{'error-codes'}) : '未知错误';
                $error = '人机验证失败：' . $error_codes;
            } else {
                // 原有注册逻辑
                $username = trim($_POST['username']);
                $password = trim($_POST['password']);
                $confirm_pwd = trim($_POST['confirm_pwd']);
                
                // 验证输入
                if (empty($username) || empty($password) || empty($confirm_pwd)) {
                    $error = '所有字段不能为空';
                } elseif (strlen($username) < 3 || strlen($username) > 20) {
                    $error = '用户名长度应在3-20个字符之间';
                } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
                    $error = '用户名只能包含字母、数字和下划线';
                } elseif (strlen($password) < 8) {
                    $error = '密码长度至少8个字符';
                } elseif (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).+$/', $password)) {
                    $error = '密码必须包含大小写字母和数字';
                } elseif ($password != $confirm_pwd) {
                    $error = '两次密码不一致';
                } else {
                    // 检查用户名是否存在
                    $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
                    $stmt->bind_param("s", $username);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    if ($result->num_rows > 0) {
                        $error = '用户名已存在';
                    } else {
                        // 插入用户
                        $hashed_pwd = password_hash($password, PASSWORD_DEFAULT);
                        $stmt = $conn->prepare("INSERT INTO users (username, password) VALUES (?, ?)");
                        $stmt->bind_param("ss", $username, $hashed_pwd);
                        if ($stmt->execute()) {
                            header("Location: login.php?msg=注册成功，请登录");
                            exit;
                        } else {
                            $error = '注册失败，请重试';
                        }
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GetPlugins.XYZ - 创建新账户</title>
    <style>
        /* 复用登录页的样式，保持界面统一 */
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: "Microsoft Yahei", "PingFang SC", Arial, sans-serif;
        }
        body {
            background-color: #ebebeb;
            padding: 40px 20px;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-card { /* 保留login-card类名，保持样式统一 */
            background-color: #f8f8f8;
            max-width: 450px;
            width: 100%;
            margin: 0 auto;
            padding: 40px 35px;
            border-radius: 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }
        .login-card h2 {
            margin-bottom: 30px;
            font-size: 28px;
            color: #333;
            text-align: center;
            font-weight: 600;
        }
        /* 提示信息样式 */
        .message {
            text-align: center;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .error-msg {
            background-color: #ffebee;
            color: #c62828;
        }
        .input-group {
            margin-bottom: 25px;
            position: relative;
        }
        .input-group input {
            width: 100%;
            padding: 16px 20px 16px 45px;
            border: 1px solid #e0e0e0;
            border-radius: 12px;
            font-size: 16px;
            color: #333;
            outline: none;
            transition: border-color 0.3s;
        }
        .input-group input:focus {
            border-color: #00af5c;
        }
        .input-group input::placeholder {
            color: #b0b0b0;
            font-size: 16px;
        }
        .input-group .icon-svg {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #888;
            width: 20px;
            height: 20px;
            flex-shrink: 0;
        }
        .h-captcha {
            margin-bottom: 25px;
            transform: scale(1);
            transform-origin: left top;
            display: flex;
            justify-content: center;
        }
        .btn-container {
            display: flex;
            justify-content: center;
            margin-bottom: 25px;
        }
        .signup-btn { /* 注册按钮样式，和登录按钮保持一致 */
            width: 200px;
            padding: 14px;
            background-color: #00af5c;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            color: #fff;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            font-weight: 500;
            transition: background-color 0.3s;
            border: none;
            outline: none;
        }
        .signup-btn:disabled {
            background-color: #9cc7b3;
            cursor: not-allowed;
        }
        .signup-btn:not(:disabled):hover {
            background-color: #00964e;
        }
        .signup-btn svg {
            width: 18px;
            height: 18px;
            flex-shrink: 0;
        }
        .links {
            margin-top: 0;
            text-align: center;
            font-size: 16px;
        }
        .links a {
            color: #00af5c;
            text-decoration: none;
            margin: 0 10px;
        }
        .links a:hover {
            text-decoration: underline;
        }
        .links span {
            margin: 0 8px;
            color: #999;
        }
    </style>
    <!-- 引入 hCaptcha 官方 JS 库 -->
    <script src="https://js.hcaptcha.com/1/api.js" async defer></script>
    <!-- 引入必要的外部资源 -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <!-- ========== 页眉、页脚、加载动画相关CSS ========== -->
    <style>
        /* 基础样式重置 */
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: #1a202c;
            margin: 0;
            padding: 0;
            background-color: #ebebeb;
            display: block;
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
    <!-- ========== 页眉组件 ========== -->
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

    <!-- ========== 加载动画组件（放在主要内容区域顶部） ========== -->
    <div class="progress-container" id="progressContainer">
        <div class="progress-bar" id="progressBar"></div>
    </div>
    <br><br>
    <div class="login-card">
        <h2>使用以下方式创建新账户</h2>
        
        <!-- 关键改动2：美化错误提示信息 -->
        <?php if ($error): ?>
            <div class="message error-msg"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <!-- 关键改动3：重构表单，适配样式 -->
        <form method="post" id="signupForm">
            <div class="input-group">
                <svg class="icon-svg" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" class="lucide lucide-mail" viewBox="0 0 24 24"><rect width="20" height="16" x="2" y="4" rx="2"></rect><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"></path></svg>
                <input type="text" placeholder="使用邮箱或用户名注册" name="username" id="username" value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
            </div>
            <div class="input-group">
                <svg class="icon-svg" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" class="icon icon-tabler icon-tabler-key" viewBox="0 0 24 24"><path stroke="none" d="M0 0h24v24H0z"></path><path d="m16.555 3.843 3.602 3.602a2.877 2.877 0 0 1 0 4.069l-2.643 2.643a2.877 2.877 0 0 1-4.069 0l-.301-.301-6.558 6.558a2 2 0 0 1-1.239.578L5.172 21H4a1 1 0 0 1-.993-.883L3 20v-1.172a2 2 0 0 1 .467-1.284l.119-.13L4 17h2v-2h2v-2l2.144-2.144-.301-.301a2.877 2.877 0 0 1 0-4.069l2.643-2.643a2.877 2.877 0 0 1 4.069 0M15 9h.01"></path></svg>
                <input type="password" placeholder="请设置密码" name="password" id="password">
            </div>
            <div class="input-group">
                <svg class="icon-svg" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" class="icon icon-tabler icon-tabler-key" viewBox="0 0 24 24"><path stroke="none" d="M0 0h24v24H0z"></path><path d="m16.555 3.843 3.602 3.602a2.877 2.877 0 0 1 0 4.069l-2.643 2.643a2.877 2.877 0 0 1-4.069 0l-.301-.301-6.558 6.558a2 2 0 0 1-1.239.578L5.172 21H4a1 1 0 0 1-.993-.883L3 20v-1.172a2 2 0 0 1 .467-1.284l.119-.13L4 17h2v-2h2v-2l2.144-2.144-.301-.301a2.877 2.877 0 0 1 0-4.069l2.643-2.643a2.877 2.877 0 0 1 4.069 0M15 9h.01"></path></svg>
                <input type="password" placeholder="请确认密码" name="confirm_pwd" id="confirm_pwd">
            </div>
            
            <!-- 关键改动4：集成hCaptcha验证 -->
            <div class="h-captcha" 
                 data-sitekey="a8d0c886-fb4e-4ebe-9572-5186ea3408f4"
                 data-callback="onCaptchaSuccess"
                 data-size="normal">
            </div>

            <div class="btn-container">
                <button type="submit" class="signup-btn" id="signupBtn" disabled>
                    注册 
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewBox="0 0 24 24">
                        <path d="M5 12h14M12 5l7 7-7 7"></path>
                    </svg>
                </button>
            </div>
        </form>
        
        <!-- 关键改动5：美化链接区域 -->
        <div class="links">
            <a href="login.php">已有账号？登录</a>
            <span>•</span>
            <a href="index.php">返回首页</a>
        </div>
    </div>
    <br><br>
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
        // hCaptcha 验证成功的回调函数
        function onCaptchaSuccess(token) {
            document.getElementById('signupBtn').disabled = false;
            console.log("hCaptcha 验证成功，token：", token);
        }

        // 表单提交前的基础验证
        document.getElementById('signupForm').addEventListener('submit', function(e) {
            const username = document.getElementById('username').value.trim();
            const password = document.getElementById('password').value.trim();
            const confirm_pwd = document.getElementById('confirm_pwd').value.trim();
            
            if (!username || !password || !confirm_pwd) {
                e.preventDefault(); // 阻止表单提交
                alert("请填写所有必填字段！");
                return false;
            }
            
            if (password !== confirm_pwd) {
                e.preventDefault();
                alert("两次输入的密码不一致！");
                return false;
            }
        });
    </script>
</body>
</html>