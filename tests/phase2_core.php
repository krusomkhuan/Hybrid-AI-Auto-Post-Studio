<?php
declare(strict_types=1);
// Isolated database + simulated HTTP transport. These are NOT live Meta/YouTube API tests.
if(PHP_SAPI!=='cli')exit;
$root=dirname(__DIR__);$fixture=$root.'/.local/phase2-fixture-'.bin2hex(random_bytes(4));mkdir($fixture.'/var/media',0700,true);mkdir($fixture.'/database',0700,true);
define('STUDIO_ROOT',$fixture);
$database='studio_p2_test_'.bin2hex(random_bytes(4));
$adminDb=new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$adminDb->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$config=['environment'=>'test','app_url'=>'https://studio.example.test/studio','db'=>['host'=>'127.0.0.1','port'=>3306,'name'=>$database,'user'=>'root','password'=>''],'storage_path'=>$fixture.'/var/media'];
foreach(['helpers','Db','Security','Auth','Storage','Media','Content','Integrations','Publisher','IntegrationApi'] as $class)require $root.'/studio-private/app/'.$class.'.php';
use Studio\{Db,Security,IntegrationSettings,OAuth,ProviderHttp,ProviderError,Content,Publisher,IntegrationApi};
use function Studio\now;
session_start();
file_put_contents($fixture.'/var/application.key',random_bytes(32));
foreach(preg_split('/;\s*(?:\r?\n|$)/',file_get_contents($root.'/studio-private/database/001_phase1.sql')) as $s)if(trim($s)!=='')Db::connection()->exec($s);
copy($root.'/studio-private/database/002_phase2.sql',$fixture.'/database/002_phase2.sql');
Db::run('INSERT INTO admin_users(email,display_name,password_hash,created_at) VALUES(?,?,?,?)',['qa@example.test','Isolated Phase 2 QA',password_hash(bin2hex(random_bytes(16)),PASSWORD_DEFAULT),now()]);$_SESSION['admin_id']=1;
$passed=[];
function check(string $name,bool $ok):void{global $passed;if(!$ok)throw new RuntimeException('FAIL '.$name);$passed[]=$name;echo 'PASS '.$name.PHP_EOL;}
function rejects(string $name,callable $call):void{try{$call();}catch(DomainException|Studio\ProviderError $e){check($name,true);return;}check($name,false);}
function response(int $status,array $json=[],array $headers=[]):array{return ['status'=>$status,'json'=>$json,'headers'=>$headers,'body'=>json_encode($json)];}
ProviderHttp::setTestTransport(static function(){throw new RuntimeException('Unexpected network call in isolated suite');});
check('Phase 2 initially not migrated',!IntegrationSettings::migrated());IntegrationSettings::migrate();IntegrationSettings::migrate();
check('Additive migration is repeatable; preserves existing admin',IntegrationSettings::migrated()&&(int)Db::one('SELECT COUNT(*) n FROM admin_users')['n']===1);
IntegrationSettings::putSecret('meta_client_secret','simulated-secret-not-real');
check('Secrets stored authenticated/encrypted',IntegrationSettings::secret('meta_client_secret')==='simulated-secret-not-real'&&!str_contains(IntegrationSettings::get('meta_client_secret'),'simulated'));
$cipher=Security::encrypt('test');$cipher[10]=$cipher[10]==='A'?'B':'A';try{Security::decrypt($cipher);check('Ciphertext tamper rejected',false);}catch(RuntimeException $e){check('Ciphertext tamper rejected',true);}
IntegrationSettings::put('meta_client_id','123456789');IntegrationSettings::put('meta_graph_version','v27.0');IntegrationSettings::put('google_client_id','qa.apps.googleusercontent.com');IntegrationSettings::putSecret('google_client_secret','simulated-client-secret');
$auth=OAuth::start('google',1);parse_str(parse_url($auth,PHP_URL_QUERY),$authParams);
check('Google OAuth uses state, PKCE and offline consent',isset($authParams['state'],$authParams['code_challenge'])&&$authParams['code_challenge_method']==='S256'&&$authParams['access_type']==='offline');
rejects('Provider mismatch rejects OAuth state',fn()=>OAuth::consumeState('meta',$authParams['state'],1));OAuth::consumeState('google',$authParams['state'],1);
rejects('OAuth state is single use',fn()=>OAuth::consumeState('google',$authParams['state'],1));
parse_str(parse_url(OAuth::start('meta',1),PHP_URL_QUERY),$stateParams);
Db::run('UPDATE oauth_states SET expires_at=? WHERE state_hash=?',['2000-01-01 00:00:00',hash('sha256',$stateParams['state'])]);
rejects('Expired OAuth state cannot be consumed',fn()=>OAuth::consumeState('meta',$stateParams['state'],1));
rejects('Upload URL rejects arbitrary hosts',fn()=>ProviderHttp::validateUrl('https://attacker.example/upload'));
rejects('Upload URL rejects private HTTP endpoints',fn()=>ProviderHttp::validateUrl('http://127.0.0.1/admin'));
rejects('Facebook expired token classified as auth',fn()=>ProviderHttp::json(response(400,['error'=>['code'=>190]])));

