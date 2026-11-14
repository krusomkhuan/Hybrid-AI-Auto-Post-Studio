# อัปเดต n8n Header Auth (รองรับ n8n 1.106.3)

n8n 1.106.3 ไม่รู้จัก Crypto Node V2 จึงแสดง Node Nonce, Body Hash และ Sign Request เป็นเครื่องหมาย `?` อัปเดตนี้เพิ่มการยืนยันคำขอแบบ **Header Auth** โดยใช้กุญแจแยกจาก HMAC และเพิ่ม Workflow ใหม่ที่ใช้เฉพาะ Node ที่รุ่นนี้รองรับ ได้แก่ Schedule Trigger 1.2, Code 2 และ HTTP Request 4.2

ระบบยังรับ HMAC แบบเดิม ใช้ได้กับ n8n รุ่นที่รองรับ Crypto V2 และ HMAC Secret ที่สร้างไว้ยังใช้ได้

## หลักการ

- Header ชื่อ `X-Studio-Key` ต้องเป็นค่า hex 64 ตัวที่สร้างจากหน้า Studio
- Studio เก็บเฉพาะค่า SHA-256 ของ Key จึงแสดง Key ได้ครั้งเดียวตอนสร้าง
- หากคำขอส่ง `X-Studio-Key` มา ระบบตรวจ Key อย่างเดียว ถ้าผิดจะปฏิเสธทันที ไม่ย้อนไปตรวจ HMAC
- หากคำขอไม่ส่ง `X-Studio-Key` ระบบตรวจ HMAC ตามเดิม รวมถึง Timestamp ±5 นาทีและการกัน Nonce ซ้ำ
- `event_id` ยังกันการประมวลผลซ้ำเหมือนเดิม
- Header Auth ไม่มีการเซ็นตัวคำขอหรือกันการส่งซ้ำแบบ HMAC จึงต้องเรียกผ่าน HTTPS เท่านั้น สิ่งที่ Key นี้ทำได้มีเพียงสั่ง `tick` คิวที่อนุมัติแล้ว
- ปุ่ม **ยกเลิก Header Key** หยุด Key ได้ทันทีโดยไม่กระทบ HMAC

## ไฟล์ที่ต้องอัปโหลดทับบนโฮสติ้ง

สำรองไฟล์เดิมก่อนทุกครั้ง โครงสร้างใน ZIP ตรงกับตำแหน่งบนโฮสติ้ง

| ไฟล์ใน ZIP | ตำแหน่งบนโฮสติ้ง |
|---|---|
| `studio/integration.php` | `Home directory/krusomkhuan.com/studio/integration.php` |
| `studio-private/app/IntegrationApi.php` | `Home directory/studio-private/app/IntegrationApi.php` |
| `studio-private/app/controller.php` | `Home directory/studio-private/app/controller.php` |
| `studio-private/views/integrations.php` | `Home directory/studio-private/views/integrations.php` |
| `studio-private/workflows/phase2-publishing-header-auth.json` | `Home directory/studio-private/workflows/` (ไฟล์ใหม่) |

- `controller.php` รวมแพตช์ข้อความวินิจฉัย OAuth ก่อนหน้าไว้แล้ว
- ไม่ต้องอัปโหลด `Integrations.php` เพราะไฟล์บนโฮสติ้งจากแพตช์ DNS Cache ตรงกับรุ่นล่าสุดแล้ว
- ไม่ต้องแก้ Config หรือฐานข้อมูล
- ไฟล์ `n8n/Hybrid-Studio-Phase2-Publishing-HeaderAuth.json` ใช้ Import เข้า n8n เท่านั้น ไม่ต้องอัปโหลดขึ้นโฮสติ้ง

## ขั้นตอนหลังอัปโหลด

1. เปิด Studio ไปที่เมนู **การเชื่อมต่อ API** ส่วน **Header Key แยกสำหรับ n8n**
2. กด **สร้าง Header Key สำหรับ n8n** แล้วคัดลอก Key ที่แสดงครั้งเดียว
3. ใน n8n ไปที่ Credentials แล้วสร้าง **Header Auth** โดยตั้ง Name = `X-Studio-Key` และ Value = Key ที่คัดลอกมา ตั้งชื่อ Credential ว่า `Studio n8n Header Key`
4. ปิด Workflow เดิมที่มี Node เป็น `?` (Hybrid Studio · Phase 2 · Publishing Scheduler) หรือลบทิ้ง เพราะใช้งานบน 1.106.3 ไม่ได้
5. Import `Hybrid-Studio-Phase2-Publishing-HeaderAuth.json` (หรือกด **ดาวน์โหลด Workflow Header Auth** ในหน้า Studio)
6. เปิด Node **Studio Publish Step** แล้วเลือก Credential `Studio n8n Header Key`
7. กด **Execute workflow** หนึ่งครั้ง ถ้าสวิตช์เผยแพร่ยังปิด ผลที่ถูกต้องคือ `{"status":"DISABLED"}` ถ้าได้ 401 ให้ตรวจชื่อ Header และ Key
8. กด Active ใน n8n เมื่อพร้อม ซึ่งจะไม่เผยแพร่อะไรจนกว่าจะเปิดสวิตช์เผยแพร่ใน Studio และจัดคิวคอนเทนต์ที่อนุมัติแล้ว

## ผลทดสอบ (ฐานข้อมูลแยก, ไม่เรียก Social API จริง)

- `tests/phase2_core.php`: 54 จุดผ่าน (เพิ่ม 10 จุดสำหรับ Header Key)
- `tests/phase2_http.py`: 36 จุดผ่าน (เพิ่ม 12 จุด) ผ่าน PHP Server จริง ครอบคลุมทั้ง Key ถูก/ผิด/ยกเลิกแล้ว, HMAC Secret ถูกส่งมาแทน Key, HMAC เดิมยังผ่าน และ Workflow ไม่มี Crypto Node หรือ Key ฝังอยู่

ยังไม่ได้ทดสอบกับ n8n 1.106.3 จริงหรือบนโฮสติ้ง ขั้นตอนที่ 7 คือการยืนยันจริงครั้งแรก
