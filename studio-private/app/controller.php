<?php
declare(strict_types=1);
namespace Studio;

$page = is_string($_GET['page'] ?? null) ? $_GET['page'] : 'dashboard';
$allowed = ['dashboard','install','login','logout','library','upload','media','contents','edit','content','manual','accounts','settings','audit','integrations','queue','oauth_callback','workflow'];
if (!in_array($page, $allowed, true)) { http_response_code(404); render('message', ['title' => 'ไม่พบหน้านี้', 'message' => 'กรุณากลับไปยังหน้าหลัก']); exit; }
$requirements = [
    'PHP 8.4.27 ขึ้นไป' => version_compare(PHP_VERSION, '8.4.27', '>='),
    'PDO MySQL' => extension_loaded('pdo_mysql'), 'Fileinfo' => extension_loaded('fileinfo'),
    'mbstring' => extension_loaded('mbstring'), 'Sodium' => extension_loaded('sodium'),
    'OpenSSL' => extension_loaded('openssl'), 'cURL (เตรียม Phase 2)' => extension_loaded('curl'),
    'โฟลเดอร์ var เขียนได้' => is_dir(STUDIO_ROOT . '/var') && is_writable(STUDIO_ROOT . '/var'),
    'getID3 พร้อมใช้' => is_file(STUDIO_ROOT . '/vendor/getid3/getid3.php'),
];
if (in_array(false, $requirements, true)) { http_response_code(503); render('requirements', compact('requirements')); exit; }
try { $installed = (bool)Db::one('SELECT id FROM admin_users LIMIT 1'); }
catch (\PDOException $error) { if (($error->errorInfo[0] ?? '') === '42S02') { $installed = false; } else { throw $error; } }
if (!$installed) { $page = 'install'; }
elseif ($page === 'install') { redirect('login'); }

if ($page !== 'install' && $page !== 'login') { $user = Auth::requireLogin(); }
else { $user = null; }

