<?php
session_start();
if (!isset($_SESSION["user_id"]) || $_SESSION['role'] !== 'user') {
    header("Location: ../index.php");
    exit;
}
require dirname(__DIR__) . '/config/db.php'; // ตรวจสอบเส้นทางให้แน่ใจว่าถูกต้อง

$uploadDir = '../uploads/';
$errors = []; // Initialize an array to store error messages

// Add this PHP block at the top of the file to handle asset data fetching
// Modified to join tables to get names for categories, locations, departments
if (isset($_GET['fetch_asset']) && isset($_GET['id'])) {
    $asset_id = (int)$_GET['id']; // Cast to int for safety
    $stmt = $conn->prepare("SELECT a.*, c.name AS category_name, l.name AS location_name, d.name AS department_name
                            FROM assets a
                            LEFT JOIN categories c ON a.category_id = c.category_id
                            LEFT JOIN locations l ON a.location_id = l.location_id
                            LEFT JOIN departments d ON a.department_id = d.department_id
                            WHERE a.asset_id = ?");
    $stmt->execute([$asset_id]);
    $asset = $stmt->fetch(PDO::FETCH_ASSOC);

    // ตรวจสอบข้อมูลครุภัณฑ์และสถานะสำหรับการยืม
    if ($asset) {
        // เงื่อนไขหลักในการอนุญาตให้ยืม:
        // 1. borrowable_status ต้องเป็น 'สามารถยืมได้'
        // 2. status ต้องไม่ใช่ 'ถูกยืม'
        // 3. status ต้องไม่ใช่ 'รออนุมัติ'
        if ($asset['borrowable_status'] === 'สามารถยืมได้' && $asset['status'] !== 'ถูกยืม' && $asset['status'] !== 'รออนุมัติ') {
            echo json_encode($asset); // ส่งข้อมูลครุภัณฑ์กลับไปเป็น JSON
        } else {
            // กรณีที่ไม่สามารถยืมได้ ให้ส่งข้อความ error กลับไป
            $error_message = 'ครุภัณฑ์ไม่พร้อมให้ยืม';
            if ($asset['borrowable_status'] !== 'สามารถยืมได้') {
                $error_message = 'ครุภัณฑ์นี้ถูกตั้งค่าว่าไม่สามารถยืมได้โดยผู้ดูแลระบบ.';
            } else if ($asset['status'] === 'ถูกยืม') {
                $error_message = 'ครุภัณฑ์นี้ถูกยืมอยู่.';
            } else if ($asset['status'] === 'รออนุมัติ') {
                $error_message = 'ครุภัณฑ์นี้อยู่ระหว่างรอการอนุมัติการยืม.';
            } else {
                // สำหรับสถานะอื่นๆ ที่ไม่พร้อมให้ยืม เช่น ชำรุด, เลิกใช้, จำหน่าย
                $error_message = 'ครุภัณฑ์ไม่พร้อมให้ยืม เนื่องจากสถานะคือ: ' . htmlspecialchars($asset['status']);
            }
            echo json_encode(['error' => $error_message]);
        }
    } else {
        // กรณีไม่พบครุภัณฑ์
        echo json_encode(['error' => 'ไม่พบครุภัณฑ์ที่ระบุ']);
    }
    exit; // *** สำคัญมาก: ต้องมี exit; เพื่อหยุดการประมวลผลหน้าเว็บที่เหลือ
} elseif ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['asset_id']) && isset($_POST['borrower_name']) && isset($_POST['borrow_date']) && isset($_POST['return_date'])) {
    $asset_id = (int)$_POST['asset_id'];
    $borrower_name = trim($_POST['borrower_name']);
    $borrow_date = $_POST['borrow_date'];
    $return_date = $_POST['return_date'];
    $notes = trim($_POST['notes'] ?? '');
    $user_id = $_SESSION['user_id']; // Get the user_id from session

    // Server-side validation for asset existence and status
    // ดึงทั้ง status และ borrowable_status เพื่อตรวจสอบ
    $stmtCheckAsset = $conn->prepare("SELECT asset_id, status, borrowable_status FROM assets WHERE asset_id = ?");
    $stmtCheckAsset->execute([$asset_id]);
    $assetInfo = $stmtCheckAsset->fetch(PDO::FETCH_ASSOC);
    error_log('DEBUG asset_id POST: ' . $asset_id . ' | assetInfo: ' . print_r($assetInfo, true));

    if (!$assetInfo) {
        $errors[] = "ไม่พบครุภัณฑ์ที่คุณต้องการยืม กรุณาลองใหม่อีกครั้ง";
    } elseif ($assetInfo['borrowable_status'] !== 'สามารถยืมได้') { // ตรวจสอบคอลัมน์ใหม่
        $errors[] = "ครุภัณฑ์นี้ไม่สามารถยืมได้ เนื่องจากถูกตั้งค่าให้ไม่พร้อมสำหรับการยืม.";
    } elseif ($assetInfo['status'] === 'ถูกยืม') { // ตรวจสอบคอลัมน์เดิม
        $errors[] = "ครุภัณฑ์นี้ไม่พร้อมให้ยืม เนื่องจากสถานะคือ: ถูกยืม";
    } elseif ($assetInfo['status'] === 'รออนุมัติ') { // ตรวจสอบคอลัมน์เดิม
        $errors[] = "ครุภัณฑ์นี้ไม่พร้อมให้ยืม เนื่องจากสถานะคือ: รออนุมัติ";
    }

    // Basic date validation (optional but recommended)
    if (empty($borrow_date) || empty($return_date)) {
        $errors[] = "กรุณาระบุวันที่ยืมและกำหนดส่งคืน";
    } elseif (strtotime($borrow_date) > strtotime($return_date)) {
        $errors[] = "กำหนดส่งคืนต้องไม่ก่อนวันที่ยืม";
    } elseif (strtotime($borrow_date) < strtotime(date('Y-m-d'))) {
        $errors[] = "วันที่ยืมไม่สามารถย้อนหลังได้";
    }
    // Add more validation if needed, e.g., check for future dates, min/max borrow period

    // --- ตรวจสอบไฟล์เอกสารแนบ (ถ้ามีการอัปโหลด) ---
    // ตรวจสอบ type/size ก่อน แต่ยังไม่ move_uploaded_file ในขั้นนี้
    // (ย้ายไฟล์จริงต่อเมื่อไม่มี error อื่นแล้ว เพื่อไม่ให้มีไฟล์ค้างอยู่บนเซิร์ฟเวอร์โดยไม่มีข้อมูลอ้างอิงในฐานข้อมูล)
    $hasAttachment = isset($_FILES['attachment']) && $_FILES['attachment']['error'] !== UPLOAD_ERR_NO_FILE;
    $fileExt = '';
    if ($hasAttachment) {
        if ($_FILES['attachment']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = "เกิดข้อผิดพลาดในการอัปโหลดไฟล์ กรุณาลองใหม่อีกครั้ง";
        } else {
            $fileTmpPath = $_FILES['attachment']['tmp_name'];
            $fileName = basename($_FILES['attachment']['name']);
            $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            $fileType = mime_content_type($fileTmpPath);
            $allowedMimeTypes = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];
            $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];
            $maxFileSize = 5 * 1024 * 1024; // 5MB

            if (!in_array($fileExt, $allowedExtensions, true) || !in_array($fileType, $allowedMimeTypes, true)) {
                $errors[] = "เอกสารแนบต้องเป็นไฟล์ PDF หรือรูปภาพ (jpg, png, webp) เท่านั้น";
            } elseif ($_FILES['attachment']['size'] > $maxFileSize) {
                $errors[] = "ไฟล์เอกสารแนบมีขนาดใหญ่เกินไป (สูงสุดไม่เกิน 5MB)";
            }
        }
    }

    if (empty($errors)) {
        try {
            $conn->beginTransaction();

            // --- ย้ายไฟล์เอกสารแนบเข้าโฟลเดอร์จริง (ทำตอนนี้เพราะมั่นใจแล้วว่าข้อมูลจะถูกบันทึก) ---
            $attachment_filename = null;
            if ($hasAttachment) {
                $docUploadDir = $uploadDir . 'borrow_docs/';
                if (!is_dir($docUploadDir)) {
                    mkdir($docUploadDir, 0755, true);
                }
                $newFileName = 'borrow_' . $asset_id . '_' . $user_id . '_' . time() . '.' . $fileExt;
                if (move_uploaded_file($fileTmpPath, $docUploadDir . $newFileName)) {
                    $attachment_filename = $newFileName;
                }
            }

            // Insert into borrow_history
            $stmt = $conn->prepare("INSERT INTO borrow_history (asset_id, user_id, borrower_name, borrow_date, return_date, note, attachment_path, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'รออนุมัติ')"); // เปลี่ยน 'pending' เป็น 'รออนุมัติ'
            $stmt->execute([$asset_id, $user_id, $borrower_name, $borrow_date, $return_date, $notes, $attachment_filename]);
            $borrow_id = $conn->lastInsertId(); // **เพิ่มบรรทัดนี้เพื่อดึง borrow_id**

            // Optionally, update asset status to 'รออนุมัติ' (pending approval) immediately
            // This prevents another user from trying to borrow it while it's pending.
            $stmtUpdateAssetStatus = $conn->prepare("UPDATE assets SET status = 'รออนุมัติ' WHERE asset_id = ?");
            $stmtUpdateAssetStatus->execute([$asset_id]);

            // **!!! เริ่มต้นส่วนที่เพิ่มเข้ามาสำหรับการบันทึก Log !!!**
            $logDetails = json_encode([
                'action_borrow' => 'requested', // ระบุว่าเป็น "requested" สำหรับการยืมที่รอดำเนินการ
                'borrow_id' => $borrow_id, // ใช้ borrow_id ที่เพิ่งได้มา
                'asset_id' => $asset_id,
                'borrower_name' => $borrower_name,
                'borrow_date' => $borrow_date,
                'return_date' => $return_date,
                'notes' => $notes,
                'attachment' => $attachment_filename,
                'status' => 'รออนุมัติ' // สถานะใน log ควรตรงกับสถานะใน borrow_history
            ], JSON_UNESCAPED_UNICODE);

            $logStmt = $conn->prepare("INSERT INTO asset_action_log (asset_id, action_type, user_id, details) VALUES (?, ?, ?, ?)");
            $logStmt->execute([$asset_id, 'borrow', $user_id, $logDetails]); // 'action_type' เป็น 'borrow'
            // **!!! สิ้นสุดส่วนที่เพิ่มเข้ามาสำหรับการบันทึก Log !!!**

            $conn->commit();
            $_SESSION['success_message'] = "คำขอยืมครุภัณฑ์ถูกส่งแล้ว กรุณารอการอนุมัติ";
            // Redirect after successful POST to prevent form resubmission and reload data
            header("Location: " . $_SERVER['PHP_SELF']); // Redirect ไปยังหน้าปัจจุบัน
            exit; // *** สำคัญมาก: ต้องมี exit; หลังจาก header()
        } catch (PDOException $e) {
            $conn->rollBack();
            error_log("Borrow request failed: " . $e->getMessage()); // Log the actual error
            $errors[] = "เกิดข้อผิดพลาดในการส่งคำขอยืม: " . $e->getMessage();
            // สามารถแสดงข้อความที่เข้าใจง่ายขึ้นใน Production
        }
    }
    // ถ้ามี error ก็จะถูกแสดงผลบนหน้าหลังจาก POST (ไม่ Redirect)
}

