<?php
require_once __DIR__ . '/../src/Core/Database.php';
require_once __DIR__ . '/../src/Core/I18n.php';
I18n::init();

$order_no = $_GET['order'] ?? '';
if (!$order_no) {
    die(__('front.stripe.error.invalid_order'));
}

$db = Database::getInstance();
$order = $db->fetch("SELECT * FROM orders WHERE order_no = ?", [$order_no]);

if (!$order) {
    die(__('front.stripe.error.order_not_found'));
}

// Site settings
$settings = $db->fetchAll("SELECT * FROM system_settings");
$cfg = [];
foreach ($settings as $s) { $cfg[$s['key_name']] = $s['value']; }
$site_name = $cfg['site_name'] ?? 'UAPI';
$stripe_pk = $cfg['stripe_public_key'] ?? '';

if (empty($stripe_pk)) {
    die(__('front.stripe.error.config'));
}

$current_lang = I18n::getLang();
$lang_zh_url = '?' . http_build_query(array_merge($_GET, ['lang' => 'zh-cn']));
$lang_en_url = '?' . http_build_query(array_merge($_GET, ['lang' => 'en']));
?>
<!DOCTYPE html>
<html lang="<?php echo $current_lang === 'en' ? 'en' : 'zh-CN'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo __('front.stripe.title'); ?> - <?php echo htmlspecialchars($site_name); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/lang-switch.css">
    <script src="https://js.stripe.com/v3/"></script>
    <style>
        body { background: #f8f9fa; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
        .payment-card { width: 100%; max-width: 500px; background: white; padding: 40px; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); }
        .StripeElement { box-sizing: border-box; height: 40px; padding: 10px 12px; border: 1px solid transparent; border-radius: 4px; background-color: white; box-shadow: 0 1px 3px 0 #e6ebf1; -webkit-transition: box-shadow 150ms ease; transition: box-shadow 150ms ease; }
        .StripeElement--focus { box-shadow: 0 1px 3px 0 #cfd7df; }
        .StripeElement--invalid { border-color: #fa755a; }
        .StripeElement--webkit-autofill { background-color: #fefde5 !important; }
    </style>
</head>
<body>

<div class="payment-card">
    <div class="d-flex justify-content-end mb-3">
        <div class="lang-switch" role="group" aria-label="<?php echo __('merchant.topbar.language'); ?>">
            <a class="<?php echo $current_lang === 'zh-cn' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($lang_zh_url); ?>">中</a>
            <a class="<?php echo $current_lang === 'en' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($lang_en_url); ?>">EN</a>
        </div>
    </div>
    <div class="text-center mb-4">
        <h3 class="fw-bold"><?php echo __('front.stripe.card_payment'); ?></h3>
        <p class="text-muted"><?php echo __('front.stripe.order_no'); ?>: <?php echo $order_no; ?></p>
        <h1 class="text-primary my-3">$<?php echo number_format($order['amount'], 2); ?></h1>
    </div>

    <form id="payment-form">
        <div class="mb-3">
            <label class="form-label"><?php echo __('front.stripe.cardholder_name'); ?></label>
            <input type="text" class="form-control" id="cardholder-name" required>
        </div>
        <div class="mb-4">
            <label class="form-label"><?php echo __('front.stripe.card_info'); ?></label>
            <div id="card-element" class="form-control p-3"></div>
            <div id="card-errors" class="text-danger small mt-2" role="alert"></div>
        </div>
        
        <button id="submit" class="btn btn-primary w-100 btn-lg">
            <span id="button-text"><?php echo __('front.stripe.pay_now'); ?></span>
            <div class="spinner-border spinner-border-sm ms-2" id="spinner" role="status" style="display:none;"></div>
        </button>
    </form>
    
    <div class="text-center mt-3">
        <a href="upgrade.php" class="text-decoration-none text-muted small"><?php echo __('front.stripe.back'); ?></a>
    </div>
</div>

<?php include __DIR__ . '/includes/stripe_loading_ui.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const stripe = Stripe('<?php echo $stripe_pk; ?>');
    const elements = stripe.elements();
    
    const style = {
        base: {
            color: "#32325d",
            fontFamily: '"Helvetica Neue", Helvetica, sans-serif',
            fontSmoothing: "antialiased",
            fontSize: "16px",
            "::placeholder": {
                color: "#aab7c4"
            }
        },
        invalid: {
            color: "#fa755a",
            iconColor: "#fa755a"
        }
    };
    
    const card = elements.create("card", { style: style });
    card.mount("#card-element");
    
    card.on('change', ({error}) => {
        const displayError = document.getElementById('card-errors');
        if (error) {
            displayError.textContent = error.message;
        } else {
            displayError.textContent = '';
        }
    });
    
    const form = document.getElementById('payment-form');
    
    form.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        setLoading(true);
        
        // In a real implementation, we would create a PaymentIntent on the server first
        // For this demo/MVP, we'll simulate the flow or implement client-only tokenization (legacy)
        // Better approach: Call backend to create PaymentIntent
        
        try {
            const { paymentMethod, error } = await stripe.createPaymentMethod({
                type: 'card',
                card: card,
                billing_details: {
                    name: document.getElementById('cardholder-name').value
                }
            });
            
            if (error) {
                showError(error.message);
                setLoading(false);
            } else {
                // Send paymentMethod.id to server
                processPayment(paymentMethod.id);
            }
        } catch (err) {
            showError(<?php echo json_encode(__('front.stripe.error.system')); ?>);
            setLoading(false);
        }
    });
    
    async function processPayment(paymentMethodId) {
        try {
            const response = await fetch('/api/v1/stripe/process.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ 
                    payment_method_id: paymentMethodId,
                    order_no: '<?php echo $order_no; ?>'
                })
            });
            
            const result = await response.json();
            
            if (result.status === 'success') {
                alert(<?php echo json_encode(__('front.stripe.success')); ?>);
                window.location.href = 'dashboard.php';
            } else {
                showError(result.message || <?php echo json_encode(__('front.stripe.error.failed')); ?>);
                setLoading(false);
            }
        } catch (err) {
            showError(<?php echo json_encode(__('front.stripe.error.network')); ?>);
            setLoading(false);
        }
    }
    
    function setLoading(isLoading) {
        if (isLoading) {
            document.querySelector("#submit").disabled = true;
            document.querySelector("#spinner").style.display = "inline-block";
            document.querySelector("#button-text").style.display = "none";
            if (typeof showStripeLoading === 'function') showStripeLoading();
        } else {
            document.querySelector("#submit").disabled = false;
            document.querySelector("#spinner").style.display = "none";
            document.querySelector("#button-text").style.display = "inline-block";
            if (typeof hideStripeLoading === 'function') hideStripeLoading();
        }
    }
    
    function showError(errorMsgText) {
        const errorMsg = document.querySelector("#card-errors");
        errorMsg.textContent = errorMsgText;
        setTimeout(() => { errorMsg.textContent = ""; }, 4000);
    }
</script>

</body>
</html>
