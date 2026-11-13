# คู่มือติดตั้ง Phase 1 ผ่าน File Manager

ปลายทางที่เลือก: **https://www.krusomkhuan.com/studio/**

ยังไม่มีการติดตั้งบนโฮสติ้งจริง ชุดติดตั้งนี้ไม่ต้องใช้ SSH, Composer, Node, Docker หรือการเปลี่ยน n8n

## 1. ตรวจความพร้อม

- PHP 8.4.27 ขึ้นไป เลือกผ่าน Hosting Control Panel
- MySQL 8.0.16 ขึ้นไป หรือ MariaDB 10.6 ขึ้นไป ระบบตรวจเวอร์ชันเพื่อให้ CHECK/Foreign Key ทำงานตามแบบ
- Extensions: PDO MySQL, cURL, Fileinfo, mbstring, OpenSSL, Sodium และ JSON
- HTTPS ที่ใช้งานได้สำหรับ www.krusomkhuan.com
- สร้างฐานข้อมูลใหม่และผู้ใช้ DB เฉพาะแอป มีสิทธิ์สร้างตาราง/Index/Foreign Key ระหว่างติดตั้ง และ SELECT/INSERT/UPDATE/DELETE ระหว่างใช้งาน
- ต้องเก็บโฟลเดอร์ส่วนตัวนอก Document Root และ PHP ต้องเข้าถึงได้ภายใต้ open_basedir ของแพ็กเกจ
- พื้นที่บัญชี 50 GB ใช้เว็บอื่นประมาณ 10 GB ตามข้อมูลเจ้าของ; แอปตั้งวงเงินเริ่มต้น 30 GiB เพื่อเผื่อพื้นที่เว็บอื่น/ไฟล์ชั่วคราว ไม่ตรวจ Quota 50 GB จาก Control Panel ให้อัตโนมัติ

หาก PHP/DB/Extensions ไม่รองรับ ให้ตรวจใน Control Panel หรือให้ผู้ให้บริการช่วยเปิดก่อน อย่าลดเวอร์ชัน/ย้ายไฟล์ส่วนตัวเข้าเว็บเพื่อฝืนให้เปิดได้

## 2. แตก ZIP ในเครื่องก่อน

ภายใน ZIP มี:

```text
studio/                       เฉพาะไฟล์เปิดผ่านเว็บ
  index.php
  .htaccess
  assets/
studio-private/               ส่วนตัว ต้องอยู่นอก Document Root
  app/
  database/001_phase1.sql
  views/
  vendor/
  var/
  config.example.php
docs/                         คู่มือ ไม่จำเป็นต้องอัปโหลดเป็นหน้าเว็บ
README.md
```

ZIP ไม่รวมบัญชีทดสอบ รหัสผ่านเริ่มต้น หรือ Config จริง ผู้ติดตั้งสร้างรหัสผ่านและ Setup Key เอง

## 3. วางสองโฟลเดอร์ให้ถูกตำแหน่ง

จาก File Manager ที่ส่งมา มีโฟลเดอร์ `krusomkhuan.com` และสร้าง `studio` ไว้แล้ว หาก `krusomkhuan.com` เป็น Document Root จริง ให้จัดดังนี้:

```text
Home directory/
├── krusomkhuan.com/                  Document Root ของเว็บไซต์
│   ├── index.php                     ของเว็บเดิม ห้ามทับ
│   ├── docshub/ ...                  ของเว็บเดิม ห้ามแก้
│   └── studio/                       โฟลเดอร์ที่สร้างแล้ว
│       ├── index.php                 จาก studio/ ใน ZIP
│       ├── .htaccess
│       └── assets/
└── studio-private/                   อยู่ระดับเดียวกับ krusomkhuan.com
    ├── config.php
    ├── app/
    ├── database/
    ├── vendor/
    ├── views/
    └── var/
```

**สำคัญ:** ไม่วาง `studio-private` ไว้ภายใน `krusomkhuan.com` และไม่วาง Config ใน `studio` การซ่อนชื่อหรืออาศัย .htaccess อย่างเดียวไม่ใช่รูปแบบติดตั้งที่กำหนด

คัดลอกเฉพาะเนื้อหาของ `studio/` เข้าโฟลเดอร์ที่สร้างแล้ว อย่าให้กลายเป็น `studio/studio/index.php` ส่วน `studio-private/` อัปโหลดแยกไปที่ Home directory ห้ามแตก ZIP ทั้งชุดไว้ในโฟลเดอร์เว็บไซต์

