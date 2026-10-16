<?php

namespace App\Support\Dashboard\Glances;

use App\Models\Mailbox;
use App\Models\User;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\DescribedGlance;
use App\Support\Dashboard\GlancePayload;
use App\Support\Email\CannotReachHost;
use App\Support\Email\CannotReadMailbox;
use App\Support\Email\MailboxAccess;
use App\Support\Email\MailboxReaders;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Email box: unread per readable inbox in the current view (snapshot).
 *
 * Unread is the provider's mailbox-level figure — never a stored message list.
 * A mailbox that cannot be asked is named in a sentence; the others still draw.
 */
final class EmailGlance extends DescribedGlance
{
    protected const KEY = 'email';
    protected const LABEL = 'Email';
    protected const PERMISSION = 'email.view';
    protected const PRESENTATIONS = ['bar', 'pie', 'text'];
    protected const DEFAULT_PRESENTATION = 'bar';
    protected const SNAPSHOT = true;

    /** How long an inbox may be silent before the home screen says so. Jose, 2026-09-14. */
    private const SILENT_HOURS = 24;

    /** How old a served unread count may be before it is re-asked after the response. */
    private const FRESH_SECONDS = 120;

    public function __construct(
        private readonly MailboxAccess $access,
        private readonly MailboxReaders $readers,
    ) {}

    public function resolve(User $user, DashboardPeriod $period, array $options): GlancePayload
    {
        return app(TenantContext::class)->run($user->organization, function () use ($user) {
            $mailboxes = $this->access->readable($user)
                ->filter(fn (Mailbox $mailbox) => $this->access->inView($user, $mailbox))
                ->values();

            if ($mailboxes->isEmpty()) {
                return $this->payload([
                    'empty' => true,
                    'sentence' => 'No inbox is assigned to your role.',
                ]);
            }

            $slices = [];
            $sentences = [];
            $disconnected = 0;
            $silent = 0;

            foreach ($mailboxes as $mailbox) {
                // A broken mailbox is not asked. `WatchMailboxes` already skips
                // it on purpose, so nothing is arriving and nothing will until
                // somebody reconnects it -- asking anyway would spend the home
                // screen's load on a connection we know has failed, and would
                // then describe a permanent state in the words of a temporary
                // one. Two production mailboxes sat broken for three weeks
                // saying "could not be asked", which reads like a bad minute.
                if ($mailbox->status === Mailbox::STATUS_BROKEN) {
                    $disconnected++;
                    $sentences[] = sprintf(
                        '%s is disconnected. No mail is arriving from it until it is reconnected in Email, Settings.',
                        $mailbox->address,
                    );

                    continue;
                }

                $failedJustNow = false;

                try {
                    $slices[] = [
                        'label' => $this->sliceLabel($mailbox),
                        'total' => $this->unread($user, $mailbox),
                    ];
                } catch (CannotReadMailbox|CannotReachHost) {
                    $failedJustNow = true;
                }

                // Checked AFTER the read, not before, and that ordering is the
                // whole accuracy of it: a read that succeeds calls
                // `markReached()`, which stamps `last_reached_at` on this very
                // instance -- so a mailbox that has just worked cannot be
                // reported stale by the same page load that proved it working.
                if ($this->silentTooLong($mailbox)) {
                    $silent++;
                    $sentences[] = sprintf(
                        '%s has not been able to fetch any mail for %s. It still shows as '
                        .'connected, so nothing is asking anybody to fix it.',
                        $mailbox->address,
                        $mailbox->last_reached_at->diffForHumans(null, true),
                    );

                    continue;
                }

                if ($failedJustNow) {
                    $sentences[] = sprintf(
                        '%s could not be asked for unread mail just now.',
                        $mailbox->address,
                    );
                }
            }

            // Never `empty: true` here, and the reason is no longer the one
            // this comment used to give.
            //
            // It was: the dashboard rendered ONE sentence for an empty glance
            // and silently dropped the rest, so every mailbox broken meant no
            // slices, several things to say, and only the first shown. That is
            // what hid a second dead inbox for three weeks. The Dashboard
            // session fixed the renderer itself in `e37502f4`, so the dropping
            // is gone and this line is no longer load-bearing against it.
            //
            // It stays `false`, and under the replacement rule (`7d76c762`)
            // the reason is sharper than "a box with something to say is not
            // empty". `empty` now asks whether this box is a STATEMENT or a
            // READING: `true` suppresses the chart and every figure, and draws
            // the sentences in the MUTED note style.
            //
            // So the trap has inverted, and it points straight at this glance.
            // With every mailbox disconnected there are no slices and nothing
            // to plot, which makes `true` look like the tidier choice -- and it
            // would render "no mail is arriving from this address" as a muted
            // aside. That is the SAME mistake this whole file was fixed for:
            // a dead inbox stated quietly enough that nobody acts on it.
            //
            // A disconnected inbox is not a "nothing to show" state. It is the
            // loudest thing this box can have to say, so it is said in ordinary
            // text. Every mailbox above contributes either a slice or a
            // sentence, so there is always something here.
            $data = ['empty' => false];

            if ($slices !== []) {
                $data['slices'] = $slices;
            }

            if ($sentences !== []) {
                $data['sentences'] = $sentences;
            }

            // Both are the inboxes as they stand now (SNAPSHOT), so neither
            // has a previous. Unread sums the bars, so an inbox that could not
            // be asked adds nothing rather than a guess; disconnected counts
            // the inboxes named as disconnected above. Counted in the loop,
            // not by re-reading `status` here: a read refused just now can
            // mark its mailbox broken mid-load, and that inbox's sentence on
            // THIS load says "could not be asked", not "disconnected".
            $data['figures'] = [
                GlancePayload::figure('unread', 'unread', (int) array_sum(array_column($slices, 'total'))),
                GlancePayload::figure('disconnected', 'disconnected', $disconnected),
            ];

            $attention = $this->attention($disconnected, $silent);

            if ($attention !== []) {
                $data['attention'] = $attention;
            }

            return $this->payload($data);
        });
    }

