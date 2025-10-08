<?php
// report.php - v2.2 - Receives status updates. Data pruning moved to a cron job.
error_reporting(0);
ini_set('display_errors', 0);

header('Content-Type: application/json');
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
        error_log("report.php - DB Connection Failed: " . $e->getMessage());
        throw new Exception("Database connection failed.");
    }
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON payload.']);
    exit;
}

$server_id = $input['server_id'] ?? '';
$secret = $input['secret'] ?? '';

if (empty($server_id) || empty($secret)) {
    http_response_code(400);
    echo json_encode(['error' => 'server_id and secret are required.']);
    exit;
}

try {
    $pdo = get_pdo_connection();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $pdo->prepare("SELECT secret, ip FROM servers WHERE id = ?");
    $stmt->execute([$server_id]);
    $server = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$server) {
        http_response_code(404);
        throw new Exception('Invalid server_id.');
    }

    if (empty($server['secret']) || !hash_equals($server['secret'], $secret)) {
        http_response_code(403);
        throw new Exception('Invalid secret.');
    }

    if (!empty($server['ip']) && $server['ip'] !== $_SERVER['REMOTE_ADDR']) {
        http_response_code(403);
        error_log("IP validation failed for server '{$server_id}'. Expected '{$server['ip']}', got '{$_SERVER['REMOTE_ADDR']}'.");
        throw new Exception('IP address mismatch.');
    }
    
    $pdo->beginTransaction();

    // Insert server statistics with new fields
    $sql_stats = "INSERT INTO server_stats (server_id, timestamp, cpu_usage, mem_usage_percent, disk_usage_percent, uptime, load_avg, net_up_speed, net_down_speed, total_up, total_down, processes, connections) 
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt_stats = $pdo->prepare($sql_stats);
    $stmt_stats->execute([
        $server_id, time(),
        $input['cpu_usage'] ?? null,
        $input['mem_usage_percent'] ?? null,
        $input['disk_usage_percent'] ?? null,
        $input['uptime'] ?? null,
        $input['load_avg'] ?? null,
        $input['net_up_speed'] ?? null,
        $input['net_down_speed'] ?? null,
        $input['total_up'] ?? null,
        $input['total_down'] ?? null,
        $input['processes'] ?? null,
        $input['connections'] ?? null,
    ]);

    // Update static server hardware info if provided (on first report)
    if (isset($input['static_info'])) {
        $info = $input['static_info'];
        $sql_update_hw = "UPDATE servers SET cpu_cores = ?, cpu_model = ?, mem_total = ?, disk_total = ?, system = ?, arch = ? WHERE id = ?";
        $stmt_update_hw = $pdo->prepare($sql_update_hw);
        $stmt_update_hw->execute([
            $info['cpu_cores'] ?? null, $info['cpu_model'] ?? null,
            $info['mem_total_bytes'] ?? null, $info['disk_total_bytes'] ?? null,
            $info['system'] ?? null, $info['arch'] ?? null,
            $server_id
        ]);
    }

    // Update server status to online
    $online_val = $db_config['type'] === 'pgsql' ? 'true' : 1;
    if ($db_config['type'] === 'pgsql') {
        $sql_status = "INSERT INTO server_status (id, is_online, last_checked) VALUES (?, {$online_val}, ?) ON CONFLICT (id) DO UPDATE SET is_online = {$online_val}, last_checked = EXCLUDED.last_checked";
    } elseif ($db_config['type'] === 'mysql') {
        $sql_status = "INSERT INTO server_status (id, is_online, last_checked) VALUES (?, {$online_val}, ?) ON DUPLICATE KEY UPDATE is_online = {$online_val}, last_checked = VALUES(last_checked)";
    } else { // sqlite
        $sql_status = "INSERT OR REPLACE INTO server_status (id, is_online, last_checked) VALUES (?, {$online_val}, ?)";
    }
    $stmt_status = $pdo->prepare($sql_status);
    $stmt_status->execute([$server_id, time()]);

    $pdo->commit();

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("report.php Error for server '{$server_id}': " . $e->getMessage());
    if(!headers_sent()){
        http_response_code(500);
    }
    echo json_encode(['error' => 'An internal server error occurred. Check server logs.']);
    exit;
}
?>

