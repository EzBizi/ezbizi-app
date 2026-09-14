<?php

namespace App\Support\Reports;

/**
 * What happened when a rule mailed a report.
 *
 * ── The vocabulary stops at HANDOFF, deliberately ───────────────
 *
 * `sent` means handed to the transport, not arrived. EzBizi ingests no SES
 * events — no SNS route, no bounce handler, no complaint handler — so
 * delivered, bounced and opened are not facts this application holds about any
 * message it sends. Measured by the Automations session and confirmed
 * independently by UI/UX, 2026-09-14.
 *
 * That is why nothing here is called `delivered`. The failure to avoid is
 * "reported success, delivered nothing", and the way to honour it is not to
 * invent a status we cannot observe — it is never to print a word that claims
 * arrival. If SES event ingestion is ever built, this reopens, and it should
 * reopen visibly: a new state on a screen, not a word quietly becoming true.
 */
final readonly class SentReport
{
    public function __construct(
        /** @var list<string> addresses the transport accepted */
        public array $sent,
        /** @var array<string, string> address => why it was refused */
        public array $refused,
        public int $rowCount,
        public bool $capped,
        /**
         * False when the configured transport goes nowhere — `log`, `array`,
         * `null`, or `ses` with no key. On such a box the handoff succeeds and
         * nothing leaves, so "Sent" is a lie of a different kind. Unlike a
         * bounce, this one IS observable, so it is observed.
         */
        public bool $transportDelivers,
    ) {}

    /**
     * Did anything leave the building?
     *
     * The Automations ruling: a rule mailing five people where ONE address is
     * malformed reports ok and names the refused one. Failing the whole action
     * for one bad address in five is how you auto-disable a rule that is
     * working for four people.
     */
    public function ok(): bool
    {
        return $this->sent !== [];
    }

    /** One line for the run record, naming what was refused. */
    public function summary(): string
    {
        $sent = count($this->sent) === 1
            ? 'Sent to 1 address'
            : 'Sent to '.count($this->sent).' addresses';

        if (! $this->transportDelivers) {
            $sent = 'Handed over, but this deployment\'s mail transport does not deliver';
        }

        if ($this->refused === []) {
            return $sent.'.';
        }

        $refused = [];

        foreach ($this->refused as $address => $why) {
            $refused[] = $address.' ('.$why.')';
        }

        return $sent.'. Refused: '.implode(', ', $refused).'.';
    }
}
