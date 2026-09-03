<?php

namespace App\Support\Social;

use App\Models\SocialConnection;
use RuntimeException;
use Throwable;

/**
 * Who is connected, and how to let go of them properly.
 *
 * ── Why disconnecting tells Thumbtack ────────────────────────────
 *
 * Deleting our own row is the easy half and it is not the whole job. Until
 * 2026-09-03 disconnecting a Thumbtack account deleted EzBizi's tokens and left
 * everything on their side exactly as it was: the webhook still registered and
 * still firing at a URL that now refuses it, the associate phone numbers still
 * claiming that a departed company's handsets answer for this business, and the
 * grant still listed on the Pro's Thumbtack account as an app with access.
 *
 * `POST /v4/users/disconnect` revokes the tokens at their end, which is what
 * makes "disconnect" mean the same thing to the person clicking it as it does
 * to the Pro looking at their Thumbtack settings a week later.
 *
 * ── Best effort, and never a blocker ─────────────────────────────
 *
 * Every step here is allowed to fail without stopping the disconnection. The
 * reason is the state this exists to escape: an account whose token has already
 * expired, or whose business was removed on Thumbtack's side, cannot be told
 * anything — and that is exactly the account somebody is trying to disconnect.
 * Refusing to let go because the far end will not answer would trap somebody in
 * a broken connection with no way out but the database.
 *
 * So the local delete always happens, and what could not be tidied is reported
 * rather than thrown.
 */
final class ThumbtackAccount
{
    public function __construct(private readonly ThumbtackClient $client) {}

    public static function for(SocialConnection $connection): self
    {
        return new self(new ThumbtackClient($connection));
    }

    /**
     * The Thumbtack user this connection authenticates as.
     *
     * @return array<string, mixed>
     */
    public function self(): array
    {
        return $this->client->get('/v4/users/self');
    }

    /**
     * Revoke at Thumbtack's end.
     *
     * Separate from the controller's own delete so it can be called, fail, and
     * be reported without the delete depending on it.
     */
    public function revoke(): void
    {
        $this->client->post('/v4/users/disconnect');
    }

    /**
     * Everything that should be undone on Thumbtack before we forget them.
     *
     * @return string[] what could not be undone, in sentences, or empty
     */
    public static function tidyUp(SocialConnection $connection): array
    {
        $problems = [];

        // Order matters: numbers first, then the webhook, then the grant.
        // Revoking first would make the other two impossible, and the one that
        // most needs doing is the phone numbers — a stale number keeps
        // identifying somebody else's handset as this business indefinitely.
        $problems[] = self::attempt(
            'remove this business\'s phone numbers from Thumbtack',
            static function () use ($connection) {
                $api = new ThumbtackAssociatePhoneNumbers;

                foreach ($api->list($connection) as $row) {
                    $api->delete($connection, (string) $row['phoneNumberID']);
                }
            },
        );

        $problems[] = self::attempt(
            'remove the Thumbtack webhook',
            static function () use ($connection) {
                $webhooks = ThumbtackWebhooks::for($connection);

                foreach ($webhooks->all() as $row) {
                    $webhooks->remove((string) $row['webhookID']);
                }
            },
        );

        $problems[] = self::attempt(
            'revoke EzBizi\'s access on Thumbtack',
            static fn () => self::for($connection)->revoke(),
        );

        return array_values(array_filter($problems));
    }

    /** @param  callable(): void  $step */
    private static function attempt(string $what, callable $step): ?string
    {
        try {
            $step();

            return null;
        } catch (RuntimeException $e) {
            return 'EzBizi could not '.$what.': '.$e->getMessage();
        } catch (Throwable $e) {
            report($e);

            return 'EzBizi could not '.$what.'.';
        }
    }
}
