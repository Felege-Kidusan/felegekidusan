<?php
/** Scalable, non-sequential member-code generation. */

namespace App\Services;

use RuntimeException;

final class MemberCodeService
{
    private const RANDOM_BYTES = 6; // 48 bits; suitable well beyond 100k members.
    private const MAX_ATTEMPTS = 20;

    public static function generate(\mysqli $connection): string
    {
        $configuredPrefix = defined('MEMBER_CODE_PREFIX') ? (string)MEMBER_CODE_PREFIX : '';
        $prefix = strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', $configuredPrefix));
        $prefix = substr($prefix, 0, 6);

        $statement = $connection->prepare('SELECT 1 FROM members WHERE member_code = ? LIMIT 1');
        if (!$statement) {
            throw new RuntimeException('Member-code lookup could not be prepared.');
        }
        try {
            for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
                $code = $prefix . strtoupper(bin2hex(random_bytes(self::RANDOM_BYTES)));
                $statement->bind_param('s', $code);
                if (!$statement->execute()) {
                    throw new RuntimeException('Member-code lookup failed.');
                }
                if (!$statement->get_result()->fetch_row()) {
                    return $code;
                }
            }
        } finally {
            $statement->close();
        }
        throw new RuntimeException('Could not allocate a unique member code.');
    }
}