// Get total count
$totalAssets = $conn->query("SELECT COUNT(*) FROM assets")->fetchColumn();

// ดึงครุภัณฑ์ทั้งหมด (ให้ DataTables เป็นคนแบ่งหน้า/ค้นหาฝั่ง client เหมือนหน้า admin)
// หมายเหตุ: ห้ามใส่ LIMIT ตรงนี้ ไม่งั้นช่องค้นหาจะค้นเจอเฉพาะแถวในหน้าปัจจุบัน
$sql = "SELECT a.*,
                c.name AS category_name,
                l.name AS location_name,
                d.name AS department_name
        FROM assets a
        LEFT JOIN categories c ON a.category_id = c.category_id
        LEFT JOIN locations l ON a.location_id = l.location_id
        LEFT JOIN departments d ON a.department_id = d.department_id
        ORDER BY a.asset_id DESC";
$stmt = $conn->query($sql);
$assets = $stmt->fetchAll();

$categories = $conn->query("SELECT * FROM categories")->fetchAll();
$locations = $conn->query("SELECT * FROM locations")->fetchAll();
$departments = $conn->query("SELECT * FROM departments")->fetchAll();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จัดการครุภัณฑ์ - ระบบจัดการครุภัณฑ์</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link href="css/assets-list.css" rel="stylesheet">
    <style>
        .dataTables_wrapper .pagination .page-item.active .page-link {
            background-color: #667eea;
            border-color: #667eea;
        }
        .dataTables_filter input {
            border-radius: 8px;
            padding: 5px 10px;
            border: 1px solid #ced4da;
        }
    </style>
