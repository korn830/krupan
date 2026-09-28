<?php
/**
 * ระดับสิทธิ์และการตรวจสอบสิทธิ์ของระบบจัดการครุภัณฑ์
 *
 * ระบบแบ่งผู้ใช้เป็น 3 ระดับ
 *   user    ผู้ใช้ทั่วไป   — ครู/นักเรียน ยืมครุภัณฑ์ได้อย่างเดียว
 *   officer เจ้าหน้าที่พัสดุ — ดูแลทะเบียนครุภัณฑ์ประจำวัน และอนุมัติการยืม
 *   head    หัวหน้าพัสดุ   — สิทธิ์ของเจ้าหน้าที่ บวกงานที่ย้อนกลับไม่ได้
 *                            (ลบครุภัณฑ์ นำเข้าข้อมูล จัดการผู้ใช้ ดูประวัติ)
 *
 * ทุกหน้าให้เรียก kp_require_cap() แทนการเช็ค $_SESSION['role'] ตรง ๆ
 * เพื่อให้สิทธิ์ทั้งระบบมาจากตารางเดียวกันในไฟล์นี้
 */

const KP_ROLE_USER    = 'user';
const KP_ROLE_OFFICER = 'officer';
const KP_ROLE_HEAD    = 'head';

/** ชื่อภาษาไทยของแต่ละระดับสิทธิ์ */
function kp_role_labels(): array
{
    return [
        KP_ROLE_USER    => 'ผู้ใช้ทั่วไป',
        KP_ROLE_OFFICER => 'เจ้าหน้าที่พัสดุ',
        KP_ROLE_HEAD    => 'หัวหน้าพัสดุ',
    ];
}

function kp_role_label(string $role): string
{
    return kp_role_labels()[kp_normalize_role($role)] ?? $role;
}

/**
 * แปลงชื่อสิทธิ์เดิมให้เป็นชื่อใหม่
 * ฐานข้อมูลเก่าใช้ admin/staff จึงต้องรองรับไว้ ไม่งั้นผู้ใช้เดิมจะเข้าระบบไม่ได้
 */
function kp_normalize_role(?string $role): string
{
    switch ($role) {
        case 'admin':                 // ผู้ดูแลเดิม = หัวหน้าพัสดุ
        case KP_ROLE_HEAD:
            return KP_ROLE_HEAD;
        case 'staff':                 // staff เดิม = เจ้าหน้าที่พัสดุ
        case KP_ROLE_OFFICER:
            return KP_ROLE_OFFICER;
        default:
            return KP_ROLE_USER;
    }
}

/**
 * ตารางสิทธิ์: ระดับไหนทำอะไรได้บ้าง
 * head ได้สิทธิ์ของ officer ทั้งหมด แล้วบวกสิทธิ์ที่ย้อนกลับไม่ได้เพิ่ม
 */
function kp_capabilities(string $role): array
{
    $officer = [
        'overview.view',          // ดูภาพรวมสถิติครุภัณฑ์
        'asset.view.all',         // ดูทะเบียนครุภัณฑ์ทั้งหมด
        'asset.create',
        'asset.edit',
        'asset.export',           // ส่งออก Excel / ดาวน์โหลด template
        'borrow.approve',         // อนุมัติ/ปฏิเสธคำขอยืม
        'taxonomy.manage',        // หมวดหมู่ สถานที่ แผนก
    ];

    switch (kp_normalize_role($role)) {
        case KP_ROLE_HEAD:
            return array_merge($officer, [
                'asset.delete',   // ลบครุภัณฑ์ออกจากทะเบียน
                'asset.import',   // นำเข้าข้อมูลจำนวนมาก
                'user.manage',    // เพิ่ม/แก้ไขบัญชีผู้ใช้
                'log.view',       // ประวัติการเพิ่ม/แก้ไข
            ]);

        case KP_ROLE_OFFICER:
            return $officer;

        default:
            return [
                'asset.view.borrowable',  // ดูเฉพาะรายการที่ยืมได้
                'borrow.request',         // ส่งคำขอยืม
                'borrow.view.own',        // ดูการยืมของตัวเอง
            ];
    }
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

/** เป็นเจ้าหน้าที่พัสดุหรือหัวหน้าพัสดุหรือไม่ (ใช้เลือกเมนู/หน้าแรก) */
function kp_is_staff(?string $role = null): bool
{
    $r = kp_normalize_role($role ?? ($_SESSION['role'] ?? null));
    return $r === KP_ROLE_OFFICER || $r === KP_ROLE_HEAD;
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
    return kp_is_staff($role) ? 'admin/index.php' : 'user/index.php';
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
        header('Location: ' . $base . 'index.php');
        exit;
    }

    if (!kp_can($capability)) {
        $_SESSION['error_message'] = 'คุณไม่มีสิทธิ์เข้าถึงหน้านี้';
        header('Location: ' . $base . kp_home_for_role());
        exit;
    }
}
