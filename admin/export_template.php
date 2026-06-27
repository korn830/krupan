<?php
session_start();
// ตรวจสอบสิทธิ์ Admin (ถ้าไม่ใช่ แตะไฟล์นี้ไม่ได้)
if (!isset($_SESSION["user_id"]) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit;
}

require '../config/db.php';
require '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

$spreadsheet = new Spreadsheet();

// ---------------------------------------------------------
// 1. สร้าง Sheet สำหรับเก็บข้อมูล Dropdown (แล้วซ่อนไว้)
// ---------------------------------------------------------
$dropdownSheet = $spreadsheet->createSheet();
$dropdownSheet->setTitle('_Dropdowns_');
$dropdownSheet->setSheetState(Worksheet::SHEETSTATE_HIDDEN); // ซ่อนไม่ให้ user เห็น

// ดึงข้อมูลจากฐานข้อมูลมาทำตัวเลือก
$categories = $conn->query("SELECT category_id, name FROM categories")->fetchAll();
$locations = $conn->query("SELECT location_id, name FROM locations")->fetchAll();
$departments = $conn->query("SELECT department_id, name FROM departments")->fetchAll();

// นำข้อมูลไปใส่ใน Sheet _Dropdowns_ ในรูปแบบ "1 - ชื่อหมวดหมู่" (PHP จะดึงแค่เลข 1 ไปใช้ตอน Import)
$rCat = 1; foreach($categories as $c) { $dropdownSheet->setCellValue('A'.$rCat, $c['category_id'].' - '.$c['name']); $rCat++; }
$rLoc = 1; foreach($locations as $l) { $dropdownSheet->setCellValue('B'.$rLoc, $l['location_id'].' - '.$l['name']); $rLoc++; }
$rDep = 1; foreach($departments as $d) { $dropdownSheet->setCellValue('C'.$rDep, $d['department_id'].' - '.$d['name']); $rDep++; }

// ---------------------------------------------------------
// 2. จัดการ Sheet หลักสำหรับกรอกข้อมูล
// ---------------------------------------------------------
$spreadsheet->setActiveSheetIndex(0);
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Template_นำเข้าครุภัณฑ์');

// --- สร้างแถวคำแนะนำ (แถวที่ 1) ---
$sheet->mergeCells('A1:I1');
$sheet->setCellValue('A1', 'คำแนะนำ: ห้ามแก้ไขหัวตารางในแถวที่ 2. สำหรับคอลัมน์ หมวดหมู่, สถานที่, แผนก และ สถานะ สามารถคลิกที่ช่องแล้ว "เลือกจาก Dropdown" ได้เลย. (วันที่ใช้รูปแบบ YYYY-MM-DD)');

// ตกแต่งแถวคำแนะนำ
$sheet->getStyle('A1')->applyFromArray([
    'font' => ['color' => ['argb' => 'FFFF0000']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFFFACD']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
]);
$sheet->getRowDimension(1)->setRowHeight(30);

// --- กำหนดหัวตาราง (แถวที่ 2) ---
$headers = [
    'A' => 'asset_code (รหัสครุภัณฑ์) *',
    'B' => 'name (ชื่อรายการ) *',
    'C' => 'description (รายละเอียด)',
    'D' => 'category_id (หมวดหมู่)',
    'E' => 'location_id (สถานที่)',
    'F' => 'department_id (แผนก)',
    'G' => 'status (สถานะ)',
    'H' => 'purchase_date (วันที่ซื้อ)',
    'I' => 'price (ราคา)'
];

foreach ($headers as $col => $headerName) {
    $sheet->setCellValue($col . '2', $headerName);
}

// ตกแต่งหัวตาราง
$headerStyle = [
    'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF4F81BD']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
];
$sheet->getStyle('A2:I2')->applyFromArray($headerStyle);

// ปรับความกว้างคอลัมน์อัตโนมัติ
foreach (range('A', 'I') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}
$sheet->freezePane('A3');

// ---------------------------------------------------------
// 3. กำหนด Data Validation (Dropdown) ให้แถวที่ 3 ถึง 500
// ---------------------------------------------------------
for ($i = 3; $i <= 500; $i++) {
    // Dropdown หมวดหมู่ (Column D)
    if ($rCat > 1) {
        $valD = $sheet->getCell("D{$i}")->getDataValidation();
        $valD->setType(DataValidation::TYPE_LIST);
        $valD->setErrorStyle(DataValidation::STYLE_STOP);
        $valD->setAllowBlank(true);
        $valD->setShowDropDown(true);
        $valD->setFormula1('_Dropdowns_!$A$1:$A$' . ($rCat - 1));
    }

    // Dropdown สถานที่ (Column E)
    if ($rLoc > 1) {
        $valE = $sheet->getCell("E{$i}")->getDataValidation();
        $valE->setType(DataValidation::TYPE_LIST);
        $valE->setErrorStyle(DataValidation::STYLE_STOP);
        $valE->setAllowBlank(true);
        $valE->setShowDropDown(true);
        $valE->setFormula1('_Dropdowns_!$B$1:$B$' . ($rLoc - 1));
    }

    // Dropdown แผนก (Column F)
    if ($rDep > 1) {
        $valF = $sheet->getCell("F{$i}")->getDataValidation();
        $valF->setType(DataValidation::TYPE_LIST);
        $valF->setErrorStyle(DataValidation::STYLE_STOP);
        $valF->setAllowBlank(true);
        $valF->setShowDropDown(true);
        $valF->setFormula1('_Dropdowns_!$C$1:$C$' . ($rDep - 1));
    }

    // Dropdown สถานะ (Column G)
    $valG = $sheet->getCell("G{$i}")->getDataValidation();
    $valG->setType(DataValidation::TYPE_LIST);
    $valG->setErrorStyle(DataValidation::STYLE_STOP);
    $valG->setAllowBlank(true);
    $valG->setShowDropDown(true);
    $valG->setFormula1('"ใช้งานปกติ,ถูกยืม,ชำรุด,ส่งซ่อม,จำหน่าย"');
}

// --- ตั้งค่า Header ของ HTTP เพื่อให้เบราว์เซอร์ดาวน์โหลดไฟล์ ---
$filename = "Template_Import_Assets.xlsx";

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
?>