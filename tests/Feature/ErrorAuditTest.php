<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\PlatformStaff;
use App\Support\Audit\AuditLogger;
use App\Support\Audit\ExceptionAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FA\Google2FA;
use RuntimeException;
use Tests\TestCase;

/**
 * Errors and crashes (plan §2.2, §10) — the two streams R2 names that did not
 * exist.
 *
 * `AuditLogger::logError` was written for this and documented as the carve-out
 * the Lead Developer exemption does not cover, and nothing had ever handed it an
 * unhandled exception. These are the properties that have to hold for "audit
 * everything" to mean all three streams.
 */
class ErrorAuditTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A route that fails the way a bug fails: no catch, no handler, a 500.
     *
     * Under `api/` because bootstrap/app.php's shouldRenderJsonWhen REPLACES the
     * framework's default with `$request->is('api/*')` — so a crash route
     * anywhere else answers a redirect and the test would be measuring the wrong
     * pipeline.
     */
    private function crashingRoute(string $uri, callable $boom): void
    {
        Route::get($uri, $boom);
    }

    private function crashRecord(): ?AuditEvent
    {
        return AuditEvent::where('action', ExceptionAudit::ACTION)->first();
    }

    private function organization(): array
    {
        return app(\App\Services\Organizations\ProvisionOrganization::class)(
            organizationName: 'Alpha',
            administrator: ['name' => 'Admin', 'email' => 'admin@alpha.test', 'password' => 'a-real-password'],
            companyName: 'Co',
            franchiseName: 'F1',
        );
    }

    // ── the record exists at all ─────────────────────────────────

    public function test_an_unhandled_exception_is_recorded(): void
    {
        $this->crashingRoute('api/crash-test/plain', fn () => throw new RuntimeException('boom'));

        $this->getJson('/api/crash-test/plain')->assertStatus(500);

        $event = $this->crashRecord();

        $this->assertNotNull($event, 'An unhandled exception left no audit record.');
        $this->assertSame(RuntimeException::class, $event->metadata['exception']);
        $this->assertSame('GET', $event->metadata['method']);
        $this->assertSame('api/crash-test/plain', $event->metadata['route']);
    }

    public function test_the_action_falls_in_the_one_month_retention_bucket(): void
    {
        // §10 retains crash logs for one month, and ExpireAuditEvents decides
        // that by ACTION PREFIX. A verb outside `error.`/`crash.` would silently
        // be kept for three.
        $this->assertStringStartsWith('error.', ExceptionAudit::ACTION);

        $this->crashingRoute('api/crash-test/retained', fn () => throw new RuntimeException('boom'));
        $this->getJson('/api/crash-test/retained')->assertStatus(500);

        DB::table('audit_events')->update(['occurred_at' => now()->subMonths(2)]);

        $this->artisan('ezbizi:expire-audit')->assertSuccessful();

        $this->assertSame(0, AuditEvent::where('action', ExceptionAudit::ACTION)->count());
    }

    public function test_expected_refusals_are_not_recorded_as_crashes(): void
    {
        // 404 and 422 are answers the application gives on purpose. The
        // framework's own ignore list filters them before a reportable callback
        // runs; if that ever stopped being true, every missing page would become
        // a crash report and the stream would be worthless.
        $this->getJson('/api/v1/no-such-endpoint')->assertNotFound();

        $this->crashingRoute(
            'api/crash-test/refused',
            fn () => throw ValidationException::withMessages(['x' => 'No.']),
        );

        $this->getJson('/api/crash-test/refused')->assertStatus(422);

        $this->assertNull($this->crashRecord());
    }

    // ── what is NOT in the payload ───────────────────────────────

    public function test_the_exception_message_is_never_recorded(): void
    {
        $this->crashingRoute(
            'api/crash-test/secret',
            fn () => throw new RuntimeException('SQLSTATE: insert into answers values (hunter2-the-password)'),
        );

        $this->getJson('/api/crash-test/secret')->assertStatus(500);

        $stored = json_encode($this->crashRecord()->metadata);

        // A message is free text. REDACT matches array keys and is inert against
        // it, and audit_events is append-only and unreachable by erasure — so
        // the message stays in storage/logs, which can be rotated, and out of
        // here, which cannot.
        $this->assertStringNotContainsString('hunter2', $stored);
        $this->assertStringNotContainsString('SQLSTATE', $stored);
    }

    public function test_stack_frames_carry_no_arguments(): void
    {
        $this->crashingRoute(
            'api/crash-test/args',
            fn () => $this->raiseCarrying('hunter2-the-password'),
        );

        $this->getJson('/api/crash-test/args')->assertStatus(500);

        $trace = json_encode($this->crashRecord()->metadata['trace']);

        // getTraceAsString() renders arguments — `Foo::check('hunter2', ...)` —
        // which is why the frames are rebuilt by hand from getTrace() with the
        // `args` key never read.
        $this->assertStringNotContainsString('hunter2', $trace);
        $this->assertStringContainsString('raiseCarrying', $trace);
    }

    /** Takes a secret it never uses, so the value exists only in the frame's args. */
    private function raiseCarrying(string $sensitive): void
    {
        throw new RuntimeException('failed');
    }

    public function test_paths_are_relative_to_the_deploy_root(): void
    {
        $this->crashingRoute('api/crash-test/paths', fn () => throw new RuntimeException('boom'));
        $this->getJson('/api/crash-test/paths')->assertStatus(500);

        $file = $this->crashRecord()->metadata['file'];

        // The release directory of the box says nothing a reader needs and is not
        // something a client should be able to read out of their own log.
        $this->assertStringNotContainsString(base_path(), $file);
        $this->assertStringContainsString('ErrorAuditTest.php', $file);
    }

    // ── who and where it is attributed to ────────────────────────

    public function test_a_crash_is_attributed_to_the_actor_and_their_organization(): void
    {
        ['organization' => $org, 'administrator' => $admin] = $this->organization();

        Route::middleware(['auth:sanctum', 'tenant'])
            ->get('api/crash-test/tenant', fn () => throw new RuntimeException('boom'));

        $this->actingAs($admin, 'sanctum')->getJson('/api/crash-test/tenant')->assertStatus(500);

        $event = $this->crashRecord();

        $this->assertNotNull($event);
        $this->assertSame(AuditEvent::ACTOR_USER, $event->actor_type);
        $this->assertSame($admin->getKey(), $event->actor_id);

        // SetTenantContext clears the tenant in a `finally`, which runs as the
        // exception unwinds — long before the framework reports it. Without
        // recovering the organization from the actor, the crash would be missing
        // from the history of the customer it happened to.
        $this->assertSame($org->getKey(), $event->organization_id);
    }

    public function test_a_staff_crash_is_recorded_as_staff(): void
    {
        $staff = PlatformStaff::create([
            'name' => 'Dev', 'email' => 'dev@ezbizi.test',
            'password' => 'a-sufficiently-long-password',
            'tier' => PlatformStaff::TIER_DEV, 'is_active' => true,
        ]);
        $staff->forceFill([
            'totp_secret'       => app(Google2FA::class)->generateSecretKey(32),
            'totp_confirmed_at' => now(),
        ])->save();

        Route::middleware(['auth:sanctum', 'staff'])
            ->get('api/crash-test/staff', fn () => throw new RuntimeException('boom'));

        $this->actingAs($staff, 'sanctum')->getJson('/api/crash-test/staff')->assertStatus(500);

        $event = $this->crashRecord();

        $this->assertNotNull($event);

        // `log()` types its actor `?User` and then assigns auth()->user() over
        // it, which PHP does not re-check — so a staff principal taking that path
        // would come out labelled `user` carrying a staff id, and retention sorts
        // staff rows by actor_type.
        $this->assertSame(AuditEvent::ACTOR_STAFF, $event->actor_type);
        $this->assertSame($staff->getKey(), $event->actor_id);
    }

    public function test_a_console_command_that_crashes_is_recorded_too(): void
    {
        \Illuminate\Support\Facades\Artisan::command(
            'crash-test:boom',
            fn () => throw new RuntimeException('boom'),
        );

        // Through the console kernel's own handle(), which is the method that
        // catches and reports — `$this->artisan()` calls `call()` instead, which
        // lets the exception straight back out and would never exercise this.
        app(\Illuminate\Contracts\Console\Kernel::class)->handle(
            new \Symfony\Component\Console\Input\ArrayInput(['command' => 'crash-test:boom']),
            new \Symfony\Component\Console\Output\BufferedOutput(),
        );

        $event = $this->crashRecord();

        // The scheduler and the queue run here. A nightly job that has been
        // failing silently for a week is the exact thing §10 says this platform
        // has already lived through once.
        $this->assertNotNull($event, 'A console crash left no audit record.');
        $this->assertSame(AuditEvent::ACTOR_SYSTEM, $event->actor_type);

        // Nothing to say about a request when there was none — and no argv,
        // which is where a password passed as an option would be.
        $this->assertArrayNotHasKey('route', $event->metadata);
        $this->assertArrayNotHasKey('method', $event->metadata);
    }

    // ── the carve-out ────────────────────────────────────────────

    public function test_a_crash_is_recorded_while_business_auditing_is_suppressed(): void
    {
        $this->crashingRoute('api/crash-test/suppressed', fn () => throw new RuntimeException('boom'));

        // Suppression wrapped around the whole request is exactly what StaffOnly
        // does for the exempt account, and §2.2's exemption says in so many words
        // that errors and crashes are still captured.
        app(AuditLogger::class)->suppressForLeadDeveloper(
            fn () => $this->getJson('/api/crash-test/suppressed')->assertStatus(500)
        );

        $this->assertNotNull($this->crashRecord());
    }

    public function test_a_failure_to_record_does_not_replace_the_original_error(): void
    {
        // The audit log itself is broken — which is the realistic case, because
        // the usual reason a request crashes is that the database is unreachable,
        // and the recorder needs the same database.
        $this->app->bind(AuditLogger::class, fn () => throw new RuntimeException('audit is down'));

        ExceptionAudit::record(new RuntimeException('the real fault'));

        // Nothing thrown. Had it escaped, the caller would be told about the
        // audit log instead of the fault that broke their request, and the log
        // line naming the real one would never be written either.
        $this->assertSame(0, AuditEvent::count());
    }
}
