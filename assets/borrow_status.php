<?php
/**
 * borrow_status.php — ปรับสถานะการยืมที่เลยกำหนดคืนให้เป็น 'เกินวันที่กำหนด'
 *
 * ทำไมต้องมีไฟล์นี้:
 * สถานะ 'เกินวันที่กำหนด' มีอยู่ใน ENUM ของตาราง borrow_history มาตั้งแต่ต้น
 * และหน้าจอต่าง ๆ ก็แสดงผลสถานะนี้ได้ (my_borrow.php, confirm_borrow.php
 * มี case รองรับและมีสีแดงให้แล้ว) แต่ไม่มีโค้ดตรงไหนเลยที่ "ตั้ง" สถานะนี้
 * รายการที่เลยกำหนดคืนมาแล้วสามสัปดาห์จึงยังแสดงเป็น 'อนุมัติ' อยู่
 * และตัวนับรายการเกินกำหนดก็เป็นศูนย์ตลอดไป
 *
 * วิธีแก้: เรียก kp_mark_overdue_borrows() ก่อนอ่านข้อมูลการยืมในหน้าที่แสดงผล
 */

if (!function_exists('kp_mark_overdue_borrows')) {

    /**
     * เปลี่ยนสถานะรายการที่ยังไม่คืนและเลยกำหนดแล้ว ให้เป็น 'เกินวันที่กำหนด'
     *
     * แตะเฉพาะ borrow_history เท่านั้น ไม่แตะสถานะของครุภัณฑ์
     * เพราะของยังอยู่กับผู้ยืม สถานะ 'ถูกยืม' ของครุภัณฑ์จึงยังถูกต้องอยู่
     *
     * @return int จำนวนรายการที่เพิ่งถูกเปลี่ยนสถานะ
     */
    function kp_mark_overdue_borrows(PDO $conn): int {
        try {
            $stmt = $conn->prepare(
                "UPDATE borrow_history
                 SET status = 'เกินวันที่กำหนด'
                 WHERE status IN ('อนุมัติ', 'ยืมอยู่')
                   AND return_date IS NOT NULL
                   AND return_date < CURDATE()"
            );
            $stmt->execute();
            return $stmt->rowCount();
        } catch (PDOException $e) {
            // ไม่ให้หน้าเว็บพังเพราะการปรับสถานะอัตโนมัติ
            error_log('kp_mark_overdue_borrows: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * สรุปจำนวนรายการยืมของผู้ใช้คนหนึ่ง แยกตามสถานะที่ผู้ใช้สนใจ
     *
     * @return array{pending:int,approved:int,overdue:int,returned:int}
     */
    function kp_my_borrow_summary(PDO $conn, int $userId): array {
        $out = ['pending' => 0, 'approved' => 0, 'overdue' => 0, 'returned' => 0];
        $map = [
            'รออนุมัติ'        => 'pending',
            'อนุมัติ'          => 'approved',
            'ยืมอยู่'          => 'approved',   // สองสถานะนี้คือ "กำลังยืมอยู่" เหมือนกัน
            'เกินวันที่กำหนด'  => 'overdue',
            'คืนแล้ว'          => 'returned',
        ];
        try {
            $stmt = $conn->prepare(
                "SELECT status, COUNT(*) AS total
                 FROM borrow_history
                 WHERE user_id = ?
                 GROUP BY status"
            );
            $stmt->execute([$userId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $key = $map[$row['status']] ?? null;
                if ($key !== null) {
                    $out[$key] += (int)$row['total'];
                }
            }
        } catch (PDOException $e) {
            error_log('kp_my_borrow_summary: ' . $e->getMessage());
        }
        return $out;
    }

    /**
     * รายการยืมของผู้ใช้ที่ยังไม่จบ (รออนุมัติ / กำลังยืม / เกินกำหนด)
     * เรียงให้รายการที่เกินกำหนดขึ้นก่อน แล้วตามด้วยรายการที่ใกล้ครบกำหนด
     */
    function kp_my_open_borrows(PDO $conn, int $userId): array {
        try {
            $stmt = $conn->prepare(
                "SELECT bh.borrow_id, bh.borrow_date, bh.return_date, bh.status,
                        a.asset_code, a.name AS asset_name,
                        DATEDIFF(bh.return_date, CURDATE()) AS days_left
                 FROM borrow_history bh
                 JOIN assets a ON a.asset_id = bh.asset_id
                 WHERE bh.user_id = ?
                   AND bh.status IN ('รออนุมัติ', 'อนุมัติ', 'ยืมอยู่', 'เกินวันที่กำหนด')
                 ORDER BY
                    CASE bh.status
                        WHEN 'เกินวันที่กำหนด' THEN 0
                        WHEN 'อนุมัติ'         THEN 1
                        WHEN 'ยืมอยู่'         THEN 1
                        ELSE 2
                    END,
                    bh.return_date ASC"
            );
            $stmt->execute([$userId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('kp_my_open_borrows: ' . $e->getMessage());
            return [];
        }
    }
}