// OAuth callback transport simulation: real DB import without contacting Google.
ProviderHttp::setTestTransport(static function($method,$url){
    if(str_contains($url,'oauth2.googleapis.com/token'))return response(200,['access_token'=>'simulated-yt-token','refresh_token'=>'simulated-refresh','expires_in'=>3600,'scope'=>'https://www.googleapis.com/auth/youtube.upload https://www.googleapis.com/auth/youtube.readonly']);
    if(str_contains($url,'/channels?'))return response(200,['items'=>[['id'=>'UCabcdefghijklmnopqrstuv','snippet'=>['title'=>'Simulated QA Channel']]]]);
    throw new RuntimeException('Unexpected simulated OAuth request');
});
parse_str(parse_url(OAuth::start('google',1),PHP_URL_QUERY),$p);
check('Simulated OAuth imports exact authorized channel',OAuth::complete('google',$p['state'],'simulated-code',1)===1);
$yt=(int)Db::one("SELECT id FROM social_accounts WHERE platform='youtube'")['id'];
check('Google tokens encrypted at rest',str_starts_with(OAuth::accessToken($yt),'simulated-')&&!str_contains(Db::one("SELECT encrypted_refresh_token FROM social_connections WHERE provider='youtube'")['encrypted_refresh_token'],'simulated'));
Db::run('INSERT INTO social_connections(admin_id,provider,provider_subject_id,encrypted_access_token,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?)',[1,'meta','simulated-meta-owner',Security::encrypt('simulated-meta-user-token'),'CONNECTED',now(),now()]);$connection=Db::id();
Db::run('INSERT INTO social_accounts(connection_id,platform,external_account_id,display_name,connection_status,created_at,updated_at) VALUES(?,?,?,?,?,?,?)',[$connection,'facebook_page','123456789012345','Simulated QA Page','CONNECTED',now(),now()]);$fb=Db::id();
Db::run('INSERT INTO facebook_pages(account_id,page_id,encrypted_page_token,permissions_status) VALUES(?,?,?,?)',[$fb,'123456789012345',Security::encrypt('simulated-page-token'),'GRANTED']);
Db::run('INSERT INTO social_accounts(platform,external_account_id,display_name,connection_status,created_at,updated_at) VALUES(?,?,?,?,?,?)',['facebook_personal','qa-personal','QA Manual','MANUAL_ONLY',now(),now()]);$personal=Db::id();Db::run('INSERT INTO personal_profiles(account_id) VALUES(?)',[$personal]);
$bytes=file_get_contents($root.'/.local/valid.mp4');$hash=hash('sha256',$bytes);file_put_contents($fixture.'/var/media/'.$hash.'.mp4',$bytes);
Db::run('INSERT INTO media_assets(storage_key,sha256,original_name,mime,size_bytes,width,height,duration_ms,video_codec,audio_codec,validation_status,validation_json,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)',[$hash.'.mp4',$hash,'isolated-qa.mp4','video/mp4',strlen($bytes),180,320,31000,'avc1','AAC','VALIDATED','{}',now()]);$asset=Db::id();
$form=['title_internal'=>'Isolated Phase 2 QA','media_asset_id'=>$asset,'facebook_page_id'=>$fb,'youtube_channel_id'=>$yt,'manual_method'=>'SHARE_PAGE','privacy'=>'private','audience'=>'not_made_for_kids','synthetic_media'=>false,'scheduled_local'=>'','youtube_title'=>'QA Shorts','captions'=>[]];
foreach(['facebook_page','youtube','facebook_personal'] as $platform)$form['captions'][$platform]=['caption'=>'QA '.$platform,'hashtags'=>'#isolated'];
$content=Content::save($form,1);Content::submit($content,1);Content::approve($content,1,1);
rejects('Dispatch disabled by default',fn()=>Publisher::arm($content));
check('Old approvals do not automatically dispatch',(int)Db::one("SELECT COUNT(*) n FROM publish_jobs WHERE status='PHASE2_REQUIRED'")['n']===2);
IntegrationSettings::put('publishing_enabled',true);check('Explicit queue arms two authorized targets',Publisher::arm($content)===2);
$ytJob=(int)Db::one("SELECT j.id FROM publish_jobs j JOIN publish_targets t ON t.id=j.target_id WHERE t.platform='youtube'")['id'];
$fbJob=(int)Db::one("SELECT j.id FROM publish_jobs j JOIN publish_targets t ON t.id=j.target_id WHERE t.platform='facebook_page'")['id'];
Db::run("UPDATE publish_jobs SET status='PHASE2_REQUIRED' WHERE id=?",[$fbJob]);