    /**
     * The rows that need somebody: counts, never addresses (the sentences
     * carry those), and only when there is something to act on.
     *
     * Both are `bad`, because neither is "about to be": a disconnected inbox
     * has already stopped, and one silent past Jose's day has missed roughly
     * three hundred consecutive sweeps while still claiming to be connected.
     *
     * Deliberately absent: an inbox that could not be asked JUST NOW. Nobody
     * can act on one bad minute, and the next load usually answers it. Raising
     * it here would put the same alarm on every flaky page load, which is how
     * a row gets ignored by the time it means something.
     *
     * @return list<array{tone: string, text: string}>
     */
    private function attention(int $disconnected, int $silent): array
    {
        $rows = [];

        if ($disconnected > 0) {
            $rows[] = GlancePayload::attention('bad', $disconnected === 1
                ? '1 inbox is disconnected'
                : "{$disconnected} inboxes are disconnected");
        }

        if ($silent > 0) {
            $rows[] = GlancePayload::attention('bad', $silent === 1
                ? '1 inbox has fetched no mail for over a day'
                : "{$silent} inboxes have fetched no mail for over a day");
        }

        return $rows;
    }

    /**
     * Has this inbox gone quiet while still claiming to be connected?
     *
     * ── The case the rest of this file does not cover ────────────
     *
     * `broken` is loud and says who must fix it. A read that fails right now
     * gets a sentence saying so. Between them sits the inbox that is CONNECTED,
     * fails most of the time, occasionally succeeds, and is therefore never
     * broken and never conclusively failing. Two production mailboxes lived
     * there for three weeks and the only alarm was `email.mailbox_broken`,
     * which fires on the TRANSITION -- so a flapping mailbox fired it 81 times
     * in eighteen days at nobody, and a rule built on it would be switched off
     * in a week. A duration cannot flap: it only grows.
     *
     * ── Twenty-four hours is Jose's number, 2026-09-14 ───────────
     *
     * Asked how long an inbox should be silent before the home screen says so.
     * It is well past any outage worth mentioning and well short of a working
     * day being lost, and the sweep polls every five minutes, so reaching a day
     * means roughly three hundred consecutive failures rather than a bad hour.
     *
     * ── A DURATION, never a wall clock ───────────────────────────
     *
     * `diffForHumans` renders an elapsed time, which is the same number in
     * every timezone. A glance that printed "since 14:37" would have to convert
     * to the viewer's view timezone first, and that conversion is the single
     * most repeated bug in this dashboard's history.
     *
     * ── Never reached at all is deliberately NOT this ────────────
     *
     * `last_reached_at` null means EzBizi has never once opened it, so "has not
     * fetched mail for 3 days" would be an odd way to say "this was never
     * finished". That belongs to the `unconfigured` status and its own screen.
     * A mailbox added and abandoned is a real gap and this is not its guard.
     */
    /**
     * Unread for one inbox, without making the dashboard wait on the provider.
     *
     * Asking every inbox live made this box 10-19 seconds of A1-Group's
     * dashboard load (measured on production 2026-10-16; every other box
     * together was about 3) -- Jose: "takes too long when it should be
     * instant". So the last count is served at once, and one older than
     * FRESH_SECONDS is re-asked AFTER the response has gone, in this worker,
     * so the next load has it. Only an inbox never counted is asked live, which
     * keeps a failing one named on first sight. A refresh that fails keeps the
     * last count; a broken or silent inbox is still said from its status and
     * last_reached_at, which WatchMailboxes keeps current.
     */
    private function unread(User $user, Mailbox $mailbox): int
    {
        $key = 'dashboard.email.unread.'.$mailbox->getKey();
        $hit = Cache::get($key);

        if (is_array($hit) && isset($hit['n'], $hit['at'])) {
            if ($hit['at'] < now()->getTimestamp() - self::FRESH_SECONDS && Cache::add($key.'.refreshing', 1, 60)) {
                $organization = $user->organization;
                dispatch(function () use ($organization, $mailbox, $key) {
                    try {
                        app(TenantContext::class)->run($organization, fn () => Cache::forever($key, [
                            'n' => $this->readers->for($mailbox)->unreadCount($mailbox),
                            'at' => now()->getTimestamp(),
                        ]));
                    } catch (Throwable) {
                        // Keep the last count; the next load tries again.
                    } finally {
                        Cache::forget($key.'.refreshing');
                    }
                })->afterResponse();
            }

            return (int) $hit['n'];
        }

        $count = $this->readers->for($mailbox)->unreadCount($mailbox);
        Cache::forever($key, ['n' => $count, 'at' => now()->getTimestamp()]);

        return $count;
    }

    private function silentTooLong(Mailbox $mailbox): bool
    {
        return $mailbox->last_reached_at !== null
            && $mailbox->last_reached_at->lt(now()->subHours(self::SILENT_HOURS));
    }

    private function sliceLabel(Mailbox $mailbox): string
    {
        $name = trim((string) $mailbox->display_name);

        return $name !== '' ? $name : $mailbox->address;
    }

    /** @param  array<string, mixed>  $data */
    private function payload(array $data): GlancePayload
    {
        return new GlancePayload(
            key: $this->key(),
            label: $this->label(),
            presentation: $this->defaultPresentation(),
            allowedPresentations: $this->allowedPresentations(),
            snapshot: $this->snapshot(),
            comingSoon: false,
            options: [],
            data: $data,
        );
    }
}
