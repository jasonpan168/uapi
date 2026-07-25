<?php

require_once __DIR__ . '/../Core/Database.php';
require_once __DIR__ . '/NotificationPolicy.php';
require_once __DIR__ . '/../../PHPMailer/src/Exception.php';
require_once __DIR__ . '/../../PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../../PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;

class EmailNotificationService
{
    private static $brandCache = null;

    public static function sendToUser($user_id, $subject, $htmlMessage, $type = 'system')
    {
        $db = Database::getInstance();
        $user = $db->fetch(
            "SELECT u.id, u.email, u.notification_settings, u.notice_cycle_ym, u.email_notice_used_month, u.email_notice_address,
                    u.email_notice_use_custom_smtp, u.smtp_host, u.smtp_port, u.smtp_username, u.smtp_password,
                    u.smtp_encryption, u.smtp_from_name, u.smtp_from_email, u.plan_id,
                    p.allow_email_notice, p.email_notice_limit
             FROM users u
             LEFT JOIN plans p ON p.id = u.plan_id
             WHERE u.id = ? LIMIT 1",
            [$user_id]
        );

        if (!$user) {
            return false;
        }

        $settings = NotificationPolicy::parse($user['notification_settings'] ?? '{}');
        if (!NotificationPolicy::isTypeEnabled($settings, (string)$type)) {
            return false;
        }
        if (!NotificationPolicy::isChannelEnabled($settings, 'email')) {
            return false;
        }

        if (empty($user['allow_email_notice'])) {
            return false;
        }

        // Receiver fallback:
        // 1) user-defined notification mailbox
        // 2) account login email
        $to = trim((string)($user['email_notice_address'] ?? ''));
        if ($to === '') {
            $to = trim((string)($user['email'] ?? ''));
        }
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        self::resetMonthlyCountersIfNeeded($db, $user);

        $used = (int)($user['email_notice_used_month'] ?? 0);
        $limit = (int)($user['email_notice_limit'] ?? 0);
        if ($limit > 0 && $used >= $limit) {
            return false;
        }

        $smtpConfig = self::resolveSmtpConfig($db, $user);
        if (!$smtpConfig) {
            return false;
        }

        $ok = self::sendUsingConfig($smtpConfig, $to, $subject, $htmlMessage);
        if ($ok) {
            $db->query("UPDATE users SET email_notice_used_month = email_notice_used_month + 1 WHERE id = ?", [$user_id]);
        }

        return $ok;
    }

    public static function sendTestToUser($user_id, $to, $subject, $htmlMessage, $useCustom = false)
    {
        $db = Database::getInstance();
        $user = $db->fetch(
            "SELECT id, email_notice_use_custom_smtp, smtp_host, smtp_port, smtp_username, smtp_password,
                    smtp_encryption, smtp_from_name, smtp_from_email
             FROM users WHERE id = ? LIMIT 1",
            [$user_id]
        );
        if (!$user) {
            return false;
        }

        $user['email_notice_use_custom_smtp'] = $useCustom ? 1 : 0;
        $smtpConfig = self::resolveSmtpConfig($db, $user);
        if (!$smtpConfig) {
            return false;
        }

        return self::sendUsingConfig($smtpConfig, $to, $subject, $htmlMessage);
    }

    public static function sendUsingConfig($smtpConfig, $to, $subject, $htmlMessage)
    {
        try {
            $mail = new PHPMailer(true);
            $mail->CharSet = 'UTF-8';
            $mail->isSMTP();
            $mail->Host = $smtpConfig['host'];
            $mail->Port = (int)$smtpConfig['port'];
            $mail->SMTPAuth = !empty($smtpConfig['username']);
            $mail->Username = $smtpConfig['username'];
            $mail->Password = $smtpConfig['password'];

            $enc = strtolower((string)($smtpConfig['encryption'] ?? 'tls'));
            if ($enc === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($enc === 'none' || $enc === '') {
                $mail->SMTPSecure = false;
                $mail->SMTPAutoTLS = false;
            } else {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            }

            $fromEmail = trim((string)$smtpConfig['from_email']);
            $fromName = trim((string)$smtpConfig['from_name']);
            if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
                return false;
            }

            $mail->setFrom($fromEmail, $fromName !== '' ? $fromName : 'UAPI');
            $mail->addAddress($to);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mailBody = self::buildUnifiedTemplate((string)$subject, (string)$htmlMessage);
            $mail->Body = $mailBody;
            $mail->AltBody = trim(preg_replace('/\s+/', ' ', strip_tags($mailBody)));

            return $mail->send();
        } catch (\Throwable $e) {
            error_log('EmailNotificationService Error: ' . $e->getMessage());
            return false;
        }
    }

