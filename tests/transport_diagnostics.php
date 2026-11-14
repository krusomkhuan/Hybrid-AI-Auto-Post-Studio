<?php
declare(strict_types=1);
// No network, database or real credentials are used.
require dirname(__DIR__).'/studio-private/app/Integrations.php';
use Studio\{ProviderHttp,ProviderError};
$secret='private-query-fixture';
$cases=[
    ['https://graph.facebook.com/v27.0/oauth/access_token?client_secret='.$secret,'token',[], 'dns'],
    ['https://graph.facebook.com/v27.0/me/accounts?access_token='.$secret,'pages',['namelookup_time'=>0.02], 'connect'],
    ['https://graph.facebook.com/v27.0/me/permissions?appsecret_proof='.$secret,'permissions',['namelookup_time'=>0.02,'connect_time'=>0.1], 'tls'],
    ['https://graph.facebook.com/v27.0/me?fields=id','identity',['namelookup_time'=>0.02,'connect_time'=>0.1,'appconnect_time'=>0.2], 'response'],
    ['https://www.googleapis.com/upload/'.$secret.'?upload_id='.$secret,'api',['namelookup_time'=>0.02,'connect_time'=>0.1,'appconnect_time'=>0.2,'starttransfer_time'=>0.3], 'transfer'],
];
foreach($cases as [$url,$step,$info,$phase]){
    $d=ProviderHttp::transportDiagnostics($url,$info+['total_time'=>20]);
    $e=new ProviderError('network','transport_28',0,$d);
    if($d['step']!==$step||$d['phase']!==$phase||$d['elapsed_ms']!==20000||str_contains(json_encode($d).$e->connectionMessage(),$secret)||str_contains($e->connectionMessage(),'ยังไม่อนุญาต'))throw new RuntimeException('Unsafe or incorrect transport diagnosis');
}
if(!str_contains((new ProviderError('permission','http_403_200'))->connectionMessage(),'http_403_200'))throw new RuntimeException('Provider code missing');
echo "PASS 5 sanitized transport stages and provider error message\n";
