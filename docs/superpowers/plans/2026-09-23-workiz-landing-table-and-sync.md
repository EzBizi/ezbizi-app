# Workiz Landing Table and Sync — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Mirror every connected branch's Workiz jobs into a `workiz_jobs` landing table, on a schedule, read-only, keeping all the available data.

**Architecture:** A raw reader on the existing `WorkizJobs` client fetches full payloads per connection; a mapper turns one payload into row attributes; a scheduled command upserts them on `(connection_id, remote_id)`. Nothing projects into `customers` or `jobs` yet — that is a separate plan. Nothing writes back to Workiz.

**Tech Stack:** Laravel 12 / PHP 8.4 / MySQL. `Http::pool` for concurrency. PHPUnit with `BuildsTenants` + `RefreshDatabase`.

**Spec:** `docs/superpowers/specs/2026-09-23-workiz-import-design.md`

## Global Constraints

- **Read-only mirror.** No call to any Workiz `POST` endpoint in this plan. Jose's 2026-09-14 ruling covers reads only.
- **Do not change `WorkizJobs::configured()`, `accounts()` or `all()`.** Reports depends on all three; `ExperimentalCallReportApiTest` pins them and `ExperimentalCallReportController:175` does `array_column($accounts, 'name')`. Add a new method instead.
- **Regression command is two whole files, not `--filter=Workiz`:** `tests/Feature/Reports/WorkizConnectTest.php` and `tests/Feature/Reports/ExperimentalCallReportApiTest.php`. Baseline 23 passed / 71 assertions.
- **Run tests with an own schema and the memory flag:** `DB_CONNECTION=mysql DB_DATABASE=ezbizi_testing_workiz php -d memory_limit=1G vendor/bin/phpunit <paths>`.
- `service_area` is **trimmed** on write; the raw value stays in `payload`.
- **Never log a URL.** The Workiz token is in the path. Failures carry a status only.
- No area is ever auto-created. This plan writes no row outside `workiz_jobs`.

## What the real payloads say, and the doc does not

Measured 2026-09-23 over 400 real stored payloads in `a1tools_workiz_jobs` on the app box. Every task below depends on these:

1. **Nothing is ever `null`. Absent values are empty strings.** `$job['Phone'] ?? null` never fires — it yields `''`. Emptiness must be tested with `=== ''`.
2. **Keys change type between rows.** `Tags` is `string|array`; `JobName`, `PhoneExt`, `SecondPhone`, `Unit` are `string|integer`; `JobAmountDue`, `JobTotalPrice`, `item_cost` are `integer|double`.
3. **Dates are `Y-m-d H:i:s`, not the ISO-8601 the doc shows, and carry no zone.** `Timezone` is absent from real payloads. The franchise's own timezone is the only available reading.
4. **The key set is not fixed.** A-1 carries undocumented custom fields — `pricing`, `system_type`, `real_wood_y/n`, `time_frames`, `inspection_report` — on 232–354 of 400 rows. This is why `payload` exists; a fixed 43-column schema would silently drop them.
5. `UUID` is a 6-character string like `DG0KYN`, not a UUID. `remote_id` is `string`, never `uuid`.

---

## File Structure

| File | Responsibility |
|---|---|
| `database/migrations/2026_09_23_140000_create_workiz_jobs_table.php` | the landing table |
| `app/Models/WorkizJob.php` | the row; tenant scoping; casts |
| `app/Support/Workiz/WorkizJobAttributes.php` | one payload → one row's attributes. Pure, no I/O. |
| `app/Support/Reports/Experimental/WorkizJobs.php` | **modify**: add `raw()`, leave the three contract methods alone |
| `app/Console/Commands/SyncWorkizJobs.php` | the sweep |
| `tests/Fixtures/workiz/job_payload.php` | captured shape, scrubbed values |
| `tests/Feature/Workiz/WorkizJobAttributesTest.php` | the mapper |
| `tests/Feature/Workiz/SyncWorkizJobsTest.php` | the sweep end to end |

---

### Task 1: The landing table and its model

