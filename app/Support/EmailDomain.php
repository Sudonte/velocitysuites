<?php

namespace App\Support;

/**
 * The two email domains the hotel's accounts live on: guests and the System Administrator use Gmail (@gmail.com),
 * hotel staff (Receptionist, Manager) use @hotel.com. Case and surrounding spaces never matter.
 */
class EmailDomain
{
    public const NOT_GMAIL = 'Please use the Gmail address registered to your account (@gmail.com).';
    public const STAFF_ON_GMAIL_FORM = 'Hotel staff accounts can\'t reset here. Use “Hotel staff: Request a staff password reset” below.';
    public const NOT_HOTEL = 'Please use your hotel staff email (@hotel.com).';
    public const GMAIL_ON_STAFF_FORM = 'Guest and administrator accounts reset with a code. Go back to “Reset your password”.';
    public const SIGN_UP_NOT_GMAIL = 'Please use a Gmail address (@gmail.com) to create your account.';

    public static function clean(?string $email): string
    {
        return strtolower(trim((string) $email));
    }

    public static function isGmail(?string $email): bool
    {
        return (bool) preg_match('/^[^@\s]+@gmail\.com$/', self::clean($email));
    }

    public static function isHotel(?string $email): bool
    {
        return (bool) preg_match('/^[^@\s]+@hotel\.com$/', self::clean($email));
    }

    /** The message for an address that does not belong on the Guest / System Administrator reset form, or null when it does. */
    public static function gmailFormError(?string $email): ?string
    {
        return self::isGmail($email) ? null : (self::isHotel($email) ? self::STAFF_ON_GMAIL_FORM : self::NOT_GMAIL);
    }

    /** The message for an address that does not belong on the staff request form, or null when it does. */
    public static function staffFormError(?string $email): ?string
    {
        return self::isHotel($email) ? null : (self::isGmail($email) ? self::GMAIL_ON_STAFF_FORM : self::NOT_HOTEL);
    }
}