Entry Point ใช้ตำแหน่งส่วนตัวเริ่มต้นเป็นสองระดับเหนือโฟลเดอร์ `studio` แล้วตามด้วย `/studio-private` ซึ่งตรงกับโครงสร้างข้างต้น หากโฮสติ้งมี Document Root ต่างจากนี้ ต้องตรวจตำแหน่งก่อนปรับตัวชี้ใน `studio/index.php` ห้ามคาดเดา Path

หาก File Manager หรือ open_basedir ไม่อนุญาตส่วนตัวนอก Document Root ให้หยุดที่ขั้นนี้และแจ้งเฉพาะข้อจำกัด/โครงสร้างโฟลเดอร์ ไม่ส่งรหัสผ่านให้ผู้ช่วย

## 4. สร้างฐานข้อมูลใหม่

ใช้เมนู Databases/MySQL ของ Control Panel สร้าง DB และ DB User ใหม่เฉพาะ Studio อย่าใช้ DB ของ WordPress/เว็บเดิม ชื่อจริงอาจมี Prefix จาก Hosting เช่น account_studio

ตัวติดตั้งจะสร้างตารางให้อัตโนมัติเมื่อยืนยัน Setup Key หรือจะนำเข้า `studio-private/database/001_phase1.sql` ผ่าน phpMyAdmin ก่อนก็ได้ แล้วกลับมาสร้าง Admin ผ่านหน้าเว็บตามเดิม

ไม่แก้/ลบตารางเว็บอื่น Migration ใช้ชื่อเฉพาะแอปในฐานข้อมูลใหม่ และสร้างผู้ดูแลได้คนเดียว

## 5. ตั้งค่า Config ผ่าน File Manager

คัดลอก `studio-private/config.example.php` เป็น `studio-private/config.php` แล้วแก้:

- environment = production
- app_url = https://www.krusomkhuan.com/studio (ไม่มี / ท้าย)
- db.host, db.port, db.name, db.user, db.password ตาม Control Panel
- setup_key = Secret ที่สุ่มใหม่อย่างน้อย 32 ตัวอักษร แนะนำ 48 ตัวอักษร เก็บส่วนตัวและอย่านำมาโพสต์ในแชต
- storage_path ใช้ค่าเดิม `__DIR__ . '/var/media'` ถ้าโครงสร้างถูกต้อง
- storage_budget_bytes เริ่มต้น 30 × 1024 × 1024 × 1024 ไบต์ ปรับตามพื้นที่ที่ยอมจัดให้แอปได้
- max_upload_bytes เริ่มต้น 200 MiB ระบบจะยังติดเพดาน PHP/WAF ที่ต่ำกว่า

อย่าใช้ค่า CHANGE_ ที่ให้มาเป็น Setup Key จริง และอย่าเปิด Local Mode บนเว็บจริง

Permissions ที่เหมาะสมขึ้นกับ PHP Handler ของโฮสติ้ง ใช้สิทธิ์ต่ำสุดที่ PHP อ่าน Config และเขียน var ได้ เช่นไฟล์ส่วนตัว 600/640 และโฟลเดอร์ 700/750 เมื่อ Handler รองรับ ไม่ตั้ง 777

## 6. ตั้งค่ารับ MP4

ผ่านเมนู PHP Settings ของ Control Panel ตรวจ upload_max_filesize, post_max_size, memory_limit, max_execution_time และ max_input_time ให้พอกับไฟล์จริง ตัวอย่างสำหรับเพดานแอป 200 MiB อาจใช้ upload_max_filesize=200M, post_max_size=205M, memory_limit=256M แต่ไม่ถือว่าแพ็กเกจอนุญาตค่าเหล่านี้แล้ว

Phase 1 ใช้ Multipart Upload ปกติ ยังไม่ใช่ Browser Chunk Upload หากโฮสติ้ง/WAF จำกัดต่ำ ให้ส่งออกไฟล์ให้เล็กลงหรือขอปรับผ่านผู้ให้บริการก่อน

คลิป 60 วินาที Video 8 Mbps + Audio 128 kbps ประมาณ 61 MB/คลิป แบบไฟล์จริงอาจต่างกัน; 5 คลิป/วันประมาณ 9.1 GB/เดือนเฉพาะต้นฉบับ ยังไม่รวม Temporary/Backup/การดาวน์โหลด ตรวจ Bandwidth แยกจากพื้นที่เก็บไฟล์

## 7. เปิดตัวติดตั้ง

1. เปิด https://www.krusomkhuan.com/studio/
2. หากมีรายการ Extension ไม่พร้อม ให้แก้ผ่านเว็บ Control Panel แล้วโหลดใหม่
3. ใส่ Setup Key, ชื่อผู้ดูแล, อีเมล และรหัสผ่านใหม่ 12–72 ไบต์ พร้อมยืนยัน
4. กดติดตั้ง ระบบสร้างตาราง, Admin, โปรไฟล์ส่วนตัว และ Encryption Key ใน var
5. กลับ File Manager ล้างค่า setup_key ใน Config เป็นค่าว่างหลังสำเร็จ
6. เข้าสู่ระบบด้วยบัญชีที่สร้างเอง ไม่มี Public Registration

