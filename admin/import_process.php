<?php
session_start();
// ตรวจสอบสิทธิ์
if (!isset($_SESSION["user_id"]) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit;
}

require '../config/db.php';
require '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_FILES['excel_file'])) {
    $fileTmpPath = $_FILES['excel_file']['tmp_name'];
    $fileName = $_FILES['excel_file']['name'];
    $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

    // เช็คว่าใช่ไฟล์ Excel หรือ CSV ไหม
    if (!in_array($extension, ['xls', 'xlsx', 'csv'])) {
        $_SESSION['error_message'] = "กรุณาอัปโหลดไฟล์นามสกุล .xls, .xlsx หรือ .csv เท่านั้น";
        header("Location: list.php");
        exit;
    }

    try {
        // อ่านไฟล์ Excel
        $spreadsheet = IOFactory::load($fileTmpPath);
        // แปลง Sheet แรกเป็น Array
        $sheetData = $spreadsheet->getSheet(0)->toArray(null, true, true, true);

        $successCount = 0;
        $duplicateCount = 0;
        $emptyRowSkipped = 0;

        $conn->beginTransaction();

        $checkStmt = $conn->prepare("SELECT COUNT(*) FROM assets WHERE asset_code = ?");
        $insertStmt = $conn->prepare("INSERT INTO assets (asset_code, name, description, category_id, location_id, department_id, status, purchase_date, price, image_url) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $logStmt = $conn->prepare("INSERT INTO asset_action_log (asset_id, action_type, user_id, details) VALUES (?, 'add', ?, ?)");

        // ฟังก์ชันช่วยดึงเฉพาะตัวเลข ID จากข้อความรูปแบบ "ID - Name"
        $extractId = function($val) {
            if (empty($val)) return null;
            $parts = explode(' - ', $val);
            return isset($parts[0]) && is_numeric(trim($parts[0])) ? (int)trim($parts[0]) : null;
        };

        // ฟังก์ชันดาวน์โหลดและบันทึกรูปภาพจาก URL
        $downloadImage = function($url) {
            if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) return null;

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_USERAGENT => 'Mozilla/5.0',
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $imageData = curl_exec($ch);
            $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($imageData === false || $httpCode !== 200) return null;

            // ตรวจสอบว่าเป็นรูปภาพจริง
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime  = $finfo->buffer($imageData);
            $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
            $extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];

            if (!in_array($mime, $allowedMimes, true)) return null;

            $ext      = $extMap[$mime];
            $filename = 'import_' . uniqid() . '.' . $ext;
            $savePath = dirname(__DIR__) . '/uploads/' . $filename;

            if (file_put_contents($savePath, $imageData) === false) return null;

            return $filename;
        };

        // ใช้ foreach ป้องกันปัญหาแถวใน Excel ถูกข้ามหรือนับจำนวนผิด
        foreach ($sheetData as $rowIndex => $row) {
            // ข้ามแถวที่ 1 และ 2 (คำแนะนำ และ หัวตารางของ Template)
            if ($rowIndex < 3) {
                continue;
            }

            $asset_code = trim($row['A'] ?? '');
            $name = trim($row['B'] ?? '');
            
            // ข้ามแถวที่ไม่มีรหัสหรือชื่อ (คอลัมน์บังคับ)
            if (empty($asset_code) || empty($name)) {
                // ถ้ารหัสหรือชื่อว่าง แต่ดันมีข้อมูลถูกกรอกในช่องอื่น ถือว่ากรอกไม่ครบ
                if (!empty(trim(implode('', $row)))) {
                     $emptyRowSkipped++;
                }
                continue;
            }

            $description = trim($row['C'] ?? '');
            
            // ดึง ID จาก Dropdown
            $category_id = $extractId($row['D'] ?? '');
            $location_id = $extractId($row['E'] ?? '');
            $department_id = $extractId($row['F'] ?? '');
            
            // ตรวจสอบสถานะ
            $status = trim($row['G'] ?? '');
            $valid_statuses = ['ใช้งานปกติ', 'ถูกยืม', 'ชำรุด', 'ส่งซ่อม', 'จำหน่าย'];
            if (!in_array($status, $valid_statuses)) {
                $status = 'ใช้งานปกติ'; 
            }

            $purchase_date = !empty($row['H']) ? date('Y-m-d', strtotime(str_replace('/', '-', $row['H']))) : null;
            $price = !empty($row['I']) ? (float)$row['I'] : null;

            // ดาวน์โหลดรูปภาพจาก URL ในคอลัมน์ J (ถ้ามี)
            $image_url = null;
            if (!empty(trim($row['J'] ?? ''))) {
                $image_url = $downloadImage(trim($row['J']));
            }

            // เช็คข้อมูลซ้ำ
            $checkStmt->execute([$asset_code]);
            if ($checkStmt->fetchColumn() > 0) {
                $duplicateCount++;
                continue;
            }

            // บันทึกข้อมูล
            $insertStmt->execute([$asset_code, $name, $description, $category_id, $location_id, $department_id, $status, $purchase_date, $price, $image_url]);
            $newAssetId = $conn->lastInsertId();

            // บันทึก Log แบบละเอียด
            $logDetails = json_encode([
                'note' => 'นำเข้าข้อมูลผ่านไฟล์ Excel', 
                'asset_code' => $asset_code, 
                'name' => $name,
                'category_id' => $category_id,
                'location_id' => $location_id,
                'department_id' => $department_id,
                'status' => $status,
                'price' => $price
            ], JSON_UNESCAPED_UNICODE);
            
            $logStmt->execute([$newAssetId, $_SESSION['user_id'], $logDetails]);

            $successCount++;
        }

        $conn->commit();

        // สรุปผลการทำงานและสร้างข้อความแจ้งเตือนที่ชัดเจนขึ้น
        if ($successCount > 0) {
            $msg = "นำเข้าข้อมูลสำเร็จ $successCount รายการ!";
            if ($duplicateCount > 0) {
                $msg .= " (และข้ามข้อมูลรหัสซ้ำ $duplicateCount รายการ)";
            }
            $_SESSION['success_message'] = $msg;
        } else if ($duplicateCount > 0) {
            $_SESSION['error_message'] = "ไม่มีการนำเข้าข้อมูลใหม่ (พบข้อมูลรหัสซ้ำ $duplicateCount รายการ)";
        } else if ($emptyRowSkipped > 0) {
            $_SESSION['error_message'] = "พบข้อมูลแต่ไม่ได้ระบุ 'รหัสครุภัณฑ์' หรือ 'ชื่อรายการ' (กรุณาตรวจสอบคอลัมน์ A และ B)";
        } else {
            $_SESSION['error_message'] = "ไม่พบข้อมูลครุภัณฑ์ใหม่ในไฟล์! (กรุณากรอกข้อมูลต่อจากแถวที่ 2 ใน Template)";
        }

    } catch (Exception $e) {
        $conn->rollBack();
        $_SESSION['error_message'] = "เกิดข้อผิดพลาดขณะประมวลผล: " . $e->getMessage();
    }

    header("Location: list.php");
    exit;
} else {
    header("Location: list.php");
    exit;
}
?>