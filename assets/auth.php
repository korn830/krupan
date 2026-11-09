<?php
/**
 * การยืนยันตัวตนของระบบจัดการครุภัณฑ์
 *
 * รวมตรรกะการเข้าสู่ระบบไว้ที่เดียว ทั้งการตรวจรหัสผ่าน การกันสวมรอย session
 * และการตัดสินว่าเข้ามาถูกประตูหรือไม่ — ถ้าคัดลอกโค้ดนี้ไปวางหลายหน้า
 * วันหนึ่งจะมีหน้าหนึ่งได้รับการแก้ไขด้านความปลอดภัย แต่อีกหน้าไม่ได้
 */

require_once __DIR__ . '/roles.php';

const KP_LOGIN_OK         = 'ok';
const KP_LOGIN_BAD_CREDS  = 'bad_credentials';
const KP_LOGIN_WRONG_DOOR = 'wrong_door';
const KP_LOGIN_PENDING    = 'pending_approval';
const KP_LOGIN_REJECTED   = 'rejected';

/** สถานะบัญชี */
const KP_STATUS_PENDING  = 'pending';
const KP_STATUS_APPROVED = 'approved';
const KP_STATUS_REJECTED = 'rejected';

/**
 * ช่องของหน้าเข้าสู่ระบบ มีสองช่อง
 *   user  — ครู
 *   staff — เจ้าหน้าที่พัสดุและหัวหน้าพัสดุ ใช้ช่องเดียวกัน
 *
 * ระดับสิทธิ์ยังมีสามระดับเหมือนเดิม แค่สองระดับบนเข้าทางเดียวกัน
 * สิทธิ์ที่ได้หลังเข้าระบบยังแยกกันตามบัญชี ไม่ได้ขึ้นกับช่องที่เลือก
 */
const KP_MODE_USER  = 'user';
const KP_MODE_STAFF = 'staff';

function kp_login_modes(): array
{
    return [KP_MODE_USER, KP_MODE_STAFF];
}

/**
 * รับค่าโหมดจากฟอร์มหรือ query string ให้เหลือเฉพาะค่าที่ยอมรับได้
 * ค่าแปลกปลอมตกมาเป็นช่องครูเสมอ ไม่ใช่ช่องที่มีสิทธิ์สูงกว่า
 */
function kp_clean_mode($mode): string
{
    return in_array($mode, kp_login_modes(), true) ? $mode : KP_MODE_USER;
}

/**
 * พยายามเข้าสู่ระบบ
 *
 * ลำดับการตรวจสำคัญมาก: ต้องตรวจรหัสผ่านให้ผ่านก่อน แล้วจึงดูว่าเข้ามาถูกโหมดไหม
 * ถ้าสลับลำดับกัน ข้อความผิดพลาดจะบอกคนที่ไล่สุ่มชื่อผู้ใช้ได้ทันทีว่า
 * บัญชีไหนเป็นของเจ้าหน้าที่ ซึ่งเท่ากับยกข้อมูลให้ผู้ไม่หวังดีฟรี ๆ
 * แบบนี้เฉพาะคนที่รู้รหัสผ่านอยู่แล้วเท่านั้นที่จะรู้ว่าบัญชีเป็นประเภทใด
 *
 * @param string $mode โหมดที่ผู้ใช้เลือกบนหน้าเข้าสู่ระบบ
 * @return array{status:string,user:?array}
 */
function kp_attempt_login(PDO $conn, string $username, string $password, string $mode): array
{
    $mode = kp_clean_mode($mode);

    $stmt = $conn->prepare(
        "SELECT user_id, username, password_hash, name, role, status FROM users WHERE username = ?"
    );
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        // เทียบกับ hash หลอกให้เสียเวลาพอ ๆ กับกรณีที่มีบัญชีอยู่จริง
        // ไม่งั้นเวลาที่ตอบกลับเร็วกว่าจะบอกได้ว่าชื่อผู้ใช้นี้ไม่มีในระบบ
        //
        // cost ต้องเท่ากับที่ใช้ตอนสร้างบัญชี (PASSWORD_DEFAULT = bcrypt cost 10)
        // ถ้าไม่เท่า เวลาที่ใช้จะต่างกันจนเดาได้อยู่ดี แค่กลับทิศกัน
        password_verify($password, '$2y$10$WQMMORydalu6iIA59eCtMeosnL/icHJvbjnOJwer2tZRTmYLWIosq');
        return ['status' => KP_LOGIN_BAD_CREDS, 'user' => null];
    }

    if (!password_verify($password, $user['password_hash'])) {
        return ['status' => KP_LOGIN_BAD_CREDS, 'user' => null];
    }

    // ตรวจสถานะบัญชีหลังรหัสผ่านผ่านแล้วเท่านั้น
    // ถ้าตรวจก่อน คนที่ไล่สุ่มชื่อผู้ใช้จะรู้ได้ว่าบัญชีไหนเพิ่งสมัครเข้ามา
    // ฐานข้อมูลเก่าที่ยังไม่ได้รันไมเกรชันจะไม่มีคอลัมน์นี้ ให้ถือว่าอนุมัติแล้ว
    $accountStatus = $user['status'] ?? KP_STATUS_APPROVED;

    if ($accountStatus === KP_STATUS_PENDING) {
        return ['status' => KP_LOGIN_PENDING, 'user' => $user];
    }
    if ($accountStatus === KP_STATUS_REJECTED) {
        return ['status' => KP_LOGIN_REJECTED, 'user' => $user];
    }

    // รหัสผ่านถูกต้องแล้ว จึงบอกได้ว่าเข้าผิดช่องโดยไม่รั่วข้อมูลให้ใคร
    // เจ้าหน้าที่พัสดุกับหัวหน้าพัสดุใช้ช่องเดียวกัน จึงเทียบแค่ว่าเป็นฝั่งพัสดุไหม
    if (kp_is_staff($user['role']) !== ($mode === KP_MODE_STAFF)) {
        return ['status' => KP_LOGIN_WRONG_DOOR, 'user' => $user];
    }

    return ['status' => KP_LOGIN_OK, 'user' => $user];
}

