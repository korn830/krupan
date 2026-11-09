-- ============================================================
-- 003_user_approval.sql
-- เปิดให้สมัครสมาชิกเองได้ แต่ต้องรอหัวหน้าพัสดุอนุมัติก่อนจึงเข้าระบบได้
--
-- วิธีใช้:
--   mysql -u <user> -p <database> < sql/migrations/003_user_approval.sql
--
-- ปลอดภัยกับข้อมูลเดิม: ค่าเริ่มต้นเป็น 'approved'
-- บัญชีที่มีอยู่แล้วทุกบัญชีจึงยังเข้าระบบได้ตามปกติทันที
-- ============================================================

ALTER TABLE users
    ADD COLUMN status ENUM('pending', 'approved', 'rejected')
        NOT NULL DEFAULT 'approved'
        COMMENT 'pending = สมัครเองและรอหัวหน้าพัสดุอนุมัติ'
        AFTER role,
    ADD COLUMN created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP
        COMMENT 'เวลาที่สมัคร ใช้เรียงลำดับคำขอ'
        AFTER status;

-- บัญชีที่มีอยู่ก่อนหน้านี้ถือว่าอนุมัติแล้วทั้งหมด
UPDATE users SET status = 'approved' WHERE status IS NULL OR status = '';

-- ใช้ค้นรายการที่รออนุมัติ
CREATE INDEX idx_users_status ON users (status);

-- ตรวจผลลัพธ์
-- SELECT status, COUNT(*) FROM users GROUP BY status;
