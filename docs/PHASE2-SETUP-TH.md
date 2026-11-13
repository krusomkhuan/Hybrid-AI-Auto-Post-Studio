# Phase 2 — ตั้งค่า OAuth, Publishing และ n8n

วันที่ 14 พฤศจิกายน 2569 • สถานะ: โค้ดพร้อมทดสอบและอัปเดต XAMPP แล้ว แต่ยังไม่ผ่านการทดสอบเผยแพร่ด้วยบัญชีจริง

## สิ่งที่เพิ่ม

- หน้า **การเชื่อมต่อ API** สำหรับ Meta App, Google Web OAuth, Redirect URIs และ HMAC Key ของ n8n
- Connect/Reconnect Facebook และ YouTube ผ่าน OAuth; ตรวจ State/Session/อายุและใช้ Google PKCE
- เก็บ Client Secrets, Access/Refresh Tokens และ Upload Session แบบเข้ารหัสนอก Frontend
- นำเข้ารายการเพจ/ช่องที่ Provider อนุญาตจริง การเพิ่มเพจไม่ต้องเพิ่ม Workflow
- คิวที่ต้องกดจัดอย่างชัดเจนหลังอนุมัติ พร้อมสวิตช์ Automation ที่ปิดเป็นค่าเริ่มต้น
- YouTube Resumable Upload ทีละช่วง 2 MiB, Resume จาก Offset ที่ Provider ยืนยัน และติดตาม Processing/Privacy
- Facebook Reels: Start → URL Fetch จาก Private Media ด้วยลิงก์มีอายุ → Processing → Finish → ตรวจสถานะและ Permalink
- Retry/Quota Backoff, การหยุด UNKNOWN_REMOTE_STATE เมื่อคำตอบการส่งคำสั่งสูญหาย, และการตรวจ Remote ID เดิมเมื่อมี ID ที่ยืนยันแล้ว
- สรุป Partial/Complete Success โดย Complete ต้องรวม Manual Confirmation ของ Facebook ส่วนตัว
- n8n Template ใหม่แบบยังไม่ Active ใช้เพียง Schedule, Crypto, Code และ HTTP Request ส่งเฉพาะ Metadata ไม่มี MP4 หรือ Social Token ผ่าน n8n
- Canonical Origin Redirect เพื่อแก้ปัญหา www/non-www ที่เคยทำให้ CSP บล็อก CSS และ Session/CSRF คนละโดเมน

## ตำแหน่งพัฒนาที่อัปเดตแล้ว

- Public: C:\xampp\htdocs\studio
- Private: C:\xampp\studio-private
- คง Config, var, application.key และ Media เดิม ไม่เปลี่ยนรหัส Admin
- สำรองก่อนอัปเดต: C:\xampp\studio-backups\phase2-25691114-042959 รวม SQL Snapshot และไฟล์ก่อนเปลี่ยน
- URL ในเครื่อง: http://localhost/studio/

Meta App ที่เจ้าของเลือกใหม่: **Hybrid AI Auto Post Studio — 1169432712613004** ไม่ใช้ kruarcheewa/krusomkhuan เดิม

## 1. อัปเดต Hosting ที่มี Phase 1 อยู่แล้ว

1. สำรองฐานข้อมูล, Config, application.key และ Media ผ่าน Control Panel ก่อน
2. ใช้ ZIP **Phase2-Upgrade** แตกในเครื่องก่อน ไม่แตกทั้งชุดใน Public Directory
3. คัดลอกเนื้อหา `studio/` ไปยังโฟลเดอร์เว็บ `krusomkhuan.com/studio/`
4. คัดลอกเฉพาะเนื้อหาที่ให้มาใน `studio-private/` ไปยัง `Home directory/studio-private/` นอก Document Root
5. ห้ามทับ/ลบ config.php, var/application.key หรือ var/media Upgrade ZIP ไม่มีไฟล์เหล่านี้
6. ตรวจ app_url ใน Config ให้ตรงกับโดเมนที่ใช้ เช่น https://krusomkhuan.com/studio โดยไม่ใส่ / ท้าย
7. Login → **การเชื่อมต่อ API** → **อัปเกรดฐานข้อมูล** (Migration เพิ่มตาราง ไม่มี DROP/ล้างข้อมูล)
8. ตั้งค่า Credentials ผ่านหน้าเว็บ ไม่ส่ง Secret ในแชต ไม่ต้องใช้ SSH

