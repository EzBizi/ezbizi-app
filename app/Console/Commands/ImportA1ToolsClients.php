<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Franchise;
use App\Models\Organization;
use App\Models\WorkizClient;
use App\Support\Audit\AuditLogger;
use App\Support\Hr\A1ToolsPeopleImport;
use App\Support\Numbering\RecordNumberGenerator;
use App\Support\Tenancy\TenantContext;
use App\Support\Workiz\PhoneE164;
use App\Support\Workiz\StateCode;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * A1 Tools' client list into EzBizi customers (docs/A1-TOOLS-CLIENTS-JOBS-MIGRATION.md).
 *
 * The file's format and the rules are that spec's clients-importer section;
 * read it first.
 *
 * ── The whole file is checked before anything is written ─────────
 *
 * As HR's importer does: one defect anywhere refuses the run, so a typo in the
 * export cannot become thirty thousand customers quietly missing a field.
 *
 * ── Who is already here ───────────────────────────────────────────
 *
 * In order: a Workiz key (franchise + client number) the mirror already holds;
 * a live customer with the same phone AND the same name; an earlier row of the
 * same file by either. Phone alone is not a match. A1's own import already
 * merged rows sharing a phone and a first name, so every same-phone pair left is
 * two people: a household, or a relay line. A matched row is never written to,
 * not even a blank field, and a phone match registers no key, because
 * auto-merging on phone is refused.
 *
 * ── The key is written with the customer, under the projection's lock ──
 *
 * In one transaction, so the projection adopts it: the client's next Workiz job
 * finds the key, keeps its customer and attaches the job, instead of minting a
 * second customer. The lock is the projection's own, because two writers racing
 * over the same rows is what made 500 duplicate customers on 2026-09-23.
 *
 * Nothing about a person is printed, only counts. The manifest, one JSON line per
 * row (A1 id, outcome, EzBizi ids), is the rollback's positive provenance and the
 * billing import's map from an A1 client to its customer.
 */
class ImportA1ToolsClients extends Command
{
    public const FORMAT = 'ezbizi.a1-clients/1';

    protected $signature = 'customers:import-a1-tools
        {file : The clients JSON written by the A1 Tools export}
        {--dry-run : Check everything and report what would be written, writing nothing}
        {--wait=120 : Seconds to wait for a running Workiz projection}';

    protected $description = 'Import the A1 Tools client list as customers, registering each Workiz key';

    private const TOP_KEYS = ['format', 'exported_at', 'organization', 'clients'];

    /** Every key a client may carry, with the column width its text must fit. */
    private const TEXT = [
        'first_name' => 255, 'last_name' => 255, 'display_name' => 255, 'company_name' => 255,
        'email' => 255, 'phone' => 40, 'phone_secondary' => 40, 'address_line1' => 255,
        'address_line2' => 255, 'city' => 255, 'state' => 40, 'zip' => 12, 'notes' => 60000,
    ];

    private const OTHER_KEYS = ['a1_id', 'franchise', 'workiz', 'client_since'];

    /** A refusal prints this many problems and the total; a broken export can have thousands. */
    private const SHOWN = 50;

    /** @var string[] */
    private array $errors = [];

    private int $blankedEmails = 0;

    private int $blankedPhones = 0;

    public function handle(TenantContext $tenant, RecordNumberGenerator $numbers, AuditLogger $audit): int
    {
        $json = json_decode((string) @file_get_contents((string) $this->argument('file')), true);

        if (! is_array($json)) {
            return $this->refuse(['The file is not readable JSON.']);
        }

        foreach (array_diff(array_keys($json), self::TOP_KEYS) as $unknown) {
            $this->errors[] = "The file has a key this importer does not know: {$unknown}.";
        }

        if (($json['format'] ?? null) !== self::FORMAT) {
            $this->errors[] = 'The file is not format '.self::FORMAT.'.';
        }

        $organization = Organization::query()->where('name', $json['organization'] ?? '')->first();

        if ($organization === null) {
            return $this->refuse([...$this->errors, 'The organization the file names does not exist.']);
        }

        return $tenant->run($organization, fn () => $this->import($json['clients'] ?? null, $numbers, $audit));
    }

