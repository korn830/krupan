-- ============================================================
-- 004_single_admin_role.sql
-- รวมสิทธิ์ฝั่งพัสดุให้เหลือระดับเดียว
--   ผู้ใช้งาน (user)  — ยืมครุภัณฑ์
--   แอดมิน   (admin) — จัดการทุกอย่าง
--
-- วิธีใช้:
--   mysql -u <user> -p <database> < sql/migrations/004_single_admin_role.sql
--
-- ปลอดภัยกับข้อมูลเดิม: ขยาย ENUM ก่อน แล้วค่อยแปลงค่า
-- จึงไม่มีช่วงไหนที่ค่าที่มีอยู่ตกนอก ENUM
-- ============================================================

-- ขั้นที่ 1: เปิดรับชื่อ admin อีกครั้ง
--   002 ขั้นที่ 3 ตัด admin ออกจาก ENUM ไปแล้ว ถ้าไม่ทำขั้นนี้ก่อน
--   คำสั่ง UPDATE ข้างล่างจะเขียนค่าไม่ได้
ALTER TABLE users
    MODIFY COLUMN role ENUM('user', 'admin', 'officer', 'head', 'staff')
    NOT NULL DEFAULT 'user';

-- ขั้นที่ 2: รวมทุกชื่อฝั่งพัสดุเป็น admin
--   officer เจ้าหน้าที่พัสดุเดิม -> admin
--   head    หัวหน้าพัสดุเดิม    -> admin
--   staff   staff เดิม          -> admin
UPDATE users SET role = 'admin' WHERE role IN ('officer', 'head', 'staff');

-- ขั้นที่ 3: ตัดชื่อเดิมออกจาก ENUM เมื่อไม่มีแถวไหนใช้แล้ว
--   ข้ามขั้นนี้ไปก่อนได้ โค้ดแปลงชื่อเดิมให้เป็น admin อยู่แล้ว
ALTER TABLE users
    MODIFY COLUMN role ENUM('user', 'admin')
    NOT NULL DEFAULT 'user';

-- ตรวจผลลัพธ์
-- SELECT role, COUNT(*) FROM users GROUP BY role;
