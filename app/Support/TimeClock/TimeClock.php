<?php

namespace App\Support\TimeClock;

use App\Models\Organization;
use App\Support\Settings\SettingsResolver;

final class TimeClock
{
    public function __construct(private readonly SettingsResolver $settings) {}

    public function organizationEnabled(Organization $organization): bool
    {
        return (bool) $this->settings->get('time_clock.enabled', $organization);
    }
}
