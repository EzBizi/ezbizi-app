<?php

namespace App\Support\Social\Publishing;

use RuntimeException;

/**
 * A send that did not happen, with a sentence a person can act on.
 *
 * The network's own error text is kept when there is one — "the video is too
 * long" is worth more than "publish failed", and a support conversation that
 * starts with the network's wording ends sooner.
 */
class SocialPublishFailed extends RuntimeException
{
    public static function network(string $network, string $why): self
    {
        return new self($network.' refused it: '.$why);
    }

    public static function notImplemented(string $network, string $kind): self
    {
        return new self(sprintf(
            'EzBizi cannot post %s to %s yet. The connection is fine; the poster is not built.',
            strtolower($kind),
            $network,
        ));
    }
}
