<?php
class Validator
{
    public static function required($value): bool
    {
        return isset($value) && trim((string)$value) !== '';
    }

    public static function email(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    public static function int($value): bool
    {
        return filter_var($value, FILTER_VALIDATE_INT) !== false;
    }

    public static function minLength(string $value, int $min): bool
    {
        return mb_strlen($value, 'UTF-8') >= $min;
    }

    public static function maxLength(string $value, int $max): bool
    {
        return mb_strlen($value, 'UTF-8') <= $max;
    }
}
