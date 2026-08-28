<?php

namespace Tests\Feature\Phone;

use App\Models\PhoneConnection;
use App\Support\Automations\EventEmitter;
use App\Support\Phone\PhoneLineHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The broken mark and its outbox row commit together, or neither does.
 *
 * ── Why this path and not a success path ─────────────────────
 *
 * The row reportBroken() writes says the phone line is DOWN, and the event it
 * emits is the only way anybody finds out. Written separately, an outbox
 * insert that threw left the line durably broken with nobody told — and a
 * vanishing "the phone is down" cannot be told apart from the phone being
 * fine. It is the same shape as the Reviews and Services outbox defects.
 *
 * It is also the worst place for it: this runs on gateway-failure paths, so it
 * fires exactly when the database write is least likely to succeed.
 *
 * ── The double is asserted to be in play ─────────────────────
 *
 * The Reviews version of this test once mocked a class that did not exist. The
 * mock never bound, the real path failed earlier for an unrelated reason, and
 * the assertion passed against THAT — green, and checking nothing it claimed
 * to. So this resolves the collaborator back out of the container and asserts
 * it is the throwing one BEFORE relying on it.
 */
class PhoneLineHealthAtomicityTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_failed_alert_rolls_back_the_broken_mark(): void
    {
        $t = $this->provisionTenant();

        $this->app->bind(EventEmitter::class, fn () => new class extends EventEmitter
        {
            public function __construct() {}

            public function emit(
                string $eventKey,
                ?\Illuminate\Database\Eloquent\Model $subject = null,
                array $payload = [],
                ?\App\Models\User $actor = null,
                ?\App\Models\AutomationEvent $causedBy = null,
                ?\DateTimeInterface $availableAt = null,
            ): \App\Models\AutomationEvent {
                throw new RuntimeException('the outbox is unavailable');
            }
        });

        $health = $this->app->make(PhoneLineHealth::class);

        // Prove the double is actually the one in play before trusting it.
        $emitter = $this->app->make(EventEmitter::class);
        $this->assertNotSame(
            EventEmitter::class,
            $emitter::class,
            'the throwing emitter did not bind, so this test would prove nothing',
        );

        $connection = $this->inTenant($t['org'], fn () => PhoneConnection::factory()->create([
            'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
            'scope_id' => $t['org']->id,
        ]));

        $this->assertNotSame(PhoneConnection::STATUS_BROKEN, $connection->status);

        $this->inTenant($t['org'], function () use ($health, $connection) {
            try {
                $health->reportBroken($connection, 'The Twilio account is suspended.');
                $this->fail('the emit was supposed to throw');
            } catch (RuntimeException $e) {
                $this->assertSame('the outbox is unavailable', $e->getMessage());
            }

            // The mark must not survive an alert nobody received.
            $this->assertNotSame(
                PhoneConnection::STATUS_BROKEN,
                PhoneConnection::query()->find($connection->id)->status,
                'the line was left durably broken with no event to say so',
            );
        });
    }
}
