<?php

namespace App\Support\Social\Publishing;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * A tenant's media, briefly fetchable by a network that will not take bytes.
 *
 * ── Why this exists at all ──────────────────────────────────────
 *
 * Instagram's Content Publishing API has no upload. There is no multipart, no
 * base64, no chunked PUT: a container is created from an `image_url` or a
 * `video_url` and INSTAGRAM FETCHES IT, anonymously, from wherever that points.
 * The same is true of a Threads container carrying media, and it is the cheap
 * path for a Facebook video. Nothing EzBizi can do changes that; the choice is
 * to serve the file for a moment or to not support the networks.
 *
 * That was a real decision and it was Jose's to make, not something to code
 * around. It was made on 2026-09-01: publish to Instagram, accept the handoff.
 * Everything below is the narrowest possible shape of the yes.
 *
 * ── What "briefly" is made of ───────────────────────────────────
 *
 * Four things, and each one is load-bearing on its own:
 *
 *   A RANDOM NAME. A ULID, not the post id, not the tenant's filename. Nothing
 *   about the URL says who it belongs to and no two are adjacent, so a guessed
 *   URL is not a neighbour of a real one.
 *
 *   A SIGNATURE. The route refuses anything this app did not sign, so the name
 *   being random is not the only thing standing between a stranger and the
 *   file. Guessing the name is not enough; you would have to forge the app key.
 *
 *   AN EXPIRY. Signed for an hour. Instagram's video ingestion is genuinely
 *   slow and a reel that dies half-fetched is worse than an hour of exposure,
 *   which is why this is not five minutes.
 *
 *   A DELETE. `release()` in the publisher's `finally`, so the window is
 *   normally seconds rather than the hour it is signed for. The hour is the
 *   ceiling, not the plan. `ezbizi:expire-social-handoffs` sweeps whatever a
 *   crash left behind, because a `finally` does not run when the process dies.
 *
 * ── Why a copy, and not the original ────────────────────────────
 *
 * The tenant's own media sits under a path a signed URL could just as well
 * point at, and pointing at it would be one less copy. It would also mean the
 * one URL Meta holds is the one that addresses the real file forever, and an
 * expiry is a much weaker promise than a deleted file. The copy is what lets
 * `release()` be a delete rather than a hope.
 */
final class MediaHandoff
{
    /** The private disk. Nothing here is web-reachable except through the signed route. */
    private const DISK = 'local';

    private const PREFIX = 'social-handoff';

    /** Signed for this long. See "AN EXPIRY" above for why it is not shorter. */
    public const MINUTES = 60;

    private function __construct(
        public readonly string $handle,
        public readonly string $url,
    ) {}

    /**
     * Put the payload's bytes somewhere the network can fetch them from.
     *
     * The caller MUST `release()` it, and from a `finally` — a network that
     * refuses the post still leaves the file sitting there otherwise.
     */
    public static function hold(PostPayload $post): self
    {
        $extension = strtolower(pathinfo((string) $post->filePath, PATHINFO_EXTENSION));
        $handle = (string) Str::ulid().($extension === '' ? '' : '.'.$extension);

        Storage::disk(self::DISK)->put(self::PREFIX.'/'.$handle, $post->bytes());

        $url = URL::temporarySignedRoute(
            'social.media',
            now()->addMinutes(self::MINUTES),
            ['handle' => $handle],
        );

        self::refuseUnreachable($url);

        return new self($handle, $url);
    }

    /** A handoff held by an earlier job, to release once Instagram is done with it. */
    public static function forHandle(string $handle): self
    {
        return new self($handle, '');
    }

    public function release(): void
    {
        Storage::disk(self::DISK)->delete(self::PREFIX.'/'.$this->handle);
    }

    /** @return string the path on the private disk, for the route that serves it */
    public static function pathFor(string $handle): string
    {
        return self::PREFIX.'/'.$handle;
    }

    /** What to serve it back as. One map, in PostPayload, read from both ends. */
    public static function mimeFor(string $handle): string
    {
        return PostPayload::mimeForExtension(pathinfo($handle, PATHINFO_EXTENSION));
    }

    /**
     * Delete handoffs older than the window they were signed for.
     *
     * Belt and braces for the crash case. Anything this finds is already
     * useless — its signature expired — so there is nothing to weigh up.
     *
     * @return int how many were swept
     */
    public static function sweep(): int
    {
        $disk = Storage::disk(self::DISK);
        $cutoff = now()->subMinutes(self::MINUTES + 10)->getTimestamp();
        $swept = 0;

        foreach ($disk->files(self::PREFIX) as $file) {
            if ($disk->lastModified($file) < $cutoff) {
                $disk->delete($file);
                $swept++;
            }
        }

        return $swept;
    }

    /**
     * Refuse a URL that should not be handed to a network, and say why.
     *
     * A signed URL is built from APP_URL, and there are two ways for that to be
     * wrong in a way nothing else would catch.
     *
     * NOT REACHABLE. It ships as http://localhost, and every .env starts life
     * that way. The handoff becomes a link to the server's own loopback: Meta
     * fetches it, reaches nothing, and reports a media error — so the sentence
     * the tenant reads blames their picture for a line in a config file.
     *
     * NOT HTTPS. An http handoff is a tenant's photo crossing the internet in
     * the clear, to a URL whose whole security is that it is unguessable and
     * signed — both of which are in the query string an eavesdropper reads.
     * There is no version of that worth doing to save a certificate.
     *
     * Both are refused here rather than at the network, because the network's
     * version of this error names the media instead of the cause.
     */
    private static function refuseUnreachable(string $url): void
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $scheme = (string) parse_url($url, PHP_URL_SCHEME);

        $unreachable = $host === ''
            || $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.test')
            // Any literal IP that is private, reserved or loopback. A public IP
            // is unusual but not wrong, so it is left alone.
            || (filter_var($host, FILTER_VALIDATE_IP) !== false
                && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false);

        if ($unreachable) {
            throw new SocialPublishFailed(
                'This post needs a picture or video the network can fetch, and this server '
                .'is publishing links to '.($host === '' ? '(nowhere)' : $host).', which it '
                .'cannot reach. APP_URL needs to be the address the internet uses.',
            );
        }

        if ($scheme !== 'https') {
            throw new SocialPublishFailed(
                'This post needs a picture or video the network can fetch, and this server '
                .'is publishing '.($scheme === '' ? 'unschemed' : $scheme).' links. Media is '
                .'not handed over unencrypted. APP_URL needs to be https.',
            );
        }
    }
}
