<?php

namespace App\Support\Reports\Tagging;

use App\Support\Reports\Experimental\RingCentralCallLog;

/**
 * The questions and labels Jose listed for the Performance report
 * (2026-10-05): why an existing client called, and why a new client did not
 * book. One place, so the two taggers ask exactly the same thing.
 */
final class CallLabels
{
    public const EXISTING = [
        'complaint' => 'Unhappy with work already done, a technician, or the service.',
        'reschedule' => 'Wants to move, change or cancel an appointment.',
        'eta' => 'Asking when the technician will arrive or whether they are on the way.',
        'billing' => 'About an invoice, a payment, a charge or a refund.',
        'other' => 'Anything else.',
    ];

    public const NOT_BOOKED = [
        'price' => 'Did not book because of the price: too expensive, or only shopping for quotes.',
        'availability' => 'Did not book because no appointment time suited them, or not soon enough.',
        'booked' => 'The caller did book an appointment on this call.',
        'other' => 'Did not book for another reason, or the reason is unclear.',
    ];

    /**
     * Which question a call gets, by the queue it came in on; null for a call
     * neither of Jose's lists is about.
     *
     * @return array{question: string, labels: array<string, string>}|null
     */
    public static function for(?string $queue): ?array
    {
        return match ($queue) {
            RingCentralCallLog::EXISTING_CLIENT_QUEUE => [
                'question' => 'This is a call from an existing customer of a chimney service company. Why did they call?',
                'labels' => self::EXISTING,
            ],
            RingCentralCallLog::NEW_CLIENT_QUEUE => [
                'question' => 'This is a call from a new customer to a chimney service company. Did they book, and if not, why not?',
                'labels' => self::NOT_BOOKED,
            ],
            default => null,
        };
    }

    /** The instruction both taggers send, built from the labels so they never drift. */
    public static function instruction(string $question, array $labels): string
    {
        $lines = [];

        foreach ($labels as $key => $meaning) {
            $lines[] = "- {$key}: {$meaning}";
        }

        return $question."\n\nAnswer with exactly one of these labels:\n".implode("\n", $lines)
            ."\n\nThe transcript is machine-made and may contain errors. Choose the label that best fits what the caller wanted.";
    }
}