**Files:**
- Create: `database/migrations/2026_09_23_140000_create_workiz_jobs_table.php`
- Create: `app/Models/WorkizJob.php`
- Test: `tests/Feature/Workiz/WorkizJobTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `App\Models\WorkizJob`, an Eloquent model on `workiz_jobs` with `HasUuids` and `BelongsToTenant`. Columns as listed in the migration below. Later tasks write through `WorkizJob::query()->updateOrCreate()`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Workiz;

use App\Models\WorkizJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class WorkizJobTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_landed_job_is_scoped_to_its_organization(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $branch = $this->branch($tenant, 'New Jersey');

        $row = $this->inTenant($tenant['org'], fn () => WorkizJob::create([
            'franchise_id' => $branch->id,
            'remote_id' => 'DG0KYN',
            'service_area' => 'New Jersey',
            'postal_code' => '07030',
            'status' => 'Submitted',
            'payload' => ['UUID' => 'DG0KYN', 'pricing' => '$149 - Level 2 Inspection'],
        ]));

        $this->assertSame($tenant['org']->id, $row->organization_id);
        $this->assertSame('New Jersey', $row->service_area);

        // payload is an array on the way back out, custom fields intact
        $fresh = $this->inTenant($tenant['org'], fn () => WorkizJob::query()->first());
        $this->assertSame('$149 - Level 2 Inspection', $fresh->payload['pricing']);
    }

    public function test_the_same_remote_job_cannot_land_twice_for_one_connection(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $branch = $this->branch($tenant, 'New Jersey');
        $connectionId = (string) \Illuminate\Support\Str::uuid();

        $this->inTenant($tenant['org'], function () use ($branch, $connectionId) {
            WorkizJob::create([
                'franchise_id' => $branch->id,
                'connection_id' => $connectionId,
                'remote_id' => 'DG0KYN',
                'status' => 'Submitted',
                'payload' => [],
            ]);
        });

        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->inTenant($tenant['org'], function () use ($branch, $connectionId) {
            WorkizJob::create([
                'franchise_id' => $branch->id,
                'connection_id' => $connectionId,
                'remote_id' => 'DG0KYN',
                'status' => 'Submitted',
                'payload' => [],
            ]);
        });
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `DB_CONNECTION=mysql DB_DATABASE=ezbizi_testing_workiz php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Workiz/WorkizJobTest.php`

Expected: FAIL — `Class "App\Models\WorkizJob" not found`.

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Workiz jobs as Workiz reported them, one row per job, per connection.
 *
 * A LANDING TABLE, not the Jobs module's table. Three reasons, all measured
 * rather than preferred:
 *
 *  - A real payload carries 43 keys plus a variable tail of the account's own
 *    custom fields (`pricing`, `system_type`, `time_frames`, `inspection_report`
 *    on A-1's). `jobs` has twelve fillable columns.
 *  - `jobs` has NO address column at all. `postal_code` and `service_area` are
 *    what A-1's entire zip-to-service-area map is derived from, and neither has
 *    anywhere to live there.
 *  - Writing direct would have dropped about thirty fields against an
 *    instruction that said all the available data.
 *
 * `payload` holds the whole thing, so a field nobody flattened is never lost
 * and a new custom field needs no migration. The flattened columns are only
 * the ones something queries.
 *
 * SERVICE AREA IS NOT A FRANCHISE. 57 distinct service areas across 10
 * accounts, one account carrying up to 30 — and on 31 of A-1's jobs the
 * service area belongs to a DIFFERENT franchise than the connection does
 * (Scheduling's 3a2b2f40). `franchise_id` is right for the job, because
 * `Job`'s own contract says a job belongs to whoever did it. But anything
 * that reads `service_area` as implying `franchise_id` out of this table is
 * wrong on those rows, silently.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workiz_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');

            // The connection's franchise. Not nullable: a Workiz account is
            // refused outside a branch view, so every row has one.
            $table->uuid('franchise_id');

            // Nullable so the mirror outlives a disconnection — losing the
            // credential must not delete the history it already delivered,
            // which is the whole reason it was copied here. Same reasoning as
            // `leads.connection_id`.
            $table->uuid('connection_id')->nullable();

            // Workiz's `UUID` is a six-character string like DG0KYN, despite
            // the name. Never a uuid column.
            $table->string('remote_id');
            $table->unsignedBigInteger('serial_id')->nullable();

            // Workiz's ClientId, unique only WITHIN an account. Ten disjoint
            // accounts, so it is meaningless without connection_id beside it.
            $table->unsignedBigInteger('client_id')->nullable();

            // Trimmed on write: four live values carry a trailing space
            // ("Milwaukee ", "Connecticut "). The raw string stays in payload.
            $table->string('service_area')->nullable();

            $table->string('postal_code', 32)->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('country', 8)->nullable();
            $table->string('address')->nullable();
            $table->string('unit')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            $table->string('status')->nullable();
            $table->string('sub_status')->nullable();
            $table->string('job_type')->nullable();
            $table->string('job_source')->nullable();

            // Workiz's CreatedDate. NOT created_at, which is import time and
            // says nothing about when the job was booked.
            $table->dateTime('booked_at')->nullable();
            $table->dateTime('scheduled_for')->nullable();
            $table->dateTime('scheduled_end')->nullable();
            $table->dateTime('last_status_update')->nullable();

            // A person's name as Workiz recorded it, not an EzBizi user. Linking
            // the two is a separate plan and needs a human to confirm it.
            $table->string('created_by')->nullable();

            $table->json('payload');
            $table->dateTime('synced_at');

            $table->timestamps();

            $table->unique(['connection_id', 'remote_id']);
            $table->index(['organization_id', 'franchise_id']);
            $table->index(['organization_id', 'service_area', 'postal_code']);
            $table->index(['organization_id', 'booked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workiz_jobs');
    }
};
```

- [ ] **Step 4: Write the model**

```php
<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Workiz job, as Workiz reported it.
 *
 * A MIRROR. Nothing in EzBizi edits these rows: the sweep overwrites them on
 * every run, so a local edit would be reverted with nothing logged. What EzBizi
 * decides about a job belongs on the projected `jobs` row, not here — the same
 * split `leads` makes between `source_status` (the source's word, overwritten)
 * and `status` (ours, never touched).
 */
#[Fillable([
    'franchise_id', 'connection_id', 'remote_id', 'serial_id', 'client_id',
    'service_area', 'postal_code', 'city', 'state', 'country', 'address', 'unit',
    'latitude', 'longitude', 'status', 'sub_status', 'job_type', 'job_source',
    'booked_at', 'scheduled_for', 'scheduled_end', 'last_status_update',
    'created_by', 'payload', 'synced_at',
])]
class WorkizJob extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'booked_at' => 'datetime',
            'scheduled_for' => 'datetime',
            'scheduled_end' => 'datetime',
            'last_status_update' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    public function franchise(): BelongsTo
    {
        return $this->belongsTo(Franchise::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(SocialConnection::class, 'connection_id');
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'A mirror of the source system A-1 is migrating off. It is kept as long as the '
            .'jobs and customers projected from it are kept — deleting the mirror would strand '
            .'those rows with no provenance for where they came from. The personal data in it '
            .'(the customer\'s name, contact details and service address, carried on every '
            .'Workiz job payload) is erased by erasing the customer it was projected onto; this '
            .'table holds no person EzBizi does not also hold in `customers`.'
        );
    }
}
```

