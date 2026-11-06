<?php

namespace App\Support;

use Illuminate\Support\Facades\Mail;

/**
 * Every emailed code (sign-up, password reset, email change, password change, reactivation) leaves as the same branded HTML email
 * with a plain-text twin (resources/views/emails/code*.blade.php). Throws when the mail server refuses, like Mail::send.
 */
class CodeEmail
{
    public const VERIFY_SUBJECT = 'Your Velocity Suites verification code';
    public const RESET_SUBJECT = 'Your Velocity Suites password reset code';

    /**
     * @param string $intro   the sentence above the code ("Use this code to reset your password:")
     * @param string $ignore  the line under the expiry ("If you didn't create an account, ignore this email.")
     */
    public static function send(string $to, string $code, ?string $firstName, int $minutes, string $intro, string $ignore, string $subject = self::VERIFY_SUBJECT): void
    {
        $data = ['code' => $code, 'name' => trim((string) $firstName) ?: 'there', 'minutes' => $minutes, 'intro' => $intro, 'ignore' => $ignore, 'subject' => $subject];
        Mail::send(['html' => 'emails.code', 'text' => 'emails.code-text'], $data, function ($message) use ($to, $subject) {
            $message->to($to)->subject($subject);
        });
    }
}
