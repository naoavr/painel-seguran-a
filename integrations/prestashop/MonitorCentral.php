<?php
/**
 * PrestaShop Module: Monitor Central
 * Compatible with PrestaShop 1.7+
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class MonitorCentral extends Module {
    private array $buffer    = [];
    private float $startTime = 0.0;

    public function __construct() {
        $this->name         = 'MonitorCentral';
        $this->tab          = 'administration';
        $this->version      = '2.4.0';
        $this->author       = 'Monitor Central';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '1.7', 'max' => _PS_VERSION_];
        $this->displayName  = $this->l('Monitor Central');
        $this->description  = $this->l('Security monitoring and traffic analysis for your PrestaShop store.');

        parent::__construct();
    }

    public function install(): bool {
        return parent::install()
            && $this->registerHook('actionFrontControllerAfterInit')
            && $this->registerHook('actionShutdown');
    }

    public function uninstall(): bool {
        Configuration::deleteByName('MC_API_URL');
        Configuration::deleteByName('MC_API_KEY');
        return parent::uninstall();
    }

    public function hookActionFrontControllerAfterInit(array $params): void {
        $this->startTime = microtime(true);
        $ip = $this->getClientIp();
        $this->buffer[] = [
            'ip'         => $ip,
            'url'        => 'https://' . Tools::getShopDomainSsl() . $_SERVER['REQUEST_URI'],
            'method'     => $_SERVER['REQUEST_METHOD'] ?? 'GET',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'referer'    => $_SERVER['HTTP_REFERER']    ?? '',
            'status_code' => 200,
        ];
    }

    public function hookActionShutdown(array $params): void {
        $apiUrl = Configuration::get('MC_API_URL');
        $apiKey = Configuration::get('MC_API_KEY');
        if (empty($apiUrl) || empty($apiKey)) return;

        $responseTime = microtime(true) - $this->startTime;

        if (!empty($this->buffer)) {
            $traffic = array_map(function ($item) use ($responseTime) {
                return array_merge($item, ['response_time' => round($responseTime, 4)]);
            }, $this->buffer);
            $this->sendToMonitor($apiUrl, $apiKey, 'traffic', $traffic);
        }

        $last  = error_get_last();
        $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
        if ($last && in_array($last['type'], $fatal, true)) {
            $this->sendToMonitor($apiUrl, $apiKey, 'errors', [[
                'type'    => $this->errnoToName($last['type']),
                'message' => $last['message'],
                'file'    => $last['file'],
                'line'    => $last['line'],
                'ip'      => $this->getClientIp(),
            ]]);
        }
    }

    private function sendToMonitor(string $apiUrl, string $apiKey, string $type, array $data): void {
        if (!extension_loaded('curl')) return;
        $payload = json_encode(['api_key' => $apiKey, 'type' => $type, 'data' => $data]);
        $ch = curl_init($apiUrl);
        if (!$ch) return;
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 2,
            CURLOPT_CONNECTTIMEOUT => 1,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_NOSIGNAL       => 1,
        ]);
        @curl_exec($ch);
        curl_close($ch);
    }

    public function getContent(): string {
        $output = '';
        if (Tools::isSubmit('mc_save') && Tools::getValue('mc_token') === Tools::getAdminToken('AdminModules')) {
            $apiUrl = Tools::getValue('mc_api_url');
            $apiKey = Tools::getValue('mc_api_key');
            if (!Validate::isUrl($apiUrl)) {
                $output .= $this->displayError($this->l('Invalid API URL'));
            } else {
                Configuration::updateValue('MC_API_URL', pSQL($apiUrl));
                Configuration::updateValue('MC_API_KEY', pSQL($apiKey));
                $output .= $this->displayConfirmation($this->l('Settings saved.'));
            }
        }
        return $output . $this->renderForm();
    }

    private function renderForm(): string {
        $apiUrl = Configuration::get('MC_API_URL', '');
        $apiKey = Configuration::get('MC_API_KEY', '');
        $token  = Tools::getAdminToken('AdminModules');
        $action = htmlspecialchars($_SERVER['REQUEST_URI'], ENT_QUOTES, 'UTF-8');
        return '<form method="POST" action="' . $action . '">'
            . '<input type="hidden" name="mc_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">'
            . '<table class="table">'
            . '<tr><td><label>API URL</label></td>'
            . '<td><input type="url" name="mc_api_url" value="' . htmlspecialchars($apiUrl, ENT_QUOTES, 'UTF-8') . '" class="fixed-width-xxl"></td></tr>'
            . '<tr><td><label>API Key</label></td>'
            . '<td><input type="text" name="mc_api_key" value="' . htmlspecialchars($apiKey, ENT_QUOTES, 'UTF-8') . '" class="fixed-width-xxl"></td></tr>'
            . '</table>'
            . '<button type="submit" name="mc_save" class="btn btn-default pull-right">Save</button>'
            . '</form>';
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

    public function getConfigFieldsValues(): array {
        return [
            'MC_API_URL' => Configuration::get('MC_API_URL', ''),
            'MC_API_KEY' => Configuration::get('MC_API_KEY', ''),
        ];
    }

    private function errnoToName(int $errno): string {
        $map = [E_ERROR => 'E_ERROR', E_PARSE => 'E_PARSE', E_CORE_ERROR => 'E_CORE_ERROR', E_COMPILE_ERROR => 'E_COMPILE_ERROR'];
        return $map[$errno] ?? 'E_UNKNOWN';
    }
}
