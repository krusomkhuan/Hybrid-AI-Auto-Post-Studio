<?php
declare(strict_types=1);
namespace Studio;

final class IntegrationSettings {
    public static function get(string $key, mixed $default=''): mixed {
        $r=Db::one('SELECT value_json FROM system_settings WHERE setting_key=?',['integration.'.$key]);
        return $r ? json_decode($r['value_json'],true,512,JSON_THROW_ON_ERROR) : $default;
    }
    public static function put(string $key,mixed $value): void {
        Db::run('INSERT INTO system_settings(setting_key,value_json,updated_at) VALUES(?,?,?) ON DUPLICATE KEY UPDATE value_json=VALUES(value_json),updated_at=VALUES(updated_at)',['integration.'.$key,json_encode($value,JSON_THROW_ON_ERROR),now()]);
    }
    public static function secret(string $key): string { $v=self::get($key);return $v!==''?Security::decrypt($v):''; }
    public static function putSecret(string $key,string $value): void { self::put($key,Security::encrypt($value)); }
    public static function configured(string $provider): bool {
        return self::get($provider.'_client_id')!=='' && self::get($provider.'_client_secret')!=='' && ($provider!=='meta'||self::get('meta_graph_version')!=='');
    }
    public static function migrated(): bool { return (bool)Db::one('SELECT version FROM schema_migrations WHERE version=?',['002_phase2']); }
    public static function migrate(): void {
        $locked=(int)Db::one('SELECT GET_LOCK(?,0) l',['studio.phase2.migration'])['l'];
        if(!$locked)throw new \DomainException('มีการอัปเกรดทำงานอยู่ กรุณารอสักครู่');
        try {
            foreach(preg_split('/;\s*(?:\r?\n|$)/',file_get_contents(STUDIO_ROOT.'/database/002_phase2.sql')) as $sql){ if(trim($sql)!=='')Db::connection()->exec($sql); }
            Db::run('INSERT IGNORE INTO schema_migrations(version,applied_at) VALUES(?,?)',['002_phase2',now()]);
            audit('phase2_migrated','system',null);
        }finally{Db::run('SELECT RELEASE_LOCK(?)',['studio.phase2.migration']);}
    }
}

final class ProviderError extends \RuntimeException {
    public function __construct(public string $category,public string $safeCode,public int $retryAfter=0,public array $diagnostics=[]){parent::__construct('Provider request failed: '.$safeCode);}
    public function connectionMessage(): string {
        if($this->category!=='network')return 'แพลตฟอร์มตอบกลับข้อผิดพลาดระหว่างเชื่อมบัญชี: '.$this->safeCode;
        $message=$this->safeCode==='transport_28'?'การติดต่อจากเซิร์ฟเวอร์ไปยัง API หมดเวลารอ':'เซิร์ฟเวอร์ติดต่อ API ไม่สำเร็จ';
        $d=$this->diagnostics;
        return $message.' ('.$this->safeCode.')'.($d?' — '.$d['host'].' / '.$d['step'].' / '.$d['phase'].' / '.$d['elapsed_ms'].' ms':'').' กรุณาเริ่ม Connect ใหม่ หากเกิดซ้ำให้แจ้งผู้ดูแลโฮสติ้งพร้อมข้อความนี้';
    }
}

