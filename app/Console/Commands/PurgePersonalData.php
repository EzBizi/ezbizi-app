<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\User;
use App\Support\Privacy\Anonymiser;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

/**
 * Erase one person, and show what survived (§9's purge).
 *
 * ── Why this is a command and not a button ───────────────────────
 *
 * §16 defers the legal question, and the part of it that bites here is
 * "verified rights-request handling (access, deletion, correction) with identity
 * checks". The MECHANISM is what §9 says to build now, and it is built. The
 * verification around it is the deferred half — and an erase button reachable by
 * anybody with a delete permission, before that half exists, is a way to destroy
 * somebody else's records on an unverified request.
 *
 * So the operation lives here: deliberate, typed out, audited, and preceded by a
 * dry run that has to be read. When §16 is answered this class becomes two lines
 * inside whatever handles a verified request.
 *
 * ── The dry run is the point, not a courtesy ─────────────────────
 *
 * A purge is irreversible and its report is the only place anybody learns what
 * DOESN'T go. Running it without `--force` prints the whole picture — every
 * table, what would be blanked, what would be removed, and the list of things
 * that survive with the reason each gave — and then stops. Somebody answering a
 * rights request needs to read that list before they promise anything, because
 * a signature stays and they will have to say so.
 */
class PurgePersonalData extends Command
{
    protected $signature = 'ezbizi:purge-personal-data
                            {kind : user or customer}
                            {id : the uuid of the person}
                            {--force : actually erase; without this it only reports}';

    protected $description = 'Anonymise one person in place across every table that declares personal data (§9)';

    public function __construct(private readonly Anonymiser $anonymiser)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $subject = $this->subject();

        if ($subject === null) {
            return self::FAILURE;
        }

        $force  = (bool) $this->option('force');
        $report = $force ? $this->anonymiser->purge($subject) : $this->anonymiser->preview($subject);

        $this->render($report, $force);

        return self::SUCCESS;
    }

    /**
     * The person, found without a tenant in context.
     *
     * `withoutGlobalScopes()` because this runs from a console with no request
     * and therefore no organization — the Anonymiser then reads the organization
     * off the subject itself and enters it, which is the only ordering that
     * cannot purge into the wrong tenant.
     */
    private function subject(): ?Model
    {
        $kind = strtolower((string) $this->argument('kind'));
        $id   = (string) $this->argument('id');

        $class = match ($kind) {
            'user', 'users'         => User::class,
            'customer', 'customers' => Customer::class,
            default                 => null,
        };

        if ($class === null) {
            $this->error("'{$kind}' is not a kind of person this system holds records about. "
                .'Use "user" for somebody with an account, or "customer" for somebody worked for.');

            return null;
        }

        $query = $class::query()->withoutGlobalScopes();

        // A soft-deleted customer is the likeliest subject of a request, because
        // the organization already believes they are gone.
        if ($class === Customer::class) {
            $query->withTrashed();
        }

        $subject = $query->find($id);

        if ($subject === null) {
            $this->error("No {$kind} with id {$id}.");
        }

        return $subject;
    }

    private function render(array $report, bool $force): void
    {
        $this->newLine();
        $this->line(sprintf(
            '%s %s in %s',
            $force ? 'Purged' : 'Would purge',
            $report['subject']['type'].' '.$report['subject']['id'],
            $report['organization']['name'],
        ));
        $this->newLine();

        foreach ($report['tables'] as $line) {
            if (! $line['applies']) {
                $this->line(sprintf('  %-22s —  holds nothing about this kind of person', $line['table']));

                continue;
            }

            $this->line(sprintf(
                '  %-22s %d row(s)%s%s',
                $line['table'],
                $line['rows'],
                $line['rows_dropped'] > 0 ? '  removed: '.$line['rows_dropped'] : '',
                $line['files_removed'] > 0 ? '  files: '.$line['files_removed'] : '',
            ));

            /*
             * Column → what it becomes, rather than a list of names under the
             * word "blank".
             *
             * This said "blank: name, email, …" and half of those are not
             * blanked: `name` becomes "Removed customer" and `email` becomes an
             * unroutable address. Somebody deciding whether to press on is
             * reading this to find out what the record will LOOK like afterwards,
             * and a label that says blank where text is written is the kind of
             * comment R15 calls a defect — it just happened to be output rather
             * than a comment.
             */
            foreach ($line['columns_erased'] as $column => $becomes) {
                $this->line(sprintf('      %-22s → %s', $column, $becomes));
            }

            foreach ($line['refusals'] as $refusal) {
                $this->warn('      refused — '.$refusal['because']);
            }
        }

        /*
         * The survivors, printed last and printed always.
         *
         * This is the half of the report that a person answering a rights request
         * has to read, and it is the half a purge that only counted deletions
         * would never show them. "Your name stays on the contract you signed" is
         * something to say before the purge, not after it is discovered.
         */
        $this->newLine();
        $this->line('What survives, and why:');

        $survivors = 0;

        foreach ($report['tables'] as $line) {
            // Only where there is actually something to survive. A table with no
            // rows about this person has a policy, not a survivor, and printing
            // its reasoning here buries the entries that matter under the ones
            // that do not apply.
            if (! $line['applies'] || $line['rows'] === 0) {
                continue;
            }

            foreach ($line['kept'] as $kept) {
                $survivors++;
                $this->line(sprintf('  %s.%s', $line['table'], $kept['field']));
                $this->line('      '.$kept['because']);
            }
        }

        if ($survivors === 0) {
            $this->line('  Nothing. Every table holding rows about this person erases in full.');
        }

        $this->newLine();
        $this->line('Where this does not reach:');

        foreach ($report['caveats'] as $caveat) {
            $this->line('  · '.$caveat);
        }

        if (! $force) {
            $this->newLine();
            $this->comment('Nothing was changed. Re-run with --force to erase.');
        }
    }
}
