<?php
session_start();
// ตรวจสอบสิทธิ์
if (!isset($_SESSION["user_id"]) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit;
}

require '../config/db.php';
// เรียกใช้งาน Composer Autoload
require '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

try {
    // 1. ดึงข้อมูลจากฐานข้อมูล
    $sql = "SELECT a.asset_code, a.name, c.name AS category_name, 
                   d.name AS department_name, l.name AS location_name, a.status 
            FROM assets a
            LEFT JOIN categories c ON a.category_id = c.category_id
            LEFT JOIN departments d ON a.department_id = d.department_id
            LEFT JOIN locations l ON a.location_id = l.location_id
            ORDER BY a.asset_id DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. สร้างไฟล์ Excel ใหม่
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('ข้อมูลครุภัณฑ์');

    // 3. ตั้งค่าหัวตาราง
    $headers = ['รหัสครุภัณฑ์', 'ชื่อครุภัณฑ์', 'หมวดหมู่', 'แผนก', 'สถานที่', 'สถานะ'];
    $columnLetter = 'A';
    foreach ($headers as $header) {
        $sheet->setCellValue($columnLetter . '1', $header);
        $columnLetter++;
    }

    // ตกแต่งสีหัวตาราง (สีฟ้า-ม่วง ตามธีมระบบ)
    $headerStyle = [
        'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF667EEA']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
    ];
    $sheet->getStyle('A1:F1')->applyFromArray($headerStyle);

    // 4. นำข้อมูลใส่ลงตาราง
    $rowNumber = 2;
    foreach ($results as $row) {
        $sheet->setCellValue('A' . $rowNumber, $row['asset_code'] ?? '-');
        $sheet->setCellValue('B' . $rowNumber, $row['name'] ?? '-');
        $sheet->setCellValue('C' . $rowNumber, $row['category_name'] ?? '-');
        $sheet->setCellValue('D' . $rowNumber, $row['department_name'] ?? '-');
        $sheet->setCellValue('E' . $rowNumber, $row['location_name'] ?? '-');
        $sheet->setCellValue('F' . $rowNumber, $row['status'] ?? '-');
        $rowNumber++;
    }

    // ปรับความกว้างคอลัมน์อัตโนมัติ
    foreach (range('A', 'F') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    // 5. ส่งไฟล์ให้ดาวน์โหลด (เป็น .xlsx แท้ๆ)
    $filename = "Asset_Report_" . date('Ymd_His') . ".xlsx";
    
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;

} catch (Exception $e) {
    die("เกิดข้อผิดพลาด: " . $e->getMessage());
}
?>