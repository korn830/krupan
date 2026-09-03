<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

// --- ต้องล็อกอินอยู่เท่านั้น (รองรับทั้ง admin และ user) ---
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['reply' => '❌ กรุณาเข้าสู่ระบบก่อนใช้งานผู้ช่วย AI']);
    exit;
}

$currentRole   = $_SESSION['role'] ?? 'user';
$currentUserId = (int)$_SESSION['user_id'];

require dirname(__DIR__) . '/config/db.php';

// --- GET: โหลดประวัติแชทล่าสุดของผู้ใช้ ---
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'history') {
    $stmt = $conn->prepare(
        "SELECT role, content, created_at
         FROM ai_chat_history
         WHERE user_id = ?
         ORDER BY created_at DESC
         LIMIT 20"
    );
    $stmt->execute([$currentUserId]);
    $rows = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
    echo json_encode(['history' => $rows]);
    exit;
}

// --- POST action=clear: ล้างประวัติแชทของผู้ใช้ ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_REQUEST['action'] ?? '') === 'clear') {
    $conn->prepare("DELETE FROM ai_chat_history WHERE user_id = ?")->execute([$currentUserId]);
    echo json_encode(['ok' => true]);
    exit;
}

// --- POST: ส่งข้อความใหม่ ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['reply' => 'Invalid request']);
    exit;
}

$configPath = __DIR__ . '/../config/ai_config.php';
if (!file_exists($configPath)) {
    echo json_encode(['reply' => '❌ ไม่พบไฟล์ config/ai_config.php กรุณาคัดลอกจาก ai_config.example.php']);
    exit;
}
$aiConfig = require $configPath;
$provider = $aiConfig['provider'] ?? 'ollama';

$userMessage = trim($_POST['message'] ?? '');
if ($userMessage === '') {
    echo json_encode(['reply' => 'พิมพ์คำสั่งหรือคำถามมาได้เลยครับ เช่น "ค้นหาคอมพิวเตอร์" หรือ "แจ้งซ่อมเลข 097-001-0001"']);
    exit;
}

// =====================================================================
//  ฟังก์ชันที่ทำงานกับฐานข้อมูลจริง (โมเดลเรียกผ่าน tool-use เท่านั้น)
// =====================================================================

/**
 * ค้นหาครุภัณฑ์จากคำค้น (ชื่อ, เลขรหัส, หมวดหมู่, สถานที่, แผนก, สถานะ)
 */
