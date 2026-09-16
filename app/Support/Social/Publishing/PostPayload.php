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
    ) {}

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
     * The MIME type for a file extension.
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
     * THE DEFAULT IS STILL WRONG FOR TWO LIVE CASES and is left alone
     * deliberately rather than fixed here. `heic` and `heif` are accepted
     * uploads - they are in SocialMediaKind::BY_EXTENSION, so the composer's
     * `mimes` rule lets them through - and neither is in this map, so both are
     * currently served as `video/mp4`. `m4v` likewise. The right fix is to name
     * every accepted extension and make the default REFUSE, since a confident
     * wrong type is worse than a failure, but `mp4` itself relies on that
     * default today, so the change has to enumerate before it can refuse. That
     * belongs to whoever owns publishing rather than to a WhatsApp errand.
     */
    public static function mimeForExtension(string $extension): string
    {
        return match (strtolower($extension)) {
            'png'         => 'image/png',
            'webp'        => 'image/webp',
            'gif'         => 'image/gif',
            'jpg', 'jpeg' => 'image/jpeg',
            'mov'         => 'video/quicktime',
            'webm'        => 'video/webm',
            'pdf'         => 'application/pdf',
            default       => 'video/mp4',
        };
    }
}
