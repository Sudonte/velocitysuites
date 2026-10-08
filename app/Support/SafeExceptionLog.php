<?php

namespace App\Support;

use Illuminate\Database\QueryException;

/**
 * Builds the log entry for a failed database query WITHOUT the values.
 *
 * Laravel's default QueryException message is the SQL with every binding filled in
 * ("insert into `users` (..., `password`, ...) values (..., $2y$12$..., ...)"), so a failed INSERT/UPDATE
 * that saves a password hash, a token or a one-time code wrote it to storage/logs. On 2026-09-30 a failed
 * `User::create()` typed into `artisan tinker` on production did exactly that.
 *
 * The entry keeps what is needed to debug - the SQL with `?` placeholders, the database's own error text,
 * the SQLSTATE and the code location - and drops every bound value. Anything that still looks like a secret
 * in the remaining text (a bcrypt/argon hash, a duplicate-key value, a bearer token, `password = '...'`
 * in a raw statement) is masked as [REDACTED].
 */
final class SafeExceptionLog
{
    public const MASK = '[REDACTED]';

    /** Mask secret-looking fragments in free text. */
    public static function redact(string $text): string
    {
        $patterns = [
            // bcrypt / argon2 hashes
            '/\$2[abxy]\$\d{2}\$[.\/A-Za-z0-9]{53}/' => self::MASK,
            '/\$argon2id?\$[^\s,)\'"]+/' => self::MASK,
            // "Duplicate entry 'VALUE' for key ..." - the value can be a token or code
            '/(Duplicate entry )\'(?:[^\'\\\\]|\\\\.)*\'/' => '$1\''.self::MASK.'\'',
            // literal secrets inside raw SQL: password = 'x', token='x', otp = "x"
            '/\b(password|passwd|token|api_token|remember_token|otp|secret)\b(\s*[=:]\s*)([\'"]).*?\3/i' => '$1$2$3'.self::MASK.'$3',
            '/\bBearer\s+[A-Za-z0-9._~+\/-]+=*/i' => 'Bearer '.self::MASK,
        ];

        return preg_replace(array_keys($patterns), array_values($patterns), $text) ?? self::MASK;
    }

    /** @return array{0: string, 1: array<string,mixed>} [message, context] for Log::error() */
    public static function forQueryException(QueryException $e): array
    {
        $driverMessage = $e->getPrevious()?->getMessage() ?? $e->getMessage();
        // PDO's text can itself carry values (duplicate key); the connection suffix Laravel appends is rebuilt below.
        $driverMessage = preg_replace('/ \(Connection: .*$/s', '', $driverMessage) ?? $driverMessage;

        $message = sprintf(
            '%s (Connection: %s, SQL: %s, %d binding(s) omitted)',
            self::redact($driverMessage),
            $e->getConnectionName(),
            self::redact($e->getSql()),
            count($e->getBindings())
        );

        $frames = [];
        foreach (array_slice($e->getTrace(), 0, 25) as $frame) {
            $frames[] = ($frame['file'] ?? '[internal]').':'.($frame['line'] ?? '?').' '.(isset($frame['class']) ? $frame['class'].($frame['type'] ?? '::') : '').($frame['function'] ?? '');
        }

        return [$message, [
            'exception' => get_class($e),
            'sqlstate' => $e->getCode(),
            'at' => $e->getFile().':'.$e->getLine(),
            'trace' => $frames,
        ]];
    }
}