$phase2Ready=$installed && IntegrationSettings::migrated();
if($page==='oauth_callback'){
    try{
        if(!$phase2Ready)throw new \DomainException('กรุณาอัปเกรดฐานข้อมูล Phase 2 ก่อน');
        $provider=is_string($_GET['provider']??null)?$_GET['provider']:'';
        if(!in_array($provider,['meta','google'],true))throw new \DomainException('Provider ไม่ถูกต้อง');
        $count=OAuth::complete($provider,is_string($_GET['state']??null)?$_GET['state']:'',is_string($_GET['code']??null)?$_GET['code']:'',(int)$user['id'],isset($_GET['error']));
        audit('oauth_connected','social_connections',null,['provider'=>$provider,'destinations'=>$count]);flash('success','เชื่อมบัญชีและนำเข้าปลายทางที่ได้รับอนุญาตแล้ว '.$count.' รายการ');
    }catch(ProviderError $e){flash('danger',$e->connectionMessage());}
    catch(\DomainException $e){flash('danger',$e->getMessage());}
    redirect('accounts');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) { throw new \DomainException('คำขอใหญ่เกิน post_max_size ของ PHP กรุณาลดขนาดคลิปหรือเพิ่มค่าผ่าน Control Panel'); }
        Security::verify();
        $action = text_input('action', 40, true);
        if ($page === 'install' && $action === 'install') {
            $key = (string)config('setup_key', '');
            if (strlen($key) < 32 || str_starts_with($key, 'CHANGE_') || !hash_equals($key, text_input('setup_key', 255, true))) { throw new \DomainException('Setup Key ไม่ถูกต้อง หรือยังไม่ได้แก้ค่าเริ่มต้นใน Config'); }
            $email = strtolower(text_input('email', 190, true)); $name = text_input('display_name', 100, true); $password = password_input('password', 72);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12 || strlen($password) > 72 || $password !== password_input('password_confirm', 72)) { throw new \DomainException('อีเมลต้องถูกต้อง รหัสผ่านยาว 12–72 ไบต์ และยืนยันให้ตรงกัน'); }
            $installLock = fopen(STUDIO_ROOT . '/var/install.mutex', 'c');
            if (!$installLock || !flock($installLock, LOCK_EX)) { throw new \RuntimeException('Install lock unavailable'); }
            try {
                $sql = file_get_contents(STUDIO_ROOT . '/database/001_phase1.sql');
                foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) as $statement) { if (trim($statement) !== '') { Db::connection()->exec($statement); } }
                if (Db::one('SELECT id FROM admin_users LIMIT 1')) { throw new \DomainException('ระบบติดตั้งแล้ว ไม่สามารถสร้างผู้ดูแลซ้ำ'); }
                $keyFile = STUDIO_ROOT . '/var/application.key';
                if (!is_file($keyFile)) { $handle = fopen($keyFile, 'x'); if (!$handle) { throw new \RuntimeException('Cannot create encryption key'); } fwrite($handle, random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)); fclose($handle); chmod($keyFile, 0600); }
                Security::key();
                $root = storage()->root();
                if (!is_dir($root) && !mkdir($root, 0700, true) && !is_dir($root)) { throw new \RuntimeException('Cannot create private storage'); }
                Db::transaction(function () use ($email, $name, $password): void {
                    Db::run('INSERT INTO admin_users (email,display_name,password_hash,created_at) VALUES (?,?,?,?)', [$email, $name, password_hash($password, PASSWORD_DEFAULT), now()]);
                    Db::run('INSERT INTO social_accounts (platform,external_account_id,display_name,connection_status,created_at,updated_at) VALUES (?,?,?,?,?,?)', ['facebook_personal','personal-admin','Facebook ส่วนตัว','MANUAL_ONLY',now(),now()]); $accountId = Db::id();
                    Db::run('INSERT INTO personal_profiles (account_id) VALUES (?)', [$accountId]);
                    Db::run('INSERT INTO account_monetization_history (account_id,status,effective_from,source) VALUES (?,?,?,?)', [$accountId,'MONETIZED',now(),'owner_master_prompt']);
                    Db::run('INSERT IGNORE INTO schema_migrations (version,applied_at) VALUES (?,?)', ['001_phase1',now()]);
                    audit('installed', 'system', null, ['version' => '1.0-phase1']);
                });
            } finally { flock($installLock, LOCK_UN); fclose($installLock); }
            flash('success', 'ติดตั้งสำเร็จ กรุณาลบ Setup Key ออกจาก Config และเข้าสู่ระบบ'); redirect('login');
        }
        if ($page === 'login' && $action === 'login') {
            if (Auth::login(text_input('email', 190, true), password_input('password'))) { redirect('dashboard'); }
            throw new \DomainException('อีเมลหรือรหัสผ่านไม่ถูกต้อง');
        }
        if (!$user) { throw new \DomainException('กรุณาเข้าสู่ระบบ'); }
        switch ($action) {
            case 'upgrade_phase2': IntegrationSettings::migrate();flash('success','อัปเกรดฐานข้อมูล Phase 2 แล้ว ข้อมูลเดิมยังคงอยู่');redirect('integrations');
            case 'save_integrations':
                if(!$phase2Ready)throw new \DomainException('กรุณาอัปเกรด Phase 2 ก่อน');
                $metaId=text_input('meta_client_id',60);$googleId=text_input('google_client_id',255);$graph=text_input('meta_graph_version',10);$configId=text_input('meta_config_id',60);
                if($metaId!==''&&!ctype_digit($metaId))throw new \DomainException('Meta App ID ต้องเป็นตัวเลข');
                if($configId!==''&&!ctype_digit($configId))throw new \DomainException('Login for Business Config ID ต้องเป็นตัวเลข');
                if($graph!==''&&!preg_match('/\Av[0-9]{1,2}\.0\z/D',$graph))throw new \DomainException('Graph Version ต้องเป็นรูปแบบ v27.0');
                if($googleId!==''&&!preg_match('/\A[A-Za-z0-9._-]+\.apps\.googleusercontent\.com\z/D',$googleId))throw new \DomainException('Google Client ID ต้องเป็น Web OAuth Client ID');
                foreach(['meta'=>$metaId,'google'=>$googleId] as $provider=>$clientId){
                    $previous=IntegrationSettings::get($provider.'_client_id');
                    if($previous!==''&&$previous!==$clientId&&$clientId!==''&&IntegrationSettings::get($provider.'_client_secret')!==''&&text_input($provider.'_client_secret',2048)==='')throw new \DomainException('เมื่อเปลี่ยน Client/App ID ต้องกรอก Secret ของแอปใหม่ด้วย');
                }
                if(isset($_POST['publishing_enabled'])&&!isset($_POST['publishing_ack']))throw new \DomainException('กรุณายืนยันว่าการประมวลผลคิวอาจเผยแพร่โพสต์จริง');
                Db::transaction(function()use($metaId,$googleId,$graph,$configId){
                    foreach(['meta'=>$metaId,'google'=>$googleId] as $provider=>$clientId){
                        $previous=IntegrationSettings::get($provider.'_client_id');
                        if($previous!==''&&$previous!==$clientId){
                            $connectionProvider=$provider==='google'?'youtube':'meta';
                            Db::run("UPDATE social_accounts a JOIN social_connections c ON c.id=a.connection_id SET a.connection_status='AUTH_REQUIRED' WHERE c.provider=?",[$connectionProvider]);
                            Db::run("UPDATE social_connections SET status='AUTH_REQUIRED' WHERE provider=?",[$connectionProvider]);
                            Db::run('DELETE FROM oauth_states WHERE provider=?',[$provider]);
                        }
                    }
                    foreach(['meta_client_id'=>$metaId,'google_client_id'=>$googleId,'meta_graph_version'=>$graph,'meta_config_id'=>$configId] as $k=>$v)IntegrationSettings::put($k,$v);
                    foreach(['meta_client_secret','google_client_secret'] as $k){$v=text_input($k,2048);if($v!=='')IntegrationSettings::putSecret($k,$v);}
                    IntegrationSettings::put('publishing_enabled',isset($_POST['publishing_enabled']));audit('integration_settings_saved','system',null,['publishing_enabled'=>isset($_POST['publishing_enabled'])]);
                });flash('success','บันทึกค่าการเชื่อมต่อแล้ว Secret ถูกเข้ารหัสและไม่แสดงกลับในฟอร์ม');redirect('integrations');
            case 'rotate_n8n_key':
                if(!$phase2Ready)throw new \DomainException('กรุณาอัปเกรด Phase 2 ก่อน');
                $key=bin2hex(random_bytes(32));IntegrationSettings::putSecret('n8n_hmac_secret',$key);$_SESSION['new_n8n_key']=$key;audit('n8n_key_rotated','system',null);redirect('integrations');
            case 'rotate_n8n_header_key':
                if(!$phase2Ready)throw new \DomainException('กรุณาอัปเกรด Phase 2 ก่อน');
                // Separate from the HMAC secret. Only a SHA-256 hash is stored; the key is shown once.
                $key=bin2hex(random_bytes(32));IntegrationSettings::put('n8n_header_key_hash',hash('sha256',$key));IntegrationSettings::put('n8n_header_key_created_at',now());$_SESSION['new_n8n_header_key']=$key;audit('n8n_header_key_rotated','system',null);redirect('integrations');
            case 'revoke_n8n_header_key':
                if(!$phase2Ready)throw new \DomainException('กรุณาอัปเกรด Phase 2 ก่อน');
                IntegrationSettings::put('n8n_header_key_hash','');IntegrationSettings::put('n8n_header_key_created_at','');audit('n8n_header_key_revoked','system',null);flash('success','ยกเลิก Header Key แล้ว Workflow ที่ใช้ Key นี้จะถูกปฏิเสธ ส่วน HMAC ยังใช้ได้ตามเดิม');redirect('integrations');
            case 'oauth_start':
                if(!$phase2Ready)throw new \DomainException('กรุณาอัปเกรด Phase 2 ก่อน');
                header('Location: '.OAuth::start(text_input('provider',20,true),(int)$user['id']),true,303);exit;
            case 'disconnect_account':
                if(!$phase2Ready)throw new \DomainException('กรุณาอัปเกรด Phase 2 ก่อน');
                OAuth::disconnect(int_id($_POST['account_id']??null));flash('success','ตัดการเชื่อมต่อปลายทางนี้ใน Studio แล้ว หากต้องเพิกถอนสิทธิ์แอปทั้งหมดให้ทำในแพลตฟอร์ม');redirect('accounts');
            case 'queue_content':
                if(!$phase2Ready)throw new \DomainException('กรุณาอัปเกรด Phase 2 ก่อน');
                $n=Publisher::arm(int_id($_POST['id']??null));flash('success','จัดคิวที่ได้รับอนุมัติแล้ว '.$n.' งาน');redirect('queue');
            case 'tick_queue':
                if(!$phase2Ready)throw new \DomainException('กรุณาอัปเกรด Phase 2 ก่อน');
                $r=Publisher::tick();audit('manual_queue_step','system',null,$r);flash('info','ผลประมวลผลหนึ่งขั้น: '.$r['status']);redirect('queue');
            case 'retry_job':
                if(!$phase2Ready)throw new \DomainException('กรุณาอัปเกรด Phase 2 ก่อน');
                Publisher::retry(int_id($_POST['job_id']??null));flash('success','จัดงานกลับเข้าคิวแล้ว ไม่สร้าง Upload ใหม่โดยไม่ตรวจสถานะ');redirect('queue');
            case 'cancel_job':
                if(!$phase2Ready)throw new \DomainException('กรุณาอัปเกรด Phase 2 ก่อน');
                Publisher::cancel(int_id($_POST['job_id']??null));flash('success','ยกเลิกงานที่ยังไม่ส่งแล้ว');redirect('queue');
            case 'reconcile_job':
                if(!$phase2Ready)throw new \DomainException('กรุณาอัปเกรด Phase 2 ก่อน');
                Publisher::reconcile(int_id($_POST['job_id']??null));flash('info','จัดคิวตรวจสถานะ Remote ID เดิมแล้ว ไม่เริ่ม Upload ใหม่');redirect('queue');
            case 'logout': Auth::logout(); redirect('login');
            case 'upload':
                $id = Media::upload($_FILES['video'] ?? []); flash('success', 'วิดีโอผ่านการตรวจและเพิ่มเข้าคลังแล้ว'); redirect('library', ['highlight' => $id]);
            case 'save_content':
                $captions = [];
                foreach (['facebook_page','youtube','facebook_personal'] as $platform) { $captions[$platform] = ['caption' => text_input('caption_' . $platform, 4500), 'hashtags' => text_input('hashtags_' . $platform, 400)]; }
                $id = Content::save(['id' => text_input('id', 20), 'revision' => text_input('revision', 20), 'media_asset_id' => text_input('media_asset_id', 20), 'title_internal' => text_input('title_internal', 190, true), 'facebook_page_id' => text_input('facebook_page_id', 20), 'youtube_channel_id' => text_input('youtube_channel_id', 20), 'manual_method' => text_input('manual_method', 30, true), 'privacy' => text_input('privacy', 20, true), 'audience' => text_input('audience', 30, true), 'synthetic_media' => isset($_POST['synthetic_media']), 'scheduled_local' => text_input('scheduled_local', 30), 'captions' => $captions, 'youtube_title' => text_input('youtube_title', 100)], (int)$user['id']);
                flash('success', 'บันทึกร่างแล้ว การแก้ไขต้องส่งตรวจและอนุมัติใหม่'); redirect('content', ['id' => $id]);
            case 'submit_content': Content::submit(int_id($_POST['id'] ?? null), int_id($_POST['revision'] ?? null)); flash('success', 'พร้อมให้ตรวจและอนุมัติแล้ว'); redirect('content', ['id' => int_id($_POST['id'])]);
            case 'approve_content':
                if (!isset($_POST['approval_ack'])) { throw new \DomainException('กรุณายืนยันว่าตรวจไฟล์ Caption และปลายทางแล้ว'); }
                Content::approve(int_id($_POST['id'] ?? null), int_id($_POST['revision'] ?? null), (int)$user['id']); flash('success', 'อนุมัติและบันทึก Snapshot แล้ว งานอัตโนมัติรอการจัดคิวและเชื่อมต่อบัญชี ส่วนตัวดำเนินการด้วยตนเอง'); redirect('content', ['id' => int_id($_POST['id'])]);
            case 'manual_complete':
                if (!isset($_POST['manual_ack'])) { throw new \DomainException('กรุณายืนยันว่าโพสต์ด้วยตนเองแล้วจริง'); }
                Content::completeManual(int_id($_POST['id'] ?? null), int_id($_POST['revision'] ?? null), text_input('post_url', 2048, true), text_input('posted_local', 30, true), (int)$user['id']); flash('success', 'บันทึกการยืนยันด้วยตนเองแล้ว ระบบไม่ได้ตรวจสอบโพสต์ผ่าน API'); redirect('content', ['id' => int_id($_POST['id'])]);
            case 'add_account':
                $platform = text_input('platform', 30, true); $externalId = text_input('external_account_id', 190, true); $name = text_input('display_name', 190, true);
                if (($platform === 'facebook_page' && !preg_match('/\A[0-9]{3,30}\z/D', $externalId)) || ($platform === 'youtube' && !preg_match('/\AUC[A-Za-z0-9_-]{22}\z/D', $externalId)) || !in_array($platform, ['facebook_page','youtube'], true)) { throw new \DomainException('กรุณากรอก Page ID เป็นตัวเลข หรือ YouTube Channel ID แบบ UC ตามด้วย 22 ตัวอักษร'); }
                if (Db::one('SELECT id FROM social_accounts WHERE platform=? AND external_account_id=?', [$platform,$externalId])) { throw new \DomainException('มีบัญชีนี้ในระบบแล้ว'); }
                Db::transaction(function () use ($platform,$externalId,$name): void {
                    Db::run('INSERT INTO social_accounts (platform,external_account_id,display_name,connection_status,created_at,updated_at) VALUES (?,?,?,?,?,?)', [$platform,$externalId,$name,'NOT_CONNECTED',now(),now()]); $id = Db::id();
                    if ($platform === 'facebook_page') { Db::run('INSERT INTO facebook_pages (account_id,page_id) VALUES (?,?)', [$id,$externalId]); }
                    else { Db::run('INSERT INTO youtube_channels (account_id,channel_id) VALUES (?,?)', [$id,$externalId]); }
                    Db::run('INSERT INTO account_monetization_history (account_id,status,effective_from,source) VALUES (?,?,?,?)', [$id,'NOT_MONETIZED',now(),'owner_master_prompt']);
                    audit('destination_registered_not_connected', 'social_accounts', $id);
                }); flash('success', 'เพิ่มรายการปลายทางแล้ว ยังไม่ได้เชื่อม OAuth/API'); redirect('accounts');
            case 'toggle_account':
                $id = int_id($_POST['account_id'] ?? null);
                Db::transaction(function () use ($id): void { $account = Db::one('SELECT * FROM social_accounts WHERE id=? FOR UPDATE', [$id]); if (!$account || $account['platform'] === 'facebook_personal') { throw new \DomainException('ไม่สามารถเปลี่ยนสถานะบัญชีนี้'); } Db::run('UPDATE social_accounts SET enabled=?,updated_at=? WHERE id=?', [$account['enabled'] ? 0 : 1,now(),$id]); audit('account_enabled_changed','social_accounts',$id,['enabled' => !$account['enabled']]); });
                flash('success', 'เปลี่ยนสถานะรายการแล้ว ประวัติยังคงอยู่'); redirect('accounts');
            case 'change_password':
                $record = Db::one('SELECT password_hash FROM admin_users WHERE id=?', [$user['id']]); $new = password_input('new_password',72);
                if (!password_verify(password_input('current_password'),$record['password_hash']) || strlen($new) < 12 || strlen($new) > 72 || $new !== password_input('confirm_password',72)) { throw new \DomainException('ตรวจรหัสผ่านปัจจุบัน และรหัสผ่านใหม่ต้องตรงกัน ยาว 12–72 ไบต์'); }
                Db::run('UPDATE admin_users SET password_hash=? WHERE id=?', [password_hash($new,PASSWORD_DEFAULT),$user['id']]); audit('password_changed','admin_users',(int)$user['id']); session_regenerate_id(true); unset($_SESSION['csrf']); flash('success','เปลี่ยนรหัสผ่านแล้ว'); redirect('settings');
            default: throw new \DomainException('คำสั่งไม่ถูกต้อง');
        }
    } catch (\DomainException $error) { flash('danger',$error->getMessage()); }
}

