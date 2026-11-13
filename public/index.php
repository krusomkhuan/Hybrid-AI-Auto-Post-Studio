<?php
declare(strict_types=1);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// In production /DOMAIN/studio is public; studio-private is a sibling of DOMAIN.
$privateRoot = getenv('STUDIO_PRIVATE_ROOT') ?: dirname(__DIR__, 2) . '/studio-private';
if (!is_file($privateRoot . '/config.php') || !is_file($privateRoot . '/app/bootstrap.php')) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo '<!doctype html><html lang="th"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Studio · รอตั้งค่า</title><body style="font-family:system-ui;max-width:720px;margin:10vh auto;padding:24px"><h1>Hybrid AI Auto Post Studio</h1><p>ไฟล์หน้าเว็บพร้อมแล้ว กรุณาติดตั้งโฟลเดอร์ส่วนตัวและตั้งค่า config.php ตามคู่มือติดตั้งก่อนเริ่มใช้งาน</p><p>ห้ามนำไฟล์ Config หรือรหัสผ่านฐานข้อมูลมาใส่ในโฟลเดอร์ studio ที่เปิดผ่านเว็บได้</p></body></html>';
    exit;
}
require $privateRoot . '/app/bootstrap.php';
require $privateRoot . '/app/controller.php';

