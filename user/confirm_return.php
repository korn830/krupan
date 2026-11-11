<?php
/**
 * หน้ายืนยันการคืนครุภัณฑ์
 *
 * เดิมเป็นกล่อง modal อยู่ในหน้า my_borrow.php แต่ปุ่ม "ยืนยันการคืน"
 * ใช้คลาส .btn-return ซึ่งไม่มีนิยามอยู่ในไฟล์ CSS ไหนเลย
 * จึงเหลือแค่ .btn เปล่า ๆ กับ text-white กลายเป็นตัวอักษรขาวบนพื้นขาว
 * มองไม่เห็นปุ่ม และคืนครุภัณฑ์ไม่ได้
 *
 * ย้ายมาเป็นหน้าแยกแทน รายละเอียดครุภัณฑ์อ่านจากฐานข้อมูลตรง ๆ
 * ไม่ได้รับมาจากพารามิเตอร์ของ JavaScript เหมือนเดิม
 * และย้ายขั้นตอนบันทึกการคืนมาไว้ที่นี่ทั้งหมด พร้อมตรวจ CSRF
 * ซึ่งของเดิมในหน้า my_borrow.php ไม่มี
 */

session_start();

require_once __DIR__ . '/../assets/roles.php';
kp_require_cap('borrow.view.own', '../');
require '../config/db.php';
require_once dirname(__DIR__) . '/assets/borrow_status.php';

// ปรับรายการที่เลยกำหนดคืนให้เป็น 'เกินวันที่กำหนด' ก่อน จะได้ตรวจสถานะถูก
kp_mark_overdue_borrows($conn);

$user_id = (int)$_SESSION['user_id'];

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/** สถานะที่ยังคืนได้ ต้องตรงกับที่หน้า my_borrow.php ใช้ตัดสินว่าจะขึ้นปุ่มไหม */
const KP_RETURNABLE = ['อนุมัติ', 'ยืมอยู่', 'เกินวันที่กำหนด'];

$borrow_id = (int)($_POST['borrow_id'] ?? $_GET['borrow_id'] ?? 0);

/**
 * อ่านรายการยืม พร้อมตรวจสิทธิ์ในคำสั่งเดียว
 * เงื่อนไข user_id กันไม่ให้คืนรายการของคนอื่นด้วยการเดาเลข borrow_id
 */
function kp_load_returnable(PDO $conn, int $borrow_id, int $user_id): ?array
{
    if ($borrow_id <= 0) {
        return null;
    }
    $in = implode(',', array_fill(0, count(KP_RETURNABLE), '?'));
    $stmt = $conn->prepare(
        "SELECT bh.borrow_id, bh.asset_id, bh.borrow_date, bh.return_date, bh.note, bh.status,
                a.asset_code, a.name AS asset_name
           FROM borrow_history bh
           JOIN assets a ON a.asset_id = bh.asset_id
          WHERE bh.borrow_id = ? AND bh.user_id = ? AND bh.status IN ($in)"
    );
    $stmt->execute(array_merge([$borrow_id, $user_id], KP_RETURNABLE));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** แปลงวันที่เป็นรูปแบบไทย (พ.ศ.) */
if (!function_exists('kp_thai_date')) {
    function kp_thai_date(?string $date): string
    {
        if (empty($date)) {
            return '-';
        }
        $ts = strtotime($date);
        return $ts ? date('d/m/', $ts) . (date('Y', $ts) + 543) : '-';
    }
}

$borrow = kp_load_returnable($conn, $borrow_id, $user_id);

// ---------- กดยืนยันแล้ว ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['error_message'] = 'เกิดข้อผิดพลาดในการส่งข้อมูล กรุณาลองใหม่อีกครั้ง';
        header('Location: my_borrow.php');
        exit;
    }

    if ($borrow === null) {
        $_SESSION['error_message'] = 'ไม่พบรายการยืมนี้ หรือรายการนี้คืนไปแล้ว';
        header('Location: my_borrow.php');
        exit;
    }

    try {
        $conn->beginTransaction();

        // 1. รายการยืมเปลี่ยนเป็น "คืนแล้ว"
        //    เงื่อนไข user_id กับ status ซ้ำอีกชั้น กันกดย้อนแล้วส่งซ้ำ
        $in = implode(',', array_fill(0, count(KP_RETURNABLE), '?'));
        $upd = $conn->prepare(
            "UPDATE borrow_history
                SET status = 'คืนแล้ว', actual_return_date = NOW()
              WHERE borrow_id = ? AND user_id = ? AND status IN ($in)"
        );
        $upd->execute(array_merge([$borrow_id, $user_id], KP_RETURNABLE));

        if ($upd->rowCount() === 0) {
            $conn->rollBack();
            $_SESSION['error_message'] = 'รายการนี้ถูกคืนไปแล้ว';
            header('Location: my_borrow.php');
            exit;
        }

        // 2. ครุภัณฑ์กลับมาพร้อมให้ยืม
        $conn->prepare("UPDATE assets SET status = 'ใช้งานปกติ' WHERE asset_id = ?")
             ->execute([(int)$borrow['asset_id']]);

        // 3. บันทึกประวัติ
        $logDetails = json_encode([
            'action_borrow'     => 'returned',
            'borrow_id'         => $borrow_id,
            'asset_id'          => (int)$borrow['asset_id'],
            'new_borrow_status' => 'คืนแล้ว',
            'new_asset_status'  => 'ใช้งานปกติ',
        ], JSON_UNESCAPED_UNICODE);

        $conn->prepare("INSERT INTO asset_action_log (asset_id, action_type, user_id, details) VALUES (?, ?, ?, ?)")
             ->execute([(int)$borrow['asset_id'], 'borrow', $user_id, $logDetails]);

        $conn->commit();
        $_SESSION['success_message'] = 'คืนครุภัณฑ์ ' . $borrow['asset_code'] . ' เรียบร้อยแล้ว';
        header('Location: my_borrow.php');
        exit;

    } catch (PDOException $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        error_log('confirm_return: ' . $e->getMessage());
        $_SESSION['error_message'] = 'เกิดข้อผิดพลาดในการคืนครุภัณฑ์ กรุณาลองใหม่อีกครั้ง';
        header('Location: my_borrow.php');
        exit;
    }
}

