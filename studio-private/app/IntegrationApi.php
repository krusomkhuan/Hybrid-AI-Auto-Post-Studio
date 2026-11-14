<?php
declare(strict_types=1);
namespace Studio;

final class IntegrationApi {
    public const HEADER_KEY_NAME='X-Studio-Key';
    /** Returns the accepted mode: 'header_key' (n8n Header Auth credential) or 'hmac' (signed, replay-protected). */
    public static function authenticate(string $raw,string $path,array $headers): string {
        if(strlen($raw)>16384)throw new \DomainException('payload_too_large');
        $presented=$headers['key']??'';
        if($presented!==''){
            // A presented key never falls back to HMAC; a wrong key is rejected outright.
            $stored=(string)IntegrationSettings::get('n8n_header_key_hash','');
            if($stored===''||!preg_match('/\A[a-f0-9]{64}\z/D',$presented)||!hash_equals($stored,hash('sha256',$presented)))throw new \DomainException('authentication_failed');
            return 'header_key';
        }
        $secret=IntegrationSettings::secret('n8n_hmac_secret');
        $timestamp=$headers['timestamp']??'';$nonce=$headers['nonce']??'';$signature=$headers['signature']??'';
        if($secret===''||!ctype_digit($timestamp)||abs(time()-(int)$timestamp)>300||!preg_match('/\A[a-f0-9]{32,64}\z/D',$nonce)||!preg_match('/\A[a-f0-9]{64}\z/D',$signature))throw new \DomainException('authentication_failed');
        $signed="POST\n".$path."\n".$timestamp."\n".$nonce."\n".hash('sha256',$raw);
        if(!hash_equals(hash_hmac('sha256',$signed,$secret),$signature))throw new \DomainException('authentication_failed');
        Db::run('DELETE FROM request_nonces WHERE expires_at<?',[now()]);
        try{Db::run('INSERT INTO request_nonces(nonce_hash,expires_at) VALUES(?,?)',[hash('sha256',$nonce),gmdate('Y-m-d H:i:s',time()+600)]);}
        catch(\PDOException $e){if(($e->errorInfo[1]??0)===1062)throw new \DomainException('replay_rejected');throw $e;}
        return 'hmac';
    }
    public static function handle(array $data,string $raw,string $authMode='hmac'): array {
        $event=$data['event_id']??null;
        if(!is_string($event)||!preg_match('/\A[a-zA-Z0-9_-]{8,100}\z/D',$event)||($data['operation']??'tick')!=='tick')throw new \DomainException('invalid_event');
        $existing=Db::transaction(function()use($event,$raw){
            $r=Db::one('SELECT * FROM integration_events WHERE event_id=? FOR UPDATE',[$event]);
            if($r){if(!hash_equals($r['payload_hash'],hash('sha256',$raw)))throw new \DomainException('event_payload_mismatch');return $r;}
            Db::run('INSERT INTO integration_events(event_id,payload_hash,status,created_at) VALUES(?,?,?,?)',[$event,hash('sha256',$raw),'PROCESSING',now()]);return null;
        });
        if($existing)return $existing['result_json']?json_decode($existing['result_json'],true,512,JSON_THROW_ON_ERROR):['status'=>'EVENT_PROCESSING'];
        $result=Publisher::tick();
        Db::run('UPDATE integration_events SET status=?,result_json=? WHERE event_id=?',['COMPLETED',json_encode($result,JSON_THROW_ON_ERROR),$event]);
        Db::run('INSERT INTO workflow_logs(correlation_id,workflow_key,event_type,severity,sanitized_summary,created_at) VALUES(?,?,?,?,?,?)',[$event,'phase2_publishing_tick',$result['status'],'info',json_encode($result+['auth'=>$authMode],JSON_THROW_ON_ERROR),now()]);
        // Keep a bounded recent execution history. Business history remains in publish_attempts/audit_logs.
        Db::run('DELETE FROM integration_events WHERE created_at<?',[gmdate('Y-m-d H:i:s',time()-30*86400)]);
        return $result;
    }
}
