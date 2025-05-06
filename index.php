<?php
// 设置session保存路径到当前目录下的sessions文件夹
if (!is_dir('sessions')) {
    mkdir('sessions', 0755, true);
}
session_save_path('sessions');

// 数据库连接
$db = new SQLite3('data/nav.db');

// 初始化数据库
$db->exec('CREATE TABLE IF NOT EXISTS config (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    theme TEXT NOT NULL,
    description TEXT,  
    keywords TEXT      
)');



$db->exec('CREATE TABLE IF NOT EXISTS categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    sort_order INTEGER DEFAULT 0
)');

$db->exec('CREATE TABLE IF NOT EXISTS items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    url TEXT NOT NULL,
    icon TEXT NOT NULL,
    category_id INTEGER DEFAULT 1,
    FOREIGN KEY (category_id) REFERENCES categories(id)
)');

$db->exec('CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    password TEXT NOT NULL
)');

// 创建config表（包含TDK和自定义主题字段）
$db->exec('CREATE TABLE IF NOT EXISTS config (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    theme TEXT NOT NULL,
    description TEXT,
    keywords TEXT,
    custom_colors TEXT  -- 用于存储自定义配色
)');

// 读取配置
$config = ['title' => '我的导航', 'theme' => 'light', 'description' => '', 'keywords' => '', 'custom_colors' => null, 'items' => []];

// 检查是否有配置数据
$result = $db->querySingle('SELECT COUNT(*) FROM config', true);
if ($result['COUNT(*)'] == 0) {
    // 插入默认配置
    $db->exec("INSERT INTO config (title, theme) VALUES ('我的导航', 'light')");
    
    // 插入默认项目
    $defaultItems = [
        ['name' => '三五二萌文网', 'url' => 'https://www.352m.com', 'icon' => 'search'],
        ['name' => 'Ok导航-OkNav', 'url' => 'https://cc.352m.com', 'icon' => 'search']
    ];
    
    foreach ($defaultItems as $item) {
        $db->exec("INSERT INTO items (name, url, icon, category_id) VALUES ('" . 
            $db->escapeString($item['name']) . "', '" . 
            $db->escapeString($item['url']) . "', '" . 
            $db->escapeString($item['icon']) . "', 1)");
    }
}

// 获取当前配置
$configRow = $db->querySingle('SELECT * FROM config LIMIT 1', true);
$config['title'] = $configRow['title'];
$config['theme'] = $configRow['theme'];
$config['description'] = $configRow['description'] ?? '';  // 获取description
$config['keywords'] = $configRow['keywords'] ?? '';        // 获取keywords
$config['custom_colors'] = $configRow['custom_colors'] ?? null;  // 获取自定义主题配色

// 获取分类列表
$categoriesResult = $db->query('SELECT * FROM categories ORDER BY sort_order ASC');
$config['categories'] = [];
while ($category = $categoriesResult->fetchArray(SQLITE3_ASSOC)) {
    $config['categories'][] = $category;
}

