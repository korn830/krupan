<?php
/**
 * ajax_ai.php
 * Backend ของ "Krupan AI" - ผู้ช่วยแชทสำหรับค้นหาและอัปเดตสถานะครุภัณฑ์
 *
 * รองรับ 2 รูปแบบ (เลือกได้ใน config/ai_config.php):
 *  - provider = 'ollama'    -> เรียกโมเดลที่รันอยู่บนเครื่องคุณเองผ่าน Ollama (ฟรี, ส่วนตัว)
 *  - provider = 'anthropic' -> เรียก Claude ผ่าน Anthropic API (ต้องมี API Key)
 *
 * ทั้งสองแบบใช้ tool-use เหมือนกัน: โมเดลเลือกเองว่าจะ "ค้นหาครุภัณฑ์" หรือ "อัปเดตสถานะครุภัณฑ์"
 * ส่วนการดึง/แก้ไขข้อมูลจริงในฐานข้อมูลทำโดยฟังก์ชัน PHP ด้านล่าง ไม่ใช่ตัวโมเดล
 */

session_start();
header('Content-Type: application/json; charset=utf-8');

// --- ต้องเป็น admin ที่ล็อกอินอยู่เท่านั้น ---
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['reply' => '❌ กรุณาเข้าสู่ระบบในสิทธิ์ผู้ดูแลระบบก่อนใช้งานผู้ช่วย AI']);
    exit;
}

require dirname(__DIR__) . '/config/db.php';

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
 * รันเครื่องมือจริง ๆ ตามชื่อ + input ที่โมเดลขอมา (ใช้ร่วมกันทั้งสอง provider)
 */
function run_tool(string $toolName, array $toolInput, PDO $conn, int $adminUserId): array
{
    switch ($toolName) {
        case 'search_assets':
            return tool_search_assets($conn, $toolInput);
        case 'update_asset_status':
            return tool_update_asset_status($conn, $toolInput, $adminUserId);
        default:
            return ['error' => 'ไม่รู้จักเครื่องมือนี้: ' . $toolName];
    }
}

// =====================================================================
//  นิยาม Tool (รูปแบบ OpenAI/Ollama เป็นต้นแบบหลัก)
// =====================================================================

$toolsOpenAI = [
    [
        'type' => 'function',
        'function' => [
            'name' => 'search_assets',
            'description' => 'ค้นหาครุภัณฑ์จากชื่อ, เลขรหัสครุภัณฑ์, หมวดหมู่, สถานที่, แผนก หรือสถานะ ' .
                'ใช้เมื่อผู้ใช้ถามหาข้อมูลครุภัณฑ์ หรือเมื่อยังไม่รู้ asset_code ที่แน่ชัดก่อนจะอัปเดตสถานะ',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'คำค้นหา เช่น ชื่อครุภัณฑ์, เลขรหัส, หมวดหมู่ หรือสถานที่'],
                ],
                'required' => ['query'],
            ],
        ],
    ],
    [
        'type' => 'function',
        'function' => [
            'name' => 'update_asset_status',
            'description' => 'อัปเดตสถานะของครุภัณฑ์ 1 ชิ้น ต้องรู้ asset_code ที่ถูกต้องแน่นอนก่อนเรียกใช้ ' .
                '(ถ้ายังไม่รู้ asset_code ที่แน่ชัด ให้เรียก search_assets ก่อนเสมอ) ' .
                'สถานะ "ถูกยืม" และ "รออนุมัติ" ไม่สามารถตั้งผ่านเครื่องมือนี้ได้ เพราะต้องผ่านขั้นตอนยืม-คืนของระบบ',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'asset_code' => ['type' => 'string', 'description' => 'เลขรหัสครุภัณฑ์ที่ต้องการแก้ไข เช่น 097-001-0001'],
                    'new_status' => [
                        'type' => 'string',
                        'enum' => ['ใช้งานปกติ', 'ชำรุด', 'ส่งซ่อม', 'จำหน่าย'],
                        'description' => 'สถานะใหม่ที่ต้องการตั้ง',
                    ],
                    'note' => ['type' => 'string', 'description' => 'หมายเหตุเพิ่มเติม เช่น เหตุผลที่แจ้งซ่อม (ถ้ามี)'],
                ],
                'required' => ['asset_code', 'new_status'],
            ],
        ],
    ],
];

$systemPrompt = <<<EOT
คุณคือ "Krupan AI" ผู้ช่วยจัดการครุภัณฑ์ของวิทยาลัย ตอบเป็นภาษาไทยเท่านั้น พูดสุภาพ กระชับ เป็นกันเอง

