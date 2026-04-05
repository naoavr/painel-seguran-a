<?php
/**
 * Monitor Central Pixel Tracker
 * Usage: <img src="pixel.php?k=API_KEY&u=PAGE_URL&r=REFERER" width="1" height="1">
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';

header('Content-Type: image/gif');
header('Content-Length: 42');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

$gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
echo $gif;

// Collect visit data
$api_key = trim($_GET['k'] ?? '');
$url     = trim($_GET['u'] ?? '');
$referer = trim($_GET['r'] ?? '');

if (empty($api_key)) {
    exit;
}

$ip = '';
$headers = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
foreach ($headers as $h) {
    if (!empty($_SERVER[$h])) {
        $candidate = trim(explode(',', $_SERVER[$h])[0]);
        if (filter_var($candidate, FILTER_VALIDATE_IP)) {
            $ip = $candidate;
            break;
        }
    }
}
if (empty($ip)) $ip = $_SERVER['REMOTE_ADDR'] ?? '';

try {
    $db   = DB::getInstance();
    $site = $db->fetchOne('SELECT id, is_active FROM sites WHERE api_key = ?', [$api_key]);
    if (!$site || !$site['is_active']) exit;

    $site_id = (int)$site['id'];
    $ua      = $_SERVER['HTTP_USER_AGENT'] ?? '';

    $db->execute(
        'INSERT INTO traffic_log (site_id, ip, url, method, user_agent, referer, status_code, timestamp)
         VALUES (?,?,?,?,?,?,?,NOW())',
        [
            $site_id,
            $ip,
            $url  ? substr($url,  0, 2000) : null,
            'GET',
            $ua   ? substr($ua,   0,  500) : null,
            $referer ? substr($referer, 0, 500) : null,
            200,
        ]
    );

    $hour = date('Y-m-d H:00:00');
    $db->execute(
        'INSERT INTO traffic_stats_hourly (site_id, hour, total_requests)
         VALUES (?,?,1)
         ON DUPLICATE KEY UPDATE total_requests = total_requests + 1',
        [$site_id, $hour]
    );

    $db->execute(
        'UPDATE sites SET visits_today = visits_today + 1, visits_total = visits_total + 1 WHERE id=?',
        [$site_id]
    );
} catch (Exception $e) {
    error_log('pixel.php error: ' . $e->getMessage());
}
