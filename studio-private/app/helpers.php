<?php
declare(strict_types=1);
namespace Studio;

function config(string $key, mixed $default = null): mixed { global $config; return $config[$key] ?? $default; }
function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function url(string $page = '', array $params = []): string {
    $base = rtrim((string)config('app_url'), '/') . '/index.php';
    $query = $page !== '' ? ['page' => $page] + $params : $params;
    return $base . ($query ? '?' . http_build_query($query) : '');
}
function asset(string $path): string { return rtrim((string)config('app_url'), '/') . '/assets/' . $path; }
function redirect(string $page = '', array $params = []): never { header('Location: ' . url($page, $params), true, 303); exit; }
function flash(string $type, string $message): void { $_SESSION['flashes'][] = [$type, $message]; }
function render(string $view, array $data = []): void { extract($data, EXTR_SKIP); ob_start(); require STUDIO_ROOT . '/views/' . $view . '.php'; $body = ob_get_clean(); require STUDIO_ROOT . '/views/layout.php'; }
function now(): string { return gmdate('Y-m-d H:i:s'); }
function thai_date(?string $date): string { return $date ? (new \DateTimeImmutable($date, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Asia/Bangkok'))->format('d/m/Y H:i') : '—'; }
function size_label(int|float $bytes): string { return $bytes >= 1073741824 ? number_format($bytes / 1073741824, 2) . ' GiB' : number_format($bytes / 1048576, 1) . ' MiB'; }
function path_within(string $path, string $root): bool {
    $path = str_replace('\\', '/', $path); $root = rtrim(str_replace('\\', '/', $root), '/');
    if (PHP_OS_FAMILY === 'Windows') { $path = strtolower($path); $root = strtolower($root); }
    return $path === $root || str_starts_with($path, $root . '/');
}
function int_id(mixed $id): int { if (!is_scalar($id) || !ctype_digit((string)$id) || (int)$id <= 0) { throw new \DomainException('รหัสรายการไม่ถูกต้อง'); } return (int)$id; }
function text_input(string $key, int $max, bool $required = false): string {
    $value = $_POST[$key] ?? '';
    if (!is_string($value)) { throw new \DomainException('รูปแบบข้อมูลไม่ถูกต้อง'); }
    $value = trim($value);
    if (($required && $value === '') || mb_strlen($value) > $max || str_contains($value, "\0")) { throw new \DomainException('กรุณาตรวจข้อมูล ' . $key . ' และความยาวไม่เกิน ' . $max . ' ตัวอักษร'); }
    return $value;
}
function password_input(string $key, int $max = 255): string {
    $value = $_POST[$key] ?? null;
    if (!is_string($value) || $value === '' || strlen($value) > $max || str_contains($value, "\0")) { throw new \DomainException('กรุณาตรวจรหัสผ่านและความยาวที่กำหนด'); }
    return $value;
}
function platform_label(string $platform): string { return ['facebook_page' => 'Facebook Page', 'youtube' => 'YouTube Shorts', 'facebook_personal' => 'Facebook ส่วนตัว'][$platform] ?? $platform; }
function publish_summary(array $targets): array {
    $auto=0;foreach(['facebook_page','youtube'] as $p)if(($targets[$p]['target_status']??'')==='PUBLISHED')$auto++;
    $manual=($targets['facebook_personal']['target_status']??'')==='MANUAL_COMPLETED';
    return ['automatic'=>$auto,'manual'=>$manual,'status'=>$auto===2&&$manual?'COMPLETE_SUCCESS':($auto>0||$manual?'PARTIAL_SUCCESS':'WAITING')];
}
function status_label(string $status): string { return ['DRAFT' => 'ร่าง', 'READY_FOR_REVIEW' => 'รออนุมัติ', 'APPROVED' => 'อนุมัติแล้ว', 'PUBLISHED' => 'เผยแพร่แล้ว', 'NOT_CONNECTED' => 'ยังไม่เชื่อมต่อ API', 'MANUAL_ONLY' => 'ดำเนินการด้วยตนเอง', 'DISABLED' => 'ปิดใช้งาน', 'WAITING_MANUAL_POST' => 'รอโพสต์ด้วยตนเอง', 'WAITING_PAGE_PUBLICATION' => 'รอเพจเผยแพร่', 'MANUAL_COMPLETED' => 'ผู้ดูแลยืนยันแล้ว', 'VALIDATED' => 'ผ่านการตรวจไฟล์', 'FAILED' => 'ตรวจไฟล์ไม่ผ่าน', 'PHASE2_REQUIRED' => 'รอจัดคิว API', 'CONNECTED'=>'เชื่อมต่อแล้ว', 'DISCONNECTED'=>'ตัดการเชื่อมต่อแล้ว', 'READY'=>'รอประมวลผล', 'QUEUED'=>'อยู่ในคิว', 'RUNNING'=>'กำลังทำงาน', 'UPLOADING'=>'กำลังอัปโหลด', 'PROCESSING'=>'แพลตฟอร์มกำลังประมวลผล', 'AUTH_REQUIRED'=>'ต้องเชื่อมบัญชี/ตรวจสิทธิ์', 'RETRY_WAIT'=>'รอลองใหม่', 'CANCELLED'=>'ยกเลิกแล้ว', 'UNKNOWN_REMOTE_STATE'=>'ต้องตรวจผลบนแพลตฟอร์ม'][$status] ?? $status; }
function audit(string $action, string $entity, ?int $id, array $details = []): void {
    Db::run('INSERT INTO audit_logs (admin_id, actor_type, action, entity_type, entity_id, details_json, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)', [$_SESSION['admin_id'] ?? null, isset($_SESSION['admin_id']) ? 'admin' : 'system', $action, $entity, $id, json_encode($details, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), now()]);
}

