<?php
declare(strict_types=1);
ini_set('display_errors','0');
$privateRoot=getenv('STUDIO_PRIVATE_ROOT')?:dirname(__DIR__,2).'/studio-private';
try{
    if(!in_array($_SERVER['REQUEST_METHOD'],['GET','HEAD'],true)){http_response_code(405);exit;}
    require $privateRoot.'/app/bootstrap.php';
    if(!\Studio\IntegrationSettings::migrated()){http_response_code(404);exit;}
    $job=\Studio\int_id($_GET['job']??null);$expiry=\Studio\int_id($_GET['expires']??null);$signature=$_GET['signature']??'';
    if($expiry<time()||$expiry>time()+7200||!is_string($signature)||strlen($signature)!==64){http_response_code(403);exit;}
    $j=\Studio\Publisher::validateApproval($job);
    $expected=hash_hmac('sha256','facebook-fetch:'.$job.':'.$j['media_asset_id'].':'.$expiry,\Studio\Security::key());
    if($j['platform']!=='facebook_page'||!hash_equals($expected,$signature)){http_response_code(403);exit;}
    $m=\Studio\Db::one('SELECT * FROM media_assets WHERE id=?',[$j['media_asset_id']]);\Studio\Media::stream($m,false);
}catch(Throwable $e){http_response_code(403);exit;}
