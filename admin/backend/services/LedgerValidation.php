<?php
namespace App\Services;
require_once __DIR__ . '/LedgerInputException.php';

/** Strict, side-effect-free validation shared by Finance and Materials. */
final class LedgerValidation
{
    public static function text($value, string $label, int $max, bool $required = false): string
    {
        if (!is_scalar($value) && $value !== null) {
            throw new LedgerInputException($label . ' must be text.');
        }
        $value = trim((string)$value);
        if (!mb_check_encoding($value, 'UTF-8')) throw new LedgerInputException($label . ' contains invalid characters.');
        if (($required && $value === '') || mb_strlen($value, 'UTF-8') > $max) {
            throw new LedgerInputException($label . ($value === '' ? ' is required.' : ' is too long.'));
        }
        return $value;
    }

    public static function integer($value, string $label, int $min = 0, int $max = 2147483647): int
    {
        if ((!is_string($value) && !is_int($value))
            || !preg_match('/^-?\d+$/D', (string)$value)) {
            throw new LedgerInputException($label . ' must be a whole number.');
        }
        $number = (int)$value;
        if ($number < $min || $number > $max) {
            throw new LedgerInputException($label . ' is outside the allowed range.');
        }
        return $number;
    }

    public static function optionalId($value, string $label): ?int
    {
        return $value === null || $value === '' || $value === 0 || $value === '0'
            ? null : self::integer($value, $label, 1);
    }

    public static function money($value, string $label = 'Amount', bool $allowZero = false): float
    {
        if ((!is_string($value) && !is_int($value) && !is_float($value))
            || !preg_match('/^\d+(?:\.\d{1,2})?$/D', (string)$value)) {
            throw new LedgerInputException($label . ' must have at most two decimal places.');
        }
        $amount = (float)$value;
        if (!is_finite($amount) || $amount > 99999999.99 || ($allowZero ? $amount < 0 : $amount <= 0)) {
            throw new LedgerInputException($label . ' is outside the allowed range.');
        }
        return $amount;
    }

    public static function choice($value, array $allowed, string $label): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new LedgerInputException('Invalid ' . strtolower($label) . '.');
        }
        return $value;
    }

    public static function date($value, string $label, ?string $default = null): ?string
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $parts)
            || (int)$parts[1] < 1000 || !checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1])) {
            throw new LedgerInputException('Invalid ' . strtolower($label) . '. Use YYYY-MM-DD.');
        }
        return $value;
    }
}
