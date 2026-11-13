<?php
declare(strict_types=1);
namespace Studio;

final class Auth {
    public static function user(): ?array { return isset($_SESSION['admin_id']) ? Db::one('SELECT id, email, display_name FROM admin_users WHERE id = ? AND active = 1', [$_SESSION['admin_id']]) : null; }
    public static function requireLogin(): array { $user = self::user(); if (!$user) { redirect('login'); } return $user; }
    public static function login(string $email, string $password): bool {
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $ipKey = hash_hmac('sha256', $ip, Security::key());
        $identity = hash_hmac('sha256', strtolower($email) . '|' . $ip, Security::key());
        $since = gmdate('Y-m-d H:i:s', time() - 900);
        $counts = Db::one('SELECT SUM(CASE WHEN identity_hash = ? THEN 1 ELSE 0 END) AS identity_count, COUNT(*) AS ip_count FROM login_attempts WHERE ip_hash = ? AND attempted_at >= ?', [$identity, $ipKey, $since]);
        if ((int)$counts['identity_count'] >= 10 || (int)$counts['ip_count'] >= 30) { throw new \DomainException('พยายามเข้าสู่ระบบหลายครั้ง กรุณารอ 15 นาที'); }
        $user = Db::one('SELECT * FROM admin_users WHERE email = ? AND active = 1', [strtolower($email)]);
        $valid = password_verify($password, $user['password_hash'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
        if (!$user || !$valid) { Db::run('INSERT INTO login_attempts (identity_hash, ip_hash, attempted_at) VALUES (?, ?, ?)', [$identity, $ipKey, now()]); return false; }
        session_regenerate_id(true); $_SESSION['admin_id'] = (int)$user['id']; unset($_SESSION['csrf']);
        Db::run('UPDATE admin_users SET last_login_at = ? WHERE id = ?', [now(), $user['id']]);
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) { Db::run('UPDATE admin_users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $user['id']]); }
        Db::run('DELETE FROM login_attempts WHERE attempted_at < ?', [gmdate('Y-m-d H:i:s', time() - 86400)]);
        audit('login', 'admin_users', (int)$user['id']); return true;
    }
    public static function logout(): void { audit('logout', 'admin_users', (int)$_SESSION['admin_id']); $_SESSION = []; session_regenerate_id(true); }
}

