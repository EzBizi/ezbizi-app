<?php

namespace Tests\Feature\Automations;

use App\Http\Controllers\Api\Phone\StatusWebhookController;
use App\Models\Automation;
use App\Models\AutomationEvent;
use App\Models\AutomationRun;
use App\Models\AutomationVersion;
use App\Models\Customer;
use App\Models\PhoneCall;
use App\Models\User;
use App\Support\Automations\EventEmitter;
use App\Support\Privacy\Anonymiser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * An erased person's number, address and name leave the automation outbox.
 *
 * Payloads are the shapes the emitters write — the phone one produced by
 * StatusWebhookController::payload itself, the others as captured from
 * production on 2026-09-24 (keys only) — because matching is by key.
 */
class AutomationEventErasureTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const PHONE = '+15550142233';
    private const EMAIL = 'marta@example.test';

    public function test_erasing_a_customer_drops_the_events_about_them_and_their_runs(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        [$customer, $about, $other] = $this->inTenant($alpha['org'], function () use ($alpha) {
            $customer = Customer::create([
                'owning_franchise_id' => $alpha['franchise']->id, 'number' => 'C-1',
                'name' => 'Marta Ruiz', 'email' => self::EMAIL, 'phone' => self::PHONE,
            ]);
            $events = app(EventEmitter::class);

            $about = [
                $events->emit('customer.created', $customer,
                    ['after' => $customer->only(['name', 'email', 'phone', 'owning_franchise_id', 'is_active'])],
                    actor: $alpha['admin']),
                $events->emit('phone.call.missed', null, $this->callPayload(self::PHONE)),
                $events->emit('email.received', null, [
                    'address' => 'office@alpha.test', 'provider' => 'google', 'display_name' => 'Office',
                    'message_id' => 'm-1', 'subject' => 'My boiler', 'from_name' => 'Marta Ruiz',
                    'from_address' => self::EMAIL, 'received_at' => now()->toIso8601String(),
                    'body_matched' => '', 'has_attachments' => false,
                ]),
                $events->emit('lead.received', null, ['after' => [
                    'source' => 'thumbtack', 'contact_name' => 'Marta', 'phone' => self::PHONE,
                    'category' => 'Chimney', 'received_at' => now()->toIso8601String(),
                ]]),
            ];
            $this->aRunFor($alpha, $about[1]);

            // Somebody else's call, and an event the admin raised about nobody.
            $other = [
                $events->emit('phone.call.missed', null, $this->callPayload('+15550199999')),
                $events->emit('company.created', $alpha['company'],
                    ['after' => ['name' => 'Alpha Co']], actor: $alpha['admin']),
            ];

            return [$customer, $about, $other];
        });

        app(Anonymiser::class)->purge($customer);

        $this->inTenant($alpha['org'], function () use ($about, $other, $alpha) {
            foreach ($about as $event) {
                $this->assertNull(AutomationEvent::find($event->id),
                    "{$event->event_key} still holds the erased customer's details.");
            }
            $this->assertSame(0, AutomationRun::count(), 'The run copied the event and outlived it.');

            foreach ($other as $event) {
                $kept = AutomationEvent::find($event->id);
                $this->assertNotNull($kept, "{$event->event_key} was not about the customer.");
                $this->assertEquals($event->payload, $kept->payload);   // MySQL reorders JSON keys
            }
            $this->assertStringContainsString($alpha['admin']->email, AutomationEvent::find($other[1]->id)->actor_label);
        });
    }

    /**
     * Where an employee WAS is about them. Field events carry a top-level
     * user_id beside a latitude and longitude; training results carry
     * after.user_id. A colleague's identical event is the control that the
     * match is on this person's id and not on the shape.
     */
    public function test_erasing_an_employee_drops_events_that_carry_their_id(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $employee = $this->userWithRole($alpha, 'Tech', email: self::EMAIL);
        $colleague = $this->userWithRole($alpha, 'Tech 2', email: 'other@example.test');

        [$theirs, $colleagues] = $this->inTenant($alpha['org'], function () use ($alpha, $employee, $colleague) {
            $at = fn (User $u) => AutomationEvent::create([
                'organization_id' => $alpha['org']->id, 'event_key' => 'field.sos',
                'payload' => ['user_id' => $u->id, 'user_name' => $u->name,
                    'latitude' => 40.1, 'longitude' => -74.2, 'map_url' => 'https://maps.example/x'],
                'occurred_at' => now(), 'available_at' => now(),
            ]);
            $failed = fn (User $u) => app(EventEmitter::class)->emit('training.failed', null,
                ['after' => ['test_id' => 't', 'user_id' => $u->id, 'score' => 40, 'passed' => false]]);

            return [[$at($employee), $failed($employee)], [$at($colleague), $failed($colleague)]];
        });

        app(Anonymiser::class)->purge($employee);

        $this->inTenant($alpha['org'], function () use ($theirs, $colleagues) {
            foreach ($theirs as $event) {
                $this->assertNull(AutomationEvent::find($event->id), "{$event->event_key} still says where they were.");
            }
            foreach ($colleagues as $event) {
                $this->assertNotNull(AutomationEvent::find($event->id), "{$event->event_key} was a colleague's.");
            }
        });
    }

    public function test_erasing_an_employee_drops_their_invitation_and_only_blanks_their_name_elsewhere(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $employee = $this->userWithRole($alpha, 'Tech', email: self::EMAIL);

        [$invited, $acted] = $this->inTenant($alpha['org'], function () use ($alpha, $employee) {
            $events = app(EventEmitter::class);

            return [
                $events->emit('invitation.sent', null, ['after' => [
                    'status' => 'pending', 'email' => self::EMAIL, 'role_id' => 'r', 'role' => 'Tech',
                    'scope_type' => 'franchise', 'scope_id' => $alpha['franchise']->id,
                    'scope' => 'Alpha F1', 'expires_at' => now()->toIso8601String(),
                ]], actor: $alpha['admin']),
                // The employee raised it; it is about the company, and the rules
                // that ran on it are the organization's history.
                $events->emit('company.created', $alpha['company'],
                    ['after' => ['name' => 'Alpha Co']], actor: $employee),
            ];
        });

        app(Anonymiser::class)->purge($employee);

        $this->inTenant($alpha['org'], function () use ($invited, $acted) {
            $this->assertNull(AutomationEvent::find($invited->id), 'The invitation event still holds their email.');

            $kept = AutomationEvent::find($acted->id);
            $this->assertNotNull($kept, 'An event they only triggered was deleted.');
            $this->assertSame('Former staff', $kept->actor_label);
            $this->assertSame(['after' => ['name' => 'Alpha Co']], $kept->payload);
        });
    }

    /** The payload the status webhook writes, from the controller itself. */
    private function callPayload(string $from): array
    {
        $call = (new PhoneCall)->forceFill([
            'id' => (string) str()->uuid(), 'twilio_sid' => 'CA1', 'direction' => 'inbound',
            'from_e164' => $from, 'to_e164' => '+15550100000', 'status' => 'no-answer',
            'duration_seconds' => null, 'number_id' => null,
        ]);

        return (new ReflectionMethod(StatusWebhookController::class, 'payload'))
            ->invoke(app(StatusWebhookController::class), $call);
    }

    private function aRunFor(array $alpha, AutomationEvent $event): void
    {
        $automation = Automation::create([
            'organization_id' => $alpha['org']->id,
            'name' => 'Text back', 'trigger_key' => $event->event_key, 'is_active' => true,
        ]);
        $version = AutomationVersion::create([
            'organization_id' => $alpha['org']->id, 'automation_id' => $automation->getKey(),
            'version' => 1, 'trigger_key' => $event->event_key, 'conditions' => [], 'actions' => [],
        ]);
        AutomationRun::create([
            'organization_id' => $alpha['org']->id, 'automation_id' => $automation->getKey(),
            'automation_version_id' => $version->getKey(), 'event_id' => $event->getKey(),
            'status' => AutomationRun::SUCCEEDED, 'action_count' => 1, 'started_at' => now(),
            'condition_trace' => [['field' => 'from_e164', 'actual' => self::PHONE]],
        ]);
    }
}
