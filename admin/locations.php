<?php
session_start();
if (!isset($_SESSION["user_id"]) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit;
}
require '../config/db.php';

// ดึงแผนกทั้งหมด
$departments = $conn->query("SELECT department_id, name FROM departments ORDER BY name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_location'])) {
    $name = $_POST['name'];
    $department_id = $_POST['department_id'];
    if ($name && $department_id) {
        $stmt = $conn->prepare("INSERT INTO locations (name, department_id) VALUES (?, ?)");
        $stmt->execute([$name, $department_id]);
        header("Location: locations.php");
        exit;
    }
}

if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $stmt = $conn->prepare("DELETE FROM locations WHERE location_id = ?");
    $stmt->execute([$id]);
    header("Location: locations.php");
    exit;
}

// PHP: เพิ่มฟังก์ชันแก้ไขสถานที่
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_location'])) {
    $id = (int)$_POST['edit_location_id'];
    $name = $_POST['edit_location_name'];
    $department_id = $_POST['edit_department_id'];
    if ($id && $name && $department_id) {
        $stmt = $conn->prepare("UPDATE locations SET name = ?, department_id = ? WHERE location_id = ?");
        $stmt->execute([$name, $department_id, $id]);
        header("Location: locations.php");
        exit;
    }
}

$locations = $conn->query("SELECT l.*, d.name AS department_name FROM locations l LEFT JOIN departments d ON l.department_id = d.department_id ORDER BY l.location_id DESC")->fetchAll();
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จัดการสถานที่ - ระบบจัดการครุภัณฑ์</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Custom CSS -->
    <?php require_once __DIR__ . '/../assets/kp_assets.php'; ?>
<link rel="stylesheet" href="<?= kp_asset('admin/css/locations.css', '../') ?>">
</head>
<body>
<?php include 'header.php'; ?>
    <div class="container fade-in">
        <!-- Page Header -->
        <div class="page-header mb-3">
            <h4>
                <i class="fas fa-map-marker-alt header-icon"></i>
                จัดการสถานที่
            </h4>
        </div>
        <!-- Action Section -->
        <div class="action-section mb-3">
            <button class="btn-add" data-bs-toggle="modal" data-bs-target="#addModal">
                <i class="fas fa-plus icon"></i>
                เพิ่มสถานที่ใหม่
            </button>
        </div>

        <!-- Table Container -->
        <div class="table-container">
            <div class="table-header">
                <h5 class="table-title">
                    <span class="icon">🏢</span>
                    รายชื่อสถานที่ทั้งหมด
                    <span class="badge bg-primary ms-2"><?= count($locations) ?> สถานที่</span>
                </h5>
            </div>
            
            <?php if (count($locations) > 0): ?>
                <div class="table-responsive">
                    <table class="table table-bordered align-middle">
                        <thead class="table-light">
                            <tr>
                                <th class="text-center">รหัสสถานที่</th>
                                <th>ชื่อสถานที่</th>
                                <th>แผนก</th>
                                <th class="text-center">จัดการ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($locations as $l): ?>
                                <tr>
                                    <td class="text-center">
                                        <span class="location-id"><?= str_pad($l['location_id'], 2, '0', STR_PAD_LEFT) ?></span>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <i class="fas fa-building text-muted me-2"></i>
                                            <strong><?= htmlspecialchars($l['name']) ?></strong>
                                        </div>
                                    </td>
                                    <td><?= htmlspecialchars($l['department_name']) ?></td>
                                    <td class="text-center">
                                        <span class="action-buttons">
                                            <button class="btn-edit" onclick="editLocation(<?= $l['location_id'] ?>, '<?= htmlspecialchars($l['name'], ENT_QUOTES) ?>', '<?= $l['department_id'] ?>')">
                                                <i class="fas fa-edit me-1"></i> แก้ไข
                                            </button>
                                            <a href="locations.php?delete=<?= $l['location_id'] ?>" class="btn-delete" style="text-decoration:none;" onclick="return confirm('ลบสถานที่นี้หรือไม่?')">
                                                <i class="fas fa-trash me-1"></i> ลบ
                                            </a>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-map-marker-alt icon"></i>
                    <h5>ยังไม่มีข้อมูลสถานที่</h5>
                    <p>คลิกปุ่ม "เพิ่มสถานที่ใหม่" เพื่อเริ่มต้นจัดการสถานที่</p>
                </div>
            <?php endif; ?>
        </div>
        <!-- Back Button -->
        <div class="text-start mt-3">
            <a href="index.php" class="btn-back">
                <i class="fas fa-arrow-left icon"></i>
                กลับหน้าครุภัณฑ์
            </a>
        </div>
    </div>

    <!-- Add Location Modal -->
    <div class="modal fade" id="addModal" tabindex="-1" aria-labelledby="addModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form method="POST" class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addModalLabel">
                        <i class="fas fa-plus me-2"></i>
                        เพิ่มสถานที่ใหม่
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">
                            <i class="fas fa-building me-1"></i>
                            ชื่อสถานที่
                        </label>
                        <input 
                            type="text" 
                            name="name" 
                            class="form-control" 
                            placeholder="กรุณาระบุชื่อสถานที่..."
                            required
                            autofocus>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">แผนก</label>
                        <select name="department_id" class="form-select" required>
                            <option value="">-- เลือกแผนก --</option>
                            <?php foreach ($departments as $d): ?>
                                <option value="<?= $d['department_id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" name="add_location" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>
                        บันทึกข้อมูล
                    </button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>
                        ยกเลิก
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Location Modal -->
    <div class="modal fade" id="editLocationModal" tabindex="-1" aria-labelledby="editLocationModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form method="POST" class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editLocationModalLabel">
                        <i class="fas fa-edit me-2"></i>
                        แก้ไขสถานที่
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="edit_location_id" id="edit_location_id">
                    <div class="mb-3">
                        <label class="form-label">
                            <i class="fas fa-building me-1"></i>
                            ชื่อสถานที่
                        </label>
                        <input type="text" name="edit_location_name" id="edit_location_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">แผนก</label>
                        <select name="edit_department_id" id="edit_department_id" class="form-select" required>
                            <option value="">-- เลือกแผนก --</option>
                            <?php foreach ($departments as $d): ?>
                                <option value="<?= $d['department_id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" name="edit_location" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>
                        บันทึกการแก้ไข
                    </button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>
                        ยกเลิก
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Custom JavaScript -->
    <script src="js/locations.js"></script>
    <script>
    function editLocation(id, name, department_id) {
        document.getElementById('edit_location_id').value = id;
        document.getElementById('edit_location_name').value = name;
        document.getElementById('edit_department_id').value = department_id;
        var modal = new bootstrap.Modal(document.getElementById('editLocationModal'));
        modal.show();
    }
    </script>
</body>
<?php
require 'footer.php';  // ปิด container, โหลด Bootstrap JS
?>
</html>