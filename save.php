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
$data = json_decode(file_get_contents('php://input'), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    log_error('JSON解析错误: ' . json_last_error_msg());
    http_response_code(400);
    die(json_encode(['error' => '无效的JSON数据']));
}

// 开始事务
$db->exec('BEGIN');

try {
    // 更新配置
    $stmt = $db->prepare('UPDATE config SET title = :title, theme = :theme');
    $stmt->bindValue(':title', $data['title'], SQLITE3_TEXT);
    $stmt->bindValue(':theme', $data['theme'], SQLITE3_TEXT);
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
    echo json_encode(['error' => $e->getMessage()]);
}