หากติดตั้งใหม่ ใช้ ZIP Phase2 แบบเต็ม ทำขั้นตอนติดตั้ง Phase 1 เพื่อสร้าง Admin ก่อน แล้วกดอัปเกรด Phase 2 ในหน้าเชื่อมต่อ

## 2. Meta App / Facebook Page

### ค่าที่ต้องใช้

- App ID: **1169432712613004**
- App Secret: เปิดดูจาก Meta แล้วกรอกในหน้าการเชื่อมต่อ API ของ Studio โดยตรง ช่องจะไม่แสดงค่าที่เก็บแล้วกลับออกมา
- Graph API Version: เลือกเวอร์ชันที่ App รองรับจาก Meta กรอกเป็นรูปแบบ v27.0 (ตัวอย่าง ไม่ใช่การยืนยันเวอร์ชัน App จริง)
- ถ้าแอปใช้ Facebook Login for Business ให้สร้าง/เลือก Configuration ที่ออก User Access Token สำหรับผู้จัดการเพจ และกรอก Config ID ใน Studio; หากใช้ Standard Facebook Login ให้เว้น Config ID

### Redirect URI ของ Hosting ที่เลือก

```text
https://krusomkhuan.com/studio/index.php?page=oauth_callback&provider=meta
```

ให้คัดลอกจากหน้า Studio จริงเป็นหลัก URI ต้องตรงทุกตัวอักษรกับที่ตั้งใน Meta โดยเฉพาะ www, HTTPS, Path และ Query เมื่อ Studio อยู่ localhost URI จะต่างออกไป ห้ามให้ OAuth State สร้างบน localhost แต่ Callback ไปฐานข้อมูล Hosting อีกชุด

ใน Meta ต้องมี Use Case/Product ที่อนุญาต Facebook Login หรือ Facebook Login for Business และสิทธิ์ Pages ที่เกี่ยวข้อง โค้ดขอ `pages_show_list`, `pages_read_engagement`, `pages_manage_posts` ใน Standard Flow หรือใช้ Configuration ของ Business Flow แล้วตรวจสิทธิ์ที่ได้รับกลับมา

สถานะ App Development, App Roles, Business Verification, Advanced Access, App Review และนโยบายส่วนตัว/การลบข้อมูล ต้องตรวจตาม App และ Use Case จริง ไม่รับรองว่าใส่ App ID แล้วเผยแพร่สาธารณะได้ทันที อย่าแก้สิทธิ์/ผลิตภัณฑ์ของแอปเก่าที่ใช้งานอื่น

หาก Meta ต้องการ Privacy Policy/Data Deletion URLs ให้ใช้หน้าที่มีข้อมูลจริงและเจ้าของตรวจแล้ว ไม่ใส่ URL หน้าว่างหรือเอกสารที่ยังไม่มีบนเว็บ

### การเชื่อม

1. บันทึก ID/Secret/Graph Version/Config ID ให้ครบตามประเภท Login
2. ไป **บัญชีและเพจ** → **Connect / Reconnect Facebook**
3. Login และเลือกเพจ/สิทธิ์บน Meta ด้วยบัญชีผู้จัดการเพจ
4. เมื่อ Callback สำเร็จ ระบบนำเข้า Page ID/ชื่อ/Page Token ที่ได้รับจริง และตรวจ Tasks สำหรับการสร้างคอนเทนต์
5. เพจที่ยังไม่มีสิทธิ์จะเป็น AUTH_REQUIRED ไม่แสดงเป็นพร้อมเผยแพร่
6. Reconnect จะทำให้เพจที่ถูกตัดออกจากการอนุญาตไม่คง CONNECTED เดิม การ Disconnect ใน Studio เป็นรายปลายทาง ไม่เพิกถอน OAuth ร่วมของเพจอื่นโดยอัตโนมัติ หากต้องถอนสิทธิ์ทั้งหมด ให้ทำใน Meta ด้วยตนเอง

