<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/db.php';

function format_bytes(int $bytes, int $precision = 2): string {
    if ($bytes <= 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $pow = (int)floor(log($bytes, 1024));
    $pow = min($pow, count($units) - 1);
    return round($bytes / (1024 ** $pow), $precision) . ' ' . $units[$pow];
}

function time_ago(string $datetime): string {
    if (empty($datetime)) return 'Never';
    $diff = time() - strtotime($datetime);
    if ($diff < 60)      return $diff . ' seconds ago';
    if ($diff < 3600)    return floor($diff / 60) . ' minutes ago';
    if ($diff < 86400)   return floor($diff / 3600) . ' hours ago';
    if ($diff < 2592000) return floor($diff / 86400) . ' days ago';
    return date('M j, Y', strtotime($datetime));
}

function get_flag_emoji(string $country_code): string {
    if (strlen($country_code) !== 2) return '🌐';
    $cc = strtoupper($country_code);
    $chars = [];
    foreach (str_split($cc) as $c) {
        $chars[] = mb_chr(0x1F1E0 + ord($c) - ord('A'), 'UTF-8');
    }
    return implode('', $chars);
}

function truncate(string $str, int $len = 50): string {
    if (mb_strlen($str) <= $len) return $str;
    return mb_substr($str, 0, $len) . '…';
}

function sanitize_input(mixed $input): string {
    if (!is_string($input)) {
        $input = (string)$input;
    }
    return htmlspecialchars(trim(strip_tags($input)), ENT_QUOTES, 'UTF-8');
}

function generate_api_key(): string {
    return bin2hex(random_bytes(32));
}

function check_abuseipdb(string $ip): ?array {
    $apiKey = defined('ABUSEIPDB_API_KEY') ? ABUSEIPDB_API_KEY : '';
    if (empty($apiKey)) return null;

    $url = 'https://api.abuseipdb.com/api/v2/check?' . http_build_query([
        'ipAddress'    => $ip,
        'maxAgeInDays' => 90,
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_HTTPHEADER     => [
            'Key: ' . $apiKey,
            'Accept: application/json',
        ],
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) return null;

    $data = json_decode($response, true);
    if (!isset($data['data'])) return null;

    $d = $data['data'];
    try {
        $db = DB::getInstance();
        $db->execute(
            'INSERT INTO ip_reputation (ip, abuse_score, country, isp, domain, total_reports, last_reported, last_checked, source)
             VALUES (?,?,?,?,?,?,?,NOW(),?)
             ON DUPLICATE KEY UPDATE abuse_score=VALUES(abuse_score), country=VALUES(country), isp=VALUES(isp),
             domain=VALUES(domain), total_reports=VALUES(total_reports), last_reported=VALUES(last_reported),
             last_checked=NOW(), source=VALUES(source)',
            [
                $ip,
                $d['abuseConfidenceScore'] ?? 0,
                $d['countryCode'] ?? '',
                $d['isp'] ?? '',
                $d['domain'] ?? '',
                $d['totalReports'] ?? 0,
                $d['lastReportedAt'] ?? null,
                'abuseipdb',
            ]
        );
    } catch (Exception $e) {
        error_log('check_abuseipdb DB error: ' . $e->getMessage());
    }
    return $d;
}

function get_ip_info(string $ip): ?array {
    $url = 'http://ip-api.com/json/' . urlencode($ip) . '?fields=status,country,countryCode,city,isp,org,query';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) return null;
    $data = json_decode($response, true);
    if (!isset($data['status']) || $data['status'] !== 'success') return null;
    return $data;
}

function check_ssl(string $domain): array {
    $result = ['valid' => false, 'expiry' => null, 'days_left' => 0];
    $context = stream_context_create([
        'ssl' => [
            'capture_peer_cert' => true,
            'verify_peer'       => true,
            'verify_peer_name'  => true,
            'SNI_enabled'       => true,
        ],
    ]);
    $client = @stream_socket_client(
        'ssl://' . $domain . ':443',
        $errno,
        $errstr,
        10,
        STREAM_CLIENT_CONNECT,
        $context
    );
    if (!$client) {
        return $result;
    }
    $params = stream_context_get_params($client);
    fclose($client);

    $cert = $params['options']['ssl']['peer_certificate'] ?? null;
    if (!$cert) return $result;

    $certInfo = openssl_x509_parse($cert);
    if (!$certInfo) return $result;

    $validTo = $certInfo['validTo_time_t'] ?? 0;
    if ($validTo > time()) {
        $result['valid']     = true;
        $result['expiry']    = date('Y-m-d', $validTo);
        $result['days_left'] = (int)(($validTo - time()) / 86400);
    }
    return $result;
}

function update_htaccess_block(string $ip): bool {
    $htaccess = dirname(__DIR__) . '/.htaccess';
    $marker   = '# BEGIN MonitorCentral Blocked IPs';
    $endMarker = '# END MonitorCentral Blocked IPs';

    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return false;
    }

    $existing = is_file($htaccess) ? file_get_contents($htaccess) : '';
    if ($existing === false) $existing = '';

    $denyLine = 'Deny from ' . $ip;

    if (strpos($existing, $marker) !== false) {
        if (strpos($existing, $denyLine) !== false) {
            return true;
        }
        $existing = preg_replace(
            '/' . preg_quote($endMarker, '/') . '/',
            $denyLine . "\n" . $endMarker,
            $existing
        );
    } else {
        $block = "\n" . $marker . "\nOrder Allow,Deny\nAllow from all\n" . $denyLine . "\n" . $endMarker . "\n";
        $existing .= $block;
    }

    return file_put_contents($htaccess, $existing, LOCK_EX) !== false;
}

