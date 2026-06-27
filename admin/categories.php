<?php
session_start();
require '../config/db.php';

if (!isset($_SESSION["user_id"]) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_category'])) {
    $name = $_POST['name'];
    if ($name) {
        $stmt = $conn->prepare("INSERT INTO categories (name) VALUES (?)");
        $stmt->execute([$name]);
        header("Location: categories.php");
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_category'])) {
    $id = (int)$_POST['edit_category_id'];
    $name = $_POST['edit_category_name'];
    if ($id && $name) {
        $stmt = $conn->prepare("UPDATE categories SET name = ? WHERE category_id = ?");
        $stmt->execute([$name, $id]);
        header("Location: categories.php");
        exit;
    }
}

if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $stmt = $conn->prepare("DELETE FROM categories WHERE category_id = ?");
    $stmt->execute([$id]);
    header("Location: categories.php");
    exit;
}

$categories = $conn->query("SELECT * FROM categories ORDER BY category_id DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>จัดการหมวดหมู่</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="css/categories.css" rel="stylesheet">
</head>
<body>
<?php include 'header.php'; ?>
<div class="container fade-in">
    <!-- Page Header -->
    <div class="page-header mb-3">
        <h4><span class="header-icon">📂</span>จัดการหมวดหมู่</h4>
    </div>

    <!-- Add Button -->
    <div class="action-section text-start mb-3">
        <button class="btn-add" text-start data-bs-toggle="modal" data-bs-target="#addModal">
            <span class="icon">＋</span>เพิ่มหมวดหมู่
        </button>
    </div>

    <!-- Table Container -->
    <div class="table-container">
        <div class="table-header">
            <div class="table-title">
                <span class="icon">📂</span>รายการหมวดหมู่
                <span class="badge bg-primary ms-2"><?= count($categories) ?> หมวดหมู่</span>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th class="text-center">รหัสหมวดหมู่</th>
                        <th>ชื่อหมวดหมู่</th>
                        <th class="text-center">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($categories as $c): ?>
                        <tr>
                            <td class="text-center"><span class="location-id"><?= $c['category_id'] ?></span></td>
                            <td><?= htmlspecialchars($c['name']) ?></td>
                            <td class="text-center">
                                <span class="action-buttons">
                                    <button class="btn-edit" onclick="editCategory(<?= $c['category_id'] ?>, '<?= htmlspecialchars($c['name'], ENT_QUOTES) ?>')">
                                        <i class="fas fa-edit me-1"></i> แก้ไข
                                    </button>
                                    <a href="categories.php?delete=<?= $c['category_id'] ?>" class="btn-delete" style="text-decoration:none;" onclick="return confirm('ลบหมวดหมู่นี้หรือไม่?')">
                                        <i class="fas fa-trash me-1"></i> ลบ
                                    </a>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (count($categories) === 0): ?>
                        <tr>
                            <td colspan="3">
                                <div class="empty-state">
                                    <div class="icon">😕</div>
                                    <h5>ไม่มีข้อมูลหมวดหมู่</h5>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Back Button -->
    <a href="index.php" class="btn-back"><span class="icon">←</span>กลับหน้าครุภัณฑ์</a>
</div>

<!-- Modal เพิ่มหมวดหมู่ -->
<div class="modal fade" id="addModal" tabindex="-1" aria-labelledby="addModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <form method="POST" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="addModalLabel">เพิ่มหมวดหมู่ใหม่</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
      </div>
      <div class="modal-body">
        <label class="form-label">ชื่อหมวดหมู่</label>
        <input type="text" name="name" class="form-control" required>
      </div>
      <div class="modal-footer">
        <button type="submit" name="add_category" class="btn-primary">
          <i class="fas fa-save me-1"></i>
          บันทึก
        </button>
        <button type="button" class="btn-secondary" data-bs-dismiss="modal">
          <i class="fas fa-times me-1"></i>
          ยกเลิก
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Modal แก้ไขหมวดหมู่ -->
<div class="modal fade" id="editCategoryModal" tabindex="-1" aria-labelledby="editCategoryModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <form method="POST" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="editCategoryModalLabel">
          <i class="fas fa-edit me-2"></i>
          แก้ไขหมวดหมู่
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="edit_category_id" id="edit_category_id">
        <label class="form-label">ชื่อหมวดหมู่</label>
        <input type="text" name="edit_category_name" id="edit_category_name" class="form-control" required>
      </div>
      <div class="modal-footer">
        <button type="submit" name="edit_category" class="btn-primary">
          <i class="fas fa-save me-1"></i>
          บันทึกการแก้ไข
        </button>
        <button type="button" class="btn-secondary" data-bs-dismiss="modal">
          <i class="fas fa-times me-1"></i>
          ยกเลิก
        </button>
      </div>
    </form>
  </div>
</div>

<script>
function editCategory(id, name) {
    document.getElementById('edit_category_id').value = id;
    document.getElementById('edit_category_name').value = name;
    var modal = new bootstrap.Modal(document.getElementById('editCategoryModal'));
    modal.show();
}

// Fade-in animation for table rows
window.addEventListener('DOMContentLoaded', function() {
    const rows = document.querySelectorAll('tbody tr');
    rows.forEach((row, idx) => {
        row.style.animationDelay = (idx * 0.08) + 's';
        row.classList.add('fade-in');
    });
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
<?php
require 'footer.php';  // ปิด container, โหลด Bootstrap JS
?>
</html>
