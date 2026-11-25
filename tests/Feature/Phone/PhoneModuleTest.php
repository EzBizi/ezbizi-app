<?php

namespace Tests\Feature\Phone;

use App\Support\Modules\ModuleRegistry;
use Tests\TestCase;

/**
 * Phone — real module declaration (view/edit, call/SMS/voicemail automations).
 *
 * Call started/ended/missed raise from the Voice webhooks. Call tagged raises
 * from CallTagsController when somebody says what a call was about — the
 * moment a call becomes a complaint or a booking, which is the only moment a
 * rule about complaints could fire. Voicemail left
 * raises from the recording callback. SMS received/sent raise from the SMS
 * webhook and MessagesController. number.broken raises from PhoneLineHealth
 * on a gateway failure transition. Subject is null (call, SMS, and number —
 * same shape as HR).
 */
class PhoneModuleTest extends TestCase
{
    public function test_phone_is_no_longer_automation_exempt_and_offers_edit(): void
    {
        $module = app(ModuleRegistry::class)->get('phone');

        $this->assertNull($module->automationExemptBecause);
        // Plus one view per tab (Jose, 2026-09-29), in the tab bar's order.
        $this->assertSame(
            // alerts.view: Communication ▸ Alerts (Jose, 2026-10-07).
            // chat.view: Communication ▸ Chat, the in-app WhatsApp (Jose, 2026-11-25).
            ['view', 'edit', 'phone.view', 'chat.view', 'users.view', 'records.view', 'reports.view', 'alerts.view', 'settings.view'],
            $module->actions,
        );
        $this->assertSame(
            [
                'phone.call.started',
                'phone.call.ended',
                'phone.call.missed',
                'phone.call.tagged',
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
        $this->assertTrue($module->trigger('phone.number.broken')->isRaised);
    }
}
