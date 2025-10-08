<?php
session_start();
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/../config.php';
$message = '';
$error_message = '';

// --- Functions ---
function get_pdo_connection() {
    global $db_config;
    if (empty($db_config)) throw new Exception("数据库配置 (config.php) 丢失或为空。");
    try {
        if ($db_config['type'] === 'pgsql') {
            $cfg = $db_config['pgsql'];
            $dsn = "pgsql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['dbname']}";
            $pdo = new PDO($dsn, $cfg['user'], $cfg['password']);
        } elseif ($db_config['type'] === 'mysql') {
            $cfg = $db_config['mysql'];
            $dsn = "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['dbname']};charset=utf8mb4";
            $pdo = new PDO($dsn, $cfg['user'], $cfg['password']);
        } else { // sqlite
            $dsn = 'sqlite:' . $db_config['sqlite']['path'];
            $pdo = new PDO($dsn);
            $pdo->exec('PRAGMA journal_mode = WAL;');
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch (PDOException $e) {
        throw new Exception("数据库连接失败: " . $e->getMessage());
    }
}

function generate_secret_key($length = 32) {
    return bin2hex(random_bytes($length / 2));
}

// --- Main Logic ---
try {
    $pdo = get_pdo_connection();

    // Handle Server Deletion
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_server'])) {
        $pdo->beginTransaction();
        $stmt_del_server = $pdo->prepare("DELETE FROM servers WHERE id = ?");
        $stmt_del_server->execute([$_POST['id']]);
        $stmt_del_stats = $pdo->prepare("DELETE FROM server_stats WHERE server_id = ?");
        $stmt_del_stats->execute([$_POST['id']]);
        $stmt_del_status = $pdo->prepare("DELETE FROM server_status WHERE id = ?");
        $stmt_del_status->execute([$_POST['id']]);
        $pdo->commit();
        $message = "服务器 '{$_POST['id']}' 及其所有数据已成功删除！";
    }

    // Handle New Secret Generation
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_secret'])) {
        $new_secret = generate_secret_key();
        $stmt = $pdo->prepare("UPDATE servers SET secret = ? WHERE id = ?");
        $stmt->execute([$new_secret, $_POST['generate_secret_id']]);
        $message = "为服务器 '{$_POST['generate_secret_id']}' 生成了新的密钥！";
    }
    
    // Handle Server Creation/Update
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_server'])) {
        $id = trim($_POST['id']);
        $is_editing = !empty($_POST['is_editing']);
        
        if (!$is_editing) {
            $stmt_check = $pdo->prepare("SELECT id FROM servers WHERE id = ?");
            $stmt_check->execute([$id]);
            if ($stmt_check->fetch()) {
                 throw new Exception("服务器 ID '{$id}' 已存在，请使用不同的ID。");
            }
        }
        
        $country_code = strtoupper(trim($_POST['country_code']));
        if (!empty($country_code) && !preg_match('/^[A-Z]{2}$/', $country_code)) {
            throw new Exception("国家代码必须是两位英文字母。");
        }

        $tags = !empty($_POST['tags']) ? implode(',', array_map('trim', explode(',', $_POST['tags']))) : null;
        $ip = !empty($_POST['ip']) ? trim($_POST['ip']) : null;
        
        if ($is_editing) {
            $sql = "UPDATE servers SET name = ?, ip = ?, latitude = ?, longitude = ?, intro = ?, tags = ?, country_code = ? WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$_POST['name'], $ip, $_POST['latitude'], $_POST['longitude'], $_POST['intro'], $tags, $country_code, $id]);
            $message = "服务器 '{$_POST['name']}' 已成功更新！";
        } else {
            $secret = generate_secret_key();
            $sql = "INSERT INTO servers (id, name, ip, latitude, longitude, intro, tags, country_code, secret) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$id, $_POST['name'], $ip, $_POST['latitude'], $_POST['longitude'], $_POST['intro'], $tags, $country_code, $secret]);
            $message = "服务器 '{$_POST['name']}' 已成功添加！";
        }
    }
    
    // Handle Settings Update
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
        $key_column = ($db_config['type'] === 'mysql') ? '`key_name`' : 'key';
        
        $sql = ($db_config['type'] === 'pgsql') 
             ? "INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value"
             : "INSERT INTO settings ({$key_column}, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)";
        if($db_config['type'] === 'sqlite') {
            $sql = "INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)";
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute(['site_name', $_POST['site_name']]);
        $stmt->execute(['telegram_bot_token', $_POST['telegram_bot_token']]);
        $stmt->execute(['telegram_chat_id', $_POST['telegram_chat_id']]);
        $message = "通用设置已保存！";
    }

    // Fetch data for display
    $servers = $pdo->query("SELECT * FROM servers ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    
    $key_column = ($db_config['type'] === 'mysql') ? '`key_name`' : 'key';
    $settings_stmt = $pdo->query("SELECT {$key_column}, value FROM settings");
    $settings = $settings_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $site_name = $settings['site_name'] ?? 'Pinty Monitor';
    $telegram_bot_token = $settings['telegram_bot_token'] ?? '';
    $telegram_chat_id = $settings['telegram_chat_id'] ?? '';

} catch (Exception $e) {
    $error_message = "操作失败: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>管理员面板 - Pinty Monitor</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; background-color: #f0f2f5; margin: 0; padding: 2rem; color: #333; }
        .container { max-width: 1200px; margin: 0 auto; background: #fff; padding: 2rem; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
        .header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #dee2e6; padding-bottom: 1rem; margin-bottom: 2rem; }
        h1, h2 { color: #111; margin-top: 0; }
        h2 { border-bottom: 1px solid #eee; padding-bottom: 0.5rem; margin-top: 2rem; }
        a.logout { text-decoration: none; background: #343a40; color: #fff; padding: 0.5rem 1rem; border-radius: 5px; transition: background 0.2s; }
        a.logout:hover { background: #495057; }
        .message { background: #28a745; color: #fff; padding: 1rem; border-radius: 5px; margin-bottom: 1rem; }
        .error-message { background: #dc3545; color: #fff; padding: 1rem; border-radius: 5px; margin-bottom: 1rem; }
        table { width: 100%; border-collapse: collapse; margin-top: 1.5rem; font-size: 0.9em; }
        th, td { text-align: left; padding: 0.9rem 0.7rem; border-bottom: 1px solid #dee2e6; vertical-align: middle; }
        th { background-color: #f8f9fa; font-weight: 600; }
        tr:hover { background-color: #f8f9fa; }
        form { margin: 0; }
        label { font-weight: 600; display: block; margin-bottom: 0.4rem; font-size: 0.9em; }
        input[type="text"], input[type="number"], textarea, select { width: 100%; padding: 0.6rem; border: 1px solid #ced4da; border-radius: 4px; box-sizing: border-box; transition: border-color 0.2s, box-shadow 0.2s; }
        input:focus, textarea:focus, select:focus { border-color: #80bdff; outline: 0; box-shadow: 0 0 0 0.2rem rgba(0,123,255,.25); }
        input[readonly] { background: #e9ecef; cursor: not-allowed; }
        button { background-color: #007bff; color: #fff; border: none; padding: 0.6rem 1.2rem; border-radius: 5px; cursor: pointer; transition: background-color 0.2s; font-weight: 600; }
        button:hover { background-color: #0056b3; }
        button.delete { background-color: #dc3545; }
        button.delete:hover { background-color: #c82333; }
        button.secondary { background-color: #6c757d; }
        button.secondary:hover { background-color: #5a6268; }
        .section { margin-bottom: 3rem; }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1.2rem; align-items: flex-start; margin-bottom: 1.2rem; }
        details { border: 1px solid #dee2e6; padding: 1.5rem; border-radius: 5px; margin-bottom: 1rem; background: #fdfdfd; }
        summary { font-weight: 600; cursor: pointer; font-size: 1.1em; }
        .actions-cell { display: flex; flex-wrap: wrap; gap: 0.5rem; }
        .secret-wrapper { display: flex; align-items: center; gap: 0.5rem; }
        .secret-wrapper input { flex-grow: 1; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>管理员面板</h1>
            <a href="logout.php" class="logout">登出</a>
        </div>
        
        <?php if ($message): ?><p class="message"><?php echo htmlspecialchars($message); ?></p><?php endif; ?>
        <?php if ($error_message): ?><p class="error-message"><?php echo htmlspecialchars($error_message); ?></p><?php endif; ?>

        <div class="section">
            <details>
                <summary>通用设置</summary>
                <form action="dashboard.php" method="post" style="margin-top: 1.5rem;">
                    <div class="form-grid">
                        <div><label for="site_name">站点名称</label><input id="site_name" type="text" name="site_name" value="<?php echo htmlspecialchars($site_name); ?>"></div>
                    </div>
                     <p style="margin-top: 2rem;">当服务器掉线时，系统会自动发送通知。请按照部署指南获取Token和Chat ID。</p>
                    <div class="form-grid">
                        <div><label for="tg-token">Telegram Bot Token</label><input id="tg-token" type="text" name="telegram_bot_token" value="<?php echo htmlspecialchars($telegram_bot_token); ?>" placeholder="例如: 123456:ABC-DEF..."></div>
                        <div><label for="tg-chat">Telegram Channel/User ID</label><input id="tg-chat" type="text" name="telegram_chat_id" value="<?php echo htmlspecialchars($telegram_chat_id); ?>" placeholder="例如: -100123456789 or 12345678"></div>
                    </div>
                    <button type="submit" name="save_settings">保存设置</button>
                </form>
            </details>
        </div>

        <div class="section">
            <details id="add-edit-details">
                <summary>添加新服务器</summary>
                <form id="add-edit-form" action="dashboard.php" method="post" style="margin-top: 1.5rem;">
                    <input type="hidden" name="is_editing" value="">
                    <div class="form-grid">
                        <div><label>服务器 ID (唯一, 英文)</label><input type="text" name="id" required></div>
                        <div><label>服务器名称</label><input type="text" name="name" required></div>
                        <div><label>国家/地区代码 (两位字母)</label><input type="text" name="country_code" placeholder="例如: CN, JP, US" maxlength="2" style="text-transform:uppercase"></div>
                        <div><label>服务器 IP 地址</label><input type="text" name="ip" placeholder="留空则不验证IP"></div>
                    </div>
                    <div class="form-grid">
                        <div><label>经度 (地图X坐标)</label><input type="number" step="any" name="longitude" placeholder="例如: 1083"></div>
                        <div><label>纬度 (地图Y坐标)</label><input type="number" step="any" name="latitude" placeholder="例如: 228"></div>
                        <div><label>标签 (逗号分隔)</label><input type="text" name="tags" placeholder="例如: 亚洲,主力,高防"></div>
                    </div>
                    <div><label>简介</label><textarea name="intro" rows="3"></textarea></div>
                    <div style="margin-top: 1.2rem;">
                        <button type="submit" name="save_server">保存服务器</button>
                        <button type="button" class="secondary" id="cancel-edit-btn" style="display: none;">取消编辑</button>
                    </div>
                </form>
            </details>
        </div>

        <div class="section">
            <h2>已管理的服务器</h2>
            <div style="overflow-x: auto;">
                <table>
                    <thead><tr><th>ID</th><th>名称</th><th>标签</th><th>密钥</th><th>操作</th></tr></thead>
                    <tbody>
                        <?php foreach ($servers as $server): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($server['id']); ?></strong></td>
                            <td><?php echo htmlspecialchars($server['name']); ?></td>
                            <td><?php echo htmlspecialchars($server['tags']); ?></td>
                            <td>
                                <div class="secret-wrapper">
                                    <input type="text" id="secret-<?php echo htmlspecialchars($server['id']); ?>" value="<?php echo htmlspecialchars($server['secret']); ?>" readonly>
                                    <button class="secondary copy-btn" data-clipboard-target="#secret-<?php echo htmlspecialchars($server['id']); ?>">复制</button>
                                </div>
                            </td>
                            <td class="actions-cell">
                                <button class="edit-btn" data-id="<?php echo htmlspecialchars($server['id']); ?>">修改</button>
                                <form action="dashboard.php" method="post" onsubmit="return confirm('确定为 \'<?php echo htmlspecialchars($server['id']); ?>\' 生成一个新的密钥吗？旧密钥将立即失效！');" style="margin:0;">
                                     <input type="hidden" name="generate_secret_id" value="<?php echo htmlspecialchars($server['id']); ?>">
                                     <button type="submit" name="generate_secret" class="secondary">新密钥</button>
                                 </form>
                                <form action="dashboard.php" method="post" onsubmit="return confirm('确定删除这台服务器及其所有监控数据吗？');" style="margin: 0;">
                                    <input type="hidden" name="id" value="<?php echo htmlspecialchars($server['id']); ?>">
                                    <button type="submit" name="delete_server" class="delete">删除</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const addEditDetails = document.getElementById('add-edit-details');
        const addEditForm = document.getElementById('add-edit-form');
        const formSummary = addEditDetails.querySelector('summary');
        const idInput = addEditForm.querySelector('input[name="id"]');
        const isEditingInput = addEditForm.querySelector('input[name="is_editing"]');
        const cancelBtn = document.getElementById('cancel-edit-btn');
        const serversData = <?php echo json_encode($servers); ?>;

        document.querySelectorAll('.edit-btn').forEach(button => {
            button.addEventListener('click', function() {
                const serverId = this.dataset.id;
                const server = serversData.find(s => s.id === serverId);
                if (!server) return;

                formSummary.textContent = `正在编辑: ${server.name}`;
                idInput.value = server.id;
                idInput.readOnly = true;
                isEditingInput.value = '1';
                
                addEditForm.querySelector('input[name="name"]').value = server.name;
                addEditForm.querySelector('input[name="country_code"]').value = server.country_code || '';
                addEditForm.querySelector('input[name="longitude"]').value = server.longitude;
                addEditForm.querySelector('input[name="latitude"]').value = server.latitude;
                addEditForm.querySelector('input[name="ip"]').value = server.ip; 
                addEditForm.querySelector('input[name="tags"]').value = server.tags;
                addEditForm.querySelector('textarea[name="intro"]').value = server.intro;
                
                addEditDetails.open = true;
                cancelBtn.style.display = 'inline-block';
                window.scrollTo({ top: addEditDetails.offsetTop, behavior: 'smooth' });
            });
        });

        function resetForm() {
            formSummary.textContent = '添加新服务器';
            addEditForm.reset();
            idInput.readOnly = false;
            isEditingInput.value = '';
            cancelBtn.style.display = 'none';
        }
        cancelBtn.addEventListener('click', resetForm);
        addEditDetails.addEventListener('toggle', function(e) {
            if (!e.target.open) {
                resetForm();
            }
        });

        document.querySelectorAll('.copy-btn').forEach(button => {
            button.addEventListener('click', function() {
                const targetInput = document.querySelector(this.dataset.clipboardTarget);
                if (targetInput) {
                    targetInput.select();
                    targetInput.setSelectionRange(0, 99999);
                    try {
                        document.execCommand('copy');
                        const originalText = this.textContent;
                        this.textContent = '已复制!';
                        setTimeout(() => { this.textContent = originalText; }, 2000);
                    } catch (err) {
                        alert('复制失败，请手动复制。');
                    }
                }
            });
        });
    });
    </script>
</body>
</html>


