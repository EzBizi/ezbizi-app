<?php

namespace Tests\Feature\Api;

use App\Models\AuditEvent;
use App\Models\Customer;
use App\Models\Franchise;
use App\Models\WorkizClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * ezbizi:customers-rehome moves the customers a Workiz account knows, and only
 * those: not the ones another account knows, and not an owner left out of
 * `--from`. Each case has a customer that must NOT move beside one that must,
 * so a command that moved everybody, or nobody, goes red.
 */
class RehomeCustomersTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $alpha;

    private Franchise $ma;

    private Franchise $ct;

    private string $maConnection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->provisionTenant('Alpha', 'a@alpha.test');
        $this->ma = $this->extraFranchise($this->alpha, 'MA');
        $this->ct = $this->extraFranchise($this->alpha, 'CT');
        $this->maConnection = (string) Str::uuid();
    }

    private function customer(string $name, Franchise $owner, ?string $connection): Customer
    {
        return $this->inTenant($this->alpha['org'], function () use ($name, $owner, $connection) {
            $customer = Customer::create([
                'owning_franchise_id' => $owner->id,
                'number' => 'C-'.bin2hex(random_bytes(3)),
                'name' => $name,
            ]);
            if ($connection !== null) {
                WorkizClient::create([
                    'franchise_id' => $owner->id, 'connection_id' => $connection,
                    'client_id' => random_int(1, 1_000_000), 'customer_id' => $customer->id,
                    'synced_at' => now(),
                ]);
            }

            return $customer;
        });
    }

    private function ownerOf(Customer $customer): string
    {
        return Customer::withoutGlobalScopes()->find($customer->id)->owning_franchise_id;
    }

    private function rehome(array $extra = []): void
    {
        $this->artisan('ezbizi:customers-rehome', [
            '--to' => $this->ma->id, '--via-workiz-connection' => $this->maConnection,
        ] + $extra)->assertSuccessful();
    }

    public function test_it_moves_the_accounts_customers_and_no_one_else(): void
    {
        $known = $this->customer('Known to MA', $this->alpha['franchise'], $this->maConnection);
        $other = $this->customer('Another account', $this->alpha['franchise'], (string) Str::uuid());
        $none = $this->customer('No Workiz', $this->alpha['franchise'], null);

        $this->rehome();

        $this->assertSame($this->ma->id, $this->ownerOf($known));
        $this->assertSame($this->alpha['franchise']->id, $this->ownerOf($other));
        $this->assertSame($this->alpha['franchise']->id, $this->ownerOf($none));

        // Through the one audited path, not a bulk UPDATE.
        $this->assertSame(1, AuditEvent::withoutGlobalScopes()->where('action', 'customer.owner_reassigned')->count());
    }

    public function test_from_leaves_an_owner_nobody_ruled_on(): void
    {
        $nationwide = $this->customer('Nationwide house', $this->alpha['franchise'], $this->maConnection);
        $connecticut = $this->customer('CT house', $this->ct, $this->maConnection);

        $this->rehome(['--from' => [$this->alpha['franchise']->id]]);

        $this->assertSame($this->ma->id, $this->ownerOf($nationwide));
        $this->assertSame($this->ct->id, $this->ownerOf($connecticut));
    }

    public function test_state_reaches_customers_the_account_never_knew(): void
    {
        // MA's Workiz held 64 clients against ~800 MA customers: a state is
        // the only rule that reaches the rest. Lower-cased, as an import can leave it.
        $ma = $this->customer('MA house', $this->alpha['franchise'], null);
        $ma->forceFill(['state' => 'ma'])->saveQuietly();
        $ct = $this->customer('CT-state house', $this->alpha['franchise'], null);
        $ct->forceFill(['state' => 'CT'])->saveQuietly();
        $ctOwned = $this->customer('CT-owned MA house', $this->ct, null);
        $ctOwned->forceFill(['state' => 'MA'])->saveQuietly();

        $this->artisan('ezbizi:customers-rehome', [
            '--to' => $this->ma->id, '--state' => ['MA'], '--from' => [$this->alpha['franchise']->id],
        ])->assertSuccessful();

        $this->assertSame($this->ma->id, $this->ownerOf($ma));
        $this->assertSame($this->alpha['franchise']->id, $this->ownerOf($ct));
        $this->assertSame($this->ct->id, $this->ownerOf($ctOwned));
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $known = $this->customer('Known to MA', $this->alpha['franchise'], $this->maConnection);

        $this->rehome(['--dry-run' => true]);

        $this->assertSame($this->alpha['franchise']->id, $this->ownerOf($known));
    }
}
