<?php
$request_origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed_origin = defined('INGEST_ALLOWED_ORIGIN') ? INGEST_ALLOWED_ORIGIN : '';
if ($request_origin !== '' && $allowed_origin !== '' && $request_origin === $allowed_origin) {
    header('Access-Control-Allow-Origin: ' . $allowed_origin);
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed']);
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);

if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON body']);
    exit;
}

$api_key = trim($body['api_key'] ?? '');
$type    = trim($body['type']    ?? '');
$data    = $body['data']         ?? [];

if (empty($api_key)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Missing api_key']);
    exit;
}

try {
    $db   = DB::getInstance();
    $site = $db->fetchOne('SELECT id, domain, is_active FROM sites WHERE api_key = ?', [$api_key]);
} catch (Exception $e) {
    error_log('ingest.php DB error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Server error']);
    exit;
}

if (!$site || !$site['is_active']) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Invalid API key']);
    exit;
}

$site_id = (int)$site['id'];

// Return early for watch list query
if ($type === 'get_watch_list') {
    $watch_list = $db->fetchAll(
        'SELECT file_path, file_hash, file_size FROM monitored_files WHERE site_id=?',
        [$site_id]
    );
    echo json_encode(['status' => 'ok', 'data' => $watch_list]);
    exit;
}

// Return OK immediately, then finish processing
echo json_encode(['status' => 'ok']);
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

