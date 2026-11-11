-- ============================================================
-- pending_users.sql — บัญชีตัวอย่างสำหรับทดสอบหน้าอนุมัติคำขอสมัคร
--
-- วิธีใช้:
--   mysql -u <user> -p <database> < sql/samples/pending_users.sql
--
-- สร้างคำขอสมัคร 5 รายการ เหมือนมีคนกดสมัครเองจากหน้า register.php
-- ทั้งห้ารายการจะไปโผล่ในกล่อง "คำขอสมัครที่รออนุมัติ" ที่หน้าจัดการผู้ใช้
-- และตัวเลขแจ้งเตือนบนหน้าแผงควบคุมจะขึ้นเป็น 5
--
-- *** นี่คือข้อมูลทดสอบ ไม่ใช่บัญชีจริง ***
-- ทั้งห้าบัญชีใช้รหัสผ่านเดียวกันคือ   test1234
-- ซึ่งเป็นรหัสที่เปิดเผยอยู่ในไฟล์นี้ จึงห้ามใช้กับระบบที่ใช้งานจริง
-- ทดสอบเสร็จแล้วให้ลบทิ้งด้วยคำสั่งบรรทัดสุดท้ายของไฟล์นี้
--
-- หมายเหตุ: ต้องรัน 003_user_approval.sql ไปแล้ว (ต้องมีคอลัมน์ status
-- กับ created_at) ไม่งั้นคำสั่งนี้จะไม่ผ่าน
-- ============================================================

-- role = 'user' และ status = 'pending' เหมือนที่ kp_register_user() เขียนลงจริง
-- บัญชีที่สมัครเองจะไม่มีทางได้สิทธิ์แอดมินมาเอง ต้องให้แอดมินตั้งให้ตอนอนุมัติ
--
-- ON DUPLICATE KEY UPDATE ทำให้รันซ้ำได้โดยไม่ error
-- ถ้าเคยอนุมัติไปแล้วแล้วอยากทดสอบใหม่ ก็รันไฟล์นี้ซ้ำได้เลย
-- ทั้งห้ารายการจะกลับมาเป็น pending อีกครั้ง
INSERT INTO users (username, password_hash, name, role, status, created_at) VALUES
('somchai.k',  '$2y$10$Xnra92oQYFrNs5LwrcPgmOwf8Q/lC2q0VsAE1VAlZD1oSIhgoDmWq', 'นายสมชาย เกษมสุข',      'user', 'pending', NOW() - INTERVAL 6 DAY),
('pranee.w',   '$2y$10$ReKmx3O8H7qRdwmLyOcAbuCvJ6uWQWZAFDAPiu6bF6Nt05gl8UnTS', 'นางสาวปราณี วงศ์ทอง',    'user', 'pending', NOW() - INTERVAL 4 DAY),
('anuwat.s',   '$2y$10$0eD7bkxLoToyAWdInZw61eqmT1UtL8KAWbYMSA7Bj9jhFezuHT.iq', 'นายอนุวัฒน์ สุขใจ',       'user', 'pending', NOW() - INTERVAL 2 DAY),
('kanokwan.p', '$2y$10$4Ckz9wpWBihjprYwJKSKvekFGa1mVWjQJLXFDCwB9hCQ.Cv6fbI0G', 'นางสาวกนกวรรณ พรหมมา', 'user', 'pending', NOW() - INTERVAL 1 DAY),
('thirapat.c', '$2y$10$BhRJlhRSEf7ll23tyAjX1Oi0l1bQ331TW24q8haP1LsKxu.FJxTY2', 'นายธีรภัทร ชัยมงคล',     'user', 'pending', NOW() - INTERVAL 3 HOUR)
ON DUPLICATE KEY UPDATE
    status     = 'pending',
    role       = 'user',
    created_at = VALUES(created_at);

-- ตรวจผลลัพธ์ — ควรได้ 5 แถว เรียงจากคำขอเก่าสุดไปใหม่สุด
SELECT user_id, username, name, role, status, created_at
FROM users
WHERE status = 'pending'
ORDER BY created_at;


-- ============================================================
-- ลบข้อมูลทดสอบทิ้ง — เอาเครื่องหมาย -- ข้างหน้าออกแล้วรันบรรทัดนี้
-- ============================================================
-- DELETE FROM users WHERE username IN ('somchai.k','pranee.w','anuwat.s','kanokwan.p','thirapat.c');
