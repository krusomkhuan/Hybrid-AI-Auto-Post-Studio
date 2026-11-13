# Hybrid AI Auto Post Studio — Phase 2

ระบบ PHP/MySQL ภาษาไทยสำหรับเตรียมและอนุมัติวิดีโอ เชื่อม Facebook Page/YouTube ผ่าน OAuth และให้ n8n เดิมเรียกคิวเผยแพร่ Facebook ส่วนตัวทำด้วยตนเอง รองรับ `/studio/` บน Shared Hosting โดยไม่ต้องมี SSH/Composer/Docker

## เริ่ม Phase 2

**สถานะ RC:** อ่าน [สถานะรอบล่าสุด](docs/PHASE2-STATUS-TH.md) ก่อนนำขึ้น Hosting การทดสอบ Integration และซิงก์แพตช์รอบสุดท้ายยังรอระบบตรวจอนุมัติพร้อม ผล 71 Checks เป็นของรอบก่อนแพตช์ล่าสุด ไม่ใช่ Live API Tests

อ่าน [คู่มือตั้งค่า Phase 2](docs/PHASE2-SETUP-TH.md) ก่อนเปิด Automation ใช้ ZIP **Phase2-Upgrade** สำหรับ Hosting ที่ติดตั้ง Phase 1 แล้ว โดยรักษา Config/Media/Key เดิม ส่วน ZIP Phase2 แบบเต็มใช้ติดตั้งใหม่

XAMPP ที่อัปเดตแล้ว: `C:\xampp\htdocs\studio` และ `C:\xampp\studio-private` เปิด http://localhost/studio/ เมนู **การเชื่อมต่อ API** และ **คิวเผยแพร่** พร้อมใช้งาน Automation เริ่มต้นปิด และยังไม่ได้เชื่อม Social Account ของเจ้าของจริง

App ที่เจ้าของเลือก: Hybrid AI Auto Post Studio — 1169432712613004 ส่วน App Secret/Google Credentials ให้กรอกในหน้า Studio โดยตรง ไม่ส่งผ่านแชต

## เริ่มทดลองบนโฮสติ้ง

1. เปิด [คู่มือติดตั้ง](docs/INSTALL-PHASE1-TH.md) ก่อนอัปโหลดไฟล์
2. ใช้ ZIP ใน `dist/` ซึ่งแยก `studio/` สำหรับเว็บและ `studio-private/` ที่ต้องวางนอก Document Root
3. สร้างฐานข้อมูลใหม่ ตั้งค่า `config.php` และเปิด `/studio/` เพื่อสร้างผู้ดูแลคนเดียว
4. ทำตาม [คู่มือทดลองใช้งาน](docs/USER-MANUAL-PHASE1-TH.md)

## ขอบเขตเดิมจาก Phase 1

มี Login/Logout, CSRF, Login Rate Limit, Media Library, ตรวจ MP4 ด้วย getID3, Private Video Preview/Download, ตรวจไฟล์ซ้ำ, Content/Caption สามช่องทาง, Single Page/Channel Selection, Revision/Approval Snapshot, Manual Upload/Share และ Audit Logs

Phase 2 เพิ่ม OAuth, Durable Publishing Jobs, Resumable Upload และ Signed n8n Endpoint งานเดิมยังไม่ถูกจัดคิวเอง รายการที่กรอก ID เองยังเป็น NOT_CONNECTED จนกว่าจะอนุญาตผ่าน OAuth จริง ไม่เรียก AI Provider หรือดึง Analytics/Revenue ใน Phase นี้ และไม่แก้การติดตั้ง/Workflow n8n เดิม

วงเงินคลังเริ่มต้น 30 GiB เป็นค่าของแอป ปรับใน Config ได้ ไม่ใช่การอ่าน Quota จริงของโฮสติ้ง ไม่มีการลบวิดีโออัตโนมัติ มี Storage Adapter สำหรับขยายไป NAS/Drive ภายหลัง แต่ยังไม่เชื่อมต่อใน Phase 1

## การพัฒนาและทดสอบ

Phase 1 ทดสอบด้วย PHP 8.4.27/MariaDB 11.4.9 ตาม [รายงานเดิม](docs/TEST-REPORT-PHASE1-TH.md) Phase 2 ทดสอบด้วย PHP 8.5.12/MariaDB 10.11.16 บน Windows: `tests/phase2_core.php` 44 จุด (Provider จำลอง) และ `tests/phase2_http.py` 27 จุด (HTTP จริงกับ DB แยก) รวม 71 จุด ไม่ใช่การรับรองผล Live Social API

`tools/` ใช้บนเครื่องพัฒนาเท่านั้น ไม่ต้องอัปโหลดขึ้นโฮสติ้ง การสร้าง ZIP ใช้ Python `tools/package_release.py` โดยไม่รวม Local Config, Encryption Key, Test Database, Test Video หรือ Runtime สำหรับ Windows

ไลบรารี: Bootstrap 5.3.9 (MIT) และ getID3 1.9.28 (เลือกใช้ MPL 2.0) แนบ License และ Source ที่เกี่ยวข้องในชุดติดตั้ง ไม่มีการพึ่ง CDN ระหว่างใช้งาน

