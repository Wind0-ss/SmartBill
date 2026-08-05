/**
 * AutoBill — Shared JavaScript (app.js)
 * Toast notifications, Dark mode, AJAX helpers, Form validation
 */

// ============================================================
// Toast Notification System
// ============================================================
const Toast = {
    container: null,

    init() {
        if (!this.container) {
            this.container = document.createElement('div');
            this.container.className = 'toast-container';
            document.body.appendChild(this.container);
        }
    },

    show(message, type = 'success', duration = 4000) {
        this.init();
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;

        const icons = {
            success: 'check_circle',
            error: 'error',
            warning: 'warning',
            info: 'info'
        };

        toast.innerHTML = `
            <span class="material-symbols-outlined" style="font-size:20px">${icons[type] || 'info'}</span>
            <span>${message}</span>
            <button onclick="this.parentElement.remove()" style="margin-left:auto;background:none;border:none;cursor:pointer;opacity:0.6;font-size:18px">&times;</button>
        `;

        this.container.appendChild(toast);

        setTimeout(() => {
            toast.style.animation = 'fadeOut 0.3s ease forwards';
            setTimeout(() => toast.remove(), 300);
        }, duration);
    },

    success(message) { this.show(message, 'success'); },
    error(message) { this.show(message, 'error'); },
    warning(message) { this.show(message, 'warning'); },
    info(message) { this.show(message, 'info'); }
};

// ============================================================
// AJAX Helper
// ============================================================
const API = {
    baseUrl: window.__APP_URL__ || '',

    async request(url, options = {}) {
        const defaults = {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        };

        if (options.body && !(options.body instanceof FormData)) {
            defaults.headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify(options.body);
        }

        try {
            const mergedHeaders = { ...defaults.headers, ...(options.headers || {}) };
            const finalOptions = { ...defaults, ...options, headers: mergedHeaders };
            const response = await fetch(this.baseUrl + url, finalOptions);
            const data = await response.json();

            if (!response.ok) {
                throw new Error(data.message || data.error || 'Request failed');
            }

            return data;
        } catch (error) {
            Toast.error(error.message || 'เกิดข้อผิดพลาด กรุณาลองใหม่อีกครั้ง');
            throw error;
        }
    },

    get(url) {
        return this.request(url);
    },

    post(url, data) {
        return this.request(url, { method: 'POST', body: data });
    },

    put(url, data) {
        return this.request(url, { method: 'PUT', body: data });
    },

    delete(url) {
        return this.request(url, { method: 'DELETE' });
    },

    upload(url, formData) {
        return this.request(url, { method: 'POST', body: formData });
    }
};

// ============================================================
// Modal System
// ============================================================
const Modal = {
    open(id) {
        const modal = document.getElementById(id);
        if (modal) {
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }
    },

    close(id) {
        const modal = document.getElementById(id);
        if (modal) {
            modal.classList.remove('active');
            document.body.style.overflow = '';
        }
    },

    init() {
        // Close modal on overlay click
        document.querySelectorAll('.modal-overlay').forEach(overlay => {
            overlay.addEventListener('click', (e) => {
                if (e.target === overlay) {
                    overlay.classList.remove('active');
                    document.body.style.overflow = '';
                }
            });
        });

        // Close modal on Escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal-overlay.active').forEach(m => {
                    m.classList.remove('active');
                });
                document.body.style.overflow = '';
            }
        });
    }
};

// ============================================================
// Confirm Dialog
// ============================================================
function confirmAction(message, callback) {
    if (confirm(message)) {
        callback();
    }
}

// ============================================================
// Format Currency
// ============================================================
function formatCurrency(amount) {
    return '฿' + parseFloat(amount).toLocaleString('th-TH', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

// ============================================================
// Format Number with Commas
// ============================================================
function formatNumber(num, decimals = 2) {
    return parseFloat(num).toLocaleString('th-TH', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals
    });
}

// ============================================================
// Mobile Navigation Toggle
// ============================================================
function toggleMobileNav() {
    const nav = document.getElementById('mobileNav');
    const overlay = document.getElementById('mobileNavOverlay');
    if (nav && overlay) {
        nav.classList.toggle('active');
        overlay.classList.toggle('active');
    }
}

// ============================================================
// Button Loading State
// ============================================================
function setButtonLoading(btn, loading = true) {
    if (loading) {
        btn.dataset.originalText = btn.innerHTML;
        btn.innerHTML = '<span class="spinner"></span> กำลังดำเนินการ...';
        btn.disabled = true;
    } else {
        btn.innerHTML = btn.dataset.originalText || btn.innerHTML;
        btn.disabled = false;
    }
}

// ============================================================
// Form Validation
// ============================================================
function validateForm(form) {
    let isValid = true;
    const required = form.querySelectorAll('[required]');

    required.forEach(input => {
        removeError(input);
        if (!input.value.trim()) {
            showError(input, 'กรุณากรอกข้อมูล');
            isValid = false;
        }
    });

    return isValid;
}

function showError(input, message) {
    input.classList.add('form-input-error');
    const hint = document.createElement('div');
    hint.className = 'form-error-text';
    hint.textContent = message;
    input.parentNode.appendChild(hint);
}

function removeError(input) {
    input.classList.remove('form-input-error');
    const hint = input.parentNode.querySelector('.form-error-text');
    if (hint) hint.remove();
}

// ============================================================
// Copy to Clipboard
// ============================================================
async function copyToClipboard(text, btn = null) {
    try {
        await navigator.clipboard.writeText(text);
        Toast.success('คัดลอกแล้ว!');
        if (btn) {
            const original = btn.innerHTML;
            btn.innerHTML = '<span class="material-symbols-outlined" style="font-size:18px">check</span> Copied!';
            setTimeout(() => { btn.innerHTML = original; }, 2000);
        }
    } catch {
        Toast.error('ไม่สามารถคัดลอกได้');
    }
}

// ============================================================
// Debounce Helper
// ============================================================
function debounce(func, wait = 300) {
    let timeout;
    return function executedFunction(...args) {
        clearTimeout(timeout);
        timeout = setTimeout(() => func.apply(this, args), wait);
    };
}

// ============================================================
// Initialize on DOM Ready
// ============================================================
document.addEventListener('DOMContentLoaded', () => {
    Modal.init();

    // Show flash message if exists
    const flashEl = document.getElementById('flashMessage');
    if (flashEl) {
        const type = flashEl.dataset.type || 'success';
        const message = flashEl.dataset.message;
        if (message) Toast.show(message, type);
    }

    // Button micro-interactions
    document.querySelectorAll('.btn').forEach(btn => {
        btn.addEventListener('mousedown', () => btn.style.transform = 'scale(0.98)');
        btn.addEventListener('mouseup', () => btn.style.transform = '');
        btn.addEventListener('mouseleave', () => btn.style.transform = '');
    });
});
