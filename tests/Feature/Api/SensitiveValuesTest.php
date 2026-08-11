<?php

namespace Tests\Feature\Api;

use App\Models\AuditEvent;
use App\Models\Customer;
use App\Models\User;
use App\Support\Data\Exporter;
use App\Support\Privacy\HoldsSensitiveValues;
use App\Support\Privacy\SensitiveValues;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The four things marking a field sensitive actually does (EZBIZI-HR-PLAN.md,
 * "Sensitive fields — what the flag actually does"; §9).
 *
 * ── What is being tested, given that no tenant fields exist yet ──
 *
 * The mechanism, which is what was asked for — the policy belongs to Contracts,
 * where a tenant marks their own field sensitive and the answers table becomes
 * the first real holder.
 *
 * A mechanism with no production caller is a mechanism with no evidence, so the
 * probe below is a REAL Eloquent model on a REAL table: a Customer subclass that
 * declares `notes` sensitive and casts it `encrypted`. `notes` is genuinely the
 * right column to borrow — it is the free-text field with no schema, so it is
 * where somebody actually would write down something about a person that should
 * not be on every screen — and using it means the encryption assertion is made
 * against a column of a real table rather than a fixture invented to pass.
 *
 * Nothing in production marks it sensitive; only this subclass does. Customer
 * itself is untouched.
 */
class SensitiveValuesTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const SECRET = 'ES91 2100 0418 4502 0005 1332';

    // ── 1. encrypted at rest ─────────────────────────────────────

    /**
     * The path a controller reaches for first, which the mechanism did not cover.
     *
     * `SensitiveValues::read()` masked, and two call sites in the whole
     * application used it. Everything else — `response()->json($model)`, an API
     * resource, `->only()`, a model nested in an eager-loaded relation — walked
     * the attributes directly and published the clear value, while the docblock
     * said every read was masked.
     */
    public function test_a_sensitive_value_is_masked_by_ordinary_serialisation(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        // The file's own helper, so this row is built the same way as every other
        // probe here and cannot drift from them.
        $row = $this->probe($alpha, 'ES91 2100 0418 4502 0005 1332', 'Marta Silva');

        $this->inTenant($alpha['org'], function () use ($row) {
            foreach ([$row->toArray()['notes'], json_decode($row->toJson(), true)['notes']] as $seen) {
                $this->assertSame(SensitiveValues::MASK, $seen);
            }

            $this->assertStringNotContainsString('ES91', $row->toJson());

            // And the clear value is still reachable by the one call that
            // writes down that somebody looked.
            $this->assertSame(
                'ES91 2100 0418 4502 0005 1332',
                $row->getAttribute('notes'),
                'The trait masks what leaves the model, not what the model holds.',
            );
        });
    }

    /**
     * The one assertion the whole first behaviour reduces to.
     *
     * "Unreadable in a database dump" is not a property of the API, the policy
     * or the mask — it is a property of the bytes in the column. So this reads
     * the row with the query builder, bypassing every cast and accessor, and
     * looks for the string.
     */
    public function test_a_sensitive_value_does_not_appear_in_a_dump_of_the_row(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $probe = $this->probe($alpha);

        $raw = DB::table('customers')->where('id', $probe->getKey())->first();

        $this->assertNotSame(self::SECRET, $raw->notes);
        $this->assertStringNotContainsString(self::SECRET, (string) $raw->notes);

        // And a dump of the WHOLE row, in case a future column starts carrying a
        // copy — which is exactly how a value that is encrypted in one place
        // ends up in the clear in another.
        $this->assertStringNotContainsString(self::SECRET, (string) json_encode($raw));

        // Still readable by the application, or the encryption would be a very
        // secure way of losing data.
        $this->assertSame(
            self::SECRET,
            $this->inTenant($alpha['org'], fn () => SensitiveProbe::findOrFail($probe->getKey())->notes),
        );
    }

    // ── 2. masked by default, revealed with permission, audited ──

    public function test_the_value_is_masked_unless_the_caller_asks_to_reveal_it(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $probe = $this->probe($alpha);

        $read = $this->inTenant(
            $alpha['org'],
            fn () => $this->sensitive()->read($probe, 'notes', $alpha['admin']),
        );

        $this->assertSame(SensitiveValues::MASK, $read['value']);
        $this->assertTrue($read['sensitive']);
        $this->assertFalse($read['revealed']);

        // Masked even for somebody who COULD reveal it, because they did not
        // ask. That is what keeps every reveal event meaning "somebody
        // deliberately looked" rather than "a screen was opened".
        $this->assertSame(0, $this->revealEvents($alpha)->count());
    }

    /**
     * The mask gives away neither the value nor its length.
     *
     * A last-four mask is four digits of somebody's bank account handed to
     * everybody who can open the screen, and a length-preserving one publishes
     * how long the value is — which narrows a guess considerably for anything
     * with a fixed format.
     */
    public function test_the_mask_reveals_nothing_about_the_value(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $short = $this->probe($alpha, 'x');
        $long  = $this->probe($alpha, self::SECRET, name: 'Second');

        // Inside the tenant, because asking the record whether this person may
        // reveal it reads their role — which is tenant data like everything else.
        $masked = fn ($p) => $this->inTenant(
            $alpha['org'],
            fn () => $this->sensitive()->read($p, 'notes', $alpha['admin'])['value'],
        );

        $this->assertSame($masked($short), $masked($long));
        $this->assertStringNotContainsString('1332', $masked($long));
    }

    public function test_revealing_needs_the_records_own_permission(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $probe = $this->probe($alpha);

        // The probe says `customers.edit` reveals it. Somebody who may READ the
        // record still may not see the value in the clear — the two are
        // deliberately different questions.
        $reader = $this->userWithRole($alpha, 'Reader', ['customers.view']);

        try {
            $this->inTenant(
                $alpha['org'],
                fn () => $this->sensitive()->read($probe, 'notes', $reader, reveal: true),
            );
            $this->fail('A reveal was allowed to somebody the record refuses.');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('recorded', $e->getMessage());
        }

        // A refused reveal is not a disclosure, so there is nothing to record —
        // and an entry here would be indistinguishable from a successful one to
        // anybody counting.
        $this->assertSame(0, $this->revealEvents($alpha)->count());

        $editor = $this->userWithRole($alpha, 'Editor', ['customers.view', 'customers.edit']);

        $read = $this->inTenant(
            $alpha['org'],
            fn () => $this->sensitive()->read($probe, 'notes', $editor, reveal: true),
        );

        $this->assertSame(self::SECRET, $read['value']);
        $this->assertTrue($read['revealed']);
    }

    /**
     * "Who looked at my bank details" is a question that gets asked.
     *
     * It can only be answered by the system that was asked to show them, which
     * is why the audit write happens BEFORE the value is returned rather than
     * after — a failure there must cost the disclosure, not the record of it.
     */
    public function test_revealing_a_sensitive_value_is_audited(): void
    {
        $alpha  = $this->provisionTenant('Alpha');
        $probe  = $this->probe($alpha);
        $editor = $this->userWithRole($alpha, 'Editor', ['customers.view', 'customers.edit']);

        $this->inTenant(
            $alpha['org'],
            fn () => $this->sensitive()->read($probe, 'notes', $editor, reveal: true),
        );

        $event = $this->revealEvents($alpha)->sole();

        $this->assertSame($editor->getKey(), $event->actor_id);
        $this->assertSame($probe->getKey(), $event->target_id);
        $this->assertSame('notes', $event->metadata['field']);

        // The FIELD, never the value. An audit log is read by more people than a
        // bank account number should be, and it is append-only — so a value
        // copied in here is a value erasure can no longer reach.
        $this->assertStringNotContainsString(
            self::SECRET,
            (string) json_encode($event->toArray()),
        );
    }

    // ── 3. excluded from exports ─────────────────────────────────

    /**
     * The default direction, which is the one that matters.
     *
     * "Otherwise the first CSV puts every account number on somebody's laptop."
     * All three export paths — the CSV, the whole-organization bundle and this
     * per-person one — go through the same private helper, and this is the path
     * that takes an injectable row, so it is where the helper is exercised.
     */
    public function test_a_sensitive_value_does_not_leave_through_an_export_by_default(): void
    {
        $alpha  = $this->provisionTenant('Alpha');
        $probe  = $this->probe($alpha);
        $editor = $this->userWithRole($alpha, 'Editor', ['customers.view', 'customers.edit']);

        $out = $this->inTenant(
            $alpha['org'],
            fn () => app(Exporter::class)->person($probe, $editor),
        );

        $this->assertSame(SensitiveValues::MASK, $out['customer']['notes']);

        // The rest of the record is untouched — masking is not redaction of
        // everything nearby.
        $this->assertSame('A Customer', $out['customer']['name']);
    }

    public function test_an_export_may_include_it_when_asked_and_permitted(): void
    {
        $alpha  = $this->provisionTenant('Alpha');
        $probe  = $this->probe($alpha);
        $editor = $this->userWithRole($alpha, 'Editor', ['customers.view', 'customers.edit']);

        $out = $this->inTenant(
            $alpha['org'],
            fn () => app(Exporter::class)->person($probe, $editor, includeSensitive: true),
        );

        $this->assertSame(self::SECRET, $out['customer']['notes']);
    }

    /**
     * And asking is not the same as being allowed.
     *
     * `include_sensitive` is a query parameter. If it were the whole gate,
     * anybody who could export at all could export everything — so the row is
     * still asked, and the flag can only stop hiding what this person was
     * already entitled to see.
     */
    public function test_asking_for_sensitive_values_does_not_grant_them(): void
    {
        $alpha  = $this->provisionTenant('Alpha');
        $probe  = $this->probe($alpha);
        $reader = $this->userWithRole($alpha, 'Reader', ['customers.view']);

        $out = $this->inTenant(
            $alpha['org'],
            fn () => app(Exporter::class)->person($probe, $reader, includeSensitive: true),
        );

        $this->assertSame(SensitiveValues::MASK, $out['customer']['notes']);
    }

    /** A model that declares nothing sensitive is exported exactly as before. */
    public function test_an_ordinary_record_is_unaffected(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $customer = $this->inTenant($alpha['org'], fn () => Customer::create([
            'owning_franchise_id' => $alpha['franchise']->id,
            'number'              => 'C-plain',
            'name'                => 'Plain',
            'notes'               => 'Nothing secret here.',
        ]));

        $out = $this->inTenant(
            $alpha['org'],
            fn () => app(Exporter::class)->person($customer, $alpha['admin']),
        );

        $this->assertSame('Nothing secret here.', $out['customer']['notes']);
    }

    // ── helpers ──────────────────────────────────────────────────

    private function sensitive(): SensitiveValues
    {
        return app(SensitiveValues::class);
    }

    private function probe(array $tenant, string $value = self::SECRET, string $name = 'A Customer'): SensitiveProbe
    {
        return $this->inTenant($tenant['org'], function () use ($tenant, $value, $name) {
            $probe = new SensitiveProbe;

            // `forceFill`, because the #[Fillable] attribute sits on Customer and
            // PHP does not inherit attributes onto a subclass — so this probe has
            // no fillable list of its own. Nothing to do with the feature.
            $probe->forceFill([
                'organization_id'     => $tenant['org']->id,
                'owning_franchise_id' => $tenant['franchise']->id,
                'number'              => 'C-'.bin2hex(random_bytes(3)),
                'name'                => $name,
                'notes'               => $value,
            ])->save();

            return $probe;
        });
    }

    private function revealEvents(array $tenant)
    {
        return $this->inTenant(
            $tenant['org'],
            fn () => AuditEvent::where('action', 'sensitive_value.revealed')->get(),
        );
    }
}

