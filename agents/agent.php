<?php
/**
 * Monitor Central PHP Agent v2.4
 *
 * Usage:
 *   define('MC_API_URL', 'https://your-monitor.com/ingest.php');
 *   define('MC_API_KEY', 'your-api-key-here');
 *   require_once 'agent.php';
 */

if (!defined('MC_API_URL')) define('MC_API_URL', 'https://your-monitor.com/ingest.php');
if (!defined('MC_API_KEY')) define('MC_API_KEY', 'your-api-key');

class MonitorCentralAgent {
    private static ?MonitorCentralAgent $instance = null;
    private array $buffer    = [];
    private float $startTime = 0.0;
    private bool  $sent      = false;

    private function __construct() {}

    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init(): void {
        $this->startTime = microtime(true);
        register_shutdown_function([$this, 'shutdown']);
        set_error_handler([$this, 'errorHandler'], E_ALL);
    }

    public function captureTraffic(): void {
        $ip = $this->getClientIp();
        $url = $this->getCurrentUrl();
        $this->buffer[] = [
            'type' => 'traffic_record',
            'ip'   => $ip,
            'url'  => $url,
            'method'     => $_SERVER['REQUEST_METHOD'] ?? 'GET',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'referer'    => $_SERVER['HTTP_REFERER'] ?? '',
            'status_code' => http_response_code() ?: 200,
        ];
    }

    public function errorHandler(int $errno, string $errstr, string $errfile, int $errline): bool {
        $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
        if (in_array($errno, $fatal, true)) {
            $this->buffer[] = [
                'type'    => 'error_record',
                'err_type' => $this->errnoToName($errno),
                'message' => $errstr,
                'file'    => $errfile,
                'line'    => $errline,
                'url'     => $this->getCurrentUrl(),
                'ip'      => $this->getClientIp(),
            ];
        }
        return false;
    }

    public function shutdown(): void {
        if ($this->sent) return;
        $this->sent = true;

        $last = error_get_last();
        $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
        if ($last && in_array($last['type'], $fatal, true)) {
            $this->buffer[] = [
                'type'     => 'error_record',
                'err_type' => $this->errnoToName($last['type']),
                'message'  => $last['message'],
                'file'     => $last['file'],
                'line'     => $last['line'],
                'url'      => $this->getCurrentUrl(),
                'ip'       => $this->getClientIp(),
            ];
        }

        $responseTime = microtime(true) - $this->startTime;

        $traffic = [];
        $errors  = [];
        foreach ($this->buffer as $item) {
            if ($item['type'] === 'traffic_record') {
                $traffic[] = [
                    'ip'           => $item['ip'],
                    'url'          => $item['url'],
                    'method'       => $item['method'],
                    'user_agent'   => $item['user_agent'],
                    'referer'      => $item['referer'],
                    'status_code'  => http_response_code() ?: 200,
                    'response_time' => round($responseTime, 4),
                ];
            } elseif ($item['type'] === 'error_record') {
                $errors[] = [
                    'type'    => $item['err_type'],
                    'message' => $item['message'],
                    'file'    => $item['file'],
                    'line'    => $item['line'],
                    'url'     => $item['url'],
                    'ip'      => $item['ip'],
                ];
            }
        }

        if (!empty($traffic)) {
            $this->sendData('traffic', $traffic);
        }
        if (!empty($errors)) {
            $this->sendData('errors', $errors);
        }

        // Heartbeat every 5 minutes
        $flagFile = sys_get_temp_dir() . '/mc_heartbeat_' . md5(MC_API_KEY);
        if (!is_file($flagFile) || (time() - filemtime($flagFile)) > 300) {
            @touch($flagFile);
            $this->sendData('heartbeat', ['http_status' => http_response_code() ?: 200]);
        }
    }

    private function sendData(string $type, array $data): void {
        if (!extension_loaded('curl')) return;

        $payload = json_encode([
            'api_key' => MC_API_KEY,
            'type'    => $type,
            'data'    => $data,
        ]);

        $ch = curl_init(MC_API_URL);
        if (!$ch) return;
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 1,
            CURLOPT_CONNECTTIMEOUT => 1,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        @curl_exec($ch);
        curl_close($ch);
    }

    public function checkWatchList(): array {
        if (!extension_loaded('curl')) return [];
        $payload = json_encode(['api_key' => MC_API_KEY, 'type' => 'get_watch_list', 'data' => []]);
        $ch = curl_init(MC_API_URL);
        if (!$ch) return [];
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        if (!$response) return [];
        $decoded = json_decode($response, true);
        if (!isset($decoded['data']) || !is_array($decoded['data'])) return [];

        $changes = [];
        foreach ($decoded['data'] as $entry) {
            $path = $entry['file_path'] ?? '';
            if (!is_file($path)) {
                $changes[] = ['file_path' => $path, 'change_type' => 'deleted', 'old_hash' => $entry['file_hash'] ?? '', 'new_hash' => '', 'old_size' => $entry['file_size'] ?? 0, 'new_size' => 0];
                continue;
            }
            $currentHash = md5_file($path);
            if ($currentHash !== ($entry['file_hash'] ?? '')) {
                $changes[] = [
                    'file_path'   => $path,
                    'change_type' => 'modified',
                    'old_hash'    => $entry['file_hash'] ?? '',
                    'new_hash'    => $currentHash,
                    'old_size'    => $entry['file_size'] ?? 0,
                    'new_size'    => filesize($path),
                ];
            }
        }
        return $changes;
    }

    private function getClientIp(): string {
        $headers = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
        foreach ($headers as $h) {
            if (!empty($_SERVER[$h])) {
                $ip = trim(explode(',', $_SERVER[$h])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    private function getCurrentUrl(): string {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $uri    = $_SERVER['REQUEST_URI'] ?? '/';
        return $scheme . '://' . $host . $uri;
    }

    private function errnoToName(int $errno): string {
        $map = [
            E_ERROR       => 'E_ERROR',
            E_PARSE       => 'E_PARSE',
            E_CORE_ERROR  => 'E_CORE_ERROR',
            E_COMPILE_ERROR => 'E_COMPILE_ERROR',
            E_WARNING     => 'E_WARNING',
            E_NOTICE      => 'E_NOTICE',
        ];
        return $map[$errno] ?? 'E_UNKNOWN';
    }
}

// Auto-initialize
MonitorCentralAgent::getInstance()->init();
MonitorCentralAgent::getInstance()->captureTraffic();
