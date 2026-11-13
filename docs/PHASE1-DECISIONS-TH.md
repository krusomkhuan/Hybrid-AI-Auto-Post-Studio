# บันทึกขอบเขต Phase 1 ที่อนุมัติ

อ้างอิงแบบระบบ 0.1 และข้อความเจ้าของโครงการวันที่ 13 พฤศจิกายน 2569

- อนุมัติพัฒนา Phase 1
- ใช้ https://www.krusomkhuan.com/studio/ แทน Subdomain ที่เคยเสนอ โฟลเดอร์ studio บน Hosting สร้างแล้ว
- จัดการ Hosting/n8n ผ่านเว็บ ไม่พึ่ง SSH หรือการติดตั้ง Runtime ใหม่บน Server
- Hosting รวมประมาณ 50 GB ใช้เว็บอื่นประมาณ 10 GB เจ้าของแจ้งว่าเหลือสำหรับระบบประมาณ 40 GB
- ทดลอง Local Hosting Storage ก่อน NAS/Google Drive เป็นส่วนขยายภายหลัง
- แอปเริ่มต้นวงเงินคลัง 30 GiB เพื่อเผื่อพื้นที่ส่วนอื่น ปรับ Config ได้ ไม่มีการลบอัตโนมัติ ไม่ใช่การรับรอง Quota จริง
- ใช้ getID3 ตรวจ MP4 ฝั่ง PHP เพื่อให้ Phase 1 ตรวจ Metadata ได้โดยไม่ต้องมี FFmpeg บน Hosting; การแปลงวิดีโอ/AI ยังไม่เปิด
- บันทึกรายการ Page/Channel แบบ NOT_CONNECTED ได้เพื่อเตรียมคอนเทนต์ การ Connect OAuth จริงอยู่ Phase 2
- SHARE_PAGE ต้องรอ Page เผยแพร่จริง ในช่วงทดลอง Phase 1 ใช้ UPLOAD_VIDEO เพื่อทดลอง Manual ได้ครบ
- การอนุมัติ Phase 1 ไม่รวมการซื้อบริการ AI, เชื่อม NAS/Drive, เปลี่ยน Hosting หรือติดตั้งสิ่งใดบน n8n เดิม

