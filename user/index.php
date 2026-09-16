<?php
// ตรวจสิทธิ์ให้เสร็จก่อนพ่น HTML ใด ๆ มิฉะนั้น header("Location:") จะใช้ไม่ได้
// เพราะส่ง output ออกไปแล้ว (รูปแบบเดียวกับ list.php)
session_start();
require_once __DIR__ . '/../assets/roles.php';
kp_require_cap('borrow.request', '../');
require '../config/db.php';

// สรุปการยืม "ของผู้ใช้คนนี้เท่านั้น"
// ผู้ใช้ทั่วไปไม่ต้องเห็นภาพรวมครุภัณฑ์ของทั้งวิทยาลัย นั่นเป็นงานของเจ้าหน้าที่พัสดุ
$stmt = $conn->prepare(
    "SELECT status, COUNT(*) AS total
       FROM borrow_history
      WHERE user_id = ?
      GROUP BY status"
);
$stmt->execute([$_SESSION['user_id']]);

$my_counts = [];
foreach ($stmt->fetchAll() as $row) {
    $my_counts[$row['status']] = (int)$row['total'];
}

$pending_count = $my_counts['รออนุมัติ'] ?? 0;
$overdue_count = $my_counts['เกินวันที่กำหนด'] ?? 0;

// 'อนุมัติ' กับ 'ยืมอยู่' รวมเป็นถังเดียว เพราะผู้ใช้มองว่าเป็น "ของที่ยังอยู่กับเรา" เหมือนกัน
$summary_cards = [
    ['label' => 'รออนุมัติ',     'icon' => '⏳', 'color' => '#f59e0b', 'count' => $pending_count],
    ['label' => 'กำลังยืม',      'icon' => '📚', 'color' => '#3b82f6', 'count' => ($my_counts['อนุมัติ'] ?? 0) + ($my_counts['ยืมอยู่'] ?? 0)],
    ['label' => 'เกินกำหนดคืน', 'icon' => '⚠️', 'color' => '#ef4444', 'count' => $overdue_count],
    ['label' => 'คืนแล้ว',       'icon' => '✅', 'color' => '#10b981', 'count' => $my_counts['คืนแล้ว'] ?? 0],
];

// รายการที่ยังไม่ได้คืน เรียงตามวันที่ครบกำหนดก่อน
$open = $conn->prepare(
    "SELECT bh.borrow_id, bh.return_date, bh.status,
            a.name AS asset_name, a.asset_code,
            DATEDIFF(bh.return_date, CURDATE()) AS days_left
       FROM borrow_history bh
       JOIN assets a ON bh.asset_id = a.asset_id
      WHERE bh.user_id = ?
        AND bh.status IN ('อนุมัติ', 'ยืมอยู่', 'เกินวันที่กำหนด')
      ORDER BY bh.return_date ASC
      LIMIT 5"
);
$open->execute([$_SESSION['user_id']]);
$open_borrows = $open->fetchAll();

/** แปลงวันที่เป็นรูปแบบไทย (พ.ศ.) */
function kp_thai_date(?string $date): string
{
    if (empty($date)) {
        return '-';
    }
    $ts = strtotime($date);
    return $ts ? date('d/m/', $ts) . (date('Y', $ts) + 543) : '-';
}

require 'header.php'; // พ่น <head> และแถบเมนู
?>

<?php require_once __DIR__ . '/../assets/kp_assets.php'; ?>
<link rel="stylesheet" href="<?= kp_asset('assets/css/shared/index.css', '../') ?>">

<div class="welcome-header mb-4 fade-in">
    <h4 class="m-0">
        <span class="user-icon">👋</span>
        ยินดีต้อนรับ, <?= htmlspecialchars($_SESSION["name"]) ?>
    </h4>
    <p class="m-0 mt-2 text-white-50">ระบบจัดการครุภัณฑ์ — วิทยาลัยการอาชีพวังไกลกังวล</p>
</div>

