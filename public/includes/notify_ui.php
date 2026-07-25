<?php
// Shared notification UI for merchant/admin panels.
?>
<style>
    .uapi-toast-stack {
        position: fixed;
        top: 20px;
        right: 20px;
        z-index: 2200;
        display: flex;
        flex-direction: column;
        gap: 10px;
        width: min(360px, calc(100vw - 24px));
        pointer-events: none;
    }
    .uapi-toast {
        pointer-events: auto;
        border-radius: 12px;
        border: 1px solid var(--border-color, #e5e7eb);
        background: #ffffff;
        color: #111827;
        box-shadow: 0 10px 28px rgba(15, 23, 42, 0.12);
        padding: 12px 14px;
        display: flex;
        align-items: flex-start;
        gap: 10px;
        opacity: 0;
        transform: translateY(-8px) scale(0.98);
        transition: opacity .2s ease, transform .2s ease;
    }
    .uapi-toast.show { opacity: 1; transform: translateY(0) scale(1); }
    .uapi-toast.hide { opacity: 0; transform: translateY(-6px) scale(0.98); }
    .uapi-toast .icon { width: 20px; text-align: center; margin-top: 1px; flex-shrink: 0; }
    .uapi-toast .content { font-size: 13px; line-height: 1.45; flex: 1; word-break: break-word; }
    .uapi-toast .close {
        border: 0;
        background: transparent;
        color: #94a3b8;
        padding: 0;
        width: 18px;
        height: 18px;
        line-height: 18px;
        font-size: 14px;
        cursor: pointer;
    }
    .uapi-toast .close:hover { color: #334155; }
    .uapi-toast.success { border-color: #b7ebd4; }
    .uapi-toast.success .icon { color: #059669; }
    .uapi-toast.danger { border-color: #fecaca; }
    .uapi-toast.danger .icon { color: #dc2626; }
    .uapi-toast.warning { border-color: #fde68a; }
    .uapi-toast.warning .icon { color: #d97706; }
    .uapi-toast.info { border-color: #bfdbfe; }
    .uapi-toast.info .icon { color: #2563eb; }
    @media (max-width: 768px) {
        .uapi-toast-stack {
            top: 12px;
            right: 12px;
            left: 12px;
            width: auto;
        }
    }
</style>
<script>
    (function () {
        if (window.UapiNotify) return;

        const ICON_BY_TYPE = {
            success: 'fa-circle-check',
            danger: 'fa-circle-xmark',
            warning: 'fa-triangle-exclamation',
            info: 'fa-circle-info'
        };
        const TIMEOUT_BY_TYPE = {
            success: 3200,
            info: 3600,
            warning: 4200,
            danger: 5200
        };

        function ensureStack() {
            let stack = document.querySelector('.uapi-toast-stack');
            if (!stack) {
                stack = document.createElement('div');
                stack.className = 'uapi-toast-stack';
                document.body.appendChild(stack);
            }
            return stack;
        }

        function show(type, message, timeoutMs) {
            const msg = (message || '').toString().trim();
            if (!msg) return;
            const level = ['success', 'danger', 'warning', 'info'].includes(type) ? type : 'info';
            const stack = ensureStack();

            const toast = document.createElement('div');
            toast.className = 'uapi-toast ' + level;
            toast.innerHTML =
                '<div class="icon"><i class="fa-solid ' + (ICON_BY_TYPE[level] || ICON_BY_TYPE.info) + '"></i></div>' +
                '<div class="content"></div>' +
                '<button type="button" class="close" aria-label="Close">&times;</button>';
            toast.querySelector('.content').textContent = msg;

            const closeToast = function () {
                toast.classList.remove('show');
                toast.classList.add('hide');
                setTimeout(function () { toast.remove(); }, 220);
            };
            toast.querySelector('.close').addEventListener('click', closeToast);

            stack.appendChild(toast);
            requestAnimationFrame(function () { toast.classList.add('show'); });
            setTimeout(closeToast, Number.isFinite(timeoutMs) ? timeoutMs : (TIMEOUT_BY_TYPE[level] || 3600));
        }

        function alertToType(alertEl) {
            if (alertEl.classList.contains('alert-success')) return 'success';
            if (alertEl.classList.contains('alert-danger')) return 'danger';
            if (alertEl.classList.contains('alert-warning')) return 'warning';
            return 'info';
        }

        function fromAlerts(selector) {
            const defaultSelector =
                '.main-content > .container-fluid > .alert, .main-content > .container > .alert, .main-content > .alert, .main-content .alert.alert-dismissible';
            const candidates = document.querySelectorAll(selector || defaultSelector);
            if (!candidates.length) return;

            candidates.forEach(function (alertEl) {
                if (alertEl.classList.contains('alert-permanent') || alertEl.dataset.static === '1') return;
                if (alertEl.querySelector('form, table, input, textarea, select')) return;
                if (alertEl.querySelector('.alert-link')) return;

                const msg = alertEl.textContent ? alertEl.textContent.trim() : '';
                if (!msg) {
                    alertEl.remove();
                    return;
                }
                show(alertToType(alertEl), msg);
                alertEl.remove();
            });
        }

        window.UapiNotify = { show: show, fromAlerts: fromAlerts };
        document.addEventListener('DOMContentLoaded', function () { fromAlerts(); });
    })();
</script>
