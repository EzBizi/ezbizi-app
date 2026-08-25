<?php

namespace App\Support\Phone;

use App\Models\User;

/**
 * Voice SDK identity: alphanumeric and underscore only (strip hyphens from UUID).
 */
final class ClientIdentity
{
    public static function of(User $user): string
    {
        return preg_replace('/[^A-Za-z0-9_]/', '', str_replace('-', '', (string) $user->id)) ?? '';
    }
}