<?php if ($overdue_count > 0): ?>
<div class="alert alert-danger fade-in mb-3" role="alert" style="border-radius:12px; border-left:4px solid #ef4444;">
    <i class="fas fa-triangle-exclamation me-2"></i>
    คุณมีครุภัณฑ์ที่<strong>เกินกำหนดคืน</strong>อยู่ <strong><?= $overdue_count ?></strong> รายการ
    กรุณาติดต่อเจ้าหน้าที่พัสดุเพื่อคืนโดยเร็ว
    — <a href="my_borrow.php" class="alert-link">ดูรายการยืมของฉัน</a>
</div>
<?php endif; ?>

<?php if ($pending_count > 0): ?>
<div class="alert alert-warning fade-in mb-4" role="alert" style="border-radius:12px; border-left:4px solid #f59e0b;">
    <i class="fas fa-clock me-2"></i>
    คุณมีคำขอยืมที่รออนุมัติอยู่ <strong><?= $pending_count ?></strong> รายการ
    — <a href="my_borrow.php" class="alert-link">ดูรายการยืมของฉัน</a>
</div>
<?php endif; ?>

<div class="row fade-in">
    <div class="col-lg-7">
        <h5 class="summary-title">สรุปการยืมของฉัน</h5>
        <div class="status-cards">
            <?php foreach ($summary_cards as $card): ?>
                <div class="status-card" style="--card-color: <?= $card['color'] ?>;">
                    <div class="card-header">
                        <div class="card-icon" style="background: <?= $card['color'] ?>;">
                            <?= $card['icon'] ?>
                        </div>
                        <h6 class="card-title"><?= htmlspecialchars($card['label']) ?></h6>
                    </div>
                    <div class="card-count"><?= number_format($card['count']) ?></div>
                    <div class="card-label">รายการ</div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card shadow-sm border-0 mb-4" style="border-radius:16px; overflow:hidden;">
            <div class="card-header bg-white border-0 pt-4 pb-2">
                <h6 class="fw-bold m-0" style="color:#475569;">
                    <i class="fas fa-hourglass-half me-1"></i> ครุภัณฑ์ที่ยังไม่ได้คืน
                </h6>
            </div>
            <div class="card-body pt-2">
                <?php if (empty($open_borrows)): ?>
                    <p class="text-muted text-center my-4">ตอนนี้คุณไม่มีครุภัณฑ์ที่ต้องคืน</p>
                <?php else: ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($open_borrows as $b): ?>
                            <?php
                            $days = (int)$b['days_left'];
                            if ($b['status'] === 'เกินวันที่กำหนด' || $days < 0) {
                                $badge = 'bg-danger';
                                $text  = 'เกิน ' . abs($days) . ' วัน';
                            } elseif ($days === 0) {
                                $badge = 'bg-warning text-dark';
                                $text  = 'ครบกำหนดวันนี้';
                            } elseif ($days <= 3) {
                                $badge = 'bg-warning text-dark';
                                $text  = 'เหลือ ' . $days . ' วัน';
                            } else {
                                $badge = 'bg-success';
                                $text  = 'เหลือ ' . $days . ' วัน';
                            }
                            ?>
                            <li class="list-group-item px-0 d-flex justify-content-between align-items-center">
                                <div class="pe-2">
                                    <div class="fw-semibold" style="font-size:.95rem;">
                                        <?= htmlspecialchars($b['asset_name']) ?>
                                    </div>
                                    <small class="text-muted">
                                        <?= htmlspecialchars($b['asset_code']) ?>
                                        · คืนภายใน <?= kp_thai_date($b['return_date']) ?>
                                    </small>
                                </div>
                                <span class="badge <?= $badge ?>"><?= htmlspecialchars($text) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="action-section">
    <a href="list.php" class="btn-view-all">
        ยืมครุภัณฑ์ <span class="icon">→</span>
    </a>
    &nbsp;
    <a href="my_borrow.php" class="btn-view-all" style="background: linear-gradient(135deg,#764ba2,#667eea);">
        การยืมของฉัน <span class="icon">→</span>
    </a>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
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
});
</script>

<?php require 'footer.php'; ?>