</head>
<body>
<?php include(__DIR__ . '/header.php'); ?>
<div class="container fade-in">
    <div class="page-header mb-3">
        <h4>
            <i class="fas fa-boxes-stacked header-icon"></i>
            รายการครุภัณฑ์สำหรับยืม
        </h4>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger" role="alert">
            <h5 class="alert-heading"><i class="fas fa-exclamation-triangle"></i> เกิดข้อผิดพลาด:</h5>
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                    <li><?= htmlspecialchars($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle"></i> <?= $_SESSION['success_message'] ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>
    <div class="table-container">
        <div class="table-header">
            <h5 class="table-title">
                <span class="icon">📦</span>
                รายการครุภัณฑ์ทั้งหมด
                <span class="badge bg-primary ms-2"><?= $totalAssets ?> รายการ</span>
            </h5>
        </div>
        <?php if (count($assets) > 0): ?>
            <div class="table-responsive p-3">
                <table id="assetsTable" class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>เลขครุภัณฑ์</th>
                            <th>ชื่อรายการ</th>
                            <th>หมวดหมู่</th>
                            <th>สถานที่</th>
                            <th>แผนก</th>
                            <th>สถานะ</th>
                            <th>วันที่ซื้อ</th>
                            <th>รูปภาพ</th>
                            <th class="text-center" width="120">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($assets as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['asset_code']) ?></td>
                                <td><?= htmlspecialchars($row['name']) ?></td>
                                <td><?= htmlspecialchars($row['category_name'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($row['location_name'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($row['department_name'] ?? '-') ?></td>
                                <td>
                                    <span class="status-badge" data-status="<?= htmlspecialchars($row['status']) ?>">
                                        <?= htmlspecialchars($row['status']) ?>
                                    </span>
                                    <?php
                                    // Optional: แสดง borrowable_status เป็น tooltip หรือ badge เล็กๆ
                                    if ($row['borrowable_status'] !== 'สามารถยืมได้' && $row['status'] === 'ใช้งานปกติ') {
                                        echo ' <span class="badge bg-danger ms-1" data-bs-toggle="tooltip" data-bs-placement="top" title="ผู้ดูแลระบุว่าไม่สามารถยืมได้">⛔</span>';
                                    }
                                    ?>
                                </td>
                                <td><?= htmlspecialchars($row['purchase_date']) ?></td>
                                <td>
                                    <?php if ($row['image_url']): ?>
                                        <a href="#" class="asset-image-link" data-img="../uploads/<?= htmlspecialchars($row['image_url']) ?>">
                                            <img src="../uploads/<?= htmlspecialchars($row['image_url']) ?>" alt="รูปครุภัณฑ์" class="asset-image">
                                        </a>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <span class="action-buttons">
                                        <?php
                                        // เงื่อนไขในการแสดงปุ่ม "ยืม"
                                        // ต้องเป็น 'สามารถยืมได้' ในคอลัมน์ borrowable_status
                                        // และ status ต้องไม่เป็น 'ถูกยืม' หรือ 'รออนุมัติ'
                                        if ($row['borrowable_status'] === 'สามารถยืมได้' && $row['status'] !== 'ถูกยืม' && $row['status'] !== 'รออนุมัติ'):
                                        ?>
                                            <a href="borrow_request.php?id=<?= $row['asset_id'] ?>"
                                               class="btn-borrow">
                                                <i class="fas fa-hand-holding me-1"></i> ยืม
                                            </a>
                                        <?php else: ?>
                                            <button class="btn btn-secondary btn-sm" disabled>
                                                <i class="fas fa-info-circle me-1"></i>
                                                <?php
                                                // แสดงข้อความที่ไม่พร้อมยืมตามสถานะจริง
                                                if ($row['borrowable_status'] !== 'สามารถยืมได้') {
                                                    echo 'ไม่สามารถยืมได้'; // ผู้ดูแลระบบตั้งค่าไม่ให้ยืม
                                                } elseif ($row['status'] === 'ถูกยืม') {
                                                    echo 'ถูกยืมอยู่';
                                                } elseif ($row['status'] === 'รออนุมัติ') {
                                                    echo 'รออนุมัติการยืม';
                                                } elseif (in_array($row['status'], ['ชำรุด', 'ซ่อมแซม', 'เลิกใช้', 'จำหน่าย'])) {
                                                    echo 'ไม่พร้อมให้ยืม';
                                                } else {
                                                    echo 'ไม่พร้อมให้ยืม'; // สถานะอื่น ๆ (ชำรุด, เลิกใช้, จำหน่าย)
                                                }
                                                ?>
                                            </button>
                                        <?php endif; ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-box-open icon"></i>
                <h5>ไม่มีครุภัณฑ์ในระบบ</h5>
                <p>โปรดรอผู้ดูแลระบบเพิ่มครุภัณฑ์</p>
            </div>
        <?php endif; ?>
    </div>

    <div class="text-start">
        <a href="index.php" class="btn-back">
            <i class="fas fa-arrow-left icon"></i>
            กลับหน้าหลัก
        </a>
    </div>
</div>

<div class="modal fade" id="imagePreviewModal" tabindex="-1" aria-labelledby="imagePreviewModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="imagePreviewModalLabel">ดูรูปภาพครุภัณฑ์</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
      </div>
      <div class="modal-body text-center">
        <img id="previewImage" src="" alt="รูปครุภัณฑ์ขนาดใหญ่" style="max-width:100%; max-height:70vh; border-radius:12px; box-shadow:0 4px 24px rgba(0,0,0,0.15);">
      </div>
    </div>
  </div>
</div>
<div class="modal fade" id="borrowAssetModal" tabindex="-1" aria-labelledby="borrowAssetModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <form id="borrowAssetForm" method="POST" enctype="multipart/form-data" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="borrowAssetModalLabel"><i class="fas fa-hand-holding me-2"></i> ยืมครุภัณฑ์</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="asset_id" id="borrow_asset_id">
        <div class="mb-3">
          <label class="form-label">เลขครุภัณฑ์</label>
          <input type="text" class="form-control" id="borrow_asset_code" readonly>
        </div>
        <div class="mb-3">
          <label class="form-label">ชื่อครุภัณฑ์</label>
          <input type="text" class="form-control" id="borrow_asset_name" readonly>
        </div>
        <div class="mb-3">
          <label class="form-label">หมวดหมู่</label>
          <input type="text" class="form-control" id="borrow_asset_category" readonly>
        </div>
        <div class="mb-3">
          <label class="form-label">สถานที่</label>
          <input type="text" class="form-control" id="borrow_asset_location" readonly>
        </div>
        <div class="mb-3">
          <label class="form-label">แผนก</label>
          <input type="text" class="form-control" id="borrow_asset_department" readonly>
        </div>
        <div class="mb-3">
          <label class="form-label">ผู้ยืม</label>
          <input type="text" name="borrower_name" class="form-control" id="borrower_name" value="<?= htmlspecialchars($_SESSION['name'] ?? '') ?>" readonly required>
        </div>
        <div class="mb-3">
          <label class="form-label">วันที่ยืม</label>
          <input type="date" name="borrow_date" class="form-control" required min="<?php echo date('Y-m-d'); ?>">
        </div>
        <div class="mb-3">
          <label class="form-label">กำหนดส่งคืน</label>
          <input type="date" name="return_date" class="form-control" required min="<?php echo date('Y-m-d'); ?>">
        </div>
        <div class="mb-3">
          <label class="form-label">หมายเหตุ</label>
          <textarea name="notes" class="form-control"></textarea>
        </div>
        <div class="mb-3">
          <label class="form-label">เอกสารแนบ (ถ้ามี)</label>
          <input type="file" name="attachment" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.webp">
          <div class="form-text">เช่น หนังสือขออนุมัติยืม หรือเอกสารอ้างอิง — รองรับ PDF, JPG, PNG (ไม่เกิน 5MB)</div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="submit" class="btn btn-primary">
          <i class="fas fa-check me-1"></i>
          ยืนยันการยืม
        </button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
          <i class="fas fa-times me-1"></i>
          ยกเลิก
        </button>
      </div>
    </form>
  </div>
</div>
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script>
// ค้นหา / เรียงลำดับ / แบ่งหน้า ให้เหมือนหน้า admin
$(document).ready(function () {
    // ตารางจะไม่ถูก render เลยถ้าไม่มีครุภัณฑ์ — ต้องเช็คก่อน
    if ($('#assetsTable').length === 0) return;

    $('#assetsTable').DataTable({
        "language": {
            "sProcessing": "กำลังดำเนินการ...",
            "sLengthMenu": "แสดง_MENU_ แถว",
            "sZeroRecords": "ไม่พบข้อมูล",
            "sInfo": "แสดง _START_ ถึง _END_ จาก _TOTAL_ แถว",
            "sInfoEmpty": "แสดง 0 ถึง 0 จาก 0 แถว",
            "sInfoFiltered": "(กรองข้อมูล _MAX_ ทุกแถว)",
            "sSearch": "🔍 ค้นหา:",
            "oPaginate": {
                "sFirst": "เริ่มต้น",
                "sPrevious": "ก่อนหน้า",
                "sNext": "ถัดไป",
                "sLast": "สุดท้าย"
            }
        },
        // คอลัมน์รูปภาพกับปุ่มจัดการเรียงลำดับไม่ได้ (เรียงแล้วไม่มีความหมาย)
        "columnDefs": [
            { "orderable": false, "targets": [7, 8] }
        ],
        "order": [[0, 'desc']],
        "pageLength": 10
    });
});
</script>
<script>
// Define mapping from PHP to window.departmentLocationMap
<?php
$deptLocMap = [];
$deptLocRows = $conn->query("SELECT department_id, location_id FROM locations WHERE department_id IS NOT NULL")->fetchAll();
foreach ($deptLocRows as $row) {
    $deptLocMap[$row['department_id']][] = $row['location_id'];
}
?>
window.departmentLocationMap = <?php echo json_encode($deptLocMap, JSON_UNESCAPED_UNICODE); ?>;
</script>
<script>
// Filter locations by department (main page filters)
// ประกาศตัวแปรแค่ครั้งเดียวที่นี่
var departmentSelects = document.querySelectorAll('select[name="department_id"], #edit_department_id');
var locationSelects = document.querySelectorAll('select[name="location_id"], #edit_location_id');

function filterLocationsByDepartment(deptId, locationSelect) {
    const allOptions = Array.from(locationSelect.querySelectorAll('option'));
    if (!deptId || !window.departmentLocationMap[deptId]) { // Use window.departmentLocationMap
        allOptions.forEach(opt => opt.style.display = '');
        return;
    }
    const allowed = window.departmentLocationMap[deptId].map(String); // Use window.departmentLocationMap
    allOptions.forEach(opt => {
        if (!opt.value || allowed.includes(opt.value)) {
            opt.style.display = '';
        } else {
            opt.style.display = 'none';
        }
    });
    // If the currently selected option is no longer valid, reset
    if (locationSelect.value && !allowed.includes(locationSelect.value)) {
        locationSelect.value = '';
    }
}
departmentSelects.forEach(deptSel => {
    deptSel.addEventListener('change', function() {
        locationSelects.forEach(locSel => filterLocationsByDepartment(this.value, locSel));
    });
});
// Call filter on initial page load
window.addEventListener('DOMContentLoaded', function() {
    departmentSelects.forEach((deptSel, i) => {
        locationSelects.forEach(locSel => filterLocationsByDepartment(deptSel.value, locSel));
    });

    // Set today's date as default for borrow_date in the modal
    const borrowDateInput = document.querySelector('#borrowAssetModal input[name="borrow_date"]');
    if (borrowDateInput) {
        const today = new Date().toISOString().split('T')[0];
        borrowDateInput.value = today;
    }

    // Optional: Set a default return date (e.g., 7 days from now)
    const returnDateInput = document.querySelector('#borrowAssetModal input[name="return_date"]');
    if (returnDateInput) {
        const defaultReturnDate = new Date();
        defaultReturnDate.setDate(defaultReturnDate.getDate() + 7); // 7 days from today
        returnDateInput.value = defaultReturnDate.toISOString().split('T')[0];
    }

    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl)
    })
});


function filterLocationsByDepartmentForAddModal(deptId, locationSelect) {
    const allOptions = Array.from(locationSelect.options);
    if (!deptId || !window.departmentLocationMap[deptId]) {
        allOptions.forEach(opt => opt.style.display = 'none');
        if (allOptions[0]) allOptions[0].style.display = ''; // Show default "select one" option
        locationSelect.value = '';
        return;
    }
    const allowed = window.departmentLocationMap[deptId].map(String);
    allOptions.forEach(opt => {
        if (!opt.value) { // Keep the empty/default option visible
            opt.style.display = '';
        } else if (allowed.includes(opt.value)) {
            opt.style.display = '';
        } else {
            opt.style.display = 'none';
            if (locationSelect.value === opt.value) locationSelect.value = ''; // Reset if current option is hidden
        }
    });
}
const addModal = document.getElementById('addModal'); // This modal isn't present in this user view
if (addModal) { // This block will likely not execute on this page
    addModal.addEventListener('shown.bs.modal', function() {
        const addDeptSel = addModal.querySelector('select[name="department_id"]');
        const addLocSel = addModal.querySelector('select[name="location_id"]');
        if (addDeptSel && addLocSel) {
            filterLocationsByDepartmentForAddModal(addDeptSel.value, addLocSel);
            addDeptSel.onchange = function() {
                filterLocationsByDepartmentForAddModal(this.value, addLocSel);
            };
        }
    });
}

// When borrow button is clicked, fetch asset data and populate modal
const borrowButtons = document.querySelectorAll('.borrow-btn');
borrowButtons.forEach(btn => {
    btn.addEventListener('click', function() {
        const assetId = this.getAttribute('data-id');
        fetch('?fetch_asset=1&id=' + assetId)
            .then(res => {
                if (!res.ok) {
                    // ถ้า response ไม่ใช่ 2xx OK
                    // ตรวจสอบ Content-Type เพื่อดูว่าเป็น JSON หรือ HTML error
                    const contentType = res.headers.get("content-type");
                    if (contentType && contentType.indexOf("application/json") !== -1) {
                        return res.json().then(data => {
                            throw new Error(data.error || 'Network response was not ok ' + res.statusText);
                        });
                    } else {
                        // ถ้าไม่ใช่ JSON ก็โยน error ทั่วไป
                        return res.text().then(text => {
                               console.error("Non-JSON response:", text); // Log raw response for debugging
                               throw new Error('Server error or malformed response: ' + res.statusText);
                        });
                    }
                }
                return res.json();
            })
            .then(asset => {
                console.log('ASSET DATA:', asset); // debug log
                if (asset && !asset.error) { // Check for asset and no error from PHP
                    document.getElementById('borrow_asset_id').value = asset.asset_id ?? '';
                    document.getElementById('borrow_asset_code').value = asset.asset_code ?? '';
                    document.getElementById('borrow_asset_name').value = asset.name ?? '';
                    document.getElementById('borrow_asset_category').value = asset.category_name ?? '';
                    document.getElementById('borrow_asset_location').value = asset.location_name ?? '';
                    document.getElementById('borrow_asset_department').value = asset.department_name ?? '';
                    // Borrower name is already pre-filled from session in PHP
                } else {
                    alert(asset.error || 'ไม่สามารถดึงข้อมูลครุภัณฑ์ได้');
                    const borrowModal = bootstrap.Modal.getInstance(document.getElementById('borrowAssetModal'));
                    if (borrowModal) borrowModal.hide();
                }
            })
            .catch(error => {
                console.error('Error fetching asset data:', error);
                alert('เกิดข้อผิดพลาดในการดึงข้อมูลครุภัณฑ์: ' + error.message);
                const borrowModal = bootstrap.Modal.getInstance(document.getElementById('borrowAssetModal'));
                if (borrowModal) borrowModal.hide();
            });
    });
});

// Style status badges dynamically
document.querySelectorAll('.status-badge').forEach(badge => {
    const status = badge.dataset.status;
    switch (status) {
        case 'ใช้งานปกติ':
            badge.classList.add('bg-success');
            break;
        case 'ถูกยืม':
            badge.classList.add('bg-danger'); // Often red for unavailable
            break;
        case 'ชำรุด':
            badge.classList.add('bg-warning', 'text-dark');
            break;
        case 'จำหน่าย':
            badge.classList.add('bg-secondary');
            break;
        case 'ซ่อมแซม':
            badge.classList.add('bg-info', 'text-dark');
            break;
        case 'เลิกใช้':
            badge.classList.add('bg-dark');
            break;
        case 'รออนุมัติ': // Added for pending borrow requests
            badge.classList.add('bg-primary'); // May choose a different color
            break;
        default:
            badge.classList.add('bg-light', 'text-dark');
            break;
    }
});

// Image preview modal JS
const imagePreviewModal = new bootstrap.Modal(document.getElementById('imagePreviewModal'));
document.querySelectorAll('.asset-image-link').forEach(link => {
    link.addEventListener('click', function(e) {
        e.preventDefault();
        const imageUrl = this.dataset.img;
        document.getElementById('previewImage').src = imageUrl;
        imagePreviewModal.show();
    });
});

</script>
</body>
<?php
require 'footer.php';  // ปิด container, โหลด Bootstrap JS
?>
</html>