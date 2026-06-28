<?php
/**
 * Krupan AI - ตัวอย่างไฟล์ตั้งค่า
 *
 * วิธีใช้:
 * 1. คัดลอกไฟล์นี้เป็น "ai_config.php" (อยู่โฟลเดอร์เดียวกัน)
 * 2. เลือก provider ที่จะใช้ (ดูคำอธิบายด้านล่าง)
 *
 * ไฟล์ ai_config.php (ตัวจริง) ถูกใส่ไว้ใน .gitignore แล้ว
 * ดังนั้นจะไม่ถูก commit ขึ้น Git โดยไม่ตั้งใจ
 */

return [
    // 'ollama'    = ใช้โมเดลที่รันบนเครื่องคุณเอง ผ่าน Ollama (ฟรี, ส่วนตัว, ต้องเปิดเครื่อง/Ollama ไว้)
    // 'anthropic' = ใช้ Claude ผ่าน API ของ Anthropic (เสียค่าใช้จ่ายตามการใช้งาน, ไม่ต้องเปิดเครื่องตัวเองทิ้งไว้)
    'provider' => 'ollama',

    // --- ตั้งค่าเมื่อ provider = 'ollama' ---
    'ollama_host'  => 'http://localhost:11434',
    'ollama_model' => 'qwen3.5:9b', // ตัวที่แนะนำสำหรับการ์ดจอ VRAM 8GB เช่น RTX 3070 Ti
    // โมเดลอื่นที่ลองได้: 'qwen3.5:35b-a3b' (ฉลาดขึ้น แต่ใช้ RAM มากขึ้น และช้าลงเล็กน้อย)

    // --- ตั้งค่าเมื่อ provider = 'anthropic' ---
    // ขอ API Key ได้ที่ https://console.anthropic.com/settings/keys
    'anthropic_api_key' => 'YOUR_API_KEY_HERE',
    'anthropic_model'   => 'claude-haiku-4-5-20251001',
];
