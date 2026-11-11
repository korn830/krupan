<?php
session_start();
require_once __DIR__ . '/assets/auth.php';
require 'config/db.php';

// Generate a new CSRF token on page load if one doesn't exist
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// โหมดที่หน้าเปิดอยู่ — มาจากฟอร์มตอน POST หรือจาก ?mode= ตอนเปิดหน้า
$mode = kp_clean_mode($_POST['mode'] ?? $_GET['mode'] ?? KP_MODE_USER);

/**
 * ปลายทางหลังล็อกอินที่ส่งมากับฟอร์ม (ใช้ตอนสแกน QR แล้วถูกเด้งมาล็อกอิน)
 * รับเฉพาะ path ภายในโฟลเดอร์ user เพื่อกันการพาออกไปเว็บอื่น
 */
function kp_safe_redirect($value): string
{
    $value = (string)$value;
    if (
        strpos($value, 'user/') !== 0
        || strpos($value, '://') !== false
        || strpos($value, '//') !== false
    ) {
        return '';
    }
    return $value;
}

$redirect = kp_safe_redirect($_POST['redirect'] ?? $_GET['redirect'] ?? '');

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = "เกิดข้อผิดพลาดในการส่งข้อมูล. โปรดลองอีกครั้ง.";
        // Regenerate token on failure to prevent repeated attacks with the same token
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } else {
        $result = kp_attempt_login(
            $conn,
            $_POST["username"] ?? '',
            $_POST["password"] ?? '',
            $mode
        );

        if ($result['status'] === KP_LOGIN_OK) {
            kp_start_session($result['user']);

            if (kp_is_admin($result['user']['role'])) {
                header("Location: " . kp_home_for_role($result['user']['role']));
            } else {
                // ถ้ามาจาก QR ให้กลับไปหน้าขอยืมของครุภัณฑ์ชิ้นนั้น
                header("Location: " . ($redirect !== '' ? $redirect : 'user/index.php'));
            }
            exit;

        } elseif ($result['status'] === KP_LOGIN_PENDING) {
            // สมัครไว้แล้วแต่แอดมินยังไม่อนุมัติ
            $error = 'บัญชีนี้ยังรอแอดมินอนุมัติ กรุณาติดต่องานพัสดุหากรอนาน';

        } elseif ($result['status'] === KP_LOGIN_REJECTED) {
            $error = 'คำขอสมัครของบัญชีนี้ไม่ได้รับอนุมัติ กรุณาติดต่องานพัสดุ';

        } elseif ($result['status'] === KP_LOGIN_WRONG_DOOR) {
            // รหัสผ่านถูกแล้ว แค่เลือกโหมดผิด จึงบอกได้ว่าต้องไปโหมดไหน
            // และสลับหน้าไปรอที่โหมดนั้นให้เลย ผู้ใช้จะได้กรอกซ้ำแค่ครั้งเดียว
            $mode  = kp_mode_for_role($result['user']['role']);
            $error = $mode === KP_MODE_ADMIN
                ? 'บัญชีนี้เป็นบัญชีแอดมิน — สลับช่องให้แล้ว กรุณาเข้าสู่ระบบอีกครั้ง'
                : 'บัญชีนี้เป็นบัญชีผู้ใช้งาน — สลับช่องให้แล้ว กรุณาเข้าสู่ระบบอีกครั้ง';

        } else {
            // ข้อความเดียวกันทั้งสองโหมด ไม่บอกว่าชื่อผู้ใช้มีอยู่จริงหรือไม่
            $error = "ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง";
        }
    }
}

$themeBar = ['user' => 'var(--kp-crest)', 'admin' => '#0f172a'];
require_once __DIR__ . '/assets/kp_assets.php';
?>
<!DOCTYPE html>
<html lang="th" data-mode="<?= htmlspecialchars($mode, ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="theme-color" content="<?= $themeBar[$mode] ?>">
    <title>เข้าสู่ระบบ - ระบบจัดการครุภัณฑ์</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
    <link rel="stylesheet" href="<?= kp_asset('assets/css/tokens.css') ?>">
    <link rel="stylesheet" href="<?= kp_asset('assets/css/shared/login.css') ?>">
    <?php require_once __DIR__ . '/assets/theme.php'; kp_theme_boot(); ?>