function tool_search_assets(PDO $conn, array $input): array
{
    $query = trim((string)($input['query'] ?? ''));
    if ($query === '') {
        return ['count' => 0, 'results' => [], 'error' => 'ไม่ได้ระบุคำค้นหา'];
    }
    $like = '%' . $query . '%';

    $stmt = $conn->prepare(
        "SELECT a.asset_code, a.name, a.status, a.borrowable_status,
                c.name AS category_name, l.name AS location_name, d.name AS department_name
         FROM assets a
         LEFT JOIN categories c ON a.category_id = c.category_id
         LEFT JOIN locations l ON a.location_id = l.location_id
         LEFT JOIN departments d ON a.department_id = d.department_id
         WHERE a.asset_code LIKE ? OR a.name LIKE ? OR a.status LIKE ?
            OR c.name LIKE ? OR l.name LIKE ? OR d.name LIKE ?
         ORDER BY a.asset_id DESC
         LIMIT 10"
    );
    $stmt->execute([$like, $like, $like, $like, $like, $like]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return ['count' => count($rows), 'results' => $rows];
}

/**
 * อัปเดตสถานะของครุภัณฑ์ 1 ชิ้น (ระบุด้วย asset_code ที่ถูกต้องแน่นอนเท่านั้น)
 * บันทึก log ลง asset_action_log ทุกครั้งที่อัปเดตสำเร็จ
 */
function tool_update_asset_status(PDO $conn, array $input, int $adminUserId): array
{
    // ไม่อนุญาตให้ตั้งเป็น "ถูกยืม" หรือ "รออนุมัติ" ผ่านแชท เพราะสถานะนี้ต้องผ่านขั้นตอนยืม-คืนที่ถูกต้องเท่านั้น
    $allowedStatuses = ['ใช้งานปกติ', 'ชำรุด', 'ส่งซ่อม', 'จำหน่าย'];

    $assetCode = trim((string)($input['asset_code'] ?? ''));
    $newStatus = trim((string)($input['new_status'] ?? ''));
    $note      = trim((string)($input['note'] ?? ''));

    if ($assetCode === '') {
        return ['success' => false, 'error' => 'ไม่ได้ระบุ asset_code'];
    }
    if (!in_array($newStatus, $allowedStatuses, true)) {
        return [
            'success' => false,
            'error' => 'สถานะไม่ถูกต้อง ต้องเป็นหนึ่งใน: ' . implode(', ', $allowedStatuses),
        ];
    }

    $stmt = $conn->prepare("SELECT asset_id, name, status FROM assets WHERE asset_code = ?");
    $stmt->execute([$assetCode]);
    $asset = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$asset) {
        return ['success' => false, 'error' => "ไม่พบครุภัณฑ์ที่มีเลขรหัส {$assetCode}"];
    }

    $oldStatus = $asset['status'];

    if ($oldStatus === $newStatus) {
        return [
            'success' => false,
            'error' => "ครุภัณฑ์ {$asset['name']} ({$assetCode}) มีสถานะเป็น '{$newStatus}' อยู่แล้ว",
        ];
    }

    try {
        $conn->beginTransaction();

        $upd = $conn->prepare("UPDATE assets SET status = ? WHERE asset_id = ?");
        $upd->execute([$newStatus, $asset['asset_id']]);

        $details = json_encode([
            'source' => 'ai_chat',
            'status' => ['old' => $oldStatus, 'new' => $newStatus],
            'note'   => $note,
        ], JSON_UNESCAPED_UNICODE);

        $log = $conn->prepare(
            "INSERT INTO asset_action_log (asset_id, action_type, user_id, details) VALUES (?, 'edit', ?, ?)"
        );
        $log->execute([$asset['asset_id'], $adminUserId, $details]);

        $conn->commit();
    } catch (PDOException $e) {
        $conn->rollBack();
        return ['success' => false, 'error' => 'เกิดข้อผิดพลาดขณะบันทึกข้อมูล: ' . $e->getMessage()];
    }

    return [
        'success'    => true,
        'asset_code' => $assetCode,
        'asset_name' => $asset['name'],
        'old_status' => $oldStatus,
        'new_status' => $newStatus,
    ];
}

/**
 * ค้นหาครุภัณฑ์ที่สามารถยืมได้เท่านั้น (สำหรับ user)
 */
