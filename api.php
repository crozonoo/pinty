<?php
// api.php - Provides monitoring data to the frontend v2.3 (Final)
ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: application/json');
require_once __DIR__ . '/config.php';

function get_pdo_connection() {
    global $db_config;
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
        error_log("Database connection failed: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Database connection failed. Check server logs.']);
        exit;
    }
}

$action = $_GET['action'] ?? 'get_all';
$server_id = $_GET['id'] ?? null;

try {
    $pdo = get_pdo_connection();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if ($action === 'get_history' && $server_id) {
        // --- Action: Get detailed history for a single server (last 24h) ---
        $sql_history = 'SELECT cpu_usage, mem_usage_percent, disk_usage_percent, load_avg, net_up_speed, net_down_speed, total_up, total_down, timestamp, processes, connections FROM server_stats WHERE server_id = ? ORDER BY timestamp DESC LIMIT 1440';
        $stmt_history = $pdo->prepare($sql_history);
        $stmt_history->execute([$server_id]);
        $history = $stmt_history->fetchAll(PDO::FETCH_ASSOC);

        $typed_history = array_map(function($record) {
            foreach($record as $key => $value) {
                if ($value !== null && is_numeric($value)) {
                    $record[$key] = strpos($value, '.') === false ? (int)$value : (float)$value;
                }
            }
            return $record;
        }, $history);

        echo json_encode(['history' => array_reverse($typed_history)]);
        exit;

    } elseif ($action === 'get_all') {
        // --- Action: Get main dashboard data (lightweight) ---
        $response = [
            'nodes' => [],
            'outages' => [],
            'site_name' => 'Pinty Monitor'
        ];
        
        $key_column = ($db_config['type'] === 'mysql') ? 'key_name' : 'key';
        $stmt_site_name = $pdo->prepare("SELECT value FROM settings WHERE {$key_column} = 'site_name'");
        $stmt_site_name->execute();
        if ($site_name = $stmt_site_name->fetchColumn()) {
            $response['site_name'] = $site_name;
        }

        $stmt_servers = $pdo->query("SELECT id, name, intro, tags, price_usd_yearly, latitude, longitude, country_code, system, arch, cpu_model, mem_total, disk_total FROM servers ORDER BY id ASC");
        $servers = $stmt_servers->fetchAll(PDO::FETCH_ASSOC);

        $stmt_status = $pdo->query("SELECT id, is_online, last_checked FROM server_status");
        $online_status_raw = $stmt_status->fetchAll(PDO::FETCH_ASSOC);
        $online_status = [];
        foreach ($online_status_raw as $status) {
            $online_status[$status['id']] = $status;
        }
        
        if ($db_config['type'] === 'pgsql') {
            $sql_stats = "SELECT DISTINCT ON (server_id) * FROM server_stats ORDER BY server_id, timestamp DESC";
        } else { // Works for SQLite and MySQL
            $sql_stats = "SELECT s.* FROM server_stats s JOIN (SELECT server_id, MAX(timestamp) AS max_ts FROM server_stats GROUP BY server_id) AS m ON s.server_id = m.server_id AND s.timestamp = m.max_ts";
        }
        $stmt_stats = $pdo->query($sql_stats);
        $latest_stats_raw = $stmt_stats->fetchAll(PDO::FETCH_ASSOC);
        $latest_stats = [];
        foreach($latest_stats_raw as $stat) {
            $latest_stats[$stat['server_id']] = $stat;
        }

        foreach ($servers as $node) {
            $node_id = $node['id'];
            $node['x'] = (float)($node['latitude'] ?? 0);
            $node['y'] = (float)($node['longitude'] ?? 0);
            $node['stats'] = $latest_stats[$node_id] ?? [];
            
            $status_info = $online_status[$node_id] ?? ['is_online' => false, 'last_checked' => 0];
            $node['is_online'] = (bool)$status_info['is_online'];
            
            if (!$node['is_online'] && $status_info['last_checked'] > 0) {
                 $node['anomaly_msg'] = '服务器掉线';
                 $node['outage_duration'] = time() - $status_info['last_checked'];
            }
            
            $node['history'] = []; // History is no longer included in the main payload

            $response['nodes'][] = $node;
        }

        $response['outages'] = $pdo->query("SELECT * FROM outages ORDER BY start_time DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode($response);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}
?>

