// js/asset_action_log.js
// departmentMap ต้องถูกกำหนดจาก <script> ใน HTML ก่อนโหลดไฟล์นี้

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.view-diff-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            let details = this.getAttribute('data-details');
            let diff = {};
            try { diff = JSON.parse(details); } catch(e) {}
            let html = '<table class="table table-sm table-bordered">';
            html += '<thead><tr><th>ฟิลด์</th><th>ข้อมูลเดิม</th><th>ข้อมูลใหม่</th></tr></thead><tbody>';
            for (const key in diff) {
                let fieldName = key;
                let oldVal = diff[key].old ?? '';
                let newVal = diff[key].new ?? '';
                if (key === 'department_id') {
                    fieldName = 'แผนก';
                    oldVal = window.departmentMap ? (window.departmentMap[oldVal] || oldVal) : oldVal;
                    newVal = window.departmentMap ? (window.departmentMap[newVal] || newVal) : newVal;
                }
                html += `<tr><td>${fieldName}</td><td>${oldVal}</td><td>${newVal}</td></tr>`;
            }
            html += '</tbody></table>';
            document.getElementById('diffContent').innerHTML = html;
            var modal = new bootstrap.Modal(document.getElementById('diffModal'));
            modal.show();
        });
    });
});