Facebook Publisher ต้องให้ Meta ดึง Media URL บน HTTPS ได้จริง ลิงก์มีอายุ 2 ชั่วโมงและจำกัดเฉพาะ Job/ไฟล์ที่อนุมัติ ไม่เปิดคลัง Media เป็น Public Directory ไม่รองรับ Facebook Publisher ผ่าน localhost ที่ Meta เข้าถึงไม่ได้

## 3. Google Cloud / YouTube

1. สร้าง Project และเปิด YouTube Data API v3
2. ตั้ง OAuth Consent Screen/ผู้ทดสอบตามสถานะของ Project
3. สร้าง OAuth Client ชนิด **Web application** ไม่ใช่ Desktop Client
4. เพิ่ม Authorized Redirect URI ตามหน้า Studio เช่น:

```text
https://krusomkhuan.com/studio/index.php?page=oauth_callback&provider=google
```

5. กรอก Client ID และ Client Secret ใน Studio แล้วบันทึก
6. ไปบัญชีและเพจ → Connect / Reconnect YouTube และเลือกบัญชี/Brand Channel ที่ต้องการ
7. ระบบขอ Offline Access/Refresh Token และ `youtube.upload` + `youtube.readonly` เพื่ออัปโหลดและตรวจ Channel/Processing ไม่ขอ Analytics/Monetary Scopes ใน Phase นี้
8. Channel ที่ Token อ้างถึงต้องตรงกับปลายทางที่อนุมัติก่อนเริ่ม Upload; ไม่มีการส่งไป Channel อื่นตามค่า Browser

ใช้ videos.insert ตาม Resumable Protocol ไม่มี Shorts Upload Flag แยก การจัดเป็น Shorts เป็นกฎของ YouTube ตามวิดีโอจริง โค้ดตรวจ Processing และ Privacy หลัง Upload หาก Public ที่ขอถูกบังคับเป็น Private ระบบแจ้ง visibility_mismatch ไม่อ้างว่าเผยแพร่ Public สำเร็จ

ตรวจ Project Quota และนโยบายบัญชีใน Google Console จริง โค้ดจัดการ 429/Quota Errors และ Retry-After โดยไม่คาดเดา Remaining Quota ห้ามถือว่าผ่าน OAuth แล้วแปลว่าได้สิทธิ์ทุกประเภทหรือรับรอง Monetization

## 4. เชื่อม n8n เดิม

เจ้าของแจ้ง URL: https://automation.utc.ac.th/home/workflows ยังไม่ได้เข้าระบบ Import/Activate หรือแก้ Workflow ใดบนเซิร์ฟเวอร์นี้ และยังไม่ทราบเวอร์ชันที่ติดตั้ง

Template ปัจจุบันอ้างอิง **Crypto Node V2** จาก Source n8n ทางการ ถ้าเวอร์ชันเดิมไม่มี Node V2 ให้ส่งเวอร์ชันมาเพื่อทำ Template ที่รองรับ ห้ามอัปเกรด/เพิ่ม Package/แก้ Environment ของ n8n เดิมเพื่อให้ Template นี้รัน

### ตั้งค่าโดยใช้หน้าเว็บ

1. บน Studio Hosting ไป **การเชื่อมต่อ API** → **สร้าง HMAC Key สำหรับ n8n** แสดง Key ครั้งเดียว
2. ใน n8n สร้าง **Crypto Credential** ใหม่ชื่อ `Studio HMAC` ใส่ Key ในช่อง Hmac Secret โดยไม่ฝังใน Code/JSON
3. ดาวน์โหลด Workflow JSON จาก Studio Hosting (URL ใน JSON จะเป็น app_url ของชุดที่ดาวน์โหลด)
4. Import เป็น Workflow ใหม่เท่านั้น ชื่อ `Hybrid Studio · Phase 2 · Publishing Scheduler` และยังไม่ Activate
5. ที่ Node **Sign Request** เลือก Crypto Credential ที่สร้างใหม่แทน Placeholder
6. ตรวจ URL ใน Node Prepare Request ว่าชี้ไป Hosting จริง:

