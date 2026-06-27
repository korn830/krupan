/**
 * Assets Management JavaScript
 * Handles interactions and animations for the assets management page
 */

// Show loading state when deleting
function showLoadingState() {
    const loadingOverlay = document.createElement('div');
    loadingOverlay.innerHTML = `
        <div style="
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.8);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            font-family: 'Prompt', sans-serif;
        ">
            <div style="text-align: center;">
                <div style="
                    width: 40px;
                    height: 40px;
                    border: 4px solid #e2e8f0;
                    border-top: 4px solid #667eea;
                    border-radius: 50%;
                    animation: spin 1s linear infinite;
                    margin: 0 auto 1rem;
                "></div>
                <p style="color: #4a5568; margin: 0;">กำลังลบข้อมูล...</p>
            </div>
        </div>
    `;
    // Add spin animation if not already present
    if (!document.getElementById('spin-animation-style')) {
        const style = document.createElement('style');
        style.id = 'spin-animation-style';
        style.textContent = `
            @keyframes spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }
        `;
        document.head.appendChild(style);
    }
    document.body.appendChild(loadingOverlay);
}

// Animate table rows on page load
function animateTableRows() {
    const rows = document.querySelectorAll('tbody tr');
    rows.forEach((row, index) => {
        row.style.animationDelay = `${index * 0.1}s`;
        row.classList.add('fade-in');
    });
}

// Setup modal event listeners for add/edit
function setupModalEvents() {
    ['addModal', 'editAssetModal'].forEach(modalId => {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.addEventListener('shown.bs.modal', function () {
                const input = this.querySelector('input[name="asset_code"], input[name="name"]');
                if (input) {
                    input.focus();
                    input.select();
                }
            });
            modal.addEventListener('hidden.bs.modal', function () {
                const form = this.querySelector('form');
                if (form) form.reset();
            });
        }
    });
}

// Setup form validation for add/edit asset
function setupFormValidation() {
    ['#addModal form', '#editAssetModal form'].forEach(selector => {
        const form = document.querySelector(selector);
        if (form) {
            form.addEventListener('submit', function(e) {
                const codeInput = this.querySelector('input[name="asset_code"]');
                const nameInput = this.querySelector('input[name="name"]');
                // Check asset code (only for add)
                if (codeInput && codeInput.required && !codeInput.value.trim()) {
                    e.preventDefault();
                    showErrorMessage('กรุณากรอกเลขครุภัณฑ์');
                    codeInput.focus();
                    return false;
                }
                // Check name
                if (nameInput && !nameInput.value.trim()) {
                    e.preventDefault();
                    showErrorMessage('กรุณากรอกชื่อรายการ');
                    nameInput.focus();
                    return false;
                }
                if (nameInput && nameInput.value.trim().length < 2) {
                    e.preventDefault();
                    showErrorMessage('ชื่อรายการต้องมีอย่างน้อย 2 ตัวอักษร');
                    nameInput.focus();
                    return false;
                }
                if (nameInput && nameInput.value.trim().length > 100) {
                    e.preventDefault();
                    showErrorMessage('ชื่อรายการต้องไม่เกิน 100 ตัวอักษร');
                    nameInput.focus();
                    return false;
                }
                showFormLoadingState(this);
            });
        }
    });
}

// Show error message in modal
function showErrorMessage(message) {
    // Remove all existing error messages
    const existingErrors = document.querySelectorAll('.error-message');
    existingErrors.forEach(error => error.remove());
    // Create error message element
    const errorDiv = document.createElement('div');
    errorDiv.className = 'error-message alert alert-danger';
    errorDiv.style.cssText = `
        margin-top: 1rem;
        padding: 0.75rem 1rem;
        border-radius: 8px;
        background: linear-gradient(135deg, #fed7d7, #feb2b2);
        border: 1px solid #f56565;
        color: #c53030;
        font-weight: 600;
    `;
    errorDiv.innerHTML = `
        <i class="fas fa-exclamation-triangle me-2"></i>
        ${message}
    `;
    // Add to modal body
    const modalBody = document.querySelector('.modal.show .modal-body') || document.querySelector('.modal .modal-body');
    if (modalBody) {
        modalBody.appendChild(errorDiv);
        setTimeout(() => {
            if (errorDiv.parentNode) {
                errorDiv.remove();
            }
        }, 5000);
    }
}

// Show form loading state
function showFormLoadingState(form) {
    const submitBtn = form.querySelector('button[type="submit"]');
    if (submitBtn) {
        const originalText = submitBtn.innerHTML;
        submitBtn.innerHTML = `
            <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
            กำลังบันทึก...
        `;
        submitBtn.disabled = true;
        setTimeout(() => {
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        }, 10000);
    }
}

// Setup keyboard shortcuts
function setupKeyboardShortcuts() {
    document.addEventListener('keydown', function(e) {
        // Alt + N: Open add modal
        if (e.altKey && e.key === 'n') {
            e.preventDefault();
            const addButton = document.querySelector('[data-bs-target="#addModal"]');
            if (addButton) {
                addButton.click();
            }
        }
        // Escape: Close modal
        if (e.key === 'Escape') {
            const modal = document.querySelector('.modal.show');
            if (modal) {
                const bsModal = bootstrap.Modal.getInstance(modal);
                if (bsModal) {
                    bsModal.hide();
                }
            }
        }
    });
}

