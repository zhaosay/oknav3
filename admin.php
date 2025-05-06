<?php
// 设置session保存路径到当前目录下的sessions文件夹
if (!is_dir('sessions')) {
    mkdir('sessions', 0755, true);
}
session_save_path('sessions');
session_start();

// 错误日志记录
function log_error($message) {
    file_put_contents('error.log', date('Y-m-d H:i:s') . ' - ' . $message . "\n", FILE_APPEND);
}

// 获取TDK（标题、描述、关键词）内容
function get_tdk() {
    global $db;
    $result = $db->query('SELECT title, description, keywords FROM config LIMIT 1');
    $tdk = $result->fetchArray(SQLITE3_ASSOC);
    return $tdk ?: ['title' => '', 'description' => '', 'keywords' => ''];
}

// 连接数据库
$db = new SQLite3('data/nav.db');
if (!$db) {
    log_error('数据库连接失败: ' . $db->lastErrorMsg());
    http_response_code(500);
    die(json_encode(['error' => '数据库连接失败']));
}

// 确保data目录存在且有写入权限
if (!is_dir('data')) {
    mkdir('data', 0755, true);
}

// 检查是否有管理员账户，如果没有则创建默认账户
$result = $db->querySingle('SELECT COUNT(*) FROM users', true);
if ($result['COUNT(*)'] == 0) {
    // 创建默认管理员账户 (用户名: admin, 密码: admin123)
    $defaultPassword = password_hash('admin123', PASSWORD_DEFAULT);
    $db->exec("INSERT INTO users (username, password) VALUES ('admin', '$defaultPassword')");
}

// 检查是否有默认分类，如果没有则创建
$result = $db->querySingle('SELECT COUNT(*) FROM categories', true);
if ($result['COUNT(*)'] == 0) {
    // 创建默认分类
    $db->exec("INSERT INTO categories (name, sort_order) VALUES ('默认分类', 0)");
}

// 处理登录请求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    $stmt = $db->prepare('SELECT * FROM users WHERE username = :username');
    $stmt->bindValue(':username', $username, SQLITE3_TEXT);
    $user = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    
    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_username'] = $user['username'];
        header('Location: admin.php');
        exit;
    } else {
        $loginError = '用户名或密码错误';
    }
}

// 处理用户密码修改
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_password' && $_SESSION['admin_logged_in']) {
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $username = $_SESSION['admin_username'];
    
    // 验证当前密码
    $stmt = $db->prepare('SELECT * FROM users WHERE username = :username');
    $stmt->bindValue(':username', $username, SQLITE3_TEXT);
    $user = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    
    if (!$user || !password_verify($currentPassword, $user['password'])) {
        $passwordError = '当前密码不正确';
    } elseif ($newPassword !== $confirmPassword) {
        $passwordError = '新密码与确认密码不匹配';
    } elseif (strlen($newPassword) < 6) {
        $passwordError = '新密码长度不能少于6个字符';
    } else {
        // 更新密码
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $db->prepare('UPDATE users SET password = :password WHERE username = :username');
        $stmt->bindValue(':password', $hashedPassword, SQLITE3_TEXT);
        $stmt->bindValue(':username', $username, SQLITE3_TEXT);
        $stmt->execute();
        
        $passwordSuccess = '密码修改成功';
    }
}

// 处理主题设置
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_theme' && $_SESSION['admin_logged_in']) {
    $theme = $_POST['theme'] ?? 'light';
    
    // 处理自定义主题颜色
    $custom_colors = null;
    if ($theme === 'custom' && isset($_POST['custom_colors']) && is_array($_POST['custom_colors'])) {
        $custom_colors = json_encode($_POST['custom_colors']);
    }
    
    // 更新配置表中的主题设置
    $stmt = $db->prepare('UPDATE config SET theme = :theme, custom_colors = :custom_colors');
    $stmt->bindValue(':theme', $theme, SQLITE3_TEXT);
    $stmt->bindValue(':custom_colors', $custom_colors, SQLITE3_TEXT);
    $stmt->execute();
    
    header('Location: admin.php');
    exit;
}

// 处理TDK设置
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_tdk' && $_SESSION['admin_logged_in']) {
    $title = $_POST['title'] ?? '';
    $description = $_POST['description'] ?? '';
    $keywords = $_POST['keywords'] ?? '';
    
    // 更新配置表中的TDK设置
    $stmt = $db->prepare('UPDATE config SET title = :title, description = :description, keywords = :keywords');
    $stmt->bindValue(':title', $title, SQLITE3_TEXT);
    $stmt->bindValue(':description', $description, SQLITE3_TEXT);
    $stmt->bindValue(':keywords', $keywords, SQLITE3_TEXT);
    $stmt->execute();
    
    header('Location: admin.php');
    exit;
}

