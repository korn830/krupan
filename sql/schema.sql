-- สร้างฐานข้อมูล
CREATE DATABASE IF NOT EXISTS asset_system DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE asset_system;

-- ตารางผู้ใช้งาน
CREATE TABLE users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    name VARCHAR(100),
    role ENUM('admin', 'staff') DEFAULT 'staff'
);

-- ตารางหมวดหมู่
CREATE TABLE categories (
    category_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL
);

-- ตารางสถานที่
CREATE TABLE locations (
    location_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL
);

-- ตารางแผนก/หน่วยงาน
CREATE TABLE departments (
    department_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL
);

-- ตารางครุภัณฑ์
-- หมายเหตุ: status และ borrowable_status ถูกแก้ให้ตรงกับค่าที่โค้ดจริงใช้งาน (ของเดิมในไฟล์นี้ไม่ตรงกับระบบจริง)
CREATE TABLE assets (
    asset_id INT AUTO_INCREMENT PRIMARY KEY,
    asset_code VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    category_id INT,
    location_id INT,
    department_id INT,
    status ENUM('ใช้งานปกติ', 'ถูกยืม', 'ชำรุด', 'ส่งซ่อม', 'จำหน่าย', 'รออนุมัติ') DEFAULT 'ใช้งานปกติ',
    borrowable_status ENUM('สามารถยืมได้', 'ไม่สามารถยืมได้') DEFAULT 'สามารถยืมได้',
    purchase_date DATE,
    warranty_expiry DATE,
    price DECIMAL(10,2),
    image_url VARCHAR(255),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(category_id),
    FOREIGN KEY (location_id) REFERENCES locations(location_id),
    FOREIGN KEY (department_id) REFERENCES departments(department_id)
);

-- ตารางประวัติการเคลื่อนย้าย/ซ่อม
CREATE TABLE asset_history (
    history_id INT AUTO_INCREMENT PRIMARY KEY,
    asset_id INT,
    date DATE,
    type ENUM('ย้าย', 'ซ่อม', 'จำหน่าย'),
    details TEXT,
    user_id INT,
    FOREIGN KEY (asset_id) REFERENCES assets(asset_id),
    FOREIGN KEY (user_id) REFERENCES users(user_id)
);

-- ตารางประวัติการเพิ่ม/แก้ไขข้อมูลครุภัณฑ์
CREATE TABLE asset_action_log (
    log_id INT AUTO_INCREMENT PRIMARY KEY,
    asset_id INT NOT NULL,
    action_type ENUM('add', 'edit', 'borrow') NOT NULL,
    type ENUM('ย้าย', 'ซ่อม', 'จำหน่าย'),
    action_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    user_id INT NOT NULL,
    details TEXT,
    FOREIGN KEY (asset_id) REFERENCES assets(asset_id),
    FOREIGN KEY (user_id) REFERENCES users(user_id)
);

-- ตารางคำขอยืม-คืนครุภัณฑ์
-- หมายเหตุ: ตารางนี้ไม่มีอยู่ในไฟล์ schema.sql เดิม (ของจริงมีอยู่แล้วในฐานข้อมูล)
-- โครงสร้างด้านล่างนี้เป็นการสร้างขึ้นใหม่จากการอ่านโค้ดที่ใช้งานจริง
-- กรุณาตรวจสอบกับฐานข้อมูลจริงของคุณก่อนใช้ไฟล์นี้สำหรับการติดตั้งใหม่ตั้งแต่ต้น
CREATE TABLE borrow_history (
    borrow_id INT AUTO_INCREMENT PRIMARY KEY,
    asset_id INT NOT NULL,
    user_id INT NOT NULL,
    borrower_name VARCHAR(100),
    borrow_date DATE NOT NULL,
    return_date DATE NOT NULL,
    actual_return_date DATETIME NULL,
    note TEXT,
    attachment_path VARCHAR(255) NULL, -- เอกสารแนบของคำขอยืม (เพิ่มใหม่)
    status ENUM('รออนุมัติ', 'อนุมัติ', 'ปฏิเสธ', 'ยืมอยู่', 'เกินวันที่กำหนด', 'คืนแล้ว') DEFAULT 'รออนุมัติ',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (asset_id) REFERENCES assets(asset_id),
    FOREIGN KEY (user_id) REFERENCES users(user_id)
);