// 获取项目列表（按分类组织）
$config['items'] = [];
foreach ($config['categories'] as $category) {
    $categoryId = $category['id'];
    $stmt = $db->prepare('SELECT * FROM items WHERE category_id = :category_id');
    $stmt->bindValue(':category_id', $categoryId, SQLITE3_INTEGER);
    $itemsResult = $stmt->execute();
    while ($item = $itemsResult->fetchArray(SQLITE3_ASSOC)) {
        $config['items'][] = $item;
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN" data-theme="<?= $config['theme'] ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="description" content="<?= htmlspecialchars($config['description']) ?>">  <!-- 输出description -->
    <meta name="keywords" content="<?= htmlspecialchars($config['keywords']) ?>">        <!-- 输出keywords -->
    <title><?= htmlspecialchars($config['title']) ?></title>
    <link rel="stylesheet" href="bootstrap-icons/font/bootstrap-icons.css">
    <link rel="stylesheet" href="layui/css/layui.css">
    <link rel="stylesheet" href="style.css">
    <link rel="icon" href="bootstrap-icons/reception-4.svg" type="image/svg+xml">
    <style>
        <?php if ($config['theme'] === 'custom' && !empty($config['custom_colors'])): 
            $colors = json_decode($config['custom_colors'], true);
            if ($colors && is_array($colors)): ?>
        :root {
            --bg-color: <?= $colors['bg-color'] ?? '#f5f7fa' ?>;
            --text-color: <?= $colors['text-color'] ?? '#333' ?>;
            --primary-color: <?= $colors['primary-color'] ?? '#4285f4' ?>;
            --card-bg: <?= $colors['card-bg'] ?? '#fff' ?>;
            --shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }
        <?php endif; 
        endif; ?>
        
        .nav-container {
            display: flex;
            flex-direction: column;
            gap: 25px;
            width: 100%;
            min-height: 70vh;
        }
        .category-section {
            margin-bottom: 15px;
            animation: fadeIn 0.5s ease-in-out;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .category-title {
            font-size: clamp(1.2rem, 3vw, 1.5rem);
            margin-bottom: 15px;
            padding-bottom: 5px;
            border-bottom: 2px solid #eee;
            color: #333;
            transition: var(--transition);
        }
        .admin-actions {
            display: flex;
            gap: 15px;
            margin-top: 25px;
            justify-content: center;
            flex-wrap: wrap;
        }
        .admin-btn {
            background-color: #6c757d;
            color: white;
            min-width: 120px;
        }
        .admin-btn:hover {
            background-color: #5a6268;
        }
        .item-category {
            flex: 1;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: var(--border-radius);
        }
        .link-item {
            display: flex;
            gap: 10px;
            margin-bottom: 10px;
            align-items: center;
        }
        .link-item input, .link-item select {
            padding: 8px;
            border-radius: 5px;
        }
        [data-theme="dark"] .category-title {
            color: #eee;
            border-bottom-color: #555;
        }
        .site-footer {
            text-align: center;
            margin-top: 30px;
            padding: 15px 0;
            font-size: 0.9rem;
            color: var(--text-color);
            opacity: 0.7;
        }
        @media (max-width: 480px) {
            .site-footer {
                margin-top: 20px;
                padding: 10px 0;
                font-size: 0.8rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1 class="site-title"><?= $config['title'] ?></h1>
        </header>
        
        <main class="nav-container">
            <?php foreach ($config['categories'] as $category): ?>
            <div class="category-section">
                <h2 class="category-title"><?= htmlspecialchars($category['name']) ?></h2>
                <div class="nav-grid">
                    <?php 
                    $categoryItems = array_filter($config['items'], function($item) use ($category) {
                        return $item['category_id'] == $category['id'];
                    });
                    foreach ($categoryItems as $item): 
                    ?>
                    <a href="<?= $item['url'] ?>" class="nav-item" target="_blank">
                        <?php if (strpos($item['icon'], 'layui-icon-') !== false): ?>
                        <i class="layui-icon <?= $item['icon'] ?>"></i>
                        <?php else: ?>
                        <i class="bi bi-<?= $item['icon'] ?>"></i>
                        <?php endif; ?>
                        <span><?= $item['name'] ?></span>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
            
            <div class="admin-actions">
                <a href="admin.php" class="nav-item admin-btn" aria-label="管理">
                    <i class="bi bi-gear"></i>
                    <span>管理</span>
                </a>
            </div>
        </main>
        
        <footer class="site-footer">
            <p>© <?= date('Y') ?> <?= $config['title'] ?></p>
        </footer>
    </div>



    <script>
    // 页面加载完成后执行
    document.addEventListener('DOMContentLoaded', function() {
        // 为所有导航项添加延迟加载动画
        const navItems = document.querySelectorAll('.nav-item');
        navItems.forEach((item, index) => {
            item.style.opacity = '0';
            item.style.transform = 'translateY(20px)';
            setTimeout(() => {
                item.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
                item.style.opacity = '1';
                item.style.transform = 'translateY(0)';
            }, 50 * index);
        });
        
        // 检测设备类型
        const isMobile = /iPhone|iPad|iPod|Android/i.test(navigator.userAgent);
        if (isMobile) {
            document.body.classList.add('mobile-device');
        }
        
        // 添加主题切换效果
        const htmlElement = document.documentElement;
        htmlElement.classList.add('theme-transition');
    });
    
    // 显示编辑模态框
    function showEditModal() {
        const modal = document.getElementById('editModal');
        modal.style.display = 'flex';
        setTimeout(() => {
            modal.classList.add('modal-active');
        }, 10);
        
        // 防止背景滚动
        document.body.style.overflow = 'hidden';
    }
    
    // 隐藏编辑模态框
    function hideEditModal() {
        const modal = document.getElementById('editModal');
        modal.classList.remove('modal-active');
        setTimeout(() => {
            modal.style.display = 'none';
        }, 300);
        
        // 恢复背景滚动
        document.body.style.overflow = '';
    }
    
    // 添加链接项
    function addLink() {
        const container = document.getElementById('linkItems');
        const index = container.children.length;
        const div = document.createElement('div');
        div.className = 'link-item';
        
        // 获取分类选项HTML
        const categorySelect = document.getElementById('default_category');
        const categoryOptions = categorySelect.innerHTML;
        
        div.innerHTML = `
            <input type="text" name="items[${index}][name]" placeholder="名称" required>
            <input type="url" name="items[${index}][url]" placeholder="网址" required>
            <input type="text" name="items[${index}][icon]" placeholder="图标" required>
            <select name="items[${index}][category_id]" class="item-category">
                ${categoryOptions}
            </select>
            <button type="button" class="delete-btn" onclick="removeLink(this)" aria-label="删除项目">
                <i class="bi bi-trash"></i>
            </button>
        `;
        
        // 添加动画效果
        div.style.opacity = '0';
        div.style.transform = 'translateY(10px)';
        container.appendChild(div);
        
        // 触发重排后添加过渡效果
        setTimeout(() => {
            div.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
            div.style.opacity = '1';
            div.style.transform = 'translateY(0)';
        }, 10);
        
        // 自动滚动到新添加的项目
        setTimeout(() => {
            div.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }, 100);
    }
    
    // 删除链接项
    function removeLink(btn) {
        const item = btn.parentElement;
        
        // 添加删除动画
        item.style.transition = 'opacity 0.3s ease, transform 0.3s ease, height 0.3s ease, margin 0.3s ease, padding 0.3s ease';
        item.style.opacity = '0';
        item.style.transform = 'translateY(10px)';
        item.style.overflow = 'hidden';
        
        setTimeout(() => {
            item.remove();
            
            // 重新索引表单元素
            const items = document.querySelectorAll('#linkItems .link-item');
            items.forEach((item, index) => {
                item.querySelectorAll('input, select').forEach(input => {
                    const nameAttr = input.name;
                    if (nameAttr) {
                        input.name = nameAttr.replace(/\[\d+\]/, `[${index}]`);
                    }
                });
            });
        }, 300);
    }
    
    // 保存配置
    async function saveConfig(e) {
        e.preventDefault();
        
        // 显示加载状态
        const submitBtn = document.querySelector('#editForm button[type="submit"]');
        const originalText = submitBtn.innerHTML;
        submitBtn.innerHTML = '<i class="bi bi-arrow-repeat spin"></i> 保存中...';
        submitBtn.disabled = true;
        
        const form = document.getElementById('editForm');
        const formData = new FormData(form);
        
        const data = {
            title: formData.get('title'),
            theme: formData.get('theme'),
            items: []
        };
        
        // 收集链接项
        const linkItems = document.querySelectorAll('#linkItems .link-item');
        for (let i = 0; i < linkItems.length; i++) {
            const nameInput = linkItems[i].querySelector('input[name$="[name]"]');
            const urlInput = linkItems[i].querySelector('input[name$="[url]"]');
            const iconInput = linkItems[i].querySelector('input[name$="[icon]"]');
            const categorySelect = linkItems[i].querySelector('select[name$="[category_id]"]');
            
            if (nameInput && urlInput && iconInput && categorySelect) {
                data.items.push({
                    name: nameInput.value,
                    url: urlInput.value,
                    icon: iconInput.value,
                    category_id: parseInt(categorySelect.value)
                });
            }
        }
        
        try {
            const response = await fetch('save.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(data)
            });
            
            const result = await response.json();
            
            if (result.success) {
                // 显示成功消息
                showToast('保存成功！', 'success');
                
                // 延迟刷新页面，让用户看到成功消息
                setTimeout(() => {
                    location.reload();
                }, 1000);
            } else {
                showToast('保存失败：' + (result.error || '未知错误'), 'error');
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            }
        } catch (error) {
            console.error('Error:', error);
            showToast('保存出错：' + (error.message || '未知错误'), 'error');
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        }
    }
    
    // 显示提示消息
    function showToast(message, type = 'info') {
        // 移除现有的toast
        const existingToasts = document.querySelectorAll('.toast');
        existingToasts.forEach(toast => toast.remove());
        
        // 创建toast元素
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.innerHTML = message;
        document.body.appendChild(toast);
        
        // 显示动画
        setTimeout(() => {
            toast.classList.add('show');
            
            // 自动隐藏
            setTimeout(() => {
                toast.classList.remove('show');
                setTimeout(() => {
                    toast.remove();
                }, 300);
            }, 3000);
        }, 10);
    }
    </script>
</body>
</html>