    private function import(mixed $clients, RecordNumberGenerator $numbers, AuditLogger $audit): int
    {
        $rows = $this->check($clients, $this->franchises());

        if ($this->errors !== []) {
            return $this->refuse($this->errors);
        }

        if ($this->option('dry-run')) {
            $this->report($this->match($rows));
            $this->info('Dry run: nothing was written.');

            return self::SUCCESS;
        }

        $lock = Cache::lock(ProjectWorkizRecords::LOCK, 3600);

        try {
            $lock->block((int) $this->option('wait'));
        } catch (LockTimeoutException) {
            $this->error('The Workiz projection is running. Nothing was written; run this again.');

            return self::FAILURE;
        }

        try {
            // Matched only now, under the lock: a projection that ran between a
            // match and the write could have taken one of these keys.
            $rows = $this->match($rows);
            $this->report($rows);

            return $this->write($rows, $numbers, $audit);
        } finally {
            $lock->release();
        }
    }

    // ── pass one: read and check, write nothing ───────────────────

    /** @return array<string, Franchise> A1 franchise code => franchise under A-1 Chimney */
    private function franchises(): array
    {
        $company = Company::query()->where('name', A1ToolsPeopleImport::FRANCHISE_COMPANY)->first();
        $byName = $company === null ? collect() : Franchise::query()->where('company_id', $company->getKey())->get()->keyBy('name');

        $out = [];

        foreach (A1ToolsPeopleImport::FRANCHISES as $code => $name) {
            if ($byName->has($name)) {
                $out[$code] = $byName[$name];
            }
        }

        return $out;
    }

    /** @param array<string, Franchise> $franchises */
    private function check(mixed $clients, array $franchises): array
    {
        if (! is_array($clients) || ! array_is_list($clients) || $clients === []) {
            $this->errors[] = 'The file lists no clients.';

            return [];
        }

        $rows = [];
        $seen = [];

        foreach ($clients as $i => $raw) {
            $a1 = is_array($raw) ? ($raw['a1_id'] ?? null) : null;
            $label = 'client '.($i + 1).(is_int($a1) ? " (A1 id {$a1})" : '');

            if (! is_array($raw)) {
                $this->errors[] = "{$label} is not an object.";

                continue;
            }

            foreach (array_diff(array_keys($raw), [...self::OTHER_KEYS, ...array_keys(self::TEXT)]) as $unknown) {
                $this->errors[] = "{$label}: unknown key {$unknown}.";
            }

            if (! is_int($a1) || $a1 < 1) {
                $this->errors[] = "{$label}: a1_id is not a positive whole number.";
            } elseif (isset($seen[$a1])) {
                $this->errors[] = "{$label}: the same a1_id as client {$seen[$a1]}.";
            } else {
                $seen[$a1] = $i + 1;
            }

            $text = [];

            foreach (self::TEXT as $key => $max) {
                $value = $raw[$key] ?? null;

                if ($value !== null && ! is_string($value)) {
                    $this->errors[] = "{$label}: {$key} is not text.";
                } elseif (is_string($value) && mb_strlen(trim($value)) > $max) {
                    $this->errors[] = "{$label}: {$key} is longer than {$max} characters.";
                }

                $text[$key] = is_string($value) && trim($value) !== '' ? trim($value) : null;
            }

            $franchise = is_string($raw['franchise'] ?? null) ? ($franchises[$raw['franchise']] ?? null) : null;

            if ($franchise === null) {
                $this->errors[] = "{$label}: franchise is not an A1 franchise code EzBizi has.";
            }

            $keys = [];

            foreach (is_array($raw['workiz'] ?? []) ? ($raw['workiz'] ?? []) : [null] as $key) {
                $at = is_array($key) && is_string($key['franchise'] ?? null) ? ($franchises[$key['franchise']] ?? null) : null;

                if ($at === null || ! is_int($key['client_id'] ?? null) || $key['client_id'] < 1 || count($key) !== 2) {
                    $this->errors[] = "{$label}: a workiz key needs exactly an A1 franchise code EzBizi has and a client_id number.";

                    continue;
                }

                $keys[] = ['franchise' => $at, 'client_id' => $key['client_id']];
            }

            $since = $raw['client_since'] ?? null;

            if ($since !== null && (! is_string($since) || ! $this->isDate($since))) {
                $this->errors[] = "{$label}: client_since is not a YYYY-MM-DD date.";
            }

            if ($this->errors !== []) {
                continue;
            }

            $rows[] = $this->row($a1, $franchise, $keys, $text, $since);
        }

        return $rows;
    }

