<?php
session_start();

// ตรวจสอบการเข้าสู่ระบบและสิทธิ์การเป็น admin
if (!isset($_SESSION["user_id"]) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit;
}

// รวมไฟล์เชื่อมต่อฐานข้อมูล PDO
require '../config/db.php';

// --- ส่วนของการอนุมัติ/ปฏิเสธ ---
if (isset($_POST['action']) && isset($_POST['borrow_id'])) {
    $borrow_id = (int)$_POST['borrow_id'];
    $action = $_POST['action']; // 'approve' or 'reject'
    $newStatus = '';
    $assetStatus = '';
    $logActionType = 'borrow';

    // กำหนดสถานะใหม่และประเภท log ตาม action
    if ($action === 'approve') {
        $newStatus = 'อนุมัติ';
        $assetStatus = 'ถูกยืม';
    } elseif ($action === 'reject') {
        $newStatus = 'ปฏิเสธ';
        $assetStatus = 'ใช้งานปกติ';
    } else {
        header("Location: confirm_borrow.php");
        exit;
    }

    try {
        $conn->beginTransaction(); // เริ่มต้น Transaction

        // 1. ดึงข้อมูลการยืมเพื่อหา asset_id
        $stmtBorrow = $conn->prepare("SELECT asset_id FROM borrow_history WHERE borrow_id = ?");
        $stmtBorrow->execute([$borrow_id]);
        $borrowInfo = $stmtBorrow->fetch(PDO::FETCH_ASSOC);

        if ($borrowInfo) {
            $asset_id = $borrowInfo['asset_id'];

            // 2. อัปเดตสถานะใน borrow_history
            $stmtUpdateBorrow = $conn->prepare("UPDATE borrow_history SET status = ? WHERE borrow_id = ?");
            $stmtUpdateBorrow->execute([$newStatus, $borrow_id]);

            // 3. อัปเดตสถานะของครุภัณฑ์ในตาราง assets
            $stmtUpdateAsset = $conn->prepare("UPDATE assets SET status = ? WHERE asset_id = ?");
            $stmtUpdateAsset->execute([$assetStatus, $asset_id]);

            // 4. บันทึก log การดำเนินการ
            $logDetails = json_encode([
                'action_borrow' => $action, 
                'borrow_id' => $borrow_id,
                'asset_id' => $asset_id,
                'new_borrow_status' => $newStatus,
                'new_asset_status' => $assetStatus
            ], JSON_UNESCAPED_UNICODE);

            $logStmt = $conn->prepare("INSERT INTO asset_action_log (asset_id, action_type, user_id, details) VALUES (?, ?, ?, ?)");
            $logStmt->execute([$asset_id, $logActionType, $_SESSION['user_id'], $logDetails]);

        } else {
            throw new Exception("ไม่พบรายการยืมที่ระบุ (Borrow ID: $borrow_id)");
        }

        $conn->commit(); // ยืนยันการเปลี่ยนแปลงทั้งหมด
        header("Location: confirm_borrow.php");
        exit;

    } catch (PDOException $e) {
        $conn->rollBack(); 
        die("Error processing request: " . $e->getMessage());
    } catch (Exception $e) {
        $conn->rollBack();
        die("Application Error: " . $e->getMessage());
    }
}

