<?php
/**
 * ระดับสิทธิ์และการตรวจสอบสิทธิ์ของระบบจัดการครุภัณฑ์
 *
 * ระบบแบ่งผู้ใช้เป็น 2 ระดับ
 *   user  ผู้ใช้งาน — ยืมครุภัณฑ์ได้อย่างเดียว
 *   admin แอดมิน    — ดูแลทะเบียนครุภัณฑ์ทั้งหมด อนุมัติการยืม
 *                     ลบ นำเข้าข้อมูล จัดการผู้ใช้ และดูประวัติ
 *
 * ทุกหน้าให้เรียก kp_require_cap() แทนการเช็ค $_SESSION['role'] ตรง ๆ
 * เพื่อให้สิทธิ์ทั้งระบบมาจากตารางเดียวกันในไฟล์นี้
 */

const KP_ROLE_USER  = 'user';
const KP_ROLE_ADMIN = 'admin';

/** ชื่อภาษาไทยของแต่ละระดับสิทธิ์ */
function kp_role_labels(): array
{
    return [
        KP_ROLE_USER  => 'ผู้ใช้งาน',
        KP_ROLE_ADMIN => 'แอดมิน',
    ];
}

function kp_role_label(string $role): string
{
    return kp_role_labels()[kp_normalize_role($role)] ?? $role;
}

/**
 * แปลงชื่อสิทธิ์เดิมให้เป็นชื่อที่ใช้อยู่
 *
 * ฐานข้อมูลเคยใช้ staff/head/officer จึงต้องรองรับไว้
 * ไม่งั้นบัญชีเดิมที่ยังไม่ถูกแปลงจะเข้าระบบไม่ได้
 * ทุกชื่อฝั่งพัสดุรวมเป็น admin ชื่อเดียว
 */
function kp_normalize_role(?string $role): string
{
    switch ($role) {
        case KP_ROLE_ADMIN:
        case 'head':        // หัวหน้าพัสดุเดิม
        case 'officer':     // เจ้าหน้าที่พัสดุเดิม
        case 'staff':       // staff เดิม
            return KP_ROLE_ADMIN;
        default:
            return KP_ROLE_USER;
    }
}

/** ตารางสิทธิ์: ระดับไหนทำอะไรได้บ้าง */
function kp_capabilities(string $role): array
{
    if (kp_normalize_role($role) === KP_ROLE_ADMIN) {
        return [
            'overview.view',          // ดูภาพรวมสถิติครุภัณฑ์
            'asset.view.all',         // ดูทะเบียนครุภัณฑ์ทั้งหมด
            'asset.create',
            'asset.edit',
            'asset.delete',           // ลบครุภัณฑ์ออกจากทะเบียน
            'asset.export',           // ส่งออก Excel / ดาวน์โหลด template
            'asset.import',           // นำเข้าข้อมูลจำนวนมาก
            'borrow.approve',         // อนุมัติ/ปฏิเสธคำขอยืม
            'taxonomy.manage',        // หมวดหมู่ สถานที่ แผนก
            'user.manage',            // เพิ่ม/แก้ไขบัญชีผู้ใช้
            'log.view',               // ประวัติการเพิ่ม/แก้ไข
        ];
    }

    return [
        'asset.view.borrowable',  // ดูเฉพาะรายการที่ยืมได้
        'borrow.request',         // ส่งคำขอยืม
        'borrow.view.own',        // ดูการยืมของตัวเอง
    ];
}

/** ระดับสิทธิ์ของผู้ที่กำลังใช้งานอยู่ */
function kp_current_role(): string
{
    return kp_normalize_role($_SESSION['role'] ?? null);
}

function kp_is_logged_in(): bool
{
    return isset($_SESSION['user_id']);
}

/** เป็นบัญชีฝั่งแอดมินหรือไม่ (ใช้เลือกเมนู/หน้าแรก/ช่องเข้าสู่ระบบ) */
function kp_is_admin(?string $role = null): bool
{
    return kp_normalize_role($role ?? ($_SESSION['role'] ?? null)) === KP_ROLE_ADMIN;
}

/** ตรวจว่าทำสิ่งนี้ได้หรือไม่ */
function kp_can(string $capability, ?string $role = null): bool
{
    $r = $role ?? ($_SESSION['role'] ?? null);
    return in_array($capability, kp_capabilities(kp_normalize_role($r)), true);
}

/** หน้าแรกที่เหมาะกับสิทธิ์ของผู้ใช้ */
function kp_home_for_role(?string $role = null): string
{
    return kp_is_admin($role) ? 'admin/index.php' : 'user/index.php';
}

/**
 * ด่านตรวจสิทธิ์ของแต่ละหน้า — ต้องเรียกก่อนพ่น HTML ใด ๆ
 * ไม่ได้เข้าสู่ระบบ  -> กลับไปหน้า login
 * สิทธิ์ไม่พอ        -> กลับไปหน้าแรกของสิทธิ์ตัวเอง ไม่ใช่หน้า login
 *                       (ไม่งั้นผู้ใช้จะงงว่าทำไมโดนเด้งออกจากระบบ)
 */
function kp_require_cap(string $capability, string $base = '../'): void
{
    if (!kp_is_logged_in()) {
        // พาไปที่ช่องเข้าสู่ระบบที่ตรงกับหน้าที่เขาพยายามเข้า
        // ถ้าเป็นสิ่งที่ผู้ใช้งานทำได้ ก็คือช่องผู้ใช้งาน นอกนั้นคือช่องแอดมิน
        // ใช้ค่าตรง ๆ เพราะไฟล์นี้ถูกโหลดก่อน auth.php จึงยังไม่มี KP_MODE_*
        $mode = kp_can($capability, KP_ROLE_USER) ? 'user' : 'admin';
        header('Location: ' . $base . 'index.php?mode=' . $mode);
        exit;
    }

    if (!kp_can($capability)) {
        $_SESSION['error_message'] = 'คุณไม่มีสิทธิ์เข้าถึงหน้านี้';
        header('Location: ' . $base . kp_home_for_role());
        exit;
    }
}
