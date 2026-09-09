<?php
/**
 * เติมหมายเลขเวอร์ชันท้าย URL ของไฟล์ CSS จากเวลาที่ไฟล์ถูกแก้ล่าสุด
 * เพื่อให้เบราว์เซอร์โหลดไฟล์ใหม่ทันทีเมื่อมีการแก้ ไม่ค้างอยู่กับแคชเก่า
 *
 * $rel    = เส้นทางไฟล์นับจากรากโปรเจกต์ เช่น 'assets/css/tokens.css'
 * $prefix = เส้นทางเว็บที่ใช้นำหน้า เช่น '../' เมื่อเรียกจากโฟลเดอร์ user/ หรือ admin/
 */
if (!function_exists('kp_asset')) {
    function kp_asset(string $rel, string $prefix = ''): string {
        $t = @filemtime(dirname(__DIR__) . '/' . ltrim($rel, '/'));
        return $prefix . $rel . ($t ? '?v=' . $t : '');
    }
}
