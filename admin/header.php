<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION["user_id"])) {
    header("Location: ../index.php");
    exit;
}

// Base URL ของโปรเจกต์ (ปรับได้ตามโฟลเดอร์ของคุณ)
$base_url = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/';

// Get current page for active navigation highlighting
$current_page = basename($_SERVER['PHP_SELF']);
$current_dir = basename(dirname($_SERVER['PHP_SELF']));

// Function to check if navigation item should be active
if (!function_exists('isActive')) {
    function isActive($page, $dir = '') {
        global $current_page, $current_dir;
        if ($dir && $current_dir === $dir) {
            return 'active';
        }
        return ($current_page === $page) ? 'active' : '';
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>ระบบจัดการครุภัณฑ์</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
    <?php require_once __DIR__ . '/../assets/kp_assets.php'; ?>
    <link rel="stylesheet" href="<?= kp_asset('assets/css/shared/header.css', $base_url . '../') ?>">
    <!-- ชั้นหน้าตาหลักของระบบ โหลดท้ายสุดเพื่อให้ทับสไตล์เดิม -->
    <link rel="stylesheet" href="<?= kp_asset('assets/css/kp.css', $base_url . '../') ?>">
    <script src="<?= $base_url ?>js/script.js"></script>
    <script>
        const baseUrlForJs = '<?= $base_url ?>';
    </script>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📊</text></svg>">
    <meta name="description" content="ระบบจัดการครุภัณฑ์ - จัดการและติดตามครุภัณฑ์ขององค์กร">
    <meta name="keywords" content="ครุภัณฑ์, จัดการ, ติดตาม, ระบบ">
    <meta name="author" content="Equipment Management System">
    <meta name="robots" content="noindex, nofollow">
    <link rel="preload" href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <link rel="preload" href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark" id="mainNavbar">
        <div class="container-fluid">
            <a class="navbar-brand" href="<?= $base_url ?>index.php" title="กลับสู่หน้าหลัก">
                ระบบครุภัณฑ์
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="สลับเมนูนำทาง">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                    <li class="nav-item">
                        <a class="nav-link <?= isActive('index.php') ?>" href="<?= $base_url ?>index.php" title="หน้าแรก">
                            หน้าแรก
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= isActive('list.php', 'assets') ?>" href="<?= $base_url ?>list.php" title="จัดการครุภัณฑ์">
                            ครุภัณฑ์
                        </a>
                    </li>
                    <!-- [เพิ่มปุ่มเมนูอนุมัติการยืมตรงนี้] -->
                    <li class="nav-item">
                        <a class="nav-link <?= isActive('confirm_borrow.php') ?>" href="<?= $base_url ?>confirm_borrow.php" title="อนุมัติการยืมครุภัณฑ์">
                            อนุมัติการยืม
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= isActive('departments.php') ?>" href="<?= $base_url ?>departments.php" title="จัดการแผนก">
                            แผนก
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= isActive('categories.php') ?>" href="<?= $base_url ?>categories.php" title="หมวดหมู่ครุภัณฑ์">
                            <i class="fas fa-layer-group me-1"></i> หมวดหมู่
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= isActive('locations.php') ?>" href="<?= $base_url ?>locations.php" title="จัดการสถานที่">
                            สถานที่
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= isActive('asset_action_log.php') ?>" href="<?= $base_url ?>asset_action_log.php" title="ประวัติการเพิ่ม/แก้ไข">
                            <i class="fas fa-history me-1"></i> ประวัติการเพิ่ม/แก้ไข
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= isActive('add_user.php') ?>" href="<?= $base_url ?>add_user.php" title="เพิ่มผู้ใช้ใหม่">
                            <i class="fas fa-user-plus me-1"></i> เพิ่มผู้ใช้
                        </a>
                    </li>
                </ul>
                <ul class="navbar-nav">
                    <li class="nav-item dropdown" style="display: none;" id="userDropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <span class="user-avatar"><?= substr($_SESSION["name"], 0, 1) ?></span> <?= htmlspecialchars($_SESSION["name"]) ?>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= $base_url ?>logout.php" title="ออกจากระบบ" id="logoutBtn">
                            ออกจากระบบ
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
    <div class="container page-transition">
        <?php if (isset($breadcrumb) && !empty($breadcrumb)): ?>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="<?= $base_url ?>index.php">หน้าแรก</a></li>
                    <?php foreach ($breadcrumb as $item): ?>
                        <?php if (isset($item['url'])): ?>
                            <li class="breadcrumb-item">
                                <a href="<?= htmlspecialchars($item['url']) ?>">
                                    <?= htmlspecialchars($item['title']) ?>
                                </a>
                            </li>
                        <?php else: ?>
                            <li class="breadcrumb-item active" aria-current="page">
                                <?= htmlspecialchars($item['title']) ?>
                            </li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </ol>
            </nav>
        <?php endif; ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Navbar scroll effect
    const navbar = document.getElementById('mainNavbar');
    let lastScrollTop = 0;
    window.addEventListener('scroll', function() {
        const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
        if (scrollTop > 50) {
            navbar.classList.add('scrolled');
        } else {
            navbar.classList.remove('scrolled');
        }
        lastScrollTop = scrollTop;
    });

    // Add loading state to navigation links
    const navLinks = document.querySelectorAll('.nav-link:not([href*="logout"])');
    navLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            if (this.classList.contains('dropdown-toggle') || this.getAttribute('href').startsWith('#') || this.getAttribute('href').startsWith('http')) {
                return;
            }
            this.classList.add('loading');
            setTimeout(() => {
                this.classList.remove('loading');
            }, 2000);
        });
    });

    // Logout confirmation
    const logoutBtn = document.getElementById('logoutBtn');
    if (logoutBtn) {
        logoutBtn.addEventListener('click', function(e) {
            e.preventDefault();
            var modalHtml = `
                <div class="modal fade" id="logoutModal" tabindex="-1" aria-labelledby="logoutModalLabel" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header bg-danger text-white">
                                <h5 class="modal-title" id="logoutModalLabel"><i class="fas fa-sign-out-alt me-2"></i> ออกจากระบบ</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
                            </div>
                            <div class="modal-body">
                                <p>คุณต้องการออกจากระบบหรือไม่?</p>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                                <a href="${baseUrlForJs}logout.php" class="btn btn-danger">ยืนยันออกจากระบบ</a>
                            </div>
                        </div>
                    </div>
                </div>`;
            if (!document.getElementById('logoutModal')) {
                document.body.insertAdjacentHTML('beforeend', modalHtml);
            }
            var modal = new bootstrap.Modal(document.getElementById('logoutModal'));
            modal.show();
        });
    }

    // Auto-collapse mobile menu when clicking a link
    const navbarCollapse = document.getElementById('navbarNav');
    const navLinksAll = document.querySelectorAll('.navbar-nav .nav-link');
    navLinksAll.forEach(link => {
        link.addEventListener('click', () => {
            if (window.innerWidth < 992) {
                const bsCollapse = new bootstrap.Collapse(navbarCollapse, { toggle: false });
                bsCollapse.hide();
            }
        });
    });

    // Keyboard navigation support
    document.addEventListener('keydown', function(e) {
        if (e.altKey && e.key === 'h') {
            e.preventDefault();
            window.location.href = 'index.php';
        }
        if (e.altKey && e.key === 'a') {
            e.preventDefault();
            window.location.href = 'assets/list.php';
        }
        if (e.altKey && e.key === 'l') {
            e.preventDefault();
            if (confirm('คุณต้องการออกจากระบบหรือไม่?')) {
                window.location.href = `${baseUrlForJs}logout.php`;
            }
        }
    });
});
</script>