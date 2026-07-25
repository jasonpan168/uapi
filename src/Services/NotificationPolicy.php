<?php

class NotificationPolicy
{
    public const DEFAULT_SETTINGS = [
        // Channels (default OFF: user must explicitly enable)
        'channel_in_app' => false,
        'channel_tg' => false,
        'channel_email' => false,

        // Notice types (default OFF)
        'order' => false,
        'withdraw' => false,
        'balance' => false,
        'announcement' => false,
        'low_quota' => false,
        'security' => false,
        'system' => false,
    ];

    public static function parse(?string $json): array
    {
        $raw = json_decode((string)$json, true);
        return self::normalize(is_array($raw) ? $raw : []);
    }

    public static function normalize(array $settings): array
    {
        $out = self::DEFAULT_SETTINGS;
        foreach ($settings as $k => $v) {
            if (!array_key_exists((string)$k, $out)) {
                continue;
            }
            $out[(string)$k] = (bool)$v;
        }
        return $out;
    }

    public static function isTypeEnabled(array $settings, string $type): bool
    {
        return !empty($settings[$type]);
    }

    public static function isChannelEnabled(array $settings, string $channel): bool
    {
        $key = 'channel_' . $channel;
        return !empty($settings[$key]);
    }
}

