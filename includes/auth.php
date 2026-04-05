<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/csrf.php';

function auth_session_start(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

auth_session_start();

function is_logged_in(): bool {
    if (empty($_SESSION['auth_token']) || empty($_SESSION['user_id'])) {
        return false;
    }
    try {
        $db = DB::getInstance();
        $session = $db->fetchOne(
            'SELECT ds.id, ds.expires_at, du.is_active
             FROM dashboard_sessions ds
             JOIN dashboard_users du ON du.id = ds.user_id
             WHERE ds.session_token = ? AND ds.user_id = ? AND ds.expires_at > NOW()',
            [$_SESSION['auth_token'], $_SESSION['user_id']]
        );
        if (!$session || !$session['is_active']) {
            return false;
        }
        return true;
    } catch (Exception $e) {
        error_log('is_logged_in error: ' . $e->getMessage());
        return false;
    }
}

function require_auth(): void {
    if (!is_logged_in()) {
        $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
        header('Location: ' . $base . '/index.php');
        exit;
    }
}

function login_user(int $user_id, string $ip, string $user_agent): bool {
    try {
        $db = DB::getInstance();
        $token = bin2hex(random_bytes(64));
        $expires = date('Y-m-d H:i:s', time() + SESSION_LIFETIME);

        $db->execute(
            'INSERT INTO dashboard_sessions (user_id, session_token, ip_address, user_agent, expires_at) VALUES (?,?,?,?,?)',
            [$user_id, $token, $ip, $user_agent, $expires]
        );
        $db->execute('UPDATE dashboard_users SET last_login = NOW() WHERE id = ?', [$user_id]);

        session_regenerate_id(true);
        $_SESSION['auth_token'] = $token;
        $_SESSION['user_id']    = $user_id;

        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return true;
    } catch (Exception $e) {
        error_log('login_user error: ' . $e->getMessage());
        return false;
    }
}

function logout_user(): void {
    if (!empty($_SESSION['auth_token'])) {
        try {
            $db = DB::getInstance();
            $db->execute('DELETE FROM dashboard_sessions WHERE session_token = ?', [$_SESSION['auth_token']]);
        } catch (Exception $e) {
            error_log('logout_user error: ' . $e->getMessage());
        }
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }
    session_destroy();
}

function get_current_user_data(): ?array {
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    try {
        $db = DB::getInstance();
        return $db->fetchOne(
            'SELECT id, username, email, created_at, last_login FROM dashboard_users WHERE id = ? AND is_active = 1',
            [$_SESSION['user_id']]
        );
    } catch (Exception $e) {
        error_log('get_current_user_data error: ' . $e->getMessage());
        return null;
    }
}

function check_rate_limit(string $ip): bool {
    try {
        $db = DB::getInstance();
        $row = $db->fetchOne("SELECT config_value FROM system_config WHERE config_key = 'login_attempts'");
        $max = (int)($db->fetchOne("SELECT config_value FROM system_config WHERE config_key = 'max_login_attempts'")['config_value'] ?? 5);
        $attempts = json_decode($row['config_value'] ?? '{}', true) ?: [];

        $now = time();
        $window = 60;

        if (!isset($attempts[$ip])) {
            $attempts[$ip] = [];
        }
        $attempts[$ip] = array_filter($attempts[$ip], fn($t) => ($now - $t) < $window);

        if (count($attempts[$ip]) >= $max) {
            return false;
        }
        return true;
    } catch (Exception $e) {
        error_log('check_rate_limit error: ' . $e->getMessage());
        return true;
    }
}

function record_login_attempt(string $ip): void {
    try {
        $db = DB::getInstance();
        $row = $db->fetchOne("SELECT config_value FROM system_config WHERE config_key = 'login_attempts'");
        $attempts = json_decode($row['config_value'] ?? '{}', true) ?: [];
        $now = time();
        if (!isset($attempts[$ip])) {
            $attempts[$ip] = [];
        }
        $attempts[$ip][] = $now;
        $attempts[$ip] = array_values(array_filter($attempts[$ip], fn($t) => ($now - $t) < 60));
        $db->execute(
            "UPDATE system_config SET config_value = ? WHERE config_key = 'login_attempts'",
            [json_encode($attempts)]
        );
    } catch (Exception $e) {
        error_log('record_login_attempt error: ' . $e->getMessage());
    }
}