// 处理分类管理和链接管理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_SESSION['admin_logged_in']) {
    // 分类管理
    if ($_POST['action'] === 'add_category') {
        $categoryName = $_POST['category_name'] ?? '';
        $sortOrder = (int)($_POST['sort_order'] ?? 0);
        
        if (!empty($categoryName)) {
            $stmt = $db->prepare('INSERT INTO categories (name, sort_order) VALUES (:name, :sort_order)');
            $stmt->bindValue(':name', $categoryName, SQLITE3_TEXT);
            $stmt->bindValue(':sort_order', $sortOrder, SQLITE3_INTEGER);
            $stmt->execute();
            header('Location: admin.php');
            exit;
        }
    } elseif ($_POST['action'] === 'edit_category') {
        $categoryId = (int)($_POST['category_id'] ?? 0);
        $categoryName = $_POST['category_name'] ?? '';
        $sortOrder = (int)($_POST['sort_order'] ?? 0);
        
        if ($categoryId > 0 && !empty($categoryName)) {
            $stmt = $db->prepare('UPDATE categories SET name = :name, sort_order = :sort_order WHERE id = :id');
            $stmt->bindValue(':name', $categoryName, SQLITE3_TEXT);
            $stmt->bindValue(':sort_order', $sortOrder, SQLITE3_INTEGER);
            $stmt->bindValue(':id', $categoryId, SQLITE3_INTEGER);
            $stmt->execute();
            header('Location: admin.php');
            exit;
        }
    } elseif ($_POST['action'] === 'delete_category') {
        $categoryId = (int)($_POST['category_id'] ?? 0);
        
        if ($categoryId > 1) { // 不允许删除默认分类（ID=1）
            // 将该分类下的项目移动到默认分类
            $stmt = $db->prepare('UPDATE items SET category_id = 1 WHERE category_id = :category_id');
            $stmt->bindValue(':category_id', $categoryId, SQLITE3_INTEGER);
            $stmt->execute();
            
            // 删除分类
            $stmt = $db->prepare('DELETE FROM categories WHERE id = :id');
            $stmt->bindValue(':id', $categoryId, SQLITE3_INTEGER);
            $stmt->execute();
            header('Location: admin.php');
            exit;
        }
    }
    
    // 链接管理
    elseif ($_POST['action'] === 'add_link') {
        $linkName = $_POST['link_name'] ?? '';
        $linkUrl = $_POST['link_url'] ?? '';
        $linkIcon = $_POST['link_icon'] ?? '';
        $categoryId = (int)($_POST['link_category_id'] ?? 1);
        
        if (!empty($linkName) && !empty($linkUrl) && !empty($linkIcon)) {
            $stmt = $db->prepare('INSERT INTO items (name, url, icon, category_id) VALUES (:name, :url, :icon, :category_id)');
            $stmt->bindValue(':name', $linkName, SQLITE3_TEXT);
            $stmt->bindValue(':url', $linkUrl, SQLITE3_TEXT);
            $stmt->bindValue(':icon', $linkIcon, SQLITE3_TEXT);
            $stmt->bindValue(':category_id', $categoryId, SQLITE3_INTEGER);
            $stmt->execute();
            header('Location: admin.php');
            exit;
        }
    } elseif ($_POST['action'] === 'edit_link') {
        $linkId = (int)($_POST['link_id'] ?? 0);
        $linkName = $_POST['link_name'] ?? '';
        $linkUrl = $_POST['link_url'] ?? '';
        $linkIcon = $_POST['link_icon'] ?? '';
        $categoryId = (int)($_POST['link_category_id'] ?? 1);
        
        if ($linkId > 0 && !empty($linkName) && !empty($linkUrl) && !empty($linkIcon)) {
            $stmt = $db->prepare('UPDATE items SET name = :name, url = :url, icon = :icon, category_id = :category_id WHERE id = :id');
            $stmt->bindValue(':name', $linkName, SQLITE3_TEXT);
            $stmt->bindValue(':url', $linkUrl, SQLITE3_TEXT);
            $stmt->bindValue(':icon', $linkIcon, SQLITE3_TEXT);
            $stmt->bindValue(':category_id', $categoryId, SQLITE3_INTEGER);
            $stmt->bindValue(':id', $linkId, SQLITE3_INTEGER);
            $stmt->execute();
            header('Location: admin.php');
            exit;
        }
    } elseif ($_POST['action'] === 'delete_link') {
        $linkId = (int)($_POST['link_id'] ?? 0);
        
        if ($linkId > 0) {
            $stmt = $db->prepare('DELETE FROM items WHERE id = :id');
            $stmt->bindValue(':id', $linkId, SQLITE3_INTEGER);
            $stmt->execute();
            header('Location: admin.php');
            exit;
        }
    }
}

// 处理退出登录
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    header('Location: admin.php');
    exit;
}

// 获取所有分类
$categories = [];
$categoriesResult = $db->query('SELECT * FROM categories ORDER BY sort_order ASC');
while ($category = $categoriesResult->fetchArray(SQLITE3_ASSOC)) {
    $categories[] = $category;
}

