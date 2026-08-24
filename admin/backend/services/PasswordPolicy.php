<?php
/**
 * Shared password policy for every browser and mobile account workflow.
 * Existing hashes remain valid; this policy applies when a password is created
 * or changed.
 */
namespace App\Services;

final class PasswordPolicy
{
    public const MIN_CHARACTERS = 12;
    // PHP's current PASSWORD_DEFAULT is bcrypt, which only processes 72 bytes.
    public const MAX_BYTES = 72;

    private const COMMON_PASSWORDS = [
        '123456789012',
        'adminadmin12',
        'changeme1234',
        'letmeinletmein',
        'password1234',
        'qwertyuiop12',
    ];

    /** @return string[] */
    public static function errors($password): array
    {
        if (!is_string($password) || $password === '') {
            return ['Password is required.'];
        }

        $errors = [];
        if (preg_match('//u', $password) !== 1) {
            return ['Password must be valid UTF-8 text.'];
        }
        $characters = function_exists('mb_strlen')
            ? mb_strlen($password, 'UTF-8')
            : strlen($password);
        if ($characters < self::MIN_CHARACTERS) {
            $errors[] = 'Password must be at least ' . self::MIN_CHARACTERS . ' characters.';
        }
        if (strlen($password) > self::MAX_BYTES) {
            $errors[] = 'Password is too long (maximum ' . self::MAX_BYTES . ' UTF-8 bytes).';
        }
        if (strpos($password, "\0") !== false || preg_match('/^\s+$/u', $password)) {
            $errors[] = 'Password contains invalid content.';
        }

        $normalized = function_exists('mb_strtolower')
            ? mb_strtolower($password, 'UTF-8')
            : strtolower($password);
        if (in_array($normalized, self::COMMON_PASSWORDS, true)) {
            $errors[] = 'Choose a less common password.';
        }

        return array_values(array_unique($errors));
    }

    public static function isValid($password): bool
    {
        return self::errors($password) === [];
    }
}
