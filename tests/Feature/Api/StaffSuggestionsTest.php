<?php

namespace Tests\Feature\Api;

use App\Models\Notification;
use App\Models\PlatformStaff;
use App\Models\Suggestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The control-plane half of Suggestions.
 *
 * Reads across every tenant on purpose — that is the whole point of the queue —
 * and is the only place in the application allowed to do so for this table.
 */
class StaffSuggestionsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private Google2FA $totp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->totp = app(Google2FA::class);
    }

    private function staffToken(): string
    {
        $staff = PlatformStaff::create([
            'name' => 'Staffer',
            'email' => 'lead@ezbizi.test',
            'password' => 'a-sufficiently-long-password',
            'tier' => PlatformStaff::TIER_LEAD,
            'is_active' => true,
        ]);

        $secret = $this->totp->generateSecretKey(32);
        $staff->totp_secret = $secret;
        $staff->totp_confirmed_at = now();
        $staff->save();

        return $this->postJson('/api/v1/staff/auth/login', [
            'email' => $staff->email,
            'password' => 'a-sufficiently-long-password',
            'code' => $this->totp->getCurrentOtp($secret),
        ])->assertOk()->json('token');
    }

    /**
     * Rows are built through the model inside inTenant(), NOT by signing in and
     * posting.
     *
     * `signedInAs` calls Sanctum::actingAs, which resolves the guard for the
     * rest of the test — a later `withToken` is then ignored, and the staff
     * call authenticates as the tenant administrator and 403s. The failure
     * reads as a broken `staff` middleware rather than as a test-harness
     * problem. StaffPhoneRequestsTest::pendingRequest avoids it the same way.
     *
     * The reverse order is safe: `signedInAs` calls forgetGuards() first, so a
     * tenant call AFTER a staff call works.
     */
    private function suggestionIn(array $t, string $body): Suggestion
    {
        return $this->inTenant($t['org'], fn () => Suggestion::create([
            'user_id' => $t['admin']->id,
            'body' => $body,
            'status' => Suggestion::STATUS_NEW,
        ]));
    }

    public function test_the_queue_spans_organizations(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $beta = $this->provisionTenant('Beta', 'beta@example.test');

        $this->suggestionIn($alpha, 'From Alpha.');
        $this->suggestionIn($beta, 'From Beta.');

        $body = $this->withToken($this->staffToken())
            ->getJson('/api/v1/staff/suggestions')
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $body);
        $this->assertEqualsCanonicalizing(
            ['Alpha', 'Beta'],
            array_column($body, 'organization'),
        );
    }

    public function test_the_queue_names_the_author_and_the_organization(): void
    {
        $t = $this->provisionTenant('Alpha');

        $this->suggestionIn($t, 'Name me.');

        $this->withToken($this->staffToken())
            ->getJson('/api/v1/staff/suggestions')
            ->assertOk()
            ->assertJsonPath('data.0.organization', 'Alpha')
            ->assertJsonPath('data.0.author', 'Alpha Admin')
            ->assertJsonPath('data.0.status', Suggestion::STATUS_NEW);
    }

    public function test_a_tenant_token_cannot_reach_the_queue(): void
    {
        $t = $this->provisionTenant();

        $this->signedInAs($t['admin'])
            ->getJson('/api/v1/staff/suggestions')
            ->assertForbidden();
    }

    /**
     * Built through the model, not over HTTP — see suggestionIn's docblock for
     * why signing in first would make every staff call in these tests 403.
     *
     * @return array{0: string, 1: array}
     */
    private function oneSuggestion(): array
    {
        $t = $this->provisionTenant('Alpha');

        return [$this->suggestionIn($t, 'Decide about me.')->id, $t];
    }

    public function test_approving_tells_the_author(): void
    {
        [$id, $t] = $this->oneSuggestion();

        $this->withToken($this->staffToken())
            ->postJson("/api/v1/staff/suggestions/{$id}/decide", [
                'status' => Suggestion::STATUS_APPROVED,
                'note' => 'Going in the next release.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', Suggestion::STATUS_APPROVED);

        $notification = Notification::query()
            ->withoutGlobalScopes()
            ->where('user_id', $t['admin']->id)
            ->get();

        $this->assertCount(1, $notification);
        $this->assertSame($t['org']->id, $notification->first()->organization_id);
        $this->assertSame(Suggestion::class, $notification->first()->subject_type);
        $this->assertSame($id, $notification->first()->subject_id);
    }

    /**
     * A refusal without a reason is the dead end §6 forbids, and the author
     * reads this sentence word for word.
     */
    public function test_denying_without_a_note_is_refused(): void
    {
        [$id] = $this->oneSuggestion();

        $this->withToken($this->staffToken())
            ->postJson("/api/v1/staff/suggestions/{$id}/decide", [
                'status' => Suggestion::STATUS_DENIED,
                'note' => '',
            ])
            ->assertStatus(422);

        $this->assertDatabaseHas('suggestions', [
            'id' => $id, 'status' => Suggestion::STATUS_NEW,
        ]);
    }

    public function test_approving_without_a_note_is_allowed(): void
    {
        [$id] = $this->oneSuggestion();

        $this->withToken($this->staffToken())
            ->postJson("/api/v1/staff/suggestions/{$id}/decide", [
                'status' => Suggestion::STATUS_APPROVED,
                'note' => null,
            ])
            ->assertOk();
    }

    /**
     * Starting to read is not news, and the bell is the wrong place to learn it.
     */
    public function test_moving_it_into_review_tells_nobody(): void
    {
        [$id, $t] = $this->oneSuggestion();

        $this->withToken($this->staffToken())
            ->postJson("/api/v1/staff/suggestions/{$id}/decide", [
                'status' => Suggestion::STATUS_REVIEWING,
                'note' => null,
            ])
            ->assertOk();

        $this->assertSame(0, Notification::query()
            ->withoutGlobalScopes()
            ->where('user_id', $t['admin']->id)
            ->count());
    }

    /**
     * A note attached to `reviewing` would reach the author's screen with no
     * notification telling them it appeared — the same gap this file's
     * `test_moving_it_into_review_tells_nobody` proves the bell stays silent
     * for. The client disables the note box for this combination; this proves
     * the server refuses it independently of that.
     */
    public function test_moving_it_into_review_with_a_note_is_refused(): void
    {
        [$id] = $this->oneSuggestion();

        $this->withToken($this->staffToken())
            ->postJson("/api/v1/staff/suggestions/{$id}/decide", [
                'status' => Suggestion::STATUS_REVIEWING,
                'note' => 'Looks promising so far.',
            ])
            ->assertStatus(422);

        $this->assertDatabaseHas('suggestions', [
            'id' => $id, 'status' => Suggestion::STATUS_NEW, 'decision_note' => null,
        ]);
    }

    /**
     * A whitespace-only note is no note at all, exactly as `blank()` treats it
     * elsewhere in this controller — it must not trip the refusal above.
     */
    public function test_moving_it_into_review_with_a_blank_note_is_allowed(): void
    {
        [$id, $t] = $this->oneSuggestion();

        $this->withToken($this->staffToken())
            ->postJson("/api/v1/staff/suggestions/{$id}/decide", [
                'status' => Suggestion::STATUS_REVIEWING,
                'note' => '   ',
            ])
            ->assertOk();

        $this->assertSame(0, Notification::query()
            ->withoutGlobalScopes()
            ->where('user_id', $t['admin']->id)
            ->count());
    }

    /**
     * Restoring `new` would hand the author back the right to rewrite text a
     * reviewer has already read.
     */
    public function test_it_cannot_be_put_back_to_new(): void
    {
        [$id] = $this->oneSuggestion();

        $this->withToken($this->staffToken())
            ->postJson("/api/v1/staff/suggestions/{$id}/decide", [
                'status' => Suggestion::STATUS_NEW,
                'note' => 'Never mind.',
            ])
            ->assertStatus(422);
    }

    public function test_a_reversal_replaces_the_answer_and_tells_them_again(): void
    {
        [$id, $t] = $this->oneSuggestion();
        $token = $this->staffToken();

        $this->withToken($token)->postJson("/api/v1/staff/suggestions/{$id}/decide", [
            'status' => Suggestion::STATUS_DENIED,
            'note' => 'Not this quarter.',
        ])->assertOk();

        $this->withToken($token)->postJson("/api/v1/staff/suggestions/{$id}/decide", [
            'status' => Suggestion::STATUS_APPROVED,
            'note' => 'Changed our minds.',
        ])->assertOk()->assertJsonPath('data.decision_note', 'Changed our minds.');

        $this->assertSame(2, Notification::query()
            ->withoutGlobalScopes()
            ->where('user_id', $t['admin']->id)
            ->count());
    }

    /** The author reads the note; a colleague still reads nothing. */
    public function test_the_author_sees_the_decision_and_the_note(): void
    {
        [$id, $t] = $this->oneSuggestion();

        $this->withToken($this->staffToken())
            ->postJson("/api/v1/staff/suggestions/{$id}/decide", [
                'status' => Suggestion::STATUS_DENIED,
                'note' => 'The board already does this — see the filter menu.',
            ])->assertOk();

        $this->signedInAs($t['admin'])
            ->getJson('/api/v1/suggestions')
            ->assertOk()
            ->assertJsonPath('data.0.status', Suggestion::STATUS_DENIED)
            ->assertJsonPath('data.0.decision_note', 'The board already does this — see the filter menu.');
    }

    /** Jose, 2026-11-17: the author sees an approved suggestion move toward release. */
    public function test_an_approved_suggestion_carries_its_progress_to_the_author(): void
    {
        [$id, $t] = $this->oneSuggestion();
        $staff = $this->withToken($this->staffToken());

        $staff->postJson("/api/v1/staff/suggestions/{$id}/progress", ['progress' => 'building'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('progress');

        $staff->postJson("/api/v1/staff/suggestions/{$id}/decide", ['status' => Suggestion::STATUS_APPROVED])
            ->assertOk()
            ->assertJsonPath('data.progress', 'approved');

        $staff->postJson("/api/v1/staff/suggestions/{$id}/progress", [
            'progress' => 'releasing',
            'release_version' => 'v0.0.0584',
        ])->assertOk()
            ->assertJsonPath('data.progress', 'releasing')
            ->assertJsonPath('data.release_version', '0.0.0584');

        $staff->postJson("/api/v1/staff/suggestions/{$id}/progress", ['progress' => 'shipped'])
            ->assertStatus(422);

        $this->signedInAs($t['admin'])
            ->getJson('/api/v1/suggestions')
            ->assertOk()
            ->assertJsonPath('data.0.progress', 'releasing')
            ->assertJsonPath('data.0.release_version', '0.0.0584');
    }

    /** Jose, 2026-11-17: the author gets the Alerts pop-up when it is approved and when it goes live, once each. */
    public function test_the_author_gets_a_pop_up_on_approval_and_on_going_live_once(): void
    {
        [$id, $t] = $this->oneSuggestion();
        $staff = $this->withToken($this->staffToken());
        $alerts = fn () => Notification::query()->withoutGlobalScopes()
            ->where('user_id', $t['admin']->id)->where('source', 'alert')->orderBy('created_at')->get();

        $staff->postJson("/api/v1/staff/suggestions/{$id}/decide", [
            'status' => Suggestion::STATUS_APPROVED,
            'note' => 'Going in soon.',
        ])->assertOk();
        $this->assertCount(1, $alerts());
        $this->assertSame('Your suggestion was approved: Decide about me. Going in soon.', $alerts()->first()->title);

        $staff->postJson("/api/v1/staff/suggestions/{$id}/progress", ['progress' => 'building'])->assertOk();
        $this->assertCount(1, $alerts(), 'Building tells nobody.');

        $staff->postJson("/api/v1/staff/suggestions/{$id}/progress", ['progress' => 'live', 'release_version' => '0.0.0584'])->assertOk();
        $staff->postJson("/api/v1/staff/suggestions/{$id}/progress", ['progress' => 'live'])->assertOk();
        $this->assertCount(2, $alerts(), 'Live once, not on every save.');
        $this->assertSame('Your suggestion is live in v0.0.0584: Decide about me. Please update to see it.', $alerts()->last()->title);

        // A save from anywhere (the desk's tinker) tells them too: the model's own event.
        $this->inTenant($t['org'], function () use ($id) {
            Suggestion::query()->find($id)->update(['progress' => 'testing']);
            Suggestion::query()->find($id)->update(['progress' => 'live']);
        });
        $this->assertCount(3, $alerts());

        $popups = $this->signedInAs($t['admin'])->getJson('/api/v1/notifications')->assertOk()->json('meta.alerts');
        $this->assertCount(3, $popups);
        $this->assertSame($id, $popups[0]['suggestion_id']);
        $this->assertFalse($popups[0]['update']);
        $this->assertTrue($popups[1]['update']);
        $this->assertSame('EzBizi', $popups[1]['sender']);
    }

    public function test_denying_clears_the_progress(): void
    {
        [$id] = $this->oneSuggestion();
        $staff = $this->withToken($this->staffToken());

        $staff->postJson("/api/v1/staff/suggestions/{$id}/decide", ['status' => Suggestion::STATUS_APPROVED])->assertOk();
        $staff->postJson("/api/v1/staff/suggestions/{$id}/progress", ['progress' => 'testing'])->assertOk();

        $staff->postJson("/api/v1/staff/suggestions/{$id}/decide", [
            'status' => Suggestion::STATUS_DENIED,
            'note' => 'It broke the board; taking it back.',
        ])->assertOk()
            ->assertJsonPath('data.progress', null)
            ->assertJsonPath('data.release_version', null);
    }

    /** The search bar: `q` narrows by words or organization, across tenants; a blank `q` is no filter. */
    public function test_q_narrows_the_queue_and_a_blank_q_changes_nothing(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $beta = $this->provisionTenant('Beta', 'beta@example.test');
        $this->suggestionIn($alpha, 'Let me print the route sheet.');
        $this->suggestionIn($beta, 'Dark mode please.');

        $token = $this->staffToken();
        $this->withToken($token)->getJson('/api/v1/staff/suggestions?q=route%20sheet')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.organization', 'Alpha');
        $this->withToken($token)->getJson('/api/v1/staff/suggestions?q=beta')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.organization', 'Beta');
        $this->withToken($token)->getJson('/api/v1/staff/suggestions?q=')->assertOk()->assertJsonCount(2, 'data');
        $this->withToken($token)->getJson('/api/v1/staff/suggestions')->assertOk()->assertJsonCount(2, 'data');
    }
}
