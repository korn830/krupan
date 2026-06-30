<?php
require 'header.php';  // เรียก session, ตรวจสอบ login, แสดง navbar
require '../config/db.php';
if (!isset($_SESSION["user_id"]) || $_SESSION['role'] !== 'user') {
    header("Location: ../index.php");
    exit;
}

// นับจำนวนครุภัณฑ์แต่ละสถานะ
$stmt = $conn->query("SELECT status, COUNT(*) AS total FROM assets GROUP BY status");
$status_summary = $stmt->fetchAll();

// Function to get status icon
function getStatusIcon($status) {
    $icons = [
        'ใช้งานปกติ' => '✅',
        'ชำรุด' => '🔧',
        'ซ่อมแซม' => '⚙️',
        'เลิกใช้' => '📦',
        'จำหน่าย' => '📤',
        'รออนุมัติ' => '⏳',
        'ถูกยืม' => '📚'
    ];
    return $icons[$status] ?? '📋';
}

// สถานะที่ต้องการแสดงตลอดเวลา
$all_statuses = [
    'ใช้งานปกติ',
    'ชำรุด',
    'ซ่อมแซม',
    'เลิกใช้',
    'จำหน่าย',
    'รออนุมัติ',
    'ถูกยืม'
];
// สร้าง array สำหรับ map status => count
$status_map = [];
foreach ($status_summary as $row) {
    $status_map[$row['status']] = $row['total'];
}

// ดึงจำนวนผู้ใช้ทั้งหมด (ไม่นับ admin)
$user_count = $conn->query("SELECT COUNT(*) FROM users WHERE role != 'admin'")->fetchColumn();
// ดึงจำนวน admin
$admin_count = $conn->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
?>

<!-- Include the CSS file -->
<link rel="stylesheet" href="css/index.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<!-- Welcome Header -->
<div class="welcome-header">
    <span class="user-icon">👋</span>
    <h4>ยินดีต้อนรับ, <?= htmlspecialchars($_SESSION["name"]) ?></h4>
</div>

<!-- Summary Section -->
<div class="summary-section">
    <div class="status-cards">
        <?php foreach ($all_statuses as $status): ?>
            <div class="status-card" data-status="<?= htmlspecialchars($status) ?>">
                <div class="card-header">
                    <div class="card-icon">
                        <?= getStatusIcon($status) ?>
                    </div>
                    <h6 class="card-title"><?= htmlspecialchars($status) ?></h6>
                </div>
                <div class="card-count"><?= number_format($status_map[$status] ?? 0) ?></div>
                <div class="card-label">รายการ</div>
            </div>
        <?php endforeach; ?>
        <div class="status-card user-card" style="background:#f5f6fa; border:1px solid #d1d5db;">
            <div class="card-header">
                <div class="card-icon">👤</div>
                <h6 class="card-title">ผู้ใช้ทั้งหมด</h6>
            </div>
            <div class="card-count"><?= number_format($user_count) ?></div>
            <div class="card-label">User</div>
        </div>
    </div>
</div>
<!-- Action Button -->
<div class="action-section">
    <a href="list.php" class="btn-view-all">
        ดูรายการครุภัณฑ์ทั้งหมด
        <span class="icon">→</span>
    </a>
</div>

<!-- Optional: Add some JavaScript for enhanced interactions -->
<script>
    // Add loading effect to view all button
    const viewAllBtn = document.querySelector('.btn-view-all');
    if (viewAllBtn) {
        viewAllBtn.addEventListener('click', function(e) {
            this.style.pointerEvents = 'none';
            this.style.opacity = '0.7';
            // Re-enable after a short delay in case of navigation issues
            setTimeout(() => {
                this.style.pointerEvents = '';
                this.style.opacity = '';
            }, 2000);
        });
    }
    // Add click handlers to status cards
    document.querySelectorAll('.status-card').forEach(card => {
        card.addEventListener('click', function() {
            const status = this.dataset.status;
            window.location.href = `list.php?status=${encodeURIComponent(status)}`;
        });
    });
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php
require 'footer.php';  // ปิด container, โหลด Bootstrap JS
?>