<?php
session_start();
if (!isset($_SESSION["user_id"])) {
    header("Location: ../index.php");
    exit;
}
require_once dirname(__DIR__) . '/config/db.php';

$asset_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// ดึงข้อมูลตามโครงสร้างตาราง assets
$stmt = $conn->prepare("SELECT a.*, c.name as cat_name FROM assets a 
                        LEFT JOIN categories c ON a.category_id = c.category_id 
                        WHERE a.asset_id = ?");
$stmt->execute([$asset_id]);
$asset = $stmt->fetch();

if (!$asset) {
    die("ไม่พบข้อมูลครุภัณฑ์");
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ขอยืม - <?php echo htmlspecialchars($asset['name']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-light">
    <div class="container py-5">
        <div class="card shadow mx-auto" style="max-width: 500px; border-radius: 15px; border: none;">
            <?php if ($asset['image_url']): ?>
                <img src="../uploads/<?php echo $asset['image_url']; ?>" style="width:100%; height:250px; object-fit:cover;">
            <?php endif; ?>
            <div class="card-body p-4">
                <h4 class="fw-bold text-primary"><?php echo htmlspecialchars($asset['name']); ?></h4>
                <p class="text-muted small">เลขครุภัณฑ์: <?php echo htmlspecialchars($asset['asset_code']); ?></p>
                <hr>
                <p><strong>สถานะครุภัณฑ์:</strong> 
                    <span class="badge <?php echo $asset['status'] == 'ใช้งานปกติ' ? 'bg-success' : 'bg-danger'; ?>">
                        <?php echo $asset['status']; ?>
                    </span>
                </p>

                <?php if ($asset['status'] == 'ใช้งานปกติ' && $asset['borrowable_status'] == 'สามารถยืมได้'): ?>
                    <form action="process_borrow.php" method="POST" class="mt-4">
                        <input type="hidden" name="asset_id" value="<?php echo $asset_id; ?>">
                        <div class="mb-3">
                            <label class="form-label fw-bold">วันที่ต้องการคืน</label>
                            <input type="date" name="return_date" class="form-control" required min="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">เหตุผลหรือบันทึกเพิ่มเติม</label>
                            <textarea name="note" class="form-control" rows="3" placeholder="ระบุเหตุผลการยืม..."></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary w-100 py-2 fw-bold">ส่งคำขอยืม</button>
                    </form>
                <?php else: ?>
                    <div class="alert alert-warning text-center mt-3">ขออภัย ครุภัณฑ์ชิ้นนี้ไม่พร้อมให้ยืมในขณะนี้</div>
                <?php endif; ?>
                <a href="index.php" class="btn btn-link w-100 mt-2 text-muted text-decoration-none">กลับหน้าหลัก</a>
            </div>
        </div>
    </div>
</body>
</html>