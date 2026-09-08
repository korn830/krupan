<?php
require 'header.php';
require '../config/db.php';
if (!isset($_SESSION["user_id"]) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit;
}

// นับจำนวนครุภัณฑ์แต่ละสถานะ
$stmt = $conn->query("SELECT status, COUNT(*) AS total FROM assets GROUP BY status");
$status_summary = $stmt->fetchAll();

// จัดการสีและไอคอน
$colorMap = [
    // ต้องเป็นค่า hex จริง เพราะ Chart.js วาดบน <canvas> ซึ่งอ่าน var() ไม่ได้
    // ค่าเหล่านี้ตรงกับ --st-* ใน assets/css/tokens.css
    'ใช้งานปกติ' => '#4A7A55',
    'ชำรุด'      => '#A8503C',
    'ส่งซ่อม'    => '#A86B2E',
    'ถูกยืม'     => '#A8801F',
    'จำหน่าย'   => '#9A9284',
    'รออนุมัติ'  => '#3D8080',
];

$iconMap = [
    'ใช้งานปกติ' => '✅', 'ชำรุด' => '🔧', 'ซ่อมแซม' => '⚙️', 'ส่งซ่อม' => '🛠️',
    'เลิกใช้' => '📦', 'จำหน่าย' => '📤', 'รออนุมัติ' => '⏳', 'ถูกยืม' => '📚'
];

$status_map = [];
$chart_labels = [];
$chart_data = [];
$chart_colors = [];

foreach ($status_summary as $row) {
    $status_map[$row['status']] = $row['total'];
    if ($row['total'] > 0) {
        $chart_labels[] = $row['status'];
        $chart_data[] = $row['total'];
        $chart_colors[] = $colorMap[$row['status']] ?? '#9A9284';
    }
}

$all_statuses = ['ใช้งานปกติ', 'ชำรุด', 'ส่งซ่อม', 'จำหน่าย', 'ถูกยืม'];
$user_count = $conn->query("SELECT COUNT(*) FROM users WHERE role != 'admin'")->fetchColumn();
?>

<link rel="stylesheet" href="css/index.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<div class="welcome-header mb-4 fade-in">
    <h4 class="m-0"><i class="fas fa-chart-pie me-2"></i> แผงควบคุม (Dashboard)</h4>
    <p class="m-0 mt-2 text-white-50">ยินดีต้อนรับคุณ <?= htmlspecialchars($_SESSION["name"]) ?></p>
</div>

<div class="row fade-in">
    <div class="col-lg-8">
        <h5 class="summary-title">สรุปภาพรวมครุภัณฑ์</h5>
        <div class="status-cards">
            <?php foreach ($all_statuses as $status): ?>
                <div class="status-card" data-status="<?= htmlspecialchars($status) ?>" style="--card-color: <?= $colorMap[$status] ?? 'var(--kp-primary)' ?>;">
                    <div class="card-header">
                        <div class="card-icon" style="background: <?= $colorMap[$status] ?? 'var(--kp-primary)' ?>;">
                            <?= $iconMap[$status] ?? '📋' ?>
                        </div>
                        <h6 class="card-title"><?= htmlspecialchars($status) ?></h6>
                    </div>
                    <div class="card-count"><?= number_format($status_map[$status] ?? 0) ?></div>
                </div>
            <?php endforeach; ?>
            <div class="status-card" style="--card-color: var(--kp-body);">
                <div class="card-header">
                    <div class="card-icon" style="background: var(--kp-body);">👤</div>
                    <h6 class="card-title">ผู้ใช้ในระบบ</h6>
                </div>
                <div class="card-count"><?= number_format($user_count) ?></div>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card shadow-sm border-0 mb-4" style="border-radius: 16px; overflow: hidden;">
            <div class="card-header bg-white border-0 pt-4 pb-0 text-center">
                <h6 class="fw-bold" style="color: var(--kp-body);"><i class="fas fa-chart-doughnut me-1"></i> สัดส่วนสถานะครุภัณฑ์</h6>
            </div>
            <div class="card-body d-flex justify-content-center align-items-center" style="height: 300px;">
                <?php if (empty($chart_data)): ?>
                    <p class="text-muted">ยังไม่มีข้อมูลครุภัณฑ์</p>
                <?php else: ?>
                    <canvas id="statusChart"></canvas>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // กำหนดการคลิกที่การ์ดเพื่อไปหน้า list กรองตามสถานะ
    document.querySelectorAll('.status-card').forEach(card => {
        card.addEventListener('click', function() {
            window.location.href = `list.php?search=${encodeURIComponent(this.dataset.status)}`;
        });
    });

    // อนิเมชั่นตัวเลข
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

    // วาดกราฟวงกลม
    <?php if (!empty($chart_data)): ?>
    const ctx = document.getElementById('statusChart').getContext('2d');
    new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: <?= json_encode($chart_labels) ?>,
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
                legend: { position: 'bottom', labels: { font: { family: 'Prompt' } } }
            },
            cutout: '65%'
        }
    });
    <?php endif; ?>
});
</script>
<?php require 'footer.php'; ?>