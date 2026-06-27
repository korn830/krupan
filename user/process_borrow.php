<?php
session_start();
if (!isset($_SESSION["user_id"])) { header("Location: ../index.php"); exit; }
require_once dirname(__DIR__) . '/config/db.php';

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $asset_id = (int)$_POST['asset_id'];
    $user_id = $_SESSION['user_id'];
    $return_date = $_POST['return_date'];
    $note = $_POST['note'];
    $borrow_date = date('Y-m-d');

    try {

        // บันทึกลงตาราง borrow_history
        $sql = "INSERT INTO borrow_history (asset_id, user_id, borrow_date, return_date, note, status, user_id) 
                VALUES (?, ?, ?, ?, ?, 'รออนุมัติ', ?)";
        $conn->prepare($sql)->execute([$asset_id, $user_id, $borrow_date, $return_date, $note, $user_id]);

        // อัปเดตสถานะครุภัณฑ์เป็น 'รออนุมัติ'
        $conn->prepare("UPDATE assets SET status = 'รออนุมัติ' WHERE asset_id = ?")->execute([$asset_id]);

        header("Location: my_borrow.php?success=1");
    } catch (PDOException $e) {
        die("เกิดข้อผิดพลาดในการบันทึกข้อมูล: " . $e->getMessage());
    }
}