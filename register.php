<?php
session_start();
require_once __DIR__ . '/assets/auth.php';
require 'config/db.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$done = false;
$old  = ['username' => '', 'name' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = 'เกิดข้อผิดพลาดในการส่งข้อมูล. โปรดลองอีกครั้ง.';
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } else {
        $old['username'] = $_POST['username'] ?? '';
        $old['name']     = $_POST['name'] ?? '';

        if (($_POST['password'] ?? '') !== ($_POST['password_confirm'] ?? '')) {
            $error = 'รหัสผ่านทั้งสองช่องไม่ตรงกัน';
        } else {
            // สิทธิ์ไม่ได้มาจากฟอร์ม — kp_register_user() ตั้งเป็น "ผู้ใช้งาน" และ "รออนุมัติ" ให้เสมอ
            $result = kp_register_user(
                $conn,
                $_POST['username'] ?? '',
                $_POST['password'] ?? '',
                $_POST['name'] ?? ''
            );
            if ($result['ok']) {
                $done = true;
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            } else {
                $error = $result['error'];
            }
        }
    }
}

require_once __DIR__ . '/assets/kp_assets.php';
?>
<!DOCTYPE html>
<html lang="th" data-mode="user">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="var(--kp-crest)">
    <title>สมัครสมาชิก - ระบบจัดการครุภัณฑ์</title>

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

            <div class="login-header">
                <div class="login-icon"><img src="assets/img/logo.jpg" alt="ตราวิทยาลัยการอาชีพวังไกลกังวล"><i class="fas fa-user-plus"></i></div>
                <h4>สมัครสมาชิก</h4>
                <p class="subtitle">สำหรับผู้ใช้งานที่ต้องการยืมครุภัณฑ์</p>
            </div>

            <?php if ($done): ?>

                <div class="alert alert-success" role="alert">
                    <i class="fas fa-circle-check me-1"></i>
                    ส่งคำขอสมัครเรียบร้อยแล้ว
                </div>
                <p class="register-done">
                    บัญชีของคุณจะใช้งานได้หลังจาก<strong>แอดมินอนุมัติ</strong>
                    หากรอนานกรุณาติดต่องานพัสดุโดยตรง
                </p>
                <a href="index.php" class="btn-login" style="text-decoration:none;">
                    <i class="fas fa-arrow-left me-1"></i> กลับไปหน้าเข้าสู่ระบบ
                </a>

            <?php else: ?>

                <?php if (isset($error)): ?>
                    <div class="alert alert-danger" role="alert">
                        <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php endif; ?>

                <form method="POST" autocomplete="on" novalidate>
                    <input type="hidden" name="csrf_token"
                           value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">

                    <div class="form-group">
                        <label for="name" class="form-label">ชื่อ-นามสกุล</label>
                        <div class="input-group">
                            <input type="text" id="name" name="name" class="form-control"
                                   value="<?= htmlspecialchars($old['name'], ENT_QUOTES, 'UTF-8') ?>"
                                   autocomplete="name" required maxlength="100">
                            <span class="input-icon"><i class="fas fa-id-card"></i></span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="username" class="form-label">ชื่อผู้ใช้</label>
                        <div class="input-group">
                            <input type="text" id="username" name="username" class="form-control"
                                   value="<?= htmlspecialchars($old['username'], ENT_QUOTES, 'UTF-8') ?>"
                                   autocomplete="username" autocapitalize="none" autocorrect="off"
                                   spellcheck="false" required minlength="4" maxlength="50">
                            <span class="input-icon"><i class="fas fa-user"></i></span>
                        </div>
                        <small class="field-hint">4-50 ตัว ใช้ได้เฉพาะ a-z A-Z 0-9 . _ -</small>
                    </div>

                    <div class="form-group">
                        <label for="password" class="form-label">รหัสผ่าน</label>
                        <div class="input-group">
                            <input type="password" id="password" name="password" class="form-control"
                                   autocomplete="new-password" required minlength="8">
                            <span class="input-icon"><i class="fas fa-lock"></i></span>
                        </div>
                        <small class="field-hint">อย่างน้อย 8 ตัวอักษร</small>
                    </div>

                    <div class="form-group">
                        <label for="password_confirm" class="form-label">ยืนยันรหัสผ่าน</label>
                        <div class="input-group">
                            <input type="password" id="password_confirm" name="password_confirm"
                                   class="form-control" autocomplete="new-password" required minlength="8">
                            <span class="input-icon"><i class="fas fa-lock"></i></span>
                        </div>
                    </div>

                    <button type="submit" class="btn-login">สมัครสมาชิก</button>
                </form>

                <div class="login-note" style="display:flex;">
                    <i class="fas fa-circle-info"></i>
                    <span>
                        สมัครได้เฉพาะบัญชีผู้ใช้งาน และต้องรอแอดมินอนุมัติก่อนจึงเข้าใช้งานได้
                        — มีบัญชีอยู่แล้ว? <a href="index.php">เข้าสู่ระบบ</a>
                    </span>
                </div>

            <?php endif; ?>
        </div>

        <p class="login-org">วิทยาลัยการอาชีพวังไกลกังวล</p>
    </div>
</body>
</html>
