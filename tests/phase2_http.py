"""HTTP smoke tests against a disposable PHP+MariaDB fixture, never the installed XAMPP DB."""
import hashlib
import hmac
import json
import os
from pathlib import Path
import re
import secrets
import shutil
import subprocess
import time
import sys
import requests

root=Path(__file__).resolve().parents[1]
fixture=root/'.local'/('p2-http-'+secrets.token_hex(4));fixture.mkdir(parents=True)
private=fixture/'private';private.mkdir()
for folder in ['app','views','database','vendor','workflows']:
    shutil.copytree(root/'studio-private'/folder,private/folder)
(private/'var').mkdir()
database='studio_p2_http_'+secrets.token_hex(4)
php=Path('C:/xampp/php/php.exe')
subprocess.run([str(php),'-r',"$p=new PDO('mysql:host=127.0.0.1;port=3306','root','');$p->exec('CREATE DATABASE "+database+" CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');"],check=True)
key=secrets.token_hex(24);password=secrets.token_urlsafe(24)
config=(root/'studio-private/config.example.php').read_text(encoding='utf-8').replace("'production'","'local'").replace('https://www.krusomkhuan.com/studio','http://127.0.0.1:8098/studio').replace("'host' => 'localhost'","'host' => '127.0.0.1'").replace('CHANGE_DATABASE',database).replace('CHANGE_USER','root').replace('CHANGE_PASSWORD','').replace('CHANGE_TO_A_RANDOM_SECRET_AT_LEAST_32_CHARACTERS',key)
(private/'config.php').write_text(config,encoding='utf-8')
router='''<?php
$public=__PUBLIC__;
putenv('STUDIO_PRIVATE_ROOT='.__PRIVATE__);
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if(str_starts_with($path,'/studio/assets/')){
 $file=realpath($public.substr($path,7));
 if($file&&str_starts_with($file,realpath($public.'/assets').DIRECTORY_SEPARATOR)&&is_file($file)){header('Content-Type: '.(str_ends_with($file,'.css')?'text/css':'text/javascript'));readfile($file);return;}
 http_response_code(404);return;
}
if($path==='/studio/integration.php'){require $public.'/integration.php';return;}
if($path==='/studio/media-fetch.php'){require $public.'/media-fetch.php';return;}
if(in_array($path,['/studio/','/studio/index.php'],true)){require $public.'/index.php';return;}
http_response_code(404);
'''.replace('__PUBLIC__',"'"+str(root/'public').replace('\\','/')+"'").replace('__PRIVATE__',"'"+str(private).replace('\\','/')+"'")
(fixture/'router.php').write_text(router,encoding='utf-8')
log=(fixture/'server.log').open('wb')
server=subprocess.Popen([str(php),'-S','127.0.0.1:8098','-t',str(root/'public'),str(fixture/'router.php')],stdout=log,stderr=log,creationflags=subprocess.CREATE_NO_WINDOW if os.name=='nt' else 0)
base='http://127.0.0.1:8098/studio/index.php'
client=requests.Session();client.trust_env=False;passed=[]
def check(name,ok):
    if not ok:raise AssertionError(name)
    passed.append(name);print('PASS',name,flush=True)
def get(page,**kw):
    r=client.get(base,params={'page':page,**kw},timeout=20);r.encoding='utf-8';return r
def post(page,action,data=None,follow=True):
    page_response=get(page)
    match=re.search(r'name="csrf" value="([a-f0-9]+)"',page_response.text)
    assert match, page_response.text[:400]
    r=client.post(base,params={'page':page},data={'csrf':match[1],'action':action,**(data or {})},timeout=30,allow_redirects=follow);r.encoding='utf-8';return r