    private static function resetMonthlyCountersIfNeeded($db, &$user)
    {
        $ym = date('Y-m');
        if (($user['notice_cycle_ym'] ?? '') === $ym) {
            return;
        }

        $db->query(
            "UPDATE users
             SET notice_cycle_ym = ?, tg_notice_used_month = 0, email_notice_used_month = 0
             WHERE id = ?",
            [$ym, $user['id']]
        );

        $user['notice_cycle_ym'] = $ym;
        $user['tg_notice_used_month'] = 0;
        $user['email_notice_used_month'] = 0;
    }

    private static function resolveSmtpConfig($db, $user)
    {
        $useCustom = !empty($user['email_notice_use_custom_smtp']);
        if ($useCustom) {
            $config = [
                'host' => trim((string)($user['smtp_host'] ?? '')),
                'port' => (int)($user['smtp_port'] ?? 587),
                'username' => trim((string)($user['smtp_username'] ?? '')),
                'password' => (string)($user['smtp_password'] ?? ''),
                'encryption' => trim((string)($user['smtp_encryption'] ?? 'tls')),
                'from_name' => trim((string)($user['smtp_from_name'] ?? 'UAPI')),
                'from_email' => trim((string)($user['smtp_from_email'] ?? '')),
            ];
            return self::isValidSmtpConfig($config) ? $config : null;
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

        $config = [
            'host' => trim((string)($cfg['smtp_host'] ?? '')),
            'port' => (int)($cfg['smtp_port'] ?? 587),
            'username' => trim((string)($cfg['smtp_username'] ?? '')),
            'password' => (string)($cfg['smtp_password'] ?? ''),
            'encryption' => trim((string)($cfg['smtp_encryption'] ?? 'tls')),
            'from_name' => trim((string)($cfg['smtp_from_name'] ?? 'UAPI')),
            'from_email' => trim((string)($cfg['smtp_from_email'] ?? '')),
        ];

        return self::isValidSmtpConfig($config) ? $config : null;
    }

    private static function isValidSmtpConfig($config)
    {
        if (empty($config['host']) || empty($config['port']) || empty($config['from_email'])) {
            return false;
        }

        if (!filter_var($config['from_email'], FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        return true;
    }

    private static function buildUnifiedTemplate(string $subject, string $htmlMessage): string
    {
        $brand = self::loadBrand();
        $siteName = htmlspecialchars((string)($brand['site_name'] ?? 'UAPI'), ENT_QUOTES, 'UTF-8');
        $logoUrl = trim((string)($brand['site_logo'] ?? ''));
        $logoHtml = '';
        if ($logoUrl !== '') {
            $logoHtml = '<img src="' . htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') . '" alt="' . $siteName . '" style="height:30px;max-width:160px;object-fit:contain;display:block;">';
        }

        $body = self::normalizeBodyHtml($htmlMessage);
        $safeSubject = htmlspecialchars(trim($subject) !== '' ? $subject : '系统通知', ENT_QUOTES, 'UTF-8');
        $preheader = htmlspecialchars(substr(trim(preg_replace('/\s+/', ' ', strip_tags($body))), 0, 90), ENT_QUOTES, 'UTF-8');

        return '<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>' . $safeSubject . '</title>
</head>
<body style="margin:0;padding:0;background:#f3f4f6;">
  <div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">' . $preheader . '</div>
  <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background:#f3f4f6;padding:18px 10px;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Helvetica,Arial,sans-serif;">
    <tr>
      <td align="center">
        <table role="presentation" cellpadding="0" cellspacing="0" width="620" style="max-width:620px;background:#161a1f;border:1px solid #2a2f36;border-radius:16px;overflow:hidden;">
          <tr>
            <td style="padding:18px 22px;background:#11161b;border-bottom:1px solid #2a2f36;">
              <table role="presentation" cellpadding="0" cellspacing="0" width="100%">
                <tr>
                  <td align="left" style="vertical-align:middle;">' . ($logoHtml !== '' ? $logoHtml : '<span style="color:#fcd535;font-size:22px;font-weight:700;letter-spacing:.4px;">' . $siteName . '</span>') . '</td>
                  <td align="right" style="vertical-align:middle;">
                    <span style="display:inline-block;background:#fcd535;color:#11161b;font-size:11px;font-weight:700;padding:5px 10px;border-radius:999px;">EMAIL NOTICE</span>
                  </td>
                </tr>
              </table>
            </td>
          </tr>
          <tr>
            <td style="padding:24px 24px 10px;">
              <div style="color:#f5f5f5;font-size:22px;line-height:1.35;font-weight:700;margin:0 0 14px;">' . $safeSubject . '</div>
              <div style="height:1px;background:#2a2f36;margin:0 0 18px;"></div>
            </td>
          </tr>
          <tr>
            <td style="padding:0 24px 24px;color:#e6e8ea;font-size:14px;line-height:1.78;white-space:normal;word-break:break-word;overflow-wrap:anywhere;line-break:anywhere;">
              ' . $body . '
            </td>
          </tr>
          <tr>
            <td style="padding:14px 24px;background:#11161b;border-top:1px solid #2a2f36;color:#8b949e;font-size:12px;line-height:1.6;">
              <div>' . $siteName . ' 自动通知邮件，请勿直接回复。</div>
              <div style="margin-top:2px;">This is an automated message from ' . $siteName . '.</div>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>';
    }

    private static function normalizeBodyHtml(string $html): string
    {
        $content = trim($html);
        if ($content === '') {
            return '<p style="margin:0;color:#e6e8ea;">系统通知</p>';
        }

        if (preg_match('/<body[^>]*>(.*?)<\/body>/is', $content, $m)) {
            $content = trim((string)$m[1]);
        } elseif (stripos($content, '<html') !== false) {
            $content = trim(strip_tags($content, '<a><p><br><strong><b><em><ul><ol><li><code><pre><span><div><table><tr><td><th><tbody><thead><img>'));
        } elseif ($content === strip_tags($content)) {
            $content = nl2br(htmlspecialchars($content, ENT_QUOTES, 'UTF-8'));
        }

        return $content;
    }

    private static function loadBrand(): array
    {
        if (is_array(self::$brandCache)) {
            return self::$brandCache;
        }
        $brand = [
            'site_name' => 'UAPI',
            'site_logo' => '',
        ];
        try {
            $db = Database::getInstance();
            $rows = $db->fetchAll(
                "SELECT key_name, value FROM system_settings WHERE key_name IN ('site_name','site_logo')"
            );
            foreach ($rows as $r) {
                $k = (string)($r['key_name'] ?? '');
                $v = (string)($r['value'] ?? '');
                if ($k === 'site_name') {
                    $brand['site_name'] = $v !== '' ? $v : 'UAPI';
                } elseif ($k === 'site_logo') {
                    $brand['site_logo'] = self::toAbsoluteUrl($v);
                }
            }
        } catch (\Throwable $ignore) {
            error_log("[EmailNotificationService] brand cache load failed: " . $ignore->getMessage());
        }
        self::$brandCache = $brand;
        return $brand;
    }

    private static function toAbsoluteUrl(string $url): string
    {
        $u = trim($url);
        if ($u === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $u)) {
            return $u;
        }
        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        if ($host === '') {
            return $u;
        }
        $scheme = 'http';
        if (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
            (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) ||
            (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
        ) {
            $scheme = 'https';
        }
        return $scheme . '://' . $host . (strpos($u, '/') === 0 ? $u : ('/' . $u));
    }
}
