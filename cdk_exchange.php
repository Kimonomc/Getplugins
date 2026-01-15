<?php
session_start();
require_once 'functions.php';

// 未登录则跳转
if (!is_logged_in()) {
    header("Location: login.php");
    exit;
}

$error = '';
$success = '';
$added_points = 0; // 记录本次增加的积分，用于前端动画
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $cdk = trim($_POST['cdk']);
    if (empty($cdk)) {
        $error = '积分序列号不能为空';
    } else {
        // ========== 关键改动：不再清理CDK格式，直接使用原始输入 ==========
        $raw_cdk = $cdk; // 直接使用用户输入的原始内容
        
        // 直接使用原始CDK查询数据库
        $stmt = $conn->prepare("SELECT id, points, used FROM cdks WHERE cdk = ?");
        $stmt->bind_param("s", $raw_cdk);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows == 1) {
            $cdk_data = $result->fetch_assoc();
            if ($cdk_data['used'] == 1) {
                $error = '该积分序列号已被使用';
            } else {
                // 兑换CDK
                $user_id = $_SESSION['user_id'];
                $points = $cdk_data['points'];
                $added_points = $points; // 记录增加的积分
                
                // 开启事务
                $conn->begin_transaction();
                try {
                    // 更新CDK状态
                    $stmt1 = $conn->prepare("UPDATE cdks SET used = 1, used_by = ?, used_at = NOW() WHERE id = ?");
                    $stmt1->bind_param("ii", $user_id, $cdk_data['id']);
                    $stmt1->execute();
                    
                    // 更新用户积分
                    $stmt2 = $conn->prepare("UPDATE users SET points = points + ? WHERE id = ?");
                    $stmt2->bind_param("ii", $points, $user_id);
                    $stmt2->execute();
                    
                    // 记录积分变动
                    $remark = "积分序列号兑换: $raw_cdk";
                    $stmt3 = $conn->prepare("INSERT INTO point_records (user_id, type, points, remark) VALUES (?, 'recharge', ?, ?)");
                    $stmt3->bind_param("iis", $user_id, $points, $remark);
                    $stmt3->execute();
                    
                    // 提交事务
                    $conn->commit();
                    
                    // 更新session中的积分
                    $_SESSION['points'] += $points;
                    $success = "兑换成功！获得 $points 积分";
                } catch (Exception $e) {
                    // 回滚事务
                    $conn->rollback();
                    $error = '兑换失败: ' . $e->getMessage();
                }
            }
        } else {
            $error = '积分序列号不存在';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>为你的账户添加积分序列号 - GetPlugins.XYZ</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        /* 原有基础样式保留 */
        body {
            background-color: #ebebeb;
        }
        
        .header-fixed {
            position: static;
            width: 100%;
        }
        
        .footer-container {
            background-color: #d3eadb;
            width: 100%;
            color: #2c2e31;
            box-sizing: border-box;
        }
        
        @media (min-width: 768px) {
            .footer-container {
                max-height: 350px;
                background-color: #d3eadb;
            }
        }
        
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

        /* 积分增加动画样式 */
        .points-number {
            position: relative;
            display: inline-block;
        }
        .points-badge {
            position: absolute;
            top: -20px;
            right: -20px;
            background-color: #00af5c;
            color: white;
            border-radius: 50%;
            padding: 5px 10px;
            font-size: 14px;
            font-weight: bold;
            animation: popUp 0.5s ease-out forwards, fadeOut 1s 1s ease-out forwards;
            opacity: 0;
            transform: scale(0);
        }
        @keyframes popUp {
            0% { opacity: 0; transform: scale(0); }
            50% { opacity: 1; transform: scale(1.2); }
            100% { opacity: 1; transform: scale(1); }
        }
        @keyframes fadeOut {
            0% { opacity: 1; transform: translateY(0); }
            100% { opacity: 0; transform: translateY(-20px); }
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

        .content-footer-divider {
            width: 100%;
            height: 1px;
            background-color: #b7cebe;
            margin: 0 auto;
            max-width: 5xl;
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
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: #1a202c;
            margin: 0;
            padding: 0;
            background-color: #ebebeb;
            display: block;
        }
        
        .main-container {
            display: flex;
            gap: 16px;
            align-items: flex-start;
            max-width: 100%;
            width: 100%;
            padding: 0;
        }
        
        .main-cards-column {
            display: flex;
            flex-direction: column;
            gap: 0;
            width: 100%;
        }

        /* CDK兑换页面专用样式 */
        .cdk-container {
            max-width: 95%;
            width: 500px;
            margin: 0 auto;
            background: #f8f8f8;
            border-radius: 20px;
            padding: 40px 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            margin-top: 30px;
            margin-bottom: 30px;
            box-sizing: border-box;
        }
        
        .cdk-title {
            text-align: center;
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 30px;
            color: #2c2e31;
        }
        
        .cdk-message {
            text-align: center;
            padding: 12px 16px;
            border-radius: 16px;
            margin-bottom: 25px;
            font-size: 1.1rem;
        }
        
        .cdk-error {
            background-color: #fee2e2;
            color: #dc2626;
        }
        
        .cdk-success {
            background-color: #dcfce7;
            color: #16a34a;
        }
        
        /* 单个完整CDK输入框样式 */
        .cdk-input-single {
            width: 100%;
            max-width: 400px;
            height: 60px;
            text-align: center;
            font-size: 1.2rem;
            font-weight: 600;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            background: white;
            letter-spacing: 1px;
            transition: all 0.2s ease;
            margin: 0 auto 30px;
            display: block;
            padding: 0 20px;
            box-sizing: border-box;
        }
        
        /* 移动端适配 */
        @media (max-width: 480px) {
            .cdk-input-single {
                height: 50px;
                font-size: 1rem;
            }
            
            .cdk-title {
                font-size: 1.6rem;
            }
        }
        
        .cdk-input-single:focus {
            outline: none;
            border-color: #00af5c;
            box-shadow: 0 0 0 3px rgba(0, 175, 92, 0.2);
        }
        
        /* 兑换按钮样式 */
        .cdk-submit-btn {
            display: block;
            width: 70%;
            max-width: 200px;
            margin: 0 auto;
            padding: 16px;
            background-color: #00af5c;
            color: white;
            border: none;
            border-radius: 26px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 4px 12px rgba(0, 175, 92, 0.2);
        }
        
        .cdk-submit-btn:hover {
            background-color: #00964e;
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0, 175, 92, 0.3);
        }
        
        .cdk-submit-btn:active {
            transform: scale(0.98) translateY(0);
            box-shadow: 0 2px 8px rgba(0, 175, 92, 0.2);
        }
        
        .cdk-note {
            text-align: center;
            margin-top: 25px;
            color: #6b7280;
            font-size: 0.95rem;
            line-height: 1.6;
            padding: 0 10px;
        }
        
        .back-link {
            display: block;
            text-align: center;
            margin-top: 35px;
            color: #00af5c;
            text-decoration: none;
            font-size: 1rem;
            font-weight: 500;
            transition: color 0.2s ease;
        }
        
        .back-link:hover {
            text-decoration: underline;
            color: #00964e;
        }
    </style>
</head>
<body>
    <!-- 页眉区域（保留不变） -->
    <header class="header-fixed py-3">
        <div class="max-w-5xl mx-auto px-6 flex justify-between items-center">
            <div class="flex items-center gap-2">
                <span class="text-2xl font-bold text-[#2c2e31]">GetPlugins</span>
            </div>
            
            <div class="flex items-center gap-5 text-[#2c2e31]">
                <div class="relative">
                    <div class="points-container" id="pointsContainer">
                        <span class="text-xl">
                            <span class="points-number font-extrabold" id="pointsDisplay">
                                <?php echo is_logged_in() ? $_SESSION['points'] : '0'; ?>
                            </span>
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

    <!-- 主要内容区域 -->
    <main class="max-w-5xl mx-auto px-6 py-10 min-h-[500px]">
        <!-- 进度条 -->
        <div class="progress-container" id="progressContainer">
            <div class="progress-bar" id="progressBar"></div>
        </div>
        
        <!-- CDK兑换容器 -->
        <div class="cdk-container">
            <h1 class="cdk-title">添加积分序列号</h1>
            
            <!-- 提示消息 -->
            <?php if ($error): ?>
                <div class="cdk-message cdk-error">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="cdk-message cdk-success">
                    <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>
            
            <!-- CDK兑换表单 - 单个输入框 -->
            <form method="post" id="cdkForm">
                <!-- 单个完整的CDK输入框 - 移除自动大写转换 -->
                <input 
                    type="text" 
                    name="cdk" 
                    id="cdkInput" 
                    class="cdk-input-single" 
                    placeholder="在此处输入完整的序列号，区分大小写" 
                    autocomplete="off"
                >
                
                <button type="submit" class="cdk-submit-btn">立即兑换</button>
                
                <p class="cdk-note">
                    请输入或粘贴你从经销商处获得的积分序列号。
                </p>
            </form>
        </div>
    </main>

    <!-- 正文和页脚之间的过渡细线 -->
    <div class="content-footer-divider"></div>

    <!-- 页脚区域 -->
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

    <!-- 脚本区域 - 完全移除CDK处理逻辑 -->
    <script>
        // 保留原有下拉菜单功能
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

        // 积分增加动画功能
        <?php if ($success && $added_points > 0): ?>
        document.addEventListener('DOMContentLoaded', function() {
            // 更新积分显示
            const pointsDisplay = document.getElementById('pointsDisplay');
            pointsDisplay.textContent = <?php echo $_SESSION['points']; ?>;
            
            // 创建积分增加徽章
            const badge = document.createElement('span');
            badge.className = 'points-badge';
            badge.textContent = '+' + <?php echo $added_points; ?>;
            pointsDisplay.appendChild(badge);
            
            // 数字滚动动画
            animatePoints(<?php echo $_SESSION['points'] - $added_points; ?>, <?php echo $_SESSION['points']; ?>, pointsDisplay);
        });
        
        // 数字滚动动画
        function animatePoints(start, end, element) {
            let current = start;
            const step = Math.ceil((end - start) / 30); // 30帧完成动画
            const timer = setInterval(() => {
                current += step;
                if (current >= end) {
                    current = end;
                    clearInterval(timer);
                }
                element.textContent = current;
            }, 30);
        }
        <?php endif; ?>

        // 仅保留最基础的表单非空验证
        document.addEventListener('DOMContentLoaded', function() {
            // 表单提交验证 - 仅检查是否为空
            document.getElementById('cdkForm').addEventListener('submit', function(e) {
                const cdkValue = document.getElementById('cdkInput').value.trim();
                if (!cdkValue) {
                    e.preventDefault();
                    alert('请输入CDK序列号');
                    document.getElementById('cdkInput').focus();
                }
            });
        });
    </script>
</body>
</html>