<!-- Font Awesome (ensure it's always loaded) -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<!-- Chat bubble button -->
<button id="ai-chat-btn" title="Krupan AI">
    <i class="fas fa-robot"></i>
</button>

<!-- Chat window -->
<div id="ai-chat-box" class="chat-hidden">

    <div id="ai-chat-header">
        <div class="d-flex align-items-center gap-2">
            <i class="fas fa-robot"></i>
            <div>
                <div style="font-weight:700; font-size:.95rem;">Krupan AI</div>
                <div id="ai-status-dot" style="font-size:.75rem; opacity:.8;">● พร้อมใช้งาน</div>
            </div>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <button id="ai-clear-btn" title="ล้างประวัติแชท" style="background:rgba(255,255,255,.15); border:none; color:#fff; border-radius:6px; padding:3px 8px; font-size:.8rem; cursor:pointer;">
                <i class="fas fa-trash-can"></i>
            </button>
            <button id="ai-chat-close" style="background:none; border:none; color:#fff; font-size:1.1rem; cursor:pointer; line-height:1;">
                <i class="fas fa-xmark"></i>
            </button>
        </div>
    </div>

    <div id="ai-chat-messages">
        <div class="ai-msg">
            <span class="ai-bubble">
                สวัสดีครับ ผม Krupan AI 👋<br>
                มีอะไรให้ช่วยไหมครับ?
            </span>
        </div>
    </div>

    <div id="ai-chat-footer">
        <form id="ai-chat-form">
            <input type="text" id="ai-chat-input" placeholder="พิมพ์คำถาม..." autocomplete="off">
            <button type="submit">
                <i class="fas fa-paper-plane"></i>
            </button>
        </form>
    </div>
</div>

<style>
#ai-chat-btn {
    position: fixed; bottom: 28px; right: 28px;
    width: 60px; height: 60px; border-radius: 50%;
    background: var(--kp-primary);
    border: none; color: #fff; font-size: 1.4rem;
    box-shadow: 0 4px 18px rgba(44, 74, 115, .4);
    cursor: pointer; z-index: 9998;
    transition: transform .2s, box-shadow .2s;
    display: flex; align-items: center; justify-content: center;
}
#ai-chat-btn:hover { transform: scale(1.1); box-shadow: 0 6px 24px rgba(118,75,162,.5); }

#ai-chat-box {
    position: fixed; bottom: 100px; right: 28px;
    width: 360px; height: 540px; z-index: 9999;
    display: flex; flex-direction: column;
    border-radius: 16px; overflow: hidden;
    box-shadow: 0 8px 40px rgba(0,0,0,.18);
    transition: all .3s cubic-bezier(.175,.885,.32,1.275);
    transform-origin: bottom right;
    font-family: 'Sarabun', sans-serif;
}
.chat-hidden { transform: scale(0); opacity: 0; pointer-events: none; }

#ai-chat-header {
    background: var(--kp-primary);
    color: #fff; padding: .75rem 1rem;
    display: flex; align-items: center; justify-content: space-between;
    flex-shrink: 0;
}

#ai-chat-messages {
    flex-grow: 1; overflow-y: auto;
    background: #f0f2ff; padding: .75rem;
    display: flex; flex-direction: column; gap: .5rem;
}

.user-msg { display: flex; justify-content: flex-end; }
.ai-msg   { display: flex; justify-content: flex-start; }

.user-bubble {
    background: var(--kp-primary);
    color: #fff; padding: .5rem .85rem;
    border-radius: 18px 18px 4px 18px;
    max-width: 82%; font-size: .9rem; line-height: 1.5;
    word-break: break-word;
}
.ai-bubble {
    background: #fff; color: #2d3748;
    border: 1px solid #e2e8f0;
    padding: .5rem .85rem;
    border-radius: 18px 18px 18px 4px;
    max-width: 82%; font-size: .9rem; line-height: 1.5;
    word-break: break-word;
    box-shadow: 0 1px 4px rgba(0,0,0,.06);
}
.msg-time {
    font-size: .7rem; color: #a0aec0; margin-top: 2px;
    text-align: right;
}
.ai-msg .msg-time { text-align: left; }

#ai-chat-footer {
    background: #fff; padding: .65rem .75rem;
    border-top: 1px solid #e2e8f0; flex-shrink: 0;
}
#ai-chat-form { display: flex; gap: .5rem; }
#ai-chat-input {
    flex-grow: 1; border: 1.5px solid #e2e8f0;
    border-radius: 20px; padding: .45rem 1rem;
    font-family: 'Sarabun', sans-serif; font-size: .9rem;
    outline: none;
}
#ai-chat-input:focus { border-color: var(--kp-primary); }
#ai-chat-form button[type=submit] {
    width: 38px; height: 38px; border-radius: 50%;
    background: var(--kp-primary);
    border: none; color: #fff; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
    transition: opacity .15s;
}
#ai-chat-form button[type=submit]:hover { opacity: .88; }