function malware_scan_file(string $content, string $file_path): ?array {
    $patterns = [
        'eval_base64'        => '/eval\s*\(\s*base64_decode\s*\(/i',
        'eval_gzinflate'     => '/eval\s*\(\s*gzinflate\s*\(/i',
        'eval_str_rot13'     => '/eval\s*\(\s*str_rot13\s*\(/i',
        'preg_replace_e'     => '/preg_replace\s*\(\s*[\'"].*\/e[\'"\s]/i',
        'base64_exec'        => '/base64_decode\s*\(\s*[\'"][A-Za-z0-9+\/]{50,}/i',
        'assert_exec'        => '/assert\s*\(\s*\$_(GET|POST|REQUEST|COOKIE)/i',
        'webshell_passthru'  => '/passthru\s*\(\s*\$_(GET|POST|REQUEST|COOKIE)/i',
        'webshell_system'    => '/system\s*\(\s*\$_(GET|POST|REQUEST|COOKIE)/i',
        'webshell_exec'      => '/\bexec\s*\(\s*\$_(GET|POST|REQUEST|COOKIE)/i',
        'webshell_popen'     => '/popen\s*\(\s*\$_(GET|POST|REQUEST|COOKIE)/i',
        'backdoor_create'    => '/create_function\s*\(\s*[\'"][\'"],\s*\$_/i',
        'obfuscated_chr'     => '/chr\s*\(\s*\d+\s*\)\s*\.\s*chr\s*\(\s*\d+/i',
        'hex_obfuscation'    => '/\\\\x[0-9a-fA-F]{2}\\\\x[0-9a-fA-F]{2}\\\\x[0-9a-fA-F]{2}/i',
        'shell_upload'       => '/move_uploaded_file.*\.(php|phtml|php3|php4|php5)/i',
        'disable_functions'  => '/disable_functions\s*=\s*/i',
    ];

    foreach ($patterns as $name => $pattern) {
        if (preg_match($pattern, $content, $matches)) {
            return [
                'threat_name'     => $name,
                'pattern_matched' => isset($matches[0]) ? substr($matches[0], 0, 255) : $name,
            ];
        }
    }
    return null;
}

function send_alert(int $site_id, string $type, string $severity, string $message, array $data = []): bool {
    try {
        $db = DB::getInstance();
        $db->execute(
            'INSERT INTO alerts (site_id, type, severity, message, data, is_read, created_at) VALUES (?,?,?,?,?,0,NOW())',
            [$site_id, $type, $severity, $message, json_encode($data)]
        );
        return true;
    } catch (Exception $e) {
        error_log('send_alert error: ' . $e->getMessage());
        return false;
    }
}
