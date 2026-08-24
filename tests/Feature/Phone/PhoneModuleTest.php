<?php

namespace Tests\Feature\Phone;

use App\Support\Modules\ModuleRegistry;
use Tests\TestCase;

/**
 * Phone — real module declaration (view/edit, call/SMS/voicemail automations).
 *
 * Call started/ended/missed raise from the Voice webhooks. Voicemail left
 * raises from the recording callback. SMS received/sent raise from the SMS
 * webhook and MessagesController. number.broken stays unraised until that
 * path emits. Subject is null (call, SMS, and number — same shape as HR).
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
        $this->assertTrue($module->triggers[0]->isRaised);
        $this->assertTrue($module->trigger('phone.call.started')->isRaised);
        $this->assertTrue($module->trigger('phone.call.ended')->isRaised);
        $this->assertTrue($module->trigger('phone.call.missed')->isRaised);
        $this->assertTrue($module->trigger('phone.voicemail.left')->isRaised);
        $this->assertTrue($module->trigger('phone.sms.received')->isRaised);
        $this->assertTrue($module->trigger('phone.sms.sent')->isRaised);
        $this->assertFalse($module->trigger('phone.number.broken')->isRaised);
    }
}
