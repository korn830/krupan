-- ============================================================
-- 005_ai_chat_history.sql
-- ตารางเก็บประวัติแชทของผู้ช่วย AI
--
-- วิธีใช้:
--   mysql -u <user> -p <database> < sql/migrations/005_ai_chat_history.sql
--
-- โค้ดใน admin/ajax_ai.php อ่านและเขียนตารางนี้อยู่แล้ว
-- แต่เดิมไม่มีคำสั่งสร้างตารางอยู่ในไฟล์ schema หรือ migration ไหนเลย
-- ฐานข้อมูลที่สร้างใหม่จึงไม่มีตารางนี้ และผู้ช่วย AI จะใช้งานไม่ได้
--
-- ปลอดภัยกับข้อมูลเดิม: เป็นการสร้างตารางใหม่ ไม่แตะตารางที่มีอยู่
-- และใช้ IF NOT EXISTS จึงรันซ้ำได้โดยไม่ error
-- ============================================================

CREATE TABLE IF NOT EXISTS ai_chat_history (
    history_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    -- 'user' = ข้อความที่ผู้ใช้พิมพ์, 'assistant' = คำตอบของ AI
    role ENUM('user', 'assistant') NOT NULL,
    content MEDIUMTEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- ใช้ตอนดึงประวัติล่าสุดของแต่ละคน และตอนลบข้อความเก่าทิ้ง
    INDEX idx_ai_chat_user_time (user_id, created_at),
    CONSTRAINT fk_ai_chat_user FOREIGN KEY (user_id)
        REFERENCES users(user_id) ON DELETE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ตรวจผลลัพธ์
-- SHOW COLUMNS FROM ai_chat_history;
