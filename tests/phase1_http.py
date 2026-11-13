"""Real HTTP + MariaDB tests, LOCAL ONLY. No social API calls or production data."""
from datetime import datetime, timedelta, timezone
import html
import json
from pathlib import Path
import re
import subprocess
import uuid
import requests

ROOT = Path(__file__).resolve().parents[1]
BASE = 'http://127.0.0.1:8097/studio/index.php'
secret = json.loads((ROOT / '.local/test-secrets.json').read_text())
client = requests.Session()
client.trust_env = False
passed = []

def check(name, condition):
    if not condition:
        raise AssertionError(name)
    passed.append(name)
    print('PASS', name, flush=True)

def sql(query, ok=True):
    command = [str(ROOT / '.tools/mariadb/bin/mariadb.exe'), '--no-defaults', '--host=127.0.0.1', '--port=33079', '--user=root', '--batch', '--skip-column-names', '--database=' + db, '-e', query]
    result = subprocess.run(command, capture_output=True, text=True, encoding='utf-8')
    if ok and result.returncode:
        raise AssertionError(result.stderr)
    if not ok:
        return result.returncode != 0
    return result.stdout.strip()

def get(page, **kwargs):
    response = client.get(BASE, params={'page': page, **kwargs}, timeout=30)
    response.encoding = 'utf-8'
    return response

def token(page, **kwargs):
    response = get(page, **kwargs)
    match = re.search(r'name="csrf" value="([a-f0-9]+)"', response.text)
    if not match:
        (ROOT / '.local/failed-response.html').write_text(response.text, encoding='utf-8')
        raise AssertionError('No CSRF field: ' + page + ' HTTP ' + str(response.status_code))
    return match[1]

def post(page, action, data=None, files=None, params=None):
    values = {'csrf': token(page, **(params or {})), 'action': action, **(data or {})}
    response = client.post(BASE, params={'page': page, **(params or {})}, data=values, files=files, timeout=60)
    response.encoding = 'utf-8'
    if response.status_code >= 500:
        (ROOT / '.local/failed-response.html').write_text(response.text, encoding='utf-8')
        raise AssertionError('Server error: ' + page)
    return response

