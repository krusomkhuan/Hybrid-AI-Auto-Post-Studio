# รายงานตรวจสอบ Phase 1

วันที่ 13 พฤศจิกายน 2569 • ทดสอบในเครื่องพัฒนาเท่านั้น

## สภาพแวดล้อม

- PHP 8.4.27 แบบ Portable พร้อม PDO MySQL, cURL, Fileinfo, mbstring, OpenSSL, Sodium
- MariaDB 11.4.9 เปิดเฉพาะ 127.0.0.1:33079 ใช้ฐานข้อมูลทดสอบใหม่แยกจากทุกเว็บจริง
- Local HTTP ที่ /studio/ บน 127.0.0.1:8097 ไม่มีการเปิด HTTPS ในเครื่องทดสอบ; Production บังคับ HTTPS
- Bootstrap 5.3.9 และ getID3 1.9.28 รวมไฟล์ในชุดติดตั้ง
- ใช้คลิปสังเคราะห์สำหรับ QA ที่สร้างด้วย FFmpeg ในเครื่องพัฒนา ไม่ติดตั้ง/เรียก FFmpeg บนโฮสติ้ง

## ผล HTTP/DB Integration

ชุดทดสอบ `tests/phase1_http.py` ผ่าน 44 จุดในรอบที่บันทึกผล ตรวจด้วย HTTP จริงและ MariaDB Constraints จริง ไม่ใช่ Mock Social API

ครอบคลุม: Setup Key, Migration, Single Admin Constraint, Login/Logout, รหัสผ่านผิด, CSRF, Security Headers, เพิ่ม Page/Channel ที่ยังไม่เชื่อมต่อ, ตรวจไฟล์ MP4/ปฏิเสธไฟล์ปลอม/แนวนอน, Audio AAC, Hash Deduplication, Private Media/Byte Range/416, Escape XSS, หนึ่งวิดีโอหนึ่งคอนเทนต์, หนึ่งเพจต่อ Content, Composite FK ตรงประเภทบัญชี, ส่งตรวจ, Stale Revision, Approval Snapshot, Phase2 Blocked Jobs, Share Waiting, แก้ไขต้องอนุมัติใหม่, URL Allowlist ของ Manual, ยืนยัน Manual แบบไม่ซ้ำ และห้ามแก้คอนเทนต์ที่เผยแพร่แล้ว

หน้าภาพรวม คอนเทนต์ คลังวิดีโอ Manual บัญชี Settings และ Audit คืน HTTP 200 หลัง Login; PHP Source ของแอปผ่าน Syntax Check และ JavaScript ผ่าน node --check

## Browser QA

ตรวจ Login และ Dashboard ผ่าน In-app Browser ตรวจการแสดงผล Desktop และ Mobile โดยใช้ข้อมูลทดสอบแยกจากข้อมูลจริง ภาพหน้าจอใน docs/screenshots มีเฉพาะข้อมูล QA ไม่ใช่ยอดใช้งานจริงของเจ้าของ

## การป้องกันที่มี

Password Hash, Single Admin, Session Regeneration/Idle Timeout, Cookie Secure บน Production, HttpOnly/SameSite, CSRF, Login Rate Limit ใน DB, Prepared Statements, HTML Escaping, CSP, Private Storage นอก Document Root, MP4 Signature/Metadata Validation, Upload Budget Lock, File Hash และ Audit ของการเปลี่ยนข้อมูลสำคัญ

Encryption Key ถูกสร้างระหว่างติดตั้ง เก็บนอก Public Root มี Sodium authenticated encryption helper สำหรับเก็บ Credentials ใน Phase 2 ยังไม่มี OAuth Token ของจริงใน Phase 1

## สิ่งที่ยังไม่รับรอง

- ยังไม่ได้ติดตั้ง/ทดสอบบน krusomkhuan.com หรือแพ็กเกจจริง จึงยังไม่รับรอง PHP/DB/Extensions, open_basedir, WAF, Upload Limit, SSL, Permissions, ขนาดไฟล์สูงสุด หรือ Bandwidth ของ Hosting
- ไม่ได้เรียก Meta/YouTube/AI API ไม่ได้ขอ OAuth และไม่มีผลทดสอบการเผยแพร่หรือสร้างรายได้จริง
- ไม่ได้ทดสอบ n8n และไม่มี Workflow ถูกสร้าง/แก้ไขในรอบนี้
- Metadata Validation ไม่ใช่ Malware Scan/การ Decode ทุก Frame/การตรวจลิขสิทธิ์ ต้องทดสอบคลิปจริงและให้ผู้ดูแล Preview
- ยังไม่ทดสอบโหลดระดับ 200 MiB หรือ Backup/Restore บนโฮสติ้งจริง ต้องทำก่อนใช้งานจริงต่อเนื่อง
- รายงานนี้เป็นการทดสอบฟังก์ชันและขอบเขตความปลอดภัยที่ระบุ ไม่ใช่การรับรองว่าไม่มีช่องโหว่ทุกชนิด

ก่อนใช้งานจริงให้ทำรายการตรวจรับใน INSTALL-PHASE1-TH.md ด้วยฐานข้อมูลและคลิปที่เจ้าของอนุญาต