</head>
<body>
    <div class="login-wrap">
        <div class="login-card">

            <!-- สวิตช์เลือกประเภทผู้ใช้ -->
            <div class="seg" role="radiogroup" aria-label="เลือกประเภทผู้ใช้งาน">
                <span class="pill" aria-hidden="true"></span>
                <?php foreach ([
                    'user'  => ['icon' => 'fa-chalkboard-user', 'label' => 'ผู้ใช้งาน'],
                    'admin' => ['icon' => 'fa-user-tie',        'label' => 'แอดมิน'],
                ] as $m => $opt): ?>
                    <input type="radio" name="mode_toggle" id="m-<?= $m ?>" value="<?= $m ?>"
                           form="loginForm" <?= $mode === $m ? 'checked' : '' ?>>
                    <label for="m-<?= $m ?>">
                        <i class="fas <?= $opt['icon'] ?>"></i>
                        <span><?= $opt['label'] ?></span>
                    </label>
                <?php endforeach; ?>
            </div>

            <div class="login-header">
                <div class="login-icon"><img src="assets/img/logo.png" alt="ตราวิทยาลัยการอาชีพวังไกลกังวล">
                    <i class="fas fa-chalkboard-user" data-for="user"></i>
                    <i class="fas fa-user-shield" data-for="admin"></i>
                </div>
                <h4 class="login-title">ระบบการจัดการและติดตามสถานะครุภัณฑ์</h4>
                <p class="subtitle" data-for="user">สำหรับผู้ใช้งานที่ต้องการยืมครุภัณฑ์</p>
                <p class="subtitle" data-for="admin">สำหรับแอดมินงานพัสดุ</p>
            </div>

            <?php if (isset($error)): ?>
                <div class="alert alert-danger" role="alert">
                    <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <form method="POST" id="loginForm" autocomplete="on" novalidate>
                <input type="hidden" name="csrf_token"
                       value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">

                <!-- โหมดที่เลือก ส่งไปให้ฝั่งเซิร์ฟเวอร์ตรวจว่าเข้ามาถูกประตูไหม -->
                <input type="hidden" name="mode" id="modeField"
                       value="<?= htmlspecialchars($mode, ENT_QUOTES, 'UTF-8') ?>">

                <input type="hidden" name="redirect"
                       value="<?= htmlspecialchars($redirect, ENT_QUOTES, 'UTF-8') ?>">

                <div class="form-group">
                    <label for="username" class="form-label">ชื่อผู้ใช้</label>
                    <div class="input-group">
                        <input type="text" id="username" name="username" class="form-control"
                               autocomplete="username" autocapitalize="none" autocorrect="off"
                               spellcheck="false" required maxlength="50">
                        <span class="input-icon"><i class="fas fa-user"></i></span>
                    </div>
                </div>

                <div class="form-group">
                    <label for="password" class="form-label">รหัสผ่าน</label>
                    <div class="input-group">
                        <input type="password" id="password" name="password" class="form-control"
                               autocomplete="current-password" required>
                        <span class="input-icon"><i class="fas fa-lock"></i></span>
                    </div>
                </div>

                <button type="submit" class="btn-login">
                    <span data-for="user">เข้าสู่ระบบ</span>
                    <span data-for="admin">เข้าสู่ระบบแอดมิน</span>
                </button>
            </form>

            <div class="login-note" data-for="user">
                <i class="fas fa-circle-info"></i>
                <span>
                    ยังไม่มีบัญชี? <a href="register.php">สมัครสมาชิก</a>
                    — บัญชีใหม่ต้องรอแอดมินอนุมัติก่อน
                </span>
            </div>
            <div class="login-note" data-for="admin">
                <i class="fas fa-circle-info"></i>
                <span>ส่วนนี้สำหรับแอดมินเท่านั้น บัญชีแอดมินสร้างได้จากในระบบ</span>
            </div>
        </div>

        <p class="login-org">วิทยาลัยการอาชีพวังไกลกังวล</p>
    </div>

<script>
// สลับธีมทั้งหน้าเมื่อเลือกโหมด และจำค่าไว้ในช่องซ่อนเพื่อส่งไปกับฟอร์ม
(function () {
    var root  = document.documentElement;
    var field = document.getElementById('modeField');
    var theme = document.querySelector('meta[name="theme-color"]');
    var bar   = { user: 'var(--kp-crest)', admin: '#0f172a' };

    document.querySelectorAll('.seg input').forEach(function (radio) {
        radio.addEventListener('change', function () {
            root.dataset.mode = radio.value;
            field.value = radio.value;
            theme.setAttribute('content', bar[radio.value]);
        });
    });
})();
</script>
</body>
</html>
