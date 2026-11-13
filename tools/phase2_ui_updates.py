from pathlib import Path

root=Path(__file__).resolve().parents[1]

def replace(path,old,new):
    p=root/path;s=p.read_text(encoding='utf-8')
    if old not in s:
        raise RuntimeError('Expected source missing: '+path)
    p.write_text(s.replace(old,new),encoding='utf-8')

replace('studio-private/views/accounts.php','use Studio\\Security;','use Studio\\{Security,IntegrationSettings};')
replace('studio-private/views/accounts.php','<div class="notice mb-4">Phase 1 เพิ่มรายการเพื่อเตรียมคอนเทนต์เท่านั้น ยังไม่ใช่การ Connect ผ่าน OAuth และยังตรวจสิทธิ์เผยแพร่ไม่ได้ การเชื่อมต่อจริงจะทำใน Phase 2</div>',
'''<div class="notice mb-4">รายการที่เพิ่มด้วย ID ยังไม่ถือว่าได้รับสิทธิ์ ต้องเชื่อมผ่าน OAuth ก่อนจัดคิวอัตโนมัติ</div>
<section class="panel mb-4"><h2>เชื่อมต่อด้วยบัญชีเจ้าของเพจ/ช่อง</h2><div class="d-flex flex-wrap gap-2"><?php if(IntegrationSettings::migrated()): foreach(['meta'=>'Connect / Reconnect Facebook','google'=>'Connect / Reconnect YouTube'] as $provider=>$label): ?><form method="post" action="<?= e(url('accounts')) ?>"><?= Security::field() ?><input type="hidden" name="action" value="oauth_start"><input type="hidden" name="provider" value="<?= e($provider) ?>"><button class="btn btn-primary" type="submit"><?= e($label) ?></button></form><?php endforeach; endif; ?><a class="btn btn-outline-primary" href="<?= e(url('integrations')) ?>">ตั้งค่า App / Client / n8n</a></div><p class="small text-secondary mt-3">เลือกเพจที่อนุญาตใน Facebook OAuth ระบบนำเข้ารายการที่ได้รับสิทธิ์จริง การเพิ่มเพจไม่ต้องสร้าง Workflow ใหม่</p></section>''')
replace('studio-private/views/accounts.php',"<?php if($a['platform']!=='facebook_personal'): ?>",'''<?php if($a['platform']!=='facebook_personal'): ?><?php if(IntegrationSettings::migrated() && $a['connection_status']==='CONNECTED'): ?><form method="post" action="<?= e(url('accounts')) ?>" class="mb-2"><?= Security::field() ?><input type="hidden" name="action" value="disconnect_account"><input type="hidden" name="account_id" value="<?= e($a['id']) ?>"><button class="btn btn-sm btn-light" type="submit">Disconnect ใน Studio</button></form><?php endif; ?>''')
replace('studio-private/views/layout.php','use Studio\\{Auth,Security};','use Studio\\{Auth,Security,IntegrationSettings};')
replace('studio-private/views/layout.php',"'accounts'=>['บัญชีและเพจ','05']", "'accounts'=>['บัญชีและเพจ','05'],'queue'=>['คิวเผยแพร่','06'],'integrations'=>['การเชื่อมต่อ API','07']")
replace('studio-private/views/layout.php','PHASE 1 · LOCAL STORAGE','PHASE 2 · HYBRID PUBLISHING')
replace('studio-private/views/layout.php','API Publishing เปิดใน Phase 2','OAuth · n8n · แยกสถานะแต่ละปลายทาง')
replace('studio-private/views/layout.php','เวลาแสดงผล Asia/Bangkok · Phase 1','เวลาแสดงผล Asia/Bangkok · Phase 2')
replace('studio-private/views/content.php','<?php use function Studio\\{e,url,status_label,platform_label,thai_date}; use Studio\\Security;','<?php use function Studio\\{e,url,status_label,platform_label,thai_date}; use Studio\\{Security,IntegrationSettings};')
replace('studio-private/views/content.php','<section class="panel mt-4"><div class="section-heading"><h2>ข้อความที่จะใช้เผยแพร่</h2>',
'''<?php if($c['status']==='APPROVED' && IntegrationSettings::migrated()): ?><section class="panel mt-4"><h2>เผยแพร่ผ่าน Official API</h2><p>กดจัดคิวเฉพาะเมื่อเชื่อมบัญชีและตรวจปลายทางแล้ว งานเก่าจะไม่เผยแพร่เอง</p><form method="post" action="<?= e(url('content',['id'=>$c['id']])) ?>"><?= Security::field() ?><input type="hidden" name="action" value="queue_content"><input type="hidden" name="id" value="<?= e($c['id']) ?>"><button class="btn btn-primary" type="submit">จัดคิวเผยแพร่คอนเทนต์ที่อนุมัติ</button> <a class="btn btn-light" href="<?= e(url('queue')) ?>">ดูคิวและสถานะ</a></form></section><?php endif; ?>
<section class="panel mt-4"><div class="section-heading"><h2>ข้อความที่จะใช้เผยแพร่</h2>''')
for path in ['studio-private/views/content.php','studio-private/views/edit.php','studio-private/views/dashboard.php','studio-private/views/accounts.php','studio-private/app/controller.php']:
    p=root/path;s=p.read_text(encoding='utf-8')
    s=s.replace('Phase 1 ยังไม่ส่งวิดีโอไปเพจหรือ YouTube ผ่าน API และไม่ดึงยอดวิว/รายได้','ระบบเผยแพร่ผ่าน API เฉพาะคิวที่อนุมัติและบัญชีที่เชื่อมแล้ว สถิติ/รายได้ยังไม่เปิดใน Phase 2')
    s=s.replace('งานอัตโนมัติรอ Phase 2','งานอัตโนมัติรอการจัดคิวและเชื่อมต่อบัญชี')
    s=s.replace('Phase 1 บันทึกปลายทางและอนุมัติได้ แต่ยังไม่ส่งโพสต์อัตโนมัติ','บันทึกและอนุมัติแล้วต้องจัดคิวก่อนเผยแพร่ผ่านบัญชีที่เชื่อมต่อ')
    s=s.replace('ระบบยังไม่ตั้งเวลาโพสต์จริงใน Phase 1','การเผยแพร่ตามเวลาต้องเปิด Automation และให้ n8n เข้าถึง Studio ได้')
    s=s.replace('API รอ Phase 2','ต้องเชื่อมต่อ API')
    s=s.replace('ยังไม่เชื่อม API</option>',"<?= $a['connection_status']==='CONNECTED'?'เชื่อมต่อแล้ว':'ยังไม่เชื่อม API' ?></option>")
    s=s.replace('Facebook Page / YouTube API รอ Phase 2','Facebook Page / YouTube ผ่านบัญชีที่เชื่อม OAuth และคิวที่อนุมัติ')
    s=s.replace('<span class="phase-tag">PHASE 1</span>','<span class="phase-tag">PHASE 2</span>')
    s=s.replace('ยังไม่เปิดใน Phase 1','ยังไม่เปิดใน Phase 2')
    p.write_text(s,encoding='utf-8')

replace('studio-private/app/helpers.php',"'APPROVED' => 'อนุมัติแล้ว · รอ Phase 2'","'APPROVED' => 'อนุมัติแล้ว'")
replace('studio-private/app/helpers.php',"'PHASE2_REQUIRED' => 'รอเชื่อม API ใน Phase 2'","'PHASE2_REQUIRED' => 'รอจัดคิว API', 'CONNECTED'=>'เชื่อมต่อแล้ว', 'DISCONNECTED'=>'ตัดการเชื่อมต่อแล้ว', 'READY'=>'รอประมวลผล', 'QUEUED'=>'อยู่ในคิว', 'RUNNING'=>'กำลังทำงาน', 'UPLOADING'=>'กำลังอัปโหลด', 'PROCESSING'=>'แพลตฟอร์มกำลังประมวลผล', 'AUTH_REQUIRED'=>'ต้องเชื่อมบัญชี/ตรวจสิทธิ์', 'RETRY_WAIT'=>'รอลองใหม่', 'CANCELLED'=>'ยกเลิกแล้ว', 'UNKNOWN_REMOTE_STATE'=>'ต้องตรวจผลบนแพลตฟอร์ม'")
print('Phase 2 UI updated')
