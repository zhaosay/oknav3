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

// 检查并创建tdk_config表
$result = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='tdk_config'");
$hasTdkConfigTable = ($result->fetchArray(SQLITE3_ASSOC) !== false);

if (!$hasTdkConfigTable) {
    echo "创建tdk_config表...<br>";
    $db->exec('BEGIN TRANSACTION');
    try {
        // 创建TDK配置表
        $db->exec('CREATE TABLE tdk_config (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL DEFAULT "我的导航",
            description TEXT DEFAULT "",
            keywords TEXT DEFAULT ""
        )');
        
        // 从config表或theme_customconfig表复制数据（如果存在）
        $configExists = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='config'");
        $customConfigExists = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='theme_customconfig'");
        
        if ($configExists->fetchArray(SQLITE3_ASSOC) !== false) {
            $db->exec('INSERT INTO tdk_config (title, description, keywords) 
                      SELECT title, COALESCE(description, ""), COALESCE(keywords, "") FROM config LIMIT 1');
        } else if ($customConfigExists->fetchArray(SQLITE3_ASSOC) !== false) {
            $db->exec('INSERT INTO tdk_config (title, description, keywords) 
                      SELECT title, COALESCE(description, ""), COALESCE(keywords, "") FROM theme_customconfig LIMIT 1');
        } else {
            // 插入默认数据
            $db->exec("INSERT INTO tdk_config (title, description, keywords) VALUES ('我的导航', '', '')");
        }
        
        $db->exec('COMMIT');
        echo "成功创建tdk_config表<br>";
    } catch (Exception $e) {
        $db->exec('ROLLBACK');
        log_error('创建tdk_config表失败: ' . $e->getMessage());
        die('创建tdk_config表失败');
    }
} else {
    echo "tdk_config表已存在<br>";
}

// 检查并创建theme_config表
$result = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='theme_config'");
$hasThemeConfigTable = ($result->fetchArray(SQLITE3_ASSOC) !== false);

if (!$hasThemeConfigTable) {
    echo "创建theme_config表...<br>";
    $db->exec('BEGIN TRANSACTION');
    try {
        // 创建主题配置表
        $db->exec('CREATE TABLE theme_config (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            theme TEXT NOT NULL DEFAULT "light",
            custom_colors TEXT,
            show_admin_icon INTEGER DEFAULT 1
        )');
        
        // 从config表或theme_customconfig表复制数据（如果存在）
        $configExists = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='config'");
        $customConfigExists = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='theme_customconfig'");
        
        if ($configExists->fetchArray(SQLITE3_ASSOC) !== false) {
            $db->exec('INSERT INTO theme_config (theme, custom_colors) 
                      SELECT theme, custom_colors FROM config LIMIT 1');
        } else if ($customConfigExists->fetchArray(SQLITE3_ASSOC) !== false) {
            $db->exec('INSERT INTO theme_config (theme, custom_colors) 
                      SELECT theme, custom_colors FROM theme_customconfig LIMIT 1');
        } 
        else {
            // 插入默认数据
            $db->exec("INSERT INTO theme_config (theme, custom_colors) VALUES ('light', NULL)");
        }
        
        $db->exec('COMMIT');
        echo "成功创建theme_config表<br>";
    } 
    catch (Exception $e) {
        $db->exec('ROLLBACK');
        log_error('创建theme_config表失败: ' . $e->getMessage());
        die('创建theme_config表失败');
    }
} else {
    echo "theme_config表已存在<br>";
    
    // 检查theme_config表是否有show_admin_icon列
    $hasShowAdminIconField = false;
    $result = $db->query("PRAGMA table_info(theme_config)");
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        if ($row['name'] === 'show_admin_icon') {
            $hasShowAdminIconField = true;
            break;
        }
    }
    
    // 如果缺少show_admin_icon列，添加该列
    if (!$hasShowAdminIconField) {
        echo "theme_config表缺少show_admin_icon列，正在添加...<br>";
        try {
            $db->exec("ALTER TABLE theme_config ADD COLUMN show_admin_icon INTEGER DEFAULT 1");
            echo "成功添加show_admin_icon列<br>";
        } catch (Exception $e) {
            log_error('添加show_admin_icon列失败: ' . $e->getMessage());
            echo "添加show_admin_icon列失败: {$e->getMessage()}<br>";
        }
    }
}

// 检查是否需要将config表改名为theme_customconfig表
$configExists = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='config'");
$customConfigExists = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='theme_customconfig'");

if ($configExists->fetchArray(SQLITE3_ASSOC) !== false && $customConfigExists->fetchArray(SQLITE3_ASSOC) === false) {
    echo "将config表改名为theme_customconfig表...<br>";
    try {
        $db->exec('BEGIN TRANSACTION');
        $db->exec('ALTER TABLE config RENAME TO theme_customconfig');
        $db->exec('COMMIT');
        echo "成功将config表改名为theme_customconfig表<br>";
    } catch (Exception $e) {
        $db->exec('ROLLBACK');
        log_error('将config表改名为theme_customconfig表失败: ' . $e->getMessage());
        echo "将config表改名为theme_customconfig表失败: {$e->getMessage()}<br>";
    }
}

// 检查items表是否有description列、created_at和updated_at列
$hasDescriptionField = false;
$hasUpdatedAtField = false;
$hasCreatedAtField = false;
$result = $db->query("PRAGMA table_info(items)");
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    if ($row['name'] === 'description') {
        $hasDescriptionField = true;
    }
    if ($row['name'] === 'updated_at') {
        $hasUpdatedAtField = true;
    }
    if ($row['name'] === 'created_at') {
        $hasCreatedAtField = true;
    }
}

// 如果缺少description列但有时间戳列，只添加description列
if (!$hasDescriptionField && $hasUpdatedAtField && $hasCreatedAtField) {
    echo "items表缺少description列，正在添加...<br>";
    $db->exec("ALTER TABLE items ADD COLUMN description TEXT");
    echo "成功添加description列<br>";
}

// 如果缺少时间戳列，重建整个表结构
if (!$hasUpdatedAtField || !$hasCreatedAtField) {
    echo "items表缺少时间戳列，正在修复...<br>";
    try {
        // 创建临时表
        $db->exec('BEGIN TRANSACTION');
        $db->exec('CREATE TABLE items_temp AS SELECT * FROM items');
        $db->exec('DROP TABLE items');
        $db->exec('CREATE TABLE items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            url TEXT NOT NULL,
            icon TEXT NOT NULL,
            category_id INTEGER DEFAULT 1,
            sort_order INTEGER DEFAULT 0,
            description TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (category_id) REFERENCES categories(id)
        )');
        $db->exec('INSERT INTO items (id, name, url, icon, category_id, sort_order, description) 
                  SELECT id, name, url, icon, category_id, COALESCE(sort_order, 0), description FROM items_temp');
        $db->exec('DROP TABLE items_temp');
        $db->exec('COMMIT');
        echo "成功修复items表结构<br>";
    } catch (Exception $e) {
        $db->exec('ROLLBACK');
        log_error('修复items表结构失败: ' . $e->getMessage());
        echo "修复items表结构失败: {$e->getMessage()}<br>";
    }
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

// 检查categories表是否有created_at和updated_at列
$result = $db->query("PRAGMA table_info(categories)");
$hasUpdatedAtField = false;
$hasCreatedAtField = false;
while ($column = $result->fetchArray(SQLITE3_ASSOC)) {
    if ($column['name'] === 'updated_at') {
        $hasUpdatedAtField = true;
    }
    if ($column['name'] === 'created_at') {
        $hasCreatedAtField = true;
    }
}

// 如果缺少时间戳列，重建表结构
if (!$hasUpdatedAtField || !$hasCreatedAtField) {
    echo "categories表缺少时间戳列，正在修复...<br>";
    try {
        // 创建临时表
        $db->exec('BEGIN TRANSACTION');
        $db->exec('CREATE TABLE categories_temp AS SELECT * FROM categories');
        $db->exec('DROP TABLE categories');
        $db->exec('CREATE TABLE categories (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            sort_order INTEGER DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )');
        $db->exec('INSERT INTO categories (id, name, sort_order) 
                  SELECT id, name, sort_order FROM categories_temp');
        $db->exec('DROP TABLE categories_temp');
        $db->exec('COMMIT');
        echo "成功修复categories表结构<br>";
    } catch (Exception $e) {
        $db->exec('ROLLBACK');
        log_error('修复categories表结构失败: ' . $e->getMessage());
        echo "修复categories表结构失败: {$e->getMessage()}<br>";
    }
}

// 检查是否有默认分类
$result = $db->querySingle('SELECT COUNT(*) FROM categories', true);
if ($result['COUNT(*)'] == 0) {
    echo "没有默认分类，正在创建categories表...<br>";
    $db->exec("INSERT INTO categories (name, sort_order) VALUES ('默认分类', 0)");
    echo "创建默认分类categories表成功<br>";
} else {
    echo "分类数据categories表正常<br>";
}

// 重置所有用户密码
echo "<h3>重置所有用户密码</h3>";
echo "<p>所有用户账户的密码将被重置为admin123</p>";
$defaultPassword = password_hash('admin123', PASSWORD_DEFAULT);
$stmt = $db->prepare("UPDATE users SET password = :password");
$stmt->bindValue(':password', $defaultPassword, SQLITE3_TEXT);
$stmt->execute();
echo "所有用户密码已重置！<br>";


echo "<h3>修复完成</h3>";
echo "<p>请返回 <a href='admin.php'>管理页面</a> 尝试登录</p>";
echo "<h3>如果修复完成依旧有问题存在，多刷新几遍这个文件！</h3>";
?>