- [ ] **Step 5: Run the test and watch it pass**

Run: `DB_CONNECTION=mysql DB_DATABASE=ezbizi_testing_workiz php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Workiz/WorkizJobTest.php`

Expected: PASS, 2 tests.

- [ ] **Step 6: Confirm the retention suite still passes**

`RetentionTest::test_every_table_in_the_schema_has_been_decided_about` fails for any new table without a policy. This is why `StatesRetention` is on the model in step 4 rather than added later.

Run: `DB_CONNECTION=mysql DB_DATABASE=ezbizi_testing_workiz php -d memory_limit=1G vendor/bin/phpunit --filter=RetentionTest`

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_09_23_140000_create_workiz_jobs_table.php app/Models/WorkizJob.php tests/Feature/Workiz/WorkizJobTest.php
git commit -m "A landing table, because jobs has no address column"
```

---

### Task 2: One payload becomes one row's attributes

**Files:**
- Create: `app/Support/Workiz/WorkizJobAttributes.php`
- Create: `tests/Fixtures/workiz/job_payload.php`
- Test: `tests/Feature/Workiz/WorkizJobAttributesTest.php`

**Interfaces:**
- Consumes: `App\Models\WorkizJob`'s column names from Task 1.
- Produces: `WorkizJobAttributes::fromPayload(array $payload): array` — returns an array keyed by the model's fillable column names, ready to hand to `updateOrCreate`. It does **not** set `organization_id`, `franchise_id`, `connection_id` or `synced_at`; the caller owns those because they come from the connection, not the payload.

- [ ] **Step 1: Write the fixture**

The value of a fixture here is its **shape**, captured from real payloads, not its values. Personal values are replaced; the key set, the types, the date format, the empty-string convention and the custom-field tail are exactly as measured on 400 real rows.

```php
<?php

// tests/Fixtures/workiz/job_payload.php
//
// SHAPE CAPTURED FROM REAL PAYLOADS, VALUES SCRUBBED.
//
// Every structural quirk here was measured on 2026-09-23 across 400 stored
// payloads in a1tools_workiz_jobs, and every one of them breaks a plausible
// importer:
//
//   - nothing is ever null; absent means empty string
//   - Tags is string|array, PhoneExt/Unit/JobName are string|integer
//   - dates are 'Y-m-d H:i:s' with NO zone, not the ISO-8601 the doc shows
//   - ServiceArea carries a trailing space on four live values
//   - the account's own custom fields arrive undocumented, at the top level
//
// The personal values are invented. The shape is not, and the shape is what
// this fixture is for.

return [
    'UUID' => 'DG0KYN',
    'SerialId' => 900018531,
    'ClientId' => 1002,
    'JobDateTime' => '2026-09-25 08:00:00',
    'JobEndDateTime' => '2026-09-25 11:00:00',
    'CreatedDate' => '2026-09-23 08:43:06',
    'LastStatusUpdate' => '2026-09-23 08:43:06',
    'PaymentDueDate' => '2026-09-25 00:00:00',
    'JobTotalPrice' => 0,
    'JobAmountDue' => 0,
    'SubTotal' => 0,
    'item_cost' => 0,
    'tech_cost' => 0,
    'Status' => 'Submitted',
    'SubStatus' => '',
    'Phone' => '5555550100',
    'SecondPhone' => '',
    'PhoneExt' => '',
    'SecondPhoneExt' => '',
    'Email' => 'someone@example.test',
    'Comments' => '',
    'FirstName' => 'Pat',
    'LastName' => 'Rivera',
    'Company' => '',
    'Address' => '1 Example Street',
    'Unit' => '',
    'City' => 'Hoboken',
    'State' => 'New York',
    'PostalCode' => '07030',
    'Country' => 'US',
    'Latitude' => 40.7440,
    'Longitude' => -74.0324,
    'JobType' => 'Free Estimate',
    'JobName' => 'Free Bid Estimate',
    'JobSource' => 'LS Ads',
    'JobNotes' => '',
    'CreatedBy' => 'Trey Brannan',
    'ServiceArea' => 'Upstate New York ',
    'Tags' => '',
    'Team' => [],
    'LineItems' => [],
    'LocationId' => 55512,
    'LocationKey' => 'loc_example',

    // Undocumented, account-specific, and present on most real rows.
    'pricing' => '$149 - Level 2 Inspection',
    'system_type' => 'Gas System',
    'real_wood_y/n' => 'Appliance',
    'time_frames' => 'Appointment Time: 8-11',
];
```

- [ ] **Step 2: Write the failing test**

```php
<?php

namespace Tests\Feature\Workiz;

use App\Support\Workiz\WorkizJobAttributes;
use Tests\TestCase;

class WorkizJobAttributesTest extends TestCase
{
    private function payload(array $overrides = []): array
    {
        return array_merge(require base_path('tests/Fixtures/workiz/job_payload.php'), $overrides);
    }

    public function test_it_flattens_the_fields_something_queries(): void
    {
        $out = WorkizJobAttributes::fromPayload($this->payload());

        $this->assertSame('DG0KYN', $out['remote_id']);
        $this->assertSame(900018531, $out['serial_id']);
        $this->assertSame(1002, $out['client_id']);
        $this->assertSame('07030', $out['postal_code']);
        $this->assertSame('Submitted', $out['status']);
        $this->assertSame('Free Estimate', $out['job_type']);
        $this->assertSame('Trey Brannan', $out['created_by']);
    }

