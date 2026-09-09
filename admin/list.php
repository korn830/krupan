<?php
session_start();
if (!isset($_SESSION["user_id"]) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit;
}
require dirname(__DIR__) . '/config/db.php';

$uploadDir = '../uploads/';

// Add this PHP block at the top of the file to handle asset data fetching
if (isset($_GET['fetch_asset']) && isset($_GET['id'])) {
    $asset_id = $_GET['id'];
    $stmt = $conn->prepare("SELECT * FROM assets WHERE asset_id = ?");
    $stmt->execute([$asset_id]);
    $asset = $stmt->fetch(PDO::FETCH_ASSOC);
    echo json_encode($asset);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && $_SESSION['role'] === 'admin') {
    // กำหนดค่าเริ่มต้นสำหรับข้อความแจ้งเตือน
    $_SESSION['success_message'] = "";
    $_SESSION['error_message'] = "";

    if (isset($_POST['asset_id'])) { // ตรวจจับการแก้ไขครุภัณฑ์
        $asset_id = $_POST['asset_id'];
        // ดึงข้อมูลเก่าก่อนแก้ไข
        $stmtOld = $conn->prepare("SELECT * FROM assets WHERE asset_id = ?");
        $stmtOld->execute([$asset_id]);
        $oldData = $stmtOld->fetch(PDO::FETCH_ASSOC);

        $name = $_POST['name'];
        $description = $_POST['description'];
        $category_id = $_POST['category_id'];
        $location_id = $_POST['location_id'];
        $department_id = $_POST['department_id'];
        $status = $_POST['status'];
        $purchase_date = !empty($_POST['purchase_date']) ? $_POST['purchase_date'] : null;
        $warranty_expiry = !empty($_POST['warranty_expiry']) ? $_POST['warranty_expiry'] : null;
        $price = $_POST['price'];

        $image_filename = $oldData['image_url']; // ตั้งค่าเริ่มต้นให้เป็นรูปเดิม

        if (isset($_FILES['image_file']) && $_FILES['image_file']['error'] === UPLOAD_ERR_OK) {
            $fileTmpPath = $_FILES['image_file']['tmp_name'];
            $fileName = basename($_FILES['image_file']['name']);
            $fileType = mime_content_type($fileTmpPath);
            $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

            if (in_array($fileType, $allowedMimeTypes) && in_array($fileExt, $allowedExtensions)) {
                $newFileName = $asset_id . '_' . time() . '.' . $fileExt;
                $destPath = $uploadDir . $newFileName;
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                if (move_uploaded_file($fileTmpPath, $destPath)) {
                    // ลบรูปเก่าออกหากมี
                    if ($oldData['image_url'] && file_exists($uploadDir . $oldData['image_url'])) {
                        unlink($uploadDir . $oldData['image_url']);
                    }
                    $image_filename = $newFileName;
                } else {
                    $_SESSION['error_message'] = "เกิดข้อผิดพลาดในการอัปโหลดไฟล์ภาพ.";
                    header("Location: list.php");
                    exit;
                }
            } else {
                $_SESSION['error_message'] = "ไฟล์ที่อัปโหลดไม่ใช่ไฟล์รูปที่รองรับ (jpg, png, gif, webp).";
                header("Location: list.php");
                exit;
            }
        }

        try {
            $stmt = $conn->prepare("UPDATE assets 
                SET name = ?, description = ?, category_id = ?, location_id = ?, department_id = ?, status = ?, 
                    purchase_date = ?, warranty_expiry = ?, price = ?, image_url = ?
                WHERE asset_id = ?");
            $stmt->execute([
                $name, 
                $description, 
                $category_id, 
                $location_id, 
                $department_id, 
                $status, 
                $purchase_date, 
                $warranty_expiry, 
                $price, 
                $image_filename, 
                $asset_id
            ]);

            // ดึงข้อมูลใหม่หลังแก้ไข
            $stmtNew = $conn->prepare("SELECT * FROM assets WHERE asset_id = ?");
            $stmtNew->execute([$asset_id]);
            $newData = $stmtNew->fetch(PDO::FETCH_ASSOC);

            // สร้าง diff เพื่อบันทึกการเปลี่ยนแปลง
            $diff = [];
            foreach ($oldData as $key => $oldValue) {
                if ($key === 'asset_id' || $key === 'image_url' || $key === 'created_at' || $key === 'updated_at') {
                    continue; // ข้ามฟิลด์ที่ไม่ต้องการจับผิด
                }
                if (isset($newData[$key]) && $oldValue != $newData[$key]) {
                    $diff[$key] = [
                        'old' => $oldValue,
                        'new' => $newData[$key]
                    ];
                }
            }
            
            if ($oldData['image_url'] !== $image_filename) {
                $diff['image_url'] = [
                    'old' => $oldData['image_url'] ?: 'ไม่มีรูปภาพ',
                    'new' => $image_filename ?: 'ไม่มีรูปภาพ'
                ];
            }

            if (!empty($diff)) {
                $logDetails = json_encode($diff, JSON_UNESCAPED_UNICODE);
                $logStmt = $conn->prepare("INSERT INTO asset_action_log (asset_id, action_type, user_id, details) VALUES (?, 'edit', ?, ?)");
                $logStmt->execute([$asset_id, $_SESSION['user_id'], $logDetails]);
            }
            
            $_SESSION['success_message'] = "แก้ไขครุภัณฑ์ '{$name}' สำเร็จแล้ว!";
            header("Location: list.php");
            exit;

        } catch (PDOException $e) {
            $_SESSION['error_message'] = "เกิดข้อผิดพลาดในการแก้ไขครุภัณฑ์: " . $e->getMessage();
            header("Location: list.php");
            exit;
        }

    } else { // ตรวจจับการเพิ่มครุภัณฑ์ใหม่
        $asset_code = $_POST['asset_code'];
        $name = $_POST['name'];
        $description = $_POST['description'];
        $category_id = $_POST['category_id'];
        $location_id = $_POST['location_id'];
        $department_id = $_POST['department_id'];
        $status = $_POST['status'];
        $purchase_date = !empty($_POST['purchase_date']) ? $_POST['purchase_date'] : null;
        $warranty_expiry = !empty($_POST['warranty_expiry']) ? $_POST['warranty_expiry'] : null;
        $price = $_POST['price'];

        $image_filename = null;

        $stmt = $conn->prepare("SELECT COUNT(*) FROM assets WHERE asset_code = ?");
        $stmt->execute([$asset_code]);
        if ($stmt->fetchColumn() > 0) {
            $_SESSION['error_message'] = "เลขครุภัณฑ์นี้มีอยู่ในระบบแล้ว กรุณาใช้เลขครุภัณฑ์อื่น.";
            header("Location: list.php");
            exit;
        }

        if (isset($_FILES['image_file']) && $_FILES['image_file']['error'] === UPLOAD_ERR_OK) {
            $fileTmpPath = $_FILES['image_file']['tmp_name'];
            $fileName = basename($_FILES['image_file']['name']);
            $fileType = mime_content_type($fileTmpPath);
            $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

            if (in_array($fileType, $allowedMimeTypes) && in_array($fileExt, $allowedExtensions)) {
                $newFileName = $asset_code . '_' . time() . '.' . $fileExt;
                $destPath = $uploadDir . $newFileName;
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                if (move_uploaded_file($fileTmpPath, $destPath)) {
                    $image_filename = $newFileName;
                } else {
                    $_SESSION['error_message'] = "เกิดข้อผิดพลาดในการอัปโหลดไฟล์ภาพ.";
                    header("Location: list.php");
                    exit;
                }
            } else {
                $_SESSION['error_message'] = "ไฟล์ที่อัปโหลดไม่ใช่ไฟล์รูปที่รองรับ (jpg, png, gif, webp).";
                header("Location: list.php");
                exit;
            }
        }

        try {
            $stmt = $conn->prepare("INSERT INTO assets 
                (asset_code, name, description, category_id, location_id, department_id, status, purchase_date, warranty_expiry, price, image_url) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $asset_code, 
                $name, 
                $description, 
                $category_id, 
                $location_id, 
                $department_id, 
                $status, 
                $purchase_date, 
                $warranty_expiry, 
                $price, 
                $image_filename
            ]);
            $lastAssetId = $conn->lastInsertId();
            $logDetails = json_encode(['action' => 'add', 'asset_code' => $asset_code, 'name' => $name], JSON_UNESCAPED_UNICODE);
            $logStmt = $conn->prepare("INSERT INTO asset_action_log (asset_id, action_type, user_id, details) VALUES (?, 'add', ?, ?)");
            $logStmt->execute([$lastAssetId, $_SESSION['user_id'], $logDetails]);

            $_SESSION['success_message'] = "เพิ่มครุภัณฑ์ '{$name}' สำเร็จแล้ว!";
            header("Location: list.php");
            exit;

        } catch (PDOException $e) {
            $_SESSION['error_message'] = "เกิดข้อผิดพลาดในการเพิ่มครุภัณฑ์: " . $e->getMessage();
            header("Location: list.php");
            exit;
        }
    }
}

