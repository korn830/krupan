-- =============================================================================
-- 001_fsn_numbering.sql
-- เพิ่มการรองรับการกำหนดหมายเลขพัสดุตามระบบ FSN
-- อ้างอิง: คู่มือการกำหนดหมายเลขพัสดุ สำนักงบประมาณ (มกราคม 2543)
--
-- วิธีใช้:  mysql -u USER -p DBNAME < sql/migrations/001_fsn_numbering.sql
-- ปลอดภัยที่จะรันซ้ำ (ใช้ IF NOT EXISTS)
-- =============================================================================

-- เก็บหมายเลขเดิมไว้ก่อนเปลี่ยนเป็น FSN เพื่อให้ตรวจสอบย้อนหลังได้
-- และเพื่อให้เจ้าหน้าที่ยังค้นด้วยเลขเดิมที่ติดอยู่บนตัวครุภัณฑ์ได้
ALTER TABLE assets
    ADD COLUMN IF NOT EXISTS old_code VARCHAR(50) NULL COMMENT 'หมายเลขเดิมก่อนแปลงเป็น FSN' AFTER asset_code;

-- ผูกหมวดหมู่ของระบบเข้ากับ "ประเภท" 4 หลักตามคู่มือ
-- เมื่อผู้ดูแลเลือกหมวดหมู่ ระบบจะเติมเลข 4 หลักแรกให้อัตโนมัติ
ALTER TABLE categories
    ADD COLUMN IF NOT EXISTS fsn_class CHAR(4) NULL COMMENT 'ประเภทพัสดุ 4 หลักตามคู่มือ เช่น 7110';

-- ค้นหาด้วยเลขเดิมได้เร็วขึ้น
CREATE INDEX IF NOT EXISTS idx_assets_old_code ON assets (old_code);

-- ตัวอย่างการผูกหมวดหมู่กับประเภท (ปรับตามหมวดหมู่จริงของวิทยาลัย)
-- UPDATE categories SET fsn_class = '7110' WHERE name = 'ครุภัณฑ์สำนักงาน';
-- UPDATE categories SET fsn_class = '7430' WHERE name = 'เครื่องกลสำนักงาน';
-- UPDATE categories SET fsn_class = '4120' WHERE name = 'เครื่องปรับอากาศ';
