<?php
// 设置错误报告
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 错误日志记录
function log_error($message) {
    file_put_contents('error.log', date('Y-m-d H:i:s') . ' - ' . $message . "\n", FILE_APPEND);
    echo $message . "<br>";
}

echo "<h2>数据库修复工具</h2>";

// 确保data目录存在且有写入权限
if (!is_dir('data')) {
    mkdir('data', 0755, true);
    echo "创建data目录成功<br>";
}

// 连接数据库
$db = new SQLite3('data/nav.db');
if (!$db) {
    log_error('数据库连接失败: ' . $db->lastErrorMsg());
    die('数据库连接失败');
}
echo "数据库连接成功<br>";

// 检查并修复表结构
echo "<h3>检查并修复表结构</h3>";

// 检查config表是否有description、keywords和custom_colors列
$hasDescription = false;
$hasKeywords = false;
$hasCustomColors = false;
$result = $db->query("PRAGMA table_info(config)");
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    if ($row['name'] === 'description') {
        $hasDescription = true;
    }
    if ($row['name'] === 'keywords') {
        $hasKeywords = true;
    }
    if ($row['name'] === 'custom_colors') {
        $hasCustomColors = true;
    }
}

if (!$hasDescription || !$hasKeywords || !$hasCustomColors) {
    echo "config表缺少description、keywords或custom_colors列，正在添加...<br>";
    $db->exec('BEGIN TRANSACTION');
    try {
        // 创建新表结构
        $db->exec('CREATE TABLE config_new (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            theme TEXT NOT NULL,
            description TEXT,
            keywords TEXT,
            custom_colors TEXT
        )');
        
        // 复制数据
        $db->exec('INSERT INTO config_new (id, title, theme, description, keywords, custom_colors) 
                  SELECT id, title, theme, 
                  COALESCE(description, ""), 
                  COALESCE(keywords, ""), 
                  NULL FROM config');
        
        // 删除旧表
        $db->exec('DROP TABLE config');
        
        // 重命名新表
        $db->exec('ALTER TABLE config_new RENAME TO config');
        
        $db->exec('COMMIT');
        echo "成功添加缺少的列<br>";
    } catch (Exception $e) {
        $db->exec('ROLLBACK');
        log_error('修复表结构失败: ' . $e->getMessage());
        die('修复表结构失败');
    }
} else {
    echo "config表结构正常<br>";
}

// 检查items表是否有category_id列
$hasColumn = false;
$result = $db->query("PRAGMA table_info(items)");
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    if ($row['name'] === 'category_id') {
        $hasColumn = true;
        break;
    }
}

if (!$hasColumn) {
    echo "items表缺少category_id列，正在添加...<br>";
    // 创建临时表
    $db->exec('BEGIN TRANSACTION');
    try {
        // 创建新表结构
        $db->exec('CREATE TABLE items_new (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            url TEXT NOT NULL,
            icon TEXT NOT NULL,
            category_id INTEGER DEFAULT 1,
            FOREIGN KEY (category_id) REFERENCES categories(id)
        )');
        
        // 复制数据
        $db->exec('INSERT INTO items_new (id, name, url, icon, category_id) 
                  SELECT id, name, url, icon, 1 FROM items');
        
        // 删除旧表
        $db->exec('DROP TABLE items');
        
        // 重命名新表
        $db->exec('ALTER TABLE items_new RENAME TO items');
        
        $db->exec('COMMIT');
        echo "成功添加category_id列<br>";
    } catch (Exception $e) {
        $db->exec('ROLLBACK');
        log_error('修复表结构失败: ' . $e->getMessage());
        die('修复表结构失败');
    }
} else {
    echo "items表结构正常<br>";
}

// 检查是否有默认分类
$result = $db->querySingle('SELECT COUNT(*) FROM categories', true);
if ($result['COUNT(*)'] == 0) {
    echo "没有默认分类，正在创建...<br>";
    $db->exec("INSERT INTO categories (name, sort_order) VALUES ('默认分类', 0)");
    echo "创建默认分类成功<br>";
} else {
    echo "分类数据正常<br>";
}

// 重置管理员密码
echo "<h3>重置管理员密码</h3>";
$defaultPassword = password_hash('admin123', PASSWORD_DEFAULT);
$db->exec("UPDATE users SET password = '$defaultPassword' WHERE username = 'admin'");
echo "管理员密码已重置！<br>";

echo "<h3>修复完成</h3>";
echo "<p>请返回 <a href='admin.php'>管理页面</a> 尝试登录</p>";
?>