final class ProviderHttp {
    private static ?\Closure $testTransport=null;
    private static ?\CurlShareHandle $graphDnsCache=null;
    public static function setTestTransport(\Closure $transport): void {
        if(PHP_SAPI!=='cli'||config('environment')!=='test')throw new \LogicException('Test transport is CLI-only in an isolated test environment');
        self::$testTransport=$transport;
    }
    // Never follow redirects or trust arbitrary URLs received from remote responses.
    public static function request(string $method,string $url,array $headers=[],?string $body=null): array {
        self::validateUrl($url);
        if(self::$testTransport)return (self::$testTransport)($method,$url,$headers,$body);
        $ch=curl_init($url);$responseHeaders=[];$response='';
        curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_RETURNTRANSFER=>false,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,CURLOPT_PROTOCOLS_STR=>'https',CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_HEADERFUNCTION=>static function($ch,$line)use(&$responseHeaders){$p=explode(':',$line,2);if(count($p)===2)$responseHeaders[strtolower(trim($p[0]))]=trim($p[1]);return strlen($line);},
            CURLOPT_WRITEFUNCTION=>static function($ch,$chunk)use(&$response){if(strlen($response)+strlen($chunk)>4194304)return 0;$response.=$chunk;return strlen($chunk);},
        ]);
        // Hosting diagnostic: Graph HTTPS works over IPv4; its IPv6 route is unavailable.
        // Keep other providers on their default address selection and keep TLS verification enabled.
        if(strtolower((string)parse_url($url,PHP_URL_HOST))==='graph.facebook.com'){
            curl_setopt($ch,CURLOPT_IPRESOLVE,CURL_IPRESOLVE_V4);
            // New easy handles otherwise discard their DNS cache after every OAuth step.
            // Share only DNS within this PHP execution, never cookies, headers or tokens.
            if(self::$graphDnsCache===null){
                $share=curl_share_init();
                if(!curl_share_setopt($share,CURLSHOPT_SHARE,CURL_LOCK_DATA_DNS))throw new ProviderError('network','dns_cache_setup_failed');
                self::$graphDnsCache=$share;
            }
            if(!curl_setopt($ch,CURLOPT_SHARE,self::$graphDnsCache))throw new ProviderError('network','dns_cache_attach_failed');
            curl_setopt($ch,CURLOPT_DNS_CACHE_TIMEOUT,60);
        }
        if($body!==null)curl_setopt($ch,CURLOPT_POSTFIELDS,$body);
        $ok=curl_exec($ch);$info=curl_getinfo($ch);$info['appconnect_time']=curl_getinfo($ch,CURLINFO_APPCONNECT_TIME);$status=(int)$info['http_code'];$errno=curl_errno($ch);curl_close($ch);
        if($ok===false)throw new ProviderError('network','transport_'.$errno,0,self::transportDiagnostics($url,$info));
        return ['status'=>$status,'headers'=>$responseHeaders,'body'=>$response,'json'=>json_decode($response,true)];
    }
    // Only fixed labels and timings: never expose URLs, query strings, headers or raw cURL errors.
    public static function transportDiagnostics(string $url,array $info): array {
        self::validateUrl($url);
        $path=parse_url($url,PHP_URL_PATH)??'';
        $step='api';
        foreach(['token'=>'~/(?:oauth/access_token|token)$~','identity'=>'~/me$~','permissions'=>'~/me/permissions$~','pages'=>'~/me/accounts$~','channels'=>'~/youtube/v3/channels$~'] as $label=>$pattern){
            if(preg_match($pattern,$path)){$step=$label;break;}
        }
        $phase=empty($info['namelookup_time'])?'dns':(empty($info['connect_time'])?'connect':(empty($info['appconnect_time'])?'tls':(empty($info['starttransfer_time'])?'response':'transfer')));
        return ['host'=>strtolower(parse_url($url,PHP_URL_HOST)),'step'=>$step,'phase'=>$phase,'elapsed_ms'=>(int)round(max(0,(float)($info['total_time']??0))*1000)];
    }
    public static function validateUrl(string $url): void {
        $p=parse_url($url);
        if(!$p||($p['scheme']??'')!=='https'||isset($p['user'])||isset($p['pass'])||(isset($p['port'])&&$p['port']!==443)||!in_array(strtolower($p['host']??''),['graph.facebook.com','rupload.facebook.com','oauth2.googleapis.com','www.googleapis.com','accounts.google.com'],true))throw new \DomainException('ปลายทาง API หรือ Upload Session ไม่ได้รับอนุญาต');
    }
    public static function json(array $r): array {
        if($r['status']<200||$r['status']>=300||isset($r['json']['error'])){
            $error=$r['json']['error']??null;
            $code=is_array($error)?(string)($error['code']??$r['status']):(is_string($error)?$error:(string)$r['status']);
            $reason=is_array($error)?($error['errors'][0]['reason']??''):'';
            $quota=in_array($reason,['quotaExceeded','dailyLimitExceeded','uploadLimitExceeded'],true);
            if($quota)$code=$reason;
            $code=preg_replace('/[^a-zA-Z0-9_-]/','',substr($code,0,60));
            $category=($r['status']===401||in_array($code,['190','invalid_grant','invalid_token'],true))?'auth':(($r['status']===429||$r['status']>=500||in_array($code,['4','17','32','613'],true))?'temporary':($r['status']===403||in_array($code,['10','200','294'],true)?'permission':'permanent'));
            $retry=min(3600,max(0,(int)($r['headers']['retry-after']??0)));
            if($quota){$category='temporary';$retry=(new \DateTimeImmutable('tomorrow',new \DateTimeZone('America/Los_Angeles')))->getTimestamp()-time()+60;}
            throw new ProviderError($category,'http_'.$r['status'].'_'.$code,$retry);
        }
        if(!is_array($r['json']))throw new ProviderError('permanent','invalid_json');
        return $r['json'];
    }
    public static function form(string $url,array $data,array $headers=[]): array {return self::json(self::request('POST',$url,array_merge(['Content-Type: application/x-www-form-urlencoded'],$headers),http_build_query($data)));}
}

