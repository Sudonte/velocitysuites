<?php

namespace App\Support;

/**
 * The downloadable Android app shown in the Home page hero. Every fact comes
 * from config('services.mobile_app') so a new release is one config edit;
 * the size is read from the APK itself when it exists.
 */
class MobileApp
{
    public static function path(): string
    {
        return public_path(config('services.mobile_app.file'));
    }

    /** True only when the APK is really on the server - otherwise the page must not link to it. */
    public static function isAvailable(): bool
    {
        return is_file(self::path());
    }

    public static function version(): string
    {
        return (string) config('services.mobile_app.version');
    }

    public static function minAndroid(): int
    {
        return (int) config('services.mobile_app.min_android');
    }

    /** "21.6 MB" - from the file when present, else the configured size. */
    public static function sizeLabel(): string
    {
        $mb = self::isAvailable()
            ? filesize(self::path()) / 1048576
            : (float) config('services.mobile_app.size_mb');

        return number_format($mb, 1, '.', '') . ' MB';
    }

    /** The name the phone saves the file under. */
    public static function downloadName(): string
    {
        return 'velocity-suites-v' . self::version() . '.apk';
    }

    /** "Version 1.51.1 · 21.6 MB · Android 10 or newer" */
    public static function infoLine(): string
    {
        return 'Version ' . self::version() . ' · ' . self::sizeLabel() . ' · Android ' . self::minAndroid() . ' or newer';
    }
}
