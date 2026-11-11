<?php
/**
 * theme.php — ปุ่มสลับโหมดสว่าง/มืด และสคริปต์ที่ตั้งธีมก่อนหน้าจะวาด
 *
 * ต้องตั้งค่า data-theme ตั้งแต่ก่อน <body> ถูกวาด ไม่งั้นหน้าจะวาบขาว
 * หนึ่งครั้งก่อนสลับเป็นมืด จึงวางสคริปต์ไว้ใน <head>
 *
 * ค่าที่เลือกเก็บใน localStorage ของเครื่องผู้ใช้ ไม่ได้เก็บในฐานข้อมูล
 * ถ้าผู้ใช้ไม่เคยเลือก จะตามการตั้งค่าของเครื่อง
 */
function kp_theme_boot(): void { ?>
<script>
(function () {
    try {
        var t = localStorage.getItem('kp-theme');
        if (t === 'dark' || t === 'light') document.documentElement.setAttribute('data-theme', t);
    } catch (e) { /* โหมดส่วนตัวหรือปิดการเก็บข้อมูล ให้ใช้ค่าของเครื่องไป */ }
})();
</script>
<?php }

function kp_theme_toggle(): void { ?>
<button type="button" class="kp-theme-toggle" id="kpThemeToggle"
        title="สลับโหมดสว่าง/มืด" aria-label="สลับโหมดสว่าง/มืด">
    <i class="fas fa-moon" aria-hidden="true"></i>
    <span>โหมดมืด</span>
</button>
<script>
(function () {
    var btn = document.getElementById('kpThemeToggle');
    if (!btn) return;
    var root = document.documentElement;
    var icon = btn.querySelector('i');
    var text = btn.querySelector('span');

    function current() {
        var set = root.getAttribute('data-theme');
        if (set) return set;
        return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }
    function paint() {
        var dark = current() === 'dark';
        icon.className = dark ? 'fas fa-sun' : 'fas fa-moon';
        text.textContent = dark ? 'โหมดสว่าง' : 'โหมดมืด';
        btn.setAttribute('aria-pressed', dark ? 'true' : 'false');
    }
    btn.addEventListener('click', function () {
        var next = current() === 'dark' ? 'light' : 'dark';
        root.setAttribute('data-theme', next);
        try { localStorage.setItem('kp-theme', next); } catch (e) {}
        paint();
    });
    paint();
})();
</script>
<?php }
