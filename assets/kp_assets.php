<?php
/**
 * เติมหมายเลขเวอร์ชันท้าย URL ของไฟล์ CSS/JS จากเวลาที่ไฟล์ถูกแก้ล่าสุด
 * เพื่อให้เบราว์เซอร์โหลดไฟล์ใหม่ทันทีเมื่อมีการแก้ไข ไม่ติดแคชเก่า
 *
 * $rel = เส้นทางไฟล์นับจากรากโปรเจกต์ เช่น 'assets/css/kp.css'
 * $prefix = เส้นทางเว็บที่จะใช้นำหน้า เช่น '../' เมื่อเรียกจากโฟลเดอร์ user/ หรือ admin/
 */
if (!function_exists('kp_asset')) {
    function kp_asset(string $rel, string $prefix = ''): string {
        $fs = dirname(__DIR__) . '/' . ltrim($rel, '/');
        $t  = @filemtime($fs);
        return $prefix . $rel . ($t ? '?v=' . $t : '');
    }
}
