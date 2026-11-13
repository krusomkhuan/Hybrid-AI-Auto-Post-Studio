<?php
declare(strict_types=1);
namespace Studio;

final class Security {
    public static function token(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
    public static function field(): string { return '<input type="hidden" name="csrf" value="' . e(self::token()) . '">'; }
    public static function verify(): void {
        if (!is_string($_POST['csrf'] ?? null) || !hash_equals(self::token(), $_POST['csrf'])) { http_response_code(419); throw new \DomainException('หน้าฟอร์มหมดอายุ กรุณาโหลดหน้าใหม่แล้วลองอีกครั้ง'); }
    }
    public static function key(): string {
        $file = STUDIO_ROOT . '/var/application.key';
        $key = is_file($file) ? file_get_contents($file) : false;
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) { throw new \RuntimeException('Application key unavailable'); }
        return $key;
    }
    public static function encrypt(string $value): string { $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES); return base64_encode($nonce . sodium_crypto_secretbox($value, $nonce, self::key())); }
    public static function decrypt(string $value): string {
        $raw = base64_decode($value, true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) { throw new \RuntimeException('Invalid ciphertext'); }
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), self::key());
        if ($plain === false) { throw new \RuntimeException('Token authentication failed'); } return $plain;
    }
    public static function checkUrl(string $value, array $hosts): string {
        $parts = parse_url($value);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || !in_array(strtolower($parts['host'] ?? ''), $hosts, true) || isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && $parts['port'] !== 443)) { throw new \DomainException('กรุณาใช้ลิงก์ HTTPS ของแพลตฟอร์มที่ถูกต้อง'); }
        return $value;
    }
}

