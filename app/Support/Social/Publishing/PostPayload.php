<?php

namespace App\Support\Social\Publishing;

use App\Support\Social\SocialMediaKind;

/**
 * One thing to send: some words, and at most one file.
 *
 * Deliberately not a list of files. Every network in the first wave takes a
 * single asset per post, and the ones that take several — a Facebook album, an
 * Instagram carousel — each do it differently enough that pretending otherwise
 * now would mean unpicking it later. One asset, honestly.
 */
final readonly class PostPayload
{
    public function __construct(
        public string $body = '',
        public ?string $filePath = null,
        public ?string $title = null,
        /** @var array<string, mixed> what the person chose for this one account (TikTok's privacy etc.) */
        public array $options = [],
    ) {}

    /** @param  array<string, mixed>  $options */
    public function withOptions(array $options): self
    {
        return new self($this->body, $this->filePath, $this->title, $options);
    }

    public function kind(): SocialMediaKind
    {
        if ($this->filePath === null) {
            return SocialMediaKind::Text;
        }

        return SocialMediaKind::forFile($this->filePath) ?? SocialMediaKind::Text;
    }

    public function bytes(): string
    {
        if ($this->filePath === null || ! is_file($this->filePath)) {
            throw new SocialPublishFailed('There is no file at '.($this->filePath ?? '(none)').'.');
        }

        return (string) file_get_contents($this->filePath);
    }

    public function mime(): string
    {
        return self::mimeForExtension(pathinfo((string) $this->filePath, PATHINFO_EXTENSION));
    }

    /**
     * The MIME type for a file extension, or a refusal.
     *
     * Static as well as instance, because MediaHandoff serves these bytes back
     * over HTTP later and has a handle rather than a payload. Meta reads the
     * type off the response headers, so the two answers have to be the same
     * answer - a second copy of this map is a second copy that drifts.
     *
     * `pdf` is here for WhatsApp, which takes a document by link and reads the
     * type off these same headers. Served as the default it arrived as a
     * broken attachment with no error at either end.
     *
     * EVERY ACCEPTED EXTENSION IS NAMED AND THE DEFAULT REFUSES, because that
     * same silent-breakage is what a guessed type buys. The default used to be
     * `video/mp4`, and `heic`, `heif` and `m4v` all fell into it while being
     * accepted uploads - they are in SocialMediaKind::BY_EXTENSION, which is
     * what the composer's `mimes` rule is built from - so an iPhone photo was
     * handed to Instagram as a video. Keep this list and that one in step: an
     * extension the composer accepts and this does not now fails loudly, which
     * is the trade on purpose.
     *
     * `m4v` is `video/mp4` on purpose rather than by omission - it is the same
     * container under another extension, so that is the honest answer.
     *
     * @throws SocialPublishFailed when the extension is not one we can name
     */
    public static function mimeForExtension(string $extension): string
    {
        return match (strtolower($extension)) {
            'png'          => 'image/png',
            'webp'         => 'image/webp',
            'gif'          => 'image/gif',
            'jpg', 'jpeg'  => 'image/jpeg',
            'heic'         => 'image/heic',
            'heif'         => 'image/heif',
            'mp4', 'm4v'   => 'video/mp4',
            'mov'          => 'video/quicktime',
            'webm'         => 'video/webm',
            'pdf'          => 'application/pdf',
            default        => throw new SocialPublishFailed(
                'EzBizi does not know what kind of file a .'.strtolower($extension).' is, '
                .'so it will not guess at one a network might reject or mangle.'
            ),
        };
    }
}
