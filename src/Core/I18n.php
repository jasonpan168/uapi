<?php

class I18n {
    private static $lang = 'en';
    private static $fallback_lang = 'en';
    private static $supported = ['zh-cn', 'zh-tw', 'en', 'ja'];
    private static $translations = [];
    private static $loaded_cache = [];
    private static $aliases = [
        // Simplified Chinese (Mainland, Singapore)
        'zh' => 'zh-cn',
        'cn' => 'zh-cn',
        'zh-cn' => 'zh-cn',
        'zh-hans' => 'zh-cn',
        'zh-hans-cn' => 'zh-cn',
        'zh-sg' => 'zh-cn',
        // Traditional Chinese (Taiwan / Hong Kong / Macau)
        'zh-tw' => 'zh-tw',
        'zh-hk' => 'zh-tw',
        'zh-mo' => 'zh-tw',
        'zh-hant' => 'zh-tw',
        'zh-hant-tw' => 'zh-tw',
        'zh-hant-hk' => 'zh-tw',
        // English
        'en-us' => 'en',
        'en-gb' => 'en',
        'en' => 'en',
        // Japanese
        'ja' => 'ja',
        'ja-jp' => 'ja',
        'jp' => 'ja',
    ];

    public static function init() {
        // Priority: GET > SESSION > COOKIE > BROWSER > DEFAULT
        $queryLang = isset($_GET['lang']) ? (string)$_GET['lang'] : '';
        $sessionLang = isset($_SESSION['lang']) ? (string)$_SESSION['lang'] : '';
        $cookieLang = isset($_COOKIE['lang']) ? (string)$_COOKIE['lang'] : '';
        // Default language is English for every new visitor. We deliberately
        // do NOT consult Accept-Language so that visitors from CN/HK/TW/JP
        // browsers still see English unless they pick a different language
        // via the switcher (preference is then remembered in session/cookie).
        $lang = $queryLang !== ''
            ? $queryLang
            : ($sessionLang !== ''
                ? $sessionLang
                : ($cookieLang !== ''
                    ? $cookieLang
                    : self::$fallback_lang));
        self::setLang($lang);
    }

    public static function setLang($lang) {
        $lang = self::normalizeLang($lang);
        self::$lang = $lang;

        // Persist
        if (session_status() === PHP_SESSION_ACTIVE) {
            if (!isset($_SESSION['lang']) || (string)$_SESSION['lang'] !== $lang) {
                $_SESSION['lang'] = $lang;
            }
        }
        if (!isset($_COOKIE['lang']) || (string)$_COOKIE['lang'] !== $lang) {
            setcookie('lang', $lang, [
                'expires' => time() + 86400 * 30,
                'path' => '/',
                'httponly' => false,
                'samesite' => 'Lax',
            ]);
            $_COOKIE['lang'] = $lang;
        }

        self::load($lang);
    }

    public static function getLang() {
        return self::$lang;
    }

    private static function normalizeLang($lang) {
        $lang = strtolower(trim((string)$lang));
        $mapped = self::$aliases[$lang] ?? $lang;
        if (!in_array($mapped, self::$supported, true)) {
            return self::$fallback_lang;
        }
        return $mapped;
    }

    private static function detectBrowserLang() {
        $header = isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? (string)$_SERVER['HTTP_ACCEPT_LANGUAGE'] : '';
        if ($header !== '') {
            $parts = explode(',', $header);
            $bestLang = null;
            $bestQ = -1.0;
            foreach ($parts as $part) {
                $seg = trim((string)$part);
                if ($seg === '') continue;
                $langPart = $seg;
                $q = 1.0;
                if (strpos($seg, ';') !== false) {
                    $tmp = explode(';', $seg);
                    $langPart = trim((string)$tmp[0]);
                    foreach ($tmp as $piece) {
                        $piece = trim((string)$piece);
                        if (stripos($piece, 'q=') === 0) {
                            $qVal = (float)substr($piece, 2);
                            if ($qVal >= 0 && $qVal <= 1) {
                                $q = $qVal;
                            }
                            break;
                        }
                    }
                }
                $normalized = self::normalizeLang($langPart);
                if (in_array($normalized, self::$supported, true) && $q > $bestQ) {
                    $bestQ = $q;
                    $bestLang = $normalized;
                }
            }
            if ($bestLang !== null) {
                return $bestLang;
            }
        }
        return null;
    }

    public static function load($lang) {
        $lang = self::normalizeLang($lang);
        if (isset(self::$loaded_cache[$lang]) && is_array(self::$loaded_cache[$lang])) {
            self::$translations = self::$loaded_cache[$lang];
            return;
        }
        $file = __DIR__ . '/../../lang/' . $lang . '.php';
        if (!file_exists($file)) {
            if ($lang !== self::$fallback_lang) {
                self::load(self::$fallback_lang);
                return;
            }
            self::$translations = [];
            return;
        }
        $data = require $file;
        if (!is_array($data)) {
            $data = [];
        }
        self::$loaded_cache[$lang] = $data;
        self::$translations = $data;
    }

    public static function t($key, $replacements = []) {
        $text = self::$translations[$key] ?? $key;
        if (!empty($replacements)) {
            foreach ($replacements as $k => $v) {
                $text = str_replace(':' . $k, $v, $text);
            }
        }
        return $text;
    }

    public static function isZh()
    {
        return self::$lang === 'zh-cn';
    }

    public static function langUrl($lang, ?array $query = null)
    {
        $target = self::normalizeLang($lang);
        $queryData = is_array($query) ? $query : $_GET;
        $queryData['lang'] = $target;
        return '?' . http_build_query($queryData);
    }
}

// Global helper functions
if (!function_exists('__')) {
    function __($key, $replacements = []) {
        return I18n::t($key, $replacements);
    }
}

if (!function_exists('e__')) {
    function e__($key, $replacements = []) {
        return htmlspecialchars(I18n::t($key, $replacements));
    }
}

if (!function_exists('jsesc')) {
    function jsesc($s): string {
        return substr(json_encode((string)($s ?? ''), JSON_UNESCAPED_UNICODE), 1, -1);
    }
}