    /**
     * THE TRAILING SPACE, and it is not cosmetic.
     *
     * Scheduling's zip map groups on this column. "Milwaukee " and "Milwaukee"
     * would be two service areas, and one territory's job history would be
     * split across two capacity allowances.
     */
    public function test_service_area_is_trimmed_but_the_raw_value_survives(): void
    {
        $out = WorkizJobAttributes::fromPayload($this->payload());

        $this->assertSame('Upstate New York', $out['service_area']);
        $this->assertSame('Upstate New York ', $out['payload']['ServiceArea']);
    }

    /**
     * WORKIZ NEVER SENDS NULL. Measured over 400 payloads: absent is ''.
     *
     * A column full of empty strings is not the same as a column full of NULLs
     * to anything that later asks "is this known", and `?? null` never fires
     * because the key is present.
     */
    public function test_an_empty_string_lands_as_null(): void
    {
        $out = WorkizJobAttributes::fromPayload($this->payload());

        $this->assertNull($out['sub_status']);
        $this->assertNull($out['unit']);
        $this->assertNull($out['city'], 'City is empty on 14 of 400 real rows');
    }

    /**
     * The same key arrives as two types on different rows. Measured: Tags is
     * string|array, PhoneExt and Unit are string|integer, JobName is
     * string|integer. A cast that assumes one of them is a fatal on the other.
     */
    public function test_a_key_that_changes_type_between_rows_does_not_fatal(): void
    {
        $asString = WorkizJobAttributes::fromPayload($this->payload(['Unit' => 12, 'Tags' => '']));
        $asArray = WorkizJobAttributes::fromPayload($this->payload(['Unit' => '#12', 'Tags' => ['RESCHEDULE']]));

        $this->assertSame('12', $asString['unit']);
        $this->assertSame('#12', $asArray['unit']);
        $this->assertSame(['RESCHEDULE'], $asArray['payload']['Tags']);
    }

    /**
     * Dates are 'Y-m-d H:i:s' and carry NO zone: `Timezone` is absent from every
     * real payload, whatever the published spec says. So the string is read as
     * wall time and the zone is somebody else's problem to attach — guessing UTC
     * here would move every A-1 appointment by four or five hours.
     */
    public function test_dates_are_read_as_wall_time_in_the_workiz_format(): void
    {
        $out = WorkizJobAttributes::fromPayload($this->payload());

        $this->assertSame('2026-09-23 08:43:06', $out['booked_at']);
        $this->assertSame('2026-09-25 08:00:00', $out['scheduled_for']);
        $this->assertSame('2026-09-25 11:00:00', $out['scheduled_end']);
    }

    public function test_a_zero_date_lands_as_null_rather_than_year_zero(): void
    {
        $out = WorkizJobAttributes::fromPayload($this->payload([
            'JobEndDateTime' => '0000-00-00 00:00:00',
            'PaymentDueDate' => '',
        ]));

        $this->assertNull($out['scheduled_end']);
    }

    /**
     * The account's own custom fields are undocumented and vary per account.
     * They are the reason `payload` exists; a fixed schema would drop them and
     * nothing would report it.
     */
    public function test_undocumented_custom_fields_survive_whole(): void
    {
        $out = WorkizJobAttributes::fromPayload($this->payload());

        $this->assertSame('Gas System', $out['payload']['system_type']);
        $this->assertSame('Appointment Time: 8-11', $out['payload']['time_frames']);
    }

    public function test_it_does_not_decide_what_the_caller_owns(): void
    {
        $out = WorkizJobAttributes::fromPayload($this->payload());

        // These four come from the connection, not the payload.
        $this->assertArrayNotHasKey('organization_id', $out);
        $this->assertArrayNotHasKey('franchise_id', $out);
        $this->assertArrayNotHasKey('connection_id', $out);
        $this->assertArrayNotHasKey('synced_at', $out);
    }
}
```

- [ ] **Step 3: Run it and watch it fail**

Run: `DB_CONNECTION=mysql DB_DATABASE=ezbizi_testing_workiz php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Workiz/WorkizJobAttributesTest.php`

Expected: FAIL — `Class "App\Support\Workiz\WorkizJobAttributes" not found`.

- [ ] **Step 4: Write the mapper**

```php
<?php

namespace App\Support\Workiz;

/**
 * One Workiz job payload, reduced to one landing row's attributes.
 *
 * Pure: no I/O, no models, no clock. Everything that comes from the connection
 * rather than from Workiz — organization, franchise, connection, synced_at —
 * is the caller's, so this can be tested against a payload alone.
 *
 * ── WHAT THE PUBLISHED SPEC WOULD HAVE GOT WRONG ────────────────
 *
 * Measured over 400 real payloads, 2026-09-23. Each of these is a defect a
 * plausible importer written from `developer.workiz.com/api.json` would have:
 *
 *   - NOTHING IS EVER NULL. Absent is `''`, so `?? null` never fires and the
 *     table fills with empty strings that no `whereNull` will ever find.
 *   - Keys change type between rows: Tags is string|array, Unit/PhoneExt/
 *     JobName are string|integer.
 *   - Dates are 'Y-m-d H:i:s' with NO zone. The doc shows ISO-8601 with a Z.
 *     Reading them as UTC moves every A-1 appointment by four or five hours.
 *   - The key set is open: the account's own custom fields arrive at the top
 *     level, undocumented, on most rows.
 */
