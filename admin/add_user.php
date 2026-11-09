<?php
session_start();
require_once __DIR__ . '/../assets/auth.php'; // โหลด roles.php ให้ด้วย และมี KP_STATUS_* ที่หน้านี้ใช้
kp_require_cap('user.manage', '../');
require '../config/db.php';

/**
 * นับจำนวนบัญชีหัวหน้าพัสดุที่ยังใช้งานอยู่ (นับ admin เดิมด้วย)
 * ใช้กันไม่ให้ลบหรือลดสิทธิ์จนไม่เหลือคนจัดการผู้ใช้เลย
 */
function kp_count_heads(PDO $conn): int
{
    return (int)$conn->query(
        "SELECT COUNT(*) FROM users WHERE role IN ('head', 'admin')"
    )->fetchColumn();
}

/** บัญชีนี้เป็นหัวหน้าพัสดุคนสุดท้ายหรือไม่ */
function kp_is_last_head(PDO $conn, int $userId): bool
{
    $stmt = $conn->prepare("SELECT role FROM users WHERE user_id = ?");
    $stmt->execute([$userId]);
    $role = $stmt->fetchColumn();
    if ($role === false) {
        return false;
    }
    return kp_normalize_role($role) === KP_ROLE_HEAD && kp_count_heads($conn) <= 1;
}

// Initialize message variable
$message = '';

// Handle form submission for adding a new user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_user') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $name = trim($_POST['name']);
    $role = $_POST['role'];

    if ($username && $password && $name && in_array($role, [KP_ROLE_USER, KP_ROLE_OFFICER, KP_ROLE_HEAD], true)) {
        $dbRole = $role;
        
        // Check for duplicate username
        $stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetchColumn() > 0) {
            $message = '<div class="alert alert-danger">ชื่อผู้ใช้นี้มีอยู่แล้ว</div>';
        } else {
            // Hash the password
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO users (username, password_hash, name, role) VALUES (?, ?, ?, ?)");
            if ($stmt->execute([$username, $hash, $name, $dbRole])) {
                $message = '<div class="alert alert-success">เพิ่มผู้ใช้ใหม่เรียบร้อยแล้ว</div>';
            } else {
                $message = '<div class="alert alert-danger">เกิดข้อผิดพลาดในการเพิ่มผู้ใช้</div>';
            }
        }
    } else {
        $message = '<div class="alert alert-danger">กรุณากรอกข้อมูลให้ครบถ้วน</div>';
    }
}

