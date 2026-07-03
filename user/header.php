<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION["user_id"])) {
    header("Location: ../index.php");
    exit;
}

// Base URL ของโปรเจกต์ (สำหรับ user) - ใช้แบบเดียวกับ admin/header.php
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
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?= $base_url ?>css/header.css">

    
    <!-- Favicon -->
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📊</text></svg>">
    
    <!-- Meta tags for better SEO and mobile experience -->
    <meta name="description" content="ระบบจัดการครุภัณฑ์ - จัดการและติดตามครุภัณฑ์ขององค์กร">
    <meta name="keywords" content="ครุภัณฑ์, จัดการ, ติดตาม, ระบบ">
    <meta name="author" content="Equipment Management System">
    <meta name="robots" content="noindex, nofollow">
    
    <!-- Preload important fonts -->
    <link rel="preload" href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <link rel="preload" href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" as="style" onload="this.onload=null;this.rel='stylesheet'">
</head>
<body>
    <!-- Enhanced Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark" id="mainNavbar">
        <div class="container-fluid">
            <!-- Brand -->
            <a class="navbar-brand" href="<?= $base_url ?>index.php" title="กลับสู่หน้าหลัก">
                ระบบครุภัณฑ์
            </a>
            
            <!-- Mobile toggle button -->
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav"
                aria-controls="navbarNav" aria-expanded="false" aria-label="สลับเมนูนำทาง">
                <span class="navbar-toggler-icon"></span>
            </button>
            
            <!-- Navigation items -->
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                    <li class="nav-item">
                        <a class="nav-link <?= isActive('index.php') ?>" href="index.php" title="หน้าแรก">
                            หน้าแรก
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= isActive('list.php', 'assets') ?>" href="list.php" title="ดูครุภัณฑ์">
                            <i class="fas fa-boxes-stacked me-1"></i> ครุภัณฑ์
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= isActive('my_borrow.php') ?>" href="my_borrow.php" title="การยืมของฉัน">
                            <i class="fas fa-hand-holding me-1"></i> การยืมของฉัน
                        </a>
                    </li>
                </ul>   
                <!-- User menu -->
                <ul class="navbar-nav">
                    <li class="nav-item">
                        <a class="nav-link" href="../logout.php" title="ออกจากระบบ" id="logoutBtn">
                            ออกจากระบบ
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Main content wrapper -->
    <div class="container page-transition">
        
        <!-- Optional: Breadcrumb navigation -->
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

    <!-- JavaScript for enhanced interactions -->
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
                // Don't add loading to dropdown toggles or external links
                if (this.classList.contains('dropdown-toggle') || 
                    this.getAttribute('href').startsWith('#') ||
                    this.getAttribute('href').startsWith('http')) {
                    return;
                }
                
                this.classList.add('loading');
                
                // Remove loading state after navigation or timeout
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
                <div class=\"modal fade\" id=\"logoutModal\" tabindex=\"-1\" aria-labelledby=\"logoutModalLabel\" aria-hidden=\"true\">
                  <div class=\"modal-dialog\">
                    <div class=\"modal-content\">
                      <div class=\"modal-header bg-danger text-white\">
                        <h5 class=\"modal-title\" id=\"logoutModalLabel\"><i class=\"fas fa-sign-out-alt me-2\"></i> ออกจากระบบ</h5>
                        <button type=\"button\" class=\"btn-close\" data-bs-dismiss=\"modal\" aria-label=\"ปิด\"></button>
                      </div>
                      <div class=\"modal-body\">
                        <p>คุณต้องการออกจากระบบหรือไม่?</p>
                      </div>
                      <div class=\"modal-footer\">
                        <button type=\"button\" class=\"btn btn-secondary\" data-bs-dismiss=\"modal\">ยกเลิก</button>
                        <a href=\"../logout.php\" class=\"btn btn-danger\">ยืนยันออกจากระบบ</a>
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
                    const bsCollapse = new bootstrap.Collapse(navbarCollapse, {
                        toggle: false
                    });
                    bsCollapse.hide();
                }
            });
        });
        
        // Keyboard navigation support
        document.addEventListener('keydown', function(e) {
            // Alt + H for Home
            if (e.altKey && e.key === 'h') {
                e.preventDefault();
                window.location.href = 'index.php';
            }
            
            // Alt + A for Assets
            if (e.altKey && e.key === 'a') {
                e.preventDefault();
                window.location.href = 'assets/list.php';
            }
            
            // Alt + L for Logout
            if (e.altKey && e.key === 'l') {
                e.preventDefault();
                if (confirm('คุณต้องการออกจากระบบหรือไม่?')) {
                    window.location.href = '/logout.php';
                }
            }
        });
    });
    </script>
