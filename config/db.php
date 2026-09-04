<?php
// กำหนด timezone ให้ตรงกับผู้ใช้งานจริง มิฉะนั้น PHP จะใช้ UTC
// ทำให้ date('Y-m-d') ("วันนี้" ของเซิร์ฟเวอร์) ไม่ตรงกับวันที่บนเครื่องผู้ใช้
// และช่วงวันที่ min/max ของฟอร์มยืมจะเพี้ยนไป 1 วัน
date_default_timezone_set('Asia/Bangkok');

$host = 'localhost';
$db   = 'krupanlnwm_asset_system';
$user = 'krupanlnwm_asset_system'; // แก้ตาม MySQL user ของคุณ
$pass = 'C_L5c47kAGbReXV';     // แก้ตามรหัสผ่านของคุณ
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
];

try {
    $conn = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    echo "การเชื่อมต่อฐานข้อมูลล้มเหลว: " . $e->getMessage();
    exit;
}
?>