// Get total count
$totalAssets = $conn->query("SELECT COUNT(*) FROM assets")->fetchColumn();

// Fetch ALL assets 
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

if (isset($_GET['delete_department'])) {
    $id = (int)$_GET['delete_department'];
    $conn->prepare("UPDATE assets SET department_id = NULL WHERE department_id = ?")->execute([$id]);
    $stmt = $conn->prepare("DELETE FROM departments WHERE department_id = ?");
    $stmt->execute([$id]);
    header("Location: ../departments.php");
    exit;
}
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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <?php require_once __DIR__ . '/../assets/kp_assets.php'; ?>
<link rel="stylesheet" href="<?= kp_asset('assets/css/shared/assets-list.css', '../') ?>">
    
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

<div class="container fade-in mt-4">
    <div class="page-header mb-3"> 
        <h4>
            <i class="fas fa-boxes-stacked header-icon"></i>
            จัดการครุภัณฑ์
        </h4>
    </div>
    
    <div class="action-section text-start mb-3">
        <button class="btn-add-asset" data-bs-toggle="modal" data-bs-target="#addModal">
            <i class="fas fa-plus icon"></i> เพิ่มครุภัณฑ์
        </button>
        <a href="export_excel.php" class="btn-add-asset ms-2" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%);">
            <i class="fas fa-file-excel me-1"></i> Export เป็น Excel
        </a>
        <button class="btn btn-primary ms-2" data-bs-toggle="modal" data-bs-target="#importModal">
            <i class="fas fa-file-import me-1"></i> นำเข้าข้อมูล (Import)
        </button>
        <a href="export_template.php" class="btn btn-outline-secondary ms-2 bg-white">
            <i class="fas fa-download me-1"></i> ดาวน์โหลด Template
        </a>
    </div>

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
                    <thead class="table-light">
                        <tr>
                            <th>เลขครุภัณฑ์</th>
                            <th>ชื่อรายการ</th>
                            <th>หมวดหมู่</th>
                            <th>สถานที่</th>
                            <th>แผนก</th>
                            <th>สถานะ</th>
                            <th>วันที่ซื้อ</th>
                            <th>รูปภาพ</th>
                            <th class="text-center" width="220">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($assets as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['asset_code']) ?></td>
                                <td class="fw-bold text-primary"><?= htmlspecialchars($row['name']) ?></td>
                                <td><?= htmlspecialchars($row['category_name']) ?></td>
                                <td><?= htmlspecialchars($row['location_name']) ?></td>
                                <td><?= htmlspecialchars($row['department_name']) ?></td>
                                <td>
                                    <span class="status-badge" data-status="<?= htmlspecialchars($row['status']) ?>">
                                        <?= htmlspecialchars($row['status']) ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($row['purchase_date']) ?></td>
                                <td>
                                    <?php if ($row['image_url']): ?>
                                        <a href="#" class="asset-image-link" data-img="../uploads/<?= htmlspecialchars($row['image_url']) ?>">
                                            <img src="../uploads/<?= htmlspecialchars($row['image_url']) ?>" alt="รูปครุภัณฑ์" class="asset-image" style="width: 40px; height: 40px; object-fit: cover; border-radius: 5px;">
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <span class="action-buttons">
                                        <button class="btn btn-sm btn-outline-primary edit-btn"
                                                data-id="<?= $row['asset_id'] ?>"
                                                data-bs-toggle="modal"
                                                data-bs-target="#editAssetModal">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-dark" 
                                                onclick="showQRModal(<?= $row['asset_id'] ?>, '<?= htmlspecialchars($row['asset_code'], ENT_QUOTES) ?>', '<?= htmlspecialchars($row['name'], ENT_QUOTES) ?>')">
                                            <i class="fas fa-qrcode"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-danger"
                                                onclick="confirmDelete(<?= $row['asset_id'] ?>, '<?= htmlspecialchars($row['name'], ENT_QUOTES) ?>')">
                                            <i class="fas fa-trash"></i>
                                        </button>
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
                <h5>ยังไม่มีข้อมูลครุภัณฑ์</h5>
                <p>คลิกปุ่ม "เพิ่มครุภัณฑ์" เพื่อเริ่มต้นจัดการครุภัณฑ์</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal fade" id="addModal" tabindex="-1" aria-labelledby="addModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <form method="POST" class="modal-content" enctype="multipart/form-data">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title" id="addModalLabel"><i class="fas fa-plus-circle me-2"></i>เพิ่มครุภัณฑ์ใหม่</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="ปิด"></button>
      </div>
      <div class="modal-body row g-3">
        <div class="col-md-6">
          <label class="form-label">เลขครุภัณฑ์</label>
          <input type="text" name="asset_code" class="form-control" required>
        </div>
        <div class="col-md-6">
          <label class="form-label">ชื่อรายการ</label>
          <input type="text" name="name" class="form-control" required>
        </div>
        <div class="col-md-12">
          <label class="form-label">รายละเอียด</label>
          <textarea name="description" class="form-control"></textarea>
        </div>
        <div class="col-md-4">
          <label class="form-label">หมวดหมู่</label>
          <select name="category_id" class="form-select" required>
            <option value="">-- เลือก --</option>
            <?php foreach ($categories as $c): ?>
              <option value="<?= $c['category_id'] ?>"><?= $c['name'] ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">สถานที่</label>
          <select name="location_id" class="form-select" required>
            <option value="">-- เลือก --</option>
            <?php foreach ($locations as $l): ?>
              <option value="<?= $l['location_id'] ?>"><?= $l['name'] ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">แผนก</label>
          <select name="department_id" class="form-select" required>
            <option value="">-- เลือก --</option>
            <?php foreach ($departments as $d): ?>
              <option value="<?= $d['department_id'] ?>"><?= $d['name'] ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">สถานะ</label>
          <select name="status" class="form-select">
            <option value="ใช้งานปกติ">ใช้งานปกติ</option>
            <option value="ชำรุด">ชำรุด</option>
            <option value="ส่งซ่อม">ส่งซ่อม</option>
            <option value="จำหน่าย">จำหน่าย</option>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">วันที่ซื้อ</label>
          <input type="date" name="purchase_date" class="form-control">
        </div>
        <div class="col-md-4">
          <label class="form-label">วันหมดประกัน</label>
          <input type="date" name="warranty_expiry" class="form-control">
        </div>
        <div class="col-md-6">
          <label class="form-label">ราคาซื้อ (บาท)</label>
          <input type="number" step="0.01" name="price" class="form-control">
        </div>
        <div class="col-md-6">
          <label class="form-label">อัปโหลดรูปภาพ</label>
          <input type="file" name="image_file" accept="image/*" class="form-control">
        </div>
      </div>
      <div class="modal-footer">
        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i> บันทึก</button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
      </div>
    </form>
  </div>