```text
https://krusomkhuan.com/studio/integration.php
```

7. ทดลอง Execute ขณะ Studio ปิด Automation ต้องได้ DISABLED และไม่โพสต์อะไร
8. ทดสอบบัญชีและคอนเทนต์ที่อนุญาตก่อน แล้วจึงเปิด Automation/จัดคิว/Activate Workflow ใหม่

Schedule ตั้ง 10 วินาทีเพื่อส่งไฟล์ทีละช่วง Shared Hosting/n8n อาจทำได้ช้ากว่านี้ คิวใช้ DB Lock ป้องกันทำพร้อมกันในโครงการนี้ ไม่เปลี่ยน Global Concurrency ของ n8n

Workflow ไม่อ่าน Social Tokens และไม่ส่ง Binary ผ่าน n8n ประวัติ Execution ถูกตั้งไม่เก็บ Payload ความลับอยู่ใน Credential ส่วน Studio เก็บ Workflow Status/Attempts สำหรับตรวจสอบ

n8n บน Internet เข้า http://localhost/studio ของเครื่องคุณไม่ได้ ต้องทดสอบบน Hosting HTTPS หรือช่องทางที่เจ้าของอนุมัติแยกต่างหาก ไม่มีการเปิด Tunnel/Port Firewall อัตโนมัติ

### HMAC Contract

Headers: X-Studio-Timestamp (Unix Seconds), X-Studio-Nonce (32–64 Hex), X-Studio-Signature (Hex SHA-256)

ข้อความที่ลงลายเซ็นประกอบด้วย Method, Path, Timestamp, Nonce และ SHA-256 ของ Raw JSON Body คั่นด้วย newline ตัวอย่าง Path คือ `/studio/integration.php` ไม่รวมโดเมน ห้ามใส่ Query String ใน Workflow Endpoint

Body มี `event_id` และ operation=`tick` เท่านั้น Timestamp ต้องคลาดไม่เกิน 5 นาที Nonce ใช้ครั้งเดียว Event ID ซ้ำพร้อม Payload เดิมคืนผลเดิมโดยไม่ทำซ้ำ Payload ที่เปลี่ยนโดยใช้ Event ID เดิมถูกปฏิเสธ

## 5. ทดลองเผยแพร่จริงอย่างควบคุม

1. เริ่ม YouTube เป็น Private และใช้คลิปของเจ้าของที่ได้รับอนุญาต
2. ตรวจบัญชีให้เป็น CONNECTED เลือกเพจเดียวและช่องเดียวใน Content
3. ตรวจ Preview/Caption/Privacy/Audience/Synthetic Media แล้วอนุมัติ Revision
4. เปิด Automation ในหน้าการเชื่อมต่อ (ต้องยืนยันว่าอาจเผยแพร่จริง)
5. กลับ Content กด **จัดคิวเผยแพร่คอนเทนต์ที่อนุมัติ** งานเก่าจะไม่เปลี่ยนเป็น READY เอง
6. ดูคิว: READY → RUNNING/UPLOADING → PROCESSING → PUBLISHED เฉพาะ Provider ยืนยันตามเงื่อนไข
7. หากใช้ SHARE_PAGE Manual Task จะเปิดเมื่อ Page PUBLISHED และมี URL ต้นทางแล้ว
8. ผู้ดูแลโพสต์ส่วนตัวเองและยืนยัน URL/เวลา; Complete Success ต้องครบทั้งสอง API และ Manual

YouTube เป็น Private จะแสดง PUBLISHED ในความหมายว่าส่งสำเร็จตาม Privacy ที่อนุมัติ ไม่ใช่คำยืนยันว่าทุกคนดูได้ ให้ตรวจ Privacy ที่แสดงคู่กับรายละเอียดเสมอ

## 6. ข้อผิดพลาดและขอบเขตที่ต้องเข้าใจ

