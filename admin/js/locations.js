/**
 * Locations Management JavaScript
 * Handles interactions and animations for the locations management page
 */

// Confirm delete location function
function confirmDelete(locationId, locationName) {
    const confirmMessage = `คุณต้องการลบสถานที่ "${locationName}" หรือไม่?\n\nการดำเนินการนี้ไม่สามารถยกเลิกได้`;
    
    if (confirm(confirmMessage)) {
        // Show loading state (optional)
        showLoadingState();
        
        // Redirect to delete URL
        window.location.href = `locations.php?delete=${locationId}`;
    }
}

// Show loading state when deleting
function showLoadingState() {
    // Create loading overlay
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
    
    // Add spin animation
    const style = document.createElement('style');
    style.textContent = `
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    `;
    document.head.appendChild(style);
    document.body.appendChild(loadingOverlay);
}

// Initialize page when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    initializePage();
});

// Main initialization function
function initializePage() {
    // Add fade-in animation to table rows
    animateTableRows();
    
    // Setup modal event listeners
    setupModalEvents();
    
    // Setup form validation
    setupFormValidation();
    
    // Setup keyboard shortcuts
    setupKeyboardShortcuts();
    
    // Attach delete confirmation to delete buttons/links
    document.querySelectorAll('.btn-delete, .btn-danger').forEach(btn => {
        btn.addEventListener('click', function(e) {
            const row = btn.closest('tr');
            const locationName = row ? row.querySelector('td:nth-child(2)')?.textContent?.trim() : '';
            const locationId = btn.getAttribute('href')?.match(/delete=(\d+)/)?.[1] || btn.dataset.id;
            if (!confirmDelete(locationId, locationName)) {
                e.preventDefault();
            }
        });
    });
}

// Animate table rows on page load
function animateTableRows() {
    const rows = document.querySelectorAll('tbody tr');
    rows.forEach((row, index) => {
        row.style.animationDelay = `${index * 0.1}s`;
        row.classList.add('fade-in');
    });
}

// Setup modal event listeners
function setupModalEvents() {
    const addModal = document.getElementById('addModal');
    if (addModal) {
        // Auto focus on input when modal opens
        addModal.addEventListener('shown.bs.modal', function () {
            const nameInput = this.querySelector('input[name="name"]');
            if (nameInput) {
                nameInput.focus();
                nameInput.select();
            }
        });
        
        // Clear form when modal is hidden
        addModal.addEventListener('hidden.bs.modal', function () {
            const form = this.querySelector('form');
            if (form) {
                form.reset();
            }
        });
    }
}

// Setup form validation
function setupFormValidation() {
    const form = document.querySelector('#addModal form');
    if (form) {
        form.addEventListener('submit', function(e) {
            const nameInput = this.querySelector('input[name="name"]');
            if (nameInput) {
                const value = nameInput.value.trim();
                
                // Check if empty
                if (!value) {
                    e.preventDefault();
                    showErrorMessage('กรุณากรอกชื่อสถานที่');
                    nameInput.focus();
                    return false;
                }
                
                // Check minimum length
                if (value.length < 2) {
                    e.preventDefault();
                    showErrorMessage('ชื่อสถานที่ต้องมีอย่างน้อย 2 ตัวอักษร');
                    nameInput.focus();
                    return false;
                }
                
                // Check maximum length
                if (value.length > 100) {
                    e.preventDefault();
                    showErrorMessage('ชื่อสถานที่ต้องไม่เกิน 100 ตัวอักษร');
                    nameInput.focus();
                    return false;
                }
                
                // Show loading state
                showFormLoadingState(this);
            }
        });
    }
}

// Show error message
function showErrorMessage(message) {
    // Remove existing error messages
    const existingError = document.querySelector('.error-message');
    if (existingError) {
        existingError.remove();
    }
    
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
    const modalBody = document.querySelector('#addModal .modal-body');
    if (modalBody) {
        modalBody.appendChild(errorDiv);
        
        // Remove after 5 seconds
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
        
        // Restore button after timeout (in case of error)
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
    
    // Auto remove after 3 seconds
    setTimeout(() => {
        if (successDiv.parentNode) {
            successDiv.remove();
        }
    }, 3000);
    
    // Manual close button
    const closeBtn = successDiv.querySelector('.btn-close');
    if (closeBtn) {
        closeBtn.addEventListener('click', () => {
            successDiv.remove();
        });
    }
}

// Search functionality (can be added later)
function initializeSearch() {
    // This function can be implemented for search functionality
    // Currently placeholder for future enhancement
}

// Export functions for external use (if needed)
if (typeof module !== 'undefined' && module.exports) {
    module.exports = {
        confirmDelete,
        showSuccessMessage,
        showErrorMessage
    };
}