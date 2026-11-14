# ทดลองเชื่อม Meta ผ่าน IPv4 บนโฮสติ้ง

ผลตรวจบนโฮสติ้ง: DNS A และ AAAA สำเร็จ, HTTPS โหมดอัตโนมัติสำเร็จ, IPv4 สำเร็จ 231 ms, IPv6 ล้มเหลว cURL 7 ภายใน 2 ms จึงยืนยันได้เฉพาะว่าเส้นทาง IPv6 ใช้งานไม่ได้ในการตรวจครั้งนี้ ยังไม่ยืนยันว่าเป็นสาเหตุของ OAuth timeout เดิม

แพตช์กำหนดให้คำขอไปยัง graph.facebook.com ใช้ IPv4 โดยยังค้นหา IP ผ่าน DNS ตามปกติ ไม่ตรึง IP ไม่ปิดการตรวจ TLS ไม่ส่งคำขอ OAuth ซ้ำอัตโนมัติ และไม่เปลี่ยนการเชื่อมต่อ Google/YouTube หรือ rupload.facebook.com

แก้การเก็บเวลา TLS ให้ใช้ CURLINFO_APPCONNECT_TIME โดยตรง เพื่อไม่ให้ค่าที่ไม่มีในอาร์เรย์รวมถูกอ่านเป็นศูนย์

## ติดตั้ง

1. สำรอง Integrations.php เดิมในพื้นที่ส่วนตัว
2. อัปโหลด Integrations.php ใน ZIP ทับ Home directory/studio-private/app/Integrations.php
3. คง controller.php จากแพตช์วินิจฉัยก่อนหน้าไว้ ไม่ต้องแก้ Config, Secret, Config ID หรือฐานข้อมูล
4. กลับ Studio เริ่ม Connect / Reconnect Facebook ใหม่ ส่งภาพผลกลับมา ห้ามใช้ callback เก่าซ้ำ

ทดสอบ syntax PHP และชุดทดสอบข้อมูลวินิจฉัยจำลองแล้ว ยังไม่ได้ยืนยัน OAuth จริงหลังใช้แพตช์