final class WorkizJobAttributes
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function fromPayload(array $payload): array
    {
        return [
            'remote_id' => (string) ($payload['UUID'] ?? ''),
            'serial_id' => self::int($payload['SerialId'] ?? null),
            'client_id' => self::int($payload['ClientId'] ?? null),

            'service_area' => self::text($payload['ServiceArea'] ?? null, trim: true),
            'postal_code' => self::text($payload['PostalCode'] ?? null),
            'city' => self::text($payload['City'] ?? null),
            'state' => self::text($payload['State'] ?? null),
            'country' => self::text($payload['Country'] ?? null),
            'address' => self::text($payload['Address'] ?? null),
            'unit' => self::text($payload['Unit'] ?? null),
            'latitude' => self::float($payload['Latitude'] ?? null),
            'longitude' => self::float($payload['Longitude'] ?? null),

            'status' => self::text($payload['Status'] ?? null),
            'sub_status' => self::text($payload['SubStatus'] ?? null),
            'job_type' => self::text($payload['JobType'] ?? null),
            'job_source' => self::text($payload['JobSource'] ?? null),

            'booked_at' => self::moment($payload['CreatedDate'] ?? null),
            'scheduled_for' => self::moment($payload['JobDateTime'] ?? null),
            'scheduled_end' => self::moment($payload['JobEndDateTime'] ?? null),
            'last_status_update' => self::moment($payload['LastStatusUpdate'] ?? null),

            'created_by' => self::text($payload['CreatedBy'] ?? null),

            // Whole and unedited, including the untrimmed ServiceArea and every
            // custom field nobody flattened.
            'payload' => $payload,
        ];
    }

    /**
     * Workiz's absent value is the empty string, not null — so this is where
     * the table's NULLs actually come from.
     */
    private static function text(mixed $value, bool $trim = false): ?string
    {
        if ($value === null || is_array($value)) {
            return null;
        }

        $text = trim((string) $value);

        if (! $trim) {
            // Only leading/trailing whitespace is ever stripped for emptiness;
            // the stored value keeps its own spacing unless asked otherwise.
            $text = (string) $value;
        }

        return trim($text) === '' ? null : $text;
    }

    private static function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private static function float(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * A wall-clock string, kept as one.
     *
     * No zone is available — `Timezone` is absent from every real payload — so
     * this refuses to invent one. A1 Tools stores absent dates as
     * '0000-00-00', which MySQL will not accept into a DATETIME.
     */
    private static function moment(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }

        return $value;
    }
}
```

- [ ] **Step 5: Run the tests and watch them pass**

Run: `DB_CONNECTION=mysql DB_DATABASE=ezbizi_testing_workiz php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Workiz/WorkizJobAttributesTest.php`

Expected: PASS, 8 tests.

- [ ] **Step 6: Commit**

```bash
git add app/Support/Workiz/WorkizJobAttributes.php tests/Fixtures/workiz/job_payload.php tests/Feature/Workiz/WorkizJobAttributesTest.php
git commit -m "Workiz never sends null, and four other things the doc gets wrong"
```

---

### Task 3: A raw reader, beside the contract Reports depends on

**Files:**
- Modify: `app/Support/Reports/Experimental/WorkizJobs.php`
- Test: `tests/Feature/Workiz/WorkizRawReadTest.php`

**Interfaces:**
- Consumes: the existing private `connections(User $user)` helper and `self::PAGE`.
- Produces: `WorkizJobs::raw(SocialConnection $connection, int $limit = 1000, ?string $since = null): array{jobs: list<array<string, mixed>>, failed: ?string}` — full untouched payloads for ONE connection, so the sweep can enter one tenant at a time. `configured()`, `accounts()` and `all()` are untouched.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Workiz;

use App\Models\SocialConnection;
use App\Support\Reports\Experimental\WorkizJobs;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class WorkizRawReadTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_it_returns_whole_payloads_not_the_reports_five_fields(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $branch = $this->branch($tenant, 'New Jersey');
        $payload = require base_path('tests/Fixtures/workiz/job_payload.php');

        Http::fake(['api.workiz.com/*' => Http::response(['flag' => true, 'data' => [$payload]])]);

        $connection = $this->inTenant($tenant['org'], function () use ($branch, $tenant) {
            $c = new SocialConnection;
            $c->forceFill([
                'organization_id' => $tenant['org']->id,
                'scope_type' => SocialConnection::SCOPE_FRANCHISE,
                'scope_id' => $branch->id,
                'provider' => SocialProvider::Workiz->value,
                'remote_id' => $branch->id,
                'display_name' => 'New Jersey',
            ]);
            $c->connect(['api_token' => 'api_good', 'refresh_token' => 'api_good']);
            $c->save();

            return $c;
        });

        $out = app(WorkizJobs::class)->raw($connection);

        $this->assertNull($out['failed']);
        $this->assertCount(1, $out['jobs']);

        // The custom field proves it is the whole payload and not shape()'s five.
        $this->assertSame('Gas System', $out['jobs'][0]['system_type']);
        $this->assertSame('Upstate New York ', $out['jobs'][0]['ServiceArea']);
    }

    /**
     * A refusal names a status and never a URL — the token is in the path.
     */
    public function test_a_refusal_carries_a_status_and_no_url(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $branch = $this->branch($tenant, 'New Jersey');

        Http::fake(['api.workiz.com/*' => Http::response([], 403)]);

        $connection = $this->inTenant($tenant['org'], function () use ($branch, $tenant) {
            $c = new SocialConnection;
            $c->forceFill([
                'organization_id' => $tenant['org']->id,
                'scope_type' => SocialConnection::SCOPE_FRANCHISE,
                'scope_id' => $branch->id,
                'provider' => SocialProvider::Workiz->value,
                'remote_id' => $branch->id,
                'display_name' => 'New Jersey',
            ]);
            $c->connect(['api_token' => 'api_secret_token', 'refresh_token' => 'api_secret_token']);
            $c->save();

            return $c;
        });

        $out = app(WorkizJobs::class)->raw($connection);

        $this->assertSame([], $out['jobs']);
        $this->assertStringContainsString('403', (string) $out['failed']);
        $this->assertStringNotContainsString('api_secret_token', (string) $out['failed']);
        $this->assertStringNotContainsString('workiz.com', (string) $out['failed']);
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `DB_CONNECTION=mysql DB_DATABASE=ezbizi_testing_workiz php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Workiz/WorkizRawReadTest.php`

Expected: FAIL — `Call to undefined method ...::raw()`.

- [ ] **Step 3: Add `raw()` to `WorkizJobs`**

Append this method to the class. Change nothing else in the file.

```php
    /**
     * Every page of ONE connection's jobs, whole and unshaped.
     *
     * ── WHY THIS IS NOT `all()` WITH A FLAG ─────────────────────
     *
     * `all()` belongs to the Experimental report. Its return shape is pinned by
     * three tests in a file this session does not own
     * (`ExperimentalCallReportApiTest`), and `ExperimentalCallReportController:175`
     * does `array_column($accounts, 'name')` on `accounts()`. A flag that
     * widened `all()`'s payload would put the report's contract and the
     * importer's needs in one signature, and the next change to either would
     * have to reason about both.
     *
     * So: same paging, same pooled-failure rule, one connection at a time —
     * because the importer enters one tenant per connection and has no use for
     * a cross-account fan-out.
     *
     * @return array{jobs: list<array<string, mixed>>, failed: ?string}
     */
    public function raw(SocialConnection $connection, int $limit = 1000, ?string $since = null): array
    {
        $token = (string) ($connection->credentials['api_token'] ?? '');

        if ($token === '') {
            return ['jobs' => [], 'failed' => 'This branch has no stored token.'];
        }

        $jobs = [];

        for ($offset = 0; $offset < $limit; $offset += self::PAGE) {
            try {
                $response = Http::acceptJson()->get(
                    'https://api.workiz.com/api/v1/'.$token.'/job/all/',
                    [
                        'start_date' => $since ?? now()->subYears(5)->toDateString(),
                        'offset' => $offset,
                        'records' => min(self::PAGE, $limit - $offset),
                        'only_open' => 'false',
                    ],
                );
            } catch (ConnectionException) {
                // Never the exception's message: it carries the URL, and the
                // token is in the URL.
                return ['jobs' => $jobs, 'failed' => 'Workiz could not be reached.'];
            }

            if ($response->failed()) {
                return ['jobs' => $jobs, 'failed' => 'Workiz refused the job list ('.$response->status().').'];
            }

            $page = $response->json('data') ?? [];

            foreach ($page as $job) {
                if (is_array($job)) {
                    $jobs[] = $job;
                }
            }

            // A short page is the last page. Stopping on count alone would loop
            // for ever against an API that ignored the offset.
            if (count($page) < self::PAGE) {
                break;
            }
        }

        return ['jobs' => $jobs, 'failed' => null];
    }
