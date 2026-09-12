<?php
declare(strict_types=1);
namespace MTX;
final class Security
{
    public const SESSION_LIFETIME = 8 * 3600;
    public const REMEMBER_LIFETIME = 30 * 86400;

    private static function cookieOptions(App $app): array
    {
        return ['path' => $app->path('/admin'), 'secure' => !$app->config['local_http'], 'httponly' => true, 'samesite' => 'Strict'];
    }

    public static function session(App $app): void
    {
        session_name('mtx_admin');
        session_save_path($app->storage . '/sessions');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        // This application uses a private session directory. PHP's short default
        // garbage-collection lifetime must not discard remembered logins early.
        ini_set('session.gc_maxlifetime', (string)self::REMEMBER_LIFETIME);
        session_set_cookie_params(['lifetime' => 0] + self::cookieOptions($app));
        session_start();
        if (!isset($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    public static function loggedIn(App $app): bool
    {
        return ($_SESSION['authenticated'] ?? false) === true
            && ($_SESSION['expires'] ?? 0) > time()
            && hash_equals(hash('sha256', $app->config['password_hash']), (string)($_SESSION['credential'] ?? ''));
    }
    public static function csrf(): void
    {
        $token = $_POST['csrf'] ?? '';
        if (!is_string($token) || !hash_equals($_SESSION['csrf'], $token)) throw new Problem(403, '页面令牌已失效，请刷新页面重试。', 'csrf');
    }
    public static function login(App $app, string $password, bool $remember = false): void
    {
        // A separate stable file lock also serializes simultaneous password attempts.
        $lock = fopen($app->storage . '/login.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) throw new \RuntimeException('Login lock failed');
        try {
            $path = $app->storage . '/login-attempts.json';
            $data = is_file($path) ? json_decode(file_get_contents($path), true, 16, JSON_THROW_ON_ERROR) : [];
            $now = time();
            $data = array_filter($data, fn($row) => $row['until'] > $now);
            $ip = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'local'); // Never trust forwarded headers.
            $row = $data[$ip] ?? ['count' => 0, 'until' => $now + 900];
            if ($row['count'] >= 5 || count($data) >= 10000) throw new Problem(429, '登录尝试较多，请 15 分钟后再试。', 'rate_limited');
            if (strlen($password) > 72 || !password_verify($password, $app->config['password_hash'])) {
                $row['count']++;
                $data[$ip] = $row;
                Store::write($path, json_encode($data, JSON_THROW_ON_ERROR));
                throw new Problem(401, '密码不正确。', 'bad_password');
            }
            unset($data[$ip]);
            Store::write($path, json_encode($data, JSON_THROW_ON_ERROR));
        } finally { flock($lock, LOCK_UN); fclose($lock); }
        session_regenerate_id(true);
        $expires = time() + ($remember ? self::REMEMBER_LIFETIME : self::SESSION_LIFETIME);
        $_SESSION = ['authenticated' => true, 'expires' => $expires, 'csrf' => bin2hex(random_bytes(32)), 'credential' => hash('sha256', $app->config['password_hash'])];
        // Persist only the opaque session ID, never the password or its hash.
        // Expiration is also enforced server-side and does not slide on requests.
        setcookie(session_name(), session_id(), ['expires' => $remember ? $expires : 0] + self::cookieOptions($app));
        $app->store->change(function (&$s) { Store::audit($s, '管理员登录', 'admin'); });
    }
    public static function logout(App $app): void
    {
        $_SESSION = [];
        session_destroy();
        setcookie(session_name(), '', ['expires' => time() - 3600] + self::cookieOptions($app));
    }
    public static function nativeKeys(): array
    {
        $key = openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC, 'curve_name'=>'prime256v1']);
        if (!$key || !openssl_pkey_export($key, $pem)) throw new \RuntimeException('Key generation failed');
        $details = openssl_pkey_get_details($key);
        return ['native_sign_secret'=>$pem, 'native_sign_public'=>base64_encode("\x04" . str_pad($details['ec']['x'],32,"\0",STR_PAD_LEFT) . str_pad($details['ec']['y'],32,"\0",STR_PAD_LEFT))];
    }
    public static function sign(App $app, array $payload): array
    {
        $raw = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (!openssl_sign($raw, $nativeSignature, $app->config['native_sign_secret'], OPENSSL_ALGO_SHA256)) throw new \RuntimeException('Native signing failed');
        return ['native_signature_base64' => base64_encode($nativeSignature), 'key_id' => $app->config['key_id'], 'payload_base64' => base64_encode($raw), 'signature_base64' => base64_encode(sodium_crypto_sign_detached($raw, base64_decode($app->config['sign_secret'], true)))];
    }
    public static function ticket(App $app, string $release, string $sha, int $expires): string
    { return hash_hmac('sha256', $release . ':' . $sha . ':' . $expires, $app->config['ticket_secret']); }
}