config = ROOT / 'studio-private/config.php'
source = config.read_text(encoding='utf-8')
assert "'environment' => 'local'" in source and "'port' => 33079" in source, 'Local config required'
db = 'studio_phase1_test_' + uuid.uuid4().hex[:8]
subprocess.run([str(ROOT / '.tools/mariadb/bin/mariadb.exe'), '--no-defaults', '--host=127.0.0.1', '--port=33079', '--user=root', '-e', 'CREATE DATABASE ' + db + ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'], check=True, capture_output=True)
source = re.sub(r"'name' => 'studio_phase1_test[^']*'", "'name' => '" + db + "'", source)
media_root = str(ROOT / '.local' / ('media-' + db)).replace('\\', '/')
source = re.sub(r"'storage_path' => '[^']*'", "'storage_path' => '" + media_root + "'", source)
config.write_text(source, encoding='utf-8')

check('Installer available under /studio/', 'เริ่มต้นพื้นที่ทำงาน' in get('install').text)
r = post('install','install',{'setup_key':'wrong','email':secret['email'],'display_name':'Local QA','password':secret['password'],'password_confirm':secret['password']})
check('Installer rejects invalid setup key', 'Setup Key ไม่ถูกต้อง' in r.text)
r = post('install','install',{'setup_key':secret['setup_key'],'email':secret['email'],'display_name':'Local QA','password':secret['password'],'password_confirm':secret['password']})
check('Real MySQL-compatible migration and admin creation', 'เข้าสู่ Studio' in r.text and sql('SELECT COUNT(*) FROM admin_users')=='1')
check('Database enforces single admin', sql("INSERT INTO admin_users(email,display_name,password_hash,created_at) VALUES('other@example.test','Other','x',NOW())",False))
check('Unauthenticated dashboard redirects to login', 'เข้าสู่ Studio' in get('dashboard').text)
r=post('login','login',{'email':secret['email'],'password':'incorrect-password'})
check('Wrong password rejected', 'อีเมลหรือรหัสผ่านไม่ถูกต้อง' in r.text)
r=post('login','login',{'email':secret['email'],'password':secret['password']})
check('Login and dashboard succeed', 'พื้นที่ทำงานของคุณ' in r.text)
check('CSP and frame protection present', "frame-ancestors 'none'" in r.headers.get('Content-Security-Policy','') and r.headers.get('X-Frame-Options')=='DENY')
r=client.post(BASE,params={'page':'accounts'},data={'action':'add_account','csrf':'bad'},timeout=30); r.encoding='utf-8'
check('CSRF failure blocks writes', r.status_code==419 and sql('SELECT COUNT(*) FROM social_accounts')=='1')
for p,ext,name in [('facebook_page','123456789012','QA Page A'),('facebook_page','223456789012','QA Page B'),('youtube','UCabcdefghijklmnopqrstuv','QA Channel')]:
    r=post('accounts','add_account',{'platform':p,'external_account_id':ext,'display_name':name})
    check('Register unconnected destination: '+name, 'เพิ่มรายการปลายทางแล้ว' in r.text)
check('Destinations never falsely connected', sql("SELECT COUNT(*) FROM social_accounts WHERE connection_status='CONNECTED'")=='0')
with (ROOT/'.local/landscape.mp4').open('rb') as file:
    r=post('library','upload',files={'video':('landscape.mp4',file,'video/mp4')})
check('Landscape video rejected', 'แนวตั้ง 9:16' in r.text and sql('SELECT COUNT(*) FROM media_assets')=='0')
r=post('library','upload',files={'video':('fake.mp4',b'<?php echo 1; ?>','video/mp4')})
check('Forged MP4 rejected by signature', 'File Signature' in r.text)
with (ROOT/'.local/valid.mp4').open('rb') as file:
    r=post('library','upload',files={'video':('valid.mp4',file,'video/mp4')})
check('Real 31s H264/AAC video validated and stored', 'วิดีโอผ่านการตรวจ' in r.text and sql('SELECT COUNT(*) FROM media_assets')=='1')
with (ROOT/'.local/valid.mp4').open('rb') as file:
    r=post('library','upload',files={'video':('duplicate.mp4',file,'video/mp4')})
check('Duplicate hash reuses the original asset', 'มีในคลังแล้ว' in r.text and sql('SELECT COUNT(*) FROM media_assets')=='1')
media_id=sql('SELECT id FROM media_assets LIMIT 1')
r=client.get(BASE,params={'page':'media','id':media_id},headers={'Range':'bytes=0-31'},timeout=30)
check('Authenticated video byte-range works', r.status_code==206 and len(r.content)==32 and r.headers['Content-Range'].startswith('bytes 0-31/'))
r=client.get(BASE,params={'page':'media','id':media_id},headers={'Range':'bytes=999999999999-'},timeout=30)
check('Unsatisfiable byte-range returns 416',r.status_code==416)
guest=requests.Session();guest.trust_env=False
r=guest.get(BASE,params={'page':'media','id':media_id},timeout=30)
check('Private MP4 not accessible without login', 'เข้าสู่ Studio' in r.text and r.headers.get('Content-Type','').startswith('text/html'))
page_a=sql("SELECT id FROM social_accounts WHERE external_account_id='123456789012'")
page_b=sql("SELECT id FROM social_accounts WHERE external_account_id='223456789012'")
yt=sql("SELECT id FROM social_accounts WHERE platform='youtube'")
form={'id':'','revision':'','media_asset_id':media_id,'title_internal':'QA Content <script>alert(1)</script>','facebook_page_id':page_a,'youtube_channel_id':yt,'manual_method':'SHARE_PAGE','privacy':'private','audience':'not_made_for_kids','scheduled_local':'','youtube_title':'QA Shorts'}
for p in ['facebook_page','youtube','facebook_personal']:
    form['caption_'+p]='QA caption '+p
    form['hashtags_'+p]='#qa'
r=post('edit','save_content',form)
cid=sql('SELECT id FROM content_items LIMIT 1')
check('Content and three platform targets saved',bool(cid) and sql('SELECT COUNT(*) FROM publish_targets')=='3')
check('Stored content title is HTML escaped', '<script>alert(1)</script>' not in r.text and '&lt;script&gt;' in r.text)
check('Database rejects second Facebook target', sql("INSERT INTO publish_targets(content_id,account_id,platform) VALUES("+cid+","+page_b+",'facebook_page')",False))
check('Database rejects wrong account platform', sql("UPDATE publish_targets SET account_id="+yt+" WHERE content_id="+cid+" AND platform='facebook_page'",False))
r=post('edit','save_content',form)
check('One asset cannot create another content/page', 'มีคอนเทนต์แล้ว' in r.text and sql('SELECT COUNT(*) FROM content_items')=='1')
r=post('content','submit_content',{'id':cid,'revision':'1'},params={'id':cid})
check('Submit complete content for approval',sql('SELECT status FROM content_items WHERE id='+cid)=='READY_FOR_REVIEW')
r=post('content','approve_content',{'id':cid,'revision':'99','approval_ack':'1'},params={'id':cid})
check('Stale approval revision rejected',sql('SELECT COUNT(*) FROM content_approvals')=='0')
r=post('content','approve_content',{'id':cid,'revision':'1','approval_ack':'1'},params={'id':cid})
check('Approval snapshot stored with hash',sql('SELECT COUNT(*) FROM content_approvals WHERE LENGTH(snapshot_sha256)=64')=='1')
check('Phase1 auto-jobs blocked instead of fake posting',sql("SELECT COUNT(*) FROM publish_jobs WHERE status='PHASE2_REQUIRED'")=='2' and sql('SELECT COUNT(*) FROM publish_attempts')=='0')
local_time=(datetime.now(timezone.utc)+timedelta(hours=7,minutes=-1)).strftime('%Y-%m-%dT%H:%M')
r=post('content','manual_complete',{'id':cid,'revision':'1','post_url':'https://www.facebook.com/qa/posts/123','posted_local':local_time,'manual_ack':'1'},params={'id':cid})
check('Share mode cannot complete before Page publishes',sql('SELECT status FROM manual_publish_tasks')=='WAITING_PAGE_PUBLICATION')
form.update({'id':cid,'revision':'1','manual_method':'UPLOAD_VIDEO'})
r=post('edit','save_content',form,params={'id':cid})
check('Editing invalidates approval and increments revision',sql('SELECT revision FROM content_items WHERE id='+cid)=='2' and sql('SELECT COUNT(*) FROM publish_jobs')=='0')
r=post('edit','save_content',form,params={'id':cid})
check('Stale editor cannot overwrite a newer revision','แก้ไขคอนเทนต์นี้แล้ว' in r.text and sql('SELECT revision FROM content_items WHERE id='+cid)=='2')
post('content','submit_content',{'id':cid,'revision':'2'},params={'id':cid})
post('content','approve_content',{'id':cid,'revision':'2','approval_ack':'1'},params={'id':cid})
r=post('content','manual_complete',{'id':cid,'revision':'2','post_url':'https://evil.example/123','posted_local':local_time,'manual_ack':'1'},params={'id':cid})
check('Personal URL restricted to Facebook HTTPS',sql('SELECT status FROM manual_publish_tasks')=='WAITING_MANUAL_POST')
r=post('content','manual_complete',{'id':cid,'revision':'2','post_url':'https://www.facebook.com/qa/posts/123','posted_local':local_time,'manual_ack':'1'},params={'id':cid})
check('Manual upload flow records admin confirmation',sql('SELECT status FROM manual_publish_tasks')=='MANUAL_COMPLETED')
r=post('content','manual_complete',{'id':cid,'revision':'2','post_url':'https://www.facebook.com/qa/posts/123','posted_local':local_time,'manual_ack':'1'},params={'id':cid})
check('Repeated manual completion does not duplicate audit',sql("SELECT COUNT(*) FROM audit_logs WHERE action='manual_confirmed'")=='1')
form['revision']='2'
r=post('edit','save_content',form,params={'id':cid})
check('Published personal content cannot be edited',sql('SELECT revision FROM content_items WHERE id='+cid)=='2')
for route in ['dashboard','contents','library','manual','accounts','settings','audit']:
    r=get(route)
    check('Authenticated screen renders: '+route,r.status_code==200 and '<html lang="th">' in r.text)
post('logout','logout')
check('Logout removes access', 'เข้าสู่ Studio' in get('dashboard').text)
report={'runtime':'PHP 8.4.27 / MariaDB 11.4.9 (loopback)','database':db,'tests':passed,'count':len(passed),'api_tests':'Not performed; Phase 1 only'}
(ROOT/'.local/test-results.json').write_text(json.dumps(report,ensure_ascii=False,indent=2),encoding='utf-8')
print('RESULT:',len(passed),'checks passed. No social APIs called.')