try:
    for i in range(30):
        try:
            if get('install').status_code==200:break
        except requests.ConnectionError:pass
        time.sleep(.1)
    r=post('install','install',{'setup_key':key,'email':'qa@example.test','display_name':'Phase 2 HTTP QA','password':password,'password_confirm':password})
    check('Fresh base install succeeds','เข้าสู่ Studio' in r.text)
    check('Queue requires authentication','เข้าสู่ Studio' in get('queue').text)
    post('login','login',{'email':'qa@example.test','password':password})
    check('Phase 2 upgrade shown before migration','อัปเกรดฐานข้อมูล' in get('integrations').text)
    r=post('integrations','upgrade_phase2');check('Authenticated additive upgrade succeeds','Meta App ID' in r.text and r.status_code==200)
    r=post('integrations','save_integrations',{'meta_client_id':'1169432712613004','meta_client_secret':'isolated-not-a-real-secret','meta_graph_version':'v27.0','meta_config_id':'','google_client_id':'qa.apps.googleusercontent.com','google_client_secret':'isolated-google-secret'})
    check('Settings save without exposing stored secrets','isolated-not-a-real-secret' not in r.text and 'isolated-google-secret' not in r.text and 'บันทึกค่าการเชื่อมต่อแล้ว' in r.text)
    r=post('accounts','oauth_start',{'provider':'meta'});check('Meta OAuth refuses insecure HTTP callback','Meta OAuth ต้องทดสอบบน URL HTTPS' in r.text)
    r=post('accounts','oauth_start',{'provider':'google'},follow=False)
    check('Google start redirects to official OAuth without contacting it',r.status_code==303 and r.headers['Location'].startswith('https://accounts.google.com/o/oauth2/v2/auth?'))
    r=get('oauth_callback',provider='google',state='invalid',code='invalid')
    check('Invalid callback state safely rejected','OAuth State ไม่ถูกต้อง' in r.text)
    r=post('integrations','rotate_n8n_key');match=re.search(r'id="n8n_new_key" value="([a-f0-9]+)"',r.text);assert match
    secret=match[1];check('HMAC credential displayed once', 'id="n8n_new_key"' not in get('integrations').text)
    api='http://127.0.0.1:8098/studio/integration.php';body=json.dumps({'event_id':'http_qa_123456','operation':'tick'},separators=(',',':'))
    def signed(nonce):
        ts=str(int(time.time()));canonical='\n'.join(['POST','/studio/integration.php',ts,nonce,hashlib.sha256(body.encode()).hexdigest()]);signature=hmac.new(secret.encode(),canonical.encode(),hashlib.sha256).hexdigest();return {'Content-Type':'application/json','X-Studio-Timestamp':ts,'X-Studio-Nonce':nonce,'X-Studio-Signature':signature}
    headers=signed(secrets.token_hex(16));r=client.post(api,data=body,headers=headers,timeout=20);check('Signed n8n request returns paused state',r.status_code==200 and r.json()['status']=='DISABLED')
    r=client.post(api,data=body,headers=headers,timeout=20);check('HTTP replay blocked',r.status_code==401)
    r=client.post(api,data=body,headers=signed(secrets.token_hex(16)),timeout=20);check('Same event with fresh nonce returns cached result',r.json()['status']=='DISABLED')
    check('Unsigned publish request blocked',client.post(api,json={'event_id':'invalid_qa'},timeout=20).status_code==401)
    check('GET cannot trigger publishing',client.get(api,timeout=20).status_code==405)
    check('Unsigned media capability URL blocked',client.get('http://127.0.0.1:8098/studio/media-fetch.php?job=1&expires=1',timeout=20).status_code==403)
    check('Old HMAC workflow still works with no header key configured',client.post(api,data=body,headers=signed(secrets.token_hex(16)),timeout=20).status_code==200)
    r=post('integrations','rotate_n8n_header_key');match=re.search(r'id="n8n_new_header_key" value="([a-f0-9]{64})"',r.text);assert match
    header_key=match[1];check('Header key displayed once and differs from HMAC secret','id="n8n_new_header_key"' not in get('integrations').text and header_key!=secret)
    hbody=json.dumps({'event_id':'http_header_qa_1','operation':'tick'},separators=(',',':'))
    r=client.post(api,data=hbody,headers={'Content-Type':'application/json','X-Studio-Key':header_key},timeout=20);check('Header Auth request accepted over HTTP',r.status_code==200 and r.json()['status']=='DISABLED')
    check('Wrong X-Studio-Key rejected over HTTP',client.post(api,data=hbody,headers={'Content-Type':'application/json','X-Studio-Key':secrets.token_hex(32)},timeout=20).status_code==401)
    check('HMAC secret sent as X-Studio-Key rejected',client.post(api,data=hbody,headers={'Content-Type':'application/json','X-Studio-Key':secret},timeout=20).status_code==401)
    check('HMAC still accepted alongside header key',client.post(api,data=body,headers=signed(secrets.token_hex(16)),timeout=20).status_code==200)
    r=get('workflow',variant='header');hw=r.json();types={n['type']:n['typeVersion'] for n in hw['nodes']}
    check('Header Auth workflow uses only n8n 1.106.3 nodes, no Crypto, no key',hw['active'] is False and types=={'n8n-nodes-base.scheduleTrigger':1.2,'n8n-nodes-base.code':2,'n8n-nodes-base.httpRequest':4.2} and header_key not in r.text and secret not in r.text and 'http://127.0.0.1:8098/studio/integration.php' in r.text and 'HeaderAuth' in r.headers.get('Content-Disposition',''))
    (private/'workflows'/'phase2-publishing-header-auth.json').rename(private/'workflows'/'missing.bak')
    r=get('workflow',variant='header');check('Missing workflow file shows clear 404 instead of 500',r.status_code==404 and 'phase2-publishing-header-auth.json' in r.text)
    (private/'workflows'/'missing.bak').rename(private/'workflows'/'phase2-publishing-header-auth.json')
    post('integrations','revoke_n8n_header_key')
    check('Revoked header key rejected; HMAC unaffected',client.post(api,data=hbody,headers={'Content-Type':'application/json','X-Studio-Key':header_key},timeout=20).status_code==401 and client.post(api,data=body,headers=signed(secrets.token_hex(16)),timeout=20).status_code==200)
    r=get('workflow');workflow=r.json();check('Workflow download contains no credentials or active automation',workflow['active'] is False and secret not in r.text and 'isolated-google-secret' not in r.text and 'http://127.0.0.1:8098/studio/integration.php' in r.text)
    for route in ['dashboard','contents','library','edit','manual','accounts','integrations','queue','settings','audit']:
        r=get(route);check('Screen renders '+route,r.status_code==200)
    r=client.get(base,headers={'Host':'wrong.example'},allow_redirects=False,timeout=20);check('Canonical origin prevents www/CSP mismatch',r.status_code==303 and r.headers['Location'].startswith('http://127.0.0.1:8098/studio/'))
    (root/'docs/phase2-http-test-results.json').write_text(json.dumps({'kind':'Isolated HTTP tests; no external OAuth or social API requests','count':len(passed),'checks':passed},ensure_ascii=False,indent=2),encoding='utf-8')
    print('RESULT',len(passed),'HTTP checks passed; installed XAMPP data unchanged.')
finally:
    if '--preview' in sys.argv:
        (root/'.local/phase2-preview.json').write_text(json.dumps({'pid':server.pid,'url':base,'email':'qa@example.test','password':password,'fixture':str(fixture)}),encoding='utf-8')
        print('Isolated preview retained on 127.0.0.1:8098; credentials kept in ignored local file only.')
    else:
        server.terminate();server.wait(timeout=10)
    log.close()
