<?php

namespace App\Support\Social;

use App\Models\NewsletterAudience;
use App\Models\SocialConnection;
use App\Support\Newsletter\MailchimpClient;
use App\Support\Social\Liveness\ConnectionLiveness;
use App\Support\Social\Liveness\Liveness;
use App\Support\Social\Publishing\PinterestPublisher;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Everything Connect does after the token is stored (Jose, 2026-11-12: "doing
 * a connection should take care of the whole process, I should not have to
 * ask you").
 *
 *  1. CHOICES. Each choice the account needs is made with a sensible default
 *     and kept with its options, so the card can offer the rest inline and
 *     nothing is left NULL: Pinterest's board (a Pin must live on one),
 *     Mailchimp's audience (a newsletter must go to one). Which company or
 *     branch owns it is the view Connect was started in; which Facebook Pages
 *     and Google listings come is asked by the provider's own consent screen.
 *  2. PROVIDER SETUP stays where it was (SocialAuthController::store queues
 *     the Meta subscription, the Business Profile sync, Thumbtack's webhook).
 *  3. THE CHECK. ConnectionLiveness runs once, right away, and its verdict is
 *     recorded exactly as the nightly ezbizi:check-connection-health records
 *     it; check() below says whether the scopes that posting needs were
 *     granted and how long the token lasts. The card shows both.
 */
final class ConnectSetup
{
    /**
     * The scopes without which posting is refused, per network. A network
     * missing here (Mailchimp's API key, the data sources) posts with
     * whatever it was given, so there is nothing to check.
     *
     * @var array<string, string[]>
     */
    public const POST_SCOPES = [
        'facebook_page' => ['pages_manage_posts'],
        'instagram_professional' => ['instagram_business_content_publish'],
        'pinterest' => [PinterestOAuth::SCOPE_BOARDS_READ, PinterestOAuth::SCOPE_PINS_WRITE],
        'tiktok' => ['video.publish'],
        // media.write: without it X refuses the first image post (XPublisher).
        'x' => ['tweet.write', 'media.write'],
        'linkedin' => ['w_member_social'],
        'youtube' => [YouTubeOAuth::SCOPE_UPLOAD],
        'google_business_profile' => ['https://www.googleapis.com/auth/business.manage'],
    ];

    /**
     * Networks whose stored token does not run out on its own: a Page token
     * from a long-lived login, and Mailchimp's API key. The `expires_at` a
     * Page row carries is the person's login token, not the Page's.
     */
    private const NEVER_EXPIRES = ['facebook_page', 'mailchimp'];

    /** Long-lived tokens EzBizi renews before they die (RenewMetaLoginTokens). */
    private const RENEWED_BY_EZBIZI = ['instagram_professional', 'threads'];

    public function __construct(private readonly ConnectionLiveness $liveness) {}

    /**
     * Make each row's choices, then run the check once for the grant they
     * share and record it on all of them. Never throws: the rows are saved.
     *
     * @param  SocialConnection[]  $rows  one provider's rows from one grant
     */
    public function afterConnect(array $rows): void
    {
        $result = null;

        foreach ($rows as $c) {
            try {
                $this->defaultChoices($c);
            } catch (Throwable) {
                // A choice that cannot be listed now keeps its previous value.
            }

            if ($result === null) {
                $result = $this->runCheck($c);
            } else {
                self::record($c, $result);
            }
        }
    }

    public function runCheck(SocialConnection $c): Liveness
    {
        try {
            $result = $this->liveness->check($c);
        } catch (Throwable) {
            $result = Liveness::unknown('The check could not run.');
        }

        self::record($c, $result);

        return $result;
    }

    /** As the nightly check records it, plus the verdict for the card. */
    public static function record(SocialConnection $c, Liveness $result): void
    {
        $c->refresh();   // a renewal may have just rotated its credentials

        match ($result->verdict) {
            Liveness::ALIVE => $c->forceFill(['last_reached_at' => now(), 'last_checked_at' => now()]),
            Liveness::REFUSED => $c->forceFill([
                'status' => SocialConnection::STATUS_BROKEN,
                'status_reason' => $result->reason,
                'last_checked_at' => now(),
            ]),
            default => null,
        };

        $c->forceFill(['setup' => [...self::setupOf($c), 'liveness' => [
            'verdict' => $result->verdict,
            'reason' => $result->reason,
            'at' => now()->toIso8601String(),
        ]]])->save();
    }

    /**
     * The pass/fail line for the account's card.
     *
     * @return array{passed: bool, can_post: ?bool, missing_scopes: string[], token: string, summary: string}
     */
    public static function check(SocialConnection $c): array
    {
        $provider = (string) $c->provider;
        $credentials = (array) ($c->getAttribute('credentials') ?? []);
        $granted = array_values(array_filter((array) ($credentials['scopes'] ?? []), 'is_string'));

        $needed = self::POST_SCOPES[$provider] ?? null;
        // A LinkedIn company Page posts on the organization scope, the person on the member one.
        if ($provider === SocialProvider::LinkedIn->value && str_starts_with((string) $c->remote_id, 'urn:li:organization:')) {
            $needed = ['w_organization_social'];
        }
        // Unknown when the network never said what it granted (a row from
        // before scopes were recorded): no verdict rather than a false alarm.
        $missing = $needed === null || $granted === [] ? [] : array_values(array_diff($needed, $granted));
        $canPost = $needed === null || $granted === [] ? null : $missing === [];

        $token = self::tokenLife($provider, $credentials);
        $alive = $c->status === SocialConnection::STATUS_CONNECTED;
        $passed = $alive && $canPost !== false;

        $parts = [];
        if (! $alive) {
            $parts[] = 'Failed: '.($c->status_reason ?: 'the provider refused the connection');
        } elseif ($canPost === false) {
            $parts[] = 'Failed: posting was not allowed ('.implode(', ', $missing).'). Reconnect and allow it';
        } elseif ($canPost === true) {
            $parts[] = 'Passed: can post';
        } else {
            $parts[] = 'Passed';
        }
        $parts[] = 'token '.$token;

        return [
            'passed' => $passed,
            'can_post' => $canPost,
            'missing_scopes' => $missing,
            'token' => $token,
            'summary' => implode(' · ', $parts),
        ];
    }

    /** @param  array<string, mixed>  $credentials */
    private static function tokenLife(string $provider, array $credentials): string
    {
        if (in_array($provider, self::NEVER_EXPIRES, true)) {
            return 'does not expire';
        }

        $expires = is_string($credentials['expires_at'] ?? null) ? Carbon::parse($credentials['expires_at']) : null;
        $refresh = is_string($credentials['refresh_token'] ?? null) && $credentials['refresh_token'] !== '';

        if ($refresh) {
            return 'renews automatically';
        }

        if ($expires === null) {
            return 'does not expire';
        }

        $date = $expires->toFormattedDayDateString();

        return in_array($provider, self::RENEWED_BY_EZBIZI, true)
            ? 'lasts until '.$date.', renewed before then'
            : 'lasts until '.$date;
    }

    /**
     * The choices for the card: each with its options and what is selected.
     *
     * @return list<array{key: string, label: string, selected: ?string, options: list<array{id: string, name: string}>}>
     */
    public static function choices(SocialConnection $c): array
    {
        $out = [];
        foreach ((array) (self::setupOf($c)['choices'] ?? []) as $key => $choice) {
            $out[] = [
                'key' => (string) $key,
                'label' => (string) ($choice['label'] ?? $key),
                'selected' => isset($choice['selected']) ? (string) $choice['selected'] : null,
                'options' => array_values((array) ($choice['options'] ?? [])),
            ];
        }

        return $out;
    }

    /** The chosen value for one key, or null. */
    public static function chosen(SocialConnection $c, string $key): ?string
    {
        $v = self::setupOf($c)['choices'][$key]['selected'] ?? null;

        return is_string($v) && $v !== '' ? $v : null;
    }

    /**
     * Change one choice to one of its listed options.
     *
     * @return bool false when the value is not one of the options
     */
    public function choose(SocialConnection $c, string $key, string $value): bool
    {
        $setup = self::setupOf($c);
        $choice = $setup['choices'][$key] ?? null;
        if (! is_array($choice)) {
            return false;
        }

        $option = collect((array) ($choice['options'] ?? []))->firstWhere('id', $value);
        if ($option === null) {
            return false;
        }

        $setup['choices'][$key]['selected'] = $value;
        $c->forceFill(['setup' => $setup])->save();

        if ($c->provider === SocialProvider::Mailchimp->value && $key === 'audience') {
            self::ensureAudience($c, $value, (string) ($option['name'] ?? 'Mailchimp audience'));
        }

        return true;
    }

    /** List the options now and keep the previous choice when it still exists. */
    public function defaultChoices(SocialConnection $c): void
    {
        $options = match ($c->provider) {
            SocialProvider::Pinterest->value => ['board', 'Board', $this->pinterestBoards($c)],
            SocialProvider::Mailchimp->value => ['audience', 'Audience', $this->mailchimpLists($c)],
            default => null,
        };

        if ($options === null) {
            return;
        }

        [$key, $label, $list] = $options;
        $setup = self::setupOf($c);
        $before = $setup['choices'][$key]['selected'] ?? null;
        $ids = array_column($list, 'id');
        $selected = in_array($before, $ids, true) ? $before : ($ids[0] ?? null);

        $setup['choices'][$key] = ['label' => $label, 'selected' => $selected, 'options' => $list];
        $c->forceFill(['setup' => $setup])->save();

        if ($c->provider === SocialProvider::Mailchimp->value && $selected !== null) {
            $name = (string) (collect($list)->firstWhere('id', $selected)['name'] ?? 'Mailchimp audience');
            self::ensureAudience($c, $selected, $name);
        }
    }

    /** @return list<array{id: string, name: string}> */
    private function pinterestBoards(SocialConnection $c): array
    {
        $publisher = app(PinterestPublisher::class);
        $boards = $publisher->boards($c);

        // None: a Pin must live on a board, so Connect makes one.
        return $boards !== [] ? $boards : [$publisher->createBoard($c, self::defaultName($c))];
    }

    /** @return list<array{id: string, name: string}> */
    private function mailchimpLists(SocialConnection $c): array
    {
        $key = (string) (((array) ($c->getAttribute('credentials') ?? []))['api_key'] ?? '');
        $client = app(MailchimpClient::class);
        $lists = array_values(array_filter($client->lists($key), fn (array $l) => ($l['id'] ?? '') !== ''));

        // None: a newsletter needs somewhere to go, so Connect makes one.
        if ($lists === []) {
            $lists = [$client->createList($key, self::defaultName($c))];
        }

        return array_values(array_map(fn (array $l) => ['id' => (string) $l['id'], 'name' => (string) $l['name']], $lists));
    }

    /** The business's own name for a board or audience Connect creates. */
    private static function defaultName(SocialConnection $c): string
    {
        $name = trim((string) ($c->organization?->name ?? ''));

        return $name !== '' ? $name : 'EzBizi';
    }

    /**
     * The chosen Mailchimp list becomes a Newsletter audience in the
     * connection's own view, unless one already points at it there.
     */
    private static function ensureAudience(SocialConnection $c, string $listId, string $name): void
    {
        $exists = NewsletterAudience::query()
            ->where('kind', NewsletterAudience::KIND_MAILCHIMP)
            ->where('mailchimp_list_id', $listId)
            ->where('scope_type', $c->scope_type)
            ->where('scope_id', $c->scope_id)
            ->exists();

        if ($exists) {
            return;
        }

        $audience = new NewsletterAudience([
            'name' => $name,
            'kind' => NewsletterAudience::KIND_MAILCHIMP,
            'mailchimp_list_id' => $listId,
        ]);
        $audience->forceFill([
            'organization_id' => $c->organization_id,
            'scope_type' => $c->scope_type,
            'scope_id' => $c->scope_id,
        ])->save();
    }

    /** @return array<string, mixed> */
    private static function setupOf(SocialConnection $c): array
    {
        $setup = $c->getAttribute('setup');

        return is_array($setup) ? $setup : [];
    }
}