- AUTH_REQUIRED: Reconnect/ตรวจสิทธิ์หรือ Privacy แล้ว Retry ที่ Job เดิม ไม่สร้าง Content ใหม่จากวิดีโอเดิม
- RETRY_WAIT: รอ Backoff/Quota Reset หรือสั่ง Retry เมื่อแก้ต้นเหตุแล้ว
- UNKNOWN_REMOTE_STATE: คำตอบหลังคำสั่งที่อาจสร้างโพสต์สูญหาย ระบบหยุดแทน Blind Retry ถ้ามี Remote ID กดตรวจสถานะ ID เดิมได้ หากยังไม่มี ID ต้องตรวจบนแพลตฟอร์มก่อน ไม่มีปุ่มเริ่ม Upload ใหม่อัตโนมัติ
- Cancel ใช้กับงานที่ยังไม่เริ่มส่ง ไม่ลบโพสต์ที่เผยแพร่แล้ว การ Disconnect/ปิด Automation ไม่ย้อนคำขอที่อยู่ระหว่างส่ง
- Processing เกิน 24 ชั่วโมงหยุดให้ตรวจ Provider ไม่ Poll ไม่สิ้นสุดโดยไม่แจ้ง
- Shared Hosting อาจจำกัดเวลา/Outbound/ขนาดไฟล์/Range/จำนวน Requests ต้องทดสอบจริง ไม่รับรองว่าจะผ่านเพียงเพราะหน้าจอเปิดได้
- ไม่รับรอง Exactly Once ข้าม Provider ที่ไม่มี Idempotency Key รองรับ ใช้ Durable Job/Session/Remote ID และหยุดกรณีกำกวมเพื่อลดความเสี่ยงโพสต์ซ้ำ
- ยังไม่ทดสอบจริงว่า Graph Version/Use Case/สิทธิ์ของ App ใหม่นี้รองรับทุกขั้นตอน การตรวจ Transport จำลองไม่แทนการทดสอบด้วยบัญชีจริง

## 7. หลักฐานการตรวจ

- Core DB/State tests 44 จุด ด้วยฐานข้อมูลแยกและ Provider จำลอง (0 Live Social API Calls)
- HTTP tests 27 จุด ด้วย PHP Server/DB แยก ทดสอบติดตั้ง อัปเกรด Secret, OAuth Start/Callback ที่ถูกปฏิเสธ, HMAC/Replay, Endpoint Methods และหน้าจอหลัก
- ตรวจ Workflow Signing Expression ด้วย Node; Template JSON ยังไม่ถูก Import/Execute บน n8n จริง
- อัปเดต XAMPP พร้อม Migration แล้ว Automation ยังปิด ไม่มีการตั้ง Secret จริงหรือเชื่อม Social Account แทนเจ้าของ
- ตรวจ TLS ขาออกจาก PHP XAMPP ด้วยเอกสาร Discovery สาธารณะของ Google ได้ HTTP 200 และ cURL Error 0 ไม่มีการปิดตรวจ Certificate

ผลรายละเอียดอยู่ใน phase2-test-results.json และ phase2-http-test-results.json ไม่อ้างว่า Phase 2 ผ่าน UAT จริงจนกว่าจะเชื่อมและทดสอบครบทั้งสองแพลตฟอร์มกับ n8n ของเจ้าของ

## แหล่งอ้างอิง

- Google OAuth Web Server: https://developers.google.com/identity/protocols/oauth2/web-server
- YouTube Resumable Upload: https://developers.google.com/youtube/data/guides/using_resumable_upload_protocol
- YouTube videos.insert: https://developers.google.com/youtube/data/reference/rest/v3/videos/insert
- Meta Reels Publishing: https://developers.facebook.com/docs/video-api/guides/reels-publishing/ (เปิดตรวจตรงติด 429 ในรอบค้นคว้านี้)
- Meta Page Video Reels Reference: https://developers.facebook.com/docs/graph-api/reference/page/video_reels/
- Meta Official Postman Collection: https://www.postman.com/meta/facebook/documentation/r56bjfd/facebook-api
- n8n Crypto Source/Docs: https://github.com/n8n-io/n8n/tree/master/packages/nodes-base/nodes/Crypto และ https://docs.n8n.io/integrations/builtin/core-nodes/n8n-nodes-base.crypto/