```

- [ ] **Step 4: Run the new tests and watch them pass**

Run: `DB_CONNECTION=mysql DB_DATABASE=ezbizi_testing_workiz php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Workiz/WorkizRawReadTest.php`

Expected: PASS, 2 tests.

- [ ] **Step 5: Prove Reports' contract is untouched**

This is the step the boundary with Reports exists for. `--filter=Workiz` does **not** cover it — it misses `test_a_branch_with_a_full_page_is_asked_for_the_next_one`, the only test of the paging loop.

Run: `DB_CONNECTION=mysql DB_DATABASE=ezbizi_testing_workiz php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Reports/WorkizConnectTest.php tests/Feature/Reports/ExperimentalCallReportApiTest.php`

Expected: PASS, **23 tests, 71 assertions** — the same numbers as before this plan started. A different number means `all()` moved and the change must be reverted, not accommodated.

- [ ] **Step 6: Commit**

```bash
git add app/Support/Reports/Experimental/WorkizJobs.php tests/Feature/Workiz/WorkizRawReadTest.php
git commit -m "A raw reader beside the report's contract, not through it"
```

---

### Task 4: The sweep

**Files:**
- Create: `app/Console/Commands/SyncWorkizJobs.php`
- Modify: `routes/console.php` (schedule registration)
- Modify: `docs/MODULE-OWNERS.md` (the Workiz row)
- Test: `tests/Feature/Workiz/SyncWorkizJobsTest.php`

**Interfaces:**
- Consumes: `WorkizJobAttributes::fromPayload()` (Task 2), `WorkizJobs::raw()` (Task 3), `App\Models\WorkizJob` (Task 1).
- Produces: the console command `ezbizi:sync-workiz`, with `--since=` (a `Y-m-d` date) and `--connection=` (one connection id).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Workiz;

use App\Models\SocialConnection;
use App\Models\WorkizJob;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class SyncWorkizJobsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function connect(array $tenant, $branch, string $name): SocialConnection
    {
        return $this->inTenant($tenant['org'], function () use ($tenant, $branch, $name) {
            $c = new SocialConnection;
            $c->forceFill([
                'organization_id' => $tenant['org']->id,
                'scope_type' => SocialConnection::SCOPE_FRANCHISE,
                'scope_id' => $branch->id,
                'provider' => SocialProvider::Workiz->value,
                'remote_id' => $branch->id,
                'display_name' => $name,
            ]);
            $c->connect(['api_token' => 'api_'.$name, 'refresh_token' => 'api_'.$name]);
            $c->save();

            return $c;
        });
    }

    public function test_it_lands_a_job_on_the_connections_franchise(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $branch = $this->branch($tenant, 'New Jersey');
        $this->connect($tenant, $branch, 'New Jersey');

        $payload = require base_path('tests/Fixtures/workiz/job_payload.php');
        Http::fake(['api.workiz.com/*' => Http::response(['flag' => true, 'data' => [$payload]])]);

        $this->artisan('ezbizi:sync-workiz')->assertSuccessful();

        $row = $this->inTenant($tenant['org'], fn () => WorkizJob::query()->first());

        $this->assertSame('DG0KYN', $row->remote_id);
        $this->assertSame($branch->id, $row->franchise_id);
        $this->assertSame($tenant['org']->id, $row->organization_id);
        $this->assertSame('Upstate New York', $row->service_area);
        $this->assertSame('Gas System', $row->payload['system_type']);
    }

    /**
     * IDEMPOTENT. The sweep runs on a schedule; a second run must refresh the
     * row rather than mint a second one and a second history.
     */
    public function test_running_twice_refreshes_rather_than_duplicates(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $branch = $this->branch($tenant, 'New Jersey');
        $this->connect($tenant, $branch, 'New Jersey');

        $payload = require base_path('tests/Fixtures/workiz/job_payload.php');
        Http::fake(['api.workiz.com/*' => Http::response(['flag' => true, 'data' => [$payload]])]);
        $this->artisan('ezbizi:sync-workiz')->assertSuccessful();

        Http::fake(['api.workiz.com/*' => Http::response(['flag' => true, 'data' => [
            array_merge($payload, ['Status' => 'Done', 'SubStatus' => 'Paid']),
        ]]]);
        $this->artisan('ezbizi:sync-workiz')->assertSuccessful();

        $rows = $this->inTenant($tenant['org'], fn () => WorkizJob::query()->get());

        $this->assertCount(1, $rows);
        $this->assertSame('Done', $rows->first()->status);
        $this->assertSame('Paid', $rows->first()->sub_status);
    }

    /**
     * ONE BRANCH FAILING MUST NOT LOSE THE OTHERS. The accounts are disjoint,
     * so a branch dropped from a run is a branch missing from every count built
     * on this table, with nothing to show it was ever expected.
     */
    public function test_one_refused_branch_does_not_stop_the_rest(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $good = $this->branch($tenant, 'New Jersey');
        $bad = $this->branch($tenant, 'Cleveland');
        $this->connect($tenant, $good, 'New Jersey');
        $this->connect($tenant, $bad, 'Cleveland');

        $payload = require base_path('tests/Fixtures/workiz/job_payload.php');
        Http::fake([
            'api.workiz.com/api/v1/api_Cleveland/*' => Http::response([], 403),
            'api.workiz.com/*' => Http::response(['flag' => true, 'data' => [$payload]]),
        ]);

        $this->artisan('ezbizi:sync-workiz')->assertSuccessful();

        $rows = $this->inTenant($tenant['org'], fn () => WorkizJob::query()->get());

        $this->assertCount(1, $rows);
        $this->assertSame($good->id, $rows->first()->franchise_id);
    }

    /**
     * The scheduler is nobody — no request, no user. Without dropping the
     * global scope the connection query returns nothing and the run reports a
     * clean sweep having fetched from no one. SyncLeads makes the same point.
     */
    public function test_it_finds_connections_with_no_signed_in_user(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $branch = $this->branch($tenant, 'New Jersey');
        $this->connect($tenant, $branch, 'New Jersey');

        $payload = require base_path('tests/Fixtures/workiz/job_payload.php');
        Http::fake(['api.workiz.com/*' => Http::response(['flag' => true, 'data' => [$payload]])]);

        $this->assertGuest();
        $this->artisan('ezbizi:sync-workiz')->assertSuccessful();

        $this->assertSame(1, $this->inTenant($tenant['org'], fn () => WorkizJob::query()->count()));
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `DB_CONNECTION=mysql DB_DATABASE=ezbizi_testing_workiz php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Workiz/SyncWorkizJobsTest.php`

Expected: FAIL — `The command "ezbizi:sync-workiz" does not exist.`

- [ ] **Step 3: Write the command**

```php
<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\SocialConnection;
use App\Models\WorkizJob;
use App\Support\Reports\Experimental\WorkizJobs;
use App\Support\Social\SocialProvider;
use App\Support\Tenancy\TenantContext;
use App\Support\Workiz\WorkizJobAttributes;
use Illuminate\Console\Command;

