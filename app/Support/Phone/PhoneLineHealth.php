<?php

namespace App\Support\Phone;

use App\Models\PhoneConnection;
use App\Models\PhoneNumber;
use App\Support\Automations\EventEmitter;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Throwable;
use Twilio\Exceptions\RestException;

/**
 * Marks a phone connection broken and raises phone.number.broken once.
 *
 * Same transition rule as mailboxes: a line that has been broken since Tuesday
 * is refused every retry, and emitting there would spam the outbox. Only the
 * move into broken fires the event. Recipient and transient Twilio faults do
 * not count as a broken line.
 */
final class PhoneLineHealth
{
    /**
     * Account / credential / From-number faults. Not invalid To, unsubscribed,
     * rate limits, or generic throws without an account/From signal.
     *
     * @var list<string>
     */
    private const LINE_BREAKING_CODES = [
        '20003', // auth
        '20404', // unknown resource on this account (status webhook path)
        '21212', // invalid From
        '21606', // From not owned / not SMS-capable on this account
    ];

    public function __construct(
        private readonly EventEmitter $events,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * Whether this Twilio fault means the org's line stopped working.
     *
     * Pass a webhook ErrorCode and/or the throwable from sendMessage. Codes
     * 21211 / 21214 (bad or unreachable To) and plain RuntimeExceptions
     * without an account/From signal return false.
     */
    public static function isLineBreakingFault(?string $errorCode = null, ?Throwable $throwable = null): bool
    {
        $code = $errorCode !== null && $errorCode !== ''
            ? $errorCode
            : null;

        if (($code === null || $code === '0') && $throwable instanceof RestException) {
            $code = (string) $throwable->getCode();
        }

        if ($code !== null && $code !== '' && $code !== '0'
            && in_array($code, self::LINE_BREAKING_CODES, true)) {
            return true;
        }

        $message = $throwable?->getMessage() ?? '';

        return $message !== '' && self::messageNamesAccountFault($message);
    }

    public function reportBroken(
        PhoneConnection $connection,
        string $reason,
        ?PhoneNumber $number = null,
    ): void {
        $wasAlreadyBroken = $connection->status === PhoneConnection::STATUS_BROKEN;
        $announce = ! $wasAlreadyBroken && $this->tenant->has();
        $subject = $number ?? $connection;

        // ── Why the mark and the alert are one transaction ───────────
        //
        // The row it writes is a FAILURE and the event it emits is how anybody
        // finds out. Saved separately, an outbox insert that threw left the
        // line durably broken with nobody told — and a vanishing "the phone is
        // down" is indistinguishable from the phone being fine, which is the
        // worst way for an alert to fail.
        //
        // This runs on gateway-failure paths, so it fires exactly when the
        // write is least likely to succeed. Same shape as the Reviews and
        // Services outbox fixes.
        DB::transaction(function () use ($connection, $reason, $announce, $subject, $number) {
            $connection->markBroken($reason);
            $connection->save();

            if (! $announce) {
                return;
            }

            $this->events->emit('phone.number.broken', $subject, [
                'e164' => $number?->e164,
                'connection_id' => $connection->id,
                'number_id' => $number?->id,
                'reason' => $reason,
                'cause' => 'gateway_failure',
            ]);
        });
    }

    private static function messageNamesAccountFault(string $message): bool
    {
        $lower = strtolower($message);

        // Fake / gateway path: "The Twilio account for this line is suspended."
        return str_contains($lower, 'account') && str_contains($lower, 'suspended');
    }
}
