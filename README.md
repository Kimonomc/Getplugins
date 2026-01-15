# GetPlugins - Minecraft 资源下载平台

## 项目简介

GetPlugins 是一个开源的 Minecraft 插件/资源下载平台，为用户提供便捷的资源浏览、购买和下载服务。平台支持资源分类管理、版本控制、积分系统和用户权限管理等功能。

## 功能特性

### 用户系统
- 用户注册和登录
- 个人中心管理
- 积分系统（通过 CDK 兑换积分）
- 购买记录和权限管理

### 资源管理
- 资源上传和版本控制
- 资源分类和标签管理
- 资源搜索和筛选
- 下载次数和点赞统计

### 积分系统
- CDK 生成和兑换
- 积分消费记录
- 管理员积分管理

### 管理员功能
- 资源管理（上传、编辑、删除）
- 用户积分管理
- 分类和版本管理
- CDK 生成

### 技术特性
- 响应式设计，支持移动端和桌面端
- 实时搜索和筛选
- 安全的密码加密
- 完整的数据库结构

## 技术栈

- **后端**：PHP 7.4+
- **数据库**：MySQL 5.7+
- **前端**：HTML5, CSS3, JavaScript
- **第三方库**：
  - Tailwind CSS (CDN)
  - Font Awesome (CDN)
  - hCaptcha (人机验证)

## 安装部署

### 环境要求
- PHP 7.4 或更高版本
- MySQL 5.7 或更高版本
- Web 服务器（Apache, Nginx 等）
- 支持文件上传的服务器配置

### 部署步骤

1. **克隆代码库**
   ```bash
   git clone <repository-url>
   cd Getplugins
   ```

2. **配置数据库**
   - 复制 `db_connect.php` 文件
   - 修改数据库连接信息：
     ```php
     $host = 'localhost';      // 数据库主机
     $dbname = 'getplugins';   // 数据库名称
     $username = 'root';       // 数据库用户名
     $password = 'password';   // 数据库密码
     ```

3. **配置 hCaptcha**
   - 修改 `login.php` 和 `register.php` 中的 hCaptcha 密钥：
     ```php
     $secret_key = "your-secret-key";     // hCaptcha 密钥
     data-sitekey="your-site-key"         // hCaptcha 站点密钥
     ```

4. **上传到服务器**
   - 将所有文件上传到您的 Web 服务器根目录
   - 确保 `upload` 目录（如果存在）具有可写权限

5. **初始化数据库**
   - 访问网站首页，系统会自动创建所需的数据库表结构
   - 系统会自动创建默认管理员账号：
     - 用户名：admin
     - 密码：wz123456

6. **配置服务器**
   - 确保 PHP 配置允许文件上传
   - 设置适当的最大上传文件大小
   - 启用 PHP cURL 扩展（用于 hCaptcha 验证）

## 使用方法

### 用户使用指南

1. **注册和登录**
   - 访问 `register.php` 创建账号
   - 访问 `login.php` 登录系统

2. **浏览资源**
   - 在首页浏览和搜索资源
   - 使用筛选功能按更新时间、下载量或点赞数排序

3. **购买和下载**
   - 点击资源进入详情页
   - 使用积分购买资源（购买后30天内可重复下载）
   - 下载最新版本或历史版本

4. **积分管理**
   - 访问 `cdk_exchange.php` 兑换 CDK 获取积分
   - 访问 `point_records.php` 查看积分记录

### 管理员使用指南

1. **登录管理员账号**
   - 使用默认管理员账号登录
   - 登录后在用户菜单中选择 "管理员面板"

2. **资源管理**
   - **上传新资源**：访问 `admin_upload_file.php`
   - **管理资源**：访问 `admin_manage_files.php`
   - **上传新版本**：在资源详情页点击 "上传新版本"

3. **系统管理**
   - **生成 CDK**：访问 `admin_generate_cdk.php`
   - **修改用户积分**：访问 `admin_modify_points.php`
   - **分类管理**：访问 `admin_create_category.php`
   - **MC版本管理**：访问 `admin_create_mc_version.php`
   - **运行平台管理**：访问 `admin_create_platform.php`

## 安全注意事项

1. **敏感信息保护**
   - 部署前请修改默认管理员密码
   - 确保数据库连接信息安全
   - 替换为您自己的 hCaptcha 密钥

2. **文件上传安全**
   - 定期检查上传目录，确保没有恶意文件
   - 考虑限制上传文件类型和大小

3. **权限管理**
   - 不要将管理员权限授予普通用户
   - 定期检查用户积分和购买记录

4. **数据库安全**
   - 使用参数化查询防止 SQL 注入
   - 定期备份数据库

## 数据库结构

系统包含以下主要数据表：

- **users** - 用户信息表
- **files** - 资源主表
- **file_versions** - 资源版本表
- **categories** - 资源分类表
- **mc_versions** - Minecraft 版本表
- **platforms** - 运行平台表
- **purchases** - 购买记录表
- **likes** - 点赞记录表
- **cdks** - CDK 表
- **point_records** - 积分记录表

## 自定义配置

### 样式自定义
- 修改 CSS 文件中的颜色和样式
- 替换网站图标和 logo

### 功能扩展
- 添加新的资源类型
- 扩展积分获取方式
- 添加评论系统
- 集成支付系统

## 故障排除

### 常见问题

1. **无法上传文件**
   - 检查服务器文件上传权限
   - 检查 PHP 上传大小限制

2. **数据库连接失败**
   - 检查数据库连接信息
   - 确保 MySQL 服务正在运行

3. **hCaptcha 验证失败**
   - 检查 hCaptcha 密钥配置
   - 确保服务器可以访问 hCaptcha API

4. **积分不更新**
   - 检查数据库事务处理
   - 查看错误日志

## 开源协议

本项目采用 MIT 开源协议，详见 LICENSE 文件。

## 贡献

欢迎提交 Issue 和 Pull Request 来改进这个项目。如果您有任何问题或建议，请在 GitHub 上创建 Issue。

## 免责声明

本项目为非官方 Minecraft 产品，未经 Mojang 或 Microsoft 批准或关联。

---

**GetPlugins** - 让 Minecraft 资源管理更简单！