/**
 * Mirror every connected branch's Workiz jobs into `workiz_jobs`.
 *
 * ── CADENCE IS WALL CLOCK, NOT QUOTA ────────────────────────────
 *
 * The opposite of SyncLeads, whose five-minute tick is a budget against
 * Google's 15,000-a-day project ceiling. Workiz advertises no rate-limit
 * header at all — measured 2026-09-15 — so there is no external allowance to
 * conserve and the only cost of running often is time. Hourly, because a
 * mirror that is an hour stale is still a mirror and nothing downstream is
 * real-time.
 *
 * ── READ-ONLY, AND THAT IS A RULING NOT A PHASE ─────────────────
 *
 * Nothing here calls a Workiz POST endpoint. Jose's 2026-09-14 ruling
 * approved holding a write-capable credential for a READ use; a write path
 * is a separate decision and would need `auth_secret`, which is not stored.
 *
 * ── ONE BRANCH'S FAILURE IS RECORDED, NOT PROPAGATED ────────────
 *
 * The ten accounts are disjoint and the same people book into all of them, so
 * a branch silently missing from a run makes every number built on this table
 * wrong rather than incomplete.
 */
class SyncWorkizJobs extends Command
{
    protected $signature = 'ezbizi:sync-workiz
        {--since= : Re-read from this date (Y-m-d) instead of the default window}
        {--connection= : Sync only this connection id}';

    protected $description = 'Mirror Workiz jobs for every connected branch';

