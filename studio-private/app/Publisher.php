<?php
declare(strict_types=1);
namespace Studio;

final class Publisher {
    public static function arm(int $contentId): int {
        if(!IntegrationSettings::get('publishing_enabled',false))throw new \DomainException('เปิดการเผยแพร่อัตโนมัติในหน้าการเชื่อมต่อก่อนจัดคิว');
        $count=Db::transaction(function()use($contentId){
            $c=Content::get($contentId,true);
            if($c['status']!=='APPROVED')throw new \DomainException('ต้องอนุมัติคอนเทนต์ Revision ปัจจุบันก่อน');
            $n=0;
            foreach(Db::all('SELECT j.*,t.account_id,t.platform,t.remote_post_id FROM publish_jobs j JOIN publish_targets t ON t.id=j.target_id WHERE t.content_id=? FOR UPDATE',[$contentId]) as $j){
                if(!in_array($j['status'],['PHASE2_REQUIRED','AUTH_REQUIRED','FAILED'],true))continue;
                $transfer=Db::one('SELECT * FROM publish_transfers WHERE job_id=?',[$j['id']]);
                if($j['remote_post_id']||($transfer&&$transfer['stage']!=='NEW'))continue;
                self::validateApproval((int)$j['id']);
                $a=Db::one('SELECT * FROM social_accounts WHERE id=?',[$j['account_id']]);
                if(!$a['enabled']||$a['connection_status']!=='CONNECTED'){
                    Db::run("UPDATE publish_jobs SET status='AUTH_REQUIRED' WHERE id=?",[$j['id']]);
                    Db::run("UPDATE publish_targets SET target_status='AUTH_REQUIRED' WHERE id=?",[$j['target_id']]);continue;
                }
                if($j['platform']==='facebook_page'&&parse_url(config('app_url'),PHP_URL_SCHEME)!=='https')throw new \DomainException('Facebook ต้องใช้ Media URL แบบ HTTPS ที่ Meta เข้าถึงได้ กรุณาทดสอบ Facebook Publisher บน Hosting');
                Db::run("UPDATE publish_jobs SET status='READY',next_retry_at=NULL,lease_token_hash=NULL,lease_expires_at=NULL WHERE id=?",[$j['id']]);
                Db::run("UPDATE publish_targets SET target_status='QUEUED' WHERE id=?",[$j['target_id']]);
                Db::run('INSERT IGNORE INTO publish_transfers(job_id,stage,updated_at) VALUES(?,?,?)',[$j['id'],'NEW',now()]);$n++;
            }
            audit('content_queued','content_items',$contentId,['jobs'=>$n]);return $n;
        });
        if(!$count)throw new \DomainException('ไม่มีงานที่จัดคิวได้ ตรวจการเชื่อมต่อบัญชี และสถานะงานเดิม');return $count;
    }
    public static function validateApproval(int $jobId): array {
        $j=Db::one('SELECT j.*,t.content_id,t.account_id,t.platform,t.approved_revision,t.privacy,t.audience,t.synthetic_media,t.remote_post_id,a.external_account_id,a.enabled,a.connection_status,c.media_asset_id,c.revision,c.status AS content_status,m.storage_key,m.storage_disk,m.sha256,m.size_bytes,ap.revision AS approval_revision,ap.snapshot,ap.snapshot_sha256 FROM publish_jobs j JOIN publish_targets t ON t.id=j.target_id JOIN social_accounts a ON a.id=t.account_id JOIN content_items c ON c.id=t.content_id JOIN media_assets m ON m.id=c.media_asset_id JOIN content_approvals ap ON ap.id=j.approval_id WHERE j.id=?',[$jobId]);
        if(!$j)throw new \DomainException('ไม่พบงานหรือไฟล์ที่อนุมัติ');
        if(!hash_equals($j['snapshot_sha256'],hash('sha256',$j['snapshot'])))throw new \DomainException('Snapshot การอนุมัติเปลี่ยนแปลง');
        $s=json_decode($j['snapshot'],true,512,JSON_THROW_ON_ERROR);$platform=$j['platform'];$t=$s['targets'][$platform]??null;
        if($j['content_status']!=='APPROVED'||(int)$j['revision']!==(int)$j['approval_revision']||(int)$j['revision']!==(int)$j['approved_revision']||!$t||(int)$t['account_id']!==(int)$j['account_id']||(int)$s['media_asset_id']!==(int)$j['media_asset_id']||!hash_equals($s['sha256'],$j['sha256']))throw new \DomainException('ข้อมูลไม่ตรงกับ Revision ที่อนุมัติ');
        foreach(['privacy','audience','synthetic_media'] as $key)if((string)$t[$key] !== (string)$j[$key])throw new \DomainException('การตั้งค่าปลายทางเปลี่ยนหลังอนุมัติ');
        $copy=Db::one('SELECT * FROM content_captions WHERE content_id=? AND platform=?',[$j['content_id'],$platform]);
        foreach(['caption','hashtags','youtube_title','revision'] as $key)if((string)($copy[$key]??'')!==(string)($s['captions'][$platform][$key]??''))throw new \DomainException('ข้อความไม่ตรงกับ Snapshot ที่อนุมัติ');
        $j['caption']=$copy['caption'];$j['hashtags']=$copy['hashtags'];$j['youtube_title']=$copy['youtube_title'];return $j;
    }
    public static function retry(int $jobId): void {
        Db::transaction(function()use($jobId){
            $j=Db::one('SELECT j.*,x.stage FROM publish_jobs j LEFT JOIN publish_transfers x ON x.job_id=j.id WHERE j.id=? FOR UPDATE',[$jobId]);
            if(!$j||!in_array($j['status'],['RETRY_WAIT','AUTH_REQUIRED','FAILED'],true)||in_array($j['stage'],['START_REQUESTED','FB_TRANSFER_REQUESTED','FB_FINISH_REQUESTED'],true))throw new \DomainException('งานนี้ยัง Retry ไม่ได้ ต้องตรวจ Remote State ก่อน');
            self::validateApproval($jobId);
            Db::run("UPDATE publish_jobs SET status='READY',next_retry_at=NULL,lease_expires_at=NULL,lease_token_hash=NULL,attempt_count=0 WHERE id=?",[$jobId]);audit('publish_retry_requested','publish_jobs',$jobId);
            Db::run('UPDATE publish_transfers SET updated_at=? WHERE job_id=?',[now(),$jobId]);
        });
    }
    public static function cancel(int $jobId): void {
        Db::transaction(function()use($jobId){
            $j=Db::one('SELECT j.*,x.stage FROM publish_jobs j LEFT JOIN publish_transfers x ON x.job_id=j.id WHERE j.id=? FOR UPDATE',[$jobId]);
            if(!$j||!in_array($j['status'],['PHASE2_REQUIRED','READY','AUTH_REQUIRED','RETRY_WAIT'],true)||($j['stage']&&$j['stage']!=='NEW'))throw new \DomainException('ยกเลิกได้เฉพาะงานที่ยังไม่เริ่มส่งไปแพลตฟอร์ม');
            Db::run("UPDATE publish_jobs SET status='CANCELLED' WHERE id=?",[$jobId]);Db::run("UPDATE publish_targets SET target_status='CANCELLED' WHERE id=?",[$j['target_id']]);audit('publish_cancelled','publish_jobs',$jobId);
        });
    }
    public static function reconcile(int $jobId): void {
        Db::transaction(function()use($jobId){
            $r=Db::one('SELECT j.status,x.* FROM publish_jobs j JOIN publish_transfers x ON x.job_id=j.id WHERE j.id=? FOR UPDATE',[$jobId]);
            if(!$r||$r['status']!=='UNKNOWN_REMOTE_STATE'||!$r['remote_id'])throw new \DomainException('ไม่มี Remote ID ที่ใช้ตรวจสอบได้ กรุณาตรวจบนแพลตฟอร์มก่อน ไม่สามารถเริ่ม Upload ใหม่แทนได้');
            $j=self::validateApproval($jobId);
            $stage=$j['platform']==='youtube'?'YT_PROCESSING':($r['stage']==='FB_FINISH_REQUESTED'?'FB_PUBLISH_POLL':'FB_STATUS');
            self::stage($jobId,$stage);Db::run("UPDATE publish_jobs SET status='READY',next_retry_at=NULL,lease_token_hash=NULL,lease_expires_at=NULL WHERE id=?",[$jobId]);
            audit('remote_reconciliation_requested','publish_jobs',$jobId,['remote_id'=>$r['remote_id']]);
        });
    }
    public static function tick(): array {
        if(!IntegrationSettings::get('publishing_enabled',false))return ['status'=>'DISABLED','message'=>'Automation is paused'];
        $lock='studio.publisher.'.substr(hash('sha256',(string)config('db')['name']),0,20);
        if((int)Db::one('SELECT GET_LOCK(?,0) l',[$lock])['l']!==1)return ['status'=>'BUSY'];
        try{
            // Only resumable, known states can be recovered after a lost PHP request.
            foreach(Db::all("SELECT j.id,x.stage FROM publish_jobs j JOIN publish_transfers x ON x.job_id=j.id WHERE j.status='RUNNING' AND j.lease_expires_at<?",[now()]) as $expired){
                $safe=in_array($expired['stage'],['NEW','YT_PROBE','YT_SEND','YT_PROCESSING','FB_FETCH','FB_STATUS','FB_FINISH','FB_PUBLISH_POLL'],true);
                Db::run('UPDATE publish_jobs SET status=?,lease_token_hash=NULL,lease_expires_at=NULL WHERE id=?',[$safe?'READY':'UNKNOWN_REMOTE_STATE',$expired['id']]);
            }
            $job=Db::transaction(function(){
                $j=Db::one("SELECT * FROM publish_jobs WHERE status IN ('READY','RETRY_WAIT') AND due_at<=? AND (next_retry_at IS NULL OR next_retry_at<=?) ORDER BY due_at,id LIMIT 1 FOR UPDATE",[now(),now()]);
                if(!$j)return null;
                Db::run("UPDATE publish_jobs SET status='RUNNING',lease_token_hash=?,lease_expires_at=? WHERE id=?",[hash('sha256',random_bytes(32)),gmdate('Y-m-d H:i:s',time()+180),$j['id']]);return $j;
            });
            if(!$job)return ['status'=>'IDLE'];$id=(int)$job['id'];
            $attempt=(int)Db::one('SELECT COALESCE(MAX(attempt_no),0)+1 n FROM publish_attempts WHERE job_id=?',[$id])['n'];
            Db::run('INSERT INTO publish_attempts(job_id,attempt_no,started_at) VALUES(?,?,?)',[$id,$attempt,now()]);
            try{
                $j=self::validateApproval($id);
                if(!$j['enabled']||$j['connection_status']!=='CONNECTED')throw new ProviderError('auth','account_not_connected');
                if($j['storage_disk']!=='local'||!is_file(storage()->path($j['storage_key']))||!hash_equals($j['sha256'],hash_file('sha256',storage()->path($j['storage_key']))))throw new \DomainException('ไฟล์ที่อนุมัติหายหรือเปลี่ยนแปลง');
                $token=OAuth::accessToken((int)$j['account_id']);
                $transfer=Db::one('SELECT * FROM publish_transfers WHERE job_id=?',[$id]);
                if(!$transfer)throw new \DomainException('ไม่พบสถานะ Upload Session');
                if($transfer['stage']!=='NEW'&&strtotime($transfer['updated_at'])<time()-86400)throw new ProviderError('permanent','processing_timeout_check_provider');
                $result=$j['platform']==='youtube'?self::youtube($j,$transfer,$token):self::facebook($j,$transfer,$token);
                Db::run('UPDATE publish_jobs SET status=?,next_retry_at=?,lease_token_hash=NULL,lease_expires_at=NULL,attempt_count=0 WHERE id=?',[$result['done']?'PUBLISHED':'READY',$result['delay']?gmdate('Y-m-d H:i:s',time()+$result['delay']):null,$id]);
                Db::run('UPDATE publish_attempts SET finished_at=?,sanitized_summary=? WHERE job_id=? AND attempt_no=?',[now(),$result['step'],$id,$attempt]);
                return ['status'=>$result['done']?'PUBLISHED':'PROGRESS','job_id'=>$id,'step'=>$result['step']];
            }catch(ProviderError $error){return self::fail($id,$attempt,$error->category,$error->safeCode,$error->retryAfter);}
            catch(\DomainException $error){return self::fail($id,$attempt,'validation','approval_or_media_invalid');}
            catch(\Throwable $error){self::fail($id,$attempt,'internal','internal_error');throw $error;}
        }finally{Db::run('SELECT RELEASE_LOCK(?)',[$lock]);}
    }
    private static function fail(int $id,int $attempt,string $category,string $code,int $retryAfter=0): array {
        $x=Db::one('SELECT * FROM publish_transfers WHERE job_id=?',[$id]);$j=Db::one('SELECT * FROM publish_jobs WHERE id=?',[$id]);
        $unsafeStage=in_array($x['stage']??'',['START_REQUESTED','FB_TRANSFER_REQUESTED','FB_FINISH_REQUESTED'],true);
        $definiteRejection=(bool)preg_match('/\Ahttp_4[0-9]{2}_/',$code);
        $ambiguous=$unsafeStage&&!$definiteRejection;
        $tries=(int)$j['attempt_count']+1;
        $status=$ambiguous?'UNKNOWN_REMOTE_STATE':(in_array($category,['auth','permission'],true)?'AUTH_REQUIRED':(in_array($category,['network','temporary'],true)&&$tries<=5?'RETRY_WAIT':'FAILED'));
        $next=$status==='RETRY_WAIT'?gmdate('Y-m-d H:i:s',time()+max($retryAfter,min(3600,30*(2**min($tries,6)))+random_int(0,20))):null;
        // Clear definite failed starts only; ambiguous starts must never become a new upload automatically.
        if(!$ambiguous&&($x['stage']??'')==='START_REQUESTED')self::stage($id,'NEW');
        if(!$ambiguous&&($x['stage']??'')==='FB_FINISH_REQUESTED')self::stage($id,'FB_FINISH');
        if(!$ambiguous&&($x['stage']??'')==='FB_TRANSFER_REQUESTED')self::stage($id,'FB_FETCH');
        Db::run('UPDATE publish_jobs SET status=?,attempt_count=?,next_retry_at=?,lease_token_hash=NULL,lease_expires_at=NULL WHERE id=?',[$status,$tries,$next,$id]);
        Db::run('UPDATE publish_targets SET target_status=? WHERE id=?',[$status,$j['target_id']]);
        Db::run('UPDATE publish_transfers SET last_error_code=?,last_error_message=?,updated_at=? WHERE job_id=?',[$code,$category,now(),$id]);
        Db::run('UPDATE publish_attempts SET finished_at=?,error_category=?,sanitized_summary=? WHERE job_id=? AND attempt_no=?',[now(),$category,$code,$id,$attempt]);
        return ['status'=>$status,'job_id'=>$id,'error_code'=>$code];
    }
    private static function stage(int $id,string $stage): void {Db::run('UPDATE publish_transfers SET stage=?,updated_at=? WHERE job_id=?',[$stage,now(),$id]);}
    private static function progress(string $step,int $delay=0): array {return ['step'=>$step,'delay'=>$delay,'done'=>false];}
    private static function remote(array $j,string $id): void {
        if(!preg_match('/\A[A-Za-z0-9_-]{1,190}\z/D',$id))throw new ProviderError('permanent','invalid_remote_id');
        Db::run('UPDATE publish_transfers SET remote_id=?,updated_at=? WHERE job_id=?',[$id,now(),$j['id']]);
        Db::run("UPDATE publish_targets SET remote_post_id=?,target_status='PROCESSING' WHERE id=?",[$id,$j['target_id']]);
    }
    private static function published(array $j,string $url): array {
        Security::checkUrl($url,$j['platform']==='youtube'?['www.youtube.com','youtube.com','youtu.be']:['www.facebook.com','facebook.com','m.facebook.com','fb.watch']);
        Db::transaction(function()use($j,$url){
            Db::run("UPDATE publish_targets SET target_status='PUBLISHED',remote_url=?,published_at=? WHERE id=?",[$url,now(),$j['target_id']]);
            if($j['platform']==='facebook_page'){
                Db::run("UPDATE manual_publish_tasks SET status='WAITING_MANUAL_POST' WHERE source_page_target_id=? AND method='SHARE_PAGE' AND status='WAITING_PAGE_PUBLICATION'",[$j['target_id']]);
                Db::run("UPDATE publish_targets t JOIN manual_publish_tasks m ON m.target_id=t.id SET t.target_status='WAITING_MANUAL_POST' WHERE m.source_page_target_id=? AND m.status='WAITING_MANUAL_POST'",[$j['target_id']]);
            }
            audit('platform_published','publish_targets',(int)$j['target_id'],['platform'=>$j['platform'],'remote_url'=>$url]);
        });
        return ['step'=>'confirmed_published','delay'=>0,'done'=>true];
    }
    private static function youtube(array $j,array $x,string $token): array {
        $auth=['Authorization: Bearer '.$token];$id=(int)$j['id'];$stage=$x['stage'];
        if($stage==='NEW'){
            $channels=ProviderHttp::json(ProviderHttp::request('GET','https://www.googleapis.com/youtube/v3/channels?part=id&mine=true',$auth));
            if(!in_array($j['external_account_id'],array_column($channels['items']??[],'id'),true))throw new ProviderError('auth','youtube_channel_mismatch');
            self::stage($id,'START_REQUESTED');
            $data=['snippet'=>['title'=>$j['youtube_title'],'description'=>trim($j['caption']."\n\n".$j['hashtags'])], 'status'=>['privacyStatus'=>$j['privacy'],'selfDeclaredMadeForKids'=>$j['audience']==='made_for_kids','containsSyntheticMedia'=>(bool)$j['synthetic_media']]];
            $r=ProviderHttp::request('POST','https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status',array_merge($auth,['Content-Type: application/json','X-Upload-Content-Type: video/mp4','X-Upload-Content-Length: '.$j['size_bytes']]),json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
            if(!in_array($r['status'],[200,201],true))ProviderHttp::json($r);
            $session=$r['headers']['location']??'';ProviderHttp::validateUrl($session);
            if(parse_url($session,PHP_URL_HOST)!=='www.googleapis.com')throw new ProviderError('permanent','unexpected_upload_host');
            Db::run("UPDATE publish_transfers SET encrypted_session=?,stage='YT_PROBE',updated_at=? WHERE job_id=?",[Security::encrypt($session),now(),$id]);
            Db::run("UPDATE publish_targets SET target_status='UPLOADING' WHERE id=?",[$j['target_id']]);return self::progress('youtube_session_created');
        }
        if(in_array($stage,['YT_PROBE','YT_SEND'],true)){
            $session=Security::decrypt($x['encrypted_session']);$size=(int)$j['size_bytes'];$offset=(int)$x['acknowledged_bytes'];
            if($stage==='YT_PROBE'){
                $r=ProviderHttp::request('PUT',$session,array_merge($auth,['Content-Length: 0','Content-Range: bytes */'.$size]),'');
            }else{
                if($offset>=$size){self::stage($id,'YT_PROBE');return self::progress('youtube_probe_required');}
                $handle=fopen(storage()->path($j['storage_key']),'rb');fseek($handle,$offset);$chunk=fread($handle,min(2097152,$size-$offset));fclose($handle);
                if($chunk===false||$chunk==='')throw new \DomainException('อ่านช่วงไฟล์วิดีโอไม่สำเร็จ');
                self::stage($id,'YT_PROBE'); // A crash/timeout must probe the provider before sending bytes again.
                $r=ProviderHttp::request('PUT',$session,array_merge($auth,['Content-Type: video/mp4','Content-Length: '.strlen($chunk),'Content-Range: bytes '.$offset.'-'.($offset+strlen($chunk)-1).'/'.$size]),$chunk);
            }
            if(in_array($r['status'],[200,201],true)){
                $body=ProviderHttp::json($r);if(empty($body['id']))throw new ProviderError('permanent','missing_video_id');self::remote($j,$body['id']);self::stage($id,'YT_PROCESSING');return self::progress('youtube_bytes_complete',15);
            }
            if($r['status']===308){
                $range=$r['headers']['range']??'';$ack=0;
                if($range!==''&&!preg_match('/\Abytes=0-([0-9]+)\z/D',$range,$m))throw new ProviderError('permanent','invalid_upload_range');
                if($range!=='')$ack=(int)$m[1]+1;
                if($ack>$size||($stage==='YT_SEND'&&$ack<$offset))throw new ProviderError('permanent','inconsistent_upload_range');
                Db::run("UPDATE publish_transfers SET acknowledged_bytes=?,stage='YT_SEND',updated_at=? WHERE job_id=?",[$ack,now(),$id]);return self::progress('youtube_chunk_acknowledged');
            }
            if(in_array($r['status'],[404,410],true))throw new ProviderError('permanent','upload_session_expired_check_remote');
            ProviderHttp::json($r);throw new ProviderError('permanent','unexpected_upload_status');
        }
        if($stage==='YT_PROCESSING'){
            $r=ProviderHttp::json(ProviderHttp::request('GET','https://www.googleapis.com/youtube/v3/videos?part=status,processingDetails&id='.rawurlencode($x['remote_id']),$auth));
            $video=$r['items'][0]??null;if(!$video)throw new ProviderError('temporary','video_not_visible',30);
            if(in_array($video['status']['uploadStatus']??'',['failed','rejected','deleted'],true)||($video['processingDetails']['processingStatus']??'')==='failed')throw new ProviderError('permanent','video_processing_failed');
            if(($video['processingDetails']['processingStatus']??'')!=='succeeded'||($video['status']['uploadStatus']??'')!=='processed')return self::progress('youtube_processing',30);
            if(($video['status']['privacyStatus']??'')!==$j['privacy'])throw new ProviderError('permission','youtube_visibility_mismatch');
            return self::published($j,'https://www.youtube.com/watch?v='.rawurlencode($x['remote_id']));
        }
        throw new ProviderError('permanent','unknown_transfer_state');
    }
    public static function mediaUrl(array $j,int $expires): string {
        $payload='facebook-fetch:'.$j['id'].':'.$j['media_asset_id'].':'.$expires;
        return rtrim((string)config('app_url'),'/').'/media-fetch.php?'.http_build_query(['job'=>$j['id'],'expires'=>$expires,'signature'=>hash_hmac('sha256',$payload,Security::key())]);
    }
    private static function facebook(array $j,array $x,string $token): array {
        $auth=['Authorization: Bearer '.$token];$id=(int)$j['id'];$stage=$x['stage'];
        if($stage==='NEW'){
            if(parse_url(config('app_url'),PHP_URL_SCHEME)!=='https')throw new ProviderError('permanent','public_https_media_required');
            $page=ProviderHttp::json(ProviderHttp::request('GET',OAuth::graph(rawurlencode($j['external_account_id']).'?fields=id'),$auth));
            if(($page['id']??'')!==$j['external_account_id'])throw new ProviderError('auth','facebook_page_mismatch');
            self::stage($id,'START_REQUESTED');
            $r=ProviderHttp::form(OAuth::graph($j['external_account_id'].'/video_reels'),['upload_phase'=>'start'],$auth);
            if(empty($r['video_id'])||empty($r['upload_url']))throw new ProviderError('permanent','missing_reel_session');
            ProviderHttp::validateUrl($r['upload_url']);if(parse_url($r['upload_url'],PHP_URL_HOST)!=='rupload.facebook.com')throw new ProviderError('permanent','unexpected_reel_upload_host');
            Db::run("UPDATE publish_transfers SET encrypted_session=?,stage='FB_FETCH',updated_at=? WHERE job_id=?",[Security::encrypt($r['upload_url']),now(),$id]);self::remote($j,(string)$r['video_id']);return self::progress('facebook_session_created');
        }
        if($stage==='FB_FETCH'){
            self::stage($id,'FB_TRANSFER_REQUESTED');
            $r=ProviderHttp::request('POST',Security::decrypt($x['encrypted_session']),['Authorization: OAuth '.$token,'file_url: '.self::mediaUrl($j,time()+7200)],'');ProviderHttp::json($r);
            self::stage($id,'FB_STATUS');return self::progress('facebook_media_requested',30);
        }
        if(in_array($stage,['FB_STATUS','FB_FINISH_REQUESTED','FB_PUBLISH_POLL'],true)){
            $r=ProviderHttp::json(ProviderHttp::request('GET',OAuth::graph($x['remote_id'].'?fields=status,permalink_url'),$auth));$status=$r['status']??[];
            if(($status['publishing_phase']['publish_status']??'')==='published'){
                if(empty($r['permalink_url']))throw new ProviderError('temporary','permalink_not_ready',30);return self::published($j,$r['permalink_url']);
            }
            foreach(['uploading_phase','processing_phase','publishing_phase'] as $phase)if(($status[$phase]['status']??'')==='error')throw new ProviderError('permanent','facebook_'.$phase.'_failed');
            if(in_array($stage,['FB_FINISH_REQUESTED','FB_PUBLISH_POLL'],true))return self::progress('facebook_awaiting_publication',30);
            if(($status['uploading_phase']['status']??'')==='complete'&&($status['processing_phase']['status']??'')==='complete'){self::stage($id,'FB_FINISH');return self::progress('facebook_ready_to_finish');}
            return self::progress('facebook_processing',30);
        }
        if($stage==='FB_FINISH'){
            self::stage($id,'FB_FINISH_REQUESTED');
            ProviderHttp::form(OAuth::graph($j['external_account_id'].'/video_reels'),['upload_phase'=>'finish','video_id'=>$x['remote_id'],'video_state'=>'PUBLISHED','description'=>trim($j['caption']."\n\n".$j['hashtags'])],$auth);
            self::stage($id,'FB_PUBLISH_POLL');return self::progress('facebook_publish_submitted',30);
        }
        throw new ProviderError('permanent','unknown_reel_state');
    }
}