// Handle delete user พร้อมลบ log
if (isset($_GET['delete_user_id'])) {
    $delete_id = (int)$_GET['delete_user_id'];
    if ($delete_id === (int)$_SESSION['user_id']) {
        $message = '<div class="alert alert-danger">ไม่สามารถลบบัญชีของตัวเองได้</div>';
    } elseif ($delete_id > 0 && kp_is_last_head($conn, $delete_id)) {
        // ถ้าลบไป จะไม่เหลือใครจัดการผู้ใช้อีกเลย
        $message = '<div class="alert alert-danger">ต้องมีหัวหน้าพัสดุอย่างน้อย 1 บัญชีเสมอ จึงลบบัญชีนี้ไม่ได้</div>';
    } elseif ($delete_id > 0) {
        try {
            // ตรวจสอบว่าผู้ใช้ยังมีรายการยืมอยู่หรือไม่
            $stmt = $conn->prepare("SELECT COUNT(*) FROM borrow_history WHERE user_id = ?");
            $stmt->execute([$delete_id]);
            $borrowCount = $stmt->fetchColumn();

            if ($borrowCount > 0) {
                // ถ้ามียืมอยู่ ไม่ลบ และแจ้งเตือน
                $message = '<div class="alert alert-danger">ไม่สามารถลบผู้ใช้ได้ เนื่องจากยังมีรายการยืมค้างอยู่</div>';
            } else {
                // ลบ log ที่เกี่ยวข้องก่อน
                $stmt = $conn->prepare("DELETE FROM asset_action_log WHERE user_id = ?");
                $stmt->execute([$delete_id]);

                // ลบผู้ใช้
                $stmt = $conn->prepare("DELETE FROM users WHERE user_id = ?");
                $stmt->execute([$delete_id]);

                $message = '<div class="alert alert-success">ลบผู้ใช้และข้อมูล log ที่เกี่ยวข้องเรียบร้อยแล้ว</div>';
            }
        } catch (PDOException $e) {
            $message = '<div class="alert alert-danger">เกิดข้อผิดพลาด: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
    }
}
// อนุมัติหรือปฏิเสธคำขอสมัคร — ทำได้เฉพาะหัวหน้าพัสดุ (หน้านี้กันด้วย user.manage อยู่แล้ว)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])
    && in_array($_POST['action'], ['approve_user', 'reject_user'], true)) {

    $target = (int)($_POST['pending_user_id'] ?? 0);
    $decision = $_POST['action'] === 'approve_user' ? KP_STATUS_APPROVED : KP_STATUS_REJECTED;

    // สิทธิ์ที่จะให้ตอนอนุมัติ เลือกได้จากฟอร์ม แต่ต้องเป็นค่าที่ยอมรับเท่านั้น
    $grantRole = $_POST['grant_role'] ?? KP_ROLE_USER;
    if (!in_array($grantRole, [KP_ROLE_USER, KP_ROLE_OFFICER, KP_ROLE_HEAD], true)) {
        $grantRole = KP_ROLE_USER;
    }

    if ($target <= 0) {
        $message = '<div class="alert alert-danger">ไม่พบคำขอที่ต้องการ</div>';
    } else {
        try {
            if ($decision === KP_STATUS_APPROVED) {
                // อนุมัติพร้อมตั้งสิทธิ์ให้ในคำสั่งเดียว
                $stmt = $conn->prepare(
                    "UPDATE users SET status = ?, role = ? WHERE user_id = ? AND status = ?"
                );
                $ok = $stmt->execute([KP_STATUS_APPROVED, $grantRole, $target, KP_STATUS_PENDING]);
            } else {
                $stmt = $conn->prepare(
                    "UPDATE users SET status = ? WHERE user_id = ? AND status = ?"
                );
                $ok = $stmt->execute([KP_STATUS_REJECTED, $target, KP_STATUS_PENDING]);
            }

            // เงื่อนไข status = pending กันการกดซ้ำ และกันการไปแก้บัญชีที่อนุมัติไปแล้ว
            if ($ok && $stmt->rowCount() > 0) {
                $message = $decision === KP_STATUS_APPROVED
                    ? '<div class="alert alert-success">อนุมัติผู้ใช้เรียบร้อยแล้ว</div>'
                    : '<div class="alert alert-success">ปฏิเสธคำขอเรียบร้อยแล้ว</div>';
            } else {
                $message = '<div class="alert alert-warning">คำขอนี้ถูกดำเนินการไปแล้ว</div>';
            }
        } catch (PDOException $e) {
            $message = '<div class="alert alert-danger">เกิดข้อผิดพลาด: '
                     . htmlspecialchars($e->getMessage()) . '</div>';
        }
    }
}

// Handle edit user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_user') {
    $edit_id = (int)$_POST['edit_user_id'];
    $edit_name = trim($_POST['edit_name']);
    $edit_role = $_POST['edit_role'];
    $edit_password = $_POST['edit_password'] ?? '';
    $selfDemotion = ($edit_id === (int)$_SESSION['user_id'] && $edit_role !== KP_ROLE_HEAD);

    // ถ้ากำลังลดสิทธิ์หัวหน้าพัสดุคนสุดท้าย ระบบจะไม่เหลือคนจัดการผู้ใช้
    $lastHead = ($edit_id > 0 && $edit_role !== KP_ROLE_HEAD && kp_is_last_head($conn, $edit_id));

    if ($selfDemotion) {
        $message = '<div class="alert alert-danger">ไม่สามารถลดสิทธิ์ของบัญชีตัวเองได้</div>';
    } elseif ($lastHead) {
        $message = '<div class="alert alert-danger">ต้องมีหัวหน้าพัสดุอย่างน้อย 1 บัญชีเสมอ</div>';
    } elseif ($edit_id > 0 && $edit_name && in_array($edit_role, [KP_ROLE_USER, KP_ROLE_OFFICER, KP_ROLE_HEAD], true)) {
        if ($edit_password) {
            $hash = password_hash($edit_password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET name = ?, role = ?, password_hash = ? WHERE user_id = ?");
            $success = $stmt->execute([$edit_name, $edit_role, $hash, $edit_id]);
        } else {
            $stmt = $conn->prepare("UPDATE users SET name = ?, role = ? WHERE user_id = ?");
            $success = $stmt->execute([$edit_name, $edit_role, $edit_id]);
        }
        if ($success) {
            $message = '<div class="alert alert-success">แก้ไขข้อมูลผู้ใช้เรียบร้อยแล้ว</div>';
        } else {
            $message = '<div class="alert alert-danger">เกิดข้อผิดพลาดในการแก้ไขผู้ใช้</div>';
        }
    } else {
        $message = '<div class="alert alert-danger">กรุณากรอกข้อมูลให้ครบถ้วน</div>';
    }
}