    private function row(int $a1, Franchise $franchise, array $keys, array $text, ?string $since): array
    {
        $email = Customer::realEmail($text['email']);
        $phone = PhoneE164::from($text['phone']);
        $real = Customer::realPhone($phone);

        $this->blankedEmails += (int) ($text['email'] !== null && $email === null);
        $this->blankedPhones += (int) ($phone !== null && $real === null);

        $name = trim(($text['first_name'] ?? '').' '.($text['last_name'] ?? ''));
        $name = $name !== '' ? $name : ($text['display_name'] ?? $text['company_name'] ?? "A1 Tools client {$a1}");

        $first = 'From A1 Tools';
        if ($keys !== []) {
            $first .= ', Workiz client '.implode(', ', array_map(
                fn (array $k) => "#{$k['client_id']} ({$k['franchise']->name})", $keys));
        }
        $first .= '.';

        return [
            'a1_id' => $a1,
            'franchise' => $franchise,
            'keys' => $keys,
            'company' => $text['company_name'],
            'customer' => [
                'owning_franchise_id' => $franchise->getKey(),
                'name' => $name,
                'email' => $email,
                'phone' => $real,
                'address_line1' => $text['address_line1'],
                'address_line2' => $text['address_line2'],
                'city' => $text['city'],
                'state' => StateCode::from($text['state']),
                'postal_code' => $text['zip'],
                'notes' => implode("\n", array_filter([
                    $first,
                    $text['company_name'] !== null ? "Company: {$text['company_name']}" : null,
                    $text['phone_secondary'] !== null ? "Second phone: {$text['phone_secondary']}" : null,
                    $text['notes'],
                ])),
                'is_active' => true,
                // When they became a customer: Customers' column for exactly
                // this, since created_at can only say the import day.
                'customer_since' => $since,
            ],
        ];
    }

    /**
     * Decide each row's outcome against the mirror, the live customers and the
     * rows before it: `workiz`, `phone`, `duplicate` (of an earlier new row), or
     * `new`.
     */
    private function match(array $rows): array
    {
        $known = WorkizClient::query()->get(['franchise_id', 'client_id', 'customer_id'])
            ->mapWithKeys(fn (WorkizClient $c) => ["k:{$c->franchise_id}|{$c->client_id}" => $c->customer_id]);

        $byPhone = Customer::query()->whereNotNull('phone')->get(['id', 'phone', 'name'])
            ->mapWithKeys(fn (Customer $c) => ['p:'.$c->phone.'|'.self::norm($c->name) => $c->getKey()]);

        $claimed = [];

        foreach ($rows as $i => $row) {
            $keys = array_map(fn (array $k) => 'k:'.$k['franchise']->getKey().'|'.$k['client_id'], $row['keys']);
            $phone = $row['customer']['phone'] === null ? [] : ['p:'.$row['customer']['phone'].'|'.self::norm($row['customer']['name'])];

            $rows[$i]['status'] = 'new';

            foreach ($keys as $key) {
                if ($known->has($key)) {
                    $rows[$i] = ['status' => 'workiz', 'customer_id' => $known[$key]] + $rows[$i];

                    continue 2;
                }
            }

            foreach ($phone as $key) {
                if ($byPhone->has($key)) {
                    $rows[$i] = ['status' => 'phone', 'customer_id' => $byPhone[$key]] + $rows[$i];

                    continue 2;
                }
            }

            foreach ([...$keys, ...$phone] as $key) {
                if (isset($claimed[$key])) {
                    $rows[$i] = ['status' => 'duplicate', 'of' => $claimed[$key]] + $rows[$i];

                    continue 2;
                }
            }

            foreach ([...$keys, ...$phone] as $key) {
                $claimed[$key] = $i;
            }
        }

        return $rows;
    }