/** เปิด session ให้ผู้ใช้ที่ยืนยันตัวตนผ่านแล้ว */
function kp_start_session(array $user): void
{
    // ออก session id ใหม่ กันการสวมรอย session ที่ถูกกำหนดค่าไว้ก่อนล็อกอิน
    session_regenerate_id(true);

    $_SESSION['user_id'] = $user['user_id'];
    $_SESSION['role']    = $user['role'];
    $_SESSION['name']    = $user['name'];
}

/** ช่องเข้าสู่ระบบที่ตรงกับสิทธิ์ของบัญชีนี้ (admin/staff เดิมถูกแปลงให้แล้ว) */
function kp_mode_for_role(?string $role): string
{
    return kp_is_staff($role) ? KP_MODE_STAFF : KP_MODE_USER;
}


/**
 * สมัครสมาชิกด้วยตัวเอง
 *
 * บัญชีที่สมัครเองได้สิทธิ์ "ครู" เสมอ และต้องรอหัวหน้าพัสดุอนุมัติ
 * ห้ามรับค่า role จากฟอร์มเด็ดขาด ไม่งั้นใครก็สมัครเป็นหัวหน้าพัสดุได้เอง
 * ถ้าจะให้ใครเป็นเจ้าหน้าที่หรือหัวหน้า หัวหน้าพัสดุเป็นคนตั้งให้ตอนอนุมัติ
 *
 * @return array{ok:bool, error:?string}
 */
function kp_register_user(PDO $conn, string $username, string $password, string $name): array
{
    $username = trim($username);
    $name     = trim($name);

    if ($username === '' || $password === '' || $name === '') {
        return ['ok' => false, 'error' => 'กรุณากรอกข้อมูลให้ครบทุกช่อง'];
    }
    if (!preg_match('/^[A-Za-z0-9._-]{4,50}$/', $username)) {
        return ['ok' => false, 'error' => 'ชื่อผู้ใช้ต้องยาว 4-50 ตัว ใช้ได้เฉพาะ a-z A-Z 0-9 . _ -'];
    }
    if (mb_strlen($password) < 8) {
        return ['ok' => false, 'error' => 'รหัสผ่านต้องยาวอย่างน้อย 8 ตัวอักษร'];
    }
    if (mb_strlen($name) > 100) {
        return ['ok' => false, 'error' => 'ชื่อ-นามสกุลยาวเกินไป'];
    }

    try {
        $stmt = $conn->prepare(
            "INSERT INTO users (username, password_hash, name, role, status)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $username,
            password_hash($password, PASSWORD_DEFAULT),
            $name,
            KP_ROLE_USER,        // สิทธิ์ตายตัว ไม่รับจากฟอร์ม
            KP_STATUS_PENDING,   // รออนุมัติเสมอ
        ]);
    } catch (PDOException $e) {
        // ชนคีย์ไม่ซ้ำ = ชื่อผู้ใช้นี้ถูกใช้ไปแล้ว
        // เช็คทั้ง SQLSTATE 23000 และรหัสของ MySQL เอง (1062) เพราะ getCode()
        // ไม่ได้คืน SQLSTATE เป็นสตริงเสมอไป ขึ้นกับไดรเวอร์และการตั้งค่า
        $sqlState  = (string)$e->getCode();
        $driverErr = $e->errorInfo[1] ?? null;
        if ($sqlState === '23000' || $driverErr === 1062) {
            return ['ok' => false, 'error' => 'ชื่อผู้ใช้นี้ถูกใช้แล้ว กรุณาเลือกชื่ออื่น'];
        }
        error_log('kp_register_user: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'เกิดข้อผิดพลาดในการสมัคร กรุณาลองใหม่อีกครั้ง'];
    }

    return ['ok' => true, 'error' => null];
}

/** จำนวนคำขอสมัครที่ยังรออนุมัติ */
function kp_pending_user_count(PDO $conn): int
{
    try {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE status = ?");
        $stmt->execute([KP_STATUS_PENDING]);
        return (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log('kp_pending_user_count: ' . $e->getMessage());
        return 0;
    }
}