</div>

<div class="modal fade" id="editAssetModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <form id="editAssetForm" method="POST" enctype="multipart/form-data">
      <div class="modal-content">
        <div class="modal-header bg-warning text-dark">
          <h5 class="modal-title" id="editAssetModalLabel"><i class="fas fa-edit me-2"></i>แก้ไขข้อมูลครุภัณฑ์</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
        </div>
        <div class="modal-body row g-3">
          <input type="hidden" name="asset_id" id="edit_asset_id">
          <div class="col-md-6">
            <label class="form-label">เลขครุภัณฑ์</label>
            <input type="text" name="asset_code" id="edit_asset_code" class="form-control" required readonly>
          </div>
          <div class="col-md-6">
            <label class="form-label">ชื่อรายการ</label>
            <input type="text" name="name" id="edit_name" class="form-control" required>
          </div>
          <div class="col-md-12">
            <label class="form-label">รายละเอียด</label>
            <textarea name="description" id="edit_description" class="form-control"></textarea>
          </div>
          <div class="col-md-4">
            <label class="form-label">หมวดหมู่</label>
            <select name="category_id" id="edit_category_id" class="form-select" required>
              <option value="">-- เลือก --</option>
              <?php foreach ($categories as $c): ?>
                <option value="<?= $c['category_id'] ?>"><?= $c['name'] ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">สถานที่</label>
            <select name="location_id" id="edit_location_id" class="form-select" required>
              <option value="">-- เลือก --</option>
              <?php foreach ($locations as $l): ?>
                <option value="<?= $l['location_id'] ?>"><?= $l['name'] ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">แผนก</label>
            <select name="department_id" id="edit_department_id" class="form-select" required>
              <option value="">-- เลือก --</option>
              <?php foreach ($departments as $d): ?>
                <option value="<?= $d['department_id'] ?>"><?= $d['name'] ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">สถานะ</label>
            <select name="status" id="edit_status" class="form-select">
              <option value="ใช้งานปกติ">ใช้งานปกติ</option>
              <option value="ถูกยืม">ถูกยืม (นำไปใช้งาน)</option>
              <option value="ชำรุด">ชำรุด</option>
              <option value="ส่งซ่อม">ส่งซ่อม</option>
              <option value="จำหน่าย">จำหน่าย</option>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">วันที่ซื้อ</label>
            <input type="date" name="purchase_date" id="edit_purchase_date" class="form-control">
          </div>
          <div class="col-md-4">
            <label class="form-label">วันหมดประกัน</label>
            <input type="date" name="warranty_expiry" id="edit_warranty_expiry" class="form-control">
          </div>
          <div class="col-md-6">
            <label class="form-label">ราคาซื้อ (บาท)</label>
            <input type="number" step="0.01" name="price" id="edit_price" class="form-control">
          </div>
          <div class="col-md-6">
            <label class="form-label">อัปโหลดรูปภาพใหม่ (ถ้ามี)</label>
            <input type="file" name="image_file" id="edit_image_file" accept="image/*" class="form-control">
            <small class="text-muted">รูปภาพปัจจุบัน: <span id="current_image_name">ไม่มี</span></small>
            <div id="current_image_preview" class="mt-2"></div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-warning text-dark"><i class="fas fa-save me-1"></i> บันทึกการแก้ไข</button>
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
        </div>
      </div>
    </form>
  </div>
