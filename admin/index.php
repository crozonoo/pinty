<?php
session_start();
// 如果 config.php 不存在，则重定向到安装程序
if (!file_exists('./../config.php')) {
    header('Location: setup.php');
    exit;
}

// 如果已登录，则直接跳转到 dashboard
if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
    header('Location: dashboard.php');
    exit;
}

require_once __DIR__ . './../config.php';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($db_config['type'] === 'pgsql') {
            $cfg = $db_config['pgsql'];
            $dsn = "pgsql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['dbname']}";
            $pdo = new PDO($dsn, $cfg['user'], $cfg['password']);
        } else {
            $dsn = 'sqlite:' . $db_config['sqlite']['path'];
            $pdo = new PDO($dsn);
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';

        $stmt = $pdo->prepare("SELECT password FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['loggedin'] = true;
            $_SESSION['username'] = $username;
            header('Location: dashboard.php');
            exit;
        } else {
            $error_message = '用户名或密码错误。';
        }
    } catch (Exception $e) {
        $error_message = '数据库错误: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>登录 - Domain Monitor</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; background-color: #f0f2f5; margin: 0; padding: 2rem; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
        .container { max-width: 400px; width: 100%; margin: 0 auto; background: #fff; padding: 2.5rem; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
        h1 { color: #111; margin-top: 0; margin-bottom: 2rem; text-align: center; }
        .error-message { background: #dc3545; color: #fff; padding: 1rem; border-radius: 5px; margin-bottom: 1.5rem; text-align: center; }
        label { font-weight: 600; display: block; margin-bottom: 0.4rem; }
        input[type="text"], input[type="password"] { width: 100%; padding: 0.8rem; border: 1px solid #ced4da; border-radius: 4px; box-sizing: border-box; margin-bottom: 1rem; }
        input:focus { border-color: #80bdff; outline: 0; box-shadow: 0 0 0 0.2rem rgba(0,123,255,.25); }
        button { width: 100%; background-color: #007bff; color: #fff; border: none; padding: 0.8rem 1.2rem; border-radius: 5px; cursor: pointer; transition: background-color 0.2s; font-weight: 600; font-size: 1em; }
        button:hover { background-color: #0056b3; }
    </style>
</head>
<body>
    <div class="container">
        <h1>登录到管理后台</h1>
        <?php if ($error_message): ?><p class="error-message"><?php echo htmlspecialchars($error_message); ?></p><?php endif; ?>
        <form method="post">
            <div>
                <label for="username">用户名</label>
                <input type="text" id="username" name="username" required>
            </div>
            <div>
                <label for="password">密码</label>
                <input type="password" id="password" name="password" required>
            </div>
            <button type="submit">登录</button>
        </form>
    </div>
</body>
</html>