// Utility function to show success message
function showSuccessMessage(message) {
    const successDiv = document.createElement('div');
    successDiv.className = 'alert alert-success position-fixed';
    successDiv.style.cssText = `
        top: 20px;
        right: 20px;
        z-index: 9999;
        min-width: 300px;
        border-radius: 8px;
        background: linear-gradient(135deg, #c6f6d5, #9ae6b4);
        border: 1px solid #48bb78;
        color: #22543d;
        font-weight: 600;
        box-shadow: 0 4px 15px rgba(72, 187, 120, 0.3);
    `;
    successDiv.innerHTML = `
        <i class="fas fa-check-circle me-2"></i>
        ${message}
        <button type="button" class="btn-close float-end" aria-label="Close"></button>
    `;
    document.body.appendChild(successDiv);
    setTimeout(() => {
        if (successDiv.parentNode) {
            successDiv.remove();
        }
    }, 3000);
    const closeBtn = successDiv.querySelector('.btn-close');
    if (closeBtn) {
        closeBtn.addEventListener('click', () => {
            successDiv.remove();
        });
    }
}

// Main initialization function
function initializePage() {
    animateTableRows();
    setupModalEvents();
    setupFormValidation();
    setupKeyboardShortcuts();
}

// Initialize page when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    initializePage();
});

// Search functionality (can be added later)
function initializeSearch() {
    // Placeholder for future enhancement
}

// Export functions for external use (if needed)
if (typeof module !== 'undefined' && module.exports && typeof window === 'undefined') {
    module.exports = {
        showSuccessMessage,
        showErrorMessage
    };
}

// Remove duplicated edit-btn and asset-image-link event listeners
document.querySelectorAll('.edit-btn').forEach(button => {
    button.addEventListener('click', function () {
        const assetId = this.getAttribute('data-id');
        fetch(`list.php?fetch_asset=1&id=${assetId}`)
            .then(response => response.json())
            .then(data => {
                document.getElementById('edit_asset_id').value = data.asset_id;
                document.getElementById('edit_asset_code').value = data.asset_code;
                document.getElementById('edit_name').value = data.name;
                document.getElementById('edit_description').value = data.description;
                document.getElementById('edit_category_id').value = data.category_id;
                document.getElementById('edit_location_id').value = data.location_id;
                document.getElementById('edit_department_id').value = data.department_id;
                document.getElementById('edit_status').value = data.status;
                document.getElementById('edit_purchase_date').value = data.purchase_date;
                document.getElementById('edit_warranty_expiry').value = data.warranty_expiry;
                document.getElementById('edit_price').value = data.price;
            });
    });
});

document.querySelectorAll('.asset-image-link').forEach(link => {
    link.addEventListener('click', function(e) {
        e.preventDefault();
        const imgSrc = this.getAttribute('data-img');
        document.getElementById('previewImage').src = imgSrc;
        var modal = new bootstrap.Modal(document.getElementById('imagePreviewModal'));
        modal.show();
        // แก้ไข: เมื่อปิด modal ให้ลบ src รูปออกด้วย เพื่อให้ปิดแล้วขยับได้
        document.getElementById('imagePreviewModal').addEventListener('hidden.bs.modal', function handler() {
            document.getElementById('previewImage').src = '';
            document.getElementById('imagePreviewModal').removeEventListener('hidden.bs.modal', handler);
        });
    });
});

// Attach delete confirmation to delete buttons
// เปลี่ยนจาก confirm เป็น Bootstrap Modal
let deleteAssetId = null;
document.querySelectorAll('.btn-delete').forEach(btn => {
    btn.addEventListener('click', function(e) {
        e.preventDefault();
        const row = btn.closest('tr');
        const assetName = row ? row.querySelector('td:nth-child(2)')?.textContent?.trim() : '';
        const assetId = btn.getAttribute('href')?.match(/id=(\d+)/)?.[1] || btn.dataset.id;
        deleteAssetId = assetId;
        document.getElementById('deleteAssetName').textContent = assetName;
        var modal = new bootstrap.Modal(document.getElementById('deleteAssetModal'));
        modal.show();
    });
});
if (document.getElementById('confirmDeleteAssetBtn')) {
    document.getElementById('confirmDeleteAssetBtn').onclick = function() {
        if (deleteAssetId) {
            window.location.href = '../delete.php?id=' + deleteAssetId;
        }
    };
}
// ฟังก์ชัน filter สถานที่ตามแผนก
const departmentSelects = document.querySelectorAll('select[name="department_id"], #edit_department_id');
const locationSelects = document.querySelectorAll('select[name="location_id"], #edit_location_id');

departmentSelects.forEach(deptSel => {
    deptSel.addEventListener('change', function() {
        locationSelects.forEach(locSel => filterLocationsByDepartment(this.value, locSel));
    });
});
window.addEventListener('DOMContentLoaded', function() {
    departmentSelects.forEach((deptSel, i) => {
        locationSelects.forEach(locSel => filterLocationsByDepartment(deptSel.value, locSel));
    });
});