หน้าที่ของคุณมี 2 อย่าง:
1. ช่วยค้นหาข้อมูลครุภัณฑ์ (เรียก search_assets)
2. ช่วยอัปเดตสถานะครุภัณฑ์ตามคำสั่งของผู้ดูแลระบบ (เรียก update_asset_status)

กฎสำคัญที่ต้องทำตามเสมอ:
- ถ้าผู้ใช้สั่งอัปเดตสถานะแต่ระบุมาแค่ชื่อ (ไม่ใช่เลขรหัสครุภัณฑ์) ให้เรียก search_assets ก่อนเสมอ เพื่อหา asset_code ที่ถูกต้อง
- ถ้า search_assets เจอมากกว่า 1 รายการที่ตรงกับคำขอ ห้ามเดาว่าหมายถึงชิ้นไหน ให้แสดงตัวเลือกทั้งหมด (เลขรหัส + ชื่อ) แล้วถามผู้ใช้ว่าหมายถึงชิ้นไหน
- ถ้าค้นหาแล้วไม่พบเลย ให้แจ้งผู้ใช้ตรงๆ อย่าสร้างข้อมูลขึ้นมาเอง
- เมื่อ update_asset_status สำเร็จ (success = true) ให้ตอบขึ้นต้นด้วย "✅" เสมอ และสรุปว่าเปลี่ยนจากสถานะอะไรเป็นอะไร
- เมื่อเกิดข้อผิดพลาดหรือ success = false ห้ามใส่ "✅" ในคำตอบ ให้อธิบายสาเหตุสั้นๆ อย่างสุภาพ
- ห้ามเปิดเผยรายละเอียดทางเทคนิคให้ผู้ใช้เห็น เช่น ชื่อตารางในฐานข้อมูล, SQL, หรือโครงสร้าง JSON
EOT;

$adminUserId = (int)$_SESSION['user_id'];
$maxTurns = 4; // กันลูปไม่จบในกรณีที่โมเดลเรียก tool ต่อเนื่องผิดปกติ

try {
    $finalReply = ($provider === 'anthropic')
        ? run_chat_anthropic($aiConfig, $systemPrompt, $toolsOpenAI, $userMessage, $conn, $adminUserId, $maxTurns)
        : run_chat_ollama($aiConfig, $systemPrompt, $toolsOpenAI, $userMessage, $conn, $adminUserId, $maxTurns);

    if ($finalReply === '') {
        $finalReply = 'ขออภัยครับ ผมไม่สามารถดำเนินการให้เสร็จสิ้นได้ในขณะนี้ ลองพิมพ์คำสั่งใหม่อีกครั้งนะครับ';
    }

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

function run_chat_ollama(array $aiConfig, string $systemPrompt, array $toolsOpenAI, string $userMessage, PDO $conn, int $adminUserId, int $maxTurns): string
{
    $host  = $aiConfig['ollama_host'] ?? 'http://localhost:11434';
    $model = $aiConfig['ollama_model'] ?? 'qwen3.5:9b';

    $messages = [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => $userMessage],
    ];

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
            // Ollama มักส่ง arguments มาเป็น array ที่ถูก parse แล้ว แต่กันไว้เผื่อบางรุ่นส่งมาเป็น JSON string
            $toolInput = is_string($rawArgs) ? (json_decode($rawArgs, true) ?? []) : $rawArgs;

            $output = run_tool($toolName, $toolInput, $conn, $adminUserId);

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

function run_chat_anthropic(array $aiConfig, string $systemPrompt, array $toolsOpenAI, string $userMessage, PDO $conn, int $adminUserId, int $maxTurns): string
{
    $apiKey = $aiConfig['anthropic_api_key'] ?? '';
    $model  = $aiConfig['anthropic_model'] ?? 'claude-haiku-4-5-20251001';

    if (empty($apiKey) || $apiKey === 'YOUR_API_KEY_HERE') {
        return '❌ ยังไม่ได้ตั้งค่า Anthropic API Key กรุณาตั้งค่าในไฟล์ config/ai_config.php';
    }

    // แปลงนิยาม tool จากรูปแบบ OpenAI/Ollama -> รูปแบบของ Anthropic
    $toolsAnthropic = array_map(static function (array $t): array {
        return [
            'name' => $t['function']['name'],
            'description' => $t['function']['description'],
            'input_schema' => $t['function']['parameters'],
        ];
    }, $toolsOpenAI);

    $messages = [
        ['role' => 'user', 'content' => $userMessage],
    ];

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

            $output = run_tool($toolName, $toolInput, $conn, $adminUserId);

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
