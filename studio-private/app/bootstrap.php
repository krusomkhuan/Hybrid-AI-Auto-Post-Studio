<?php
declare(strict_types=1);

namespace Studio;

define('STUDIO_ROOT', dirname(__DIR__));
$config = require STUDIO_ROOT . '/config.php';
date_default_timezone_set('UTC');
ini_set('display_errors', '0');
ini_set('log_errors', '1');
require __DIR__ . '/helpers.php';
require __DIR__ . '/Db.php';
require __DIR__ . '/Security.php';
require __DIR__ . '/Auth.php';
require __DIR__ . '/Storage.php';
require __DIR__ . '/Media.php';
require __DIR__ . '/Content.php';
require __DIR__ . '/Integrations.php';
require __DIR__ . '/Publisher.php';
require __DIR__ . '/IntegrationApi.php';

set_exception_handler(function (\Throwable $error): void {
    $reference = bin2hex(random_bytes(6));
    error_log('Studio ' . $reference . ': ' . get_class($error) . ' ' . $error->getMessage());
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="th"><meta charset="utf-8"><title>Studio</title><body><h1>ระบบยังดำเนินการไม่ได้</h1><p>กรุณาตรวจการตั้งค่าและบันทึกข้อผิดพลาด รหัสอ้างอิง: ' . $reference . '</p></body></html>';
});

$url = parse_url((string) config('app_url'));
if (!$url || empty($url['host']) || !in_array($url['scheme'] ?? '', ['http', 'https'], true)) {
    throw new \RuntimeException('Invalid configured app_url');
}
if (config('environment') !== 'local') {
    if (($url['scheme'] ?? '') !== 'https') {
        throw new \RuntimeException('Production requires HTTPS app_url');
    }
    if (($_SERVER['HTTPS'] ?? '') !== 'on' && ($_SERVER['SERVER_PORT'] ?? '') != 443) {
        // Use the configured origin, never a client-supplied Host/Forwarded header.
        header('Location: ' . url(), true, 303);
        exit;
    }
    $docroot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
    foreach ([STUDIO_ROOT, (string) config('storage_path')] as $protected) {
        $real = realpath($protected) ?: $protected;
        if ($docroot && path_within($real, $docroot)) {
            throw new \RuntimeException('Private application/storage must be outside Document Root');
        }
    }
}

// Keep assets, OAuth state cookies and CSRF forms on the configured origin (www/non-www).
$defaultPort=($url['scheme']==='https'?443:80);
$configuredHost=strtolower($url['host'].(isset($url['port'])&&$url['port']!==$defaultPort?':'.$url['port']:''));
$requestHost=parse_url('http://'.($_SERVER['HTTP_HOST']??''));
$requestAuthority=strtolower(($requestHost['host']??'').(isset($requestHost['port'])&&$requestHost['port']!==$defaultPort?':'.$requestHost['port']:''));
if ($requestAuthority!==$configuredHost) {
    if (in_array($_SERVER['REQUEST_METHOD']??'', ['GET','HEAD'],true)) {
        header('Location: '.rtrim((string)config('app_url'),'/').'/index.php'.($_GET?'?'.http_build_query($_GET):''),true,303);exit;
    }
    http_response_code(400);exit('Please use the configured Studio URL.');
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; media-src 'self' blob:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self' https://www.facebook.com https://m.facebook.com https://accounts.google.com");
header('Cache-Control: no-store');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

$sessionPath = rtrim((string)($url['path'] ?? ''), '/') . '/';
session_name('hybrid_studio');
session_set_cookie_params(['lifetime' => 0, 'path' => $sessionPath, 'secure' => config('environment') !== 'local', 'httponly' => true, 'samesite' => 'Lax']);
ini_set('session.use_strict_mode', '1');
session_start();
if (isset($_SESSION['admin_id'], $_SESSION['last_activity']) && time() - (int)$_SESSION['last_activity'] > (int)config('session_idle_minutes', 60) * 60) {
    $_SESSION = [];
    session_regenerate_id(true);
    flash('warning', 'เซสชันหมดอายุ กรุณาเข้าสู่ระบบอีกครั้ง');
}
$_SESSION['last_activity'] = time();

