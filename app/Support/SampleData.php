<?php

namespace App\Support;

use RuntimeException;

/**
 * Sample (sandbox and demo) data exists for local development and tests only.
 * Production shows nothing but what the source apps send.
 */
class SampleData
{
    public static function allowed(): bool
    {
        return app()->environment(['local', 'testing']);
    }

    /**
     * @throws RuntimeException
     */
    public static function ensureAllowed(string $what): void
    {
        if (! self::allowed()) {
            throw new RuntimeException("{$what} is for local development only and cannot run in the ".app()->environment().' environment.');
        }
    }
}
