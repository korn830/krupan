<?php
require 'header.php';
require '../config/db.php';

if (!isset($_SESSION["user_id"]) || $_SESSION['role'] !== 'user') {
    header("Location: ../index.php");
    exit;
}
$user_id = (int)$_SESSION['user_id'];

/* --- แปลงวันที่เป็นรูปแบบไทย พ.ศ. --- */
function kp_thai_date(?string $ymd): string {
    static $mon = ['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
    $d = $ymd ? DateTimeImmutable::createFromFormat('!Y-m-d', substr($ymd, 0, 10)) : false;
    if (!$d) return '-';
    return (int)$d->format('j') . ' ' . $mon[(int)$d->format('n') - 1] . ' ' . ((int)$d->format('Y') + 543);
}

/* --- เหลืออีกกี่วันถึงกำหนดคืน (ติดลบ = เกินกำหนด) --- */
function kp_days_left(?string $ymd): ?int {
    if (!$ymd) return null;
    $due = DateTimeImmutable::createFromFormat('!Y-m-d', substr($ymd, 0, 10));
    if (!$due) return null;
    return (int)(new DateTimeImmutable('today'))->diff($due)->format('%r%a');
}

/* --- รายการยืมของผู้ใช้คนนี้เท่านั้น ไม่ใช่สถิติทั้งวิทยาลัย --- */
$stmtMine = $conn->prepare(
    "SELECT b.borrow_id, b.borrow_date, b.return_date, b.status,
            a.asset_id, a.asset_code, a.name AS asset_name
     FROM borrow_history b
     JOIN assets a ON a.asset_id = b.asset_id
     WHERE b.user_id = ?
       AND b.status IN ('อนุมัติ', 'ยืมอยู่', 'เกินวันที่กำหนด', 'รออนุมัติ')
     ORDER BY b.return_date ASC"
);
$stmtMine->execute([$user_id]);
$myBorrows = $stmtMine->fetchAll(PDO::FETCH_ASSOC);

$active = [];   // กำลังยืมอยู่จริง (คืนได้)
$pending = [];  // ยังรออนุมัติ
foreach ($myBorrows as $row) {
    if ($row['status'] === 'รออนุมัติ') { $pending[] = $row; continue; }
    $row['days_left'] = kp_days_left($row['return_date']);
    $row['is_late']   = $row['status'] === 'เกินวันที่กำหนด'
                        || ($row['days_left'] !== null && $row['days_left'] < 0);
    $active[] = $row;
}
// เกินกำหนดขึ้นก่อน แล้วเรียงตามวันที่ใกล้ครบกำหนด
usort($active, function ($x, $y) {
    if ($x['is_late'] !== $y['is_late']) return $x['is_late'] ? -1 : 1;
    return ($x['days_left'] ?? PHP_INT_MAX) <=> ($y['days_left'] ?? PHP_INT_MAX);
});
$lateCount = count(array_filter($active, fn($r) => $r['is_late']));

/* --- ครุภัณฑ์ที่ยืมได้ตอนนี้ --- */
$stmtAvail = $conn->query(
    "SELECT a.asset_id, a.asset_code, a.name, c.name AS category_name, l.name AS location_name
     FROM assets a
     LEFT JOIN categories c ON c.category_id = a.category_id
     LEFT JOIN locations  l ON l.location_id = a.location_id
     WHERE a.borrowable_status = 'สามารถยืมได้' AND a.status = 'ใช้งานปกติ'
     ORDER BY a.asset_id DESC
     LIMIT 4"
);
$available = $stmtAvail->fetchAll(PDO::FETCH_ASSOC);
?>

<link rel="stylesheet" href="css/index.css">

<div class="kp-page-head">
    <div>
        <h1 class="kp-h1">สวัสดี <?= htmlspecialchars($_SESSION['name'] ?? 'ผู้ใช้') ?></h1>
        <div class="kp-rule"></div>
    </div>
    <a href="list.php" class="kp-btn-solid">ค้นหาครุภัณฑ์ที่ต้องการยืม</a>
</div>

<?php if (!empty($_SESSION['success_message'])): ?>
    <div class="alert alert-success"><?= htmlspecialchars($_SESSION['success_message']) ?></div>
    <?php unset($_SESSION['success_message']); ?>
<?php endif; ?>

<!-- ตัวเลขของฉันเอง ไม่ใช่ของทั้งวิทยาลัย -->
<div class="kp-minibar">
    <div class="kp-mini"><span class="kp-mini-n num"><?= count($active) - $lateCount ?></span> กำลังยืม</div>
    <div class="kp-mini"><span class="kp-mini-n num"><?= count($pending) ?></span> รออนุมัติ</div>
    <div class="kp-mini"><span class="kp-mini-n num<?= $lateCount ? ' is-late' : '' ?>"><?= $lateCount ?></span> เกินกำหนดคืน</div>
</div>

<?php if ($lateCount): ?>
<div class="kp-note kp-note-bad">
    <i class="fas fa-exclamation-circle"></i>
    <div>คุณมีครุภัณฑ์ <strong class="num"><?= $lateCount ?></strong> รายการที่เกินกำหนดคืนแล้ว กรุณาติดต่องานพัสดุ</div>
</div>
<?php endif; ?>

<div class="kp-cols">
    <!-- ของที่ยืมอยู่ -->
    <section class="card">
        <div class="kp-card-h">
            <div class="kp-card-t">ครุภัณฑ์ที่ฉันยืมอยู่</div>
            <a href="my_borrow.php" class="kp-quiet">ดูประวัติทั้งหมด</a>
        </div>

        <?php if (!$active): ?>
            <p class="kp-empty">ยังไม่มีครุภัณฑ์ที่ยืมอยู่</p>
        <?php else: foreach ($active as $i => $r):
            $d = $r['days_left'];
            $late = $r['is_late'];
            $soon = !$late && $d !== null && $d <= 2;
            if ($late)            $when = 'เกินกำหนด ' . abs($d) . ' วัน';
            elseif ($d === 0)     $when = 'ครบกำหนดวันนี้';
            elseif ($d === null)  $when = '';
            else                  $when = 'เหลืออีก ' . $d . ' วัน';
        ?>
            <div class="kp-row<?= $i === count($active) - 1 ? ' is-last' : '' ?>">
                <div class="kp-row-main">
                    <div class="kp-row-name"><?= htmlspecialchars($r['asset_name']) ?></div>
                    <div class="kp-row-meta">
                        <span class="num"><?= htmlspecialchars($r['asset_code']) ?></span>
                        · คืนภายใน <span class="num"><?= kp_thai_date($r['return_date']) ?></span>
                    </div>
                </div>
                <div class="kp-row-side">
                    <div class="kp-when<?= $late ? ' is-late' : ($soon ? ' is-soon' : '') ?>"><?= $when ?></div>
                    <form method="POST" action="my_borrow.php" class="mt-2">
                        <input type="hidden" name="action" value="return_asset">
                        <input type="hidden" name="borrow_id" value="<?= (int)$r['borrow_id'] ?>">
                        <button type="submit" class="btn-outline-primary btn-sm">คืนครุภัณฑ์</button>
                    </form>
                </div>
            </div>
        <?php endforeach; endif; ?>

        <?php if ($pending): ?>
            <div class="kp-row-foot">
                และมีคำขออีก <strong class="num"><?= count($pending) ?></strong> รายการที่รอผู้ดูแลอนุมัติ
            </div>
        <?php endif; ?>
    </section>

    <!-- ยืมได้ตอนนี้ -->
    <section class="card">
        <div class="kp-card-h">
            <div class="kp-card-t">ยืมได้ตอนนี้</div>
            <a href="list.php" class="kp-quiet">ดูทั้งหมด</a>
        </div>
        <?php if (!$available): ?>
            <p class="kp-empty">ตอนนี้ยังไม่มีครุภัณฑ์ที่พร้อมให้ยืม</p>
        <?php else: foreach ($available as $i => $a): ?>
            <div class="kp-row<?= $i === count($available) - 1 ? ' is-last' : '' ?>">
                <div class="kp-row-main">
                    <div class="kp-row-name"><?= htmlspecialchars($a['name']) ?></div>
                    <div class="kp-row-meta">
                        <?= htmlspecialchars($a['category_name'] ?? '-') ?> · <?= htmlspecialchars($a['location_name'] ?? '-') ?>
                    </div>
                </div>
                <a href="borrow_request.php?id=<?= (int)$a['asset_id'] ?>" class="btn-outline-primary btn-sm">ยืม</a>
            </div>
        <?php endforeach; endif; ?>
    </section>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
