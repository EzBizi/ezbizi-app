<?php

namespace Tests\Feature\Social;

use App\Models\SocialConnection;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Whose phone numbers are sent to which Thumbtack business.
 *
 * This is the Organization → Company → Franchise rule at its sharpest, because
 * getting it wrong is not a visibility bug. A number registered against the
 * wrong business tells Thumbtack that THAT business answers this handset, so a
 * Premier Garage technician's mobile starts showing A-1 Chimney's caller ID to
 * customers. It is wrong on somebody else's phone, in front of a member of the
 * public, and nothing in EzBizi would look broken.
 *
 * The command shipped on 2026-09-08 with this rule and no tests at all. Found
 * by auditing rather than by anything failing.
 */
class ThumbtackPhoneSyncTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.thumbtack.api_base', 'https://api.thumbtack.com/api');

        // Nothing registered yet, so every send is a create and the assertions
        // are about WHICH numbers go up rather than about reconciliation.
        Http::fake([
            '*associate-phone-numbers-bulk-create' => Http::response(['phoneNumbers' => []]),
            '*associate-phone-numbers'             => Http::response(['phoneNumbers' => []]),
        ]);
    }

    public function test_a_franchise_scoped_business_gets_only_that_franchises_people(): void
    {
        $t     = $this->provisionTenant('A1-Group');
        $other = $this->extraFranchise($t, 'Premier Garage');

        $mine = $this->userWithRole($t, 'Sweep', [], 'sweep@a1.test', onlyAt: $t['franchise']);
        $them = $this->userWithRole($t, 'Fitter', [], 'fitter@a1.test', onlyAt: $other);

        $this->givePhone($t, $mine, '5552345678');
        $this->givePhone($t, $them, '5559876543');

        $this->connection($t, SocialConnection::SCOPE_FRANCHISE, $t['franchise']->getKey());

        $this->artisan('ezbizi:sync-thumbtack-phone-numbers')->assertSuccessful();

        $sent = $this->numbersSent();

        // The sideways case a single-franchise tenant cannot express: the other
        // franchise's fitter must not answer for this business.
        $this->assertContains('+15552345678', $sent);
        $this->assertNotContains('+15559876543', $sent);
    }

    public function test_a_company_scoped_business_includes_the_franchises_beneath_it(): void
    {
        $t     = $this->provisionTenant('A1-Group');
        $other = $this->extraFranchise($t, 'A-1 Cleveland');

        $one = $this->userWithRole($t, 'Sweep', [], 'sweep@a1.test', onlyAt: $t['franchise']);
        $two = $this->userWithRole($t, 'Fitter', [], 'fitter@a1.test', onlyAt: $other);

        $this->givePhone($t, $one, '5552345678');
        $this->givePhone($t, $two, '5559876543');

        $this->connection($t, SocialConnection::SCOPE_COMPANY, $t['company']->getKey());

        $this->artisan('ezbizi:sync-thumbtack-phone-numbers')->assertSuccessful();

        $sent = $this->numbersSent();

        // A franchise is part of its company and its people work that company's
        // leads, so a company-scoped business covers both.
        $this->assertContains('+15552345678', $sent);
        $this->assertContains('+15559876543', $sent);
    }

    public function test_a_number_nobody_could_dial_is_skipped_rather_than_sent(): void
    {
        $t    = $this->provisionTenant('A1-Group');
        $user = $this->userWithRole($t, 'Reception', [], 'reception@a1.test', onlyAt: $t['franchise']);

        $this->givePhone($t, $user, 'ext 4102');

        $this->connection($t, SocialConnection::SCOPE_FRANCHISE, $t['franchise']->getKey());

        // Named in the output, not counted. A skipped number is somebody whose
        // caller ID will not work, and "1 skipped" gives nobody enough to fix it.
        $this->artisan('ezbizi:sync-thumbtack-phone-numbers')
            ->expectsOutputToContain('ext 4102')
            ->assertSuccessful();

        // Thumbtack rejects a whole request over one malformed number, so it
        // must never leave here.
        $this->assertSame([], $this->numbersSent());
    }

    public function test_dry_run_sends_nothing_at_all(): void
    {
        $t    = $this->provisionTenant('A1-Group');
        $user = $this->userWithRole($t, 'Sweep', [], 'sweep@a1.test', onlyAt: $t['franchise']);

        $this->givePhone($t, $user, '5552345678');
        $this->connection($t, SocialConnection::SCOPE_FRANCHISE, $t['franchise']->getKey());

        $this->artisan('ezbizi:sync-thumbtack-phone-numbers --dry-run')->assertSuccessful();

        // A dry run that reaches Thumbtack is not a dry run, and this one is
        // the only way to see what WOULD be sent before it is.
        Http::assertNothingSent();
    }

    /** @return string[] every phoneNumber this run actually sent */
    private function numbersSent(): array
    {
        $numbers = [];

        foreach (Http::recorded() as [$request]) {
            $body = $request->data();

            foreach ($body['phoneNumbers'] ?? [] as $row) {
                $numbers[] = $row['phoneNumber'];
            }

            if (isset($body['phoneNumber'])) {
                $numbers[] = $body['phoneNumber'];
            }
        }

        return $numbers;
    }

    private function givePhone(array $t, $user, string $phone): void
    {
        $this->inTenant($t['org'], fn () => $user->forceFill(['phone' => $phone])->save());
    }

    private function connection(array $t, string $scopeType, string $scopeId): SocialConnection
    {
        return $this->inTenant($t['org'], function () use ($t, $scopeType, $scopeId) {
            $row = new SocialConnection;
            $row->forceFill([
                'organization_id' => $t['org']->getKey(),
                'scope_type'      => $scopeType,
                'scope_id'        => $scopeId,
                'provider'        => SocialProvider::Thumbtack->value,
                'remote_id'       => 'biz-1',
                'display_name'    => 'A1 Chimney',
            ]);
            $row->connect(['access_token' => 'a-token', 'refresh_token' => 'r-token']);
            $row->save();

            return $row;
        });
    }
}
