# ทดลอง Phase 1 ที่ localhost/studio

เตรียมสภาพแวดล้อมในเครื่องวันที่ 14 พฤศจิกายน 2569 ยังไม่ได้อัปโหลดหรือแก้ข้อมูลบน Hosting

## ตำแหน่งที่ใช้จริง

- หน้าเว็บ: http://localhost/studio/
- Public Files: C:\xampp\htdocs\studio
- Application / Config / Media: C:\xampp\studio-private (อยู่นอก htdocs)
- Database ใหม่เฉพาะแอป: studio_local_phase1
- PHP ของ XAMPP: 8.5.12; MariaDB: 10.11.16
- Config ในเครื่องเป็น environment=local และ app_url=http://localhost/studio ไม่ใช้รหัส DB ของ Hosting

โฟลเดอร์ studio เดิมที่แตก ZIP ซ้อนอยู่ถูกสำรองไว้ที่ C:\xampp\studio-backups\25691114-033658\studio-original ไม่ได้ลบทิ้ง ส่วน Source/Config เดิมในโครงการยังคงอยู่

## สร้างผู้ดูแลด้วยตัวเอง

1. เปิด http://localhost/studio/ แล้ว Refresh ด้วย Ctrl+F5 ถ้ายังเห็นหน้าตรวจ Extensions เก่า
2. เปิดไฟล์ C:\xampp\studio-private\LOCAL-SETUP-KEY.txt ในเครื่อง เพื่อคัดลอก Setup Key ห้ามอัปโหลด/แชร์ไฟล์นี้
3. ใส่ Setup Key และสร้างชื่อผู้ดูแล อีเมล รหัสผ่าน 12–72 ไบต์ และยืนยันรหัสผ่านในหน้าเว็บ
4. ติดตั้งสำเร็จแล้วเข้าสู่ระบบด้วยบัญชีที่สร้าง จากนั้นล้าง setup_key ใน Config และเก็บ/ลบไฟล์ Setup Key ตามต้องการ
5. ทดลองตาม USER-MANUAL-PHASE1-TH.md โดยเลือก Manual แบบอัปโหลดเป็นโพสต์ใหม่

ยังไม่มีบัญชี Admin ที่ผู้ช่วยสร้างให้ เพื่อให้เจ้าของกำหนดรหัสผ่านเอง ตัวติดตั้งจึงยังเปิดรอขั้นตอนนี้

## การปรับ XAMPP ที่ดำเนินการ

เปิด Sodium ใน php.ini และเพิ่ม LoadFile ของ DLL จาก PHP เดิมให้ Apache โหลด Sodium/cURL ได้ สำรองไฟล์ก่อนแก้ไว้ใน C:\xampp\studio-backups ส่วน httpd.conf หลักไม่ได้เปลี่ยน

Apache ที่เปิดทดสอบใช้ C:\xampp\studio-private\local-httpd.conf ซึ่งเป็นสำเนาที่รับ HTTP เฉพาะ 127.0.0.1:80 ไม่เปิด SSL/AJP ในสำเนานี้ โหมด Local ใช้ HTTP และ Cookie ที่เหมาะกับ HTTP; Production ยังคงต้องใช้ HTTPS

หลังรีบูตใช้ XAMPP Control Panel Start Apache และ MySQL ได้ตามปกติ โดย Config ของ Studio ไม่ต้องเปลี่ยน ทั้งนี้ Control Panel จะเริ่มด้วย httpd.conf หลักของ XAMPP ไม่ใช่สำเนาที่ผูกเฉพาะ loopback

PHP ปัจจุบันตั้ง upload_max_filesize=40M และ post_max_size=40M ต้องใช้ไฟล์ต่ำกว่าเพดานนี้รวมข้อมูลฟอร์ม หากต้องเพิ่มสำหรับคลิปใหญ่ ให้ปรับอย่างเจาะจงหลังตรวจค่าของ XAMPP แล้ว ไม่ถือว่าเพดาน 200 MiB ของแอปทำให้ PHP รับได้ 200 MiB อัตโนมัติ

## ผลตรวจครั้งนี้

- GET http://localhost/studio/ คืน HTTP 200 และแสดงหน้าติดตั้งสร้างผู้ดูแล
- การตรวจ PHP/Extensions/var/getID3 ผ่านครบแล้ว
- URL สำหรับ Config ส่วนตัวเปิดผ่านเว็บไม่ได้ (HTTP 403)
- DB User ของแอปเชื่อมต่อฐานข้อมูลใหม่ได้ โดยยังไม่มีการสร้าง Admin หรือโพสต์ Social

## เมื่อนำขึ้นโฮสติ้งภายหลัง

ใช้ชุดติดตั้งและคู่มือ INSTALL-PHASE1-TH.md สร้าง Production Config ใหม่เป็น HTTPS พร้อม Credentials ของ Hosting ห้ามคัดลอก config.php หรือ LOCAL-SETUP-KEY.txt ของ localhost ขึ้นเว็บจริง

หากจะย้ายข้อมูลทดลองด้วย ต้อง Export DB พร้อม Media และ application.key ที่ติดตั้งแล้วเป็นชุดเดียวกัน ห้าม Restore ทับฐานข้อมูลเว็บอื่น
