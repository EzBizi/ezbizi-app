<?php

namespace App\Support\Newsletter;

use App\Models\Newsletter;
use App\Models\SocialConnection;

/**
 * Puts one newsletter in front of one audience, through one connected account.
 *
 * One implementation per provider, because "send a mailing" genuinely differs:
 * Mailchimp wants a campaign created, filled and then sent as three calls
 * against a list it already holds. A single method with branches would hide
 * that; small classes admit it — the same argument `SocialPublisher` makes.
 */
interface NewsletterSender
{
    /**
     * @param  array<int, array{email: string, first_name?: string, last_name?: string}>  $people
     *         the EzBizi-side audience, already filtered for unsubscribes. Empty
     *         when the audience lives at the provider and we hold no addresses.
     * @return array{remote_id: string|null, recipients: int}
     */
    public function send(
        SocialConnection $connection,
        Newsletter $newsletter,
        string $html,
        array $people,
    ): array;
}