// คำขอสมัครที่ยังรออนุมัติ
// ฐานข้อมูลที่ยังไม่ได้รันไมเกรชัน 003 จะไม่มีคอลัมน์ status
// หน้านี้ต้องยังใช้งานได้ จึงถือว่าไม่มีคำขอค้างอยู่แทนที่จะพังทั้งหน้า
$pending      = [];
$hasApprovals = true;
try {
    $ps = $conn->prepare(
        "SELECT user_id, username, name, created_at
           FROM users WHERE status = ? ORDER BY created_at ASC, user_id ASC"
    );
    $ps->execute([KP_STATUS_PENDING]);
    $pending = $ps->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $hasApprovals = false;
    error_log('pending users: ' . $e->getMessage());
}

// Fetch all users for the table display
$users = [];
try {
    if ($hasApprovals) {
        // ซ่อนคำขอที่ยังไม่อนุมัติออกจากรายชื่อหลัก เพราะมีตารางของตัวเองอยู่ด้านบน
        $stmt = $conn->prepare(
            "SELECT user_id, username, name, role FROM users WHERE status <> ? ORDER BY user_id DESC"
        );
        $stmt->execute([KP_STATUS_PENDING]);
    } else {
        $stmt = $conn->prepare("SELECT user_id, username, name, role FROM users ORDER BY user_id DESC");
        $stmt->execute();
    }
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Only set message if no other message is already set from form submission
    if (empty($message)) {
        $message = '<div class="alert alert-danger">เกิดข้อผิดพลาดในการดึงข้อมูลผู้ใช้: ' . $e->getMessage() . '</div>';
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>จัดการผู้ใช้</title>
    <link href="../assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/vendor/fontawesome/css/all.min.css">
    <?php require_once __DIR__ . '/../assets/kp_assets.php'; ?>
<link rel="stylesheet" href="<?= kp_asset('admin/css/add_user.css', '../') ?>">
</head>
<body>
<?php include 'header.php'; ?>
<div class="container mt-4 fade-in" style="max-width: 900px;">
    <div class="page-header mb-3">
        <h4><i class="fas fa-users me-2"></i> จัดการผู้ใช้ระบบ</h4>
    </div>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <button type="button" class="btn-add" data-bs-toggle="modal" data-bs-target="#addUserModal">
            <i class="fas fa-user-plus me-1"></i> เพิ่มผู้ใช้ใหม่
        </button>
    </div>

    <?= $message ?>

    <?php if ($pending): ?>
    <div class="pending-panel">
        <h6 class="pending-title">
            <i class="fas fa-user-clock me-1"></i>
            คำขอสมัครที่รออนุมัติ
            <span class="pending-count"><?= count($pending) ?></span>
        </h6>
        <p class="pending-help">
            ผู้ที่สมัครเองจะยังเข้าระบบไม่ได้จนกว่าจะอนุมัติ
            เลือกสิทธิ์ที่ต้องการให้ก่อนกดอนุมัติ
        </p>

        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr>
                        <th>ชื่อผู้ใช้</th>
                        <th>ชื่อ-นามสกุล</th>
                        <th>สมัครเมื่อ</th>
                        <th>ให้สิทธิ์เป็น</th>
                        <th>จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($pending as $req): ?>
                    <tr>
                        <td><?= htmlspecialchars($req['username']) ?></td>
                        <td><?= htmlspecialchars((string)$req['name']) ?></td>
                        <td class="text-muted">
                            <?= $req['created_at']
                                ? htmlspecialchars(date('d/m/', strtotime($req['created_at']))
                                    . (date('Y', strtotime($req['created_at'])) + 543))
                                : '-' ?>
                        </td>
                        <td>
                            <form method="POST" class="d-flex gap-2 align-items-center m-0">
                                <input type="hidden" name="pending_user_id" value="<?= (int)$req['user_id'] ?>">
                                <select name="grant_role" class="form-select form-select-sm" style="min-width:150px;">
                                    <option value="<?= KP_ROLE_USER ?>">ครู</option>
                                    <option value="<?= KP_ROLE_OFFICER ?>">เจ้าหน้าที่พัสดุ</option>
                                    <option value="<?= KP_ROLE_HEAD ?>">หัวหน้าพัสดุ</option>
                                </select>
                        </td>
                        <td class="text-nowrap">
                                <button type="submit" name="action" value="approve_user"
                                        class="btn btn-sm btn-success">
                                    <i class="fas fa-check"></i> อนุมัติ
                                </button>
                                <button type="submit" name="action" value="reject_user"
                                        class="btn btn-sm btn-outline-danger"
                                        onclick="return confirm('ปฏิเสธคำขอของ <?= htmlspecialchars($req['username'], ENT_QUOTES) ?> ?');">
                                    <i class="fas fa-xmark"></i> ปฏิเสธ
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php if (empty($users)): ?>
        <div class="alert alert-info">ยังไม่มีผู้ใช้งานในระบบ</div>
    <?php else: ?>
        <div class="table-responsive card p-3 shadow-sm">
            <table class="table table-striped table-hover mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>ชื่อผู้ใช้</th>
                        <th>ชื่อ-นามสกุล</th>
                        <th>สิทธิ์การใช้งาน</th>
                        <th>จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $i = 1; foreach ($users as $user): ?>
                        <tr>
                            <td><?= $i++ ?></td>
                            <td><?= htmlspecialchars($user['username']) ?></td>
                            <td><?= htmlspecialchars($user['name']) ?></td>
                            <td>
                                <?php
                                    $roleBadge = [
                                        KP_ROLE_HEAD    => 'bg-danger',
                                        KP_ROLE_OFFICER => 'bg-primary',
                                        KP_ROLE_USER    => 'bg-success',
                                    ];
                                    $r = kp_normalize_role($user['role']);
                                    printf('<span class="badge %s">%s</span>', $roleBadge[$r], htmlspecialchars(kp_role_label($r)));
                                ?>
                            </td>
                            <td>
                                <button type="button" class="btn btn-sm btn-warning me-1 edit-user-btn" 
                                    data-user-id="<?= $user['user_id'] ?>" 
                                    data-username="<?= htmlspecialchars($user['username']) ?>" 
                                    data-name="<?= htmlspecialchars($user['name']) ?>" 
                                    data-role="<?= kp_normalize_role($user['role']) ?>"
                                    title="แก้ไข"><i class="fas fa-edit"></i></button>
                                <a href="?delete_user_id=<?= $user['user_id'] ?>" class="btn btn-sm btn-danger" title="ลบ" onclick="return confirm('คุณแน่ใจหรือไม่ว่าต้องการลบผู้ใช้นี้?');"><i class="fas fa-trash-alt"></i></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <div class="text-start mt-3">
        <a href="index.php" class="btn-back"><i class="fas fa-arrow-left me-1"></i> กลับหน้าหลัก</a>
    </div>
</div>

<div class="modal fade" id="addUserModal" tabindex="-1" aria-labelledby="addUserModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addUserModalLabel"><i class="fas fa-user-plus me-2"></i> เพิ่มผู้ใช้ใหม่</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_user">
                    <div class="mb-3">
                        <label class="form-label">ชื่อผู้ใช้ (username)</label>
                        <input type="text" name="username" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">รหัสผ่าน</label>
                        <input type="password" name="password" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ชื่อ-นามสกุล</label>
                        <input type="text" name="name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">สิทธิ์การใช้งาน</label>
                        <select name="role" class="form-select" required>
                            <option value="<?= KP_ROLE_USER ?>">ครู — ยืมครุภัณฑ์ได้อย่างเดียว</option>
                            <option value="<?= KP_ROLE_OFFICER ?>">เจ้าหน้าที่พัสดุ — จัดการทะเบียนและอนุมัติการยืม</option>
                            <option value="<?= KP_ROLE_HEAD ?>">หัวหน้าพัสดุ — เพิ่มสิทธิ์ลบ นำเข้าข้อมูล และจัดการผู้ใช้</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i> บันทึก</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal แก้ไขผู้ใช้ -->
<div class="modal fade" id="editUserModal" tabindex="-1" aria-labelledby="editUserModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editUserModalLabel"><i class="fas fa-user-edit me-2"></i> แก้ไขผู้ใช้</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="edit_user">
                <input type="hidden" name="edit_user_id" id="edit_user_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">ชื่อผู้ใช้ (username)</label>
                        <input type="text" id="edit_username" class="form-control" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ชื่อ-นามสกุล</label>
                        <input type="text" name="edit_name" id="edit_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">สิทธิ์การใช้งาน</label>
                        <select name="edit_role" id="edit_role" class="form-select" required>
                            <option value="<?= KP_ROLE_USER ?>">ครู — ยืมครุภัณฑ์ได้อย่างเดียว</option>
                            <option value="<?= KP_ROLE_OFFICER ?>">เจ้าหน้าที่พัสดุ — จัดการทะเบียนและอนุมัติการยืม</option>
                            <option value="<?= KP_ROLE_HEAD ?>">หัวหน้าพัสดุ — เพิ่มสิทธิ์ลบ นำเข้าข้อมูล และจัดการผู้ใช้</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">รหัสผ่านใหม่ <span class="text-muted small">(ถ้าไม่เปลี่ยนให้เว้นว่าง)</span></label>
                        <input type="password" name="edit_password" id="edit_password" class="form-control" autocomplete="new-password">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i> บันทึก</button>
                </div>
            </form>
        </div>
    </div>
</div>
<div class="modal fade" id="confirmDeleteModal" tabindex="-1" aria-labelledby="confirmDeleteLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-warning text-dark">
        <h5 class="modal-title" id="confirmDeleteLabel"><i class="fas fa-exclamation-triangle me-2"></i> ยืนยันการลบผู้ใช้</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        คุณแน่ใจหรือไม่ว่าต้องการลบผู้ใช้ <strong id="deleteUsername"></strong> ?
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
        <a href="#" class="btn btn-danger" id="confirmDeleteBtn">ลบเลย</a>
      </div>
    </div>
  </div>
</div>
<div class="modal fade" id="deleteAssetModal" tabindex="-1" aria-labelledby="deleteAssetModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title" id="deleteAssetModalLabel"><i class="fas fa-trash me-2"></i> ยืนยันการลบครุภัณฑ์</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
      </div>
      <div class="modal-body">
        <p>คุณต้องการลบครุภัณฑ์ <strong id="deleteAssetName"></strong> หรือไม่?</p>
        <p class="text-danger small mb-0">* การลบนี้ไม่สามารถย้อนกลับได้</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
        <button type="button" class="btn btn-danger" id="confirmDeleteAssetBtn">ยืนยันลบ</button>
      </div>
    </div>
  </div>
</div>

<script src="../assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script>
document.querySelectorAll('.edit-user-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        document.getElementById('edit_user_id').value = this.dataset.userId;
        document.getElementById('edit_username').value = this.dataset.username;
        document.getElementById('edit_name').value = this.dataset.name;
        document.getElementById('edit_role').value = this.dataset.role;
        var editModal = new bootstrap.Modal(document.getElementById('editUserModal'));
        editModal.show();
    });
});
</script>
</body>
<?php
require 'footer.php';  // ปิด container, โหลด Bootstrap JS
?>
</html>