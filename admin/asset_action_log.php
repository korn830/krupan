<?php
session_start();
if (!isset($_SESSION["user_id"]) || $_SESSION['role'] !== 'admin') {
     header("Location: ../index.php");
     exit;
}
require '../config/db.php';

// ดึงข้อมูลประวัติทั้งหมด (ไม่ใช้ LIMIT แล้ว เพราะ DataTables จะจัดการให้)
$sql = "SELECT
            l.*,
            u.username,
            u.name AS user_name,
            COALESCE(a.asset_code, JSON_UNQUOTE(JSON_EXTRACT(l.details, '$.asset_code'))) AS asset_code,
            COALESCE(a.name, JSON_UNQUOTE(JSON_EXTRACT(l.details, '$.name'))) AS asset_name
        FROM asset_action_log l
        LEFT JOIN users u ON l.user_id = u.user_id
        LEFT JOIN assets a ON l.asset_id = a.asset_id
        ORDER BY l.action_time DESC";
$logs = $conn->query($sql)->fetchAll();

$departments = $conn->query("SELECT department_id, name FROM departments")->fetchAll(PDO::FETCH_KEY_PAIR);
$categories = $conn->query("SELECT category_id, name FROM categories")->fetchAll(PDO::FETCH_KEY_PAIR);
$locations = $conn->query("SELECT location_id, name FROM locations")->fetchAll(PDO::FETCH_KEY_PAIR);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>ประวัติการเคลื่อนไหว - ระบบจัดการครุภัณฑ์</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <?php require_once __DIR__ . '/../assets/kp_assets.php'; ?>
<link rel="stylesheet" href="<?= kp_asset('admin/css/asset_action_log.css', '../') ?>">
    <style>
        .dataTables_wrapper .pagination .page-item.active .page-link { background-color: #667eea; border-color: #667eea; }
        .dataTables_filter input { border-radius: 8px; padding: 5px 10px; border: 1px solid #ced4da; }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<div class="container fade-in mt-4">
    <div class="page-header mb-3" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 2rem; border-radius: 20px; box-shadow: 0 10px 30px rgba(102, 126, 234, 0.2);">
        <h4 class="m-0"><i class="fas fa-history me-2"></i> ประวัติการเคลื่อนไหว (Activity Log)</h4>
    </div>
    
    <div class="table-container bg-white p-4" style="border-radius: 16px; box-shadow: 0 10px 25px rgba(0,0,0,0.05);">
        <div class="table-responsive">
            <table id="logTable" class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th>วัน-เวลา</th>
                        <th>ประเภท</th>
                        <th>ครุภัณฑ์</th>
                        <th>ผู้ดำเนินการ</th>
                        <th>รายละเอียด</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><?= htmlspecialchars($log['action_time']) ?></td>
                            <td>
                                <?php
                                if ($log['action_type'] === 'add') {
                                    echo '<span class="badge bg-success"><i class="fas fa-plus"></i> เพิ่มครุภัณฑ์</span>';
                                } elseif ($log['action_type'] === 'delete') {
                                    echo '<span class="badge bg-danger"><i class="fas fa-trash"></i> ลบ</span>';
                                } elseif ($log['action_type'] === 'edit') {
                                    echo '<span class="badge bg-warning text-dark"><i class="fas fa-edit"></i> อัปเดต/แก้ไข</span>';
                                } else {
                                    echo '<span class="badge bg-secondary"><i class="fas fa-sync"></i> ' . htmlspecialchars($log['action_type']) . '</span>';
                                }
                                ?>
                            </td>
                            <td>
                                <strong class="text-primary"><?= htmlspecialchars($log['asset_code'] ?? 'N/A') ?></strong><br>
                                <small class="text-muted"><?= htmlspecialchars($log['asset_name'] ?? 'N/A') ?></small>
                            </td>
                            <td><i class="fas fa-user-circle text-muted me-1"></i> <?= htmlspecialchars($log['user_name'] ?: $log['username']) ?></td>
                            <td>
                                <?php if ($log['action_type'] === 'add'): ?>
                                    <button class="btn btn-sm btn-outline-success view-add-details-btn" data-details='<?= htmlspecialchars($log['details'], ENT_QUOTES) ?>'><i class="fas fa-search"></i> ดูข้อมูล</button>
                                <?php elseif ($log['action_type'] === 'edit' && $log['details'] && $log['details'] !== 'null'): ?>
                                    <button class="btn btn-sm btn-outline-warning text-dark view-diff-btn" data-details='<?= htmlspecialchars($log['details'], ENT_QUOTES) ?>'><i class="fas fa-search"></i> ดูข้อมูล</button>
                                <?php elseif ($log['action_type'] === 'delete'): ?>
                                    <button class="btn btn-sm btn-outline-danger view-delete-details-btn" data-details='<?= htmlspecialchars($log['details'], ENT_QUOTES) ?>'><i class="fas fa-search"></i> ดูข้อมูล</button>
                                <?php else: ?>
                                    <span class="text-muted small">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="detailsModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content" style="border-radius: 15px; overflow: hidden;">
      <div class="modal-header bg-dark text-white">
        <h5 class="modal-title" id="detailsModalLabel"><i class="fas fa-file-alt me-2"></i> รายละเอียดการบันทึก</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-4" id="detailsContent"></div>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

<script>
window.departmentMap = <?= json_encode($departments, JSON_UNESCAPED_UNICODE) ?>;
window.categoryMap = <?= json_encode($categories, JSON_UNESCAPED_UNICODE) ?>;
window.locationMap = <?= json_encode($locations, JSON_UNESCAPED_UNICODE) ?>;

// ฟังก์ชัน format ข้อมูลเดิมของคุณ
function formatFieldName(key) {
    const map = { 'department_id': 'แผนก', 'category_id': 'หมวดหมู่', 'location_id': 'สถานที่', 'status': 'สถานะ', 'name': 'ชื่อ', 'note': 'หมายเหตุ' };
    return map[key] || key;
}
function formatFieldValue(key, value) {
    if(!value) return '-';
    if(key === 'department_id') return window.departmentMap[value] || value;
    if(key === 'category_id') return window.categoryMap[value] || value;
    if(key === 'location_id') return window.locationMap[value] || value;
    return value;
}

$(document).ready(function() {
    // ใช้งาน DataTables
    $('#logTable').DataTable({
        "language": {
            "sLengthMenu": "แสดง _MENU_ รายการ",
            "sZeroRecords": "ไม่พบข้อมูลการบันทึก",
            "sInfo": "แสดง _START_ ถึง _END_ จากทั้งหมด _TOTAL_ รายการ",
            "sSearch": "🔍 ค้นหา (ชื่อ, รหัส, คนทำ):",
            "oPaginate": { "sPrevious": "ย้อนกลับ", "sNext": "ถัดไป" }
        },
        "order": [[0, 'desc']], // เรียงวันที่ล่าสุดขึ้นก่อน
        "pageLength": 15
    });

    // Script ปุ่มดูรายละเอียด (ใช้ Logic เดิมของคุณเพื่อความปลอดภัย)
    $('.table-responsive').on('click', '.view-diff-btn', function() {
        let diff = JSON.parse($(this).attr('data-details') || '{}');
        let html = '<table class="table table-bordered"><thead><tr class="table-light"><th>หัวข้อ</th><th>ข้อมูลเดิม</th><th>ข้อมูลใหม่ (ที่ถูกเปลี่ยน)</th></tr></thead><tbody>';
        for (const key in diff) {
            if(key === 'action' || key === 'note' || key === 'ip') continue; // ข้ามข้อมูลเชิงระบบ
            let fName = formatFieldName(key);
            let oVal = formatFieldValue(key, diff[key]?.old);
            let nVal = formatFieldValue(key, diff[key]?.new || diff[key]); // รองรับรูปแบบที่บันทึกมาจาก QR Update
            html += `<tr><td class="fw-bold">${fName}</td><td class="text-muted text-decoration-line-through">${oVal}</td><td class="text-success fw-bold">${nVal}</td></tr>`;
        }
        
        // ถ้ามี Note แนบมาจากการสแกน QR
        if(diff.note) {
            html += `<tr><td class="fw-bold bg-light">หมายเหตุ</td><td colspan="2" class="text-primary">${diff.note}</td></tr>`;
        }
        html += '</tbody></table>';
        $('#detailsContent').html(html);
        new bootstrap.Modal(document.getElementById('detailsModal')).show();
    });

    $('.table-responsive').on('click', '.view-add-details-btn, .view-delete-details-btn', function() {
        let details = JSON.parse($(this).attr('data-details') || '{}');
        let html = '<table class="table table-bordered"><tbody>';
        for (const key in details) {
            if (key === 'action' || key === 'asset_id') continue;
            html += `<tr><td class="fw-bold bg-light w-25">${formatFieldName(key)}</td><td>${formatFieldValue(key, details[key])}</td></tr>`;
        }
        html += '</tbody></table>';
        $('#detailsContent').html(html);
        new bootstrap.Modal(document.getElementById('detailsModal')).show();
    });
});
</script>
</body>
</html>