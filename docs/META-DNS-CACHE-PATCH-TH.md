# แพตช์ลดการค้นหา DNS ซ้ำใน Meta OAuth

หลังใช้ IPv4 ข้อผิดพลาดย้ายจากขั้น token ไปยัง permissions โดยรายงาน dns / 5006 ms จึงยังไม่ยืนยันว่าเครือข่ายของโฮสติ้งทำงานสม่ำเสมอ

โค้ดเดิมสร้าง cURL handle ใหม่ทุกคำขอ ทำให้แต่ละขั้น OAuth ไม่ใช้ DNS cache ร่วมกัน แพตช์ใช้ cURL share เฉพาะข้อมูล DNS ของ graph.facebook.com ภายในการทำงาน PHP รอบเดียว พร้อม cache อายุ 60 วินาที คง IPv4 และการตรวจ TLS ตามเดิม ไม่แชร์ Cookie หรือ Token ไม่ตรึง IP ไม่ส่งคำขอแลกโทเค็นซ้ำอัตโนมัติ

อ้างอิง: https://curl.se/libcurl/c/CURLSHOPT_SHARE.html ส่วน CURL_LOCK_DATA_DNS

## ติดตั้ง

1. สำรอง Integrations.php เดิมในพื้นที่ส่วนตัว
2. อัปโหลด Integrations.php ใน ZIP ทับ Home directory/studio-private/app/Integrations.php
3. ไม่ต้องแก้ Config, Secret, Config ID, controller.php หรือฐานข้อมูล
4. เริ่ม Connect / Reconnect Facebook ใหม่จาก Studio ส่งผลกลับมา

หากคำขอแรกยังค้นหา DNS ไม่สำเร็จ cache จะช่วยไม่ได้ ให้ผู้ให้บริการโฮสติ้งตรวจ DNS resolver ของ PHP ต่อ แพตช์นี้ไม่ใช่การยืนยันว่าปัญหาบนโฮสติ้งถูกแก้แล้ว
