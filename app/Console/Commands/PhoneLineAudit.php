<?php

namespace App\Console\Commands;

use App\Models\PhoneConnection;
use App\Models\PhoneNumber;
use App\Support\Phone\TwilioGateway;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Nightly, read-only: does every line on Twilio still point at us, and did the
 * set of numbers change since last night?
 *
 * Twilio lets anybody with console access repoint a number or release it, and
 * nothing tells us. A line whose voice URL left EzBizi rings somewhere else
 * and the Calls screen just goes quiet. This compares Twilio's list with ours
 * and with last night's snapshot, and logs every difference as a warning.
 *
 * ponytail: log-only alert; send it to a person when Jose picks who.
 */
class PhoneLineAudit extends Command
{
    protected $signature = 'ezbizi:phone-line-audit';

    protected $description = 'Check every Twilio line still routes to EzBizi and report changes';

    public function handle(TwilioGateway $twilio): int
    {
        $host = rtrim((string) config('app.url'), '/');
        $issues = [];

        $connections = PhoneConnection::query()
            ->withoutGlobalScopes()
            ->where('status', PhoneConnection::STATUS_CONNECTED)
            ->get();

        foreach ($connections as $connection) {
            try {
                $live = collect($twilio->listIncoming($connection))->keyBy('sid');
            } catch (Throwable $e) {
                $issues[] = "{$connection->id}: Twilio unreachable: {$e->getMessage()}";

                continue;
            }

            $ours = PhoneNumber::query()->withoutGlobalScopes()
                ->where('connection_id', $connection->id)
                // A line parked on another account (the 855 on the main profile)
                // is not in this account's list; ponytail: unchecked until we list that account.
                ->where(fn ($q) => $q->whereNull('account_sid')->orWhere('account_sid', $connection->account_sid))
                ->pluck('e164', 'twilio_sid');

            foreach ($ours as $sid => $e164) {
                $line = $live->get($sid);
                if ($line === null) {
                    $issues[] = "{$e164}: gone from Twilio";
                } elseif (! str_starts_with($line['voice_url'], $host.'/')) {
                    $issues[] = "{$e164}: voice goes to ".($line['voice_url'] ?: 'nothing');
                } elseif (! str_starts_with($line['sms_url'], $host.'/')) {
                    $issues[] = "{$e164}: texts go to ".($line['sms_url'] ?: 'nothing');
                }
            }

            $key = "phone-line-audit:{$connection->id}";
            $now = $live->map(fn ($l) => $l['phone_number'].'|'.$l['voice_url'].'|'.$l['sms_url'])->sort()->all();
            $before = Cache::get($key);
            if (is_array($before)) {
                foreach (array_diff_key($now, $before) as $line) {
                    $issues[] = 'added on Twilio: '.strtok($line, '|');
                }
                foreach (array_diff_key($before, $now) as $line) {
                    $issues[] = 'removed from Twilio: '.strtok($line, '|');
                }
                foreach (array_intersect_key($now, $before) as $sid => $line) {
                    if ($line !== $before[$sid]) {
                        $issues[] = 'changed on Twilio: '.strtok($line, '|');
                    }
                }
            }
            Cache::forever($key, $now);
        }

        foreach ($issues as $issue) {
            Log::warning('phone-line-audit: '.$issue);
            $this->warn($issue);
        }
        $this->info(count($connections).' accounts, '.count($issues).' issues.');

        return self::SUCCESS;
    }
}
