<?php
// check_status.php - Cron job script to check for offline servers and send alerts. v2.0
date_default_timezone_set('UTC'); 

require_once __DIR__ . '/config.php';

function get_pdo_connection() {
    global $db_config;
    if (empty($db_config)) throw new Exception("Database config is missing.");
    
    try {
        if ($db_config['type'] === 'pgsql') {
            $cfg = $db_config['pgsql'];
            $dsn = "pgsql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['dbname']}";
            return new PDO($dsn, $cfg['user'], $cfg['password']);
        } elseif ($db_config['type'] === 'mysql') {
            $cfg = $db_config['mysql'];
            $dsn = "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['dbname']};charset=utf8mb4";
            return new PDO($dsn, $cfg['user'], $cfg['password']);
        } else { // sqlite
            $dsn = 'sqlite:' . $db_config['sqlite']['path'];
            $pdo = new PDO($dsn);
            $pdo->exec('PRAGMA journal_mode = WAL;');
            return $pdo;
        }
    } catch (PDOException $e) {
        error_log("check_status.php - DB Connection Failed: " . $e->getMessage());
        throw new Exception("Database connection failed.");
    }
}

function send_telegram_message($token, $chat_id, $message) {
    if (empty($token) || empty($chat_id)) {
        error_log("Telegram bot token or chat ID is not configured.");
        return false;
    }
    $url = "https://api.telegram.org/bot" . $token . "/sendMessage";
    $data = ['chat_id' => $chat_id, 'text' => $message, 'parse_mode' => 'Markdown'];
    $options = [
        'http' => [
            'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
            'method'  => 'POST',
            'content' => http_build_query($data),
            'ignore_errors' => true
        ],
    ];
    $context  = stream_context_create($options);
    $result = file_get_contents($url, false, $context);
    
    if ($result === FALSE) {
        error_log("Telegram API request failed.");
        return false;
    }
    
    $response_data = json_decode($result, true);
    if (!isset($response_data['ok']) || !$response_data['ok']) {
        error_log("Telegram API Error: " . ($response_data['description'] ?? 'Unknown error'));
        return false;
    }
    return true;
}

const OFFLINE_THRESHOLD = 35; // Seconds since last report to be marked as offline

try {
    $pdo = get_pdo_connection();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // --- 1. Update server online status ---
    $current_time = time();
    $is_online_col = $db_config['type'] === 'pgsql' ? 'is_online' : 'is_online';
    $status_stmt = $pdo->query("SELECT id, last_checked, {$is_online_col} AS is_online FROM server_status");
    $all_statuses = $status_stmt->fetchAll(PDO::FETCH_ASSOC);

    $offline_val = $db_config['type'] === 'pgsql' ? 'false' : 0;
    $update_status_stmt = $pdo->prepare("UPDATE server_status SET {$is_online_col} = {$offline_val} WHERE id = ?");

    foreach ($all_statuses as $status) {
        if ($status['is_online'] && ($current_time - $status['last_checked'] > OFFLINE_THRESHOLD)) {
            $update_status_stmt->execute([$status['id']]);
            error_log("Server '{$status['id']}' marked as offline due to timeout.");
        }
    }

    // --- 2. Process outage and recovery notifications ---
    $key_column = ($db_config['type'] === 'mysql') ? 'key_name' : 'key';
    $settings_stmt = $pdo->query("SELECT {$key_column} AS key, value FROM settings WHERE {$key_column} LIKE 'telegram_%'");
    $settings = $settings_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $bot_token = $settings['telegram_bot_token'] ?? '';
    $chat_id = $settings['telegram_chat_id'] ?? '';

    $servers_stmt = $pdo->query("SELECT s.id, s.name, st.{$is_online_col} AS is_online FROM servers s LEFT JOIN server_status st ON s.id = st.id");
    $servers = $servers_stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($servers as $server) {
        $server_id = $server['id'];
        $server_name = $server['name'];
        $is_currently_online = (bool)$server['is_online'];

        $outage_stmt = $pdo->prepare("SELECT * FROM outages WHERE server_id = ? AND end_time IS NULL ORDER BY start_time DESC LIMIT 1");
        $outage_stmt->execute([$server_id]);
        $active_outage = $outage_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$is_currently_online) {
            if (!$active_outage) {
                // New outage event
                $start_time = time();
                $insert_stmt = $pdo->prepare("INSERT INTO outages (server_id, start_time, title, content) VALUES (?, ?, ?, ?)");
                $insert_stmt->execute([$server_id, $start_time, '服务器掉线', '服务器停止报告数据。']);
                
                $message = "🔴 *服务离线警告*\n\n服务器 `{$server_name}` (`{$server_id}`) 已停止响应。";
                send_telegram_message($bot_token, $chat_id, $message);
            }
        } else {
            if ($active_outage) {
                // Server has recovered
                $end_time = time();
                $duration = $end_time - $active_outage['start_time']; // duration in seconds
                
                $duration_str = '';
                if ($duration < 60) {
                    $duration_str = "{$duration} 秒";
                } elseif ($duration < 3600) {
                    $duration_str = round($duration / 60) . " 分钟";
                } else {
                    $duration_str = round($duration / 3600, 1) . " 小时";
                }

                $update_stmt = $pdo->prepare("UPDATE outages SET end_time = ? WHERE id = ?");
                $update_stmt->execute([$end_time, $active_outage['id']]);

                $message = "✅ *服务恢复通知*\n\n服务器 `{$server_name}` (`{$server_id}`) 已恢复在线。\n持续离线时间：约 {$duration_str}。";
                send_telegram_message($bot_token, $chat_id, $message);
            }
        }
    }
} catch (Exception $e) {
    error_log("check_status.php CRON Error: " . $e->getMessage());
    exit(1);
}

exit(0);
?>
