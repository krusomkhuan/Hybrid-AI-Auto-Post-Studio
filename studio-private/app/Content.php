<?php
declare(strict_types=1);
namespace Studio;

final class Content {
    public static function get(int $id, bool $lock = false): array {
        $content = Db::one('SELECT c.*, m.original_name, m.width, m.height, m.duration_ms, m.sha256, m.validation_status FROM content_items c LEFT JOIN media_assets m ON m.id=c.media_asset_id WHERE c.id=?' . ($lock ? ' FOR UPDATE' : ''), [$id]);
        if (!$content) { throw new \DomainException('ไม่พบคอนเทนต์'); }
        $content['targets'] = [];
        foreach (Db::all('SELECT t.*, a.display_name, a.enabled, a.connection_status FROM publish_targets t JOIN social_accounts a ON a.id=t.account_id WHERE t.content_id=?', [$id]) as $target) { $content['targets'][$target['platform']] = $target; }
        $content['captions'] = [];
        foreach (Db::all('SELECT * FROM content_captions WHERE content_id=?', [$id]) as $caption) { $content['captions'][$caption['platform']] = $caption; }
        $content['manual'] = Db::one('SELECT mt.* FROM manual_publish_tasks mt JOIN publish_targets t ON t.id=mt.target_id WHERE t.content_id=?', [$id]);
        return $content;
    }
    public static function save(array $data, int $adminId): int {
        return Db::transaction(function () use ($data, $adminId): int {
            $id = (int)($data['id'] ?? 0); $old = $id ? self::get($id, true) : null;
            if ($old && (int)$data['revision'] !== (int)$old['revision']) { throw new \DomainException('มีการแก้ไขคอนเทนต์นี้แล้ว กรุณาโหลดข้อมูลล่าสุด'); }
            if ($old) { self::assertEditable($old); }
            $asset = !empty($data['media_asset_id']) ? Db::one('SELECT * FROM media_assets WHERE id=? AND validation_status=?', [int_id($data['media_asset_id']), 'VALIDATED']) : null;
            if (!empty($data['media_asset_id']) && !$asset) { throw new \DomainException('กรุณาเลือกวิดีโอที่ผ่านการตรวจ'); }
            if ($asset && Db::one('SELECT id FROM content_items WHERE media_asset_id=? AND id<>?', [$asset['id'], $id])) { throw new \DomainException('วิดีโอนี้มีคอนเทนต์แล้ว กรุณาแก้รายการเดิม หนึ่งวิดีโอใช้ได้หนึ่งคอนเทนต์'); }
            $title = trim((string)$data['title_internal']);
            if ($title === '' || mb_strlen($title) > 190) { throw new \DomainException('ชื่อคอนเทนต์ต้องมี 1–190 ตัวอักษร'); }
            if (!in_array($data['manual_method'], ['SHARE_PAGE', 'UPLOAD_VIDEO'], true)) { throw new \DomainException('วิธีโพสต์ส่วนตัวไม่ถูกต้อง'); }
            if (!in_array($data['privacy'], ['private', 'unlisted', 'public'], true) || !in_array($data['audience'], ['made_for_kids', 'not_made_for_kids'], true)) { throw new \DomainException('การตั้งค่า YouTube ไม่ถูกต้อง'); }
            $scheduled = null;
            if ($data['scheduled_local'] !== '') {
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $data['scheduled_local'], new \DateTimeZone('Asia/Bangkok'));
                if (!$date || $date->format('Y-m-d\TH:i') !== $data['scheduled_local']) { throw new \DomainException('วันเวลาที่ตั้งไม่ถูกต้อง'); }
                $scheduled = $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            }
            $revision = $old ? (int)$old['revision'] + 1 : 1;
            if ($old) {
                // Editing requires all queued work to be cancelled, with no remote attempt ever started.
                $active = Db::one("SELECT j.id FROM publish_jobs j JOIN publish_targets t ON t.id=j.target_id WHERE t.content_id=? AND (j.status NOT IN ('PHASE2_REQUIRED','CANCELLED','AUTH_REQUIRED') OR EXISTS(SELECT 1 FROM publish_attempts p WHERE p.job_id=j.id))", [$id]);
                if ($active) { throw new \DomainException('คอนเทนต์มีงานที่เริ่มดำเนินการแล้ว ไม่สามารถแก้ไขได้'); }
                if(IntegrationSettings::migrated())Db::run('DELETE x FROM publish_transfers x JOIN publish_jobs j ON j.id=x.job_id JOIN publish_targets t ON t.id=j.target_id WHERE t.content_id=?',[$id]);
                Db::run('DELETE j FROM publish_jobs j JOIN publish_targets t ON t.id=j.target_id WHERE t.content_id=?', [$id]);
                Db::run('DELETE mt FROM manual_publish_tasks mt JOIN publish_targets t ON t.id=mt.target_id WHERE t.content_id=?', [$id]);
                Db::run('UPDATE content_items SET media_asset_id=?,title_internal=?,status=?,revision=?,desired_publish_at_utc=?,updated_at=? WHERE id=?', [$asset['id'] ?? null, $title, 'DRAFT', $revision, $scheduled, now(), $id]);
            } else {
                Db::run('INSERT INTO content_items (admin_id,media_asset_id,title_internal,status,revision,desired_publish_at_utc,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?)', [$adminId, $asset['id'] ?? null, $title, 'DRAFT', $revision, $scheduled, now(), now()]); $id = Db::id();
            }
            $personal = Db::one("SELECT id FROM social_accounts WHERE platform='facebook_personal' AND enabled=1");
            $accounts = ['facebook_page' => (int)$data['facebook_page_id'], 'youtube' => (int)$data['youtube_channel_id'], 'facebook_personal' => (int)($personal['id'] ?? 0)];
            foreach ($accounts as $platform => $accountId) {
                $existing = Db::one('SELECT id FROM publish_targets WHERE content_id=? AND platform=?', [$id, $platform]);
                if (!$accountId) { if ($existing) { Db::run('DELETE FROM publish_targets WHERE id=?', [$existing['id']]); } continue; }
                $account = Db::one('SELECT id FROM social_accounts WHERE id=? AND platform=? AND enabled=1', [$accountId, $platform]);
                if (!$account) { throw new \DomainException('บัญชีปลายทางไม่ถูกต้องหรือถูกปิดใช้งาน'); }
                if ($existing) { Db::run('UPDATE publish_targets SET account_id=?,approved_revision=NULL,target_status=?,privacy=?,audience=?,synthetic_media=? WHERE id=?', [$accountId, 'DRAFT', $data['privacy'], $data['audience'], (int)$data['synthetic_media'], $existing['id']]); }
                else { Db::run('INSERT INTO publish_targets (content_id,account_id,platform,target_status,privacy,audience,synthetic_media) VALUES (?,?,?,?,?,?,?)', [$id, $accountId, $platform, 'DRAFT', $data['privacy'], $data['audience'], (int)$data['synthetic_media']]); }
                $caption = (string)($data['captions'][$platform]['caption'] ?? ''); $tags = (string)($data['captions'][$platform]['hashtags'] ?? '');
                $ytTitle = $platform === 'youtube' ? (string)$data['youtube_title'] : '';
                if (mb_strlen($caption) > 4500 || mb_strlen($tags) > 400 || mb_strlen($ytTitle) > 100) { throw new \DomainException('ข้อความยาวเกินกำหนด Caption 4,500, Hashtags 400, YouTube Title 100 ตัวอักษร'); }
                Db::run('INSERT INTO content_captions (content_id,platform,caption,hashtags,youtube_title,revision) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE caption=VALUES(caption),hashtags=VALUES(hashtags),youtube_title=VALUES(youtube_title),revision=VALUES(revision)', [$id, $platform, $caption, $tags, $ytTitle, $revision]);
            }
            $personalTarget = Db::one("SELECT id FROM publish_targets WHERE content_id=? AND platform='facebook_personal'", [$id]);
            $pageTarget = Db::one("SELECT id FROM publish_targets WHERE content_id=? AND platform='facebook_page'", [$id]);
            if ($personalTarget) { Db::run('INSERT INTO manual_publish_tasks (target_id,method,status,source_page_target_id) VALUES (?,?,?,?)', [$personalTarget['id'], $data['manual_method'], $data['manual_method'] === 'SHARE_PAGE' ? 'WAITING_PAGE_PUBLICATION' : 'WAITING_MANUAL_POST', $data['manual_method'] === 'SHARE_PAGE' ? ($pageTarget['id'] ?? null) : null]); }
            audit($old ? 'content_revised' : 'content_created', 'content_items', $id, ['revision' => $revision]); return $id;
        });
    }
    private static function assertEditable(array $content): void {
        foreach ($content['targets'] as $target) { if ($target['remote_post_id'] || $target['published_at'] || $target['target_status'] === 'MANUAL_COMPLETED') { throw new \DomainException('มีปลายทางเผยแพร่แล้ว ไม่สามารถเปลี่ยนไฟล์หรือเนื้อหาได้'); } }
    }
    public static function submit(int $id, int $revision): void {
        Db::transaction(function () use ($id, $revision): void {
            $c = self::get($id, true);
            if ((int)$c['revision'] !== $revision || $c['status'] !== 'DRAFT') { throw new \DomainException('สถานะหรือ Revision เปลี่ยนแล้ว กรุณาโหลดใหม่'); }
            self::validateReady($c);
            Db::run('UPDATE content_items SET status=?,updated_at=? WHERE id=?', ['READY_FOR_REVIEW', now(), $id]); audit('content_submitted', 'content_items', $id, ['revision' => $revision]);
        });
    }
    private static function validateReady(array $c): void {
        if (!$c['media_asset_id'] || $c['validation_status'] !== 'VALIDATED') { throw new \DomainException('ต้องเลือกวิดีโอที่ผ่านการตรวจก่อน'); }
        foreach (['facebook_page', 'youtube', 'facebook_personal'] as $platform) {
            if (empty($c['targets'][$platform]) || !$c['targets'][$platform]['enabled']) { throw new \DomainException('กรุณาเลือกบัญชีปลายทางให้ครบและต้องเปิดใช้งาน'); }
            if (trim($c['captions'][$platform]['caption'] ?? '') === '') { throw new \DomainException('กรุณาใส่ Caption ให้ครบสามช่องทาง'); }
        }
        if (trim($c['captions']['youtube']['youtube_title'] ?? '') === '') { throw new \DomainException('กรุณาระบุชื่อวิดีโอ YouTube'); }
        $asset = Db::one('SELECT storage_disk,storage_key,sha256 FROM media_assets WHERE id=?', [$c['media_asset_id']]);
        if ($asset['storage_disk'] !== 'local' || !is_file(storage()->path($asset['storage_key'])) || !hash_equals($asset['sha256'], hash_file('sha256', storage()->path($asset['storage_key'])))) { throw new \DomainException('ไฟล์ต้นฉบับหายหรือเปลี่ยนแปลง กรุณาตรวจคลังวิดีโอ'); }
    }
    public static function approve(int $id, int $revision, int $adminId): void {
        Db::transaction(function () use ($id, $revision, $adminId): void {
            $c = self::get($id, true);
            if ($c['status'] !== 'READY_FOR_REVIEW' || (int)$c['revision'] !== $revision) { throw new \DomainException('Revision หรือสถานะไม่ตรงกับหน้าที่กำลังอนุมัติ'); }
            self::validateReady($c);
            if ($c['desired_publish_at_utc'] && $c['desired_publish_at_utc'] <= now()) { throw new \DomainException('เวลาที่ตั้งผ่านไปแล้ว กรุณาแก้ไขและส่งตรวจใหม่ หรือเลือกเผยแพร่เมื่อพร้อม'); }
            $snapshot = ['content_id' => $id, 'revision' => $revision, 'media_asset_id' => $c['media_asset_id'], 'sha256' => $c['sha256'], 'captions' => $c['captions'], 'targets' => $c['targets'], 'manual_method' => $c['manual']['method'], 'desired_publish_at_utc' => $c['desired_publish_at_utc']];
            $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            Db::run('INSERT INTO content_approvals (content_id,admin_id,revision,snapshot,snapshot_sha256,approved_at) VALUES (?,?,?,?,?,?)', [$id, $adminId, $revision, $json, hash('sha256', $json), now()]); $approvalId = Db::id();
            foreach ($c['targets'] as $platform => $target) {
                $status = $platform === 'facebook_personal' ? ($c['manual']['method'] === 'SHARE_PAGE' ? 'WAITING_PAGE_PUBLICATION' : 'WAITING_MANUAL_POST') : 'PHASE2_REQUIRED';
                Db::run('UPDATE publish_targets SET approved_revision=?,target_status=? WHERE id=?', [$revision, $status, $target['id']]);
                if ($platform !== 'facebook_personal') { Db::run('INSERT INTO publish_jobs (target_id,approval_id,operation_type,idempotency_key,status,due_at,created_at) VALUES (?,?,?,?,?,?,?)', [$target['id'], $approvalId, 'initial_publish', hash('sha256', $target['id'] . ':initial_publish'), 'PHASE2_REQUIRED', $c['desired_publish_at_utc'] ?? now(), now()]); }
            }
            Db::run('UPDATE content_items SET status=?,updated_at=? WHERE id=?', ['APPROVED', now(), $id]);
            audit('content_approved', 'content_items', $id, ['revision' => $revision, 'snapshot_sha256' => hash('sha256', $json), 'automation' => 'PHASE2_REQUIRED']);
        });
    }
    public static function completeManual(int $id, int $revision, string $postUrl, string $postedLocal, int $adminId): void {
        Security::checkUrl($postUrl, ['www.facebook.com', 'facebook.com', 'm.facebook.com', 'web.facebook.com', 'fb.watch']);
        $posted = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $postedLocal, new \DateTimeZone('Asia/Bangkok'));
        if (!$posted || $posted->format('Y-m-d\TH:i') !== $postedLocal || $posted->getTimestamp() > time() + 60) { throw new \DomainException('กรุณาระบุเวลาที่โพสต์จริงและต้องไม่เป็นเวลาในอนาคต'); }
        Db::transaction(function () use ($id, $revision, $postUrl, $posted, $adminId): void {
            $c = self::get($id, true); $task = $c['manual'];
            if ($c['status'] !== 'APPROVED' || (int)$c['revision'] !== $revision || !$task || $task['status'] === 'MANUAL_COMPLETED') { throw new \DomainException('คอนเทนต์ต้องอนุมัติแล้วและยังไม่ได้ยืนยันการโพสต์'); }
            if ($task['method'] === 'SHARE_PAGE' && ($c['targets']['facebook_page']['target_status'] !== 'PUBLISHED' || empty($c['targets']['facebook_page']['remote_url']))) { throw new \DomainException('ต้องรอ Facebook Page เผยแพร่สำเร็จก่อนแชร์จากเพจ'); }
            $publishedAt = $posted->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            Db::run('UPDATE manual_publish_tasks SET status=?,personal_post_url=?,confirmed_by=?,confirmed_at=?,posted_at=? WHERE id=?', ['MANUAL_COMPLETED', $postUrl, $adminId, now(), $publishedAt, $task['id']]);
            Db::run('UPDATE publish_targets SET target_status=?,remote_url=?,published_at=? WHERE id=?', ['MANUAL_COMPLETED', $postUrl, $publishedAt, $task['target_id']]);
            audit('manual_confirmed', 'content_items', $id, ['method' => $task['method'], 'evidence' => 'admin_confirmation', 'post_url' => $postUrl]);
        });
    }
}
