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

    private function organization(string $name = 'Alpha', string $email = 'admin@alpha.test'): array
    {
        return app(\App\Services\Organizations\ProvisionOrganization::class)(
            organizationName: $name,
            administrator: ['name' => 'Admin', 'email' => $email, 'password' => 'a-real-password'],
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

    public function test_a_file_scope_closure_does_not_carry_the_deploy_root_into_the_trace(): void
    {
        // `metadata['file']` was scrubbed and `metadata['trace']` was not, and
        // on PHP 8.4 the trace is where the absolute path actually is: a
        // file-scope closure is named `{closure:<ABSOLUTE FILE>:<line>}`.
        // routes/api.php:407 and routes/web.php:5 are both this shape.
        $this->crashingRoute(
            'api/crash-test/closure',
            require base_path('tests/Fixtures/file_scope_closure.php'),
        );

        $this->getJson('/api/crash-test/closure')->assertStatus(500);

        $trace = implode("\n", $this->crashRecord()->metadata['trace']);

        $this->assertStringContainsString('{closure:', $trace, 'PHP did not name the closure as expected.');
        $this->assertStringNotContainsString(base_path(), $trace);
        $this->assertStringContainsString('tests/Fixtures/file_scope_closure.php', $trace);
    }

    public function test_an_anonymous_class_carries_neither_the_deploy_root_nor_a_nul_byte(): void
    {
        // Every file in database/migrations/ is an anonymous class, so a failed
        // migration wrote `class@anonymous`, a NUL byte and the absolute path of
        // the migration into a table tenant admins read.
        $anonymous = new class
        {
            public function boom(): void
            {
                throw new RuntimeException('boom');
            }
        };

        $this->crashingRoute('api/crash-test/anon', fn () => $anonymous->boom());
        $this->getJson('/api/crash-test/anon')->assertStatus(500);

        $trace = implode("\n", $this->crashRecord()->metadata['trace']);

        $this->assertStringContainsString('class@anonymous', $trace);
        $this->assertStringNotContainsString("\0", $trace, 'A NUL byte reached the audit row.');
        $this->assertStringNotContainsString(base_path(), $trace);
    }

    public function test_the_exception_and_previous_class_names_are_scrubbed_too(): void
    {
        /*
         * `trace` goes through callName(), which converts the NUL and strips the
         * deploy root. `exception` and `previous` were built from `$e::class`
         * raw — one method away from the same scrubber — so an anonymous
         * exception class wrote
         * `RuntimeException@anonymous<NUL>/opt/…/SomeTest.php:99$2e8`
         * into a column tenant admins read.
         */
        $anonymous = new class('boom') extends RuntimeException {};

        ExceptionAudit::record($anonymous);
        ExceptionAudit::record(new \LogicException('wrapped', 0, $anonymous));

        $names = AuditEvent::where('action', ExceptionAudit::ACTION)
            ->get()
            ->flatMap(fn (AuditEvent $event) => array_filter([
                $event->metadata['exception'] ?? null,
                $event->metadata['previous'] ?? null,
            ]))
            ->all();

        $joined = implode("\n", $names);

        // Both fields, not just one: the wrapper's `previous` is the same value
        // arriving by the other door.
        $this->assertSame(2, substr_count($joined, '@anonymous'), 'PHP did not name the class as expected.');
        $this->assertStringNotContainsString("\0", $joined, 'A NUL byte reached the audit row.');
        $this->assertStringNotContainsString(base_path(), $joined);
        $this->assertStringContainsString('ErrorAuditTest.php', $joined);
    }

    // ── the table stays bounded ──────────────────────────────────

    public function test_the_same_crash_repeated_writes_one_row_per_window(): void
    {
        $this->crashingRoute('api/crash-test/loop', fn () => throw new RuntimeException('boom'));

        for ($i = 0; $i < 6; $i++) {
            $this->getJson('/api/crash-test/loop')->assertStatus(500);
        }

        // audit_events is append-only, erasure cannot reach it and retention
        // sweeps it monthly, so the write rate is the only bound there is. A
        // crash loop, or anyone who can reliably provoke one 500, would
        // otherwise fill the disk — hardest at the moment something is already
        // wrong. Every occurrence is still fully reported to the log channel.
        $this->assertSame(1, AuditEvent::where('action', ExceptionAudit::ACTION)->count());
    }

    public function test_different_crashes_are_not_collapsed_into_each_other(): void
    {
        $this->crashingRoute('api/crash-test/one', fn () => throw new RuntimeException('boom'));
        $this->crashingRoute('api/crash-test/two', fn () => throw new \LogicException('boom'));

        $this->getJson('/api/crash-test/one')->assertStatus(500);
        $this->getJson('/api/crash-test/two')->assertStatus(500);

        // The valve must not become a way to lose the second fault of the day.
        $this->assertSame(2, AuditEvent::where('action', ExceptionAudit::ACTION)->count());
    }

    public function test_a_fault_that_varies_its_own_fingerprint_still_hits_a_ceiling(): void
    {
        $ceiling = ExceptionAudit::DISTINCT_PER_WINDOW;

        // One bad deploy breaking every route at once walks straight past a
        // per-fingerprint check, so there is a second bound on how many distinct
        // crashes one window may record at all.
        for ($i = 0; $i <= $ceiling; $i++) {
            $this->crashingRoute("api/crash-test/many-{$i}", fn () => throw new RuntimeException('boom'));
            $this->getJson("/api/crash-test/many-{$i}")->assertStatus(500);
        }

        $this->assertSame($ceiling, AuditEvent::where('action', ExceptionAudit::ACTION)->count());
    }

    public function test_one_organizations_flood_does_not_silence_another_organizations_crash(): void
    {
        ['organization' => $alpha, 'administrator' => $alphaAdmin] = $this->organization();
        ['organization' => $beta, 'administrator' => $betaAdmin] = $this->organization('Beta', 'admin@beta.test');

        $ceiling = ExceptionAudit::DISTINCT_PER_WINDOW;

        /*
         * The distinct-crash ceiling used to be ONE COUNTER FOR THE PLATFORM.
         * Forty distinct fingerprints inside a five-minute window — one bad
         * deploy, or an unauthenticated caller walking forty routes that 500 —
         * and crash auditing was off for every customer for the rest of it.
         *
         * That is worse than the unbounded growth the ceiling was added to
         * prevent: it is a way to blind the crash trail on purpose, from
         * outside, before doing something that would have been recorded.
         */
        for ($i = 0; $i < $ceiling; $i++) {
            Route::middleware(['auth:sanctum', 'tenant'])
                ->get("api/crash-test/flood-{$i}", fn () => throw new RuntimeException('boom'));

            $this->actingAs($alphaAdmin, 'sanctum')
                ->getJson("/api/crash-test/flood-{$i}")
                ->assertStatus(500);
        }

        // Alpha is capped — its own budget, spent by its own crashes.
        $this->assertSame($ceiling, AuditEvent::where('action', ExceptionAudit::ACTION)
            ->where('organization_id', $alpha->getKey())
            ->count());

        Route::middleware(['auth:sanctum', 'tenant'])
            ->get('api/crash-test/beta-first', fn () => throw new \LogicException('boom'));

        $this->actingAs($betaAdmin, 'sanctum')
            ->getJson('/api/crash-test/beta-first')
            ->assertStatus(500);

        $this->assertSame(
            1,
            AuditEvent::where('action', ExceptionAudit::ACTION)
                ->where('organization_id', $beta->getKey())
                ->count(),
            'A flood in one organization swallowed a different organization\'s first-ever crash.',
        );
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

        // The crash belongs in the history of the customer it happened to.
        //
        // This comment used to say SetTenantContext's `finally` had already
        // cleared the tenant by now and the organization was recovered from the
        // actor. It had not. Illuminate\Routing\Pipeline::carry() wraps every
        // pipe in its own try/catch and reports from the innermost one, so
        // report() runs while the middleware is still inside its own `try` —
        // this test passed either way and could not tell the difference. The
        // one below is the case that could.
        $this->assertSame($org->getKey(), $event->organization_id);
    }

    public function test_a_crash_with_no_actor_is_still_attributed_to_its_tenant(): void
    {
        ['organization' => $org] = $this->organization();

        $tenant = app(\App\Support\Tenancy\TenantContext::class);
        $tenant->forget();

        /*
         * The queue, which is the one case the actor recovery was written for
         * and the one case it cannot serve: a worker has no `auth()->user()`,
         * so there is no actor to take an organization from — and
         * TenantContext::run() restores in a `finally` that has already run by
         * the time Worker::process() catches and reports.
         *
         * DeliverAutomationEvent is the only queued job there is and its whole
         * body runs inside `run()`, so every automation crash was landing with
         * organization_id null.
         */
        try {
            $tenant->run($org, fn () => throw new RuntimeException('boom'));
        } catch (RuntimeException $e) {
            ExceptionAudit::record($e);
        }

        $event = $this->crashRecord();

        $this->assertNotNull($event);
        $this->assertSame(AuditEvent::ACTOR_SYSTEM, $event->actor_type);
        $this->assertSame($org->getKey(), $event->organization_id);

        // And recording it must not strand the process in that tenant — in a
        // worker handling many jobs that is the next job reading this one's data.
        $this->assertFalse($tenant->has());
    }

    public function test_the_correlation_id_on_the_row_is_on_the_log_line(): void
    {
        $logged = [];

        \Illuminate\Support\Facades\Log::listen(
            function (\Illuminate\Log\Events\MessageLogged $message) use (&$logged): void {
                $logged[] = $message->context;
            }
        );

        $this->crashingRoute('api/crash-test/join', fn () => throw new RuntimeException('boom'));
        $this->getJson('/api/crash-test/join')->assertStatus(500);

        /*
         * The row deliberately carries no message, and the entire argument for
         * that is "the log has one, joined by the correlation id". Nothing put
         * the id anywhere near a log line — it went on the row and on a response
         * header — so the two were joinable only by timestamp, which on a box
         * serving concurrent requests is not a join at all.
         */
        $this->assertNotEmpty($logged, 'The framework reported nothing to the log channel.');
        $this->assertContains(
            $this->crashRecord()->correlation_id,
            array_column($logged, 'correlation_id'),
        );
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
