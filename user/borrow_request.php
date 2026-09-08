<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    // จำหน้าที่ผู้ใช้ต้องการกลับไปหลัง Login
    $redirect = 'user/borrow_request.php';

    if (!empty($_GET['id'])) {
        $redirect .= '?id=' . (int)$_GET['id'];
    }

    header('Location: ../index.php?redirect=' . urlencode($redirect));
    exit;
}
require_once dirname(__DIR__) . '/config/db.php';

$asset_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = $conn->prepare(
    "SELECT a.*, c.name AS cat_name, l.name AS loc_name, d.name AS dept_name
     FROM assets a
     LEFT JOIN categories c  ON a.category_id  = c.category_id
     LEFT JOIN locations l   ON a.location_id   = l.location_id
     LEFT JOIN departments d ON a.department_id = d.department_id
     WHERE a.asset_id = ?"
);
$stmt->execute([$asset_id]);
$asset = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$asset) {
    header("Location: list.php");
    exit;
}

$canBorrow = $asset['borrowable_status'] === 'สามารถยืมได้'
          && $asset['status'] === 'ใช้งานปกติ';

// รับ error จาก process_borrow.php ผ่าน session (ถ้ามี)
$formError = '';
if (!empty($_SESSION['borrow_error'])) {
    $formError = $_SESSION['borrow_error'];
    unset($_SESSION['borrow_error']);
}

// รับค่าที่ผู้ใช้กรอกไว้เดิม (กรณีส่งฟอร์มแล้วไม่ผ่านการตรวจสอบ) เพื่อไม่ให้ต้องกรอกใหม่ทั้งหมด
$oldInput = $_SESSION['borrow_old'] ?? [];
unset($_SESSION['borrow_old']);

// --- ขอบเขตวันที่: คำนวณที่เดียว ใช้ร่วมกันทั้ง HTML และ JS ---
// ต้องตรงกับกฎใน process_borrow.php
const MAX_ADVANCE_DAYS = 14;   // จองล่วงหน้าได้สูงสุดกี่วันนับจากวันนี้
const MAX_BORROW_DAYS  = 14;   // ยืมได้นานสูงสุดกี่วันนับจาก "วันที่ยืม"

// ตรวจว่าเป็นวันที่รูปแบบ Y-m-d จริง ๆ (กันค่าขยะจาก session/POST)
function validDate($value): ?string {
    $value = trim((string)$value);
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return ($d && $d->format('Y-m-d') === $value) ? $value : null;
}

$today         = date('Y-m-d');
$maxBorrowDate = date('Y-m-d', strtotime('+' . MAX_ADVANCE_DAYS . ' days'));

// วันที่ยืมที่จะแสดงในฟอร์ม (ใช้ค่าเดิมของผู้ใช้ถ้ายังอยู่ในช่วงที่อนุญาต)
$borrowValue = validDate($oldInput['borrow_date'] ?? '') ?? $today;
if ($borrowValue < $today || $borrowValue > $maxBorrowDate) {
    $borrowValue = $today;
}

// สำคัญ: ขอบเขตของ "กำหนดคืน" ต้องอ้างอิงจาก "วันที่ยืม" ไม่ใช่ "วันนี้"
$minReturnDate = date('Y-m-d', strtotime($borrowValue . ' +1 day'));
$maxReturnDate = date('Y-m-d', strtotime($borrowValue . ' +' . MAX_BORROW_DAYS . ' days'));

$returnValue = validDate($oldInput['return_date'] ?? '') ?? '';
if ($returnValue !== '' && ($returnValue < $minReturnDate || $returnValue > $maxReturnDate)) {
    $returnValue = '';
}

