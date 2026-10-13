<?php

namespace App\Support\Training;

use App\Models\TrainingGuide;
use App\Models\TrainingMedia;
use Illuminate\Validation\ValidationException;

/**
 * A guide's topics — what `training_guides.screens` holds.
 *
 * A topic is A1 Tools' shape: a title, the chapter and section it sits under,
 * and its content as ordered blocks, as many as the page needs:
 *
 *   {title, chapter, section, blocks: [
 *     {type: text,  text},
 *     {type: image, media_id}      an image the guide holds (TrainingMedia)
 *     {type: image, url}           or one it links to
 *     {type: video, url}           Vimeo / YouTube / any http(s) link
 *     {type: table, rows: [[cell, …], …]}   first row is the header
 *   ]}
 *
 * Topics written before blocks existed carry `body`, `media_kind`,
 * `media_url`, `media_id` instead; [topic] reads them as blocks, so nothing
 * stored has to be rewritten.
 */
final class GuideScreens
{
    public const TYPES = ['text', 'image', 'video', 'table'];

    /**
     * One stored topic as blocks, whichever shape it was stored in.
     *
     * @param  array<string, mixed>  $screen
     * @return array{title: string, chapter: ?string, section: ?string, blocks: list<array<string, mixed>>}
     */
    public static function topic(array $screen): array
    {
        return [
            'title' => (string) ($screen['title'] ?? ''),
            'chapter' => self::label($screen['chapter'] ?? null),
            'section' => self::label($screen['section'] ?? null),
            'blocks' => array_key_exists('blocks', $screen) ? array_values((array) $screen['blocks']) : self::legacyBlocks($screen),
        ];
    }

    /**
     * What a write stores: known keys only, `media_id` checked against THIS
     * guide, and never the signed link the client was handed on read.
     *
     * The id must belong to this guide, and is REFUSED otherwise rather than
     * dropped: a topic quietly losing its picture is worse than a sentence
     * saying why. A new guide owns no media yet, so on create any `media_id`
     * is refused.
     *
     * A save from a client older than blocks (it sends `body`/`media_*`, no
     * `blocks`) would flatten every topic to one text and one picture. So on a
     * guide that already holds blocks, such a save is refused.
     *
     * @param  array<int, array<string, mixed>>  $screens
     * @return list<array<string, mixed>>
     */
    public static function normalize(array $screens, ?TrainingGuide $guide): array
    {
        $legacyWrite = collect($screens)->contains(fn ($s) => ! array_key_exists('blocks', $s));

        if ($legacyWrite && $guide !== null && collect($guide->screens ?? [])->contains(fn ($s) => array_key_exists('blocks', (array) $s))) {
            throw ValidationException::withMessages([
                'screens' => 'Update EzBizi to edit this guide: this version would lose part of its content.',
            ]);
        }

        $topics = array_map(fn (array $s) => self::topic($s), $screens);

        $referenced = [];
        foreach ($topics as $topic) {
            foreach ($topic['blocks'] as $block) {
                if (($block['media_id'] ?? null) !== null) {
                    $referenced[] = $block['media_id'];
                }
            }
        }

        if ($referenced !== []) {
            $owned = $guide === null ? [] : TrainingMedia::query()
                ->where('guide_id', $guide->getKey())
                ->whereIn('id', $referenced)
                ->pluck('id')
                ->all();

            if (array_diff($referenced, $owned) !== []) {
                throw ValidationException::withMessages([
                    'screens' => 'A topic points at an image that does not belong to this guide.',
                ]);
            }
        }

        return array_map(fn (array $topic) => [
            'title' => $topic['title'],
            'chapter' => $topic['chapter'],
            'section' => $topic['section'],
            'blocks' => array_values(array_filter(array_map(fn ($b) => self::block((array) $b), $topic['blocks']))),
        ], $topics);
    }

    /**
     * A guide's topics, optionally only those under one chapter (and section),
     * in order — what the WhatsApp staff bot's curated menus read.
     *
     * @return list<array{title: string, chapter: ?string, section: ?string, topic: array<string, mixed>}>
     */
    public static function topics(TrainingGuide $guide, ?string $chapter = null, ?string $section = null): array
    {
        $out = [];

        foreach ($guide->screens ?? [] as $screen) {
            $topic = self::topic((array) $screen);

            if (($chapter !== null && $topic['chapter'] !== $chapter) || ($section !== null && $topic['section'] !== $section)) {
                continue;
            }

            $out[] = ['title' => $topic['title'], 'chapter' => $topic['chapter'], 'section' => $topic['section'], 'topic' => $topic];
        }

        return $out;
    }

