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

// 获取POST数据
$data = null;
if (empty($_POST) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        log_error('JSON解析错误: ' . json_last_error_msg());
        http_response_code(400);
        die(json_encode(['error' => '无效的JSON数据']));
    }
}

// 处理主题设置
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_theme' && $_SESSION['admin_logged_in']) {
    $theme = $_POST['theme'] ?? 'light';
    $title = $_POST['title'] ?? '';
    $description = $_POST['description'] ?? '';
    $keywords = $_POST['keywords'] ?? '';
    $custom_colors = isset($_POST['custom_colors']) ? json_encode($_POST['custom_colors']) : null;
    
    // 更新配置表中的主题设置和TDK
    $stmt = $db->prepare('UPDATE config SET theme = :theme, title = :title, description = :description, keywords = :keywords, custom_colors = :custom_colors');
    $stmt->bindValue(':theme', $theme, SQLITE3_TEXT);
    $stmt->bindValue(':title', $title, SQLITE3_TEXT);
    $stmt->bindValue(':description', $description, SQLITE3_TEXT);
    $stmt->bindValue(':keywords', $keywords, SQLITE3_TEXT);
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

// 只有在接收到JSON数据时才处理
if ($data !== null) {
    // 开始事务
    $db->exec('BEGIN');
    
    try {
        // 更新配置
        $stmt = $db->prepare('UPDATE config SET title = :title, theme = :theme, description = :description, keywords = :keywords, custom_colors = :custom_colors');
        $stmt->bindValue(':title', $data['title'], SQLITE3_TEXT);
        $stmt->bindValue(':theme', $data['theme'], SQLITE3_TEXT);
        $stmt->bindValue(':description', $data['description'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':keywords', $data['keywords'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':custom_colors', isset($data['custom_colors']) ? json_encode($data['custom_colors']) : null, SQLITE3_TEXT);
        $stmt->execute();
    
        // 清空现有项目
        $db->exec('DELETE FROM items');
        
        // 插入新项目
        $stmt = $db->prepare('INSERT INTO items (name, url, icon, category_id) VALUES (:name, :url, :icon, :category_id)');
        foreach ($data['items'] as $item) {
            $stmt->bindValue(':name', $item['name'], SQLITE3_TEXT);
            $stmt->bindValue(':url', $item['url'], SQLITE3_TEXT);
            $stmt->bindValue(':icon', $item['icon'], SQLITE3_TEXT);
            $stmt->bindValue(':category_id', $item['category_id'] ?? 1, SQLITE3_INTEGER); // 默认分类ID为1
            $stmt->execute();
        }
        
        // 调试日志 - 移到事务提交前
        log_error('准备插入数据: ' . print_r($data['items'], true));
        
        // 提交事务
        $db->exec('COMMIT');
        
        // 确保数据已写入磁盘
        $db->close();
        
        http_response_code(200);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        // 回滚事务
        $db->exec('ROLLBACK');
        log_error('保存失败: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => '保存失败: ' . $e->getMessage()]);
    }
}

