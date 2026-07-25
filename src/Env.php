<?php

class Env {
    public static function load($path) {
        if (!file_exists($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || strpos($line, '#') === 0) {
                continue;
            }

            if (strpos($line, '=') === false) {
                continue;
            }

            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);
            
            // Remove quotes if present
            if (preg_match('/^"(.*)"$/', $value, $matches)) {
                $value = $matches[1];
            }

            if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
                if (function_exists('putenv')) {
                    putenv(sprintf('%s=%s', $name, $value));
                }
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }
    }

    public static function get($key, $default = null) {
        // Try getting from getenv() if available
        if (function_exists('getenv')) {
            $value = getenv($key);
            if ($value !== false) {
                return self::parseValue($value);
            }
        }
        
        // Fallback to $_ENV or $_SERVER
        if (array_key_exists($key, $_ENV)) {
            return self::parseValue($_ENV[$key]);
        }
        if (array_key_exists($key, $_SERVER)) {
            return self::parseValue($_SERVER[$key]);
        }
        
        return $default;
    }

    private static function parseValue($value) {
        switch (strtolower((string)$value)) {
            case 'true':
            case '(true)':
                return true;
            case 'false':
            case '(false)':
                return false;
            case 'empty':
            case '(empty)':
                return '';
            case 'null':
            case '(null)':
                return null;
        }
        return $value;
    }
}
