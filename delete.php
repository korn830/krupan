<?php
session_start();
// ตรวจสอบว่าผู้ใช้ล็อกอินอยู่และมีสิทธิ์เป็น admin หรือไม่
if (!isset($_SESSION["user_id"]) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

require __DIR__ . '/config/db.php';

if (isset($_GET['id']) && $_SERVER["REQUEST_METHOD"] === "GET") {
    $asset_id = $_GET['id'];

    try {
        // ดึงข้อมูลครุภัณฑ์ที่จะลบ เพื่อนำไปใช้บันทึกใน Log และลบไฟล์รูปภาพ
        $stmt = $conn->prepare("SELECT asset_code, name, image_url FROM assets WHERE asset_id = ?");
        $stmt->execute([$asset_id]);
        $asset_to_delete = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($asset_to_delete) {
            // เริ่มต้น Transaction เพื่อให้แน่ใจว่าข้อมูลถูกลบทั้งหมดหรือไม่มีการลบเลย (ป้องกันข้อมูลเสียหาย)
            $conn->beginTransaction();

            // ลบรายการในตาราง asset_action_log ที่เกี่ยวข้องกับ asset_id นี้ก่อน
            $stmt = $conn->prepare("DELETE FROM asset_action_log WHERE asset_id = ?");
            $stmt->execute([$asset_id]);

            // ลบข้อมูลครุภัณฑ์ออกจากฐานข้อมูล
            $stmt = $conn->prepare("DELETE FROM assets WHERE asset_id = ?");
            $stmt->execute([$asset_id]);

            // ตรวจสอบว่าลบสำเร็จหรือไม่
            if ($stmt->rowCount() > 0) {
                // บันทึก Log การลบ
                $logDetails = json_encode([
                    'action' => 'delete',
                    'asset_id' => $asset_id,
                    'asset_code' => $asset_to_delete['asset_code'],
                    'name' => $asset_to_delete['name']
                ], JSON_UNESCAPED_UNICODE);

                $logStmt = $conn->prepare("INSERT INTO asset_action_log (asset_id, action_type, user_id, details) VALUES (?, 'delete', ?, ?)");
                // ในกรณีที่ลบข้อมูล asset ออกไปแล้ว เราอาจจะใช้ asset_id เดิม หรือกำหนดเป็น NULL/0 ถ้าไม่ต้องการผูกกับ ID ที่ถูกลบไปแล้ว
                // แต่เพื่อวัตถุประสงค์ในการเก็บ log ว่า asset_id นี้ถูกลบไปแล้ว เราจะยังคงใช้ asset_id เดิม
                $logStmt->execute([$asset_id, $_SESSION['user_id'], $logDetails]);

                // ลบไฟล์รูปภาพที่เกี่ยวข้อง หากมี (ใช้ path แบบ absolute)
                $uploadDir = __DIR__ . '/uploads/';
                if ($asset_to_delete['image_url'] && file_exists($uploadDir . $asset_to_delete['image_url'])) {
                    unlink($uploadDir . $asset_to_delete['image_url']);
                }
                // Commit transaction
                $conn->commit();

                $_SESSION['success_message'] = "ลบครุภัณฑ์ '{$asset_to_delete['name']}' (เลขครุภัณฑ์: {$asset_to_delete['asset_code']}) สำเร็จแล้ว!";
                header("Location: admin/list.php");
                exit;
            } else {
                // Rollback transaction หากไม่มีการลบ
                $conn->rollBack();
                $_SESSION['error_message'] = "ไม่พบครุภัณฑ์ที่ต้องการลบ หรือเกิดข้อผิดพลาดในการลบ.";
                header("Location: admin/list.php");
                exit;
            }
        } else {
            $_SESSION['error_message'] = "ไม่พบครุภัณฑ์ที่ระบุ.";
            header("Location: admin/list.php");
            exit;
        }
    } catch (PDOException $e) {
        // Rollback transaction หากเกิดข้อผิดพลาด
        $conn->rollBack();
        error_log("Error deleting asset: " . $e->getMessage()); // บันทึกข้อผิดพลาดใน error log
        $_SESSION['error_message'] = "เกิดข้อผิดพลาดในการลบครุภัณฑ์: " . $e->getMessage();
        header("Location: admin/list.php");
        exit;
    }
} else {
    // หากไม่มีการส่งค่า id มา หรือไม่ใช่ method GET
    $_SESSION['error_message'] = "คำขอไม่ถูกต้อง.";
    header("Location: list.php");
    exit;
}
?>