// Process types
switch ($type) {

    case 'traffic':
        if (!is_array($data)) break;
        $records = isset($data[0]) ? $data : [$data];
        foreach ($records as $rec) {
            if (!is_array($rec)) continue;
            $ip = trim($rec['ip'] ?? '');
            if (empty($ip)) continue;

            $db->execute(
                'INSERT INTO traffic_log (site_id, ip, country, country_code, city, url, method, status_code, user_agent, referer, response_time, timestamp)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())',
                [
                    $site_id,
                    $ip,
                    $rec['country']       ?? null,
                    $rec['country_code']  ?? null,
                    $rec['city']          ?? null,
                    isset($rec['url']) ? substr($rec['url'], 0, 2000) : null,
                    $rec['method']        ?? 'GET',
                    isset($rec['status_code']) ? (int)$rec['status_code'] : null,
                    isset($rec['user_agent']) ? substr($rec['user_agent'], 0, 500) : null,
                    isset($rec['referer']) ? substr($rec['referer'], 0, 500) : null,
                    isset($rec['response_time']) ? (float)$rec['response_time'] : null,
                ]
            );

            // Update hourly stats
            $hour = date('Y-m-d H:00:00');
            $db->execute(
                'INSERT INTO traffic_stats_hourly (site_id, hour, total_requests, unique_ips)
                 VALUES (?,?,1,1)
                 ON DUPLICATE KEY UPDATE
                   total_requests = total_requests + 1',
                [$site_id, $hour]
            );

            // Check if blocked
            $blocked = $db->fetchOne(
                'SELECT id FROM blocked_ips WHERE ip=? AND is_active=1 LIMIT 1', [$ip]
            );
            if ($blocked) {
                $db->execute(
                    'UPDATE traffic_stats_hourly SET blocked_count = blocked_count + 1
                     WHERE site_id=? AND hour=?',
                    [$site_id, $hour]
                );
            }
        }

        // Update visits
        $db->execute(
            'UPDATE sites SET visits_today = visits_today + ?, visits_total = visits_total + ? WHERE id=?',
            [count($records), count($records), $site_id]
        );

        // Enrich geolocation for unique IPs (runs after response is sent)
        $seen = [];
        foreach ($records as $rec) {
            $ip = trim($rec['ip'] ?? '');
            if (!empty($ip) && !isset($seen[$ip])) {
                $seen[$ip] = true;
                enrich_ip_geo($ip);
            }
        }
        break;

    case 'errors':
        if (!is_array($data)) break;
        $fatal_types = ['E_ERROR', 'E_PARSE', 'E_CORE_ERROR', 'E_COMPILE_ERROR'];
        $records = isset($data[0]) ? $data : [$data];
        foreach ($records as $rec) {
            if (!is_array($rec)) continue;
            $errType = strtoupper(trim($rec['type'] ?? ''));
            if (!in_array($errType, $fatal_types, true)) continue;
            $db->execute(
                'INSERT INTO error_log (site_id, type, message, file, line, url, ip, timestamp)
                 VALUES (?,?,?,?,?,?,?,NOW())',
                [
                    $site_id,
                    $errType,
                    isset($rec['message']) ? substr($rec['message'], 0, 2000) : null,
                    isset($rec['file'])    ? substr($rec['file'],    0,  500) : null,
                    isset($rec['line'])    ? (int)$rec['line'] : null,
                    isset($rec['url'])     ? substr($rec['url'],     0, 2000) : null,
                    $rec['ip'] ?? null,
                ]
            );
            // Update error count in hourly stats
            $hour = date('Y-m-d H:00:00');
            $db->execute(
                'INSERT INTO traffic_stats_hourly (site_id, hour, error_count)
                 VALUES (?,?,1)
                 ON DUPLICATE KEY UPDATE error_count = error_count + 1',
                [$site_id, $hour]
            );
        }
        break;

    case 'file_changes':
        if (!is_array($data)) break;
        $records = isset($data[0]) ? $data : [$data];
        foreach ($records as $rec) {
            if (!is_array($rec)) continue;
            $filePath  = isset($rec['file_path']) ? substr($rec['file_path'], 0, 1000) : '';
            $changeType = $rec['change_type'] ?? 'modified';
            if (!in_array($changeType, ['added', 'modified', 'deleted'], true)) $changeType = 'modified';

            $db->execute(
                'INSERT INTO file_change_log (site_id, file_path, change_type, old_hash, new_hash, old_size, new_size, detected_at)
                 VALUES (?,?,?,?,?,?,?,NOW())',
                [
                    $site_id,
                    $filePath,
                    $changeType,
                    $rec['old_hash'] ?? null,
                    $rec['new_hash'] ?? null,
                    isset($rec['old_size']) ? (int)$rec['old_size'] : null,
                    isset($rec['new_size']) ? (int)$rec['new_size'] : null,
                ]
            );

            // Malware scan for added/modified files
            if (in_array($changeType, ['added', 'modified'], true) && !empty($rec['content'])) {
                $content = $rec['content'];
                $threat  = malware_scan_file($content, $filePath);
                if ($threat) {
                    $db->execute(
                        'INSERT INTO malware_scans (site_id, file_path, threat_name, pattern_matched, detected_at)
                         VALUES (?,?,?,?,NOW())',
                        [
                            $site_id,
                            $filePath,
                            $threat['threat_name'],
                            $threat['pattern_matched'],
                        ]
                    );
                    send_alert($site_id, 'malware_detected', 'critical',
                        'Malware detected: ' . $threat['threat_name'] . ' in ' . basename($filePath),
                        ['file' => $filePath, 'threat' => $threat['threat_name']]
                    );
                }
            }

            // Update monitored_files
            if ($changeType !== 'deleted') {
                $db->execute(
                    'INSERT INTO monitored_files (site_id, file_path, file_hash, file_size, last_checked)
                     VALUES (?,?,?,?,NOW())
                     ON DUPLICATE KEY UPDATE file_hash=VALUES(file_hash), file_size=VALUES(file_size), last_checked=NOW()',
                    [
                        $site_id,
                        $filePath,
                        $rec['new_hash'] ?? null,
                        isset($rec['new_size']) ? (int)$rec['new_size'] : null,
                    ]
                );
            }
        }
        break;

    case 'heartbeat':
        $http_status = isset($data['http_status']) ? (int)$data['http_status'] : null;
        $status = ($http_status && $http_status >= 200 && $http_status < 400) ? 'online' : 'offline';
        $db->execute(
            'UPDATE sites SET last_seen=NOW(), http_status=?, status=? WHERE id=?',
            [$http_status, $status, $site_id]
        );
        break;
}
