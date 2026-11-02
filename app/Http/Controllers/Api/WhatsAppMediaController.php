<?php

namespace App\Http\Controllers\Api;

use App\Models\WhatsAppNumber;
use App\Support\Social\WhatsAppCloud;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;
use RuntimeException;

/**
 * A WhatsApp voice note, image, video or document, streamed from Meta on
 * demand and never stored (Jose, 2026-10-27: no copies of recordings).
 *
 * Reached only by a short-lived signed URL the thread API hands out, because
 * a player or an image cannot send our bearer token. The phone number id
 * picks the token; an unknown number is 404.
 */
final class WhatsAppMediaController
{
    /** What a thread row stores in `attachments`: never a URL, only this. */
    public const PREFIX = 'wa-media:';

    public function __invoke(string $phone, string $media, WhatsAppCloud $cloud): Response
    {
        abort_unless(WhatsAppNumber::withoutGlobalScopes()->where('phone_number_id', $phone)->exists(), 404);

        try {
            $file = $cloud->downloadMedia($media, $phone);
        } catch (RuntimeException) {
            abort(404);
        }

        return response($file['bytes'])
            ->header('Content-Type', $file['mime'] ?: 'application/octet-stream')
            ->header('Cache-Control', 'private, no-store');
    }

    public static function ref(string $phone, string $media, string $kind): string
    {
        return self::PREFIX.$phone.':'.$media.':'.$kind;
    }

    /** A stored ref as the link the client opens (?kind=audio|image|video|document|sticker); anything else unchanged. */
    public static function url(string $attachment): string
    {
        if (! str_starts_with($attachment, self::PREFIX)) {
            return $attachment;
        }

        [$phone, $media, $kind] = array_pad(explode(':', substr($attachment, strlen(self::PREFIX)), 3), 3, 'file');

        return URL::temporarySignedRoute('whatsapp.media', now()->addMinutes(30), compact('phone', 'media', 'kind'));
    }
}
