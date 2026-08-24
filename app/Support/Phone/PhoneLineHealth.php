<?php

namespace App\Support\Phone;

use App\Models\PhoneConnection;
use App\Models\PhoneNumber;
use App\Support\Automations\EventEmitter;
use App\Support\Tenancy\TenantContext;

/**
 * Marks a phone connection broken and raises phone.number.broken once.
 *
 * Same transition rule as mailboxes: a line that has been broken since Tuesday
 * is refused every retry, and emitting there would spam the outbox. Only the
 * move into broken fires the event.
 */
final class PhoneLineHealth
{
    public function __construct(
        private readonly EventEmitter $events,
        private readonly TenantContext $tenant,
    ) {}

    public function reportBroken(
        PhoneConnection $connection,
        string $reason,
        ?PhoneNumber $number = null,
    ): void {
        $wasAlreadyBroken = $connection->status === PhoneConnection::STATUS_BROKEN;

        $connection->markBroken($reason);
        $connection->save();

        if ($wasAlreadyBroken || ! $this->tenant->has()) {
            return;
        }

        $subject = $number ?? $connection;

        $this->events->emit('phone.number.broken', $subject, [
            'e164' => $number?->e164,
            'connection_id' => $connection->id,
            'number_id' => $number?->id,
            'reason' => $reason,
            'cause' => 'gateway_failure',
        ]);
    }
}
