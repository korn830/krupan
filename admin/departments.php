<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION["user_id"]) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit;
}
require '../config/db.php';

// เพิ่มแผนก
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_department'])) {
    $name = $_POST['name'];
    if ($name) {
        $stmt = $conn->prepare("INSERT INTO departments (name) VALUES (?)");
        $stmt->execute([$name]);
        header("Location: departments.php");
        exit;
    }
}

// ลบแผนก
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    // ตรวจสอบว่ามี assets อ้างถึงแผนกนี้หรือไม่
    $count = $conn->prepare("SELECT COUNT(*) FROM assets WHERE department_id = ?");
    $count->execute([$id]);
    $assetCount = $count->fetchColumn();
    if ($assetCount > 0) {
        // redirect พร้อมแจ้งเตือนผ่าน query string
        header("Location: departments.php?error=has_assets");
        exit;
    }
    $stmt = $conn->prepare("DELETE FROM departments WHERE department_id = ?");
    $stmt->execute([$id]);
    header("Location: departments.php");
    exit;
}

// แก้ไขแผนก
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_department'])) {
    $id = $_POST['edit_department_id'];
    $name = $_POST['edit_department_name'];
    if ($id && $name) {
        $stmt = $conn->prepare("UPDATE departments SET name = ? WHERE department_id = ?");
        $stmt->execute([$name, $id]);
        header("Location: departments.php");
        exit;
    }
}

$departments = $conn->query("SELECT * FROM departments ORDER BY department_id DESC")->fetchAll();
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>จัดการแผนก</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <?php require_once __DIR__ . '/../assets/kp_assets.php'; ?>
<link rel="stylesheet" href="<?= kp_asset('admin/css/departments.css', '../') ?>">
</head>
<body>
<?php include 'header.php'; ?>
<div class="container fade-in">
    <div class="page-header mb-3">
        <h4><span class="header-icon">🏢</span> จัดการแผนก</h4>
    </div>

    <div class="action-section text-start mb-3">
        <button class="btn-add" data-bs-toggle="modal" data-bs-target="#addModal">
            <i class="fas fa-plus icon"></i>
            เพิ่มแผนก
        </button>
    </div>

    <div class="table-container">
        <div class="table-header">
            <h5 class="table-title">
                <span class="icon">🏢</span>
                รายชื่อแผนกทั้งหมด
                <span class="badge bg-primary ms-2"><?= count($departments) ?> แผนก</span>
            </h5>
        </div>
        <div class="table-responsive">
            <table class="table table-bordered align-middle">
                <thead class="table-light">
                    <tr>
                        <th class="text-center">รหัสแผนก</th>
                        <th>ชื่อแผนก</th>
                        <th class="text-center">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($departments as $d): ?>
                        <tr>
                            <td class="text-center"><span class="department-id"><?= $d['department_id'] ?></span></td>
                            <td><?= htmlspecialchars($d['name']) ?></td>
                            <td class="text-center">
                                <span class="action-buttons">
                                    <button class="btn-edit" onclick="editDepartment(<?= $d['department_id'] ?>, '<?= htmlspecialchars($d['name'], ENT_QUOTES) ?>')">
                                        <i class="fas fa-edit me-1"></i> แก้ไข
                                    </button>
                                    <a href="#" class="btn-delete" style="text-decoration:none;" data-id="<?= $d['department_id'] ?>" data-name="<?= htmlspecialchars($d['name'], ENT_QUOTES) ?>" onclick="showDeleteModal(this); return false;">
                                        <i class="fas fa-trash me-1"></i> ลบ
                                    </a>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (count($departments) === 0): ?>
                        <tr>
                            <td colspan="3" class="text-center text-muted">ไม่มีข้อมูลแผนก</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <a href="index.php" class="btn btn-secondary mt-3">
        <i class="fas fa-arrow-left icon"></i> กลับหน้าหลัก
    </a>
</div>

