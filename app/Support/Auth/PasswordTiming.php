<?php

namespace App\Support\Auth;

use Illuminate\Support\Facades\Hash;

/**
 * A dummy bcrypt check so a miss costs the same as a hit.
 *
 * The previous pattern was `Hash::check($password, Hash::make('…'))` on every
 * miss. `Hash::make` is a full bcrypt, so unknown emails were slower than known
 * ones with a wrong password — the opposite of equalisation, and an oracle by
 * timing.
 *
 * The dummy hash is built once per process and reused. The check still runs at
 * the same cost as a real password; only the extra `make` is gone.
 */
final class PasswordTiming
{
    private static ?string $dummy = null;

    public static function equalise(string $password): void
    {
        Hash::check($password, self::hash());
    }

    public static function hash(): string
    {
        return self::$dummy ??= Hash::make('timing-equalisation');
    }
}
