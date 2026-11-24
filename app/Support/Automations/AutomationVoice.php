<?php

namespace App\Support\Automations;

use Illuminate\Database\Eloquent\Model;

/**
 * Who a rule's alerts, task updates and task changes say they are from:
 * "EzBizi AI · <rule>", never the rule's creator (Adeline, suggestion
 * 01a20f52, 2026-11-24). The creator stays the actor (user_id/sender_id) for
 * permissions and audit; only the shown name changes. Models with a
 * sender_label column fill it while a step runs (see boot in each).
 */
final class AutomationVoice
{
    private static ?string $label = null;

    public static function speak(?Model $automation, callable $step): mixed
    {
        $previous = self::$label;
        self::$label = 'EzBizi AI · '.($automation?->getAttribute('name') ?: 'a rule');
        try {
            return $step();
        } finally {
            self::$label = $previous;
        }
    }

    public static function label(): ?string
    {
        return self::$label;
    }

    /** For a model's creating hook. */
    public static function stamp(Model $row): void
    {
        if (self::$label !== null && $row->getAttribute('sender_label') === null) {
            $row->setAttribute('sender_label', mb_substr(self::$label, 0, 120));
        }
    }
}