<!-- Modal -->
<div class="modal fade" id="addModal" tabindex="-1" aria-labelledby="addModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <form method="POST" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="addModalLabel">เพิ่มแผนกใหม่</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
      </div>
      <div class="modal-body">
        <label class="form-label">ชื่อแผนก</label>
        <input type="text" name="name" class="form-control" required>
      </div>
      <div class="modal-footer">
        <button type="submit" name="add_department" class="btn btn-primary">
          <i class="fas fa-save me-1"></i>
          บันทึก
        </button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
          <i class="fas fa-times me-1"></i>
          ยกเลิก
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Edit Department Modal -->
<div class="modal fade" id="editDepartmentModal" tabindex="-1" aria-labelledby="editDepartmentModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <form method="POST" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="editDepartmentModalLabel">
          <i class="fas fa-edit me-2"></i>
          แก้ไขแผนก
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="edit_department_id" id="edit_department_id">
        <label class="form-label">ชื่อแผนก</label>
        <input type="text" name="edit_department_name" id="edit_department_name" class="form-control" required>
      </div>
      <div class="modal-footer">
        <button type="submit" name="edit_department" class="btn btn-primary">
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

<!-- Modal ยืนยันการลบแผนก -->
<div class="modal fade" id="deleteDepartmentModal" tabindex="-1" aria-labelledby="deleteDepartmentModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="deleteDepartmentModalLabel"><i class="fas fa-trash me-2 text-danger"></i>ยืนยันการลบแผนก</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
      </div>
      <div class="modal-body">
        <p>คุณต้องการลบแผนก <strong id="deleteDepartmentName"></strong> หรือไม่?</p>
        <p class="text-danger small mb-0">* การลบนี้ไม่สามารถย้อนกลับได้</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
        <a href="#" id="confirmDeleteDepartmentBtn" class="btn btn-danger">ยืนยันลบ</a>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function showDeleteModal(el) {
    var id = el.getAttribute('data-id');
    var name = el.getAttribute('data-name');
    document.getElementById('deleteDepartmentName').textContent = name;
    document.getElementById('confirmDeleteDepartmentBtn').href = 'departments.php?delete=' + id;
    var modal = new bootstrap.Modal(document.getElementById('deleteDepartmentModal'));
    modal.show();
}
function editDepartment(id, name) {
    document.getElementById('edit_department_id').value = id;
    document.getElementById('edit_department_name').value = name;
    var modal = new bootstrap.Modal(document.getElementById('editDepartmentModal'));
    modal.show();
}
// แสดง popup แจ้งเตือนถ้าลบไม่ได้เพราะมี assets อ้างถึง
<?php if (isset($_GET['error']) && $_GET['error'] === 'has_assets'): ?>
document.addEventListener('DOMContentLoaded', function() {
    var alertModal = document.createElement('div');
    alertModal.innerHTML = `
    <div class="modal fade" id="cannotDeleteModal" tabindex="-1" aria-labelledby="cannotDeleteModalLabel" aria-hidden="true">
      <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header bg-danger text-white">
            <h5 class="modal-title" id="cannotDeleteModalLabel"><i class="fas fa-exclamation-triangle me-2"></i> ไม่สามารถลบแผนกได้</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
          </div>
          <div class="modal-body">
            <p>ไม่สามารถลบแผนกนี้ได้ เนื่องจากมีครุภัณฑ์ที่ยังอ้างถึงแผนกนี้อยู่ในระบบ<br>กรุณาย้ายหรือแก้ไขข้อมูลครุภัณฑ์ก่อนลบแผนกนี้</p>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ปิด</button>
          </div>
        </div>
      </div>
    </div>`;
    document.body.appendChild(alertModal);
    var modal = new bootstrap.Modal(document.getElementById('cannotDeleteModal'));
    modal.show();
});
<?php endif; ?>

// Fade-in animation for table rows
window.addEventListener('DOMContentLoaded', function() {
    const rows = document.querySelectorAll('tbody tr');
    rows.forEach((row, idx) => {
        row.style.animationDelay = (idx * 0.08) + 's';
        row.classList.add('fade-in');
    });
});
</script>
</body>
<?php
require 'footer.php';  // ปิด container, โหลด Bootstrap JS
?>
</html>
