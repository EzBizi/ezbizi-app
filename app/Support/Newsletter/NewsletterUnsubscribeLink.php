<?php

namespace App\Support\Newsletter;

use App\Models\Newsletter;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/** A recipient proof retains its scope even if the original mailing is deleted. */
final class NewsletterUnsubscribeLink
{
    public static function for(Newsletter $newsletter, string $email): string
    {
        $proof = Crypt::encryptString(json_encode([
            'newsletter' => $newsletter->id,
            'organization' => $newsletter->organization_id,
            'scope_type' => $newsletter->scope_type,
            'scope_id' => $newsletter->scope_id,
            'email' => mb_strtolower(trim($email)),
        ], JSON_THROW_ON_ERROR));

        return url('/api/v1/newsletter/unsubscribe/'.$newsletter->id).'?'.http_build_query(['proof' => $proof]);
    }

    public static function read(string $proof, string $newsletter): array
    {
        try {
            $payload = json_decode(Crypt::decryptString($proof), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($payload) || ($payload['newsletter'] ?? null) !== $newsletter
                || ! filter_var($payload['email'] ?? '', FILTER_VALIDATE_EMAIL)
                || ! in_array($payload['scope_type'] ?? null, ['organization', 'company', 'franchise'], true)
                || ! is_string($payload['scope_id'] ?? null) || ! is_string($payload['organization'] ?? null)) {
                abort(403, 'This unsubscribe link is not valid. Use the link in your email.');
            }

            return $payload;
        } catch (DecryptException|\JsonException $e) {
            abort(403, 'This unsubscribe link is not valid. Use the link in your email.');
        }
    }
}
