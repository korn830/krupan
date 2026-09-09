<?php
require 'header.php';
require '../config/db.php';
if (!isset($_SESSION["user_id"]) || $_SESSION['role'] !== 'user') {
    header("Location: ../index.php");
    exit;
}

// นับจำนวนครุภัณฑ์แต่ละสถานะ
$stmt = $conn->query("SELECT status, COUNT(*) AS total FROM assets GROUP BY status");
$status_summary = $stmt->fetchAll();

$colorMap = [
    'ใช้งานปกติ' => '#10b981',
    'ชำรุด'      => '#ef4444',
    'ส่งซ่อม'    => '#f59e0b',
    'ถูกยืม'     => '#3b82f6',
    'จำหน่าย'   => '#6b7280',
    'รออนุมัติ'  => '#8b5cf6',
];

$iconMap = [
    'ใช้งานปกติ' => '✅',
    'ชำรุด'      => '🔧',
    'ส่งซ่อม'    => '🛠️',
    'ถูกยืม'     => '📚',
    'จำหน่าย'   => '📤',
    'รออนุมัติ'  => '⏳',
];

$status_map    = [];
$chart_labels  = [];
$chart_data    = [];
$chart_colors  = [];

foreach ($status_summary as $row) {
    $status_map[$row['status']] = $row['total'];
    if ($row['total'] > 0) {
        $chart_labels[] = $row['status'];
        $chart_data[]   = $row['total'];
        $chart_colors[] = $colorMap[$row['status']] ?? '#cbd5e1';
    }
}

$all_statuses = ['ใช้งานปกติ', 'ชำรุด', 'ส่งซ่อม', 'ถูกยืม', 'จำหน่าย', 'รออนุมัติ'];

// คำขอยืมของฉันที่ยังรออนุมัติ
$my_pending = $conn->prepare("SELECT COUNT(*) FROM borrow_history WHERE user_id = ? AND status = 'รออนุมัติ'");
$my_pending->execute([$_SESSION['user_id']]);
$pending_count = (int)$my_pending->fetchColumn();
?>

<?php require_once __DIR__ . '/../assets/kp_assets.php'; ?>
<link rel="stylesheet" href="<?= kp_asset('assets/css/shared/index.css', '../') ?>">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<div class="welcome-header mb-4 fade-in">
    <h4 class="m-0">
        <span class="user-icon">👋</span>
        ยินดีต้อนรับ, <?= htmlspecialchars($_SESSION["name"]) ?>
    </h4>
    <p class="m-0 mt-2 text-white-50">ระบบจัดการครุภัณฑ์ — วิทยาลัยการอาชีพวังไกลกังวล</p>
</div>

<?php if ($pending_count > 0): ?>
<div class="alert alert-warning fade-in mb-4" role="alert" style="border-radius:12px; border-left:4px solid #f59e0b;">
    <i class="fas fa-clock me-2"></i>
    คุณมีคำขอยืมที่รออนุมัติอยู่ <strong><?= $pending_count ?></strong> รายการ
    — <a href="my_borrow.php" class="alert-link">ดูรายการยืมของฉัน</a>
</div>
<?php endif; ?>

<div class="row fade-in">
    <div class="col-lg-8">
        <h5 class="summary-title">สรุปภาพรวมครุภัณฑ์</h5>
        <div class="status-cards">
            <?php foreach ($all_statuses as $status): ?>
                <div class="status-card" data-status="<?= htmlspecialchars($status) ?>"
                     style="--card-color: <?= $colorMap[$status] ?? '#667eea' ?>;">
                    <div class="card-header">
                        <div class="card-icon" style="background: <?= $colorMap[$status] ?? '#667eea' ?>;">
                            <?= $iconMap[$status] ?? '📋' ?>
                        </div>
                        <h6 class="card-title"><?= htmlspecialchars($status) ?></h6>
                    </div>
                    <div class="card-count"><?= number_format($status_map[$status] ?? 0) ?></div>
                    <div class="card-label">รายการ</div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card shadow-sm border-0 mb-4" style="border-radius:16px; overflow:hidden;">
            <div class="card-header bg-white border-0 pt-4 pb-0 text-center">
                <h6 class="fw-bold" style="color:#475569;">
                    <i class="fas fa-chart-pie me-1"></i> สัดส่วนสถานะครุภัณฑ์
                </h6>
            </div>
            <div class="card-body d-flex justify-content-center align-items-center" style="height:280px;">
                <?php if (empty($chart_data)): ?>
                    <p class="text-muted">ยังไม่มีข้อมูลครุภัณฑ์</p>
                <?php else: ?>
                    <canvas id="statusChart"></canvas>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="action-section">
    <a href="list.php" class="btn-view-all">
        ดูรายการครุภัณฑ์ทั้งหมด <span class="icon">→</span>
    </a>
    &nbsp;
    <a href="my_borrow.php" class="btn-view-all" style="background: linear-gradient(135deg,#764ba2,#667eea);">
        การยืมของฉัน <span class="icon">→</span>
    </a>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    // คลิกการ์ดเพื่อกรองรายการครุภัณฑ์
    document.querySelectorAll('.status-card').forEach(card => {
        card.style.cursor = 'pointer';
        card.addEventListener('click', function () {
            window.location.href = 'list.php?search=' + encodeURIComponent(this.dataset.status);
        });
    });

    // นับขึ้น animation
    document.querySelectorAll('.card-count').forEach(counter => {
        const target = parseInt(counter.textContent.replace(/,/g, ''));
        let current = 0;
        const increment = Math.ceil(target / 30) || 1;
        const timer = setInterval(() => {
            current += increment;
            if (current >= target) {
                counter.textContent = target.toLocaleString();
                clearInterval(timer);
            } else {
                counter.textContent = current.toLocaleString();
            }
        }, 30);
    });

    <?php if (!empty($chart_data)): ?>
    const ctx = document.getElementById('statusChart').getContext('2d');
    new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: <?= json_encode($chart_labels, JSON_UNESCAPED_UNICODE) ?>,
            datasets: [{
                data: <?= json_encode($chart_data) ?>,
                backgroundColor: <?= json_encode($chart_colors) ?>,
                borderWidth: 2,
                hoverOffset: 5
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom', labels: { font: { family: 'Sarabun' } } }
            },
            cutout: '65%'
        }
    });
    <?php endif; ?>
});
</script>

<?php require 'footer.php'; ?>