if ($page === 'install') { render('install', compact('requirements')); exit; }
if ($page === 'login') { if (Auth::user()) { redirect('dashboard'); } render('login'); exit; }
try {
    switch ($page) {
        case 'integrations': render('integrations',compact('phase2Ready'));break;
        case 'queue':
            $jobs=$phase2Ready?Db::all('SELECT j.*,t.platform,t.remote_post_id,t.remote_url,c.id content_id,c.title_internal,a.display_name,x.stage,x.acknowledged_bytes,x.last_error_code FROM publish_jobs j JOIN publish_targets t ON t.id=j.target_id JOIN content_items c ON c.id=t.content_id JOIN social_accounts a ON a.id=t.account_id LEFT JOIN publish_transfers x ON x.job_id=j.id ORDER BY j.id DESC LIMIT 100'):[];
            render('queue',compact('jobs','phase2Ready'));break;
        case 'workflow':
            if(!$phase2Ready)throw new \DomainException('กรุณาอัปเกรด Phase 2 ก่อน');
            $headerVariant=($_GET['variant']??'')==='header';$workflowFile=$headerVariant?'phase2-publishing-header-auth.json':'phase2-publishing.json';$template=is_file(STUDIO_ROOT.'/workflows/'.$workflowFile)?file_get_contents(STUDIO_ROOT.'/workflows/'.$workflowFile):false;if($template===false){http_response_code(404);render('message',['title'=>'ไม่พบไฟล์ Workflow บน Hosting','message'=>'กรุณาอัปโหลด '.$workflowFile.' ไปที่ studio-private/workflows/ แล้วลองใหม่']);break;}$template=str_replace('https://STUDIO-URL-REPLACE/studio',rtrim((string)config('app_url'),'/'),$template);
            header('Content-Type: application/json; charset=utf-8');header('Content-Disposition: attachment; filename="'.($headerVariant?'Hybrid-Studio-Phase2-Publishing-HeaderAuth.json':'Hybrid-Studio-Phase2-Publishing.json').'"');echo $template;break;
        case 'media':
            if (!in_array($_SERVER['REQUEST_METHOD'], ['GET','HEAD'],true)) { http_response_code(405); exit; }
            $media = Db::one('SELECT * FROM media_assets WHERE id=?', [int_id($_GET['id'] ?? null)]);
            if (!$media) { http_response_code(404); exit; } Media::stream($media, ($_GET['download'] ?? '') === '1');
        case 'dashboard':
            $counts = Db::one("SELECT COUNT(*) total, SUM(status='DRAFT') drafts, SUM(status='READY_FOR_REVIEW') reviews, SUM(status='APPROVED') approved FROM content_items");
            $waiting = Db::one("SELECT COUNT(*) n FROM manual_publish_tasks mt JOIN publish_targets t ON t.id=mt.target_id JOIN content_items c ON c.id=t.content_id WHERE c.status='APPROVED' AND mt.status<>'MANUAL_COMPLETED'")['n'];
            $recent = Db::all('SELECT * FROM content_items ORDER BY id DESC LIMIT 6'); render('dashboard',compact('counts','waiting','recent')); break;
        case 'library': case 'upload':
            $media = Db::all('SELECT m.*, c.id AS content_id FROM media_assets m LEFT JOIN content_items c ON c.media_asset_id=m.id ORDER BY m.id DESC LIMIT 100'); render('library', compact('media')); break;
        case 'contents':
            $filter = is_string($_GET['status'] ?? null) ? $_GET['status'] : '';
            $filter = in_array($filter,['DRAFT','READY_FOR_REVIEW','APPROVED'],true) ? $filter : '';
            $items = Db::all('SELECT c.*, m.original_name FROM content_items c LEFT JOIN media_assets m ON m.id=c.media_asset_id' . ($filter ? ' WHERE c.status=?' : '') . ' ORDER BY c.id DESC LIMIT 100', $filter ? [$filter] : []); render('contents',compact('items','filter')); break;
        case 'edit':
            $content = isset($_GET['id']) ? Content::get(int_id($_GET['id'])) : null;
            $assets = Db::all('SELECT m.* FROM media_assets m LEFT JOIN content_items c ON c.media_asset_id=m.id WHERE c.id IS NULL OR c.id=? ORDER BY m.id DESC', [$content['id'] ?? 0]);
            $accounts = Db::all('SELECT * FROM social_accounts WHERE enabled=1 ORDER BY platform,display_name'); render('edit',compact('content','assets','accounts')); break;
        case 'content': $content = Content::get(int_id($_GET['id'] ?? null)); render('content',compact('content')); break;
        case 'manual':
            $tasks = Db::all("SELECT mt.*, c.id content_id,c.title_internal,a.display_name FROM manual_publish_tasks mt JOIN publish_targets t ON t.id=mt.target_id JOIN social_accounts a ON a.id=t.account_id JOIN content_items c ON c.id=t.content_id WHERE c.status='APPROVED' ORDER BY mt.id DESC LIMIT 100"); render('manual',compact('tasks')); break;
        case 'accounts': $accounts = Db::all('SELECT * FROM social_accounts ORDER BY platform,id'); render('accounts',compact('accounts')); break;
        case 'settings': render('settings',compact('requirements')); break;
        case 'audit': $logs = Db::all('SELECT * FROM audit_logs ORDER BY id DESC LIMIT 100'); render('audit',compact('logs')); break;
        default: redirect('dashboard');
    }
} catch (\DomainException $error) { http_response_code(422); render('message',['title'=>'ยังดำเนินการไม่ได้','message'=>$error->getMessage()]); }