    /**
     * One topic as plain text for a chat: text blocks, tables as "a · b · c"
     * lines, each video's public link on its own line. Images are skipped:
     * a held image's link is signed and dies in two hours.
     *
     * @param  array<string, mixed>  $topic  a topic as [topic] returns it
     */
    public static function plainText(array $topic): string
    {
        $parts = [];

        foreach ($topic['blocks'] ?? [] as $block) {
            $parts[] = match ($block['type'] ?? null) {
                'text' => trim((string) ($block['text'] ?? '')),
                'table' => implode('
', array_map(fn ($row) => implode(' · ', (array) $row), (array) ($block['rows'] ?? []))),
                'video' => (string) ($block['url'] ?? ''),
                default => '',
            };
        }

        return implode('

', array_filter($parts, fn ($p) => $p !== ''));
    }

    /**
     * The EzBizi page that plays a Vimeo or YouTube link, or null for any
     * other link.
     *
     * A1 Tools found its WebView refused a bare `player.vimeo.com` page and
     * played the video inside an iframe on a real domain; VideoController
     * serves that page.
     */
    public static function embedUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);

        if (str_ends_with($host, 'vimeo.com') && preg_match('~/(\d{5,})(?:/([0-9a-fA-F]{6,32}))?(?:/|$)~', $path, $m)) {
            // An unlisted video's hash: `?h=` on a player link, or the path
            // segment after the id on a vimeo.com link.
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $hash = strtolower((string) ($query['h'] ?? ($m[2] ?? '')));

            return route('training.video', ['platform' => 'vimeo', 'id' => $m[1]] + (self::isVimeoHash($hash) ? ['h' => $hash] : []));
        }

        $youtube = null;

        if ($host === 'youtu.be') {
            $youtube = ltrim($path, '/');
        } elseif (str_ends_with($host, 'youtube.com') || str_ends_with($host, 'youtube-nocookie.com')) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $youtube = $query['v'] ?? (preg_match('~/(?:embed|shorts|live)/([^/?]+)~', $path, $m) ? $m[1] : null);
        }

        return is_string($youtube) && preg_match('/^[A-Za-z0-9_-]{6,20}$/', $youtube)
            ? route('training.video', ['platform' => 'youtube', 'id' => $youtube])
            : null;
    }

    public static function isVimeoHash(string $hash): bool
    {
        return preg_match('/^[0-9a-f]{6,32}$/', $hash) === 1;
    }

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        $httpUrl = function (string $attribute, mixed $value, \Closure $fail): void {
            if ($value === null || $value === '') {
                return;
            }

            // parse_url() does not lowercase the scheme; Dart's Uri does.
            $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));

            if (! in_array($scheme, ['http', 'https'], true)) {
                $fail('The media link must be a plain http or https URL.');
            }
        };

        return [
            'screens' => ['sometimes', 'array', 'max:200'],
            'screens.*.title' => ['required', 'string', 'max:200'],
            'screens.*.chapter' => ['sometimes', 'nullable', 'string', 'max:200'],
            'screens.*.section' => ['sometimes', 'nullable', 'string', 'max:200'],
            'screens.*.blocks' => ['sometimes', 'array', 'max:100'],
            'screens.*.blocks.*.type' => ['required', 'string', 'in:'.implode(',', self::TYPES)],
            'screens.*.blocks.*.text' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'screens.*.blocks.*.url' => ['sometimes', 'nullable', 'string', 'max:2000', $httpUrl],
            'screens.*.blocks.*.media_id' => ['sometimes', 'nullable', 'uuid'],
            'screens.*.blocks.*.rows' => ['sometimes', 'nullable', 'array', 'max:200'],
            'screens.*.blocks.*.rows.*' => ['array', 'max:20'],
            'screens.*.blocks.*.rows.*.*' => ['nullable', 'string', 'max:1000'],
            // The shape before blocks, still sent by older clients.
            'screens.*.body' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'screens.*.media_kind' => ['sometimes', 'string', 'in:none,image,video'],
            'screens.*.media_id' => ['sometimes', 'nullable', 'uuid'],
            'screens.*.media_url' => ['sometimes', 'nullable', 'string', 'max:2000', $httpUrl],
        ];
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>|null
     */
    private static function block(array $block): ?array
    {
        return match ($block['type'] ?? null) {
            'text' => trim((string) ($block['text'] ?? '')) === '' ? null : ['type' => 'text', 'text' => (string) $block['text']],
            // A held image stores its id, never the signed link it was read with.
            'image' => ($block['media_id'] ?? null) !== null
                ? ['type' => 'image', 'media_id' => $block['media_id']]
                : (($block['url'] ?? '') === '' ? null : ['type' => 'image', 'url' => $block['url']]),
            'video' => ($block['url'] ?? '') === '' ? null : ['type' => 'video', 'url' => $block['url']],
            'table' => ($block['rows'] ?? []) === [] ? null : ['type' => 'table', 'rows' => array_map(
                fn ($row) => array_map(fn ($cell) => (string) $cell, array_values((array) $row)),
                array_values((array) $block['rows']),
            )],
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $screen
     * @return list<array<string, mixed>>
     */
    private static function legacyBlocks(array $screen): array
    {
        $blocks = [];
        $mediaId = $screen['media_id'] ?? null;
        $url = $screen['media_url'] ?? null;
        $kind = $mediaId !== null ? 'image' : ($screen['media_kind'] ?? 'none');

        // The old reader drew the media above the text.
        if ($kind === 'image' && ($mediaId !== null || ($url ?? '') !== '')) {
            $blocks[] = $mediaId !== null ? ['type' => 'image', 'media_id' => $mediaId] : ['type' => 'image', 'url' => $url];
        } elseif ($kind === 'video' && ($url ?? '') !== '') {
            $blocks[] = ['type' => 'video', 'url' => $url];
        }

        if (trim((string) ($screen['body'] ?? '')) !== '') {
            $blocks[] = ['type' => 'text', 'text' => (string) $screen['body']];
        }

        return $blocks;
    }

    private static function label(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
