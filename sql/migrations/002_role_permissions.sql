-- ============================================================
-- 002_role_permissions.sql
-- แยกสิทธิ์ผู้ใช้เป็น 3 ระดับ: ผู้ใช้ทั่วไป / เจ้าหน้าที่พัสดุ / หัวหน้าพัสดุ
--
-- วิธีใช้:
--   mysql -u <user> -p <database> < sql/migrations/002_role_permissions.sql
--
-- ปลอดภัยกับข้อมูลเดิม: ขยาย ENUM ก่อน แล้วค่อยแปลงค่า
-- จึงไม่มีช่วงไหนที่ค่าที่มีอยู่ตกนอก ENUM
-- ============================================================

-- ขั้นที่ 1: เปิดรับชื่อสิทธิ์ใหม่ โดยยังเก็บชื่อเดิมไว้
ALTER TABLE users
    MODIFY COLUMN role ENUM('user', 'officer', 'head', 'admin', 'staff')
    NOT NULL DEFAULT 'user';

-- ขั้นที่ 2: แปลงค่าเดิมเป็นชื่อใหม่
--   admin ผู้ดูแลระบบเดิม   -> head    หัวหน้าพัสดุ (ได้สิทธิ์ครบเหมือนเดิม)
--   staff เจ้าหน้าที่เดิม    -> officer เจ้าหน้าที่พัสดุ
UPDATE users SET role = 'head'    WHERE role = 'admin';
UPDATE users SET role = 'officer' WHERE role = 'staff';

-- ขั้นที่ 3: ตัดชื่อเดิมออกจาก ENUM เมื่อไม่มีแถวไหนใช้แล้ว
--   ถ้ายังไม่มั่นใจ ข้ามขั้นนี้ไปก่อนได้ ระบบรองรับชื่อเดิมอยู่แล้ว
ALTER TABLE users
    MODIFY COLUMN role ENUM('user', 'officer', 'head')
    NOT NULL DEFAULT 'user';

-- ตรวจผลลัพธ์
-- SELECT role, COUNT(*) FROM users GROUP BY role;
