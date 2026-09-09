<?php
session_start();

// ตรวจสอบการเข้าสู่ระบบ
if (!isset($_SESSION["user_id"]) || $_SESSION['role'] !== 'user') {
    header("Location: ../index.php");
    exit;
}
// รวมไฟล์เชื่อมต่อฐานข้อมูล PDO
require '../config/db.php';

$user_id = $_SESSION["user_id"];

// --- ส่วนของการคืนครุภัณฑ์ ---
if (isset($_POST['action']) && $_POST['action'] === 'return_asset' && isset($_POST['borrow_id'])) {
    $borrow_id = (int)$_POST['borrow_id'];

    try {
        $conn->beginTransaction(); // เริ่มต้น Transaction

        // 1. ดึงข้อมูลการยืมเพื่อหา asset_id
        $stmtBorrow = $conn->prepare("SELECT asset_id FROM borrow_history WHERE borrow_id = ? AND user_id = ? AND (status = 'อนุมัติ' OR status = 'ยืมอยู่' OR status = 'เกินวันที่กำหนด')");
        $stmtBorrow->execute([$borrow_id, $user_id]);
        $borrowInfo = $stmtBorrow->fetch(PDO::FETCH_ASSOC);

        if ($borrowInfo) {
            $asset_id = $borrowInfo['asset_id'];

            // 2. อัปเดตสถานะใน borrow_history เป็น 'คืนแล้ว'
            $stmtUpdateBorrow = $conn->prepare("UPDATE borrow_history SET status = 'คืนแล้ว', actual_return_date = NOW() WHERE borrow_id = ?");
            $stmtUpdateBorrow->execute([$borrow_id]);

            // 3. อัปเดตสถานะของครุภัณฑ์ในตาราง assets เป็น 'ใช้งานปกติ'
            $stmtUpdateAsset = $conn->prepare("UPDATE assets SET status = 'ใช้งานปกติ' WHERE asset_id = ?");
            $stmtUpdateAsset->execute([$asset_id]);

            // 4. บันทึก log การดำเนินการ
            $logDetails = json_encode([
                'action_borrow' => 'returned',
                'borrow_id' => $borrow_id,
                'asset_id' => $asset_id,
                'new_borrow_status' => 'คืนแล้ว',
                'new_asset_status' => 'ใช้งานปกติ'
            ], JSON_UNESCAPED_UNICODE);

            $logStmt = $conn->prepare("INSERT INTO asset_action_log (asset_id, action_type, user_id, details) VALUES (?, ?, ?, ?)");
            $logStmt->execute([$asset_id, 'borrow', $_SESSION['user_id'], $logDetails]);

        } else {
            throw new Exception("ไม่พบรายการยืมที่ระบุ หรือคุณไม่มีสิทธิ์คืนครุภัณฑ์นี้ (Borrow ID: $borrow_id)");
        }

        $conn->commit();
        $_SESSION['success_message'] = "คืนครุภัณฑ์สำเร็จ!";
        header("Location: my_borrow.php");
        exit;

    } catch (PDOException $e) {
        $conn->rollBack();
        $_SESSION['error_message'] = "เกิดข้อผิดพลาดในการคืนครุภัณฑ์: " . $e->getMessage();
        header("Location: my_borrow.php");
        exit;
    } catch (Exception $e) {
        $conn->rollBack();
        $_SESSION['error_message'] = "Application Error: " . $e->getMessage();
        header("Location: my_borrow.php");
        exit;
    }
}


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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
                                <button class="btn btn-primary btn-sm" onclick="showReturnModal(<?= $row['borrow_id'] ?>, '<?= htmlspecialchars($row['asset_code']) ?>', '<?= htmlspecialchars($row['asset_name']) ?>')">
                                    <i class="fas fa-undo"></i> คืนครุภัณฑ์
                                </button>
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

<div class="modal fade" id="returnModal" tabindex="-1" aria-labelledby="returnModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="returnModalLabel">
                    <i class="fas fa-undo-alt me-2"></i>ยืนยันการคืนครุภัณฑ์
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="return-icon">
                    <i class="fas fa-handshake"></i>
                </div>
                <h4 class="mb-3">คุณต้องการคืนครุภัณฑ์นี้หรือไม่?</h4>
                <div class="asset-info">
                    <h5>รายละเอียดครุภัณฑ์</h5>
                    <div class="row">
                        <div class="col-5 text-end"><strong>เลขครุภัณฑ์:</strong></div>
                        <div class="col-7 text-start asset-code" id="modalAssetCode"></div>
                    </div>
                    <div class="row mt-2">
                        <div class="col-5 text-end"><strong>ชื่อครุภัณฑ์:</strong></div>
                        <div class="col-7 text-start" id="modalAssetName"></div>
                    </div>
                </div>
                <div class="alert alert-info mt-3">
                    <i class="fas fa-info-circle me-2"></i>
                    เมื่อคืนครุภัณฑ์แล้ว สถานะจะเปลี่ยนเป็น "คืนแล้ว" และครุภัณฑ์จะพร้อมให้ผู้อื่นยืมต่อไป
                </div>
            </div>
            <div class="modal-footer justify-content-center">
                <button type="button" class="btn btn-cancel me-3" data-bs-dismiss="modal">
                    <i class="fas fa-times me-2"></i>ยกเลิก
                </button>
                <button type="button" class="btn btn-return text-white" onclick="confirmReturn()">
                    <i class="fas fa-check me-2"></i>ยืนยันการคืน
                </button>
            </div>
        </div>
    </div>
</div>

<form id="returnForm" method="post" style="display: none;">
    <input type="hidden" name="borrow_id" id="returnBorrowId">
    <input type="hidden" name="action" value="return_asset">
</form>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/js/all.min.js"></script>
<script>
let currentBorrowId = null;

function showReturnModal(borrowId, assetCode, assetName) {
    currentBorrowId = borrowId;
    document.getElementById('modalAssetCode').textContent = assetCode;
    document.getElementById('modalAssetName').textContent = assetName;
    
    // Show modal with animation
    const modal = new bootstrap.Modal(document.getElementById('returnModal'));
    modal.show();
}

function confirmReturn() {
    if (currentBorrowId) {
        document.getElementById('returnBorrowId').value = currentBorrowId;
        document.getElementById('returnForm').submit();
    }
}

// Add loading state when form is submitted
document.getElementById('returnForm').addEventListener('submit', function() {
    const confirmButton = document.querySelector('.btn-return');
    confirmButton.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>กำลังประมวลผล...';
    confirmButton.disabled = true;
});

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