final class OAuth {
    public static function callbackUrl(string $provider): string {return url('oauth_callback',['provider'=>$provider]);}
    public static function graph(string $path,?string $token=null): string {
        $version=IntegrationSettings::get('meta_graph_version');
        if(!preg_match('/\Av[0-9]{1,2}\.0\z/D',$version))throw new \DomainException('กรุณาระบุ Graph API Version ในหน้าการเชื่อมต่อ');
        $url='https://graph.facebook.com/'.$version.'/'.ltrim($path,'/');
        if($token!==null)$url.=(str_contains($url,'?')?'&':'?').'appsecret_proof='.hash_hmac('sha256',$token,IntegrationSettings::secret('meta_client_secret'));
        return $url;
    }
    public static function start(string $provider,int $adminId): string {
        if(!in_array($provider,['meta','google'],true)||!IntegrationSettings::configured($provider))throw new \DomainException('กรุณาตั้งค่า App ID/Client ID และ Secret ก่อนเชื่อมบัญชี');
        if($provider==='meta' && parse_url(config('app_url'),PHP_URL_SCHEME)!=='https')throw new \DomainException('Meta OAuth ต้องทดสอบบน URL HTTPS ที่ลงทะเบียนใน Meta App เช่น Hosting จริง');
        $state=bin2hex(random_bytes(32));$verifier=rtrim(strtr(base64_encode(random_bytes(48)),'+/','-_'),'=');
        Db::run('DELETE FROM oauth_states WHERE expires_at<?',[now()]);
        Db::run('INSERT INTO oauth_states(state_hash,admin_id,provider,encrypted_verifier,session_hash,expires_at) VALUES(?,?,?,?,?,?)',[hash('sha256',$state),$adminId,$provider,Security::encrypt($verifier),hash('sha256',session_id()),gmdate('Y-m-d H:i:s',time()+600)]);
        $params=['client_id'=>IntegrationSettings::get($provider.'_client_id'),'redirect_uri'=>self::callbackUrl($provider),'response_type'=>'code','state'=>$state];
        if($provider==='google'){
            $params+=['scope'=>'https://www.googleapis.com/auth/youtube.upload https://www.googleapis.com/auth/youtube.readonly','access_type'=>'offline','prompt'=>'consent select_account','code_challenge'=>rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'='),'code_challenge_method'=>'S256'];
            return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query($params);
        }
        $params+=['scope'=>'pages_show_list,pages_read_engagement,pages_manage_posts','auth_type'=>'rerequest'];
        $configuration=IntegrationSettings::get('meta_config_id');
        if($configuration!==''){$params['config_id']=$configuration;unset($params['scope'],$params['auth_type']);}
        return 'https://www.facebook.com/'.IntegrationSettings::get('meta_graph_version').'/dialog/oauth?'.http_build_query($params);
    }
    public static function consumeState(string $provider,string $state,int $adminId): array {
        return Db::transaction(function()use($provider,$state,$adminId){
            $hash=hash('sha256',$state);$r=Db::one('SELECT * FROM oauth_states WHERE state_hash=? FOR UPDATE',[$hash]);
            if(!$r||$r['provider']!==$provider||(int)$r['admin_id']!==$adminId||$r['expires_at']<now()||!hash_equals($r['session_hash'],hash('sha256',session_id())))throw new \DomainException('OAuth State ไม่ถูกต้องหรือหมดอายุ กรุณาเริ่มเชื่อมต่อใหม่');
            Db::run('DELETE FROM oauth_states WHERE state_hash=?',[$hash]);return $r;
        });
    }
    public static function complete(string $provider,string $state,string $code,int $adminId,bool $denied=false): int {
        $record=self::consumeState($provider,$state,$adminId);
        if($denied||$code==='')throw new \DomainException('ไม่ได้รับอนุญาตจากแพลตฟอร์ม ยังไม่เชื่อมบัญชี');
        if($provider==='google'){
            $token=ProviderHttp::form('https://oauth2.googleapis.com/token',['code'=>$code,'client_id'=>IntegrationSettings::get('google_client_id'),'client_secret'=>IntegrationSettings::secret('google_client_secret'),'redirect_uri'=>self::callbackUrl('google'),'grant_type'=>'authorization_code','code_verifier'=>Security::decrypt($record['encrypted_verifier'])]);
            self::requireToken($token);
            $scopes=preg_split('/\s+/',(string)($token['scope']??''),-1,PREG_SPLIT_NO_EMPTY);
            foreach(['https://www.googleapis.com/auth/youtube.upload','https://www.googleapis.com/auth/youtube.readonly'] as $scope)if(!in_array($scope,$scopes,true))throw new \DomainException('Google ยังไม่ยืนยันสิทธิ์ Upload และ Read-only ครบ กรุณาอนุญาตทั้งสองสิทธิ์แล้วเชื่อมใหม่');
            $channels=ProviderHttp::json(ProviderHttp::request('GET','https://www.googleapis.com/youtube/v3/channels?part=id,snippet&mine=true',['Authorization: Bearer '.$token['access_token']]));
            if(empty($channels['items']))throw new \DomainException('บัญชีที่อนุญาตไม่มี YouTube Channel');
            return Db::transaction(function()use($channels,$token,$adminId){$n=0;foreach($channels['items'] as $channel){
                $old=Db::one('SELECT * FROM social_connections WHERE provider=? AND provider_subject_id=?',['youtube',$channel['id']]);
                if(empty($token['refresh_token'])&&empty($old['encrypted_refresh_token']))throw new \DomainException('ไม่ได้รับ Refresh Token กรุณาเชื่อม Google ใหม่และอนุญาต Offline Access');
                $connection=self::saveConnection('youtube',$channel['id'],$token,$adminId,$old);
                self::saveAccount('youtube',$channel['id'],$channel['snippet']['title'],$connection);$n++;
            }return $n;});
        }
        $short=ProviderHttp::json(ProviderHttp::request('GET',self::graph('oauth/access_token').'?'.http_build_query(['client_id'=>IntegrationSettings::get('meta_client_id'),'client_secret'=>IntegrationSettings::secret('meta_client_secret'),'redirect_uri'=>self::callbackUrl('meta'),'code'=>$code])));self::requireToken($short);
        $token=ProviderHttp::json(ProviderHttp::request('GET',self::graph('oauth/access_token').'?'.http_build_query(['grant_type'=>'fb_exchange_token','client_id'=>IntegrationSettings::get('meta_client_id'),'client_secret'=>IntegrationSettings::secret('meta_client_secret'),'fb_exchange_token'=>$short['access_token']])));self::requireToken($token);
        $headers=['Authorization: Bearer '.$token['access_token']];
        $identity=ProviderHttp::json(ProviderHttp::request('GET',self::graph('me?fields=id',$token['access_token']),$headers));
        $permissions=ProviderHttp::json(ProviderHttp::request('GET',self::graph('me/permissions',$token['access_token']),$headers));
        $granted=array_column(array_filter($permissions['data']??[],fn($p)=>($p['status']??'')==='granted'),'permission');
        foreach(['pages_show_list','pages_read_engagement','pages_manage_posts'] as $scope)if(!in_array($scope,$granted,true))throw new \DomainException('สิทธิ์ Meta ยังไม่ครบ: '.$scope);
        $pages=[];$after='';
        do{$r=ProviderHttp::json(ProviderHttp::request('GET',self::graph('me/accounts?fields=id,name,access_token,tasks&limit=100'.($after!==''?'&after='.rawurlencode($after):''),$token['access_token']),$headers));foreach($r['data']??[] as $p)$pages[]=$p;
            $after=!empty($r['paging']['next'])?($r['paging']['cursors']['after']??''):'';
            if(count($pages)>1000)throw new \DomainException('รายการเพจเกินขอบเขตการเชื่อมครั้งเดียว กรุณาจำกัดเพจที่อนุญาต');
        }while($after!=='');
        if(!$pages)throw new \DomainException('ไม่พบเพจที่ได้รับอนุญาตให้จัดการ');
        $token['scope']=implode(' ',$granted);
        return Db::transaction(function()use($pages,$identity,$token,$adminId){
            $old=Db::one('SELECT * FROM social_connections WHERE provider=? AND provider_subject_id=?',['meta',$identity['id']]);
            $connection=self::saveConnection('meta',$identity['id'],$token,$adminId,$old);
            // Pages omitted during reconnect must not retain a stale CONNECTED capability.
            Db::run("UPDATE social_accounts SET connection_status='AUTH_REQUIRED' WHERE connection_id=?",[$connection]);
            $n=0;foreach($pages as $page){if(empty($page['access_token']))continue;
                $id=self::saveAccount('facebook_page',$page['id'],$page['name'],$connection);
                $tasks=$page['tasks']??[];$canPublish=(bool)array_intersect($tasks,['CREATE_CONTENT','MANAGE','PROFILE_PLUS_CREATE_CONTENT','PROFILE_PLUS_FULL_CONTROL']);
                Db::run('UPDATE facebook_pages SET encrypted_page_token=?,permissions_status=?,permission_check_at=?,token_expires_at=? WHERE account_id=?',[Security::encrypt($page['access_token']),$canPublish?'GRANTED':'TASK_REQUIRED',now(),null,$id]);
                if(!$canPublish)Db::run("UPDATE social_accounts SET connection_status='AUTH_REQUIRED' WHERE id=?",[$id]);$n++;
            }return $n;
        });
    }
    private static function requireToken(array $token): void {if(empty($token['access_token'])||!is_string($token['access_token']))throw new ProviderError('auth','missing_token');}
    private static function saveConnection(string $provider,string $subject,array $token,int $adminId,?array $old): int {
        $expiry=isset($token['expires_in'])?gmdate('Y-m-d H:i:s',time()+(int)$token['expires_in']):null;
        $refresh=!empty($token['refresh_token'])?Security::encrypt($token['refresh_token']):($old['encrypted_refresh_token']??null);
        if($old){Db::run('UPDATE social_connections SET encrypted_access_token=?,encrypted_refresh_token=?,expires_at=?,granted_scopes=?,status=?,updated_at=? WHERE id=?',[Security::encrypt($token['access_token']),$refresh,$expiry,$token['scope']??($old['granted_scopes']??''),'CONNECTED',now(),$old['id']]);return (int)$old['id'];}
        Db::run('INSERT INTO social_connections(admin_id,provider,provider_subject_id,encrypted_access_token,encrypted_refresh_token,expires_at,granted_scopes,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?)',[$adminId,$provider,$subject,Security::encrypt($token['access_token']),$refresh,$expiry,$token['scope']??'','CONNECTED',now(),now()]);return Db::id();
    }
    private static function saveAccount(string $platform,string $external,string $name,int $connection): int {
        Db::run('INSERT INTO social_accounts(connection_id,platform,external_account_id,display_name,connection_status,created_at,updated_at) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE connection_id=VALUES(connection_id),display_name=VALUES(display_name),connection_status=VALUES(connection_status),updated_at=VALUES(updated_at)',[$connection,$platform,$external,$name,'CONNECTED',now(),now()]);
        $id=(int)Db::one('SELECT id FROM social_accounts WHERE platform=? AND external_account_id=?',[$platform,$external])['id'];
        if($platform==='facebook_page')Db::run('INSERT IGNORE INTO facebook_pages(account_id,page_id) VALUES(?,?)',[$id,$external]);
        else Db::run('INSERT INTO youtube_channels(account_id,channel_id,upload_capability_status) VALUES(?,?,?) ON DUPLICATE KEY UPDATE upload_capability_status=VALUES(upload_capability_status)',[$id,$external,'AUTHORIZED']);
        return $id;
    }
    public static function accessToken(int $accountId): string {
        return Db::transaction(function()use($accountId){
            $a=Db::one('SELECT a.*,c.encrypted_access_token,c.encrypted_refresh_token,c.expires_at,c.status AS oauth_status FROM social_accounts a LEFT JOIN social_connections c ON c.id=a.connection_id WHERE a.id=? FOR UPDATE',[$accountId]);
            if(!$a||!$a['enabled']||$a['connection_status']!=='CONNECTED'||$a['oauth_status']!=='CONNECTED')throw new ProviderError('auth','account_not_connected');
            if($a['platform']==='facebook_page'){
                $p=Db::one('SELECT * FROM facebook_pages WHERE account_id=?',[$accountId]);
                if(!$p['encrypted_page_token']||$p['permissions_status']!=='GRANTED')throw new ProviderError('permission','page_permissions');
                return Security::decrypt($p['encrypted_page_token']);
            }
            if(!$a['expires_at']||$a['expires_at']<=gmdate('Y-m-d H:i:s',time()+120)){
                if(!$a['encrypted_refresh_token'])throw new ProviderError('auth','refresh_required');
                $t=ProviderHttp::form('https://oauth2.googleapis.com/token',['client_id'=>IntegrationSettings::get('google_client_id'),'client_secret'=>IntegrationSettings::secret('google_client_secret'),'refresh_token'=>Security::decrypt($a['encrypted_refresh_token']),'grant_type'=>'refresh_token']);self::requireToken($t);
                Db::run('UPDATE social_connections SET encrypted_access_token=?,expires_at=?,updated_at=? WHERE id=?',[Security::encrypt($t['access_token']),gmdate('Y-m-d H:i:s',time()+(int)($t['expires_in']??3600)),now(),$a['connection_id']]);return $t['access_token'];
            }
            return Security::decrypt($a['encrypted_access_token']);
        });
    }
    public static function disconnect(int $accountId): void {
        Db::transaction(function()use($accountId){
            $a=Db::one('SELECT * FROM social_accounts WHERE id=? FOR UPDATE',[$accountId]);
            if(!$a||$a['platform']==='facebook_personal')throw new \DomainException('ไม่สามารถ Disconnect บัญชีนี้');
            // Local disconnection is per destination; do not revoke the shared Facebook login for other Pages.
            Db::run("UPDATE social_accounts SET connection_status='DISCONNECTED',updated_at=? WHERE id=?",[now(),$accountId]);
            if($a['platform']==='facebook_page')Db::run("UPDATE facebook_pages SET encrypted_page_token=NULL,permissions_status='NOT_CONNECTED' WHERE account_id=?",[$accountId]);
            Db::run("UPDATE publish_jobs j JOIN publish_targets t ON t.id=j.target_id SET j.status='AUTH_REQUIRED' WHERE t.account_id=? AND j.status IN ('READY','RETRY_WAIT','AUTH_REQUIRED')",[$accountId]);
            audit('account_disconnected_locally','social_accounts',$accountId);
        });
    }
}
