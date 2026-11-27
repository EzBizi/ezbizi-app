<?php

namespace App\Support\Reports;

/**
 * Which report a message being sent belongs to (Jose, 2026-11-25: "we are
 * sending out a lot of reports on whatsapp, and yet they can't be seen on the
 * app"). A sender wraps its send in during(); WhatsAppCloud stamps the key on
 * the message row, and ReportEmail keeps an email sent inside it. Reports ›
 * Reports lists every key and opens each one's history.
 *
 * Keys: "automation:<id>" for a rule's step; "same-day", "period:week",
 * "workspace:<kind>", "requested" for the senders outside rules.
 */
final class ReportSource
{
    private static ?string $current = null;

    /**
     * @template T
     *
     * @param  callable(): T  $send
     * @return T
     */
    public static function during(?string $key, callable $send): mixed
    {
        if ($key === null) {
            return $send();
        }

        $outer = self::$current;
        self::$current = $key;

        try {
            return $send();
        } finally {
            self::$current = $outer;
        }
    }

    public static function current(): ?string
    {
        return self::$current;
    }
}