</div>

<div class="modal fade" id="imagePreviewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">ดูรูปภาพครุภัณฑ์</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
      </div>
      <div class="modal-body text-center">
        <img id="previewImage" src="" alt="รูปครุภัณฑ์ขนาดใหญ่" style="max-width:100%; max-height:70vh; border-radius:12px;">
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="qrModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content qr-modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="qrModalLabel"><span class="qr-icon">📱</span> QR Code ครุภัณฑ์</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body qr-modal-body">
                <div class="qr-asset-info">
                    <p><strong>รหัสครุภัณฑ์:</strong> <span id="qrAssetCode">-</span></p>
                    <p><strong>ชื่อครุภัณฑ์:</strong> <span id="qrAssetName">-</span></p>
                </div>
                <div class="qr-code-display">
                    <img id="qrCodeImage" src="" alt="QR Code">
                </div>
                <div class="qr-scan-info">
                    <span class="scan-icon">ℹ️</span>
                    <span>แอดมินสแกน QR Code เพื่อจัดการสถานะครุภัณฑ์</span>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-print-qr" onclick="printQR()">🖨️ พิมพ์</button>
                <a id="qrDownloadLink" href="" download="" class="btn-download-qr">💾 ดาวน์โหลด</a>
                <button type="button" class="btn-close-modal" data-bs-dismiss="modal">ปิด</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="importModal" tabindex="-1" aria-labelledby="importModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <form action="import_process.php" method="POST" enctype="multipart/form-data" class="modal-content">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title" id="importModalLabel"><i class="fas fa-file-excel me-2"></i>นำเข้าข้อมูลครุภัณฑ์</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="ปิด"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-info">
          <strong><i class="fas fa-info-circle"></i> คำแนะนำ:</strong><br>
          1. กรุณา <a href="export_template.php" class="alert-link">ดาวน์โหลด Template</a> เพื่อดูรูปแบบคอลัมน์<br>
          2. คอลัมน์ "หมวดหมู่", "สถานที่", "แผนก" ให้ใส่เป็น <b>ตัวเลข ID</b> เท่านั้น<br>
          3. หากรหัสครุภัณฑ์ซ้ำกับที่มีอยู่แล้ว ระบบจะข้ามแถวนั้นไปอัตโนมัติ
        </div>
        <div class="mb-3">
          <label class="form-label fw-bold">เลือกไฟล์ Excel (.xlsx, .xls)</label>
          <input type="file" name="excel_file" class="form-control" accept=".xlsx, .xls" required>
        </div>
      </div>
      <div class="modal-footer">
        <button type="submit" class="btn btn-success"><i class="fas fa-upload me-1"></i> เริ่มการนำเข้า</button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
      </div>
    </form>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