function tool_search_assets_user(PDO $conn, array $input): array
{
    $query = trim((string)($input['query'] ?? ''));
    if ($query === '') {
        return ['count' => 0, 'results' => [], 'error' => 'ไม่ได้ระบุคำค้นหา'];
    }
    $like = '%' . $query . '%';

    $stmt = $conn->prepare(
        "SELECT a.asset_code, a.name, a.status, a.borrowable_status,
                c.name AS category_name, l.name AS location_name, d.name AS department_name
         FROM assets a
         LEFT JOIN categories c ON a.category_id = c.category_id
         LEFT JOIN locations l ON a.location_id = l.location_id
         LEFT JOIN departments d ON a.department_id = d.department_id
         WHERE a.borrowable_status = 'สามารถยืมได้'
           AND a.status = 'ใช้งานปกติ'
           AND (a.asset_code LIKE ? OR a.name LIKE ?
                OR c.name LIKE ? OR l.name LIKE ? OR d.name LIKE ?)
         ORDER BY a.asset_id DESC
         LIMIT 10"
    );
    $stmt->execute([$like, $like, $like, $like, $like]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return ['count' => count($rows), 'results' => $rows];
}

/**
 * ดูประวัติการยืมของผู้ใช้ตัวเอง (สำหรับ user เท่านั้น — ไม่เห็นข้อมูลของคนอื่น)
 */
function tool_check_my_borrows(PDO $conn, array $input, int $userId): array
{
    $statusFilter = trim((string)($input['status'] ?? ''));

    $sql = "SELECT bh.borrow_id, a.asset_code, a.name AS asset_name,
                   bh.borrow_date, bh.return_date, bh.status, bh.note
            FROM borrow_history bh
            JOIN assets a ON bh.asset_id = a.asset_id
            WHERE bh.user_id = ?";
    $params = [$userId];

    if ($statusFilter !== '') {
        $sql .= " AND bh.status = ?";
        $params[] = $statusFilter;
    }

    $sql .= " ORDER BY bh.borrow_id DESC LIMIT 10";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return ['count' => count($rows), 'results' => $rows];
}

/**
 * รันเครื่องมือจริงๆ ตาม role (admin เข้าถึงได้ทุกอย่าง, user เข้าถึงได้เฉพาะที่อนุญาต)
 */
function run_tool(string $toolName, array $toolInput, PDO $conn, int $userId, string $role): array
{
    if ($role === 'admin') {
        switch ($toolName) {
            case 'search_assets':
                return tool_search_assets($conn, $toolInput);
            case 'update_asset_status':
                return tool_update_asset_status($conn, $toolInput, $userId);
            default:
                return ['error' => 'ไม่รู้จักเครื่องมือนี้: ' . $toolName];
        }
    } else {
        // user — จำกัดเฉพาะ tool ที่ปลอดภัย
        switch ($toolName) {
            case 'search_assets':
                return tool_search_assets_user($conn, $toolInput);
            case 'check_my_borrows':
                return tool_check_my_borrows($conn, $toolInput, $userId);
            default:
                return ['error' => 'คุณไม่มีสิทธิ์ใช้เครื่องมือนี้'];
        }
    }
}

// =====================================================================
//  Tool definitions — แยกตาม role
// =====================================================================

$toolsAdmin = [
    [
        'type' => 'function',
        'function' => [
            'name' => 'search_assets',
            'description' => 'ค้นหาครุภัณฑ์จากชื่อ, เลขรหัส, หมวดหมู่, สถานที่, แผนก หรือสถานะ',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'คำค้นหา'],
                ],
                'required' => ['query'],
            ],
        ],
    ],
    [
        'type' => 'function',
        'function' => [
            'name' => 'update_asset_status',
            'description' => 'อัปเดตสถานะของครุภัณฑ์ 1 ชิ้น ต้องรู้ asset_code ที่ถูกต้องก่อน ' .
                'ถ้ายังไม่รู้ให้เรียก search_assets ก่อนเสมอ ' .
                'ห้ามตั้งสถานะ "ถูกยืม" หรือ "รออนุมัติ" ผ่านเครื่องมือนี้',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'asset_code' => ['type' => 'string', 'description' => 'เลขรหัสครุภัณฑ์'],
                    'new_status' => [
                        'type' => 'string',
                        'enum' => ['ใช้งานปกติ', 'ชำรุด', 'ส่งซ่อม', 'จำหน่าย'],
                    ],
                    'note' => ['type' => 'string', 'description' => 'หมายเหตุ (ถ้ามี)'],
                ],
                'required' => ['asset_code', 'new_status'],
            ],
        ],
    ],
];

