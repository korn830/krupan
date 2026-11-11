<?php
session_start();

// ตรวจสอบการเข้าสู่ระบบ
require_once __DIR__ . '/../assets/roles.php';
kp_require_cap('borrow.view.own', '../');
// รวมไฟล์เชื่อมต่อฐานข้อมูล PDO
require '../config/db.php';
require_once dirname(__DIR__) . '/assets/borrow_status.php';
// ปรับรายการที่เลยกำหนดคืนให้เป็น 'เกินวันที่กำหนด' ก่อนอ่านข้อมูลมาแสดง
kp_mark_overdue_borrows($conn);

$user_id = $_SESSION["user_id"];

// ขั้นตอนบันทึกการคืนย้ายไปอยู่ที่ confirm_return.php แล้ว
// หน้านั้นตรวจ CSRF และตรวจสิทธิ์เจ้าของรายการก่อนบันทึก ซึ่งของเดิมตรงนี้ไม่มี

// --- ส่วนของการดึงข้อมูลเพื่อแสดงผล (แก้ไขตรงนี้) ---
try {
    // เพิ่ม 'รออนุมัติ' และ 'ปฏิเสธ' เข้าไปใน IN (...)
    $stmt = $conn->prepare("SELECT bh.*, a.name AS asset_name, a.asset_code
        FROM borrow_history bh
        JOIN assets a ON bh.asset_id = a.asset_id
        WHERE bh.user_id = ? AND bh.status IN ('รออนุมัติ', 'อนุมัติ', 'ยืมอยู่', 'เกินวันที่กำหนด', 'คืนแล้ว', 'ปฏิเสธ')
        ORDER BY bh.created_at DESC");
    $stmt->execute([$user_id]);
    $borrows = $stmt->fetchAll();
} catch (PDOException $e) {
    die("Error fetching data: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รายการครุภัณฑ์ที่ยืม - ระบบจัดการครุภัณฑ์</title>
    <link href="../assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/vendor/fontawesome/css/all.min.css">
    <?php require_once __DIR__ . '/../assets/kp_assets.php'; ?>
<link rel="stylesheet" href="<?= kp_asset('user/css/my-borrow.css', '../') ?>">
</head>
<body>
<?php include(__DIR__ . '/header.php'); ?>
<div class="container mb-3 fade-in">
    <h4 class="mb-3">
        <i class="fas fa-box-open"></i>
        <span>รายการครุภัณฑ์ที่ยืม</span>
    </h4>

    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?= $_SESSION['success_message']; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['error_message'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?= $_SESSION['error_message']; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php unset($_SESSION['error_message']); ?>
    <?php endif; ?>

    <div class="table-responsive">
        <table class="table table-bordered table-hover">
            <thead>
                <tr>
                    <th>เลขครุภัณฑ์</th>
                    <th>ชื่อครุภัณฑ์</th>
                    <th>วันที่ยืม</th>
                    <th>กำหนดคืน</th>
                    <th>หมายเหตุ</th>
                    <th>เอกสารแนบ</th>
                    <th>สถานะ</th>
                    <th>จัดการ</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!empty($borrows)): ?>
                <?php foreach ($borrows as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['asset_code']) ?></td>
                        <td><?= htmlspecialchars($row['asset_name']) ?></td>
                        <td><?= htmlspecialchars($row['borrow_date']) ?></td>
                        <td><?= htmlspecialchars($row['return_date']) ?></td>
                        <td><?= htmlspecialchars($row['note'] ?? '-') ?></td>
                        <td>
                            <?php if (!empty($row['attachment_path'])): ?>
                                <a href="../uploads/borrow_docs/<?= htmlspecialchars($row['attachment_path']) ?>" target="_blank" rel="noopener" class="btn btn-outline-info btn-sm">
                                    <i class="fas fa-file-arrow-up"></i> ดูเอกสาร
                                </a>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                            $statusClass = '';
                            $statusText = $row['status']; // เก็บข้อความสถานะไว้ก่อน
                            switch ($row['status']) {
                                case 'รออนุมัติ': 
                                    $statusClass = 'bg-warning text-dark'; // ใช้ Class ของ Bootstrap โดยตรงเพื่อให้เห็นชัด
                                    break;
                                case 'อนุมัติ': $statusClass = 'status-approved'; break;
                                case 'ยืมอยู่': $statusClass = 'status-borrowing'; break;
                                case 'เกินวันที่กำหนด': $statusClass = 'status-overdue'; break;
                                case 'คืนแล้ว': $statusClass = 'status-returned'; break;
                                case 'ปฏิเสธ': $statusClass = 'status-rejected'; break;
                                default: $statusClass = 'bg-secondary text-white'; break;
                            }
                            ?>
                            <span class="badge rounded-pill <?= $statusClass ?>"><?= htmlspecialchars($statusText) ?></span>
                        </td>
                        <td>
                            <?php if ($row['status'] === 'อนุมัติ' || $row['status'] === 'ยืมอยู่' || $row['status'] === 'เกินวันที่กำหนด'): ?>
                                <a class="btn btn-primary btn-sm" href="confirm_return.php?borrow_id=<?= (int)$row['borrow_id'] ?>">
                                    <i class="fas fa-undo"></i> คืนครุภัณฑ์
                                </a>
                            <?php elseif ($row['status'] === 'รออนุมัติ'): ?>
                                <span class="text-muted small"><i class="fas fa-clock"></i> รอการตรวจสอบ</span>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="8" class="text-center empty-state">
                        <i class="fas fa-box-open icon"></i>
                        <h5>ยังไม่มีรายการครุภัณฑ์ที่คุณยืม</h5>
                        <p>คุณสามารถดูครุภัณฑ์ที่พร้อมให้ยืมได้ที่หน้าหลัก</p>
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <a href="index.php" class="btn btn-secondary mt-3"> <i class="fas fa-arrow-left"></i>  กลับหน้าหลัก</a>
</div>

<script src="../assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script>
// Add smooth animations
document.addEventListener('DOMContentLoaded', function() {
    const rows = document.querySelectorAll('tbody tr');
    rows.forEach((row, index) => {
        row.style.opacity = '0';
        row.style.transform = 'translateY(20px)';
        setTimeout(() => {
            row.style.transition = 'all 0.3s ease';
            row.style.opacity = '1';
            row.style.transform = 'translateY(0)';
        }, index * 100);
    });
});
</script>
</body>
<?php
require 'footer.php';
?>
</html>