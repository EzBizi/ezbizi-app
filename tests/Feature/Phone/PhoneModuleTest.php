<?php

namespace Tests\Feature\Phone;

use App\Support\Modules\ModuleRegistry;
use Tests\TestCase;

/**
 * Phone — real module declaration (view/edit, call/SMS/voicemail automations).
 *
 * Triggers stay unraised until later tasks emit from webhooks. Subject is null
 * (call, SMS, and number — same shape as HR).
 */
class PhoneModuleTest extends TestCase
{
    public function test_phone_is_no_longer_automation_exempt_and_offers_edit(): void
    {
        $module = app(ModuleRegistry::class)->get('phone');

        $this->assertNull($module->automationExemptBecause);
        $this->assertSame(['view', 'edit'], $module->actions);
        $this->assertSame(
            [
                'phone.call.started',
                'phone.call.ended',
                'phone.call.missed',
                'phone.voicemail.left',
                'phone.sms.received',
                'phone.sms.sent',
                'phone.number.broken',
            ],
            array_map(fn ($t) => $t->key, $module->triggers),
        );
        $this->assertFalse($module->triggers[0]->isRaised);
    }
}
