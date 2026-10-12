<?php

namespace Tests\Feature\Social;

use App\Models\SocialConnection;
use App\Support\Social\SocialProvider;
use App\Support\Social\ThumbtackAssociatePhoneNumbers;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The associate phone numbers Thumbtack matches calls against.
 *
 * Thumbtack made this a condition of the integration, and it is the one part
 * where being wrong is worse than being absent: a number sent in error tells
 * Thumbtack that somebody else's handset answers for this business, and their
 * mobile starts showing the business's caller ID. So what is pinned here is
 * mostly what does NOT get sent.
 */
class ThumbtackPhoneNumbersTest extends TestCase
{
    private const BASE = 'https://api.thumbtack.com/api/v4/businesses/biz-1/associate-phone-numbers';

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Pinned, because a developer with staging credentials in .env has
         * THUMBTACK_API_BASE pointing at staging-api - and this suite asserts
         * on whole URLs. Left to the ambient environment it passed alone and
         * failed in the full run, which is the worst way for a test to be
         * wrong: it looks like a real regression somewhere else.
         */
        config()->set('services.thumbtack.api_base', 'https://api.thumbtack.com/api');
    }

    public function test_a_number_typed_any_of_the_usual_ways_becomes_one_e164_number(): void
    {
        foreach (['5552345678', '(555) 234-5678', '555.234.5678', '1-555-234-5678', '+15552345678'] as $typed) {
            $this->assertSame(
                '+15552345678',
                ThumbtackAssociatePhoneNumbers::e164($typed),
                $typed.' should normalise',
            );
        }
    }

    public function test_anything_it_cannot_be_sure_of_is_refused_rather_than_guessed(): void
    {
        // Null, not a best effort. Guessing hands the business's caller ID to
        // whatever number the guess landed on.
        foreach ([
            '12345',            // too short
            '555123456789012',  // too long
            'ext 4102',         // not a number at all
            '0551234567',       // area codes never start 0
            '1551234567',       // ...or 1
            '5550234567',       // exchanges never start 0
            '',
        ] as $bad) {
            $this->assertNull(ThumbtackAssociatePhoneNumbers::e164($bad), $bad.' should be refused');
        }
    }

    public function test_new_numbers_go_up_in_one_bulk_call_addressed_to_the_business(): void
    {
        Http::fake([
            '*associate-phone-numbers-bulk-create' => Http::response(['phoneNumbers' => [
                ['phoneNumberID' => '1', 'businessID' => 'biz-1', 'phoneNumber' => '+15552345678', 'createdTime' => 'now'],
                ['phoneNumberID' => '2', 'businessID' => 'biz-1', 'phoneNumber' => '+15559876543', 'createdTime' => 'now'],
            ]]),
            '*associate-phone-numbers' => Http::response(['phoneNumbers' => []]),
        ]);

        $result = (new ThumbtackAssociatePhoneNumbers)->reconcile($this->connection(), [
            '(555) 234-5678' => 'Ada Sweep',
            '555-987-6543'   => 'Ben Flue',
        ]);

        $this->assertSame(2, $result['created']);

        Http::assertSent(function ($request) {
            if (! str_ends_with($request->url(), '-bulk-create')) {
                return false;
            }

            // The businessID is in the PATH. Every one of these endpoints is
            // per-business, so a connection's remote_id being wrong writes a
            // rival's number list rather than failing.
            return str_contains($request->url(), '/businesses/biz-1/')
                && $request->data()['phoneNumbers'] === [
                    ['phoneNumber' => '+15552345678', 'name' => 'Ada Sweep'],
                    ['phoneNumber' => '+15559876543', 'name' => 'Ben Flue'],
                ];
        });
    }

    /** 2026-10-09: one number Thumbtack calls invalid refused the whole batch, every night. */
    public function test_a_number_thumbtack_calls_invalid_is_dropped_and_the_rest_still_go_up(): void
    {
        Http::fake([
            '*associate-phone-numbers-bulk-create' => Http::sequence()
                ->push(['type' => 'about:blank', 'status' => 422, 'detail' => 'invalid phone number: +15552345678'], 422)
                ->push(['phoneNumbers' => [['phoneNumberID' => '2', 'phoneNumber' => '+15559876543']]]),
            '*associate-phone-numbers' => Http::response(['phoneNumbers' => []]),
        ]);

        $result = (new ThumbtackAssociatePhoneNumbers)->reconcile($this->connection(), [
            '(555) 234-5678' => 'Ada Sweep',
            '555-987-6543'   => 'Ben Flue',
            '555-765-4321'   => 'Cy Damper',
        ]);

        $this->assertSame(1, $result['created']);
        $this->assertSame(['+15552345678'], $result['skipped']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '-bulk-create')
            && $request->data()['phoneNumbers'] === [
                ['phoneNumber' => '+15559876543', 'name' => 'Ben Flue'],
                ['phoneNumber' => '+15557654321', 'name' => 'Cy Damper'],
            ]);
    }

    /** 2026-10-11: after the batch went up, the invalid number was the only new one, on the single path. */
    public function test_a_lone_number_thumbtack_calls_invalid_is_skipped_not_fatal(): void
    {
        Http::fake([
            '*associate-phone-numbers' => Http::sequence()
                ->push(['phoneNumbers' => []])
                ->push(['type' => 'about:blank', 'status' => 422, 'detail' => 'invalid phone number: +15552345678'], 422),
        ]);

        $result = (new ThumbtackAssociatePhoneNumbers)->reconcile($this->connection(), [
            '(555) 234-5678' => 'Ada Sweep',
        ]);

        $this->assertSame(0, $result['created']);
        $this->assertSame(['+15552345678'], $result['skipped']);
    }

    public function test_a_single_new_number_uses_the_plain_endpoint_not_the_bulk_one(): void
    {
        Http::fake([
            '*associate-phone-numbers' => Http::response(['phoneNumbers' => []]),
        ]);

        (new ThumbtackAssociatePhoneNumbers)->reconcile($this->connection(), ['5552345678' => 'Ada']);

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'bulk-create'));
        Http::assertSent(fn ($r) => $r->method() === 'POST'
            && $r->url() === self::BASE
            && $r->data() === ['phoneNumber' => '+15552345678', 'name' => 'Ada']);
    }

    public function test_a_number_that_changed_hands_is_updated_and_not_duplicated(): void
    {
        Http::fake([
            '*associate-phone-numbers/pn-1' => Http::response(['phoneNumberID' => 'pn-1']),
            '*associate-phone-numbers'      => Http::response(['phoneNumbers' => [
                ['phoneNumberID' => 'pn-1', 'businessID' => 'biz-1', 'phoneNumber' => '+15552345678',
                    'createdTime' => 'then', 'name' => 'Ada Sweep'],
            ]]),
        ]);

        $result = (new ThumbtackAssociatePhoneNumbers)->reconcile($this->connection(), [
            '5552345678' => 'Ben Flue',
        ]);

        $this->assertSame(['created' => 0, 'updated' => 1, 'deleted' => 0, 'skipped' => []], $result);

        Http::assertSent(fn ($r) => $r->method() === 'PUT'
            && str_ends_with($r->url(), '/pn-1')
            && $r->data()['name'] === 'Ben Flue');
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    public function test_a_number_no_longer_ours_is_deleted_so_it_stops_answering_as_the_business(): void
    {
        Http::fake([
            '*associate-phone-numbers/pn-9' => Http::response([]),
            '*associate-phone-numbers'      => Http::response(['phoneNumbers' => [
                ['phoneNumberID' => 'pn-9', 'businessID' => 'biz-1', 'phoneNumber' => '+15550001111',
                    'createdTime' => 'then', 'name' => 'Departed'],
            ]]),
        ]);

        $result = (new ThumbtackAssociatePhoneNumbers)->reconcile($this->connection(), []);

        $this->assertSame(1, $result['deleted']);

        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/pn-9'));
    }

    public function test_nothing_is_sent_when_the_two_lists_already_agree(): void
    {
        Http::fake([
            '*associate-phone-numbers' => Http::response(['phoneNumbers' => [
                ['phoneNumberID' => 'pn-1', 'businessID' => 'biz-1', 'phoneNumber' => '+15552345678',
                    'createdTime' => 'then', 'name' => 'Ada'],
            ]]),
        ]);

        $result = (new ThumbtackAssociatePhoneNumbers)->reconcile($this->connection(), ['5552345678' => 'Ada']);

        $this->assertSame(['created' => 0, 'updated' => 0, 'deleted' => 0, 'skipped' => []], $result);

        // Idempotent, which is the whole reason this is a repeatable sweep
        // rather than a one-shot push. A no-op run must really be a no-op.
        Http::assertSentCount(1);
    }

    public function test_a_number_thumbtack_marked_deleted_is_added_again_rather_than_treated_as_present(): void
    {
        // Somebody left, then came back. A soft-deleted row read as "present"
        // would mean their number is never re-registered and their calls stay
        // anonymous forever.
        Http::fake([
            '*associate-phone-numbers' => Http::response(['phoneNumbers' => [
                ['phoneNumberID' => 'pn-1', 'businessID' => 'biz-1', 'phoneNumber' => '+15552345678',
                    'createdTime' => 'then', 'name' => 'Ada', 'isDeleted' => true],
            ]]),
        ]);

        $result = (new ThumbtackAssociatePhoneNumbers)->reconcile($this->connection(), ['5552345678' => 'Ada']);

        $this->assertSame(1, $result['created']);
        $this->assertSame(0, $result['deleted']);
    }

    public function test_one_unusable_number_is_named_and_does_not_stop_the_good_ones(): void
    {
        Http::fake(['*associate-phone-numbers' => Http::response(['phoneNumbers' => []])]);

        $result = (new ThumbtackAssociatePhoneNumbers)->reconcile($this->connection(), [
            'ext 4102'   => 'Reception',
            '5552345678' => 'Ada',
        ]);

        // Thumbtack rejects the whole request over one malformed number, so a
        // bad entry has to be dropped here rather than sent and argued about.
        $this->assertSame(['ext 4102'], $result['skipped']);
        $this->assertSame(1, $result['created']);

        Http::assertSent(fn ($r) => $r->method() === 'POST'
            && $r->data() === ['phoneNumber' => '+15552345678', 'name' => 'Ada']);
    }

    public function test_a_refusal_carries_thumbtacks_own_words(): void
    {
        Http::fake(['*' => Http::response(['message' => 'That number belongs to another business.'], 409)]);

        $this->expectExceptionMessageMatches('/belongs to another business/');

        (new ThumbtackAssociatePhoneNumbers)->list($this->connection());
    }

    private function connection(): SocialConnection
    {
        $connection = new SocialConnection;
        $connection->forceFill([
            'provider'     => SocialProvider::Thumbtack->value,
            'remote_id'    => 'biz-1',
            'display_name' => 'A-1 Chimney on Thumbtack',
        ]);
        $connection->connect(['access_token' => 'a-token', 'refresh_token' => 'r']);

        return $connection;
    }
}