$step=0;$expectedOffset=0;
ProviderHttp::setTestTransport(static function($method,$url,$headers,$body)use(&$step,&$expectedOffset,$bytes){
    if(str_contains($url,'/channels?'))return response(200,['items'=>[['id'=>'UCabcdefghijklmnopqrstuv']]]);
    if(str_contains($url,'uploadType=resumable'))return response(200,[],['location'=>'https://www.googleapis.com/upload/youtube/v3/videos?upload_id=simulated']);
    if(str_contains($url,'upload_id=simulated')){
        if(in_array('Content-Length: 0',$headers,true))return response(308,[]);
        if($step++===0)throw new ProviderError('network','simulated_timeout');
        check('Resumed bytes match the approved file',$body===$bytes);return response(200,['id'=>'QaVideo1234']);
    }
    if(str_contains($url,'/videos?part=status'))return response(200,['items'=>[['status'=>['uploadStatus'=>'processed','privacyStatus'=>'private'],'processingDetails'=>['processingStatus'=>'succeeded']]]]);
    throw new RuntimeException('Unexpected simulated publisher request');
});
check('Resumable upload session saved before bytes',Publisher::tick()['step']==='youtube_session_created');
check('Initial upload probes authoritative byte offset',Publisher::tick()['step']==='youtube_chunk_acknowledged');
check('Upload timeout enters retry without a new session',Publisher::tick()['status']==='RETRY_WAIT');
check('Timeout changes stage to probe',Db::one('SELECT stage FROM publish_transfers WHERE job_id=?',[$ytJob])['stage']==='YT_PROBE');
Publisher::retry($ytJob);check('Retry probes existing session',Publisher::tick()['step']==='youtube_chunk_acknowledged');
check('Final upload stores the returned video ID before polling',Publisher::tick()['step']==='youtube_bytes_complete');
check('Upload completion alone is not PUBLISHED',Db::one('SELECT status FROM publish_jobs WHERE id=?',[$ytJob])['status']==='READY');
Db::run('UPDATE publish_jobs SET next_retry_at=NULL WHERE id=?',[$ytJob]);
check('Processing and privacy confirmation complete one target',Publisher::tick()['status']==='PUBLISHED');
check('Partial success does not mark Facebook or Manual complete',Db::one('SELECT status FROM publish_jobs WHERE id=?',[$fbJob])['status']==='PHASE2_REQUIRED'&&Db::one('SELECT status FROM manual_publish_tasks')['status']==='WAITING_PAGE_PUBLICATION');