.history-divider {
    text-align: center; font-size: .72rem; color: #a0aec0;
    margin: .25rem 0; user-select: none;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const chatBtn      = document.getElementById('ai-chat-btn');
    const chatBox      = document.getElementById('ai-chat-box');
    const chatClose    = document.getElementById('ai-chat-close');
    const chatClear    = document.getElementById('ai-clear-btn');
    const chatForm     = document.getElementById('ai-chat-form');
    const chatInput    = document.getElementById('ai-chat-input');
    const chatMessages = document.getElementById('ai-chat-messages');

    // Detect which folder we're in to build correct path to ajax_ai.php
    const isUserPage = window.location.pathname.includes('/user/');
    const aiEndpoint = isUserPage ? '../admin/ajax_ai.php' : 'ajax_ai.php';

    let historyLoaded = false;

    // Open/close
    chatBtn.addEventListener('click', () => {
        chatBox.classList.toggle('chat-hidden');
        if (!chatBox.classList.contains('chat-hidden') && !historyLoaded) {
            loadHistory();
        }
        if (!chatBox.classList.contains('chat-hidden')) {
            chatInput.focus();
        }
    });
    chatClose.addEventListener('click', () => chatBox.classList.add('chat-hidden'));

    // Clear history
    chatClear.addEventListener('click', () => {
        if (!confirm('ล้างประวัติแชทของคุณทั้งหมดไหมครับ?')) return;
        fetch(aiEndpoint + '?action=clear', { method: 'POST' })
            .then(() => {
                chatMessages.innerHTML = '';
                appendAI('ล้างประวัติแชทเรียบร้อยแล้วครับ 🗑️');
                historyLoaded = false;
            });
    });

    // Load history when chat first opens
    function loadHistory() {
        historyLoaded = true;
        fetch(aiEndpoint + '?action=history')
            .then(r => r.json())
            .then(data => {
                if (data.history && data.history.length > 0) {
                    // Add divider before history
                    const div = document.createElement('div');
                    div.className = 'history-divider';
                    div.textContent = '— ประวัติการสนทนาก่อนหน้า —';
                    chatMessages.insertBefore(div, chatMessages.firstChild);

                    // Insert history messages before the welcome message
                    const welcome = chatMessages.querySelector('.ai-msg');
                    data.history.forEach(h => {
                        const el = buildMessage(h.role === 'user' ? 'user' : 'ai', h.content, h.created_at);
                        chatMessages.insertBefore(el, welcome);
                    });
                    chatMessages.scrollTop = chatMessages.scrollHeight;
                }
            })
            .catch(() => {}); // fail silently
    }

    // Send message
    chatForm.addEventListener('submit', function (e) {
        e.preventDefault();
        const message = chatInput.value.trim();
        if (!message) return;

        appendUser(message);
        chatInput.value = '';
        chatInput.disabled = true;

        const loadingEl = buildMessage('ai', 'กำลังคิด <i class="fas fa-spinner fa-spin"></i>');
        chatMessages.appendChild(loadingEl);
        chatMessages.scrollTop = chatMessages.scrollHeight;

        fetch(aiEndpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'message=' + encodeURIComponent(message)
        })
        .then(r => r.json())
        .then(data => {
            loadingEl.remove();
            appendAI(data.reply || '❌ ไม่ได้รับคำตอบจาก AI');
            chatInput.disabled = false;
            chatInput.focus();
            if (data.reply && data.reply.includes('✅')) {
                setTimeout(() => location.reload(), 2000);
            }
        })
        .catch(() => {
            loadingEl.remove();
            appendAI('❌ เกิดข้อผิดพลาดในการเชื่อมต่อ');
            chatInput.disabled = false;
        });
    });

    function appendUser(text) {
        const el = buildMessage('user', text);
        chatMessages.appendChild(el);
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }
    function appendAI(text) {
        const el = buildMessage('ai', text);
        chatMessages.appendChild(el);
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }
    function buildMessage(type, text, timeStr = null) {
        const wrapper = document.createElement('div');
        wrapper.className = type === 'user' ? 'user-msg' : 'ai-msg';
        const inner = document.createElement('div');
        const bubble = document.createElement('div');
        bubble.className = type === 'user' ? 'user-bubble' : 'ai-bubble';
        bubble.innerHTML = text;
        const time = document.createElement('div');
        time.className = 'msg-time';
        time.textContent = timeStr ? new Date(timeStr).toLocaleTimeString('th-TH', {hour:'2-digit',minute:'2-digit'}) : new Date().toLocaleTimeString('th-TH', {hour:'2-digit',minute:'2-digit'});
        inner.appendChild(bubble);
        inner.appendChild(time);
        wrapper.appendChild(inner);
        return wrapper;
    }
});
</script>