    // ── pass two: write ───────────────────────────────────────────

    private function write(array $rows, RecordNumberGenerator $numbers, AuditLogger $audit): int
    {
        $path = 'a1-imports/clients-'.now()->format('Ymd-His').'.jsonl';
        Storage::disk('local')->makeDirectory('a1-imports');

        // A line per row as it lands, so a run that dies still names what it created.
        $manifest = fopen(Storage::disk('local')->path($path), 'a');
        $created = 0;

        try {
            foreach ($rows as $i => $row) {
                $line = ['a1_id' => $row['a1_id'], 'status' => $row['status']];

                if ($row['status'] === 'new') {
                    [$customer, $links] = DB::transaction(function () use ($row, $numbers) {
                        $customer = Customer::create($row['customer'] + [
                            'number' => $numbers->next(RecordNumberGenerator::CUSTOMERS, $row['franchise']),
                        ]);

                        $links = array_map(fn (array $k) => WorkizClient::create([
                            'franchise_id' => $k['franchise']->getKey(),
                            'client_id' => $k['client_id'],
                            'customer_id' => $customer->getKey(),
                            'name' => $customer->name,
                            'company' => $row['company'],
                            'email' => $customer->email,
                            'phone' => $customer->phone,
                            'phone_digits' => $customer->phone === null ? null : preg_replace('/\D+/', '', $customer->phone),
                            'address' => $customer->address_line1,
                            'unit' => $customer->address_line2,
                            'city' => $customer->city,
                            'state' => $customer->state,
                            'postal_code' => $customer->postal_code,
                            'synced_at' => now(),
                        ])->getKey(), $row['keys']);

                        return [$customer->getKey(), $links];
                    });

                    $rows[$i]['customer_id'] = $customer;
                    $line = ['a1_id' => $row['a1_id'], 'status' => 'created', 'customer_id' => $customer, 'workiz_client_ids' => $links];
                    $created++;
                } else {
                    $line['customer_id'] = $row['status'] === 'duplicate' ? $rows[$row['of']]['customer_id'] : $row['customer_id'];
                }

                fwrite($manifest, json_encode($line)."\n");
            }
        } finally {
            fclose($manifest);
        }

        $counts = array_count_values(array_column($rows, 'status'));

        // One row per run, as the projection writes: Customers' ruling on imports.
        $audit->log('customer.imported', metadata: [
            'source' => 'a1tools',
            'created' => $created,
            'workiz' => $counts['workiz'] ?? 0,
            'phone' => $counts['phone'] ?? 0,
            'duplicate' => $counts['duplicate'] ?? 0,
            'manifest' => $path,
        ]);

        $this->info("Created {$created} customers. Manifest: {$path} on the local disk.");

        return self::SUCCESS;
    }

    // ── helpers ───────────────────────────────────────────────────

    private function report(array $rows): void
    {
        $counts = array_count_values(array_column($rows, 'status'));

        $this->info(sprintf(
            '%d clients read: %d match a Workiz client EzBizi has, %d match a customer by phone and name, %d repeat an earlier row, %d to create.',
            count($rows), $counts['workiz'] ?? 0, $counts['phone'] ?? 0, $counts['duplicate'] ?? 0, $counts['new'] ?? 0,
        ));

        $byFranchise = collect($rows)->where('status', 'new')->countBy(fn (array $r) => $r['franchise']->name)->sortKeys();
        $this->line('To create, by franchise: '.$byFranchise->map(fn (int $n, string $name) => "{$name} {$n}")->implode(', ').'.');
        $this->line("Blanked as placeholders: emails {$this->blankedEmails}, phones {$this->blankedPhones}.");
    }

    private function refuse(array $errors): int
    {
        $this->error('Refused. Nothing was written. '.count($errors).' problem(s):');

        foreach (array_slice($errors, 0, self::SHOWN) as $error) {
            $this->line("  - {$error}");
        }

        if (count($errors) > self::SHOWN) {
            $this->line('  … and '.(count($errors) - self::SHOWN).' more.');
        }

        return self::FAILURE;
    }

    private function isDate(string $value): bool
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    private static function norm(string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($name)));
    }
}
