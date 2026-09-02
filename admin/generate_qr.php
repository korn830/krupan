<?php
// เริ่มหน่วงการส่งออกข้อมูล
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0); 

require '../config/db.php';
require '../vendor/autoload.php'; 

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use chillerlan\QRCode\Output\QROutputInterface;

if (isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    
    // ดึงข้อมูลรหัสครุภัณฑ์จากฐานข้อมูล
    $stmt = $conn->prepare("SELECT asset_code FROM assets WHERE asset_id = ?");
    $stmt->execute([$id]);
    $asset = $stmt->fetch();

    if ($asset) {
        $assetCode = $asset['asset_code'];
        $qrData = $assetCode;

        try {
            // ตั้งค่าการสร้าง QR Code เป็น SVG (ไม่ต้องพึ่งพา PHP GD Extension)
            $options = new QROptions([
                'version'      => 5,
                'outputType'   => QROutputInterface::MARKUP_SVG,
                'eccLevel'     => QRCode::ECC_L,
                'moduleSize'   => 5,
                'addQuietzone' => true,
            ]);

            $qrcode = new QRCode($options);
            $image = $qrcode->render($qrData);
            
            // ล้างข้อมูลขยะทั้งหมดก่อนส่งรูป
            ob_clean();
            
            // ส่งภาพแบบ SVG
            header('Content-type: image/svg+xml');
            header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
            header("Cache-Control: post-check=0, pre-check=0", false);
            header("Pragma: no-cache");
            
            echo $image;
            exit;

        } catch (\Exception $e) {
            // ถ้ามี Error จะวาดรูปภาพ SVG เป็นข้อความสีแดงแจ้งเตือนให้เห็นชัดๆ
            ob_clean();
            header('Content-type: image/svg+xml');
            $errorMsg = htmlspecialchars($e->getMessage());
            echo '<svg width="400" height="100" xmlns="http://www.w3.org/2000/svg"><rect width="100%" height="100%" fill="#ffe6e6"/><text x="10" y="40" fill="red" font-family="sans-serif" font-size="14">Error: ' . $errorMsg . '</text></svg>';
            exit;
        }
    }
}

// ถ้าไม่มีรหัส หรือไม่พบข้อมูล
ob_clean();
header('Content-type: image/svg+xml');
echo '<svg width="300" height="100" xmlns="http://www.w3.org/2000/svg"><rect width="100%" height="100%" fill="#ffe6e6"/><text x="10" y="40" fill="red" font-family="sans-serif" font-size="14">Not Found / ไม่พบข้อมูล</text></svg>';
exit;
?>