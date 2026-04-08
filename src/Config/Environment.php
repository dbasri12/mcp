<?php

declare(strict_types=1);

namespace InternalAppMcp\Config;

final class Environment
{
    public static function loadFile(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        $lines = file($path, \FILE_IGNORE_NEW_LINES);
        if (false === $lines) {
            return;
        }

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ('' === $trimmed || str_starts_with($trimmed, '#')) {
                continue;
            }

            if (str_starts_with($trimmed, 'export ')) {
                $trimmed = trim(substr($trimmed, 7));
            }

            $delimiter = strpos($trimmed, '=');
            if (false === $delimiter) {
                continue;
            }

            $name = trim(substr($trimmed, 0, $delimiter));
            $value = trim(substr($trimmed, $delimiter + 1));

            if ('' === $name || 1 !== preg_match('/^[A-Z_][A-Z0-9_]*$/i', $name)) {
                continue;
            }

            if (self::isDefined($name)) {
                continue;
            }

            self::set($name, self::parseValue($value));
        }
    }

    private static function isDefined(string $name): bool
    {
        return false !== getenv($name) || array_key_exists($name, $_ENV) || array_key_exists($name, $_SERVER);
    }

    private static function set(string $name, string $value): void
    {
        putenv($name.'='.$value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    private static function parseValue(string $value): string
    {
        if ('' === $value) {
            return '';
        }

        if (
            str_starts_with($value, '"')
            && str_ends_with($value, '"')
            && strlen($value) >= 2
        ) {
            return stripcslashes(substr($value, 1, -1));
        }

        if (
            str_starts_with($value, "'")
            && str_ends_with($value, "'")
            && strlen($value) >= 2
        ) {
            return substr($value, 1, -1);
        }

        $commentPosition = strpos($value, ' #');
        if (false !== $commentPosition) {
            $value = substr($value, 0, $commentPosition);
        }

        return trim($value);
    }
}