$toolsUser = [
    [
        'type' => 'function',
        'function' => [
            'name' => 'search_assets',
            'description' => 'ค้นหาครุภัณฑ์ที่สามารถยืมได้จากชื่อ, หมวดหมู่ หรือสถานที่',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'คำค้นหา เช่น คอมพิวเตอร์ ห้อง 5406'],
                ],
                'required' => ['query'],
            ],
        ],
    ],
    [
        'type' => 'function',
        'function' => [
            'name' => 'check_my_borrows',
            'description' => 'ดูรายการยืมของตัวเอง สามารถกรองด้วยสถานะได้',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'status' => [
                        'type' => 'string',
                        'description' => 'กรองด้วยสถานะ เช่น รออนุมัติ, อนุมัติ, คืนแล้ว (ถ้าไม่ระบุ = ดูทั้งหมด)',
                    ],
                ],
                'required' => [],
            ],
        ],
    ],
];

// เลือก tools และ system prompt ตาม role
if ($currentRole === 'admin') {
    $toolsOpenAI = $toolsAdmin;
    $systemPrompt = <<<EOT
คุณคือ "Krupan AI" ผู้ช่วยจัดการครุภัณฑ์ของวิทยาลัย ตอบเป็นภาษาไทยเท่านั้น พูดสุภาพ กระชับ เป็นกันเอง

หน้าที่ของคุณมี 2 อย่าง:
1. ช่วยค้นหาข้อมูลครุภัณฑ์ (เรียก search_assets)
2. ช่วยอัปเดตสถานะครุภัณฑ์ตามคำสั่งของผู้ดูแลระบบ (เรียก update_asset_status)

กฎสำคัญ:
- ถ้าผู้ใช้สั่งอัปเดตแต่ระบุแค่ชื่อ ให้เรียก search_assets ก่อนเสมอ
- ถ้าเจอมากกว่า 1 รายการ ห้ามเดา ให้แสดงตัวเลือกแล้วถาม
- เมื่อ update สำเร็จ ขึ้นต้นด้วย "✅" เสมอ
- ห้ามเปิดเผยรายละเอียดทางเทคนิค
EOT;
} else {
    $toolsOpenAI = $toolsUser;
    $systemPrompt = <<<EOT
คุณคือ "Krupan AI" ผู้ช่วยสำหรับนักเรียน/บุคลากรของวิทยาลัย ตอบเป็นภาษาไทยเท่านั้น พูดสุภาพ กระชับ เป็นกันเอง

หน้าที่ของคุณมี 2 อย่าง:
1. ช่วยค้นหาครุภัณฑ์ที่ต้องการยืม (เรียก search_assets) — จะแสดงเฉพาะรายการที่ยืมได้เท่านั้น
2. ช่วยตรวจสอบสถานะการยืมของตัวเอง (เรียก check_my_borrows)

กฎสำคัญ:
- คุณไม่สามารถแก้ไขข้อมูลใดๆ ได้ — ถ้าผู้ใช้ขอแจ้งซ่อมหรือเปลี่ยนสถานะ ให้แจ้งว่าต้องติดต่อผู้ดูแลระบบ
- ถ้าค้นหาครุภัณฑ์แล้วไม่พบ ให้แนะนำให้ลองคำค้นอื่น หรือติดต่อผู้ดูแลระบบ
- ถ้าผู้ใช้ถามว่ายืมได้ไหม ให้ดูจาก borrowable_status และ status ของครุภัณฑ์
- ห้ามเปิดเผยรายละเอียดทางเทคนิค
EOT;
}

$maxTurns = 4;

// --- โหลดประวัติการสนทนาล่าสุด (10 exchanges = 20 messages) ---
$historyStmt = $conn->prepare(
    "SELECT role, content FROM ai_chat_history
     WHERE user_id = ?
     ORDER BY created_at DESC LIMIT 20"
);
$historyStmt->execute([$currentUserId]);
$historyRows = array_reverse($historyStmt->fetchAll(PDO::FETCH_ASSOC));

