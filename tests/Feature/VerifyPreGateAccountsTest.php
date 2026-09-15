<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The backfill that repairs accounts older than the verification gate.
 *
 * RequireVerifiedEmail went live on 2026-09-14 at 15:00 and refused every write
 * by a user with a null `email_verified_at`. Twenty-three of the twenty-four
 * accounts on the box were in that state and none could leave it: the column
 * arrived with the change that began reading it, and no confirmation link had
 * ever been sent to anybody. The product was read-only for everyone.
 *
 * This pins the thing that makes the repair correct rather than merely large:
 * it has a BOUNDARY. Confirming every user would fix the outage and silently
 * disarm the gate for the only rows it exists to guard — and nothing would ever
 * mark them unverified again, so the damage would not surface.
 *
 * Both halves are asserted deliberately. A test that only checked the old
 * account would pass just as happily against `UPDATE users SET
 * email_verified_at = now()` with no WHERE clause at all, which is exactly the
 * mistake worth preventing.
 */
class VerifyPreGateAccountsTest extends TestCase
{
    use RefreshDatabase;
    use BuildsTenants;

    private const MIGRATION = 'database/migrations/2026_09_14_160000_verify_every_account_that_predates_the_gate.php';

    public function test_it_confirms_accounts_older_than_the_gate_and_leaves_newer_ones_alone(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        // The harness confirms fixture people, so put them back into the state
        // the real rows were in: created before the gate, never confirmed,
        // because nothing had ever sent them a link.
        $stranded = $this->userWithRole($alpha, 'Stranded', ['customers.view']);
        $recent   = $this->userWithRole($alpha, 'Recent', ['customers.view']);

        DB::table('users')->where('id', $stranded->id)->update([
            'email_verified_at' => null,
            'created_at'        => '2026-08-06 10:00:00',
        ]);

        // Created AFTER the gate: this one signed up through the front door and
        // was sent a link. It is exactly what the gate is for.
        DB::table('users')->where('id', $recent->id)->update([
            'email_verified_at' => null,
            'created_at'        => '2026-09-14 16:30:00',
        ]);

        $this->assertNull($stranded->fresh()->email_verified_at, 'fixture precondition');
        $this->assertNull($recent->fresh()->email_verified_at, 'fixture precondition');

        (require base_path(self::MIGRATION))->up();

        $this->assertNotNull(
            $stranded->fresh()->email_verified_at,
            'An account that predates the gate was never sent a link, so it could never leave '
            .'the unverified state on its own. The backfill must confirm it.',
        );

        $this->assertNull(
            $recent->fresh()->email_verified_at,
            'An account created after the gate went live is the only kind the gate exists to '
            .'hold. Confirming it here would disarm the feature permanently and invisibly.',
        );
    }

    public function test_it_writes_nothing_on_a_database_with_no_stranded_accounts(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $user  = $this->userWithRole($alpha, 'Confirmed', ['customers.view']);

        $before = $user->fresh()->email_verified_at;
        $this->assertNotNull($before, 'fixture precondition');

        (require base_path(self::MIGRATION))->up();

        // Re-stamping an already-confirmed account would be harmless but untrue:
        // it would claim the address was confirmed today rather than when it was.
        $this->assertEquals(
            $before->toIso8601String(),
            $user->fresh()->email_verified_at->toIso8601String(),
            'The backfill is scoped to null values, so a confirmed account keeps its own date.',
        );

        // runWithoutTenant because this deliberately spans every organization -
        // the backfill is a platform-wide repair, not a tenant-scoped one, and
        // the guard is right to refuse an unscoped read that does not say so.
        $this->assertSame(0, app(TenantContext::class)->runWithoutTenant(
            fn () => User::whereNull('email_verified_at')->count(),
        ));
    }
}
