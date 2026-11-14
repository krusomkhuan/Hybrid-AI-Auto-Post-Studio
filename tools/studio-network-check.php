<?php
declare(strict_types=1);
// Temporary, fixed-destination check. No Studio bootstrap, configuration or credentials.
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');
$test=is_string($_GET['test']??null)?$_GET['test']:'';
if(!in_array($test,['a','aaaa','auto','ipv4','ipv6'],true)){
    header('Content-Type: text/html; charset=utf-8');
    header("Content-Security-Policy: default-src 'none'; base-uri 'none'; frame-ancestors 'none'");
    echo '<!doctype html><html lang="th"><meta charset="utf-8"><title>Studio network check</title><h1>ตรวจเครือข่ายไปยัง Meta</h1><p>ทดสอบทีละรายการจาก PHP บนโฮสติ้ง โดยไม่ใช้ Token หรือ Secret</p><ul>';
    foreach(['auto'=>'HTTPS ตามค่าเริ่มต้น (ทดสอบข้อนี้ก่อน)','a'=>'DNS A — IPv4','aaaa'=>'DNS AAAA — IPv6','ipv4'=>'HTTPS บังคับ IPv4','ipv6'=>'HTTPS บังคับ IPv6'] as $key=>$label)echo '<li><a href="?test='.$key.'" target="_blank" rel="noopener">'.$label.'</a></li>';
    echo '</ul><p>แต่ละผลเปิดในแท็บใหม่ ส่งภาพผลกลับมาวิเคราะห์ เมื่อเสร็จให้ลบไฟล์นี้จากโฮสติ้ง</p><p>IPv6 ล้มเหลวเพียงอย่างเดียวไม่ได้แปลว่าระบบใช้งานไม่ได้ หาก HTTPS ค่าเริ่มต้นหรือ IPv4 สำเร็จ</p></html>';
    exit;
}
header('Content-Type: text/plain; charset=utf-8');
$host='graph.facebook.com';
$report=['host'=>$host,'test'=>$test,'utc'=>gmdate('c')];
if($test==='a'||$test==='aaaa'){
    if(!function_exists('dns_get_record'))$report['result']='dns_get_record unavailable; ask hosting support';
    else{
        $start=microtime(true);
        $records=@dns_get_record($host,$test==='a'?DNS_A:DNS_AAAA);
        $report['elapsed_ms']=(int)round((microtime(true)-$start)*1000);
        $report['result']=$records===false?'DNS query failed':(count($records)?'DNS response received':'No records returned (not necessarily a DNS failure)');
        $report['records']=[];
        foreach($records?:[] as $record)$report['records'][]=array_intersect_key($record,array_flip(['host','type','ip','ipv6','target','ttl']));
    }
}elseif(!function_exists('curl_init'))$report['result']='PHP cURL unavailable';
else{
    $ch=curl_init('https://graph.facebook.com/');
    curl_setopt_array($ch,[CURLOPT_NOBODY=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>8,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_IPRESOLVE=>match($test){'ipv4'=>CURL_IPRESOLVE_V4,'ipv6'=>CURL_IPRESOLVE_V6,default=>CURL_IPRESOLVE_WHATEVER}]);
    $ok=curl_exec($ch);$errno=curl_errno($ch);$info=curl_getinfo($ch);curl_close($ch);
    $report['result']=$ok!==false?'HTTPS response received (does not test OAuth permissions)':'cURL failed';
    $report['curl_errno']=$errno;
    $report['http_status']=(int)$info['http_code'];
    $report['remote_ip']=$info['primary_ip'];
    foreach(['namelookup_time','connect_time','appconnect_time','starttransfer_time','total_time'] as $key)$report[$key.'_ms']=(int)round((float)($info[$key]??0)*1000);
    $report['note']='Any HTTP response, including 400/401/403/404/405, shows a response was received over HTTPS; OAuth has not been tested. Timing values are cumulative.';
}
echo json_encode($report,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
