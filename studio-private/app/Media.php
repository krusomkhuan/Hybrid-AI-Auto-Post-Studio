<?php
declare(strict_types=1);
namespace Studio;

final class Media {
    public static function inspect(string $path): array {
        if (!is_file($path) || is_link($path)) { throw new \DomainException('ไม่พบไฟล์วิดีโอ'); }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $head = file_get_contents($path, false, null, 0, 32);
        if (!in_array($mime, ['video/mp4', 'application/mp4', 'video/quicktime'], true) || substr((string)$head, 4, 4) !== 'ftyp') { throw new \DomainException('รองรับไฟล์ MP4 ที่มี File Signature ถูกต้องเท่านั้น'); }
        require_once STUDIO_ROOT . '/vendor/getid3/getid3.php';
        $analyzer = new \getID3();
        $analyzer->option_tags_process = false;
        $info = $analyzer->analyze($path);
        $video = $info['video'] ?? [];
        $seconds = (float)($info['playtime_seconds'] ?? 0);
        $width = (int)($video['resolution_x'] ?? 0); $height = (int)($video['resolution_y'] ?? 0);
        $codec = (string)($video['fourcc'] ?? $video['codec'] ?? '');
        if (!empty($info['error']) || $width <= 0 || $height <= 0 || $seconds <= 0) { throw new \DomainException('อ่านโครงสร้าง MP4 ไม่สำเร็จ กรุณาส่งออกวิดีโอใหม่'); }
        if ($seconds < (float)config('min_duration_seconds', 30) - 0.1 || $seconds > (float)config('max_duration_seconds', 60) + 0.1) { throw new \DomainException('Phase 1 รับวิดีโอความยาว 30–60 วินาที'); }
        if (abs(($width / $height) - (9 / 16)) > 0.02) { throw new \DomainException('กรุณาใช้วิดีโอแนวตั้ง 9:16 โดยส่งออกไฟล์ให้ถูกต้องก่อนอัปโหลด'); }
        if (!preg_match('/avc1|h[.\s-]?264/i', $codec)) { throw new \DomainException('กรุณาส่งออก MP4 ด้วย Video Codec H.264 (AVC)'); }
        $audio = (string)($info['audio']['codec'] ?? $info['audio']['dataformat'] ?? '');
        if ($audio !== '' && !preg_match('/aac|mp4a/i', $audio)) { throw new \DomainException('เสียงต้องเป็น AAC หรือเป็นวิดีโอที่ไม่มีเสียง'); }
        return ['mime' => 'video/mp4', 'width' => $width, 'height' => $height, 'duration_ms' => (int)round($seconds * 1000), 'video_codec' => $codec, 'audio_codec' => $audio ?: null, 'warnings' => array_values($info['warning'] ?? []), 'validator' => 'getID3'];
    }
    public static function upload(array $file): int {
        $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($error !== UPLOAD_ERR_OK) { throw new \DomainException(match ($error) { UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'ไฟล์เกินขนาดที่ PHP/โฮสติ้งอนุญาต', UPLOAD_ERR_PARTIAL => 'อัปโหลดไม่ครบ กรุณาลองใหม่', default => 'กรุณาเลือกไฟล์ MP4 และตรวจพื้นที่อัปโหลดของโฮสติ้ง' }); }
        $tmp = (string)($file['tmp_name'] ?? '');
        if (!is_uploaded_file($tmp)) { throw new \DomainException('ไฟล์อัปโหลดไม่ถูกต้อง'); }
        $bytes = filesize($tmp);
        if (!$bytes || $bytes > (int)config('max_upload_bytes')) { throw new \DomainException('ไฟล์เกินขนาดสูงสุดของระบบ'); }
        $name = basename(str_replace('\\', '/', (string)$file['name']));
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'mp4' || mb_strlen($name) > 255) { throw new \DomainException('ชื่อไฟล์ต้องลงท้าย .mp4 และยาวไม่เกิน 255 ตัวอักษร'); }
        $meta = self::inspect($tmp);
        $hash = hash_file('sha256', $tmp);
        $root = storage()->root();
        if (!is_dir($root) && !mkdir($root, 0700, true) && !is_dir($root)) { throw new \RuntimeException('Cannot create media directory'); }
        $lock = fopen($root . '/.quota.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) { throw new \RuntimeException('Storage lock unavailable'); }
        $moved = false; $destination = storage()->path($hash . '.mp4');
        try {
            $existing = Db::one('SELECT id FROM media_assets WHERE sha256 = ?', [$hash]);
            if ($existing) { flash('info', 'ไฟล์นี้มีในคลังแล้ว ระบบใช้รายการเดิมเพื่อป้องกันวิดีโอซ้ำ'); return (int)$existing['id']; }
            if (storage()->usedBytes() + $bytes > (int)config('storage_budget_bytes')) { throw new \DomainException('พื้นที่เก็บไฟล์ของระบบถึงวงเงินที่ตั้งไว้ กรุณาตรวจพื้นที่ก่อนอัปโหลดเพิ่ม'); }
            if (is_file($destination)) { throw new \RuntimeException('Unregistered media file requires reconciliation'); }
            if (!move_uploaded_file($tmp, $destination)) { throw new \RuntimeException('Cannot store upload'); }
            $moved = true; chmod($destination, 0600);
            return Db::transaction(function () use ($meta, $hash, $name, $bytes): int {
                Db::run('INSERT INTO media_assets (storage_disk, storage_key, sha256, original_name, mime, size_bytes, width, height, duration_ms, video_codec, audio_codec, validation_status, validation_json, source_type, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', ['local', $hash . '.mp4', $hash, $name, $meta['mime'], $bytes, $meta['width'], $meta['height'], $meta['duration_ms'], $meta['video_codec'], $meta['audio_codec'], 'VALIDATED', json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'upload', now()]);
                $id = Db::id(); audit('media_uploaded', 'media_assets', $id, ['size_bytes' => $bytes, 'sha256' => $hash]); return $id;
            });
        } catch (\Throwable $error) { if ($moved && is_file($destination)) { unlink($destination); } throw $error; }
        finally { flock($lock, LOCK_UN); fclose($lock); }
    }
    public static function stream(array $media, bool $download): never {
        if ($media['storage_disk'] !== 'local') { throw new \DomainException('Storage Adapter นี้ยังไม่เปิดใช้'); }
        $path = storage()->path($media['storage_key']);
        if (!is_file($path) || is_link($path)) { http_response_code(404); exit; }
        $size = filesize($path); $start = 0; $end = $size - 1;
        if (isset($_SERVER['HTTP_RANGE'])) {
            $range = $_SERVER['HTTP_RANGE'];
            if (!preg_match('/\Abytes=(\d*)-(\d*)\z/D', $range, $match) || ($match[1] === '' && $match[2] === '')) { header('Content-Range: bytes */' . $size); http_response_code(416); exit; }
            if ($match[1] === '') { $suffix = (int)$match[2]; if ($suffix <= 0) { header('Content-Range: bytes */' . $size); http_response_code(416); exit; } $start = max(0, $size - $suffix); }
            else { $start = (int)$match[1]; if ($match[2] !== '') { $end = min($end, (int)$match[2]); } }
            if ($start >= $size || $start > $end) { header('Content-Range: bytes */' . $size); http_response_code(416); exit; }
            http_response_code(206); header("Content-Range: bytes $start-$end/$size");
        }
        header('Content-Type: video/mp4'); header('Accept-Ranges: bytes'); header('Content-Length: ' . ($end - $start + 1));
        header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="studio-video-' . (int)$media['id'] . '.mp4"');
        session_write_close();
        if ($_SERVER['REQUEST_METHOD'] === 'HEAD') { exit; }
        $handle = fopen($path, 'rb'); fseek($handle, $start); $remaining = $end - $start + 1;
        while ($remaining > 0 && !feof($handle) && !connection_aborted()) { $buffer = fread($handle, min(262144, $remaining)); if ($buffer === false || $buffer === '') { break; } echo $buffer; $remaining -= strlen($buffer); }
        fclose($handle); exit;
    }
}

