<?php
/**
 * Plugin Name: Monitor Central
 * Plugin URI:  https://github.com/your-org/monitor-central
 * Description: Integrates your WordPress site with Monitor Central security dashboard.
 * Version:     2.4.0
 * Author:      Monitor Central
 * License:     GPL2
 */

if (!defined('ABSPATH')) {
    exit;
}

class MonitorCentralPlugin {
    private static ?MonitorCentralPlugin $instance = null;
    private array  $buffer    = [];
    private float  $startTime = 0.0;

    private function __construct() {}

    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init(): void {
        add_action('init',     [$this, 'captureRequest']);
        add_action('shutdown', [$this, 'sendData']);
        add_action('admin_menu', [$this, 'addAdminMenu']);
        add_action('admin_init', [$this, 'registerSettings']);
        $this->startTime = microtime(true);
    }

    public function captureRequest(): void {
        if (wp_is_json_request() && !is_admin()) {
            return;
        }
        $ip = $this->getClientIp();
        $this->buffer[] = [
            'ip'          => $ip,
            'url'         => (is_ssl() ? 'https' : 'http') . '://' . sanitize_text_field($_SERVER['HTTP_HOST'] ?? '') . sanitize_text_field($_SERVER['REQUEST_URI'] ?? '/'),
            'method'      => sanitize_text_field($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            'user_agent'  => sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'referer'     => sanitize_text_field($_SERVER['HTTP_REFERER'] ?? ''),
            'status_code' => http_response_code() ?: 200,
        ];
    }

    public function sendData(): void {
        $apiUrl = sanitize_url(get_option('mc_api_url', ''));
        $apiKey = sanitize_text_field(get_option('mc_api_key', ''));
        if (empty($apiUrl) || empty($apiKey)) return;

        $responseTime = microtime(true) - $this->startTime;

        // Check for fatal error
        $last  = error_get_last();
        $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
        if ($last && in_array($last['type'], $fatal, true)) {
            $this->sendPayload($apiUrl, $apiKey, 'errors', [[
                'type'    => $this->errnoToName($last['type']),
                'message' => $last['message'],
                'file'    => $last['file'],
                'line'    => $last['line'],
                'url'     => sanitize_text_field($_SERVER['REQUEST_URI'] ?? '/'),
                'ip'      => $this->getClientIp(),
            ]]);
        }

        if (!empty($this->buffer)) {
            $traffic = array_map(function ($item) use ($responseTime) {
                return array_merge($item, ['response_time' => round($responseTime, 4)]);
            }, $this->buffer);
            $this->sendPayload($apiUrl, $apiKey, 'traffic', $traffic);
        }
    }

    private function sendPayload(string $apiUrl, string $apiKey, string $type, array $data): void {
        wp_remote_post($apiUrl, [
            'body'    => wp_json_encode(['api_key' => $apiKey, 'type' => $type, 'data' => $data]),
            'headers' => ['Content-Type' => 'application/json'],
            'timeout' => 2,
            'blocking' => false,
            'sslverify' => false,
        ]);
    }

    public function addAdminMenu(): void {
        add_options_page(
            'Monitor Central',
            'Monitor Central',
            'manage_options',
            'monitor-central',
            [$this, 'renderSettingsPage']
        );
    }

    public function registerSettings(): void {
        register_setting('mc_settings', 'mc_api_url',  ['sanitize_callback' => 'sanitize_url']);
        register_setting('mc_settings', 'mc_api_key',  ['sanitize_callback' => 'sanitize_text_field']);
    }

    public function renderSettingsPage(): void {
        if (!current_user_can('manage_options')) return;
        if (isset($_POST['mc_save']) && check_admin_referer('mc_settings_nonce')) {
            update_option('mc_api_url', sanitize_url(wp_unslash($_POST['mc_api_url'] ?? '')));
            update_option('mc_api_key', sanitize_text_field(wp_unslash($_POST['mc_api_key'] ?? '')));
            echo '<div class="updated"><p>Settings saved.</p></div>';
        }
        $apiUrl = esc_attr(get_option('mc_api_url', ''));
        $apiKey = esc_attr(get_option('mc_api_key', ''));
        ?>
        <div class="wrap">
          <h1>Monitor Central Settings</h1>
          <form method="post" action="">
            <?php wp_nonce_field('mc_settings_nonce'); ?>
            <table class="form-table">
              <tr>
                <th><label for="mc_api_url">API URL</label></th>
                <td>
                  <input type="url" id="mc_api_url" name="mc_api_url"
                         value="<?= $apiUrl ?>"
                         class="regular-text" placeholder="https://your-monitor.com/ingest.php">
                </td>
              </tr>
              <tr>
                <th><label for="mc_api_key">API Key</label></th>
                <td>
                  <input type="text" id="mc_api_key" name="mc_api_key"
                         value="<?= $apiKey ?>"
                         class="regular-text" placeholder="Your site API key">
                </td>
              </tr>
            </table>
            <?php submit_button('Save Settings', 'primary', 'mc_save'); ?>
          </form>
        </div>
        <?php
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
        return sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    private function errnoToName(int $errno): string {
        $map = [E_ERROR => 'E_ERROR', E_PARSE => 'E_PARSE', E_CORE_ERROR => 'E_CORE_ERROR', E_COMPILE_ERROR => 'E_COMPILE_ERROR'];
        return $map[$errno] ?? 'E_UNKNOWN';
    }
}

MonitorCentralPlugin::getInstance()->init();
