<?php

namespace App\Support\Auth;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\RateLimiter;

/** A different tenant's successful password must never reset this factor's budget. */
final class FactorAttempts
{
    public function verify(Model $account, string $code, callable $verify): bool
    {
        if (trim($code) === '') {
            return false;
        }
        $key = 'auth-factor:'.$account->getMorphClass().':'.$account->getKey();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw new HttpResponseException(response()->json([
                'message' => 'Too many attempts. Try again shortly.',
                'code' => 'throttled',
                'retry_after' => RateLimiter::availableIn($key),
            ], 429));
        }
        if (! $verify()) {
            RateLimiter::hit($key, 300);

            return false;
        }
        RateLimiter::clear($key);

        return true;
    }
}
