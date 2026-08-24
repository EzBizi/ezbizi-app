<?php

namespace App\Support\Phone;

/**
 * The spoken consent sentence. One place so inbound and outbound stay identical.
 */
final class RecordingNotice
{
    public static function sentence(): string
    {
        return 'This call may be recorded.';
    }
}
