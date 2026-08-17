<?php

namespace Tests\Feature\Api;

use App\Http\Middleware\EnforceMinimumClientVersion;
use App\Models\AuditEvent;
use App\Models\PlatformMinimumClientVersion;
use App\Models\PlatformStaff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PragmaRX\Google2FA\Google2FA;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The minimum client version gate.
 *
 * ── What these assertions are actually protecting ────────────────
 *
 * Half of this file tests that the gate REFUSES, and that half is the easy
 * half. The other half tests that it ALLOWS — no header, an unreadable version,
 * an unconfigured platform, an unknown platform — and those are the ones that
 * matter, because every EzBizi client in existence today sends no version
 * header at all. A gate that failed closed would lock every customer and every
 * member of staff out of the entire product on the day it deployed, with the
 * sign-in they would need to switch it off sitting behind the refusal.
 *
 * So the allow tests are not "coverage". They are the specification.
 *
 * ── No assertions on stored JSON key order ───────────────────────
 *
 * The suite runs on MySQL in the deploy gate, and MySQL does not preserve the
 * key order of a JSON column — it sorts keys by length and then
 * lexicographically. Anything here that looks at audit metadata reads it by KEY.
 */
class MinimumClientVersionTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /**
     * Headers as a real client would send them.
     *
     * @return array<string, string>
     */
    private function client(string $platform, ?string $version): array
    {
        $headers = [EnforceMinimumClientVersion::PLATFORM_HEADER => $platform];

        if ($version !== null) {
            $headers[EnforceMinimumClientVersion::VERSION_HEADER] = $version;
        }

        return $headers;
    }

    private function minimum(string $platform, ?string $version, array $extra = []): PlatformMinimumClientVersion
    {
        return PlatformMinimumClientVersion::create([
            'platform'        => $platform,
            'minimum_version' => $version,
            ...$extra,
        ]);
    }

    /** A signed-in staff account, past the TOTP requirement that login enforces. */
    private function staff(string $tier = PlatformStaff::TIER_LEAD): PlatformStaff
    {
        $staff = PlatformStaff::create([
            'name'      => 'Platform Staff',
            'email'     => $tier.'@ezbizi.test',
            'password'  => 'a-sufficiently-long-password',
            'tier'      => $tier,
            'is_active' => true,
        ]);

        $staff->totp_secret = app(Google2FA::class)->generateSecretKey(32);
        $staff->totp_confirmed_at = now();
        $staff->save();

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($staff);

        return $staff;
    }

    /**
     * A staff account that can go through the real sign-in, and the secret its
     * authenticator holds.
     *
     * Separate from staff() above because that one shortcuts straight to an
     * authenticated state with Sanctum::actingAs — which is right for testing
     * what a signed-in staff member may do, and useless for testing whether
     * they can sign in at all, which is the question the gate got wrong.
     *
     * @return array{0: PlatformStaff, 1: string}
     */
    private function staffWhoCanSignIn(): array
    {
        $staff = PlatformStaff::create([
            'name'      => 'Platform Staff',
            'email'     => 'signs-in@ezbizi.test',
            'password'  => 'a-sufficiently-long-password',
            'tier'      => PlatformStaff::TIER_LEAD,
            'is_active' => true,
        ]);

        $secret = app(Google2FA::class)->generateSecretKey(32);
        $staff->totp_secret = $secret;
        $staff->totp_confirmed_at = now();
        $staff->save();

        return [$staff, $secret];
    }

    // ── the contract that crosses the wire ───────────────────────

    /**
     * The header names, as LITERAL STRINGS, on both sides of the boundary.
     *
     * ── Why this test exists ─────────────────────────────────────
     *
     * The client shipped `X-Client-Platform` / `X-Client-Version` while the
     * middleware read `X-EzBizi-Client-Platform` / `X-EzBizi-Client-Version`.
     * The gate therefore saw no platform header from any real client, took the
     * fail-open branch meant for clients that send nothing, and refused nobody.
     * The whole feature was inert in production: staff could raise a minimum,
     * see the control plane report it as enforced, and stop no one.
     *
     * Both suites were green the entire time, and that is the part worth
     * understanding. Every other test in this file builds its request from
     * EnforceMinimumClientVersion::PLATFORM_HEADER — so it can never disagree
     * with the middleware, whatever either of them says. The Flutter suite
     * asserted on the client's own spelling for the same reason. Each half was
     * self-consistent; the two literals never met in one assertion anywhere.
     *
     * So this test does the two things none of the others could. It types the
     * strings out rather than reading the constants, and it reads the Flutter
     * source to check the other end says the same thing. Reading a file from a
     * PHPUnit test is unusual and deliberate: it is the only place in this
     * repository where both ends of this contract can be looked at together,
     * and the alternative — two suites each certifying their own half — is
     * exactly what shipped a dead feature.
     */
    public function test_the_gate_reads_the_header_names_the_client_actually_sends(): void
    {
        $this->minimum('windows', '0.0.0030');

        // Typed out, not read from the constants. If somebody renames the
        // constants this fails, which is the entire point.
        $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.test', 'password' => 'x'], [
            'X-EzBizi-Client-Platform' => 'windows',
            'X-EzBizi-Client-Version'  => '0.0.0029',
        ])->assertStatus(426);

        $client = base_path('client/lib/core/api_client.dart');

        if (! file_exists($client)) {
            // The Flutter client is not always present — a backend-only checkout
            // or a container built from composer alone. Skipping says so out
            // loud; passing silently would be this test quietly becoming the
            // half-a-contract test it was written to replace.
            $this->markTestSkipped('The Flutter client is not in this checkout.');
        }

        $source = file_get_contents($client);

        foreach (['X-EzBizi-Client-Platform', 'X-EzBizi-Client-Version'] as $header) {
            $this->assertStringContainsString(
                "'{$header}'",
                $source,
                "The client does not send {$header}, so the gate reads null for every "
                .'request from every real build and fails open forever. This is not a '
                .'naming preference — it is the difference between a feature and nothing.',
            );
        }
    }

    /**
     * The keys of the refusal body, pinned as literals for the same reason.
     *
     * The client read `upgrade_url`, a key this server has never emitted under
     * any configuration. Nothing crashed — the address was simply dropped, and
     * every customer on a refused installed build was told "there is no download
     * address set for this kind of device yet" while the address staff had
     * configured sat two fields away in the same payload. A dead end that also
     * states something false about the configuration.
     *
     * assertJsonStructure with an exact list, so ADDING a key is as loud as
     * renaming one: a key that appears here and is never read on the other side
     * is the shape this defect took the first time.
     */
    public function test_the_refusal_body_carries_the_keys_the_client_reads(): void
    {
        $this->minimum('windows', '0.0.0030', [
            'download_url' => 'https://downloads.ezbizi.com/windows',
            'note'         => 'This update fixes invoice totals.',
        ]);

        $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.test', 'password' => 'x'],
            $this->client('windows', '0.0.0029'))
            ->assertStatus(426)
            ->assertExactJsonStructure([
                'message', 'code', 'detail', 'platform',
                'minimum_version', 'your_version', 'download_url', 'note',
            ])
            // The two the client reads besides `message`. Flat, at the top
            // level, in the shape Laravel uses for every other refusal here.
            ->assertJsonPath('download_url', 'https://downloads.ezbizi.com/windows')
            ->assertJsonPath('note', 'This update fixes invoice totals.');
    }

    // ── the gate refuses ─────────────────────────────────────────

    public function test_an_outdated_client_is_refused_with_426_and_a_sentence(): void
    {
        $this->minimum('windows', '0.0.0030', [
            'download_url' => 'https://downloads.ezbizi.com/windows',
        ]);

        $tenant = $this->provisionTenant('Alpha');

        $response = $this->signedInAs($tenant['admin'])
            ->withHeaders($this->client('windows', '0.0.0029'))
            ->getJson('/api/v1/customers')
            ->assertStatus(426)
            ->assertJsonPath('code', 'client_too_old')
            ->assertJsonPath('minimum_version', '0.0.0030')
            ->assertJsonPath('your_version', '0.0.0029')
            ->assertJsonPath('download_url', 'https://downloads.ezbizi.com/windows');

        // A sentence a person can read, not a code and a number. The specific
        // failure being prevented is a client showing "client_too_old" to a
        // customer, so the message has to carry the meaning by itself.
        $message = $response->json('message');
        $this->assertStringContainsString('too old', $message);
        $this->assertStringContainsString('EzBizi', $message);
        $this->assertGreaterThan(40, strlen($message), 'The refusal must be a sentence, not a label.');

        // And it must say what to do next — no dead ends.
        $this->assertStringContainsString(
            'https://downloads.ezbizi.com/windows',
            $response->json('detail'),
        );

        // The mirror of the web assertions: an installed app is never told to
        // reload a page it does not have.
        $this->assertDoesNotMatchRegularExpression('/\bpage\b|reload/i', $message);
    }

    public function test_the_refusal_still_says_what_to_do_when_no_download_url_is_configured(): void
    {
        $this->minimum('android', '0.0.0030');

        $detail = $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.test', 'password' => 'x'],
            $this->client('android', '0.0.0001'))
            ->assertStatus(426)
            ->json('detail');

        // A statement of a problem with no action attached is the one shape this
        // must never take.
        $this->assertNotEmpty($detail);
        $this->assertStringContainsString('EzBizi', $detail);
        $this->assertMatchesRegularExpression('/ask|contact/i', $detail);
    }

    public function test_web_is_told_to_reload_and_never_to_install_anything(): void
    {
        $this->minimum('web', '0.0.0030');

        $body = $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.test', 'password' => 'x'],
            $this->client('web', '0.0.0029'))
            ->assertStatus(426)
            ->json();

        // The MESSAGE, on its own, has to be the web one. Asserting against the
        // message and the remedy joined together is not enough: the remedy says
        // "reload" for web whatever the message says, so a sentence written for
        // an installed app would slip through beside it and the customer would
        // be told their copy of the app is out of date while they look at a
        // browser tab.
        $this->assertMatchesRegularExpression('/\bpage\b/i', $body['message']);
        $this->assertDoesNotMatchRegularExpression(
            '/\bapp\b|\bcopy\b/i',
            $body['message'],
            'A browser user has no copy of an app; this is a tab that has been open too long.',
        );

        $copy = $body['message'].' '.$body['detail'];

        $this->assertMatchesRegularExpression('/reload/i', $copy);
        $this->assertDoesNotMatchRegularExpression(
            '/install|download/i',
            $copy,
            'A browser user has nothing to install; sending them looking for an installer is a dead end.',
        );
        $this->assertNull($body['download_url']);
    }

    public function test_web_is_never_offered_a_download_link_even_if_a_row_carries_one(): void
    {
        // The staff endpoint clears the link for web, so a row shaped like this
        // can only arrive some other way. The refusal still says "reload":
        // handing a browser user an installer link is a dead end dressed up as
        // help, and the moment they are shown it is the moment they are anxious
        // and inclined to click.
        $this->minimum('web', '0.0.0030', ['download_url' => 'https://downloads.ezbizi.com/windows']);

        $body = $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.test', 'password' => 'x'],
            $this->client('web', '0.0.0029'))
            ->assertStatus(426)
            ->json();

        $this->assertNull($body['download_url']);
        $this->assertStringNotContainsString('downloads.ezbizi.com', $body['detail']);
    }

    public function test_the_gate_refuses_before_authentication_so_the_reason_is_never_mistaken_for_a_bad_password(): void
    {
        $this->minimum('windows', '0.0.0030');

        // Unauthenticated, and deliberately with credentials that do not exist.
        // If the gate ran after auth this would be a 401 — which every client
        // answers by signing the user out and showing the login screen, i.e. by
        // hiding the real problem behind an infinite sign-in loop.
        $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.test', 'password' => 'wrong'],
            $this->client('windows', '0.0.0029'))
            ->assertStatus(426)
            ->assertJsonPath('code', 'client_too_old');
    }

    // ── the gate allows: rule 2, fail open ───────────────────────

    public function test_a_client_on_exactly_the_minimum_passes(): void
    {
        $this->minimum('windows', '0.0.0030');

        $tenant = $this->provisionTenant('Alpha');

        // The minimum is the oldest ALLOWED build. If this were exclusive,
        // setting the minimum to the version you just shipped would refuse
        // everybody who had already installed it.
        $this->signedInAs($tenant['admin'])
            ->withHeaders($this->client('windows', '0.0.0030'))
            ->getJson('/api/v1/customers')
            ->assertOk();
    }

    public function test_an_up_to_date_client_passes(): void
    {
        $this->minimum('windows', '0.0.0030');

        $tenant = $this->provisionTenant('Alpha');

        $this->signedInAs($tenant['admin'])
            ->withHeaders($this->client('windows', '0.0.0031'))
            ->getJson('/api/v1/customers')
            ->assertOk();
    }

    public function test_zero_padding_is_cosmetic_and_segments_compare_as_numbers(): void
    {
        $this->minimum('windows', '0.0.0099');

        $tenant = $this->provisionTenant('Alpha');

        // 0.0.0100 sorts BEFORE 0.0.0099 as text. Compared as numbers it is
        // newer, which is the only reading that survives build 100.
        $this->signedInAs($tenant['admin'])
            ->withHeaders($this->client('windows', '0.0.0100'))
            ->getJson('/api/v1/customers')
            ->assertOk();
    }

    public function test_a_client_sending_no_headers_at_all_passes(): void
    {
        // The whole product, as it exists today: the client sends no version
        // header, and a minimum has been set for its platform. This is the test
        // that stands between a raised minimum and a total outage.
        $this->minimum('windows', '0.0.0030');

        $tenant = $this->provisionTenant('Alpha');

        $this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/customers')
            ->assertOk();
    }

    public function test_a_platform_header_with_no_version_header_passes(): void
    {
        $this->minimum('windows', '0.0.0030');

        $tenant = $this->provisionTenant('Alpha');

        $this->signedInAs($tenant['admin'])
            ->withHeaders($this->client('windows', null))
            ->getJson('/api/v1/customers')
            ->assertOk();
    }

    // The attribute, not the `@dataProvider` doc comment: PHPUnit 12 removed
    // the annotation form, and a provider it cannot see makes the test method
    // run once with no arguments and fail on the signature.
    #[DataProvider('unreadableVersions')]
    public function test_a_malformed_version_passes(string $version): void
    {
        $this->minimum('windows', '0.0.0030');

        $tenant = $this->provisionTenant('Alpha');

        $this->signedInAs($tenant['admin'])
            ->withHeaders($this->client('windows', $version))
            ->getJson('/api/v1/customers')
            ->assertOk("'{$version}' cannot be compared, so it must not be refused.");
    }

    public static function unreadableVersions(): array
    {
        return [
            'a v prefix'        => ['v0.0.0029'],
            'words'             => ['dev'],
            'empty'             => [''],
            'trailing dot'      => ['0.0.'],
            'letters in a part' => ['0.0.29a'],
            'not a version'     => ['{{VERSION}}'],
            'absurdly deep'     => ['1.1.1.1.1.1.1.1'],
            'absurdly long'     => ['0.0.00000000000000029'],
        ];
    }

    public function test_a_build_suffix_is_ignored_rather_than_making_a_version_unreadable(): void
    {
        $this->minimum('android', '0.0.0030');

        $tenant = $this->provisionTenant('Alpha');

        // Flutter writes `version+build`. The suffix carries no ordering we can
        // trust across channels, so it is discarded — "0.0.0030+41" is the same
        // build as "0.0.0030" and passes a minimum of 0.0.0030.
        $this->signedInAs($tenant['admin'])
            ->withHeaders($this->client('android', '0.0.0030+41'))
            ->getJson('/api/v1/customers')
            ->assertOk();

        // A pre-release tag is treated as its release, so internal testers on a
        // release candidate are not locked out by the minimum they are testing.
        $this->signedInAs($tenant['admin'])
            ->withHeaders($this->client('android', '0.0.0030-rc.1'))
            ->getJson('/api/v1/customers')
            ->assertOk();

        // The discriminating half, and the reason the two assertions above are
        // not enough on their own: an OLD version carrying a suffix is still
        // REFUSED. If the suffix made the whole string unreadable rather than
        // being discarded, everything above would still pass — an unreadable
        // version allows — and "ignored" would be indistinguishable from
        // "broken".
        $this->signedInAs($tenant['admin'])
            ->withHeaders($this->client('android', '0.0.0029+41'))
            ->getJson('/api/v1/customers')
            ->assertStatus(426);
    }

    public function test_a_client_with_more_segments_than_the_minimum_passes(): void
    {
        $this->minimum('windows', '0.0.0030');

        $tenant = $this->provisionTenant('Alpha');

        // The extra segment makes it strictly newer. Being ahead of the format
        // the minimum was written in is the normal case, not an error.
        $this->signedInAs($tenant['admin'])
            ->withHeaders($this->client('windows', '0.0.0030.1'))
            ->getJson('/api/v1/customers')
            ->assertOk();
    }

    public function test_a_platform_with_no_configured_minimum_passes(): void
    {
        // Windows is gated; nothing has ever been set for iOS. iOS sails through
        // — which is the whole reason minimums are per platform.
        $this->minimum('windows', '0.0.0030');

        $tenant = $this->provisionTenant('Alpha');

        $this->signedInAs($tenant['admin'])
            ->withHeaders($this->client('ios', '0.0.0001'))
            ->getJson('/api/v1/customers')
            ->assertOk();
    }

    public function test_a_row_with_a_null_minimum_refuses_nobody(): void
    {
        // The undo: clearing the minimum lifts the gate for that platform.
        $this->minimum('windows', null, ['download_url' => 'https://downloads.ezbizi.com/windows']);

        $tenant = $this->provisionTenant('Alpha');

        $this->signedInAs($tenant['admin'])
            ->withHeaders($this->client('windows', '0.0.0001'))
            ->getJson('/api/v1/customers')
            ->assertOk();
    }

    public function test_a_stored_minimum_that_cannot_be_parsed_refuses_nobody(): void
    {
        // Written straight to the table, bypassing the endpoint's validation, so
        // this covers a row that arrived some other way — a manual fix, a
        // restore, an older version of the endpoint.
        $this->minimum('windows', 'v0.0.0030');

        $tenant = $this->provisionTenant('Alpha');

        $this->signedInAs($tenant['admin'])
            ->withHeaders($this->client('windows', '0.0.0001'))
            ->getJson('/api/v1/customers')
            ->assertOk();
    }

    public function test_a_gate_that_cannot_reach_its_own_table_lets_the_request_through(): void
    {
        $this->minimum('windows', '0.0.0030');

        $tenant = $this->provisionTenant('Alpha');

        // The table the gate reads is gone. This stands for the whole class of
        // "the decision itself threw" — an unreachable database, a schema caught
        // mid-migration, an exception from some later edit to this code. Every
        // one of them must ALLOW, for exactly the reason an unreadable version
        // does: a courtesy check that takes the entire product offline when its
        // own storage hiccups is far worse than the stale client it was
        // guarding against.
        Schema::drop('platform_minimum_client_versions');

        $this->signedInAs($tenant['admin'])
            ->withHeaders($this->client('windows', '0.0.0001'))
            ->getJson('/api/v1/customers')
            ->assertOk();
    }

    public function test_an_unknown_platform_passes(): void
    {
        $this->minimum('windows', '0.0.0030');

        // A row for a platform EzBizi does not ship a client for. The column is
        // a plain string, so such a row is writable by a restore, a manual fix
        // or a future migration — and it must be inert. The authoritative list
        // of platforms lives in code, and a header naming anything outside it is
        // never judged, because a minimum we cannot have meant to set is not a
        // minimum.
        $this->minimum('nintendo-switch', '9.9.9999');

        $tenant = $this->provisionTenant('Alpha');

        $this->signedInAs($tenant['admin'])
            ->withHeaders($this->client('nintendo-switch', '0.0.0001'))
            ->getJson('/api/v1/customers')
            ->assertOk();
    }

    public function test_the_platform_header_is_matched_case_insensitively(): void
    {
        // Platform APIs answer "Windows"; a lockout caused by capitalisation
        // would be the stupidest possible version of this outage.
        $this->minimum('windows', '0.0.0030');

        $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.test', 'password' => 'x'],
            $this->client('  Windows ', '0.0.0029'))
            ->assertStatus(426);
    }

    // ── rule 4: the gate never blocks the way out ────────────────

    public function test_the_health_endpoint_answers_an_outdated_client(): void
    {
        $this->minimum('windows', '0.0.0030');
        $this->staff();

        // The page somebody opens to find out why the product is behaving
        // oddly. A version gate that blanked it would be hiding the evidence of
        // its own decision.
        $this->withHeaders($this->client('windows', '0.0.0001'))
            ->getJson('/api/v1/staff/health')
            ->assertOk()
            ->assertJsonStructure(['backups', 'mail', 'outbox', 'checked_at']);
    }

    public function test_signing_out_works_for_an_outdated_client(): void
    {
        $this->minimum('windows', '0.0.0030');

        $tenant = $this->provisionTenant('Alpha');

        // Somebody stuck behind the refusal must still be able to put the app
        // down cleanly rather than leaving a live session on the machine.
        $this->signedInAs($tenant['admin'])
            ->withHeaders($this->client('windows', '0.0.0001'))
            ->postJson('/api/v1/auth/logout')
            ->assertOk();
    }

    /**
     * The lockout the exemption list exists to prevent, followed all the way
     * through to the undo.
     *
     * Exempting the client-versions routes is not enough on its own, and that
     * gap was real: both of them sit behind auth:sanctum, so they answer 401
     * without a staff token — and the request that OBTAINS the token was being
     * refused by the very minimum it exists to lower. A staff member whose
     * twelve-hour token had lapsed, or who was at a different machine, had no
     * way back in through EzBizi's own client. Signing out is deliberately
     * exempt, so the exit was open and the entrance was not.
     *
     * Deliberately uses `web`, because that is the worst case and the most
     * likely typo: web is the client every member of staff is using, so a
     * minimum set one build too high locks the control plane out of itself.
     */
    public function test_staff_can_sign_in_and_lift_a_minimum_that_refuses_their_own_build(): void
    {
        $this->minimum('web', '0.0.0031');

        [$staff, $secret] = $this->staffWhoCanSignIn();

        $token = $this->withHeaders($this->client('web', '0.0.0030'))
            ->postJson('/api/v1/staff/auth/login', [
                'email'    => $staff->email,
                'password' => 'a-sufficiently-long-password',
                'code'     => app(Google2FA::class)->getCurrentOtp($secret),
            ])
            ->assertOk()
            ->json('token');

        // And the token it issued reaches the undo. Signing in without being
        // able to act on it would be a longer walk to the same dead end.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/v1/staff/client-versions/web', ['minimum_version' => null])
            ->assertOk()
            ->assertJsonPath('data.is_enforced', false);
    }

    /**
     * The same for enrolment, which is the other half of "cannot sign in".
     *
     * A staff account with no confirmed authenticator cannot sign in at all —
     * that is what enrolment is for — so refusing it here refuses somebody who
     * has no other route. Exempting it grants nobody anything: it is
     * unauthenticated by necessity, throttled per address, and audited on every
     * attempt whether or not it succeeds.
     */
    public function test_staff_enrolment_is_not_refused_by_a_minimum(): void
    {
        $this->minimum('web', '0.0.0031');

        $staff = PlatformStaff::create([
            'name'      => 'Newly Hired',
            'email'     => 'new@ezbizi.test',
            'password'  => 'a-sufficiently-long-password',
            'tier'      => PlatformStaff::TIER_DEV,
            'is_active' => true,
        ]);

        $this->withHeaders($this->client('web', '0.0.0030'))
            ->postJson('/api/v1/staff/auth/enrolment', [
                'email'    => $staff->email,
                'password' => 'a-sufficiently-long-password',
            ])
            ->assertOk();
    }

    /**
     * The tenant sign-in is deliberately NOT exempt, and this pins that apart
     * from the exemptions above so the two are not "tidied" into one rule.
     *
     * A customer signing in on a stale build must be told their app is out of
     * date. Letting them through would sign them in to an application that then
     * fails on its next request, with the one moment they could have acted on
     * the explanation already behind them.
     */
    public function test_the_tenant_sign_in_is_still_refused_to_an_outdated_client(): void
    {
        $this->minimum('web', '0.0.0031');

        $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.test', 'password' => 'x'],
            $this->client('web', '0.0.0030'))
            ->assertStatus(426);
    }

    public function test_the_minimums_screen_answers_an_outdated_client(): void
    {
        $this->minimum('windows', '0.0.0030');
        $this->staff();

        // The staff member lowering a minimum that was set too high must not be
        // refused by the very rule they are lifting.
        $this->withHeaders($this->client('windows', '0.0.0001'))
            ->getJson('/api/v1/staff/client-versions')
            ->assertOk();

        $this->withHeaders($this->client('windows', '0.0.0001'))
            ->putJson('/api/v1/staff/client-versions/windows', ['minimum_version' => null])
            ->assertOk()
            ->assertJsonPath('data.minimum_version', null);
    }

    // ── the control plane: who may read and write the minimums ───

    public function test_a_customer_cannot_read_the_minimums(): void
    {
        $this->minimum('windows', '0.0.0030');

        $tenant = $this->provisionTenant('Alpha');

        // The organization's Administrator — the most privileged person inside a
        // tenant — and still refused. This is platform data, not a tenant
        // setting, and there is no tenant role that reaches it.
        $this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/staff/client-versions')
            ->assertForbidden();
    }

    public function test_a_customer_cannot_write_the_minimums(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $this->signedInAs($tenant['admin'])
            ->putJson('/api/v1/staff/client-versions/windows', [
                'minimum_version' => '0.0.0001',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('platform_minimum_client_versions', 0);
    }

    public function test_an_unauthenticated_caller_cannot_read_or_write_the_minimums(): void
    {
        $this->getJson('/api/v1/staff/client-versions')->assertUnauthorized();

        $this->putJson('/api/v1/staff/client-versions/windows', ['minimum_version' => '0.0.0001'])
            ->assertUnauthorized();
    }

    public function test_staff_see_every_platform_including_the_unconfigured_ones(): void
    {
        $this->minimum('windows', '0.0.0030', ['download_url' => 'https://downloads.ezbizi.com/windows']);
        $this->staff();

        $data = $this->getJson('/api/v1/staff/client-versions')->assertOk()->json('data');

        $this->assertSame(PlatformMinimumClientVersion::PLATFORMS, array_column($data, 'platform'));

        $byPlatform = array_column($data, null, 'platform');

        $this->assertTrue($byPlatform['windows']['is_enforced']);
        $this->assertSame('0.0.0030', $byPlatform['windows']['minimum_version']);

        // The unconfigured platforms are the interesting half of this screen:
        // they are where a stale client is currently sailing through.
        $this->assertFalse($byPlatform['ios']['is_enforced']);
        $this->assertNull($byPlatform['ios']['minimum_version']);

        // Web has no installer, and the server says so rather than the client
        // deciding it independently.
        $this->assertFalse($byPlatform['web']['takes_download_url']);
        $this->assertTrue($byPlatform['windows']['takes_download_url']);
    }

    public function test_staff_can_set_a_minimum_and_it_takes_effect_immediately(): void
    {
        $staff = $this->staff();

        $this->putJson('/api/v1/staff/client-versions/windows', [
            'minimum_version' => '0.0.0030',
            'download_url'    => 'https://downloads.ezbizi.com/windows',
            'note'            => 'This update fixes invoice totals.',
        ])->assertOk()->assertJsonPath('data.is_enforced', true);

        $tenant = $this->provisionTenant('Alpha');

        $body = $this->signedInAs($tenant['admin'])
            ->withHeaders($this->client('windows', '0.0.0029'))
            ->getJson('/api/v1/customers')
            ->assertStatus(426)
            ->json();

        // The staff note reaches the customer, appended to the remedy rather
        // than replacing it.
        $this->assertStringContainsString('This update fixes invoice totals.', $body['detail']);
        $this->assertStringContainsString('https://downloads.ezbizi.com/windows', $body['detail']);
    }

    public function test_clearing_a_minimum_lifts_the_gate(): void
    {
        $this->minimum('windows', '0.0.0030');
        $this->staff();

        $this->putJson('/api/v1/staff/client-versions/windows', ['minimum_version' => null])
            ->assertOk()
            ->assertJsonPath('data.is_enforced', false);

        $tenant = $this->provisionTenant('Alpha');

        $this->signedInAs($tenant['admin'])
            ->withHeaders($this->client('windows', '0.0.0001'))
            ->getJson('/api/v1/customers')
            ->assertOk();
    }

    public function test_a_minimum_the_gate_could_never_compare_is_refused_at_the_point_it_is_typed(): void
    {
        $this->staff();

        // Stored, this would silently mean "no minimum" — and somebody would go
        // home believing the gate was up.
        $this->putJson('/api/v1/staff/client-versions/windows', ['minimum_version' => 'v0.0.30'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('minimum_version');

        $this->assertDatabaseCount('platform_minimum_client_versions', 0);
    }

    public function test_a_save_that_forgets_to_state_the_minimum_is_refused(): void
    {
        $this->minimum('windows', '0.0.0030');
        $this->staff();

        // Under PUT an omitted field is a cleared field, so a save that leaves
        // minimum_version out would lift the gate — and "I forgot" and "I meant
        // to" would be the same request. Lifting it is still one call away; it
        // just has to be said.
        $this->putJson('/api/v1/staff/client-versions/windows', [
            'note' => 'Only touching the note.',
        ])->assertStatus(422)->assertJsonValidationErrors('minimum_version');

        $this->assertDatabaseHas('platform_minimum_client_versions', [
            'platform'        => 'windows',
            'minimum_version' => '0.0.0030',
        ]);
    }

    public function test_a_download_link_offered_for_web_is_not_stored(): void
    {
        $this->staff();

        // The screen does not offer this field for web (`takes_download_url` is
        // false), so a link arriving here is a client that has not been told, or
        // somebody experimenting. Accepting it would leave a value in the
        // database that nothing will ever show — a setting that looks set and
        // does nothing, which is worse than not having the field at all.
        $this->putJson('/api/v1/staff/client-versions/web', [
            'minimum_version' => '0.0.0030',
            'download_url'    => 'https://downloads.ezbizi.com/windows',
        ])->assertOk()->assertJsonPath('data.download_url', null);

        $this->assertDatabaseHas('platform_minimum_client_versions', [
            'platform'     => 'web',
            'download_url' => null,
        ]);
    }

    public function test_a_plain_http_download_link_is_refused(): void
    {
        $this->staff();

        $this->putJson('/api/v1/staff/client-versions/windows', [
            'minimum_version' => '0.0.0030',
            'download_url'    => 'http://downloads.ezbizi.com/windows',
        ])->assertStatus(422)->assertJsonValidationErrors('download_url');
    }

    public function test_an_unknown_platform_is_refused_with_the_list_of_real_ones(): void
    {
        $this->staff();

        $message = $this->putJson('/api/v1/staff/client-versions/nintendo-switch', [
            'minimum_version' => '0.0.0030',
        ])->assertStatus(422)->json('errors.platform.0');

        // Naming the six is what turns a refusal into an instruction.
        foreach (PlatformMinimumClientVersion::PLATFORMS as $platform) {
            $this->assertStringContainsString($platform, $message);
        }
    }

    public function test_setting_a_minimum_is_audited(): void
    {
        $staff = $this->staff();

        $this->minimum('windows', '0.0.0020');

        $this->putJson('/api/v1/staff/client-versions/windows', [
            'minimum_version' => '0.0.0030',
        ])->assertOk();

        $event = AuditEvent::where('action', 'staff.minimum_client_version_changed')->sole();

        $this->assertSame($staff->getKey(), $event->actor_id);

        // Read by key, never by position: MySQL reorders JSON object keys, and
        // asserting on their order is how nine tests passed on sqlite and failed
        // the real deploy gate.
        $this->assertSame('windows', $event->metadata['platform']);
        $this->assertSame('0.0.0020', $event->metadata['before']['minimum_version']);
        $this->assertSame('0.0.0030', $event->metadata['after']['minimum_version']);
    }

    // ── the comparison itself ────────────────────────────────────

    #[DataProvider('comparisons')]
    public function test_the_decision_lives_on_the_model(?string $minimum, ?string $client, bool $expected): void
    {
        $row = new PlatformMinimumClientVersion(['platform' => 'windows', 'minimum_version' => $minimum]);

        $this->assertSame($expected, $row->permits($client));
    }

    public static function comparisons(): array
    {
        return [
            'older is refused'            => ['0.0.0030', '0.0.0029', false],
            'far older is refused'        => ['0.0.0030', '0.0.0001', false],
            'a smaller major is refused'  => ['1.0.0000', '0.9.9999', false],
            'equal passes'                => ['0.0.0030', '0.0.0030', true],
            'unpadded equal passes'       => ['0.0.0030', '0.0.30',   true],
            'newer passes'                => ['0.0.0030', '0.0.0031', true],
            'numeric not lexical'         => ['0.0.0099', '0.0.0100', true],
            'a bigger major passes'       => ['0.9.9999', '1.0.0000', true],
            'a build suffix is ignored'   => ['0.0.0030', '0.0.0030+41', true],
            'a pre-release is its release'=> ['0.0.0030', '0.0.0030-rc.1', true],

            // The pair that tells "the suffix is discarded" apart from "the
            // suffix makes the version unreadable". Both readings allow the two
            // rows above; only the first refuses these.
            'an old build with a suffix still bites'      => ['0.0.0030', '0.0.0029+41', false],
            'an old pre-release with a tag still bites'   => ['0.0.0031', '0.0.0030-rc.1', false],
            'extra segments are newer'    => ['0.0.0030', '0.0.0030.1', true],
            'fewer segments compare as 0' => ['0.0.0030', '0.1',      true],
            'a shorter minimum still bites' => ['0.1', '0.0.9999', false],

            // The prefix case, in the direction that only works if the SHORTER
            // side is padded with zeros rather than the comparison stopping at
            // whichever string ran out first. A client sitting on 0.0.0030 is
            // genuinely older than a minimum of 0.0.0030.1 and must be refused.
            'a longer minimum still bites'  => ['0.0.0030.1', '0.0.0030', false],
            'no minimum allows'           => [null,      '0.0.0001', true],
            'no client version allows'    => ['0.0.0030', null,      true],
            'unreadable client allows'    => ['0.0.0030', 'v0.0.0029', true],
            'unreadable minimum allows'   => ['nightly',  '0.0.0001', true],
            'empty client allows'         => ['0.0.0030', '',        true],
            'empty minimum allows'        => ['',         '0.0.0001', true],
        ];
    }
}
