<?php
declare(strict_types=1);
ini_set('display_errors','0');
$privateRoot=getenv('STUDIO_PRIVATE_ROOT')?:dirname(__DIR__,2).'/studio-private';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);header('Allow: POST');echo '{"error":"method_not_allowed"}';exit;}
try{
    require $privateRoot.'/app/bootstrap.php';
    if(!\Studio\IntegrationSettings::migrated()){http_response_code(503);echo '{"error":"upgrade_required"}';exit;}
    $raw=file_get_contents('php://input',false,null,0,16385);
    \Studio\IntegrationApi::authenticate($raw,parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH),['timestamp'=>$_SERVER['HTTP_X_STUDIO_TIMESTAMP']??'','nonce'=>$_SERVER['HTTP_X_STUDIO_NONCE']??'','signature'=>$_SERVER['HTTP_X_STUDIO_SIGNATURE']??'']);
    $data=json_decode($raw,true,32,JSON_THROW_ON_ERROR);if(!is_array($data))throw new DomainException('invalid_json');
    session_write_close();
    echo json_encode(\Studio\IntegrationApi::handle($data,$raw),JSON_THROW_ON_ERROR);
}catch(DomainException|JsonException $e){http_response_code(401);echo json_encode(['error'=>'request_rejected']);}
catch(Throwable $e){error_log('Studio integration error: '.get_class($e));http_response_code(500);echo '{"error":"integration_failed_check_server_log"}';}