// Simulated FB lifecycle ensures finish is never sent twice while publication lags.
$finishes=0;$polls=0;
ProviderHttp::setTestTransport(static function($method,$url,$headers,$body)use(&$finishes,&$polls){
    if(str_contains($url,'123456789012345?fields=id'))return response(200,['id'=>'123456789012345']);
    if(str_contains($url,'/video_reels')){parse_str($body??'',$p);if(($p['upload_phase']??'')==='start')return response(200,['video_id'=>'99887766','upload_url'=>'https://rupload.facebook.com/video-upload/v27.0/99887766']);if(($p['upload_phase']??'')==='finish'){$finishes++;return response(200,['success'=>true]);}}
    if(str_contains($url,'rupload.facebook.com'))return response(200,['success'=>true]);
    if(str_contains($url,'99887766?fields=status')){$polls++;return response(200,['status'=>['uploading_phase'=>['status'=>'complete'],'processing_phase'=>['status'=>'complete'],'publishing_phase'=>['status'=>$polls>2?'complete':'not_started','publish_status'=>$polls>2?'published':'unpublished']],'permalink_url'=>'https://www.facebook.com/reel/99887766']);}
    throw new RuntimeException('Unexpected FB simulation');
});
Db::run("UPDATE publish_jobs SET status='READY' WHERE id=?",[$fbJob]);
for($i=0;$i<7;$i++){Db::run('UPDATE publish_jobs SET next_retry_at=NULL WHERE id=?',[$fbJob]);$r=Publisher::tick();if($r['status']==='PUBLISHED')break;}
check('FB finish submitted only once while publication pending',$finishes===1);
check('FB publication unlocks share task',Db::one('SELECT status FROM manual_publish_tasks')['status']==='WAITING_MANUAL_POST');
$summary=Studio\publish_summary(Content::get($content)['targets']);check('Two API successes still report partial while Manual waits',$summary['status']==='PARTIAL_SUCCESS'&&$summary['automatic']===2&&!$summary['manual']);
Content::completeManual($content,1,'https://www.facebook.com/qa/posts/manual',(new DateTimeImmutable('-1 minute',new DateTimeZone('Asia/Bangkok')))->format('Y-m-d\TH:i'),1);
check('Complete success requires both APIs and Manual confirmation',Studio\publish_summary(Content::get($content)['targets'])['status']==='COMPLETE_SUCCESS');

$approvedCaption=Db::one("SELECT caption FROM content_captions WHERE content_id=? AND platform='youtube'",[$content])['caption'];
Db::run("UPDATE content_captions SET caption='tampered after approval' WHERE content_id=? AND platform='youtube'",[$content]);
rejects('Publishing rejects caption tampered after approval',fn()=>Publisher::validateApproval($ytJob));
Db::run("UPDATE content_captions SET caption=? WHERE content_id=? AND platform='youtube'",[$approvedCaption,$content]);
ProviderHttp::setTestTransport(static function(){throw new RuntimeException('A future job must not contact a provider');});
Db::run("UPDATE publish_jobs SET status='READY',due_at=? WHERE id=?",[gmdate('Y-m-d H:i:s',time()+3600),$fbJob]);
check('Scheduler does not dispatch future jobs',Publisher::tick()['status']==='IDLE');
Db::run("UPDATE publish_jobs SET status='READY',due_at=?,next_retry_at=NULL WHERE id=?",[now(),$fbJob]);
Db::run("UPDATE publish_transfers SET stage='NEW',remote_id=NULL,encrypted_session=NULL WHERE job_id=?",[$fbJob]);
Db::run("UPDATE publish_targets SET remote_post_id=NULL,published_at=NULL,target_status='QUEUED' WHERE id=(SELECT target_id FROM publish_jobs WHERE id=?)",[$fbJob]);
$startCalls=0;
ProviderHttp::setTestTransport(static function($method,$url)use(&$startCalls){if(str_contains($url,'?fields=id'))return response(200,['id'=>'123456789012345']);$startCalls++;throw new ProviderError('network','simulated_lost_start_response');});
check('Lost start response stops as UNKNOWN_REMOTE_STATE',Publisher::tick()['status']==='UNKNOWN_REMOTE_STATE');
check('Ambiguous start is not automatically uploaded again',Publisher::tick()['status']==='IDLE'&&$startCalls===1);
rejects('Unknown start with no Remote ID cannot be reconciled blindly',fn()=>Publisher::reconcile($fbJob));
try{ProviderHttp::json(response(403,['error'=>['code'=>403,'errors'=>[['reason'=>'quotaExceeded']]]]));check('Quota failure defers rather than requests OAuth',false);}catch(ProviderError $e){check('Quota failure defers rather than requests OAuth',$e->category==='temporary'&&$e->retryAfter>0);}

