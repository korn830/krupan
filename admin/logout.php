<?php
session_start();

// อ่านสิทธิ์ไว้ก่อนลบ session จะได้พากลับไปช่องเข้าสู่ระบบเดิมของเขา
require_once __DIR__ . '/../assets/auth.php';
$mode = kp_mode_for_role($_SESSION['role'] ?? null);

session_destroy();
header("Location: ../index.php?mode=" . urlencode($mode)); // ระบุ path แบบ absolute
exit;