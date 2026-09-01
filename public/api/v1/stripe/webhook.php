<?php
/**
 * Stripe Webhook Endpoint
 *
 * Fill this URL in your Stripe Dashboard → Developers → Webhooks:
 *   https://yourdomain.com/api/v1/stripe/webhook.php
 *
 * Required event to subscribe:
 *   - checkout.session.completed
 *
 * Required setting in admin panel (system_settings):
 *   key_name: stripe_webhook_secret   (starts with whsec_...)
 *   key_name: stripe_secret_key       (starts with sk_...)
 *
 * SECURITY: the signing secret is the only thing that proves a request really
 * came from Stripe. When stripe_webhook_secret is missing or malformed this
 * endpoint refuses the request with 503 and processes nothing — anyone on the
 * internet could otherwise POST a forged "checkout.session.completed" event
 * carrying a pending order number and have that order marked paid, crediting
 * a balance or upgrading a plan for free.
 */

// No session needed – this endpoint is called by Stripe, not a browser.
@set_time_limit(30);

require_once __DIR__ . '/../../../../src/Core/Database.php';
require_once __DIR__ . '/../../../../src/Services/UpgradeOrderService.php';
require_once __DIR__ . '/../../../../src/Services/NotificationDispatcher.php';
require_once __DIR__ . '/../../../../src/Services/ReferralService.php';
require_once __DIR__ . '/../../../../src/Services/CouponService.php';

header('Content-Type: application/json');

$rawBody = (string)file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

// ── Helper ──────────────────────────────────────────────────────────────────

function stripe_webhook_respond(int $code, string $msg): void
{
    http_response_code($code);
    echo json_encode(['status' => $code === 200 ? 'ok' : 'error', 'message' => $msg]);
    exit;
}

