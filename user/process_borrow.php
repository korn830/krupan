<?php
/**
 * process_borrow.php
 * รับ POST จาก borrow_request.php — บันทึกคำขอยืมพร้อมเอกสารแนบลงฐานข้อมูล
 */
session_start();
if (!isset($_SESSION["user_id"])) { header("Location: ../index.php"); exit; }

// รับแค่ POST เท่านั้น
if ($_SERVER["REQUEST_METHOD"] !== "POST") { header("Location: list.php"); exit; }

require_once dirname(__DIR__) . '/config/db.php';

$asset_id     = (int)($_POST['asset_id'] ?? 0);
$user_id      = (int)$_SESSION['user_id'];
$borrower_name = trim($_POST['borrower_name'] ?? $_SESSION['name'] ?? '');
$borrow_date  = $_POST['borrow_date'] ?? date('Y-m-d');
$return_date  = $_POST['return_date'] ?? '';
$note         = trim($_POST['note'] ?? '');

// --- ฟังก์ชัน redirect กลับพร้อม error ---
function fail(string $msg, int $assetId): void
{
    $_SESSION['borrow_error'] = $msg;
    header("Location: borrow_request.php?id=" . $assetId);
    exit;
}

// --- Validation พื้นฐาน ---
if ($asset_id <= 0) { header("Location: list.php"); exit; }
if ($return_date === '' || $return_date <= $borrow_date) {
    fail("กำหนดคืนต้องเป็นวันหลังจากวันที่ยืม", $asset_id);
}
if ($borrower_name === '') {
    fail("กรุณาระบุชื่อผู้ยืม", $asset_id);
}

// --- ตรวจสอบว่า asset ยังพร้อมให้ยืมอยู่ ---
$stmtCheck = $conn->prepare("SELECT asset_id, status, borrowable_status FROM assets WHERE asset_id = ?");
$stmtCheck->execute([$asset_id]);
$asset = $stmtCheck->fetch(PDO::FETCH_ASSOC);

if (!$asset) { fail("ไม่พบข้อมูลครุภัณฑ์", $asset_id); }
if ($asset['borrowable_status'] !== 'สามารถยืมได้') {
    fail("ครุภัณฑ์ชิ้นนี้ถูกกำหนดว่าไม่สามารถยืมได้", $asset_id);
}
if ($asset['status'] !== 'ใช้งานปกติ') {
    fail("ครุภัณฑ์ไม่พร้อมให้ยืม (สถานะปัจจุบัน: " . $asset['status'] . ")", $asset_id);
}

// --- ตรวจสอบและย้ายไฟล์แนบ (ถ้ามี) ---
$attachment_filename = null;
$hasFile = isset($_FILES['attachment']) && $_FILES['attachment']['error'] !== UPLOAD_ERR_NO_FILE;

if ($hasFile) {
    if ($_FILES['attachment']['error'] !== UPLOAD_ERR_OK) {
        fail("เกิดข้อผิดพลาดในการอัปโหลดไฟล์ กรุณาลองอีกครั้ง", $asset_id);
    }

    $fileTmpPath = $_FILES['attachment']['tmp_name'];
    $fileName    = basename($_FILES['attachment']['name']);
    $fileExt     = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $fileMime    = mime_content_type($fileTmpPath);
    $fileSize    = $_FILES['attachment']['size'];

    $allowedExts  = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];
    $allowedMimes = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];
    $maxSize      = 5 * 1024 * 1024; // 5MB

    if (!in_array($fileExt, $allowedExts, true) || !in_array($fileMime, $allowedMimes, true)) {
        fail("ไฟล์ที่แนบต้องเป็น PDF หรือรูปภาพ (jpg, png, webp) เท่านั้น", $asset_id);
    }
    if ($fileSize > $maxSize) {
        fail("ไฟล์มีขนาดใหญ่เกินไป (สูงสุด 5MB)", $asset_id);
    }

    $docUploadDir = dirname(__DIR__) . '/uploads/borrow_docs/';
    if (!is_dir($docUploadDir)) {
        mkdir($docUploadDir, 0755, true);
    }

    $newFileName = 'borrow_' . $asset_id . '_' . $user_id . '_' . time() . '.' . $fileExt;
    if (!move_uploaded_file($fileTmpPath, $docUploadDir . $newFileName)) {
        fail("ไม่สามารถบันทึกไฟล์ได้ กรุณาลองอีกครั้ง", $asset_id);
    }
    $attachment_filename = $newFileName;
}

// --- บันทึกลงฐานข้อมูล (transaction) ---
try {
    $conn->beginTransaction();

    $stmt = $conn->prepare(
        "INSERT INTO borrow_history
            (asset_id, user_id, borrower_name, borrow_date, return_date, note, attachment_path, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, 'รออนุมัติ')"
    );
    $stmt->execute([$asset_id, $user_id, $borrower_name, $borrow_date, $return_date, $note, $attachment_filename]);

    // อัปเดตสถานะครุภัณฑ์เป็น 'รออนุมัติ'
    $conn->prepare("UPDATE assets SET status = 'รออนุมัติ' WHERE asset_id = ?")->execute([$asset_id]);

    $conn->commit();

    header("Location: my_borrow.php?success=1");
} catch (PDOException $e) {
    $conn->rollBack();
    // ถ้า DB ล้มเหลว ลบไฟล์ที่ย้ายไปแล้วออก เพื่อไม่ให้มีไฟล์กำพร้า
    if ($attachment_filename && file_exists($docUploadDir . $attachment_filename)) {
        unlink($docUploadDir . $attachment_filename);
    }
    fail("เกิดข้อผิดพลาดในการบันทึกข้อมูล กรุณาลองอีกครั้ง", $asset_id);
}
