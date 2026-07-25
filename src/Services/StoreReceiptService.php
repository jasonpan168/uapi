<?php

require_once __DIR__ . '/../Core/Database.php';
require_once __DIR__ . '/EmailNotificationService.php';
require_once __DIR__ . '/CryptoService.php';

class StoreReceiptService
{
    public static function sendForOrder($orderId)
    {
        $db = Database::getInstance();
        $db->autoMigrate();
        $order = $db->fetch(
            "SELECT o.*, s.name AS store_name, s.slug AS store_slug, s.logo_url AS store_logo_url,
                    p.name AS product_name, u.email_notice_use_custom_smtp, u.smtp_host, u.smtp_port,
                    u.smtp_username, u.smtp_password, u.smtp_encryption, u.smtp_from_name, u.smtp_from_email
             FROM orders o
             LEFT JOIN stores s ON s.id = o.source_id
             LEFT JOIN store_products p ON p.id = o.product_id
             LEFT JOIN users u ON u.id = o.user_id
             WHERE o.id = ?
             LIMIT 1",
            [(int)$orderId]
        );

        if (!$order || ($order['source'] ?? '') !== 'store' || ($order['status'] ?? '') !== 'paid') {
            return false;
        }
        if (!empty($order['receipt_sent_at'])) {
            return true;
        }

        $to = trim((string)($order['customer_email'] ?? ''));
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $smtp = self::resolveSmtpConfig($db, $order);
        if (!$smtp) {
            return false;
        }

        $subject = 'Payment Receipt / 支付收据 #' . ($order['order_no'] ?? '');
        $html = self::buildReceiptHtml($order);
        $ok = EmailNotificationService::sendUsingConfig($smtp, $to, $subject, $html);
        if ($ok) {
            $db->query("UPDATE orders SET receipt_sent_at = NOW() WHERE id = ? AND receipt_sent_at IS NULL", [(int)$order['id']]);
        }
        return $ok;
    }

    private static function resolveSmtpConfig($db, $order)
    {
        if (!empty($order['email_notice_use_custom_smtp'])) {
            $custom = [
                'host' => trim((string)($order['smtp_host'] ?? '')),
                'port' => (int)($order['smtp_port'] ?? 587),
                'username' => trim((string)($order['smtp_username'] ?? '')),
                'password' => (string)($order['smtp_password'] ?? ''),
                'encryption' => trim((string)($order['smtp_encryption'] ?? 'tls')),
                'from_name' => trim((string)($order['smtp_from_name'] ?? ($order['store_name'] ?? 'UAPI'))),
                'from_email' => trim((string)($order['smtp_from_email'] ?? '')),
            ];
            if (self::isValidSmtp($custom)) {
                return $custom;
            }
        }

        $rows = $db->fetchAll(
            "SELECT key_name, value FROM system_settings
             WHERE key_name IN ('smtp_enabled','smtp_host','smtp_port','smtp_username','smtp_password','smtp_encryption','smtp_from_name','smtp_from_email')"
        );
        $cfg = [];
        foreach ($rows as $row) {
            $cfg[$row['key_name']] = $row['value'];
        }
        if (($cfg['smtp_enabled'] ?? '0') !== '1') {
            return null;
        }

        $platform = [
            'host' => trim((string)($cfg['smtp_host'] ?? '')),
            'port' => (int)($cfg['smtp_port'] ?? 587),
            'username' => trim((string)($cfg['smtp_username'] ?? '')),
            'password' => (string)($cfg['smtp_password'] ?? ''),
            'encryption' => trim((string)($cfg['smtp_encryption'] ?? 'tls')),
            'from_name' => trim((string)($cfg['smtp_from_name'] ?? ($order['store_name'] ?? 'UAPI'))),
            'from_email' => trim((string)($cfg['smtp_from_email'] ?? '')),
        ];
        return self::isValidSmtp($platform) ? $platform : null;
    }

    private static function isValidSmtp($smtp)
    {
        if (empty($smtp['host']) || empty($smtp['port']) || empty($smtp['from_email'])) {
            return false;
        }
        return (bool)filter_var($smtp['from_email'], FILTER_VALIDATE_EMAIL);
    }