    public function __construct(
        private readonly WorkizJobs $workiz,
        private readonly TenantContext $tenant,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        /*
         * Unscoped to FIND the work, then into each tenant to DO it.
         *
         * The scheduler is nobody: no request, no user, so the global scope
         * would filter every connection to nothing and this would report a
         * clean run having read from no one. And deliberately no `inView()`
         * filter — that answers "what may this person see", which is a question
         * about a request. A sweep legitimately reaches every connected
         * account.
         */
        $connections = SocialConnection::query()
            ->withoutGlobalScopes()
            ->where('provider', SocialProvider::Workiz->value)
            ->when($this->option('connection'), fn ($q, $id) => $q->whereKey($id))
            ->get();

        if ($connections->isEmpty()) {
            $this->info('No Workiz accounts are connected.');

            return self::SUCCESS;
        }

        $organizations = Organization::query()
            ->withoutGlobalScopes()
            ->whereIn('id', $connections->pluck('organization_id')->unique())
            ->get()
            ->keyBy(fn (Organization $o) => $o->getKey());

        $landed = 0;
        $failed = [];

        foreach ($connections as $connection) {
            $organization = $organizations[$connection->organization_id] ?? null;

            if ($organization === null) {
                $failed[(string) $connection->display_name] = 'No organization for this connection.';

                continue;
            }

            $result = $this->workiz->raw($connection, since: $this->option('since'));

            if ($result['failed'] !== null) {
                // By NAME, so a branch that answered nothing is visible rather
                // than merely absent.
                $failed[(string) $connection->display_name] = $result['failed'];
            }

            $landed += $this->tenant->run($organization, function () use ($connection, $result) {
                $count = 0;

                foreach ($result['jobs'] as $payload) {
                    $attributes = WorkizJobAttributes::fromPayload($payload);

                    if ($attributes['remote_id'] === '') {
                        continue;
                    }

                    WorkizJob::query()->updateOrCreate(
                        [
                            'connection_id' => $connection->getKey(),
                            'remote_id' => $attributes['remote_id'],
                        ],
                        $attributes + [
                            // From the connection, never from the payload — the
                            // whole placement rule in one line.
                            'franchise_id' => $connection->scope_id,
                            'synced_at' => now(),
                        ],
                    );

                    $count++;
                }

                return $count;
            });
        }

        $this->info($landed.' Workiz jobs mirrored from '.$connections->count().' connected branches.');

        foreach ($failed as $branch => $why) {
            $this->warn($branch.': '.$why);
        }

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Register the schedule**

In `routes/console.php`, beside the other sweeps:

```php
Schedule::command('ezbizi:sync-workiz')->hourly()->withoutOverlapping();
```

- [ ] **Step 5: Run the tests and watch them pass**

Run: `DB_CONNECTION=mysql DB_DATABASE=ezbizi_testing_workiz php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Workiz/SyncWorkizJobsTest.php`

Expected: PASS, 4 tests.

- [ ] **Step 6: Run everything this plan touches, plus Reports' contract**

Run: `DB_CONNECTION=mysql DB_DATABASE=ezbizi_testing_workiz php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Workiz tests/Feature/Reports/WorkizConnectTest.php tests/Feature/Reports/ExperimentalCallReportApiTest.php`

Expected: PASS. Reports' two files must still read **23 tests, 71 assertions**.

- [ ] **Step 7: Add the Workiz row to MODULE-OWNERS**

In the module table of `docs/MODULE-OWNERS.md`:

```markdown
| **Workiz** | `Api/WorkizConnectController.php` · `Support/Workiz/` · `Support/Reports/Experimental/WorkizJobs.php` · `Models/WorkizJob.php` · `Console/Commands/SyncWorkizJobs.php` · `tests/Feature/Workiz/` · `tests/Feature/Reports/WorkizConnectTest.php` · `tests/Fixtures/workiz/` · the `Workiz` arms of `SocialProvider` · the `workiz` routes | `local_ea9c0484-3b23-40b6-8262-10a1fccc1a3a` (EzBizi - Workiz API) |
```

Not ours, and named so nobody tidies them in: the Experimental call report and its tests are the Reports session's, including the three `workiz`-named tests in `ExperimentalCallReportApiTest` — they assert what the REPORT does. `config/experimental_reports.php` is theirs too.

- [ ] **Step 8: Commit**

```bash
git add app/Console/Commands/SyncWorkizJobs.php routes/console.php docs/MODULE-OWNERS.md tests/Feature/Workiz/SyncWorkizJobsTest.php
git commit -m "Mirror every branch's Workiz jobs, hourly, and never write back"
```

---

## Self-review

**Spec coverage.** §1 (what the API offers) → Tasks 2–3. §3.1 (`workiz_jobs` columns) → Task 1. §3.2 (Scheduling reads `service_area` + `postal_code`) → Task 1's index and Task 2's trim. §4.1 (placement from the connection) → Task 4 step 3. §4.2 (the 31-row inversion) → Task 1's migration docblock. §5 (sync, wall-clock cadence, read-only) → Task 4. §12 (regression is two whole files) → Task 3 step 5 and Task 4 step 6.

**Deliberately out of this plan, each its own:** `workiz_leads` (§3.1 last line), team linking (§7), automation triggers (§8), the permission rename (§9), the projection into `customers`/`jobs` (§3.2), duplicate-flagging (§4.3). None of them is a step in another task, so none is silently half-done.

**Type consistency.** `WorkizJobAttributes::fromPayload()` returns exactly the model's fillable keys minus the four the caller owns, and Task 4 supplies those four. `raw()` returns `{jobs, failed}` where `failed` is `?string` — singular, because it reads one connection; `all()`'s `failed` is `array<string,string>` and is untouched.

**One thing a reviewer should push on:** `text()` in Task 2 has a `$trim` parameter whose two branches both end in `trim($text) === ''`. It reads oddly and is worth simplifying during implementation — the behaviour the tests pin is what matters, not that shape.
