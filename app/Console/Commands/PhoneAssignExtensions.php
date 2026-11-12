<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Support\Phone\ExtensionAssigner;
use Illuminate\Console\Command;

/** Gives every active person without an extension the next free one (see ExtensionAssigner). */
class PhoneAssignExtensions extends Command
{
    protected $signature = 'ezbizi:phone-assign-extensions';

    protected $description = 'Give every connected phone account a ring group and voicemail if it has none, every line its 911 address on file, and every active person without an extension the next free one';

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
                // Every line's 911 address, from its branch or company on file.
                foreach (\App\Models\PhoneNumber::query()->where('emergency_address_set', false)->whereNotNull('twilio_sid')->get() as $number) {
                    $why = \App\Support\Phone\EmergencyAddress::attach($number, app(\App\Support\Phone\TwilioGateway::class));
                    $this->info("{$organization->name}: {$number->e164} ".($why ?? 'E911 address attached'));
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