try {
    $finalReply = ($provider === 'anthropic')
        ? run_chat_anthropic($aiConfig, $systemPrompt, $toolsOpenAI, $userMessage, $historyRows, $conn, $currentUserId, $currentRole, $maxTurns)
        : run_chat_ollama($aiConfig, $systemPrompt, $toolsOpenAI, $userMessage, $historyRows, $conn, $currentUserId, $currentRole, $maxTurns);

    if ($finalReply === '') {
        $finalReply = 'ขออภัยครับ ผมไม่สามารถดำเนินการให้เสร็จสิ้นได้ในขณะนี้ ลองพิมพ์คำสั่งใหม่อีกครั้งนะครับ';
    }

    // --- บันทึกข้อความผู้ใช้และคำตอบ AI ลงฐานข้อมูล ---
    $saveStmt = $conn->prepare(
        "INSERT INTO ai_chat_history (user_id, role, content) VALUES (?, ?, ?)"
    );
    $saveStmt->execute([$currentUserId, 'user',      $userMessage]);
    $saveStmt->execute([$currentUserId, 'assistant', $finalReply]);

    // เก็บแค่ 40 ข้อความล่าสุดต่อคน (20 exchanges) ลบของเก่าทิ้ง
    $conn->prepare(
    "DELETE FROM ai_chat_history WHERE user_id = ? AND history_id NOT IN (
        SELECT history_id FROM (
            SELECT history_id FROM ai_chat_history WHERE user_id = ? ORDER BY created_at DESC LIMIT 40
        ) AS keep_rows
    )"  )->execute([$currentUserId, $currentUserId]);

    echo json_encode(['reply' => $finalReply]);
} catch (Exception $e) {
    echo json_encode(['reply' => '❌ เกิดข้อผิดพลาด: ' . $e->getMessage()]);
}

// =====================================================================
//  Ollama (local) - ใช้ /api/chat แบบ native tool-calling
// =====================================================================

function call_ollama_api(string $host, string $model, array $messages, array $tools): array
{
    $payload = [
        'model' => $model,
        'messages' => $messages,
        'tools' => $tools,
        'stream' => false,
        'think' => false, // ปิด extended thinking เพื่อให้ตอบเร็วขึ้น เปลี่ยนเป็น true ได้ถ้าต้องการให้คิดละเอียดขึ้น (แต่จะช้าลง)
    ];

    $ch = curl_init(rtrim($host, '/') . '/api/chat');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 90, // โมเดล local อาจช้ากว่า cloud โดยเฉพาะรอบแรกที่ต้องโหลดโมเดลเข้า VRAM
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new Exception('เรียก Ollama ไม่สำเร็จ (เปิดโปรแกรม Ollama อยู่หรือไม่?): ' . $curlErr);
    }

    $decoded = json_decode($response, true);

    if ($httpCode >= 400) {
        $msg = is_array($decoded) ? ($decoded['error'] ?? ('HTTP ' . $httpCode)) : ('HTTP ' . $httpCode);
        throw new Exception('Ollama error: ' . $msg . ' (ลองรัน "ollama pull ' . $model . '" หรือเช็คชื่อโมเดลใน config/ai_config.php)');
    }

    return $decoded;
}

function run_chat_ollama(array $aiConfig, string $systemPrompt, array $toolsOpenAI, string $userMessage, array $history, PDO $conn, int $userId, string $role, int $maxTurns): string
{
    $host  = $aiConfig['ollama_host'] ?? 'http://localhost:11434';
    $model = $aiConfig['ollama_model'] ?? 'qwen3.5:9b';

    // สร้าง messages โดยใส่ประวัติก่อน แล้วต่อด้วยข้อความใหม่
    $messages = [['role' => 'system', 'content' => $systemPrompt]];
    foreach ($history as $h) {
        $messages[] = ['role' => $h['role'], 'content' => $h['content']];
    }
    $messages[] = ['role' => 'user', 'content' => $userMessage];

    for ($turn = 0; $turn < $maxTurns; $turn++) {
        $result = call_ollama_api($host, $model, $messages, $toolsOpenAI);
        $message = $result['message'] ?? [];
        $toolCalls = $message['tool_calls'] ?? [];

        $messages[] = $message;

        if (empty($toolCalls)) {
            return (string)($message['content'] ?? '');
        }

        foreach ($toolCalls as $call) {
            $toolName = $call['function']['name'] ?? '';
            $rawArgs  = $call['function']['arguments'] ?? [];
            $toolInput = is_string($rawArgs) ? (json_decode($rawArgs, true) ?? []) : $rawArgs;

            $output = run_tool($toolName, $toolInput, $conn, $userId, $role);

            $messages[] = [
                'role' => 'tool',
                'tool_name' => $toolName,
                'content' => json_encode($output, JSON_UNESCAPED_UNICODE),
            ];
        }
    }

    return '';
}