<?php
$deptLocMap = [];
$deptLocRows = $conn->query("SELECT department_id, location_id FROM locations WHERE department_id IS NOT NULL")->fetchAll();
foreach ($deptLocRows as $row) {
    $deptLocMap[$row['department_id']][] = $row['location_id'];
}
?>
window.departmentLocationMap = <?php echo json_encode($deptLocMap, JSON_UNESCAPED_UNICODE); ?>;

$(document).ready(function() {
    // 1. เรียกใช้งาน DataTables
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
        "order": [[0, 'desc']], 
        "pageLength": 10 
    });

    // 2. แจ้งเตือน SweetAlert2 จาก PHP Session
    <?php if (isset($_SESSION['success_message']) && $_SESSION['success_message'] !== ""): ?>
        Swal.fire({
            icon: 'success',
            title: 'สำเร็จ!',
            text: '<?= htmlspecialchars($_SESSION['success_message']) ?>',
            timer: 3000,
            showConfirmButton: true
        });
        <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['error_message']) && $_SESSION['error_message'] !== ""): ?>
        Swal.fire({
            icon: 'error',
            title: 'แจ้งเตือน!',
            text: '<?= htmlspecialchars($_SESSION['error_message']) ?>'
        });
        <?php unset($_SESSION['error_message']); ?>
    <?php endif; ?>
});

