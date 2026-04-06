<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_auth();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

$is_post = $_SERVER['REQUEST_METHOD'] === 'POST';
$post_actions = [
    'block_ip', 'unblock_ip', 'check_reputation', 'mark_alert_read',
    'add_site', 'delete_site', 'resolve_malware', 'run_cron',
    'cron_threat_feeds', 'cron_ssl_check', 'cron_cleanup',
    'get_stats', 'get_globe_data', 'get_relations_data',
];

$input = [];
if ($is_post) {
    $raw = file_get_contents('php://input');
    if ($raw) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $input = $decoded;
        }
    }
    if (empty($input)) {
        $input = $_POST;
    }
    $action = $input['action'] ?? $action;
}

if ($is_post && in_array($action, $post_actions, true)) {
    $token  = $input['_csrf_token'] ?? $_POST['_csrf_token'] ?? '';
    $stored = $_SESSION['csrf_token'] ?? '';
    if (empty($token) || empty($stored) || !hash_equals($stored, $token)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid CSRF token']);
        exit;
    }
}

function json_ok(mixed $data, string $message = ''): void {
    echo json_encode(['status' => 'ok', 'data' => $data, 'message' => $message]);
    exit;
}

function json_err(string $message, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['status' => 'error', 'message' => $message]);
    exit;
}

