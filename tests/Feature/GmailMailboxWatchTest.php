<?php

namespace Tests\Feature;

use App\Models\Mailbox;
use App\Support\Email\GmailMailboxReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Gmail's own answer to "what is new" — the history id, and what it costs.
 *
 * ═══════════════════════════════════════════════════════════════════
 *  WHY THE POSITION IS A HISTORY ID AND NOT A DATE OR A SUBJECT.
 *
 *  Gmail keeps a sequence number for the whole mailbox and will list
 *  everything that has happened since one. That is the only primitive
 *  here that is both ORDERED and OWNED BY THE PROVIDER — a Date:
 *  header is written by the sender's computer and is routinely hours
 *  wrong, and a subject repeats a dozen times a day in any inbox
 *  worth watching.
 *
 *  Which leaves exactly one thing to get right, and it is what most
 *  of this file is about: a history id EXPIRES. Google keeps a
 *  limited window and answers 404 for anything older. The obvious
 *  recovery — list the inbox and announce what is there — is the
 *  catastrophe this whole feature is built to avoid, so the reader
 *  must take a fresh position and say NOTHING.
 * ═══════════════════════════════════════════════════════════════════
 *
 * There is no Google account on this installation, so what is asserted is the
 * reader's behaviour against the response shapes Google documents: which
 * endpoint it calls, what it sends, and what it concludes. Whether Google really
 * answers 404 for an aged-out history id is Google's documentation, and no test
 * here can or should assert it.
 */
class GmailMailboxWatchTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const TOKEN = 'https://oauth2.googleapis.com/token';

    private const PROFILE = 'gmail.googleapis.com/gmail/v1/users/me/profile*';

    private const HISTORY = 'gmail.googleapis.com/gmail/v1/users/me/history*';

    private const MESSAGE = 'gmail.googleapis.com/gmail/v1/users/me/messages/*';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.google_mail.client_id'     => 'a-client-id.apps.googleusercontent.com',
            'services.google_mail.client_secret' => 'a-client-secret',
        ]);
    }

    private function inbox(): Mailbox
    {
        $t = $this->provisionTenant('A1-Group');

        return $this->inTenant($t['org'], function () use ($t) {
            $mailbox = Mailbox::create([
                'organization_id' => $t['org']->id,
                'scope_type'      => 'organization',
                'scope_id'        => $t['org']->id,
                'address'         => 'info@a-1chimney.com',
                'display_name'    => 'Info',
                'provider'        => 'gmail_oauth',
            ]);

            $mailbox->connect('a-refresh-token');
            $mailbox->save();

            return $mailbox;
        });
    }

    private function reader(): GmailMailboxReader
    {
        return app(GmailMailboxReader::class);
    }

    /** One metadata response, shaped the way Gmail shapes it. */
    private function metadata(string $id, string $subject, string $from): array
    {
        return [
            'id'      => $id,
            'threadId' => 't-'.$id,
            'snippet' => 'SECRETPREVIEWTEXT that must never leave this response',
            'payload' => ['headers' => [
                ['name' => 'From', 'value' => $from],
                ['name' => 'Subject', 'value' => $subject],
                ['name' => 'Date', 'value' => 'Thu, 13 Aug 2026 09:00:00 +0100'],
            ]],
        ];
    }

    // ── the position ─────────────────────────────────────────────

    /**
     * A first position is one call, and it lists nothing.
     *
     * The endpoint matters as much as the answer. `messages.list` would also
     * yield a history id — off the newest message — and it would be a position
     * that lags the mailbox, cost two calls, and require touching mail this
     * method has no business touching. The profile is the mailbox's own answer.
     */
    public function test_the_first_position_is_the_profile_history_id_and_reads_no_mail(): void
    {
        Http::fake([
            self::TOKEN   => Http::response(['access_token' => 'at-1', 'expires_in' => 3600]),
            self::PROFILE => Http::response([
                'emailAddress' => 'info@a-1chimney.com',
                'messagesTotal' => 4000,
                'historyId'    => '987654',
            ]),
            '*' => Http::response([], 500),
        ]);

        $this->assertSame('987654', $this->reader()->currentMark($this->inbox()));

        // Nothing about messages was asked for. A first look at an inbox with
        // four thousand messages in it must not enumerate one of them.
        $this->assertSame(0, Http::recorded(
            fn ($request) => str_contains($request->url(), '/messages')
        )->count());
    }

    // ── arrivals ─────────────────────────────────────────────────

    /**
     * Two messages added since the position, with their headers fetched.
     *
     * Also asserts the three query parameters that decide correctness, because
     * dropping any one of them is a bug nothing else here would catch:
     * `historyTypes=messageAdded` (or archiving a message fires "an email
     * arrived"), `labelId=INBOX` (or our own outgoing replies do), and the
     * position itself.
     */
    public function test_messages_added_since_the_position_are_returned_oldest_first(): void
    {
        Http::fake([
            self::TOKEN   => Http::response(['access_token' => 'at-1', 'expires_in' => 3600]),
            self::HISTORY => Http::response([
                'history' => [
                    ['id' => '1001', 'messagesAdded' => [
                        ['message' => ['id' => 'm-000002', 'labelIds' => ['INBOX', 'UNREAD']]],
                    ]],
                    ['id' => '1002', 'messagesAdded' => [
                        ['message' => ['id' => 'm-000001', 'labelIds' => ['INBOX']]],
                    ]],
                ],
                'historyId' => '1050',
            ]),
            'gmail.googleapis.com/gmail/v1/users/me/messages/m-000001*'
                => Http::response($this->metadata('m-000001', 'First', 'Ada <ada@example.com>')),
            'gmail.googleapis.com/gmail/v1/users/me/messages/m-000002*'
                => Http::response($this->metadata('m-000002', 'Second', 'Bob <bob@example.com>')),
        ]);

        $arrivals = $this->reader()->arrivalsSince($this->inbox(), '900', 50);

        $this->assertFalse($arrivals->markWasStale);
        $this->assertSame('1050', $arrivals->mark);
        $this->assertCount(2, $arrivals->messages);

        // Oldest first, by Gmail's own increasing ids — which is what makes a
        // truncated batch a resumable prefix rather than a hole.
        $this->assertSame(['First', 'Second'], array_map(
            fn ($m) => $m->subject, $arrivals->messages,
        ));

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/history')) {
                return false;
            }

            return str_contains($request->url(), 'startHistoryId=900')
                && str_contains($request->url(), 'historyTypes=messageAdded')
                && str_contains($request->url(), 'labelId=INBOX');
        });
    }

    /**
     * A message deleted after it arrived answers 404. It is skipped, and the
     * position moves on: failing on it replayed the same history every poll,
     * and four A-1 inboxes stopped announcing any mail (11-12).
     */
    public function test_a_message_deleted_since_it_arrived_does_not_stop_the_watch(): void
    {
        Http::fake([
            self::TOKEN   => Http::response(['access_token' => 'at-1', 'expires_in' => 3600]),
            self::HISTORY => Http::response([
                'history' => [
                    ['id' => '1001', 'messagesAdded' => [
                        ['message' => ['id' => 'm-000001', 'labelIds' => ['INBOX']]],
                        ['message' => ['id' => 'm-000002', 'labelIds' => ['INBOX']]],
                    ]],
                ],
                'historyId' => '1050',
            ]),
            'gmail.googleapis.com/gmail/v1/users/me/messages/m-000001*' => Http::response(['error' => ['code' => 404]], 404),
            'gmail.googleapis.com/gmail/v1/users/me/messages/m-000002*'
                => Http::response($this->metadata('m-000002', 'Kept', 'Bob <bob@example.com>')),
        ]);

        $arrivals = $this->reader()->arrivalsSince($this->inbox(), '900', 50);

        $this->assertSame('1050', $arrivals->mark);
        $this->assertSame(['Kept'], array_map(fn ($m) => $m->subject, $arrivals->messages));
    }

    /**
     * A message listed twice in one history is one arrival.
     *
     * Google's own guidance is that history records can repeat a message, and a
     * resumed listing can repeat records. Left unhandled that is one message,
     * two automation events — which is the promise this whole feature rests on,
     * broken by a data shape rather than by a race.
     */
    public function test_a_message_appearing_in_two_history_records_is_announced_once(): void
    {
        Http::fake([
            self::TOKEN   => Http::response(['access_token' => 'at-1', 'expires_in' => 3600]),
            self::HISTORY => Http::response([
                'history' => [
                    ['id' => '1001', 'messagesAdded' => [['message' => ['id' => 'm-1', 'labelIds' => ['INBOX']]]]],
                    ['id' => '1002', 'messagesAdded' => [['message' => ['id' => 'm-1', 'labelIds' => ['INBOX']]]]],
                ],
                'historyId' => '1050',
            ]),
            self::MESSAGE => Http::response($this->metadata('m-1', 'Only once', 'Ada <ada@example.com>')),
        ]);

        $this->assertCount(1, $this->reader()->arrivalsSince($this->inbox(), '900', 50)->messages);
    }

    /**
     * A draft saved, or a reply this application sent, is not an arrival.
     *
     * The second one is the dangerous case: `messages.send` puts our own reply
     * into the mailbox, so without this an auto-reply rule would answer itself
     * — the loop guards would eventually stop it, after it had sent mail to a
     * customer.
     */
    public function test_drafts_and_sent_mail_are_not_arrivals(): void
    {
        Http::fake([
            self::TOKEN   => Http::response(['access_token' => 'at-1', 'expires_in' => 3600]),
            self::HISTORY => Http::response([
                'history' => [
                    ['id' => '1001', 'messagesAdded' => [
                        ['message' => ['id' => 'm-draft', 'labelIds' => ['DRAFT', 'INBOX']]],
                        ['message' => ['id' => 'm-sent', 'labelIds' => ['SENT']]],
                        ['message' => ['id' => 'm-real', 'labelIds' => ['INBOX']]],
                    ]],
                ],
                'historyId' => '1050',
            ]),
            self::MESSAGE => Http::response($this->metadata('m-real', 'A customer', 'Ada <ada@example.com>')),
        ]);

        $arrivals = $this->reader()->arrivalsSince($this->inbox(), '900', 50);

        $this->assertCount(1, $arrivals->messages);
        $this->assertSame('m-real', $arrivals->messages[0]->id);
    }

    // ── the position expiring ────────────────────────────────────

    /**
     * AN EXPIRED HISTORY ID ANNOUNCES NOTHING.
     *
     * The single most important assertion in this file. A 404 is not a failure
     * to be thrown and it is not a reason to list the inbox: it is a position
     * that has aged out, and the only safe recovery is a fresh position and
     * silence.
     */
    public function test_an_expired_position_is_replaced_and_nothing_is_announced(): void
    {
        Http::fake([
            self::TOKEN   => Http::response(['access_token' => 'at-1', 'expires_in' => 3600]),
            self::HISTORY => Http::response(['error' => ['message' => 'Requested entity was not found.']], 404),
            self::PROFILE => Http::response(['historyId' => '5000']),
            '*' => Http::response([], 500),
        ]);

        $arrivals = $this->reader()->arrivalsSince($this->inbox(), '1', 50);

        $this->assertTrue($arrivals->markWasStale);
        $this->assertSame([], $arrivals->messages);
        $this->assertSame('5000', $arrivals->mark);

        // And it did NOT fall back to listing the inbox, which is the recovery
        // that would announce every message in it.
        $this->assertSame(0, Http::recorded(
            fn ($request) => str_contains($request->url(), '/messages')
        )->count());
    }

    /**
     * A history longer than the reader will follow is treated the same way.
     *
     * Gmail's position can only be taken where its paging stops, so a listing
     * that never ends leaves no safe half-way mark to store. Storing the
     * response's own `historyId` would skip every page not read — silently, and
     * for ever. So the cap re-marks and announces nothing, exactly like an
     * expired position, and says so.
     */
    public function test_a_history_that_never_ends_re_marks_rather_than_guessing(): void
    {
        Http::fake([
            self::TOKEN   => Http::response(['access_token' => 'at-1', 'expires_in' => 3600]),
            self::HISTORY => Http::response([
                'history' => [
                    ['id' => '1001', 'messagesAdded' => [['message' => ['id' => 'm-1', 'labelIds' => ['INBOX']]]]],
                ],
                'historyId'     => '1050',
                'nextPageToken' => 'always-more',
            ]),
            self::PROFILE => Http::response(['historyId' => '9999']),
            self::MESSAGE => Http::response($this->metadata('m-1', 'Never announced', 'Ada <ada@example.com>')),
        ]);

        $arrivals = $this->reader()->arrivalsSince($this->inbox(), '900', 50);

        $this->assertTrue($arrivals->markWasStale);
        $this->assertSame([], $arrivals->messages);
        $this->assertSame('9999', $arrivals->mark);

        // Bounded, and bounded by the constant rather than by the test running
        // out of patience: an unbounded follow would still be running.
        $this->assertLessThanOrEqual(10, Http::recorded(
            fn ($request) => str_contains($request->url(), '/history')
        )->count());
    }

    /**
     * A page that DOES end is followed, and everything on both pages arrives.
     *
     * The counterpart to the test above, and the reason that one is a cap rather
     * than a refusal to page at all: stopping at the first page would silently
     * drop the second, which is the failure the cap exists to avoid.
     */
    public function test_a_history_spread_over_two_pages_announces_both(): void
    {
        $page = 0;

        Http::fake([
            self::TOKEN   => Http::response(['access_token' => 'at-1', 'expires_in' => 3600]),
            self::HISTORY => function () use (&$page) {
                $page++;

                return Http::response($page === 1
                    ? [
                        'history' => [['id' => '1', 'messagesAdded' => [
                            ['message' => ['id' => 'm-000001', 'labelIds' => ['INBOX']]],
                        ]]],
                        'historyId'     => '1010',
                        'nextPageToken' => 'page-2',
                    ]
                    : [
                        'history' => [['id' => '2', 'messagesAdded' => [
                            ['message' => ['id' => 'm-000002', 'labelIds' => ['INBOX']]],
                        ]]],
                        'historyId' => '1020',
                    ]);
            },
            'gmail.googleapis.com/gmail/v1/users/me/messages/m-000001*'
                => Http::response($this->metadata('m-000001', 'One', 'Ada <ada@example.com>')),
            'gmail.googleapis.com/gmail/v1/users/me/messages/m-000002*'
                => Http::response($this->metadata('m-000002', 'Two', 'Bob <bob@example.com>')),
        ]);

        $arrivals = $this->reader()->arrivalsSince($this->inbox(), '900', 50);

        $this->assertFalse($arrivals->markWasStale);
        $this->assertCount(2, $arrivals->messages);

        // The mark is the LAST page's, not the first's. Storing the first would
        // re-announce the second page's messages on every poll for ever.
        $this->assertSame('1020', $arrivals->mark);
    }

    /**
     * Nothing happened, and the position still moves.
     *
     * Gmail's history id advances for things that are not arrivals — a label
     * changed, a message archived. Holding the old one would make every
     * subsequent poll re-ask a question Google has already answered, which at
     * five minutes a mailbox is a quota bill for nothing.
     */
    public function test_a_quiet_mailbox_still_takes_the_new_position(): void
    {
        Http::fake([
            self::TOKEN   => Http::response(['access_token' => 'at-1', 'expires_in' => 3600]),
            self::HISTORY => Http::response(['historyId' => '1234']),
            '*' => Http::response([], 500),
        ]);

        $arrivals = $this->reader()->arrivalsSince($this->inbox(), '900', 50);

        $this->assertSame([], $arrivals->messages);
        $this->assertSame('1234', $arrivals->mark);
    }

    /**
     * The headers are fetched, and the body is not.
     *
     * `format=metadata` with the four headers named, which is the same request
     * the message list makes. A watcher that asked for `format=full` would pull
     * every attachment in the mailbox across the wire to read a subject — and
     * would be holding message bodies in a process whose whole design says it
     * never does.
     */
    public function test_only_headers_are_fetched_for_a_new_message(): void
    {
        Http::fake([
            self::TOKEN   => Http::response(['access_token' => 'at-1', 'expires_in' => 3600]),
            self::HISTORY => Http::response([
                'history'   => [['id' => '1', 'messagesAdded' => [['message' => ['id' => 'm-1', 'labelIds' => ['INBOX']]]]]],
                'historyId' => '1050',
            ]),
            self::MESSAGE => Http::response($this->metadata('m-1', 'Subject', 'Ada <ada@example.com>')),
        ]);

        $this->reader()->arrivalsSince($this->inbox(), '900', 50);

        Http::assertSent(fn ($request) => ! str_contains($request->url(), '/messages/')
            || (str_contains($request->url(), 'format=metadata')
                && ! str_contains($request->url(), 'format=full')));
    }
}
