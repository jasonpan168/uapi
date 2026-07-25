<?php
$uapiStripeLoadingModalId = $uapiStripeLoadingModalId ?? 'uapiStripeLoadingModal';
?>
<div class="modal fade" id="<?php echo htmlspecialchars($uapiStripeLoadingModalId); ?>" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-0 shadow" style="border-radius: 14px;">
            <div class="modal-body text-center py-4">
                <div class="spinner-border text-primary mb-3" role="status" aria-hidden="true"></div>
                <div class="fw-semibold"><?php echo __('merchant.balance.recharge_modal.processing'); ?></div>
                <div class="small text-muted mt-1"><?php echo __('merchant.balance.recharge_modal.processing_hint'); ?></div>
            </div>
        </div>
    </div>
</div>
<script>
(function () {
    const modalId = <?php echo json_encode((string)$uapiStripeLoadingModalId); ?>;
    if (window.__uapiStripeLoadingInitialized) return;
    window.__uapiStripeLoadingInitialized = true;

    let modalInstance = null;
    function getModalInstance() {
        const el = document.getElementById(modalId);
        if (!el) return null;
        if (!modalInstance) {
            modalInstance = new bootstrap.Modal(el);
        }
        return modalInstance;
    }

    window.showStripeLoading = function () {
        const instance = getModalInstance();
        if (instance) instance.show();
    };

    window.hideStripeLoading = function () {
        if (modalInstance) modalInstance.hide();
    };

    window.bindStripeLoadingOnForm = function (options) {
        const cfg = options || {};
        const form = document.querySelector(cfg.formSelector || '');
        if (!form) return;
        const submitBtn = cfg.submitButtonSelector ? document.querySelector(cfg.submitButtonSelector) : null;
        const processingText = cfg.processingText || '';
        const submitDelay = Number.isFinite(cfg.submitDelayMs) ? cfg.submitDelayMs : 80;
        const shouldShow = typeof cfg.shouldShow === 'function' ? cfg.shouldShow : function () { return true; };

        form.addEventListener('submit', function (e) {
            if (form.dataset.submitting === '1') {
                e.preventDefault();
                return;
            }
            form.dataset.submitting = '1';
            if (submitBtn) submitBtn.disabled = true;

            let showLoading = false;
            try { showLoading = !!shouldShow(form); } catch (err) { showLoading = false; }
            if (!showLoading) return;

            e.preventDefault();
            if (submitBtn && processingText) submitBtn.innerText = processingText;
            window.showStripeLoading();
            setTimeout(function () { form.submit(); }, submitDelay);
        });
    };
})();
</script>
