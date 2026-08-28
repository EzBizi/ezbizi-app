<?php

namespace App\Support\Social\Publishing;

use App\Support\Social\SocialProvider;

/**
 * Which networks EzBizi can actually send to.
 *
 * ── Why this is not a `match` in the caller ──────────────────────
 *
 * There are two callers — `social:post-test` and the publish job — and there
 * will be one more each time a network is added. A copy of the map in each of
 * them is a list that goes stale silently: the day LinkedIn lands, the command
 * posts and the composer says "not wired yet", and nothing fails.
 *
 * It answers `null` rather than throwing, because "no publisher for this yet"
 * is an ordinary state this module is in for four of its nine connectable
 * networks, and the caller has a better sentence for it than an exception does.
 */
final class Publishers
{
    public static function for(?SocialProvider $provider, bool $toDrafts = false): ?SocialPublisher
    {
        return match ($provider) {
            SocialProvider::Pinterest => new PinterestPublisher,
            SocialProvider::TikTok    => new TikTokPublisher(toDrafts: $toDrafts),
            default                   => null,
        };
    }

    /**
     * Why this account cannot be posted to, in a sentence, or null if it can.
     *
     * The sentence is the server's, not the client's (§4): a screen that wrote
     * its own would be a second list of which networks work, and the two would
     * disagree the day this map grows.
     */
    public static function refusalFor(?SocialProvider $provider): ?string
    {
        if (self::for($provider) !== null) {
            return null;
        }

        return $provider === null
            ? 'EzBizi does not recognise that account’s network, so it cannot post to it.'
            : 'Publishing to '.$provider->label().' is not built yet. The account is '
              .'connected, and posting to it will work without reconnecting.';
    }
}