ตัวติดตั้งปิดการสร้าง Admin ซ้ำเมื่อมี Admin ใน DB แล้ว แม้ผู้ใช้เปิด URL install อีกครั้ง การติดตั้งไม่ส่ง Secret ไป n8n หรือแพลตฟอร์ม Social

## 8. ตรวจรับบนโฮสติ้ง

- Login/Logout และหน้า Settings เปิดได้
- ใช้คลิปทดสอบของตัวเอง H.264/AAC แนวตั้ง 9:16 ยาว 30–60 วินาที อัปโหลดแล้ว Preview/Download ได้
- เมื่อออกจากระบบ ลิงก์ Media ต้องกลับหน้า Login และเข้าถึงโฟลเดอร์ Media โดยตรงจากเว็บไม่ได้
- เพิ่ม Page ID/Channel ID จริงได้ แต่สถานะยังเป็น “ยังไม่เชื่อมต่อ API”
- สร้างคอนเทนต์/Caption ครบ ส่งตรวจ และอนุมัติได้โดยไม่เกิดโพสต์บน Social
- ทดลอง Manual แบบอัปโหลดใหม่ด้วยตัวเอง แล้วใส่ URL/เวลาโพสต์จริงเพื่อยืนยัน
- ตรวจเว็บเดิมยังทำงาน และไม่ได้แก้การติดตั้ง n8n

## 9. ปัญหาที่อาจพบ

- **ขึ้นรอตั้งค่า:** ตรวจว่ามี config.php และวาง studio-private นอก Document Root ถูกระดับ รวมถึง open_basedir
- **HTTP 500:** ดู Error Log ใน Control Panel จากรหัสอ้างอิง ตรวจ DB Credentials/Version และสิทธิ์ไฟล์ก่อน ไม่เปิด display_errors บน Production
- **HTTPS Redirect วน:** ตรวจ SSL/HTTPS flag ของ Hosting หากมี Proxy ต้องกำหนด Trusted Proxy อย่างเจาะจง อย่าเชื่อ X-Forwarded-Proto จากทุกแหล่ง
- **403/404 ใน /studio:** ตรวจการวางไฟล์ Document Root และกฎ Redirect ของเว็บหลัก อย่าแทน .htaccess ของเว็บหลักทั้งไฟล์
- **500 จาก .htaccess:** หาก Hosting ไม่อนุญาต Options ให้ตรวจ Error Log/ผู้ให้บริการก่อนปรับเฉพาะไฟล์ studio/.htaccess ห้ามลดการป้องกันไฟล์ส่วนตัว
- **Upload เกินขนาด/413/Timeout:** ตรวจเพดาน PHP, Web Server/WAF, พื้นที่ชั่วคราว และเวลารับข้อมูล ระบบไม่ได้เปลี่ยนค่าระดับเซิร์ฟเวอร์ให้เอง
- **Codec/แนวตั้งไม่ผ่าน:** ส่งออก MP4 H.264 พร้อม AAC/ไม่มีเสียง และสัดส่วน 9:16 จริง ไม่มีการแปลงไฟล์บน Hosting
- **แชร์จากเพจยังยืนยันไม่ได้:** ถูกต้องตาม Phase 1 ต้องรอ Page เผยแพร่สำเร็จใน Phase 2 หรือแก้ร่างเป็นอัปโหลดส่วนตัวใหม่แล้วอนุมัติอีกครั้ง

## 10. Backup และย้อนกลับ

สำรอง DB ผ่าน phpMyAdmin/Control Panel และสำรอง studio-private/var/media พร้อม Config และ application.key แยกเก็บอย่างปลอดภัย รายการ Content อยู่ใน DB ไฟล์คลิปไม่ได้เก็บใน DB

การกู้คืนต้องจับคู่ DB/Media/Encryption Key ที่ถูกชุด ทดสอบบน DB และโฟลเดอร์แยกก่อน ห้าม Restore ทับ DB ของเว็บอื่น

หากยังไม่ใช้แอป สามารถหยุดเส้นทางเข้า Studio โดยย้าย/เปลี่ยนชื่อเฉพาะโฟลเดอร์ studio ผ่าน File Manager ให้เก็บ DB และ studio-private ไว้ก่อน ไม่มีการลบข้อมูลอัตโนมัติ และไม่มี Workflow เดิมของ n8n ที่ต้องย้อนกลับใน Phase 1