// =====================================================================
//  Anthropic (cloud) - ใช้ /v1/messages แบบ tool-use ของ Claude
// =====================================================================

function call_claude_api(string $apiKey, string $model, string $system, array $tools, array $messages): array
{
    $payload = [
        'model' => $model,
        'max_tokens' => 1024,
        'system' => $system,
        'tools' => $tools,
        'messages' => $messages,
    ];

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new Exception('เรียก Claude API ไม่สำเร็จ: ' . $curlErr);
    }

    $decoded = json_decode($response, true);

    if ($httpCode >= 400) {
        $msg = $decoded['error']['message'] ?? ('HTTP ' . $httpCode);
        throw new Exception('Claude API error: ' . $msg);
    }

    return $decoded;
}

function run_chat_anthropic(array $aiConfig, string $systemPrompt, array $toolsOpenAI, string $userMessage, array $history, PDO $conn, int $userId, string $role, int $maxTurns): string
{
    $apiKey = $aiConfig['anthropic_api_key'] ?? '';
    $model  = $aiConfig['anthropic_model'] ?? 'claude-haiku-4-5-20251001';

    if (empty($apiKey) || $apiKey === 'YOUR_API_KEY_HERE') {
        return '❌ ยังไม่ได้ตั้งค่า Anthropic API Key กรุณาตั้งค่าในไฟล์ config/ai_config.php';
    }

    $toolsAnthropic = array_map(static function (array $t): array {
        return [
            'name' => $t['function']['name'],
            'description' => $t['function']['description'],
            'input_schema' => $t['function']['parameters'],
        ];
    }, $toolsOpenAI);

    // ใส่ประวัติก่อน แล้วต่อด้วยข้อความใหม่
    $messages = [];
    foreach ($history as $h) {
        $messages[] = ['role' => $h['role'], 'content' => $h['content']];
    }
    $messages[] = ['role' => 'user', 'content' => $userMessage];

    $finalReply = '';

    for ($turn = 0; $turn < $maxTurns; $turn++) {
        $result = call_claude_api($apiKey, $model, $systemPrompt, $toolsAnthropic, $messages);

        $stopReason = $result['stop_reason'] ?? '';
        $content = $result['content'] ?? [];

        $messages[] = ['role' => 'assistant', 'content' => $content];

        if ($stopReason !== 'tool_use') {
            foreach ($content as $block) {
                if (($block['type'] ?? '') === 'text') {
                    $finalReply .= $block['text'];
                }
            }
            break;
        }

        $toolResultBlocks = [];
        foreach ($content as $block) {
            if (($block['type'] ?? '') !== 'tool_use') {
                continue;
            }

            $toolName = $block['name'];
            $toolInput = $block['input'] ?? [];
            $toolUseId = $block['id'];

            $output = run_tool($toolName, $toolInput, $conn, $userId, $role);

            $toolResultBlocks[] = [
                'type' => 'tool_result',
                'tool_use_id' => $toolUseId,
                'content' => json_encode($output, JSON_UNESCAPED_UNICODE),
            ];
        }

        $messages[] = ['role' => 'user', 'content' => $toolResultBlocks];
    }

    return $finalReply;
}
