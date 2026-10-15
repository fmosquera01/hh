<?php
declare(strict_types=1);

final class Config
{
    public static function get(string $name, ?string $default = null): ?string
    {
        $value = getenv($name);
        return $value === false ? $default : $value;
    }

    public static function required(string $name): string
    {
        $value = self::get($name);
        if ($value === null || trim($value) === '') {
            throw new RuntimeException('Falta configurar la variable de entorno: ' . $name);
        }
        return $value;
    }

    public static function bool(string $name, bool $default = false): bool
    {
        $value = self::get($name);
        if ($value === null || $value === '') {
            return $default;
        }
        return filter_var($value, FILTER_VALIDATE_BOOL);
    }

    public static function int(string $name, int $default): int
    {
        $value = self::get($name);
        if ($value === null || $value === '') {
            return $default;
        }
        $parsed = filter_var($value, FILTER_VALIDATE_INT);
        return $parsed === false ? $default : $parsed;
    }
}