// 3. ฟังก์ชันลบข้อมูลด้วย SweetAlert2
function confirmDelete(id, name) {
    Swal.fire({
        title: 'ยืนยันการลบ?',
        html: `คุณแน่ใจหรือไม่ที่จะลบ <b>${name}</b>?<br><small class="text-danger">* ข้อมูลนี้จะไม่สามารถกู้คืนได้</small>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#6c757d',
        confirmButtonText: '<i class="fas fa-trash"></i> ใช่, ลบข้อมูล!',
        cancelButtonText: 'ยกเลิก'
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = `../delete.php?type=asset&id=${id}`;
        }
    });
}

// 4. ฟังก์ชันจัดการ QR Code
function showQRModal(assetId, assetCode, assetName) {
    document.getElementById('qrAssetCode').textContent = assetCode;
    document.getElementById('qrAssetName').textContent = assetName;
    const qrUrl = 'generate_qr.php?id=' + assetId;
    document.getElementById('qrCodeImage').src = qrUrl;
    const downloadLink = document.getElementById('qrDownloadLink');
    downloadLink.href = qrUrl;
    downloadLink.download = 'qr_' + assetCode + '.png';
    const modal = new bootstrap.Modal(document.getElementById('qrModal'));
    modal.show();
}

// แก้ไขฟังก์ชัน printQR ให้สะอาดและทำงานได้ถูกต้อง
function printQR() {
    const qrImage = document.getElementById('qrCodeImage').src;
    const assetCode = document.getElementById('qrAssetCode').textContent;
    const assetName = document.getElementById('qrAssetName').textContent;
    
    // สร้างหน้าต่างใหม่
    const printWindow = window.open('', '_blank', 'width=500,height=600');
    
    // เขียน HTML ลงในหน้าต่างใหม่
    printWindow.document.write(`
        <!DOCTYPE html>
        <html lang="th">
        <head>
            <title>พิมพ์ QR Code - ${assetCode}</title>
            <style>
                body { text-align: center; font-family: sans-serif; margin-top: 50px; }
                img { max-width: 300px; margin: 20px 0; }
                .info { font-size: 18px; margin-bottom: 10px; }
            </style>
        </head>
        <body>
            <h2>🏢 QR Code ครุภัณฑ์</h2>
            <div class="info"><strong>รหัส:</strong> ${assetCode}</div>
            <div class="info"><strong>ชื่อ:</strong> ${assetName}</div>
            <img src="${qrImage}" alt="QR Code">
            <p><small>ระบบจัดการครุภัณฑ์ วิทยาลัยการอาชีพวังไกลกังวล</small></p>
        </body>
        </html>
    `);
    
    printWindow.document.close();
    
    // สั่งพิมพ์หลังจากโหลดเสร็จ 500ms
    setTimeout(() => { 
        printWindow.print(); 
        printWindow.close(); 
    }, 500);
}
</script>

<script src="js/assets-list.js"></script>

<?php require 'footer.php'; ?>