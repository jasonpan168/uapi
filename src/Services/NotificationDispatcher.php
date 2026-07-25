<?php

require_once __DIR__ . '/../Core/Database.php';
require_once __DIR__ . '/../Telegram.php';
require_once __DIR__ . '/EmailNotificationService.php';
require_once __DIR__ . '/NotificationPolicy.php';

class NotificationDispatcher
{
    public static function sendToUser($user_id, $subject, $messageHtml, $type = 'system')
    {
        $title = trim((string)$subject) !== '' ? (string)$subject : '系统通知';
        $content = trim(strip_tags((string)$messageHtml));
        if ($content === '') {
            $content = $title;
        }
        $result = self::notifyUser(
            (int)$user_id,
            [
                'type' => (string)$type,
                'title' => $title,
                'content' => $content,
                'subject' => (string)$subject,
                'html' => (string)$messageHtml,
            ]
        );
        return (bool)($result['sent'] ?? false);
    }

    public static function notifyUser(int $userId, array $payload): array
    {
        $type = (string)($payload['type'] ?? 'system');
        $title = trim((string)($payload['title'] ?? '系统通知'));
        $content = trim((string)($payload['content'] ?? ''));
        $subject = trim((string)($payload['subject'] ?? $title));
        $inAppType = trim((string)($payload['in_app_type'] ?? $type));
        $html = trim((string)($payload['html'] ?? ''));
        $dedupeLike = trim((string)($payload['dedupe_like'] ?? ''));

        if ($userId <= 0 || $title === '') {
            return ['sent' => false, 'channels' => []];
        }
        if ($content === '') {
            $content = $title;
        }
        if ($html === '') {
            $html = nl2br(htmlspecialchars($content, ENT_QUOTES, 'UTF-8'));
        }

        $db = Database::getInstance();
        $row = $db->fetch("SELECT notification_settings FROM users WHERE id = ? LIMIT 1", [$userId]);
        if (!$row) {
            self::logDelivery($userId, 'in_app', $type, false, 'skipped');
            self::logDelivery($userId, 'tg', $type, false, 'skipped');
            self::logDelivery($userId, 'email', $type, false, 'skipped');
            return ['sent' => false, 'channels' => []];
        }
        $settings = NotificationPolicy::parse((string)($row['notification_settings'] ?? '{}'));

        if (!NotificationPolicy::isTypeEnabled($settings, $type)) {
            self::logDelivery($userId, 'in_app', $type, false, 'skipped');
            self::logDelivery($userId, 'tg', $type, false, 'skipped');
            self::logDelivery($userId, 'email', $type, false, 'skipped');
            return ['sent' => false, 'channels' => ['in_app' => false, 'tg' => false, 'email' => false]];
        }

        $channels = ['in_app' => false, 'tg' => false, 'email' => false];

        if (NotificationPolicy::isChannelEnabled($settings, 'in_app')) {
            $inAppOk = self::insertInApp($db, $userId, $inAppType, $title, $content, $dedupeLike);
            $channels['in_app'] = $inAppOk;
            self::logDelivery($userId, 'in_app', $type, $inAppOk, $inAppOk ? 'success' : 'failed');
        } else {
            self::logDelivery($userId, 'in_app', $type, false, 'skipped');
        }

        if (NotificationPolicy::isChannelEnabled($settings, 'tg')) {
            $tgOk = Telegram::sendToUser($userId, $html, $type);
            $channels['tg'] = $tgOk;
            self::logDelivery($userId, 'tg', $type, $tgOk, $tgOk ? 'success' : 'failed');
        } else {
            self::logDelivery($userId, 'tg', $type, false, 'skipped');
        }

        if (NotificationPolicy::isChannelEnabled($settings, 'email')) {
            $mailOk = EmailNotificationService::sendToUser($userId, $subject, $html, $type);
            $channels['email'] = $mailOk;
            self::logDelivery($userId, 'email', $type, $mailOk, $mailOk ? 'success' : 'failed');
        } else {
            self::logDelivery($userId, 'email', $type, false, 'skipped');
        }

        return [
            'sent' => ($channels['in_app'] || $channels['tg'] || $channels['email']),
            'channels' => $channels,
        ];
    }

    public static function broadcastAnnouncement(string $title, string $content): int
    {
        $db = Database::getInstance();
        $users = $db->fetchAll("SELECT id FROM users WHERE role <> 'admin'");
        $sent = 0;
        foreach ($users as $u) {
            $uid = (int)($u['id'] ?? 0);
            if ($uid <= 0) {
                continue;
            }
            $result = self::notifyUser($uid, [
                'type' => 'announcement',
                'in_app_type' => 'announcement',
                'title' => $title,
                'content' => $content,
                'subject' => $title,
                'html' => nl2br(htmlspecialchars($content, ENT_QUOTES, 'UTF-8')),
                'dedupe_like' => substr($content, 0, 80),
            ]);
            if (!empty($result['sent'])) {
                $sent++;
            }
        }
        return $sent;
    }

    private static function insertInApp($db, int $userId, string $type, string $title, string $content, string $dedupeLike = ''): bool
    {
        try {
            if ($dedupeLike !== '') {
                $exists = $db->fetch(
                    "SELECT id FROM notifications
                     WHERE user_id = ? AND type = ? AND title = ? AND content LIKE ?
                     ORDER BY id DESC LIMIT 1",
                    [$userId, $type, $title, '%' . $dedupeLike . '%']
                );
                if (!$exists) {
                    $exists = $db->fetch(
                        "SELECT id FROM notifications
                         WHERE user_id = ? AND type = ? AND CONCAT(title, ' ', content) LIKE ?
                         ORDER BY id DESC LIMIT 1",
                        [$userId, $type, '%' . $dedupeLike . '%']
                    );
                }
                if ($exists) {
                    return true;
                }
            }
            $db->query(
                "INSERT INTO notifications (user_id, type, title, content, is_read, created_at)
                 VALUES (?, ?, ?, ?, 0, NOW())",
                [$userId, $type, $title, $content]
            );
            return true;
        } catch (Throwable $e) {
            error_log('NotificationDispatcher insertInApp Error: ' . $e->getMessage());
            return false;
        }
    }

    private static function logDelivery($user_id, $channel, $type, $ok, $status = null)
    {
        try {
            $db = Database::getInstance();
            $db->query(
                "INSERT INTO notification_send_logs (user_id, channel, notice_type, status, created_at)
                 VALUES (?, ?, ?, ?, NOW())",
                [
                    $user_id ? (int)$user_id : null,
                    (string)$channel,
                    (string)($type ?: 'system'),
                    $status !== null ? (string)$status : ($ok ? 'success' : 'failed')
                ]
            );
        } catch (Throwable $e) {
            error_log('NotificationDispatcher logDelivery Error: ' . $e->getMessage());
        }
    }
}
