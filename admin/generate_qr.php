<?php

ob_start();

error_reporting(E_ALL);
ini_set('display_errors', '0');

require __DIR__ . '/../config/db.php';
require __DIR__ . '/../vendor/autoload.php';

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use chillerlan\QRCode\Output\QROutputInterface;

function outputSvg(string $svg, int $status = 200): never
{
    if (ob_get_level() > 0) {
        ob_clean();
    }

    http_response_code($status);

    header('Content-Type: image/svg+xml; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    echo $svg;
    exit;
}

function errorSvg(string $message): string
{
    $message = htmlspecialchars(
        $message,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );

    return '
    <svg xmlns="http://www.w3.org/2000/svg"
         width="600"
         height="120"
         viewBox="0 0 600 120">

        <rect width="100%" height="100%" fill="#fff5f5"/>

        <text x="20"
              y="50"
              fill="#c53030"
              font-family="Arial,sans-serif"
              font-size="18">
            ' . $message . '
        </text>

    </svg>';
}

try {

    // ตรวจสอบ asset ID
    if (!isset($_GET['id']) || !ctype_digit((string)$_GET['id'])) {
        outputSvg(errorSvg('Invalid asset ID'), 400);
    }

    $id = (int)$_GET['id'];

    // ดึงข้อมูลครุภัณฑ์
    $stmt = $conn->prepare(
        'SELECT asset_id, asset_code, name
         FROM assets
         WHERE asset_id = ?
         LIMIT 1'
    );

    $stmt->execute([$id]);

    $asset = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$asset) {
        outputSvg(errorSvg('Asset not found'), 404);
    }

    /*
     * =====================================================
     * สร้าง URL สำหรับ QR
     * =====================================================
     *
     * ตัวอย่าง:
     * https://example.com/user/borrow_request.php?id=57
     */

    $https = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)
    );

    $scheme = $https ? 'https' : 'http';

    $host = $_SERVER['HTTP_HOST'] ?? '';

    // หาโฟลเดอร์ root ของระบบ
    // จาก /krupan/admin/generate_qr.php
    // จะได้ /krupan
    $projectRoot = dirname(dirname($_SERVER['SCRIPT_NAME']));

    $borrowUrl =
        $scheme . '://' .
        $host .
        $projectRoot .
        '/user/borrow_request.php?id=' .
        urlencode((string)$id);

    /*
     * =====================================================
     * สร้าง QR Code
     * =====================================================
     */

    $options = new QROptions([
        'outputType'    => QROutputInterface::MARKUP_SVG,

        // สำคัญมาก ต้องเป็น false
        'outputBase64'  => false,

        'eccLevel'      => QRCode::ECC_M,

        'scale'         => 5,

        'addQuietzone'  => true,

        'quietzoneSize' => 4,
    ]);

    $qrcode = new QRCode($options);

    // QR จะเก็บ URL ไม่ใช่ asset_code
    $svg = $qrcode->render($borrowUrl);

    outputSvg($svg);

} catch (\Throwable $e) {

    outputSvg(
        errorSvg(
            'QR generation error: ' . $e->getMessage()
        ),
        500
    );
}