$borrowerValue = (string)($oldInput['borrower_name'] ?? ($_SESSION['name'] ?? ''));
$noteValue     = (string)($oldInput['note'] ?? '');
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ขอยืมครุภัณฑ์ — <?= htmlspecialchars($asset['name']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap" rel="stylesheet">
    <?php require_once __DIR__ . '/../assets/kp_assets.php'; ?>
<link rel="stylesheet" href="<?= kp_asset('assets/css/shared/header.css', '../') ?>">
    <style>
        .borrow-card {
            max-width: 620px;
            margin: 2.5rem auto;
            background: var(--kp-surface);
            border-radius: var(--kp-radius);
            box-shadow: 0 4px 24px rgba(44, 74, 115, .13);
            overflow: hidden;
        }
        .borrow-card-header {
            background: var(--kp-gradient);
            padding: 1.1rem 1.5rem;
            color: #fff;
            display: flex;
            align-items: center;
            gap: .7rem;
        }
        .borrow-card-header h5 { margin: 0; font-weight: 700; font-size: 1.05rem; }
        .asset-info-strip {
            background: var(--kp-bg);
            padding: .85rem 1.5rem;
            border-bottom: 1px solid var(--kp-border);
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .asset-thumb {
            width: 60px; height: 60px;
            border-radius: 8px;
            object-fit: cover;
            border: 2px solid var(--kp-border);
            flex-shrink: 0;
        }
        .asset-thumb-placeholder {
            width: 60px; height: 60px;
            border-radius: 8px;
            background: var(--kp-border);
            display: flex; align-items: center; justify-content: center;
            font-size: 1.4rem;
            flex-shrink: 0;
        }
        .asset-info-strip .asset-name { font-weight: 700; font-size: 1rem; color: var(--kp-text); }
        .asset-info-strip .asset-meta { font-size: .82rem; color: var(--kp-muted); }
        .borrow-form-body { padding: 1.4rem 1.5rem; }
        .form-label { font-weight: 600; font-size: .9rem; }
        .form-control, .form-select {
            border-radius: 7px;
            border-color: var(--kp-border);
            font-family: 'Sarabun', sans-serif;
            font-size: .92rem;
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--kp-primary);
            box-shadow: 0 0 0 3px rgba(44, 74, 115, .15);
        }
        .btn-submit {
            background: var(--kp-gradient);
            border: none;
            color: #fff;
            border-radius: 8px;
            font-weight: 700;
            font-size: 1rem;
            padding: .65rem 1.5rem;
            width: 100%;
            font-family: 'Sarabun', sans-serif;
            transition: opacity .15s;
        }
        .btn-submit:hover { opacity: .88; color: #fff; }
        .back-link {
            display: flex;
            align-items: center;
            gap: .4rem;
            color: var(--kp-muted);
            text-decoration: none;
            font-size: .88rem;
            padding: .75rem 1.5rem;
            border-top: 1px solid var(--kp-border);
            transition: color .15s;
        }
        .back-link:hover { color: var(--kp-primary); }
        .not-available-box {
            padding: 1.5rem;
            text-align: center;
            color: var(--kp-muted);
        }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>

    <div class="container" style="padding-top:1.5rem; padding-bottom:3rem;">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="index.php">หน้าหลัก</a></li>
                <li class="breadcrumb-item"><a href="list.php">รายการครุภัณฑ์</a></li>
                <li class="breadcrumb-item active">ขอยืม</li>
            </ol>
        </nav>

        <div class="borrow-card">
            <!-- Header -->
            <div class="borrow-card-header">
                <i class="fas fa-hand-holding fa-lg"></i>
                <h5>แบบฟอร์มขอยืมครุภัณฑ์</h5>
            </div>

            <!-- Asset info strip -->
            <div class="asset-info-strip">
                <?php if (!empty($asset['image_url'])): ?>
                    <img src="../uploads/<?= htmlspecialchars($asset['image_url']) ?>"
                         alt="รูปครุภัณฑ์" class="asset-thumb">
                <?php else: ?>
                    <div class="asset-thumb-placeholder">📦</div>
                <?php endif; ?>
                <div>
                    <div class="asset-name"><?= htmlspecialchars($asset['name']) ?></div>
                    <div class="asset-meta">
                        เลขรหัส: <?= htmlspecialchars($asset['asset_code']) ?>
                        <?php if (!empty($asset['cat_name'])): ?> &bull; <?= htmlspecialchars($asset['cat_name']) ?><?php endif; ?>
                        <?php if (!empty($asset['loc_name'])): ?> &bull; <?= htmlspecialchars($asset['loc_name']) ?><?php endif; ?>
                    </div>
                    <div class="mt-1">
                        <span class="status-badge" data-status="<?= htmlspecialchars($asset['status']) ?>">
                            <?= htmlspecialchars($asset['status']) ?>
                        </span>
                    </div>
                </div>
            </div>

            <?php if ($canBorrow): ?>
                <!-- Error from last submission -->
                <?php if ($formError): ?>
                    <div class="alert alert-danger mx-3 mt-3 mb-0" role="alert">
                        <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($formError) ?>
                    </div>
                <?php endif; ?>

                <!-- Borrow form -->
                <form action="process_borrow.php" method="POST"
                      enctype="multipart/form-data"
                      class="borrow-form-body">
                    <input type="hidden" name="asset_id" value="<?= $asset_id ?>">

                    <div class="mb-3">
                        <label for="borrower_name" class="form-label">ชื่อผู้ยืม</label>
                        <input type="text" id="borrower_name" name="borrower_name"
                               class="form-control"
                               value="<?= htmlspecialchars($borrowerValue) ?>"
                               required>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-sm-6">
                            <label for="borrow_date" class="form-label">วันที่ยืม</label>
                            <input type="date" id="borrow_date" name="borrow_date"
                                   class="form-control"
                                   value="<?= htmlspecialchars($borrowValue) ?>"
                                   min="<?= htmlspecialchars($today) ?>"
                                   max="<?= htmlspecialchars($maxBorrowDate) ?>" required>
                            <div class="form-text">ยืมได้ล่วงหน้าสูงสุด <?= MAX_ADVANCE_DAYS ?> วัน</div>
                        </div>
                        <div class="col-sm-6">
                            <label for="return_date" class="form-label">กำหนดคืน</label>
                            <input type="date" id="return_date" name="return_date"
                                   class="form-control"
                                   value="<?= htmlspecialchars($returnValue) ?>"
                                   min="<?= htmlspecialchars($minReturnDate) ?>"
                                   max="<?= htmlspecialchars($maxReturnDate) ?>" required>
                            <div class="form-text">คืนภายใน <?= MAX_BORROW_DAYS ?> วันนับจากวันที่ยืม</div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="note" class="form-label">เหตุผล / หมายเหตุ</label>
                        <textarea id="note" name="note" class="form-control" rows="3"
                                  placeholder="ระบุวัตถุประสงค์การยืม..."><?= htmlspecialchars($noteValue) ?></textarea>
                    </div>

                    <div class="mb-4">
                        <label for="attachment" class="form-label">เอกสารแนบ (ถ้ามี)</label>
                        <input type="file" id="attachment" name="attachment"
                               class="form-control"
                               accept=".pdf,.jpg,.jpeg,.png,.webp">
                        <div class="form-text">
                            เช่น หนังสือขออนุมัติยืม — รองรับ PDF, JPG, PNG (ไม่เกิน 5MB)
                        </div>
                    </div>

                    <button type="submit" class="btn-submit">
                        <i class="fas fa-paper-plane me-2"></i>ส่งคำขอยืม
                    </button>
                </form>

            <?php else: ?>
                <div class="not-available-box">
                    <i class="fas fa-ban fa-2x mb-2 d-block" style="color:var(--st-broken)"></i>
                    <p class="mb-0">ขออภัย ครุภัณฑ์ชิ้นนี้ไม่พร้อมให้ยืมในขณะนี้<br>
                    <small>(สถานะ: <?= htmlspecialchars($asset['status']) ?> / <?= htmlspecialchars($asset['borrowable_status']) ?>)</small></p>
                </div>
            <?php endif; ?>

            <a href="list.php" class="back-link">
                <i class="fas fa-arrow-left"></i> กลับรายการครุภัณฑ์
            </a>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // ตรวจสอบว่า return_date ต้องหลังจาก borrow_date และไม่เกิน MAX_BORROW_DAYS วัน
        (function () {
            var borrowInput = document.getElementById('borrow_date');
            var returnInput = document.getElementById('return_date');

            // ครุภัณฑ์ที่ยืมไม่ได้จะไม่มีฟอร์ม — ออกก่อนเพื่อไม่ให้สคริปต์พัง
            if (!borrowInput || !returnInput) return;

            var TODAY           = <?= json_encode($today) ?>;
            var MAX_BORROW_DATE = <?= json_encode($maxBorrowDate) ?>;
            var MAX_DAYS        = <?= (int)MAX_BORROW_DAYS ?>;

            // บวกวันแบบ UTC ล้วน ๆ เพื่อไม่ให้ timezone/DST ของเครื่องผู้ใช้ทำให้วันเพี้ยนไป 1 วัน
            function addDays(ymd, days) {
                var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(ymd || '');
                if (!m) return null;
                var d = new Date(Date.UTC(+m[1], +m[2] - 1, +m[3]));
                if (isNaN(d.getTime())) return null;
                d.setUTCDate(d.getUTCDate() + days);
                return d.toISOString().slice(0, 10);
            }

            function syncReturnRange() {
                var bd = borrowInput.value;

                // วันที่ยืมยังว่างหรือไม่ถูกต้อง — ปล่อยให้ฝั่งเซิร์ฟเวอร์เป็นคนตรวจ
                if (!addDays(bd, 0) || bd < TODAY || bd > MAX_BORROW_DATE) {
                    returnInput.removeAttribute('min');
                    returnInput.removeAttribute('max');
                    return;
                }

                var minReturn = addDays(bd, 1);
                var maxReturn = addDays(bd, MAX_DAYS);
                returnInput.min = minReturn;
                returnInput.max = maxReturn;

                // ล้างกำหนดคืนเดิมถ้าหลุดออกนอกช่วงใหม่
                if (returnInput.value &&
                    (returnInput.value < minReturn || returnInput.value > maxReturn)) {
                    returnInput.value = '';
                }
            }

            borrowInput.addEventListener('change', syncReturnRange);
            borrowInput.addEventListener('input', syncReturnRange);

            // สำคัญ: ต้องซิงก์ตอนโหลดหน้าด้วย ไม่ใช่เฉพาะตอนผู้ใช้เปลี่ยนค่า
            syncReturnRange();
        })();
    </script>
</body>
</html>
