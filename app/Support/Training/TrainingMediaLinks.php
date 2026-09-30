<?php

namespace App\Support\Training;

use App\Models\TrainingGuide;
use App\Models\TrainingMedia;
use Illuminate\Support\Facades\URL;

/**
 * Turns a guide's stored topics into what a reader is handed: every topic as
 * ordered blocks (GuideScreens::blocks), each image the guide holds as a
 * signed link, each Vimeo/YouTube video with the EzBizi page that plays it.
 *
 * Called by the two paths that hand screens to a person — the author's guide
 * read and the learner's — AFTER each has authorised that person to read the
 * guide. The signature is the only thing the media route checks, so issuing
 * it is where the authorisation lives.
 *
 * `media_id` itself is returned too, because the builder has to send it back
 * on save (see GuideScreens::normalize). It grants nothing on its own: without
 * a signature the route refuses, and the id is not guessable.
 *
 * ── Why the link expires, and why this long ─────────────────────
 *
 * A signed URL is a bearer capability until it expires, so it must. Two hours
 * covers somebody working through a long guide in one sitting; the guide is
 * re-read — and re-signed — every time it is opened.
 *
 * ── `body` / `media_kind` / `media_url` ─────────────────────────
 *
 * The one-text-one-media shape clients before 0.0.0325 read. Projected from
 * the blocks so an older client still shows something; the new client reads
 * `blocks` only.
 */
final class TrainingMediaLinks
{
    public const LIFETIME_MINUTES = 120;

    /**
     * @param  array<int, array<string, mixed>>  $screens
     * @return array<int, array<string, mixed>>
     */
    public static function resolve(array $screens, TrainingGuide $guide): array
    {
        $topics = array_map(fn (array $s) => GuideScreens::topic($s), $screens);

        $ids = [];
        foreach ($topics as $topic) {
            foreach ($topic['blocks'] as $block) {
                if (($block['media_id'] ?? null) !== null) {
                    $ids[] = $block['media_id'];
                }
            }
        }

        // Owned by THIS guide only. A reference to another guide's media — or
        // another tenant's — resolves to nothing rather than to a link.
        $owned = $ids === [] ? collect() : TrainingMedia::query()
            ->where('guide_id', $guide->getKey())
            ->whereIn('id', $ids)
            ->pluck('id')
            ->flip();

        $expires = now()->addMinutes(self::LIFETIME_MINUTES);

        return array_map(function (array $topic) use ($owned, $expires) {
            $topic['blocks'] = array_map(function (array $block) use ($owned, $expires) {
                if ($block['type'] === 'image' && ($block['media_id'] ?? null) !== null) {
                    $block['url'] = $owned->has($block['media_id'])
                        ? URL::temporarySignedRoute('training.media', $expires, ['media' => $block['media_id']])
                        : null;
                }

                if ($block['type'] === 'video') {
                    $block['embed_url'] = GuideScreens::embedUrl($block['url'] ?? null);
                }

                return $block;
            }, $topic['blocks']);

            $texts = array_filter(array_map(fn ($b) => $b['type'] === 'text' ? $b['text'] : null, $topic['blocks']));
            $media = collect($topic['blocks'])->first(fn ($b) => in_array($b['type'], ['image', 'video'], true) && ($b['url'] ?? null) !== null);

            return $topic + [
                'body' => implode("\n\n", $texts),
                'media_kind' => $media['type'] ?? 'none',
                'media_url' => $media['url'] ?? null,
            ];
        }, $topics);
    }
}
