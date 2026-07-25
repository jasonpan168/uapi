<?php

require_once __DIR__ . '/TotpService.php';

class User2FAService
{
    public static function defaultScenes(): array
    {
        return [
            // Merchant scenes
            'login'             => 1,
            'balance_pay'       => 1,
            'settings_security' => 1,
            'derived_wallet'    => 1,
            // Admin-only optional scenes (0 = disabled by default)
            'admin_settings'    => 0,
            'admin_binance'     => 0,
            'admin_broadcast'   => 0,
            'admin_user_delete' => 0,
            'admin_plan_edit'   => 0,
        ];
    }

    /** Admin-only optional scene labels for use in the security page */
    public static function adminOptionalScenes(): array
    {
        return [
            'admin_settings'    => '系统设置变更',
            'admin_binance'     => '币安商户敏感操作（退款/关单）',
            'admin_broadcast'   => '邮件群发',
            'admin_user_delete' => '用户删除/封禁',
            'admin_plan_edit'   => '套餐管理（新增/编辑/删除）',
        ];
    }

    public static function parseScenes(array $user): array
    {
        $defaults = self::defaultScenes();
        $raw = trim((string)($user['two_factor_scenes'] ?? ''));
        if ($raw === '') {
            return $defaults;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $defaults;
        }
        foreach ($defaults as $k => $v) {
            $defaults[$k] = !empty($decoded[$k]) ? 1 : 0;
        }
        return $defaults;
    }

    public static function buildScenesFromInput(array $input): array
    {
        $keys = array_keys(self::defaultScenes());
        $in = [];
        foreach ((array)$input as $k) {
            $in[(string)$k] = 1;
        }
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = isset($in[$k]) ? 1 : 0;
        }
        return $out;
    }

    public static function isEnabled(array $user): bool
    {
        return (int)($user['two_factor_enabled'] ?? 0) === 1 && trim((string)($user['two_factor_secret'] ?? '')) !== '';
    }

    public static function isSceneEnabled(array $user, string $scene): bool
    {
        if (!self::isEnabled($user)) {
            return false;
        }
        $scenes = self::parseScenes($user);
        return !empty($scenes[$scene]);
    }

    public static function verifyForScene(array $user, string $scene, string $otp): array
    {
        if (!self::isSceneEnabled($user, $scene)) {
            return [true, ''];
        }
        $secret = trim((string)($user['two_factor_secret'] ?? ''));
        if (!TotpService::verifyCode($secret, trim($otp), 1)) {
            return [false, '谷歌验证码无效，请输入 6 位动态码。'];
        }
        return [true, ''];
    }
}

