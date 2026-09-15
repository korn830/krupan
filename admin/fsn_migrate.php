<?php
/**
 * fsn_migrate.php — แปลงเลขครุภัณฑ์เดิมให้เป็นระบบ FSN
 *
 * อ้างอิง: คู่มือการกำหนดหมายเลขพัสดุ สำนักงบประมาณ (มกราคม 2543)
 *
 * วิธีทำงาน
 *   1. ครุภัณฑ์ที่เลขถูกรูปแบบ FSN อยู่แล้ว จะถูกข้าม
 *   2. ที่เหลือจะดู fsn_class ของหมวดหมู่ที่ผูกไว้ ถ้ายังไม่ได้ผูกจะข้ามและรายงาน
 *   3. ชนิด (3 หลัก) ใช้ค่าเริ่มต้น 001 เพราะคู่มือให้หน่วยงานกำหนดเอง
 *      สำหรับรายการที่ไม่มีตัวอย่างในคู่มือ
 *   4. เลขลำดับ 4 หลักท้าย ไล่ต่อจากเลขที่มีอยู่แล้วใน prefix นั้น
 *   5. เลขเดิมถูกเก็บไว้ในคอลัมน์ old_code เสมอ
 *
 * ต้องรัน sql/migrations/001_fsn_numbering.sql ก่อน
 *
 * ใช้งาน (จากบรรทัดคำสั่ง):
 *   php admin/fsn_migrate.php            ดูผลก่อน ไม่แก้ข้อมูล (dry run)
 *   php admin/fsn_migrate.php --apply    แก้ข้อมูลจริง
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("สคริปต์นี้ให้รันจากบรรทัดคำสั่งเท่านั้น\n");
}

require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/assets/fsn.php';

$apply = in_array('--apply', $argv, true);
$defaultType = '001';

echo $apply
    ? "== โหมดแก้ไขจริง ==\n\n"
    : "== โหมดทดลอง (ไม่แก้ข้อมูล) — ใส่ --apply เพื่อแก้จริง ==\n\n";

// ตรวจว่ารันไฟล์ migration แล้วหรือยัง
try {
    $conn->query("SELECT old_code FROM assets LIMIT 1");
    $conn->query("SELECT fsn_class FROM categories LIMIT 1");
} catch (PDOException $e) {
    exit("ยังไม่ได้รัน sql/migrations/001_fsn_numbering.sql — กรุณารันก่อน\n");
}

$rows = $conn->query(
    "SELECT a.asset_id, a.asset_code, a.name, c.name AS category_name, c.fsn_class
     FROM assets a
     LEFT JOIN categories c ON c.category_id = a.category_id
     ORDER BY a.asset_id"
)->fetchAll(PDO::FETCH_ASSOC);

$skipped = $planned = $noClass = 0;
$taken   = [];   // prefix => เลขลำดับสูงสุดที่จองไว้แล้วในรอบนี้

// เริ่มจากเลขที่มีอยู่จริงในฐานข้อมูล เพื่อไม่ให้ชนกัน
foreach ($rows as $r) {
    $p = fsn_parse((string)$r['asset_code']);
    if ($p) {
        $taken[$p['prefix']] = max($taken[$p['prefix']] ?? 0, (int)$p['serial']);
    }
}

$updates = [];
foreach ($rows as $r) {
    $code = (string)$r['asset_code'];

    if (fsn_is_valid($code)) {
        $skipped++;
        continue;
    }

    $class = (string)($r['fsn_class'] ?? '');
    if (!preg_match('/^\d{4}$/', $class)) {
        $noClass++;
        printf("  ข้าม  %-16s %-34s (หมวดหมู่ \"%s\" ยังไม่ได้ผูกประเภท)\n",
            $code, mb_strimwidth($r['name'], 0, 34, '…'), $r['category_name'] ?? '-');
        continue;
    }

    $prefix = $class . '-' . $defaultType;
    $next   = ($taken[$prefix] ?? 0) + 1;
    if ($next > 9999) {
        printf("  ข้าม  %-16s เลขลำดับของ %s เต็มแล้ว\n", $code, $prefix);
        continue;
    }
    $taken[$prefix] = $next;

    $newCode = $prefix . '-' . sprintf('%04d', $next);
    $updates[] = [$r['asset_id'], $code, $newCode];
    $planned++;
    printf("  %-5s %-16s -> %-16s %s\n",
        $apply ? 'แก้' : 'จะแก้', $code, $newCode, mb_strimwidth($r['name'], 0, 34, '…'));
}

if ($apply && $updates) {
    try {
        $conn->beginTransaction();
        // เก็บเลขเดิมไว้เฉพาะครั้งแรกที่แปลง จะได้ไม่ทับของเดิมถ้ารันซ้ำ
        $stmt = $conn->prepare(
            "UPDATE assets
             SET old_code = COALESCE(old_code, ?), asset_code = ?
             WHERE asset_id = ?"
        );
        foreach ($updates as [$id, $oldCode, $newCode]) {
            $stmt->execute([$oldCode, $newCode, $id]);
        }
        $conn->commit();
        echo "\nบันทึกแล้ว\n";
    } catch (PDOException $e) {
        $conn->rollBack();
        exit("\nผิดพลาด ยกเลิกทั้งหมด: " . $e->getMessage() . "\n");
    }
}

printf("\nสรุป: ถูกต้องอยู่แล้ว %d | %s %d | ข้ามเพราะยังไม่ผูกประเภท %d\n",
    $skipped, $apply ? 'แก้แล้ว' : 'รอแก้', $planned, $noClass);

if (!$apply && $planned) {
    echo "ยังไม่ได้แก้ข้อมูล — รันซ้ำด้วย --apply เพื่อบันทึก\n";
}
