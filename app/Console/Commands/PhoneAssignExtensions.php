<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Support\Phone\ExtensionAssigner;
use Illuminate\Console\Command;

/** Gives every active person without an extension the next free one (see ExtensionAssigner). */
class PhoneAssignExtensions extends Command
{
    protected $signature = 'ezbizi:phone-assign-extensions';

    protected $description = 'Give every active person without an extension the next free one, in every organization';

    public function handle(): int
    {
        foreach (Organization::query()->orderBy('name')->get() as $organization) {
            $given = ExtensionAssigner::assignMissing($organization);
            if ($given > 0) {
                $this->info("{$organization->name}: {$given}");
            }
        }

        return self::SUCCESS;
    }
}
