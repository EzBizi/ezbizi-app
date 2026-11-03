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
            $ours = PhoneNumber::query()->withoutGlobalScopes()
                ->where('connection_id', $connection->id)
                ->get(['e164', 'twilio_sid', 'account_sid']);

            // Lines parked on another account (the 855 on the main profile)
            // are read from that account with the platform credentials.
            foreach ($ours->groupBy(fn ($n) => $n->account_sid ?: $connection->account_sid) as $account => $lines) {
                $read = $account === $connection->account_sid
                    ? fn () => $twilio->listIncoming($connection)
                    : fn () => $twilio->listAccountIncoming((string) $account);
                $this->check($read, $lines->pluck('e164', 'twilio_sid')->all(), "{$connection->id}:{$account}", $host, $issues);
            }
        }

        foreach ($issues as $issue) {
            Log::warning('phone-line-audit: '.$issue);
            $this->warn($issue);
        }
        $this->info(count($connections).' accounts, '.count($issues).' issues.');

        return self::SUCCESS;
    }

    /** @param array<string, string> $ours sid => e164 */
    private function check(callable $read, array $ours, string $key, string $host, array &$issues): void
    {
        try {
            $live = collect($read())->keyBy('sid');
        } catch (Throwable $e) {
            $issues[] = "{$key}: Twilio unreachable: {$e->getMessage()}";

            return;
        }

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

        $cacheKey = "phone-line-audit:{$key}";
        $now = $live->map(fn ($l) => $l['phone_number'].'|'.$l['voice_url'].'|'.$l['sms_url'])->sort()->all();
        $before = Cache::get($cacheKey);
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
        Cache::forever($cacheKey, $now);
    }
}