// 获取当前主题设置
$currentTheme = 'light';
$themeResult = $db->querySingle('SELECT theme FROM config LIMIT 1', true);
if ($themeResult) {
    $currentTheme = $themeResult['theme'];
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>导航管理</title>
    <link rel="stylesheet" href="bootstrap-icons/font/bootstrap-icons.css">
    <link rel="stylesheet" href="layui/css/layui.css">
    <link rel="stylesheet" href="style.css">
    <link rel="icon" href="bootstrap-icons/reception-4.svg" type="image/svg+xml">
    <style>
        :root {
            --primary-color: #3498db;
            --secondary-color: #2ecc71;
            --danger-color: #e74c3c;
            --dark-color: #34495e;
            --light-color: #ecf0f1;
            --border-radius: 8px;
            --box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s ease;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: #333;
            background-color: #f8f9fa;
            margin: 0;
            padding: 0;
        }
        
        .admin-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
            background-color: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
        }
        
        .admin-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #eee;
        }
        
        .admin-header h1 {
            margin: 0;
            color: var(--dark-color);
            font-size: 1.8rem;
        }
        
        .admin-nav {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 20px;
            background-color: var(--light-color);
            padding: 10px;
            border-radius: var(--border-radius);
        }
        
        .admin-nav a {
            padding: 10px 15px;
            background-color: white;
            border-radius: var(--border-radius);
            text-decoration: none;
            color: var(--dark-color);
            font-weight: 500;
            transition: var(--transition);
            text-align: center;
            flex: 1;
            min-width: 100px;
        }
        
        .admin-nav a:hover {
            background-color: #e9ecef;
            transform: translateY(-2px);
        }
        
        .admin-nav a.active {
            background-color: var(--primary-color);
            color: white;
        }
        
        .login-form {
            max-width: 400px;
            margin: 50px auto;
            padding: 30px;
            background-color: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
        }
        
        .login-form h2 {
            margin-top: 0;
            color: var(--dark-color);
            text-align: center;
            margin-bottom: 20px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--dark-color);
        }
        
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 16px;
            transition: border-color 0.3s;
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: var(--primary-color);
            outline: none;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.2);
        }
        
        .styled-textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 16px;
            transition: border-color 0.3s;
            resize: vertical;
            min-height: 80px;
            font-family: inherit;
            background-color: #fff;
            color: #333;
        }
        
        .category-list, .link-list {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            background-color: white;
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--box-shadow);
        }
        
        .category-list th, .category-list td, .link-list th, .link-list td {
            padding: 12px 15px;
            border: 1px solid #eee;
            text-align: left;
        }
        
        .category-list th, .link-list th {
            background-color: var(--primary-color);
            color: white;
            font-weight: 500;
        }
        
        .category-form, .link-form {
            max-width: 100%;
            margin-bottom: 30px;
            padding: 20px;
            background-color: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
        }
        
        .category-form h2, .link-form h2 {
            margin-top: 0;
            color: var(--dark-color);
            margin-bottom: 20px;
        }
        
        .link-filter {
            margin-bottom: 20px;
            padding: 15px;
            background-color: white;
            border-radius: var(--border-radius);
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 10px;
            box-shadow: var(--box-shadow);
        }
        
        .link-filter select {
            padding: 10px;
            border-radius: 4px;
            border: 1px solid #ddd;
            flex-grow: 1;
            min-width: 150px;
        }
        
        .link-list {
            box-shadow: var(--box-shadow);
            margin-top: 15px;
        }
        
        .link-list th {
            position: sticky;
            top: 0;
            background-color: var(--primary-color);
            z-index: 10;
        }
        
        .link-list td {
            vertical-align: middle;
        }
        
        .link-list td a {
            color: var(--primary-color);
            text-decoration: none;
            word-break: break-all;
        }
        
        .link-list td a:hover {
            text-decoration: underline;
        }
        
        #editLinkForm, #editCategoryForm {
            background-color: white;
            padding: 20px;
            border-radius: var(--border-radius);
            margin-top: 20px;
            border: 1px solid #eee;
            box-shadow: var(--box-shadow);
        }
        
        .btn-group {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .btn {
            padding: 10px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 500;
            transition: var(--transition);
        }
        
        .btn:hover {
            opacity: 0.9;
            transform: translateY(-2px);
        }
        
        .btn-primary {
            background-color: var(--primary-color);
            color: white;
        }
        
        .btn-danger {
            background-color: var(--danger-color);
            color: white;
        }
        
        .btn-secondary {
            background-color: var(--dark-color);
            color: white;
        }
        
        /* 主题预览样式 */
        .theme-preview {
            margin: 20px 0;
        }
        
        .preview-container {
            border: 1px solid #ddd;
            border-radius: var(--border-radius);
            padding: 15px;
            background-color: var(--bg-color);
            color: var(--text-color);
            transition: all 0.3s ease;
        }
        
        /* 自定义主题设置样式 */
        #custom-theme-settings {
            background-color: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: var(--border-radius);
            padding: 20px;
            margin-top: 15px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        
        #custom-theme-settings h3 {
            margin-top: 0;
            margin-bottom: 20px;
            color: var(--dark-color);
            font-size: 1.2rem;
            border-bottom: 1px solid #e9ecef;
            padding-bottom: 10px;
        }
        
        .color-settings-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 15px;
        }
        
        .color-picker-group {
            display: flex;
            flex-direction: column;
            background-color: white;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .color-picker-group label {
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--dark-color);
        }
        
        .color-picker-group input[type="color"] {
            width: 100%;
            height: 40px;
            padding: 2px;
            border: 1px solid #ddd;
            border-radius: 4px;
            cursor: pointer;
            margin-bottom: 8px;
        }
        
        .color-value {
            font-family: monospace;
            font-size: 14px;
            color: #666;
            text-align: center;
            background-color: #f5f5f5;
            padding: 4px 8px;
            border-radius: 4px;
        }
        
        .preview-header {
            font-size: 1.2rem;
            font-weight: bold;
            text-align: center;
            margin-bottom: 15px;
            color: var(--primary-color);
        }
        
        .preview-items {
            display: flex;
            justify-content: space-around;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .preview-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            background-color: var(--card-bg);
            padding: 10px;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            min-width: 80px;
            text-align: center;
        }
        
        .preview-item i {
            font-size: 1.5rem;
            margin-bottom: 5px;
            color: var(--primary-color);
        }
        
        /* 响应式设计 */
        @media (max-width: 768px) {
            .admin-header {
                flex-direction: column;
                text-align: center;
                gap: 15px;
            }
            
            .admin-header h1 {
                font-size: 1.5rem;
            }
            
            .admin-nav {
                flex-direction: column;
            }
            
            .admin-nav a {
                width: 100%;
            }
            
            .category-list, .link-list {
                display: block;
                overflow-x: auto;
            }
            
            .btn-group {
                display: grid;
                grid-template-columns: repeat(2, 1fr);
                gap: 8px;
            }
            
            .btn-group .btn:only-child {
                grid-column: span 2;
            }
            
            .category-form, .link-form {
                padding: 15px;
            }
            
            /* 移动端表格优化 */
            .link-list thead {
                display: none;
            }
            
            .link-list, .link-list tbody, .link-list tr, .link-list td {
                display: inline-grid;
                width: 100%;
            }
            
            .link-list tr {
                margin-bottom: 15px;
                border: 1px solid #ddd;
                border-radius: var(--border-radius);
                padding: 10px;
            }
            
            .link-list td {
                border: none;
                border-bottom: 1px solid #eee;
                position: relative;
                padding-left: 50%;
                text-align: right;
            }
            
            .link-list td:last-child {
                border-bottom: none;
            }
            
            .link-list td:before {
                content: attr(data-label);
                position: absolute;
                left: 10px;
                width: 45%;
                padding: 5% 0;
                white-space: nowrap;
                text-align: left;
                font-weight: bold;
            }
            
            /* 同样处理分类表格 */
            .category-list thead {
                display: none;
            }
            
            .category-list, .category-list tbody, .category-list tr, .category-list td {
                display: block;
                width: 100%;
            }
            
            .category-list tr {
                margin-bottom: 15px;
                border: 1px solid #ddd;
                border-radius: var(--border-radius);
                padding: 10px;
            }
            
            .category-list td {
                border: none;
                border-bottom: 1px solid #eee;
                position: relative;
                padding-left: 50%;
                text-align: right;
            }
            
            .category-list td:last-child {
                border-bottom: none;
            }
            
            .category-list td:before {
                content: attr(data-label);
                position: absolute;
                left: 10px;
                width: 45%;
                padding-right: 10px;
                white-space: nowrap;
                text-align: left;
                font-weight: bold;
            }
        }
        
        /* 模态框样式优化 */
        #userSettingsModal, #iconSelectorModal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            z-index: 1000;
            overflow-y: auto;
        }
        
        .modal-content {
            background: white;
            max-width: 500px;
            margin: 50px auto;
            padding: 25px;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
        }
        
        .modal-header h2 {
            margin: 0;
            color: var(--dark-color);
        }
        
        .close-btn {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #999;
            transition: var(--transition);
        }
        
        .close-btn:hover {
            color: var(--danger-color);
        }
        
        .icon-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(80px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .icon-item {
            text-align: center;
            padding: 15px 10px;
            border: 1px solid #eee;
            border-radius: var(--border-radius);
            cursor: pointer;
            transition: var(--transition);
        }
        
        .icon-item:hover {
            border-color: var(--primary-color);
            transform: translateY(-3px);
            box-shadow: var(--box-shadow);
        }
        
        #iconSearch {
            width: 100%;
            padding: 12px;
            margin-bottom: 20px;
            border: 1px solid #ddd;
            border-radius: var(--border-radius);
            font-size: 16px;
        }
        
        .icon-type-buttons {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }
        
        @media (max-width: 576px) {
            .modal-content {
                margin: 20px;
                padding: 15px;
            }
            
            .icon-grid {
                grid-template-columns: repeat(auto-fill, minmax(60px, 1fr));
            }
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <?php if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in']): ?>
            <div class="admin-header">
                <h1>导航管理系统</h1>
                <div>
                    <span>欢迎, <?= htmlspecialchars($_SESSION['admin_username']) ?></span>
                    <a href="admin.php?action=logout" class="btn btn-secondary">退出登录</a>
                    <a href="index.php" class="btn btn-primary">返回首页</a>
                </div>
            </div>
            
            <div class="admin-nav">
                <a href="#" id="category-tab" onclick="showTab('category')">分类管理</a>
                <a href="#" id="link-tab" onclick="showTab('link')" class="active">链接管理</a>
                <a href="#" id="theme-tab" onclick="showTab('theme')">主题设置</a>
                <a href="#" id="tdk-tab" onclick="showTab('tdk')">TDK设置</a>
                <a href="#" onclick="showUserSettings()">账户设置</a>
                <a href="#" onclick="showIconSelector()">图标设置</a>
            </div>
            
            <!-- 主题设置部分 -->
            <div id="theme-section" style="display: none;">
                <h2>主题设置</h2>
                <form class="category-form" method="post" action="save.php">
                    <input type="hidden" name="action" value="save_theme">
                    <div class="form-group">
                        <label>选择主题</label>
                        <select name="theme" id="theme-selector" onchange="previewTheme(this.value)">
                            <option value="light" <?= $currentTheme === 'light' ? 'selected' : '' ?>>默认主题</option>
                            <option value="dark" <?= $currentTheme === 'dark' ? 'selected' : '' ?>>暗黑主题</option>
                            <option value="blue" <?= $currentTheme === 'blue' ? 'selected' : '' ?>>蓝色主题</option>
                            <option value="chinese" <?= $currentTheme === 'chinese' ? 'selected' : '' ?>>中国风主题</option>
                            <option value="custom" <?= $currentTheme === 'custom' ? 'selected' : '' ?>>自定义主题</option>
                        </select>
                    </div>
                    
                    <!-- 自定义主题设置 -->
                    <div id="custom-theme-settings" style="display: <?= $currentTheme === 'custom' ? 'block' : 'none' ?>">
                        <h3>自定义主题颜色</h3>
                        <?php
                        // 获取自定义颜色设置
                        $customColors = [];
                        $customColorsResult = $db->querySingle('SELECT custom_colors FROM config LIMIT 1', true);
                        if ($customColorsResult && !empty($customColorsResult['custom_colors'])) {
                            $customColors = json_decode($customColorsResult['custom_colors'], true) ?: [];
                        }
                        ?>
                        <div class="color-settings-grid">
                            <div class="form-group color-picker-group">
                                <label>主要颜色</label>
                                <input type="color" name="custom_colors[primary-color]" value="<?= htmlspecialchars($customColors['primary-color'] ?? '#4285f4') ?>">
                                <span class="color-value"><?= htmlspecialchars($customColors['primary-color'] ?? '#4285f4') ?></span>
                            </div>
                            <div class="form-group color-picker-group">
                                <label>背景颜色</label>
                                <input type="color" name="custom_colors[bg-color]" value="<?= htmlspecialchars($customColors['bg-color'] ?? '#f5f7fa') ?>">
                                <span class="color-value"><?= htmlspecialchars($customColors['bg-color'] ?? '#f5f7fa') ?></span>
                            </div>
                            <div class="form-group color-picker-group">
                                <label>卡片背景颜色</label>
                                <input type="color" name="custom_colors[card-bg]" value="<?= htmlspecialchars($customColors['card-bg'] ?? '#ffffff') ?>">
                                <span class="color-value"><?= htmlspecialchars($customColors['card-bg'] ?? '#ffffff') ?></span>
                            </div>
                            <div class="form-group color-picker-group">
                                <label>文字颜色</label>
                                <input type="color" name="custom_colors[text-color]" value="<?= htmlspecialchars($customColors['text-color'] ?? '#333333') ?>">
                                <span class="color-value"><?= htmlspecialchars($customColors['text-color'] ?? '#333333') ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="theme-preview">
                        <h3>主题预览</h3>
                        <div class="preview-container" data-theme="<?= $currentTheme ?>">
                            <div class="preview-header">导航标题</div>
                            <div class="preview-items">
                                <div class="preview-item"><i class="bi bi-house"></i><span>首页</span></div>
                                <div class="preview-item"><i class="bi bi-search"></i><span>搜索</span></div>
                                <div class="preview-item"><i class="bi bi-book"></i><span>文档</span></div>
                            </div>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">保存主题设置</button>
                </form>
            </div>
            
            <!-- TDK设置部分 -->
            <div id="tdk-section" style="display: none;">
                <h2>TDK设置</h2>
                <form class="category-form" method="post" action="save.php">
                    <input type="hidden" name="action" value="save_tdk">
                    <?php
                    // 获取TDK内容
                    $tdk = get_tdk();
                    ?>
                    
                    <div class="form-group">
                        <label>首页标题 (Title)</label>
                        <input type="text" name="title" value="<?= htmlspecialchars($tdk['title'] ?? '') ?>" placeholder="请输入网站标题" required>
                    </div>
                    <div class="form-group">
                        <label>首页描述 (Description)</label>
                        <textarea name="description" rows="3" placeholder="请输入网站描述" class="styled-textarea"><?= htmlspecialchars($tdk['description'] ?? '') ?></textarea>
                    </div>
                    <div class="form-group">
                        <label>首页关键词 (Keywords)</label>
                        <textarea name="keywords" rows="3" placeholder="请输入网站关键词，用逗号分隔" class="styled-textarea"><?= htmlspecialchars($tdk['keywords'] ?? '') ?></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary">保存TDK设置</button>
                </form>
            </div>
            
            <!-- 分类管理部分 -->
            <div id="category-section" style="display: none;">
                <h2>添加新分类</h2>
                <form class="category-form" method="post" action="admin.php">
                    <input type="hidden" name="action" value="add_category">
                    <div class="form-group">
                        <label>分类名称</label>
                        <input type="text" name="category_name" required>
                    </div>
                    <div class="form-group">
                        <label>排序顺序</label>
                        <input type="number" name="sort_order" value="0">
                    </div>
                    <button type="submit" class="btn btn-primary">添加分类</button>
                </form>
                
                <h2>分类列表</h2>
                <table class="category-list">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>名称</th>
                            <th>排序</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($categories as $category): ?>
                        <tr>
                            <td data-label="ID"><?= $category['id'] ?></td>
                            <td data-label="名称"><?= htmlspecialchars($category['name']) ?></td>
                            <td data-label="排序"><?= $category['sort_order'] ?></td>
                            <td data-label="操作" class="btn-group">
                                <button class="btn btn-primary" onclick="editCategory(<?= $category['id'] ?>, '<?= htmlspecialchars($category['name']) ?>', <?= $category['sort_order'] ?>)">编辑</button>
                                <?php if ($category['id'] > 1): ?>
                                <form method="post" action="admin.php" onsubmit="return confirm('确定要删除此分类吗？该分类下的所有项目将移动到默认分类。')">
                                    <input type="hidden" name="action" value="delete_category">
                                    <input type="hidden" name="category_id" value="<?= $category['id'] ?>">
                                    <button type="submit" class="btn btn-danger">删除</button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <!-- 编辑分类的表单 (默认隐藏) -->
                <div id="editCategoryForm" style="display: none;">
                    <h2>编辑分类</h2>
                    <form class="category-form" method="post" action="admin.php">
                        <input type="hidden" name="action" value="edit_category">
                        <input type="hidden" name="category_id" id="edit_category_id">
                        <div class="form-group">
                            <label>分类名称</label>
                            <input type="text" name="category_name" id="edit_category_name" required>
                        </div>
                        <div class="form-group">
                            <label>排序顺序</label>
                            <input type="number" name="sort_order" id="edit_sort_order" value="0">
                        </div>
                        <button type="submit" class="btn btn-primary">保存修改</button>
                        <button type="button" class="btn btn-secondary" onclick="document.getElementById('editCategoryForm').style.display='none'">取消</button>
                    </form>
                </div>
            </div>
            
            <!-- 链接管理部分 -->
            <div id="link-section">
                <h2>添加新链接</h2>
                <form class="link-form" method="post" action="admin.php">
                    <input type="hidden" name="action" value="add_link">
                    <div class="form-group">
                        <label>链接名称</label>
                        <input type="text" name="link_name" required>
                    </div>
                    <div class="form-group">
                        <label>链接地址</label>
                        <input type="url" name="link_url" required>
                    </div>
                    <div class="form-group">
                        <label>图标名称 <small>(点击"图标设置"查看可用图标)</small></label>
                        <input type="text" name="link_icon" id="edit_link_icon" required>
                    </div>
                    <div class="form-group">
                        <label>所属分类</label>
                        <select name="link_category_id" required>
                            <?php foreach ($categories as $category): ?>
                            <option value="<?= $category['id'] ?>"><?= htmlspecialchars($category['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary">添加链接</button>
                </form>
                
                <h2>链接列表</h2>
                <div class="link-filter">
                    <label>按分类筛选：</label>
                    <select id="category-filter" onchange="filterLinks()">
                        <option value="0">全部分类</option>
                        <?php foreach ($categories as $category): ?>
                        <option value="<?= $category['id'] ?>"><?= htmlspecialchars($category['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <table class="link-list">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>名称</th>
                            <th>链接</th>
                            <th>图标</th>
                            <th>分类</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        // 获取所有链接
                        $linksResult = $db->query('SELECT i.*, c.name as category_name FROM items i LEFT JOIN categories c ON i.category_id = c.id ORDER BY i.id DESC');
                        while ($link = $linksResult->fetchArray(SQLITE3_ASSOC)): 
                        ?>
                        <tr class="link-item" data-category="<?= $link['category_id'] ?>">
                            <td data-label="ID"><?= $link['id'] ?></td>
                            <td data-label="名称"><?= htmlspecialchars($link['name']) ?></td>
                            <td data-label="链接"><a href="<?= $link['url'] ?>" target="_blank"><?= htmlspecialchars($link['url']) ?></a></td>
                            <td data-label="图标"><i class="bi bi-<?= $link['icon'] ?>"></i> <?= $link['icon'] ?></td>
                            <td data-label="分类"><?= htmlspecialchars($link['category_name']) ?></td>
                            <td data-label="操作" class="btn-group">
                                <button class="btn btn-primary" onclick="editLink(<?= $link['id'] ?>, '<?= htmlspecialchars($link['name']) ?>', '<?= htmlspecialchars($link['url']) ?>', '<?= $link['icon'] ?>', <?= $link['category_id'] ?>)">编辑</button>
                                <form method="post" action="admin.php" onsubmit="return confirm('确定要删除此链接吗？')">
                                    <input type="hidden" name="action" value="delete_link">
                                    <input type="hidden" name="link_id" value="<?= $link['id'] ?>">
                                    <button type="submit" class="btn btn-danger">删除</button>
                                </form>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
                
                <!-- 编辑链接（修改）的表单 (默认隐藏) -->
                <div id="editLinkForm" style="display: none;">
                    <h2>修改链接</h2>
                    <form class="link-form" method="post" action="admin.php">
                        <input type="hidden" name="action" value="edit_link">
                        <input type="hidden" name="link_id" id="edit_link_id">
                        <div class="form-group">
                            <label>链接名称</label>
                            <input type="text" name="link_name" id="edit_link_name" required>
                        </div>
                        <div class="form-group">
                            <label>链接地址</label>
                            <input type="url" name="link_url" id="edit_link_url" required>
                        </div>
                        <div class="form-group">
                            <label>图标名称</label>
                            <input type="text" name="link_icon" id="edit_link_icon" onclick="hideIconSelector()"  required>
                        </div>
                        <div class="form-group">
                            <label>所属分类</label>
                            <select name="link_category_id" id="edit_link_category_id" required>
                                <?php foreach ($categories as $category): ?>
                                <option value="<?= $category['id'] ?>"><?= htmlspecialchars($category['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary">保存修改</button>
                        <button type="button" class="btn btn-secondary" onclick="document.getElementById('editLinkForm').style.display='none'">取消</button>
                    </form>
                </div>
            </div>
            
        <?php else: ?>
            <div class="login-form">
                <h2>管理员登录</h2>
                <?php if (isset($loginError)): ?>
                    <div style="color: red; margin-bottom: 15px;"><?= $loginError ?></div>
                <?php endif; ?>
                <form method="post" action="admin.php">
                    <input type="hidden" name="action" value="login">
                    <div class="form-group">
                        <label>用户名</label>
                        <input type="text" name="username" required>
                    </div>
                    <div class="form-group">
                        <label>密码</label>
                        <input type="password" name="password" required>
                    </div>
                    <button type="submit" class="btn btn-primary">登录</button>
                </form>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- 用户设置模态框 -->
    <div id="userSettingsModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>修改账户密码</h2>
                <button onclick="hideUserSettings()" class="close-btn">×</button>
            </div>
            
            <?php if (isset($passwordError)): ?>
                <div style="color: red; margin-bottom: 15px;"><?= $passwordError ?></div>
            <?php endif; ?>
            
            <?php if (isset($passwordSuccess)): ?>
                <div style="color: green; margin-bottom: 15px;"><?= $passwordSuccess ?></div>
            <?php endif; ?>
            
            <form method="post" action="admin.php">
                <input type="hidden" name="action" value="change_password">
                <div class="form-group">
                    <label>当前密码</label>
                    <input type="password" name="current_password" required>
                </div>
                <div class="form-group">
                    <label>新密码</label>
                    <input type="password" name="new_password" required minlength="6">
                </div>
                <div class="form-group">
                    <label>确认新密码</label>
                    <input type="password" name="confirm_password" required minlength="6">
                </div>
                <button type="submit" class="btn btn-primary">修改密码</button>
            </form>
        </div>
    </div>
    
    <!-- 图标选择器模态框 -->
    <div id="iconSelectorModal" style="display: none;">
        <div class="modal-content" style="max-width: 800px;">
            <div class="modal-header">
                <h2>图标选择器</h2>
                <button onclick="hideIconSelector()" class="close-btn">×</button>
            </div>
            
            <div style="margin-bottom: 20px;">
                <p>以下是可用的Layui和Bootstrap图标，点击图标可复制其名称：</p>
                <input type="text" id="iconSearch" placeholder="搜索图标...">
            </div>
            
            <div class="icon-type-buttons">
                <button onclick="showIconType('layui')" class="btn btn-primary">Layui图标</button>
                <button onclick="showIconType('bootstrap')" class="btn btn-primary">Bootstrap图标</button>
            </div>
            
            <div id="layuiIcons" class="icon-grid">
                <!-- Layui图标将通过JavaScript动态加载 -->
            </div>
            
            <div id="bootstrapIcons" class="icon-grid" style="display: none;">
                <!-- Bootstrap图标将通过JavaScript动态加载 -->
            </div>
        </div>
    </div>
    
    <script>
    // 页面加载完成后执行
    document.addEventListener('DOMContentLoaded', function() {
        // 默认显示链接管理界面
        showTab('link');
        // 初始化链接筛选，确保表格样式正确显示
        filterLinks();
    });
    
    // 切换标签页
    function showTab(tabName) {
        // 隐藏所有内容区域
        document.getElementById('category-section').style.display = 'none';
        document.getElementById('link-section').style.display = 'none';
        document.getElementById('theme-section').style.display = 'none';
        document.getElementById('tdk-section').style.display = 'none';
        
        // 移除所有标签的活动状态
        document.getElementById('category-tab').classList.remove('active');
        document.getElementById('link-tab').classList.remove('active');
        document.getElementById('theme-tab').classList.remove('active');
        document.getElementById('tdk-tab').classList.remove('active');
        
        // 显示选中的内容区域并激活对应标签
        if (tabName === 'category') {
            document.getElementById('category-section').style.display = 'block';
            document.getElementById('category-tab').classList.add('active');
        } else if (tabName === 'link') {
            document.getElementById('link-section').style.display = 'block';
            document.getElementById('link-tab').classList.add('active');
        } else if (tabName === 'theme') {
            document.getElementById('theme-section').style.display = 'block';
            document.getElementById('theme-tab').classList.add('active');
        } else if (tabName === 'tdk') {
            document.getElementById('tdk-section').style.display = 'block';
            document.getElementById('tdk-tab').classList.add('active');
        }
    }
    
    // 分类编辑函数
    function editCategory(id, name, sortOrder) {
        document.getElementById('edit_category_id').value = id;
        document.getElementById('edit_category_name').value = name;
        document.getElementById('edit_sort_order').value = sortOrder;
        document.getElementById('editCategoryForm').style.display = 'block';
    }
    
    // 链接编辑（修改）函数
    function editLink(id, name, url, icon, categoryId) {
        document.getElementById('edit_link_id').value = id;
        document.getElementById('edit_link_name').value = name;
        document.getElementById('edit_link_url').value = url;
        document.getElementById('edit_link_icon').value = icon;
        document.getElementById('edit_link_category_id').value = categoryId;
        document.getElementById('editLinkForm').style.display = 'block';
        
        // 滚动到编辑表单
        document.getElementById('editLinkForm').scrollIntoView({ behavior: 'smooth' });
    }
    
    // 预览主题
    function previewTheme(theme) {
        const previewContainer = document.querySelector('.preview-container');
        previewContainer.setAttribute('data-theme', theme);
        
        // 如果选择了自定义主题，显示自定义主题设置
        var customThemeSettings = document.getElementById('custom-theme-settings');
        if (customThemeSettings) {
            customThemeSettings.style.display = theme === 'custom' ? 'block' : 'none';
        }
    }
    
    // 更新颜色值显示
    document.addEventListener('DOMContentLoaded', function() {
        // 为所有颜色选择器添加事件监听
        const colorInputs = document.querySelectorAll('.color-picker-group input[type="color"]');
        colorInputs.forEach(input => {
            // 初始化时更新一次颜色值显示
            const colorValueSpan = input.nextElementSibling;
            if (colorValueSpan && colorValueSpan.classList.contains('color-value')) {
                colorValueSpan.textContent = input.value;
            }
            
            // 添加change事件监听器
            input.addEventListener('input', function() {
                const colorValueSpan = this.nextElementSibling;
                if (colorValueSpan && colorValueSpan.classList.contains('color-value')) {
                    colorValueSpan.textContent = this.value;
                }
            });
        });
    })
    
    // 按分类筛选链接
    function filterLinks() {
        const categoryId = document.getElementById('category-filter').value;
        const linkItems = document.querySelectorAll('.link-item');
        
        linkItems.forEach(item => {
            if (categoryId === '0' || item.getAttribute('data-category') === categoryId) {
                item.style.display = 'table-row';
            } else {
                item.style.display = 'none';
            }
        });
    }
    
    // 显示用户设置模态框
    function showUserSettings() {
        document.getElementById('userSettingsModal').style.display = 'block';
    }
    
    // 隐藏用户设置模态框
    function hideUserSettings() {
        document.getElementById('userSettingsModal').style.display = 'none';
    }
    
    // 显示图标选择器模态框
    function showIconSelector() {
        document.getElementById('iconSelectorModal').style.display = 'block';
        loadIcons();
    }
    
    // 隐藏图标选择器模态框
    function hideIconSelector() {
        document.getElementById('iconSelectorModal').style.display = 'none';
    }
    
    // 显示指定类型的图标
    function showIconType(type) {
        if (type === 'layui') {
            document.getElementById('layuiIcons').style.display = 'grid';
            document.getElementById('bootstrapIcons').style.display = 'none';
        } else {
            document.getElementById('layuiIcons').style.display = 'none';
            document.getElementById('bootstrapIcons').style.display = 'grid';
        }
    }
    
    // 加载图标
    function loadIcons() {
        // Layui图标列表
        const layuiIconList = [
            'layui-icon-rate-half', 'layui-icon-rate', 'layui-icon-rate-solid', 'layui-icon-cellphone', 'layui-icon-vercode',
            'layui-icon-login-wechat', 'layui-icon-login-qq', 'layui-icon-login-weibo', 'layui-icon-password', 'layui-icon-username',
            'layui-icon-refresh-3', 'layui-icon-auz', 'layui-icon-spread-left', 'layui-icon-shrink-right', 'layui-icon-snowflake',
            'layui-icon-tips', 'layui-icon-note', 'layui-icon-home', 'layui-icon-senior', 'layui-icon-refresh',
            'layui-icon-refresh-1', 'layui-icon-flag', 'layui-icon-theme', 'layui-icon-notice', 'layui-icon-website',
            'layui-icon-console', 'layui-icon-face-surprised', 'layui-icon-set', 'layui-icon-template-1', 'layui-icon-app',
            'layui-icon-template', 'layui-icon-praise', 'layui-icon-tread', 'layui-icon-male', 'layui-icon-female',
            'layui-icon-camera', 'layui-icon-camera-fill', 'layui-icon-more', 'layui-icon-more-vertical', 'layui-icon-rmb',
            'layui-icon-dollar', 'layui-icon-diamond', 'layui-icon-fire', 'layui-icon-return', 'layui-icon-location',
            'layui-icon-read', 'layui-icon-survey', 'layui-icon-face-smile', 'layui-icon-face-cry', 'layui-icon-cart-simple',
            'layui-icon-cart', 'layui-icon-next', 'layui-icon-prev', 'layui-icon-upload-drag', 'layui-icon-upload',
            'layui-icon-download-circle', 'layui-icon-component', 'layui-icon-file-b', 'layui-icon-user', 'layui-icon-find-fill',
            'layui-icon-loading', 'layui-icon-loading-1', 'layui-icon-add-1', 'layui-icon-play', 'layui-icon-pause',
            'layui-icon-headset', 'layui-icon-video', 'layui-icon-voice', 'layui-icon-speaker', 'layui-icon-fonts-del',
            'layui-icon-fonts-code', 'layui-icon-fonts-html', 'layui-icon-fonts-strong', 'layui-icon-unlink', 'layui-icon-picture',
            'layui-icon-link', 'layui-icon-face-smile-b', 'layui-icon-align-left', 'layui-icon-align-right', 'layui-icon-align-center',
            'layui-icon-fonts-u', 'layui-icon-fonts-i', 'layui-icon-tabs', 'layui-icon-radio', 'layui-icon-circle',
            'layui-icon-edit', 'layui-icon-share', 'layui-icon-delete', 'layui-icon-form', 'layui-icon-cellphone-fine',
            'layui-icon-dialogue', 'layui-icon-fonts-clear', 'layui-icon-layer', 'layui-icon-date', 'layui-icon-water',
            'layui-icon-code-circle', 'layui-icon-carousel', 'layui-icon-prev-circle', 'layui-icon-layouts', 'layui-icon-util',
            'layui-icon-templeate-1', 'layui-icon-upload-circle', 'layui-icon-tree', 'layui-icon-table', 'layui-icon-chart',
            'layui-icon-chart-screen', 'layui-icon-engine', 'layui-icon-triangle-d', 'layui-icon-triangle-r', 'layui-icon-file',
            'layui-icon-set-sm', 'layui-icon-add-circle', 'layui-icon-404', 'layui-icon-about', 'layui-icon-up',
            'layui-icon-down', 'layui-icon-left', 'layui-icon-right', 'layui-icon-circle-dot', 'layui-icon-search',
            'layui-icon-set-fill', 'layui-icon-group', 'layui-icon-friends', 'layui-icon-reply-fill', 'layui-icon-menu-fill',
            'layui-icon-log', 'layui-icon-picture-fine', 'layui-icon-face-smile-fine', 'layui-icon-list', 'layui-icon-release',
            'layui-icon-ok', 'layui-icon-help', 'layui-icon-chat', 'layui-icon-top', 'layui-icon-star',
            'layui-icon-star-fill', 'layui-icon-close-fill', 'layui-icon-close', 'layui-icon-ok-circle', 'layui-icon-add-circle-fine'
        ];
        
        // Bootstrap图标列表
        const bootstrapIconList = [
            'alarm', 'archive', 'arrow-down', 'arrow-left', 'arrow-right', 'arrow-up', 'bell', 'book', 'bookmark',
            'briefcase', 'calendar', 'camera', 'cart', 'chat', 'check', 'circle', 'clock', 'cloud', 'code', 'cog',
            'compass', 'credit-card', 'cursor', 'dash', 'diagram-3', 'diamond', 'display', 'download', 'envelope',
            'exclamation', 'eye', 'facebook', 'file', 'film', 'folder', 'gear', 'gem', 'gift', 'github', 'globe',
            'graph-up', 'grid', 'heart', 'house', 'image', 'inbox', 'info', 'instagram', 'key', 'laptop',
            'layers', 'lightning', 'link', 'list', 'lock', 'map', 'mic', 'moon', 'music-note', 'paperclip',
            'pencil', 'people', 'person', 'phone', 'pie-chart', 'pin', 'play', 'plus', 'power', 'printer',
            'question', 'reply', 'search', 'share', 'shield', 'shop', 'shuffle', 'signpost', 'star', 'sun',
            'tag', 'terminal', 'trash', 'trophy', 'truck', 'twitter', 'type', 'unlock', 'upload', 'wallet',
            'wifi', 'window', 'x', 'zoom-in', 'zoom-out'
        ];
        
        const layuiIconsContainer = document.getElementById('layuiIcons');
        const bootstrapIconsContainer = document.getElementById('bootstrapIcons');
        
        // 清空容器
        layuiIconsContainer.innerHTML = '';
        bootstrapIconsContainer.innerHTML = '';
        
        // 添加Layui图标
        layuiIconList.forEach(icon => {
            const iconElement = document.createElement('div');
            iconElement.className = 'icon-item';
            
            iconElement.innerHTML = `
                <i class="layui-icon ${icon}" style="font-size: 24px;"></i>
                <div style="font-size: 12px; margin-top: 5px; word-break: break-all;">${icon}</div>
            `;
            
            iconElement.onclick = function() {
                copyToClipboard(icon);
                // 自动填充到图标输入框
                if(document.getElementById('edit_link_icon')) {
                    document.getElementById('edit_link_icon').value = icon;
                }
                hideIconSelector();
            };
            
            layuiIconsContainer.appendChild(iconElement);
        });

        // 添加Bootstrap图标
        bootstrapIconList.forEach(icon => {
            const iconElement = document.createElement('div');
            iconElement.className = 'icon-item';
            
            iconElement.innerHTML = `
                <i class="bi bi-${icon}" style="font-size: 24px;"></i>
                <div style="font-size: 12px; margin-top: 5px;">${icon}</div>
            `;
            
            iconElement.onclick = function() {
                copyToClipboard(icon);
                // 自动填充到图标输入框
                if(document.getElementById('edit_link_icon')) {
                    document.getElementById('edit_link_icon').value = icon;
                }
                hideIconSelector();
            };
            
            bootstrapIconsContainer.appendChild(iconElement);
        });

        // 添加搜索功能
        document.getElementById('iconSearch').addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            
            // 搜索Layui图标
            document.querySelectorAll('#layuiIcons .icon-item').forEach(item => {
                const iconName = item.querySelector('div').textContent.toLowerCase();
                if (iconName.includes(searchTerm)) {
                    item.style.display = 'block';
                } else {
                    item.style.display = 'none';
                }
            });
            
            // 搜索Bootstrap图标
            document.querySelectorAll('#bootstrapIcons .icon-item').forEach(item => {
                const iconName = item.querySelector('div').textContent.toLowerCase();
                if (iconName.includes(searchTerm)) {
                    item.style.display = 'block';
                } else {
                    item.style.display = 'none';
                }
            });
        });
    }

    // 复制图标名称到剪贴板
    function copyToClipboard(text) {
        const textarea = document.createElement('textarea');
        textarea.value = text;
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        document.body.removeChild(textarea);
        
        // 使用更友好的提示
        const notification = document.createElement('div');
        notification.textContent = '已复制图标名称: ' + text;
        notification.style.position = 'fixed';
        notification.style.bottom = '20px';
        notification.style.left = '50%';
        notification.style.transform = 'translateX(-50%)';
        notification.style.padding = '10px 20px';
        notification.style.backgroundColor = 'rgba(0,0,0,0.7)';
        notification.style.color = 'white';
        notification.style.borderRadius = '4px';
        notification.style.zIndex = '9999';
        
        document.body.appendChild(notification);
        
        // 2秒后自动消失
        setTimeout(() => {
            document.body.removeChild(notification);
        }, 2000);
    }

    // 为图标名称输入框绑定点击事件
    document.addEventListener('DOMContentLoaded', function() {
        const iconInput = document.getElementById('edit_link_icon');
        if (iconInput) {
            iconInput.onclick = function() {
                showIconSelector();
            };
        }
    });

    // 图标选择逻辑
    function selectIcon(iconName) {
        const iconInput = document.getElementById('edit_link_icon');
        if (iconInput) {
            iconInput.value = iconName;
        }
        hideIconSelector();
    }

    // 修改图标选择器的点击事件处理
    document.addEventListener('DOMContentLoaded', function() {
        const iconItems = document.querySelectorAll('.icon-item');
        iconItems.forEach(item => {
            item.onclick = function() {
                const iconName = this.querySelector('div').textContent;
                selectIcon(iconName);
            };
        });
    });

    <?php if (isset($passwordError) || isset($passwordSuccess)): ?>
    // 如果有密码相关的消息，自动显示用户设置模态框
    document.addEventListener('DOMContentLoaded', function() {
        showUserSettings();
    });
    <?php endif; ?>
    </script>
</body>
</html>