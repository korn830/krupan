<!-- ให้แน่ใจว่ามี Font Awesome เสมอ (บางหน้าเช่น Dashboard ยังไม่ได้โหลดไว้) -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<!-- ปุ่มเปิด/ปิดแชท (Bubble) -->
<button id="ai-chat-btn" class="btn btn-primary rounded-circle shadow" style="position: fixed; bottom: 30px; right: 30px; width: 65px; height: 65px; z-index: 9999; border: none;">
    <i class="fas fa-robot fs-3"></i>
</button>

<!-- กล่องหน้าต่างแชท (พร้อม CSS Animation) -->
<div id="ai-chat-box" class="card shadow chat-hidden" style="position: fixed; bottom: 110px; right: 30px; width: 360px; height: 550px; z-index: 9999; display: flex; flex-direction: column; border-radius: 15px; overflow: hidden; transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275); transform-origin: bottom right;">
    
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center py-3">
        <h5 class="mb-0"><i class="fas fa-robot me-2"></i> Krupan AI</h5>
        <button id="ai-chat-close" class="btn-close btn-close-white" aria-label="Close"></button>
    </div>
    
    <div class="card-body" id="ai-chat-messages" style="overflow-y: auto; flex-grow: 1; background-color: #f8f9fa; padding: 15px;">
        <!-- ข้อความต้อนรับ -->
        <div class="mb-3 text-start">
            <span class="badge bg-white text-dark border p-3 fs-6 text-wrap shadow-sm text-start">
                สวัสดีครับ ผม Krupan AI ผู้ช่วยจัดการครุภัณฑ์<br>
                มีอะไรให้ผมช่วยอัปเดตสถานะ หรือค้นหาข้อมูลไหมครับ?
            </span>
        </div>
    </div>
    
    <div class="card-footer bg-white border-top p-3">
        <form id="ai-chat-form" class="d-flex">
            <input type="text" id="ai-chat-input" class="form-control me-2 rounded-pill px-3" placeholder="พิมพ์คำสั่ง เช่น แจ้งซ่อม..." required autocomplete="off">
            <button type="submit" class="btn btn-primary rounded-circle" style="width: 40px; height: 40px; padding: 0;">
                <i class="fas fa-paper-plane"></i>
            </button>
        </form>
    </div>
</div>

<style>
/* Animation สำหรับซ่อน/แสดง */
.chat-hidden {
    transform: scale(0);
    opacity: 0;
    pointer-events: none;
}
#ai-chat-btn { transition: transform 0.2s; }
#ai-chat-btn:hover { transform: scale(1.1); }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const chatBtn = document.getElementById('ai-chat-btn');
    const chatBox = document.getElementById('ai-chat-box');
    const chatClose = document.getElementById('ai-chat-close');
    const chatForm = document.getElementById('ai-chat-form');
    const chatInput = document.getElementById('ai-chat-input');
    const chatMessages = document.getElementById('ai-chat-messages');

    // สลับเปิด/ปิด
    chatBtn.addEventListener('click', () => chatBox.classList.toggle('chat-hidden'));
    chatClose.addEventListener('click', () => chatBox.classList.add('chat-hidden'));

    // ส่งข้อความ
    chatForm.addEventListener('submit', function(e) {
        e.preventDefault();
        const message = chatInput.value.trim();
        if (!message) return;

        appendMessage('user', message);
        chatInput.value = '';

        const loadingId = 'loading-' + Date.now();
        appendMessage('ai', 'กำลังคิดสักครู่... <i class="fas fa-spinner fa-spin"></i>', loadingId);

        fetch('ajax_ai.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'message=' + encodeURIComponent(message)
        })
        .then(response => response.json())
        .then(data => {
            document.getElementById(loadingId).remove();
            if (data.reply) {
                appendMessage('ai', data.reply);
                if (data.reply.includes('✅')) {
                    setTimeout(() => location.reload(), 2000);
                }
            } else {
                appendMessage('ai', '❌ ขออภัยครับ ไม่ได้รับคำตอบจาก AI');
            }
        });
    });

    function appendMessage(sender, text, id = null) {
        const div = document.createElement('div');
        div.className = sender === 'user' ? 'mb-3 text-end' : 'mb-3 text-start';
        if (id) div.id = id;
        const span = document.createElement('span');
        span.className = sender === 'user' 
            ? 'badge bg-primary p-2 fs-6 text-wrap shadow-sm text-start' 
            : 'badge bg-white text-dark border p-2 fs-6 text-wrap shadow-sm text-start';
        span.innerHTML = text;
        div.appendChild(span);
        chatMessages.appendChild(div);
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }
});
</script>