IntegrationSettings::putSecret('n8n_hmac_secret','simulated-hmac-key-not-production');$raw=json_encode(['event_id'=>'isolated_event_123','operation'=>'tick']);$timestamp=(string)time();$nonce=bin2hex(random_bytes(16));$path='/studio/integration.php';
$signature=hash_hmac('sha256',"POST\n$path\n$timestamp\n$nonce\n".hash('sha256',$raw),'simulated-hmac-key-not-production');
IntegrationApi::authenticate($raw,$path,compact('timestamp','nonce','signature'));check('Valid HMAC signature accepted',true);
$signedHeaders=compact('timestamp','nonce','signature');
rejects('Nonce replay rejected',fn()=>IntegrationApi::authenticate($raw,$path,$signedHeaders));
$badSignature=str_repeat('a',64);rejects('Invalid HMAC cannot dispatch',fn()=>IntegrationApi::authenticate($raw,$path,['timestamp'=>$timestamp,'nonce'=>bin2hex(random_bytes(16)),'signature'=>$badSignature]));
rejects('Expired signed request cannot dispatch',fn()=>IntegrationApi::authenticate($raw,$path,['timestamp'=>(string)(time()-600),'nonce'=>$nonce,'signature'=>$signature]));
// Separate n8n Header Auth key (n8n 1.106.3 has no Crypto V2). HMAC must keep working alongside it.
$plainPath='/studio/integration.php';
rejects('Header key rejected before one is created',fn()=>IntegrationApi::authenticate($raw,$plainPath,['key'=>str_repeat('b',64)]));
$headerKey=bin2hex(random_bytes(32));IntegrationSettings::put('n8n_header_key_hash',hash('sha256',$headerKey));
check('Header key stored as hash only',IntegrationSettings::get('n8n_header_key_hash')===hash('sha256',$headerKey)&&!str_contains(json_encode(Db::all('SELECT value_json FROM system_settings')),$headerKey));
check('Valid header key accepted',IntegrationApi::authenticate($raw,$plainPath,['key'=>$headerKey])==='header_key');
check('Header key does not depend on timestamp/nonce',IntegrationApi::authenticate($raw,$plainPath,['key'=>$headerKey,'timestamp'=>'0','nonce'=>'x','signature'=>'y'])==='header_key');
rejects('Wrong header key rejected',fn()=>IntegrationApi::authenticate($raw,$plainPath,['key'=>bin2hex(random_bytes(32))]));
rejects('Malformed header key rejected',fn()=>IntegrationApi::authenticate($raw,$plainPath,['key'=>strtoupper($headerKey)]));
rejects('HMAC secret is not accepted as header key',fn()=>IntegrationApi::authenticate($raw,$plainPath,['key'=>'simulated-hmac-key-not-production']));
$t2=(string)time();$n2=bin2hex(random_bytes(16));$s2=hash_hmac('sha256',"POST\n$plainPath\n$t2\n$n2\n".hash('sha256',$raw),'simulated-hmac-key-not-production');
rejects('Wrong header key does not fall back to valid HMAC',fn()=>IntegrationApi::authenticate($raw,$plainPath,['key'=>bin2hex(random_bytes(32)),'timestamp'=>$t2,'nonce'=>$n2,'signature'=>$s2]));
$t3=(string)time();$n3=bin2hex(random_bytes(16));$s3=hash_hmac('sha256',"POST\n$plainPath\n$t3\n$n3\n".hash('sha256',$raw),'simulated-hmac-key-not-production');
check('HMAC still accepted while header key is configured',IntegrationApi::authenticate($raw,$plainPath,['key'=>'','timestamp'=>$t3,'nonce'=>$n3,'signature'=>$s3])==='hmac');
IntegrationSettings::put('n8n_header_key_hash','');rejects('Revoked header key rejected',fn()=>IntegrationApi::authenticate($raw,$plainPath,['key'=>$headerKey]));
IntegrationSettings::put('publishing_enabled',false);$r1=IntegrationApi::handle(json_decode($raw,true),$raw);$r2=IntegrationApi::handle(json_decode($raw,true),$raw);
check('Duplicate event returns saved result without repeating work',$r1===$r2&&$r1['status']==='DISABLED');
rejects('Reusing event ID with changed payload rejected',fn()=>IntegrationApi::handle(['event_id'=>'isolated_event_123','operation'=>'tick'],$raw.' '));
OAuth::disconnect($yt);rejects('Disconnected channel cannot provide token',fn()=>OAuth::accessToken($yt));
check('Disconnect preserves published history',Db::one('SELECT status FROM publish_jobs WHERE id=?',[$ytJob])['status']==='PUBLISHED');
$report=['kind'=>'Isolated DB tests with SIMULATED provider transport; not live API verification','checks'=>$passed,'count'=>count($passed),'external_calls'=>0];file_put_contents($root.'/docs/phase2-test-results.json',json_encode($report,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
echo 'RESULT '.count($passed).' checks passed; no live social API calls.'.PHP_EOL;
