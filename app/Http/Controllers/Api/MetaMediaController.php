<?php

namespace App\Http\Controllers\Api;

use App\Models\SocialConnection;
use App\Support\Social\FacebookOAuth;
use App\Support\Social\SocialProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;

/**
 * A photo, video or file sent by Messenger or Instagram DM, streamed from
 * Meta on demand and never stored (Jose, 2026-10-27: no copies). Meta's CDN
 * links expire, so the row keeps the message id and this asks Meta for a
 * fresh link each time. Reached only by a short-lived signed URL.
 */
final class MetaMediaController
{
    /** What a thread row stores in `attachments`: never a URL, only this. */
    public const PREFIX = 'meta-media:';

    public function __invoke(string $connection, string $mid, int $index): Response
    {
        $c = SocialConnection::withoutGlobalScopes()->find($connection);
        $token = $c?->credentials['access_token'] ?? null;
        abort_unless(is_string($token) && $token !== '', 404);

        $base = $c->provider === SocialProvider::InstagramProfessional->value
            ? 'https://graph.instagram.com/v21.0/'
            : 'https://graph.facebook.com/'.FacebookOAuth::GRAPH_VERSION.'/';

        try {
            $a = Http::timeout(15)->get($base.self::decode($mid), ['fields' => 'attachments', 'access_token' => $token])
                ->json('attachments.data.'.$index);
            $src = $a['image_data']['url'] ?? $a['video_data']['url'] ?? $a['file_url'] ?? null;
            abort_unless(is_string($src), 404);
            $file = Http::timeout(30)->get($src);
        } catch (ConnectionException) {
            abort(404);
        }
        abort_unless($file->successful(), 404);

        return response($file->body())
            ->header('Content-Type', $file->header('Content-Type') ?: 'application/octet-stream')
            ->header('Cache-Control', 'private, no-store');
    }

    /** One ref per attachment in a Messenger/Instagram message, or null when there are none. */
    public static function refs(SocialConnection $connection, string $mid, array $message): ?array
    {
        $refs = [];
        foreach (array_values((array) ($message['attachments'] ?? [])) as $i => $a) {
            $kind = in_array($a['type'] ?? null, ['image', 'video', 'audio'], true) ? $a['type'] : 'file';
            $refs[] = self::PREFIX.$connection->getKey().':'.rtrim(strtr(base64_encode($mid), '+/', '-_'), '=').':'.$i.':'.$kind;
        }

        return $refs ?: null;
    }

    /** A stored ref as the link the client opens (?kind=image|video|audio|file); anything else unchanged. */
    public static function url(string $attachment): string
    {
        if (! str_starts_with($attachment, self::PREFIX)) {
            return $attachment;
        }

        [$connection, $mid, $index, $kind] = array_pad(explode(':', substr($attachment, strlen(self::PREFIX)), 4), 4, 'file');

        return URL::temporarySignedRoute('meta.media', now()->addMinutes(30), compact('connection', 'mid', 'index', 'kind'));
    }

    private static function decode(string $mid): string
    {
        return (string) base64_decode(strtr($mid, '-_', '+/'));
    }
}