/**
 * A record that carries one sensitive value.
 *
 * Deliberately a subclass of a real model on a real table rather than a fixture
 * with its own schema: the first behaviour being tested is that the bytes in the
 * column are unreadable, and that is only worth asserting against a column that
 * really exists.
 *
 * It also demonstrates the shape a holder takes, which is the other half of what
 * this file is for — Contracts will write one of these against its answers table
 * and can read this as the example.
 */
class SensitiveProbe extends Customer implements HoldsSensitiveValues
{
    use \App\Support\Privacy\MasksSensitiveValues;

    protected $table = 'customers';

    public function sensitiveAttributes(): array
    {
        return ['notes'];
    }

    /**
     * Whichever permission the record type decides.
     *
     * Asked of the record rather than fixed by the mechanism, so that a holder
     * can also answer yes to "this is the person's own data" — which is the case
     * the HR plan leaves open, and the one a single global permission could
     * never express.
     */
    public function mayReveal(User $actor): bool
    {
        return $actor->hasPermission('customers.edit');
    }

    protected function casts(): array
    {
        // The cast is per-column and unconditional, which is the point made in
        // HoldsSensitiveValues: a table of tenant-defined answers encrypts every
        // value, and the sensitivity flag drives the other three behaviours.
        return parent::casts() + ['notes' => 'encrypted'];
    }
}