    private static function buildReceiptHtml($order)
    {
        $storeName = htmlspecialchars((string)($order['store_name'] ?? 'UAPI'));
        $productName = htmlspecialchars((string)($order['product_name'] ?? 'Product'));
        $orderNo = htmlspecialchars((string)($order['order_no'] ?? ''));
        $paidAt = htmlspecialchars((string)($order['updated_at'] ?? $order['created_at'] ?? date('Y-m-d H:i:s')));
        $amount = number_format((float)($order['amount'] ?? 0), 6, '.', '');
        $chain = strtoupper((string)($order['chain'] ?? ''));
        $logo = trim((string)($order['store_logo_url'] ?? ''));
        $safeLogo = htmlspecialchars($logo);
        $txHash = trim((string)($order['tx_hash'] ?? ''));
        $txHtml = '-';
        if ($txHash !== '') {
            $explorer = CryptoService::getExplorerUrl($order['chain'] ?? '', $txHash);
            $safeHash = htmlspecialchars($txHash);
            if (!empty($explorer)) {
                $txHtml = '<a href="' . htmlspecialchars($explorer) . '" style="color:#2563eb;text-decoration:none;">' . $safeHash . '</a>';
            } else {
                $txHtml = $safeHash;
            }
        }

        $storeUrl = '';
        if (!empty($order['store_slug'])) {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? '';
            if ($host !== '') {
                $storeUrl = $scheme . '://' . $host . '/shop.php?store=' . rawurlencode((string)$order['store_slug']);
            }
        }
        $storeBtn = '';
        if ($storeUrl !== '') {
            $storeBtn = '<a href="' . htmlspecialchars($storeUrl) . '" style="display:inline-block;padding:10px 16px;background:#0f172a;color:#fff;text-decoration:none;border-radius:8px;font-weight:600;">Visit Store / 访问店铺</a>';
        }

        $logoBlock = '';
        if ($logo !== '') {
            $logoBlock = '<img src="' . $safeLogo . '" alt="logo" style="height:40px;max-width:180px;object-fit:contain;">';
        } else {
            $logoBlock = '<div style="font-size:18px;font-weight:700;color:#0f172a;">' . $storeName . '</div>';
        }

        return '<!doctype html>
<html><body style="margin:0;padding:0;background:#f3f4f6;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="padding:24px 0;">
    <tr>
      <td align="center">
        <table role="presentation" width="640" cellpadding="0" cellspacing="0" style="max-width:640px;background:#ffffff;border:1px solid #e5e7eb;border-radius:14px;overflow:hidden;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#0f172a;">
          <tr><td style="padding:24px 28px;border-bottom:1px solid #e5e7eb;">' . $logoBlock . '</td></tr>
          <tr><td style="padding:24px 28px 8px;">
            <div style="font-size:22px;font-weight:800;">Payment Receipt</div>
            <div style="font-size:14px;color:#64748b;margin-top:6px;">支付收据</div>
          </td></tr>
          <tr><td style="padding:8px 28px 18px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;line-height:1.7;color:#334155;">
              <tr><td style="width:170px;color:#64748b;">Order No / 订单号</td><td>' . $orderNo . '</td></tr>
              <tr><td style="color:#64748b;">Paid At / 支付时间</td><td>' . $paidAt . '</td></tr>
              <tr><td style="color:#64748b;">Product / 商品</td><td>' . $productName . '</td></tr>
              <tr><td style="color:#64748b;">Network / 链</td><td>' . htmlspecialchars($chain) . '</td></tr>
              <tr><td style="color:#64748b;">Amount / 金额</td><td><strong>' . htmlspecialchars($amount) . ' USDT</strong></td></tr>
              <tr><td style="color:#64748b;">Transaction / 交易哈希</td><td>' . $txHtml . '</td></tr>
            </table>
          </td></tr>
          <tr><td style="padding:0 28px 26px;">' . $storeBtn . '</td></tr>
          <tr><td style="padding:16px 28px;background:#f8fafc;color:#64748b;font-size:12px;line-height:1.6;">
            This is an automated receipt for your completed purchase.<br>
            这是您完成支付后的自动收据邮件，请妥善留存。
          </td></tr>
        </table>
      </td>
    </tr>
  </table>
</body></html>';
    }
}
