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
        "SELECT user_id, username, password_hash, name, role FROM users WHERE username = ?"
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
