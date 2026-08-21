<?php

namespace App\Support\Email;

/**
 * Bytes of a picture that was in a message, plus the type the provider claimed.
 *
 * Never stored. Produced by a fetch (remote, CID, or a data URI) and handed
 * straight to the response. The type is an allowlisted image MIME, already
 * judged — a caller must not re-parse the sender's Content-Type.
 */
final class EmailPicture
{
    public function __construct(
        public readonly string $bytes,
        public readonly string $mime,
    ) {}
}