function stripe_webhook_log(Database $db, string $eventId, string $eventType, string $orderNo, string $status, string $detail, string $rawBody): void
{
    try {
        $db->query(
            "CREATE TABLE IF NOT EXISTS stripe_webhook_logs (
                id          INT AUTO_INCREMENT PRIMARY KEY,
                event_id    VARCHAR(100) DEFAULT '',
                event_type  VARCHAR(80)  DEFAULT '',
                order_no    VARCHAR(64)  DEFAULT '',
                status      VARCHAR(20)  DEFAULT 'received',
                detail      TEXT,
                ip          VARCHAR(64)  DEFAULT '',
                created_at  DATETIME     DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_event_id (event_id),
                INDEX idx_order_no (order_no),
                INDEX idx_created  (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $db->query(
            "INSERT INTO stripe_webhook_logs (event_id, event_type, order_no, status, detail, ip) VALUES (?,?,?,?,?,?)",
            [
                $eventId,
                $eventType,
                $orderNo,
                $status,
                $detail,
                $_SERVER['REMOTE_ADDR'] ?? '',
            ]
        );
    } catch (Throwable $e) {
        error_log('[Stripe Webhook] Log write failed: ' . $e->getMessage());
    }
}

/**
 * Maximum accepted age (in seconds) of the timestamp inside Stripe-Signature.
 * Mirrors Stripe's own default tolerance and bounds replay of captured events.
 */
const STRIPE_WEBHOOK_TOLERANCE = 300;

function stripe_webhook_verify_signature(string $payload, string $sigHeader, string $secret): bool
{
    // An empty secret must never validate anything (defence in depth: the
    // caller already rejects unconfigured secrets before reaching this point).
    if ($secret === '') {
        return false;
    }

    // Stripe signature format: t=timestamp,v1=hash[,v1=hash2...]
    $parts = [];
    foreach (explode(',', $sigHeader) as $part) {
        [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
        $parts[trim($k)][] = trim($v);
    }

    $timestampRaw = (string)($parts['t'][0] ?? '');
    $signatures = $parts['v1'] ?? [];

    if ($timestampRaw === '' || !ctype_digit($timestampRaw) || empty($signatures)) {
        return false;
    }
    $timestamp = (int)$timestampRaw;

    // Reject stale or future-dated events (replay protection).
    if (abs(time() - $timestamp) > STRIPE_WEBHOOK_TOLERANCE) {
        return false;
    }

    $signed = $timestampRaw . '.' . $payload;
    $expected = hash_hmac('sha256', $signed, $secret);

    $valid = false;
    foreach ($signatures as $sig) {
        // hash_equals is constant time and safe with attacker-controlled input;
        // do not short-circuit the loop, so timing does not leak which entry matched.
        if (hash_equals($expected, $sig)) {
            $valid = true;
        }
    }
    return $valid;
}

// ── Load settings ────────────────────────────────────────────────────────────

$db = Database::getInstance();

$settingsRows = $db->fetchAll("SELECT key_name, value FROM system_settings WHERE key_name IN ('stripe_webhook_secret', 'stripe_secret_key')");
$cfg = [];
foreach ($settingsRows as $row) {
    $cfg[$row['key_name']] = $row['value'];
}

$webhookSecret = trim((string)($cfg['stripe_webhook_secret'] ?? ''));

// ── Verify signature ─────────────────────────────────────────────────────────

// Fail closed: without a usable signing secret this endpoint cannot tell a
// genuine Stripe event from a forged one, so it must not process anything.
if ($webhookSecret === '' || strpos($webhookSecret, 'whsec_') !== 0) {
    error_log('[Stripe Webhook] REJECTED: stripe_webhook_secret is not configured or does not start with "whsec_". '
        . 'Set it in the admin panel (Settings → Payment) before pointing Stripe at this endpoint. '
        . 'Refusing the request instead of processing an unverified event.');
    stripe_webhook_respond(503, 'Stripe webhook secret is not configured');
}

if ($sigHeader === '') {
    stripe_webhook_respond(400, 'Missing Stripe-Signature header');
}

if (!stripe_webhook_verify_signature($rawBody, $sigHeader, $webhookSecret)) {
    error_log('[Stripe Webhook] REJECTED: Stripe-Signature verification failed (bad signature, or timestamp outside the '
        . STRIPE_WEBHOOK_TOLERANCE . 's tolerance).');
    stripe_webhook_respond(400, 'Signature verification failed');
}

// ── Parse event ──────────────────────────────────────────────────────────────

$event = json_decode($rawBody, true);
if (!is_array($event) || empty($event['type'])) {
    stripe_webhook_respond(400, 'Invalid event payload');
}

$eventType = (string)$event['type'];
$eventId   = (string)($event['id'] ?? '');

// Log the event for debugging
error_log("[Stripe Webhook] Received event: {$eventType} | id: {$eventId}");

// ── Only handle checkout.session.completed ───────────────────────────────────

// Log all received events
stripe_webhook_log($db, $eventId, $eventType, '', 'received', 'Event received from Stripe', $rawBody);

if ($eventType !== 'checkout.session.completed') {
    stripe_webhook_log($db, $eventId, $eventType, '', 'ignored', 'Event type not handled', '');
    stripe_webhook_respond(200, 'Event type ignored: ' . $eventType);
}

$session = $event['data']['object'] ?? [];
$sessionId     = (string)($session['id']                 ?? '');
$paymentStatus = strtolower((string)($session['payment_status'] ?? ''));
$sessionStatus = strtolower((string)($session['status']         ?? ''));
$orderNo       = (string)($session['client_reference_id']       ?? '');
$paymentIntent = (string)($session['payment_intent']            ?? $sessionId);

// Must be a completed, paid session
if ($paymentStatus !== 'paid' || $sessionStatus !== 'complete') {
    stripe_webhook_respond(200, 'Session not complete/paid – skipping');
}

if ($orderNo === '') {
    stripe_webhook_respond(200, 'No client_reference_id in session – skipping');
}

// ── Fetch order ───────────────────────────────────────────────────────────────

$order = $db->fetch("SELECT * FROM orders WHERE order_no = ? LIMIT 1", [$orderNo]);
if (!$order) {
    // Could be a race condition or unknown order; return 200 so Stripe stops retrying
    error_log("[Stripe Webhook] Order not found: {$orderNo}");
    stripe_webhook_respond(200, 'Order not found – acknowledged');
}

$orderId  = (int)$order['id'];
$userId   = (int)$order['user_id'];
$source   = strtolower(trim((string)($order['source'] ?? '')));
$merchantOrderId = (string)($order['merchant_order_id'] ?? '');

// If already paid, nothing to do (idempotent)
if (strtolower((string)($order['status'] ?? '')) === 'paid') {
    stripe_webhook_log($db, $eventId, $eventType, $orderNo, 'duplicate', 'Order already paid, skipped', '');
    stripe_webhook_respond(200, 'Order already paid – idempotent ack');
}

// ── Mark order as paid ────────────────────────────────────────────────────────

$updated = $db->query(
    "UPDATE orders
     SET status='paid', pay_provider='stripe', chain='stripe', currency='USD',
         tx_hash=?, paid_at=NOW(), updated_at=NOW()
     WHERE id=? AND status='pending'",
    [$paymentIntent, $orderId]
);
$rowsChanged = $updated->rowCount();

// Coupon usage
if ($rowsChanged > 0) {
    CouponService::countAdminRedemption($db, $order);

    // Referral reward
    try {
        ReferralService::grantForOrder($db, $orderId);
    } catch (Throwable $ignore) {}
}

// ── Balance recharge fulfillment ──────────────────────────────────────────────

if ($source === 'recharge' && $rowsChanged > 0) {
    $amount = (float)($order['amount'] ?? 0);
    $rechargeCredited = false;
    if ($amount > 0) {
        $db->query("START TRANSACTION");
        try {
            $desc = 'Stripe 余额充值 #' . $orderNo;
            $existsTx = $db->fetch(
                "SELECT id FROM transactions WHERE user_id = ? AND type = 'recharge' AND description = ? LIMIT 1 FOR UPDATE",
                [$userId, $desc]
            );
            if (!$existsTx) {
                $db->query(
                    "UPDATE users SET balance = balance + ? WHERE id = ?",
                    [$amount, $userId]
                );
                $u = $db->fetch("SELECT balance FROM users WHERE id = ? LIMIT 1", [$userId]);
                $balanceAfter = isset($u['balance']) ? (float)$u['balance'] : $amount;

                $db->query(
                    "INSERT INTO transactions (user_id, type, amount, balance_after, description, status)
                     VALUES (?, 'recharge', ?, ?, ?, 'completed')",
                    [$userId, $amount, $balanceAfter, $desc]
                );
                $rechargeCredited = true;
            }
            $db->query("COMMIT");
        } catch (Throwable $e) {
            $db->query("ROLLBACK");
            error_log("[Stripe Webhook] Recharge fulfillment error for order {$orderNo}: " . $e->getMessage());
        }
        if ($rechargeCredited) {
            try {
                NotificationDispatcher::notifyUser($userId, [
                    'type'         => 'balance',
                    'in_app_type'  => 'balance',
                    'title'        => '余额充值成功',
                    'content'      => "Stripe 充值已到账。\n订单号：{$orderNo}\n充值金额：{$amount} USD\n时间：" . date('Y-m-d H:i:s'),
                    'subject'      => '余额充值成功',
                    'dedupe_like'  => (string)$orderNo,
                ]);
            } catch (Throwable $ignore) {
            }
        }
    }

    stripe_webhook_log($db, $eventId, $eventType, $orderNo, 'success', '余额充值处理完成', '');
stripe_webhook_respond(200, 'Recharge processed');
}

// ── Plan upgrade fulfillment ──────────────────────────────────────────────────

if (strpos($merchantOrderId, 'PLAN-') === 0) {
    try {
        require_once __DIR__ . '/../../../../src/Services/UpgradeOrderService.php';
        UpgradeOrderService::fulfillPlanFromOrder($db, $order);

        $uRow = $db->fetch("SELECT expire_at FROM users WHERE id = ? LIMIT 1", [$userId]);
        $newExpire = $uRow['expire_at'] ?? '';
        NotificationDispatcher::notifyUser($userId, [
            'type'        => 'upgrade',
            'in_app_type' => 'upgrade',
            'title'       => '套餐升级成功',
            'content'     => "您的套餐已成功升级。\n订单号：{$orderNo}\n到期时间：{$newExpire}",
            'subject'     => '套餐升级成功',
            'dedupe_like' => (string)$orderNo,
        ]);
    } catch (Throwable $e) {
        error_log("[Stripe Webhook] Plan upgrade error for order {$orderNo}: " . $e->getMessage());
    }
}

stripe_webhook_log($db, $eventId, $eventType, $orderNo, 'success', '套餐升级/订单处理完成', '');
stripe_webhook_respond(200, 'Webhook processed successfully');
