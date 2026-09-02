<?php
session_start();
require 'config/db.php';

// Generate a new CSRF token on page load if one doesn't exist
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = "เกิดข้อผิดพลาดในการส่งข้อมูล. โปรดลองอีกครั้ง.";
        // Regenerate token on failure to prevent repeated attacks with the same token
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); 
    } else {
        $username = $_POST["username"];
        $password = $_POST["password"];

        $stmt = $conn->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user["password_hash"])) {
            $_SESSION["user_id"] = $user["user_id"];
            $_SESSION["role"] = $user["role"];
            $_SESSION["name"] = $user["name"];

            // เก็บ redirect ที่ส่งมากับฟอร์มไว้ก่อนเปลี่ยนหน้า
            $redirect = $_POST['redirect'] ?? '';

            // Regenerate session ID after successful login
            session_regenerate_id(true);

            if ($user["role"] === 'admin') {
                header("Location: admin/index.php");
            } else {
                // ถ้ามาจาก QR ให้กลับไปหน้าขอยืมของ asset นั้น
                if (
                    !empty($redirect)
                    && strpos($redirect, 'user/') === 0
                    && strpos($redirect, '://') === false
                    && strpos($redirect, '//') === false
                ) {
                    header("Location: " . $redirect);
                } else {
                    header("Location: user/index.php");
                }
            }

            exit;
        } else {
            $error = "ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="theme-color" content="#667eea">
    <title>เข้าสู่ระบบ - ระบบจัดการครุภัณฑ์</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://cdn.jsdelivr.net">
    
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <link rel="stylesheet" href="admin/css/login.css">
</head>
<body>
    <div class="container">
        <div class="row justify-content-center align-items-center min-vh-100">
            <div class="col-12 col-sm-10 col-md-8 col-lg-6 col-xl-4">
                <div class="login-card">
                    <div class="login-header">
                        <div class="login-icon"></div>
                        <h4>ระบบจัดการครุภัณฑ์</h4>
                        <p class="subtitle">กรุณาเข้าสู่ระบบเพื่อดำเนินการต่อ</p>
                    </div>
                    
                    <?php if (isset($error)): ?>
                        <div class="alert alert-danger" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                    
                    <form method="POST" autocomplete="on" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">

                        <?php
                        // รับ URL ปลายทางจาก QR และส่งต่อไปพร้อมกับ POST
                        $redirect = $_GET['redirect'] ?? '';

                        // อนุญาตเฉพาะ URL ภายในโฟลเดอร์ user
                        if (
                            strpos($redirect, 'user/') !== 0
                            || strpos($redirect, '://') !== false
                            || strpos($redirect, '//') !== false
                        ) {
                            $redirect = '';
                        }
                        ?>

                        <input
                            type="hidden"
                            name="redirect"
                            value="<?= htmlspecialchars($redirect, ENT_QUOTES, 'UTF-8') ?>"
                        >

                        <div class="form-group">
                            <label for="username" class="form-label">ชื่อผู้ใช้</label>
                            <div class="input-group">
                                <input 
                                    type="text" 
                                    id="username"
                                    name="username" 
                                    class="form-control" 
                                    autocomplete="username"
                                    autocapitalize="none"
                                    autocorrect="off"
                                    spellcheck="false"
                                    required
                                    maxlength="50"
                                    value="<?= isset($_POST['username']) ? htmlspecialchars($_POST['username'], ENT_QUOTES, 'UTF-8') : '' ?>"
                                >
                                <span class="input-icon">👤</span>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="password" class="form-label">รหัสผ่าน</label>
                            <div class="input-group">
                                <input 
                                    type="password" 
                                    id="password"
                                    name="password" 
                                    class="form-control" 
                                    autocomplete="current-password"
                                    required
                                    maxlength="255"
                                >
                                <span class="input-icon">🔒</span>
                            </div>
                        </div>
                        
                        <button type="submit" class="btn-login" id="loginBtn">
                            <span class="btn-text">เข้าสู่ระบบ</span>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.querySelector('form');
            const loginBtn = document.getElementById('loginBtn');
            const btnText = document.querySelector('.btn-text');
            
            // Handle form submission with loading state
            form.addEventListener('submit', function(e) {
                if (!loginBtn.classList.contains('loading')) { // Prevent adding multiple times
                    loginBtn.classList.add('loading');
                    loginBtn.disabled = true;
                    btnText.textContent = 'กำลังเข้าสู่ระบบ...';
                }
            });


            // Focus management for better mobile UX
            const inputs = document.querySelectorAll('input[required]');
            inputs.forEach((input, index) => {
                input.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        if (index < inputs.length - 1) {
                            inputs[index + 1].focus();
                        } else {
                            form.submit();
                        }
                    }
                });
            });

            // Auto-focus first input on desktop, avoid on mobile to prevent keyboard pop-up
            if (window.innerWidth > 768) {
                document.getElementById('username').focus();
            }

            // Handle back button to reset loading state
            window.addEventListener('pageshow', function(event) {
                if (event.persisted) {
                    loginBtn.classList.remove('loading');
                    loginBtn.disabled = false;
                    btnText.textContent = 'เข้าสู่ระบบ';
                }
            });
        });

        // Prevent double submission
        let submitted = false;
        document.querySelector('form').addEventListener('submit', function(e) {
            if (submitted) {
                e.preventDefault();
                return false;
            }
            submitted = true;
        });

        // Handle iOS Safari viewport bug
        function setViewportHeight() {
            let vh = window.innerHeight * 0.01;
            document.documentElement.style.setProperty('--vh', `${vh}px`);
        }
        
        window.addEventListener('resize', setViewportHeight);
        setViewportHeight();
    </script>
</body>
</html>