// ---------- เปิดหน้ามาดู แต่ไม่มีรายการให้คืน ----------
if ($borrow === null) {
    $_SESSION['error_message'] = 'ไม่พบรายการยืมนี้ หรือรายการนี้คืนไปแล้ว';
    header('Location: my_borrow.php');
    exit;
}

$isOverdue = $borrow['status'] === 'เกินวันที่กำหนด';

require 'header.php'; // พ่น <head> แถบเมนู และเปิด .kp-main ค้างไว้
?>

<?php require_once __DIR__ . '/../assets/kp_assets.php'; ?>
<link rel="stylesheet" href="<?= kp_asset('user/css/confirm-return.css', '../') ?>">

<nav aria-label="breadcrumb">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="my_borrow.php">การยืมของฉัน</a></li>
        <li class="breadcrumb-item active" aria-current="page">ยืนยันการคืน</li>
    </ol>
</nav>

<div class="kp-confirm">
    <h4 class="kp-confirm__title">
        <i class="fas fa-rotate-left"></i>
        <span>ยืนยันการคืนครุภัณฑ์</span>
    </h4>
    <p class="kp-confirm__lead">ตรวจรายละเอียดด้านล่างให้ถูกต้อง แล้วกดยืนยันเพื่อคืนครุภัณฑ์ชิ้นนี้</p>

    <?php if ($isOverdue): ?>
        <div class="alert alert-warning" role="alert">
            <i class="fas fa-triangle-exclamation me-2"></i>
            รายการนี้<strong>เลยกำหนดคืนแล้ว</strong> คืนได้ตามปกติ แต่ควรแจ้งแอดมินให้ทราบด้วย
        </div>
    <?php endif; ?>

    <dl class="kp-confirm__list">
        <div class="kp-confirm__row">
            <dt>เลขครุภัณฑ์</dt>
            <dd class="kp-confirm__code"><?= htmlspecialchars($borrow['asset_code']) ?></dd>
        </div>
        <div class="kp-confirm__row">
            <dt>ชื่อครุภัณฑ์</dt>
            <dd><?= htmlspecialchars($borrow['asset_name']) ?></dd>
        </div>
        <div class="kp-confirm__row">
            <dt>วันที่ยืม</dt>
            <dd><?= htmlspecialchars(kp_thai_date($borrow['borrow_date'])) ?></dd>
        </div>
        <div class="kp-confirm__row">
            <dt>กำหนดคืน</dt>
            <dd<?= $isOverdue ? ' class="kp-confirm__overdue"' : '' ?>>
                <?= htmlspecialchars(kp_thai_date($borrow['return_date'])) ?>
            </dd>
        </div>
        <?php if (!empty($borrow['note'])): ?>
            <div class="kp-confirm__row">
                <dt>หมายเหตุ</dt>
                <dd><?= htmlspecialchars($borrow['note']) ?></dd>
            </div>
        <?php endif; ?>
    </dl>

    <div class="alert alert-info" role="alert">
        <i class="fas fa-circle-info me-2"></i>
        เมื่อคืนแล้ว สถานะจะเปลี่ยนเป็น "คืนแล้ว" และครุภัณฑ์จะพร้อมให้ผู้อื่นยืมต่อไป
    </div>

    <form method="POST" class="kp-confirm__actions">
        <input type="hidden" name="csrf_token"
               value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="borrow_id" value="<?= (int)$borrow['borrow_id'] ?>">

        <a href="my_borrow.php" class="btn btn-outline-secondary">
            <i class="fas fa-xmark me-1"></i> ยกเลิก
        </a>
        <button type="submit" class="btn btn-primary" id="kpConfirmBtn">
            <i class="fas fa-check me-1"></i> ยืนยันการคืน
        </button>
    </form>
</div>

<script>
// กันกดซ้ำระหว่างรอเซิร์ฟเวอร์ตอบ ถึงกดซ้ำได้ก็ไม่เกิดอะไร
// เพราะคำสั่ง UPDATE ฝั่งเซิร์ฟเวอร์ตรวจสถานะซ้ำอยู่แล้ว
document.querySelector('.kp-confirm__actions').addEventListener('submit', function () {
    var btn = document.getElementById('kpConfirmBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> กำลังบันทึก...';
});
</script>

<?php require 'footer.php'; ?>
