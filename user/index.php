<?php
// ตรวจสิทธิ์ให้เสร็จก่อนพ่น HTML ใด ๆ มิฉะนั้น header("Location:") จะใช้ไม่ได้
// เพราะส่ง output ออกไปแล้ว (รูปแบบเดียวกับ list.php)
session_start();
if (!isset($_SESSION["user_id"]) || $_SESSION['role'] !== 'user') {
    header("Location: ../index.php");
    exit;
}
require '../config/db.php';
require_once dirname(__DIR__) . '/assets/borrow_status.php';
// ปรับรายการที่เลยกำหนดคืนให้เป็น 'เกินวันที่กำหนด' ก่อนอ่านข้อมูลมาแสดง
kp_mark_overdue_borrows($conn);

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

// สรุปการยืมของผู้ใช้คนนี้ แยกตามสถานะ
$my_summary     = kp_my_borrow_summary($conn, (int)$_SESSION['user_id']);
$pending_count  = $my_summary['pending'];
$approved_count = $my_summary['approved'];
$overdue_count  = $my_summary['overdue'];
$my_open        = kp_my_open_borrows($conn, (int)$_SESSION['user_id']);

require 'header.php'; // พ่น <head> และแถบเมนู
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

<?php if ($overdue_count > 0): ?>
<div class="alert alert-danger fade-in mb-3" role="alert" style="border-radius:12px; border-left:4px solid #e53e3e;">
    <i class="fas fa-exclamation-circle me-2"></i>
    คุณมีครุภัณฑ์ <strong><?= $overdue_count ?></strong> รายการที่เกินกำหนดคืนแล้ว
    — <a href="my_borrow.php" class="alert-link">ดูรายการและคืนครุภัณฑ์</a>
</div>
<?php endif; ?>

<!-- สรุปการยืมของฉัน -->
<div class="card fade-in mb-4" style="border-radius:12px;">
  <div class="card-body">
    <h5 class="card-title mb-3"><i class="fas fa-hand-holding me-2"></i>การยืมของฉัน</h5>

    <div class="row text-center mb-3">
      <div class="col-4 border-end">
        <div class="h3 mb-0"><?= $pending_count ?></div>
        <div class="text-muted small">รออนุมัติ</div>
      </div>
      <div class="col-4 border-end">
        <div class="h3 mb-0"><?= $approved_count ?></div>
        <div class="text-muted small">กำลังยืม</div>
      </div>
      <div class="col-4">
        <div class="h3 mb-0<?= $overdue_count > 0 ? ' text-danger' : '' ?>"><?= $overdue_count ?></div>
        <div class="text-muted small">เกินกำหนดคืน</div>
      </div>
    </div>

    <?php if (empty($my_open)): ?>
      <p class="text-muted text-center mb-0 py-2">ยังไม่มีรายการยืมที่ดำเนินการอยู่</p>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead>
            <tr><th>เลขครุภัณฑ์</th><th>ชื่อครุภัณฑ์</th><th>กำหนดคืน</th><th>สถานะ</th></tr>
          </thead>
          <tbody>
          <?php foreach ($my_open as $row):
              $d = $row['days_left'];
              if ($row['status'] === 'เกินวันที่กำหนด') {
                  $when = 'เกินกำหนด ' . abs((int)$d) . ' วัน'; $tone = 'text-danger';
              } elseif ($row['status'] === 'รออนุมัติ') {
                  $when = 'รอผู้ดูแลอนุมัติ'; $tone = 'text-muted';
              } elseif ($d === null) {
                  $when = '-'; $tone = 'text-muted';
              } elseif ((int)$d === 0) {
                  $when = 'ครบกำหนดวันนี้'; $tone = 'text-warning';
              } else {
                  $when = 'เหลืออีก ' . (int)$d . ' วัน';
                  $tone = (int)$d <= 2 ? 'text-warning' : 'text-muted';
              }
          ?>
            <tr>
              <td><?= htmlspecialchars($row['asset_code']) ?></td>
              <td><?= htmlspecialchars($row['asset_name']) ?></td>
              <td><?= htmlspecialchars((string)$row['return_date']) ?></td>
              <td>
                <?php
                  $badge = match ($row['status']) {
                      'เกินวันที่กำหนด' => 'bg-danger',
                      'รออนุมัติ'       => 'bg-primary',
                      'อนุมัติ', 'ยืมอยู่' => 'bg-success',
                      default            => 'bg-secondary',
                  };
                ?>
                <span class="badge <?= $badge ?>"><?= htmlspecialchars($row['status']) ?></span>
                <div class="small <?= $tone ?>"><?= htmlspecialchars($when) ?></div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="text-end mt-2"><a href="my_borrow.php" class="small">ดูประวัติการยืมทั้งหมด</a></div>
    <?php endif; ?>
  </div>
</div>

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
