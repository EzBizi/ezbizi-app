<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Support\Phone\ExtensionAssigner;
use Illuminate\Console\Command;

/** Gives every active person without an extension the next free one (see ExtensionAssigner). */
class PhoneAssignExtensions extends Command
{
    protected $signature = 'ezbizi:phone-assign-extensions';

    protected $description = 'Give every connected phone account a ring group and voicemail if it has none, and every active person without an extension the next free one';

    public function handle(): int
    {
        foreach (Organization::query()->orderBy('name')->get() as $organization) {
            // Every connected account answers somebody (Jose, 2026-11-12).
            app(\App\Support\Tenancy\TenantContext::class)->run($organization, function () use ($organization) {
                foreach (\App\Models\PhoneConnection::query()->where('status', \App\Models\PhoneConnection::STATUS_CONNECTED)->get() as $connection) {
                    $made = \App\Support\Phone\ConnectionDefaults::ensure($connection);
                    if ($made !== []) {
                        $this->info("{$organization->name}: account {$connection->id} got a ".implode(' and a ', $made));
                    }
                }
            });
            $given = ExtensionAssigner::assignMissing($organization);
            if ($given > 0) {
                $this->info("{$organization->name}: {$given}");
            }
        }

        return self::SUCCESS;
    }
}