// --- ส่วนของการดึงข้อมูลเพื่อแสดงผล (แก้ไขดึงชื่อแบบ Real-time) ---
try {
    // เพิ่มการ JOIN ตาราง users เพื่อดึงชื่อปัจจุบันมาแสดง
    $stmt = $conn->query("SELECT bh.*, a.name AS asset_name, a.asset_code, u.name AS current_user_name
        FROM borrow_history bh
        JOIN assets a ON bh.asset_id = a.asset_id
        LEFT JOIN users u ON bh.user_id = u.user_id
        WHERE bh.status = 'รออนุมัติ'
        ORDER BY bh.created_at DESC");
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
    <title>ยืนยันการยืมครุภัณฑ์ - ระบบจัดการครุภัณฑ์</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <?php require_once __DIR__ . '/../assets/kp_assets.php'; ?>
<link rel="stylesheet" href="<?= kp_asset('assets/css/shared/confirm_borrow.css', '../') ?>">
</head>
<body>
<?php include(__DIR__ . '/header.php'); ?>
<div class="container mb-3 fade-in">
    <div class="page-header mb-3">
        <h4><i class="fas fa-clipboard checked"></i><span>ยืนยันการยืมครุภัณฑ์</span></h4>
    </div>
    <div class="table-responsive mb-3">
        <table class="table table-bordered table-hover">
            <thead>
                <tr>
                    <th>เลขครุภัณฑ์</th>
                    <th>ชื่อครุภัณฑ์</th>
                    <th>ผู้ยืม</th>
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
                        <td><?= htmlspecialchars(!empty($row['current_user_name']) ? $row['current_user_name'] : $row['borrower_name']) ?></td>
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
                            switch ($row['status']) {
                                case 'รออนุมัติ': $statusClass = 'status-pending'; break;
                                case 'อนุมัติ': $statusClass = 'status-approved'; break;
                                case 'ปฏิเสธ': $statusClass = 'status-rejected'; break;
                                case 'ยืมอยู่': $statusClass = 'status-borrowing'; break;
                                case 'เกินวันที่กำหนด': $statusClass = 'status-overdue'; break;
                                default: $statusClass = ''; break;
                            }
                            ?>
                            <span class="status-badge <?= $statusClass ?>"><?= htmlspecialchars($row['status']) ?></span>
                        </td>
                        <td>
                            <button type="button" class="btn btn-success btn-sm me-1" onclick="showApproveModal(<?= htmlspecialchars($row['borrow_id']) ?>)">
                                <i class="fas fa-check"></i> อนุมัติ
                            </button>
                            <button type="button" class="btn btn-danger btn-sm me-1" onclick="showRejectModal(<?= htmlspecialchars($row['borrow_id']) ?>)">
                                <i class="fas fa-times"></i> ปฏิเสธ
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="9" class="text-center empty-state">
                        <i class="fas fa-hand-holding-box icon"></i>
                        <h5>ไม่มีรายการยืมครุภัณฑ์ที่รอการอนุมัติ</h5>
                        <p>เมื่อมีผู้ยื่นคำขอยืมครุภัณฑ์ รายการจะปรากฏที่นี่</p>
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <a href="index.php" class="btn btn-secondary mt-3"> <i class="fas fa-arrow-left icon"></i>
        กลับหน้าหลัก
    </a>
</div>

<div class="modal fade" id="approveModal" tabindex="-1" aria-labelledby="approveModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-success text-white">
        <h5 class="modal-title" id="approveModalLabel"><i class="fas fa-check-circle me-2"></i> ยืนยันการอนุมัติ</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        คุณต้องการอนุมัติการยืมครุภัณฑ์นี้หรือไม่?
      </div>
      <div class="modal-footer">
        <form id="approveForm" method="post">
          <input type="hidden" name="borrow_id" id="modal_borrow_id">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
          <button name="action" value="approve" type="submit" class="btn btn-success">ยืนยันอนุมัติ</button>
        </form>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="rejectModal" tabindex="-1" aria-labelledby="rejectModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title" id="rejectModalLabel"><i class="fas fa-times-circle me-2"></i> ยืนยันการปฏิเสธ</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        คุณต้องการปฏิเสธการยืมครุภัณฑ์นี้หรือไม่?
      </div>
      <div class="modal-footer">
        <form id="rejectForm" method="post">
          <input type="hidden" name="borrow_id" id="modal_reject_borrow_id">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
          <button name="action" value="reject" type="submit" class="btn btn-danger">ยืนยันปฏิเสธ</button>
        </form>
      </div>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function showApproveModal(borrowId) {
    setTimeout(function() {
        document.getElementById('modal_borrow_id').value = borrowId;
        var modal = new bootstrap.Modal(document.getElementById('approveModal'));
        modal.show();
    }, 100);
}
function showRejectModal(borrowId) {
    setTimeout(function() {
        document.getElementById('modal_reject_borrow_id').value = borrowId;
        var modal = new bootstrap.Modal(document.getElementById('rejectModal'));
        modal.show();
    }, 100);
}
function showCancelModal(borrowId) {
    setTimeout(function() {
        document.getElementById('modal_cancel_borrow_id').value = borrowId;
        var modal = new bootstrap.Modal(document.getElementById('cancelModal'));
        modal.show();
    }, 100);
}
</script>
</body>
<?php
require 'footer.php';  // ปิด container, โหลด Bootstrap JS
?>
</html>