try {
    $db = DB::getInstance();

    switch ($action) {

        case 'get_stats':
            $stats = [
                'total_sites'   => (int)($db->fetchOne('SELECT COUNT(*) AS c FROM sites WHERE is_active=1')['c'] ?? 0),
                'blocked_ips'   => (int)($db->fetchOne('SELECT COUNT(*) AS c FROM blocked_ips WHERE is_active=1')['c'] ?? 0),
                'php_errors'    => (int)($db->fetchOne("SELECT COUNT(*) AS c FROM error_log WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")['c'] ?? 0),
                'file_changes'  => (int)($db->fetchOne("SELECT COUNT(*) AS c FROM file_change_log WHERE detected_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")['c'] ?? 0),
                'malware_count' => (int)($db->fetchOne('SELECT COUNT(*) AS c FROM malware_scans WHERE is_resolved=0')['c'] ?? 0),
                'unread_alerts' => (int)($db->fetchOne('SELECT COUNT(*) AS c FROM alerts WHERE is_read=0')['c'] ?? 0),
            ];
            json_ok($stats);

        case 'get_traffic_chart':
            $site_id = isset($input['site_id']) && (int)$input['site_id'] > 0 ? (int)$input['site_id'] : null;
            $where = $site_id ? 'AND site_id=?' : '';
            $params = $site_id ? [$site_id] : [];
            $rows = $db->fetchAll(
                "SELECT DATE_FORMAT(hour,'%H:00') AS label, SUM(total_requests) AS val
                 FROM traffic_stats_hourly
                 WHERE hour >= DATE_SUB(NOW(), INTERVAL 24 HOUR) $where
                 GROUP BY hour ORDER BY hour ASC",
                $params
            );
            json_ok(['labels' => array_column($rows, 'label'), 'values' => array_column($rows, 'val')]);

        case 'get_sites':
            $sites = $db->fetchAll('SELECT id, domain, name, status, ssl_valid, ssl_expiry, http_status, last_seen, visits_today, visits_total FROM sites WHERE is_active=1 ORDER BY name');
            json_ok($sites);

        case 'block_ip':
            $ip     = trim($input['ip'] ?? '');
            $reason = trim($input['reason'] ?? '');
            $siteId = (int)($input['site_id'] ?? 0);
            if (!filter_var($ip, FILTER_VALIDATE_IP)) json_err('Invalid IP address');
            $db->execute(
                'INSERT INTO blocked_ips (site_id, ip, reason, blocked_by, is_active) VALUES (?,?,?,?,1)',
                [$siteId ?: null, $ip, $reason, 'manual']
            );
            update_htaccess_block($ip);
            json_ok(['ip' => $ip], 'IP blocked successfully');

        case 'unblock_ip':
            $id = (int)($input['block_id'] ?? 0);
            if ($id <= 0) json_err('Invalid block ID');
            $db->execute('UPDATE blocked_ips SET is_active=0 WHERE id=?', [$id]);
            json_ok([], 'IP unblocked');

        case 'check_reputation':
            $ip = trim($input['ip'] ?? '');
            if (!filter_var($ip, FILTER_VALIDATE_IP)) json_err('Invalid IP address');
            $result = check_abuseipdb($ip);
            json_ok($result ?? [], 'Reputation checked');

        case 'get_alerts':
            $limit = min(50, max(1, (int)($input['limit'] ?? 20)));
            $alerts = $db->fetchAll(
                "SELECT a.*, s.domain FROM alerts a LEFT JOIN sites s ON s.id=a.site_id ORDER BY a.created_at DESC LIMIT $limit"
            );
            json_ok($alerts);

        case 'mark_alert_read':
            $id = (int)($input['alert_id'] ?? 0);
            if ($id <= 0) {
                $db->execute('UPDATE alerts SET is_read=1 WHERE is_read=0');
                json_ok([], 'All alerts marked read');
            }
            $db->execute('UPDATE alerts SET is_read=1 WHERE id=?', [$id]);
            json_ok([], 'Alert marked read');

        case 'add_site':
            $domain = trim($input['domain'] ?? '');
            $name   = trim($input['name']   ?? '');
            if (empty($domain)) json_err('Domain is required');
            $apiKey = generate_api_key();
            // Geocode the domain for the globe view (resolve hostname to IP first)
            $host    = preg_replace('/^https?:\/\//i', '', rtrim($domain, '/'));
            $host    = strtok($host, '/');
            $siteIp  = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);
            $geoInfo = ($siteIp && filter_var($siteIp, FILTER_VALIDATE_IP)) ? get_ip_info($siteIp) : null;
            $siteLat = isset($geoInfo['lat']) ? (float)$geoInfo['lat'] : null;
            $siteLon = isset($geoInfo['lon']) ? (float)$geoInfo['lon'] : null;
            $db->execute(
                'INSERT INTO sites (domain, api_key, name, status, latitude, longitude) VALUES (?,?,?,?,?,?)',
                [$domain, $apiKey, $name, 'unknown', $siteLat, $siteLon]
            );
            $newId = (int)$db->lastInsertId();
            json_ok(['id' => $newId, 'api_key' => $apiKey, 'domain' => $domain], 'Site added');

        case 'delete_site':
            $id = (int)($input['site_id'] ?? 0);
            if ($id <= 0) json_err('Invalid site ID');
            $db->execute('UPDATE sites SET is_active=0 WHERE id=?', [$id]);
            json_ok([], 'Site removed');

        case 'get_relations_data':
            $rows = $db->fetchAll(
                "SELECT t.site_id, t.ip, s.domain, t.country_code, COUNT(*) AS hits
                 FROM traffic_log t
                 JOIN sites s ON s.id=t.site_id
                 WHERE t.timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR) AND t.ip IS NOT NULL
                 GROUP BY t.site_id, t.ip HAVING hits > 2
                 ORDER BY hits DESC LIMIT 200"
            );
            json_ok($rows);

        case 'resolve_malware':
            $id = (int)($input['malware_id'] ?? 0);
            if ($id <= 0) json_err('Invalid ID');
            $db->execute('UPDATE malware_scans SET is_resolved=1 WHERE id=?', [$id]);
            json_ok([], 'Resolved');

        case 'get_globe_data':
            $traffic = $db->fetchAll(
                "SELECT t.ip, t.country_code,
                        COALESCE(r.country, t.country) AS country,
                        COALESCE(r.abuse_score, 0) AS abuse_score,
                        CASE WHEN b.id IS NOT NULL THEN 1 ELSE 0 END AS is_blocked,
                        r.longitude AS lon,
                        r.latitude AS lat,
                        s.longitude AS site_lon,
                        s.latitude AS site_lat
                 FROM traffic_log t
                 LEFT JOIN ip_reputation r ON r.ip = t.ip
                 LEFT JOIN blocked_ips b ON b.ip = t.ip AND b.is_active = 1
                 LEFT JOIN sites s ON s.id = t.site_id
                 WHERE t.timestamp >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
                 ORDER BY t.timestamp DESC LIMIT 50"
            );
            $servers = $db->fetchAll(
                "SELECT domain, longitude AS lon, latitude AS lat FROM sites WHERE is_active=1 AND longitude IS NOT NULL AND latitude IS NOT NULL"
            );
            json_ok(['traffic' => $traffic, 'servers' => $servers]);

        case 'run_cron':
        case 'cron_threat_feeds':
            $db->execute(
                "INSERT INTO system_config (config_key, config_value) VALUES ('cron_last_run_threat_feeds', NOW())
                 ON DUPLICATE KEY UPDATE config_value=NOW()"
            );
            json_ok([], 'Threat feeds update triggered');

        case 'cron_ssl_check':
            $sites = $db->fetchAll('SELECT id, domain FROM sites WHERE is_active=1');
            $updated = 0;
            foreach ($sites as $site) {
                $ssl = check_ssl($site['domain']);
                $db->execute(
                    'UPDATE sites SET ssl_valid=?, ssl_expiry=? WHERE id=?',
                    [$ssl['valid'] ? 1 : 0, $ssl['expiry'] ?? null, $site['id']]
                );
                $updated++;
            }
            $db->execute(
                "INSERT INTO system_config (config_key, config_value) VALUES ('cron_last_run_ssl_check', NOW())
                 ON DUPLICATE KEY UPDATE config_value=NOW()"
            );
            json_ok(['updated' => $updated], "SSL checked for $updated sites");

        case 'cron_cleanup':
            $db->execute("DELETE FROM traffic_log WHERE timestamp < DATE_SUB(NOW(), INTERVAL 30 DAY)");
            $db->execute("DELETE FROM error_log WHERE timestamp < DATE_SUB(NOW(), INTERVAL 30 DAY)");
            $db->execute("DELETE FROM dashboard_sessions WHERE expires_at < NOW()");
            $db->execute(
                "INSERT INTO system_config (config_key, config_value) VALUES ('cron_last_run_cleanup', NOW())
                 ON DUPLICATE KEY UPDATE config_value=NOW()"
            );
            json_ok([], 'Cleanup complete');

        case 'export_stats':
            $site_id   = isset($_GET['site_id']) && (int)$_GET['site_id'] > 0 ? (int)$_GET['site_id'] : null;
            $date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-7 days'));
            $date_to   = $_GET['date_to']   ?? date('Y-m-d');
            $where  = 'WHERE hour >= ? AND hour <= ?';
            $params = [$date_from . ' 00:00:00', $date_to . ' 23:59:59'];
            if ($site_id) { $where .= ' AND site_id=?'; $params[] = $site_id; }
            $rows = $db->fetchAll(
                "SELECT DATE_FORMAT(hour,'%Y-%m-%d %H:00') AS hour,
                        SUM(total_requests) AS requests, SUM(unique_ips) AS unique_ips,
                        SUM(error_count) AS errors, SUM(blocked_count) AS blocked
                 FROM traffic_stats_hourly $where GROUP BY hour ORDER BY hour ASC",
                $params
            );
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="stats_' . date('Y-m-d') . '.csv"');
            echo "Hour,Requests,Unique IPs,Errors,Blocked\n";
            foreach ($rows as $r) {
                echo implode(',', array_map(fn($v) => '"' . str_replace('"', '""', (string)$v) . '"', $r)) . "\n";
            }
            exit;

        default:
            json_err('Unknown action: ' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8'), 400);
    }
} catch (Exception $e) {
    error_log('ajax.php error: ' . $e->getMessage());
    json_err('Internal server error', 500);
}
