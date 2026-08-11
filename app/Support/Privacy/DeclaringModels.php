<?php

namespace App\Support\Privacy;

use Illuminate\Database\Eloquent\Model;

/**
 * The models that declare themselves, found by looking rather than by list.
 *
 * ── Why this is its own class ────────────────────────────────────
 *
 * DataInventory already walked app/Models looking for implementors, and its
 * docblock says exactly why it walks rather than consults a list. The purge and
 * the retention sweep need the same walk, and copying it would produce three
 * loops with one job — the failure DataInventory's own sibling
 * (ModuleRegistry::findTrigger) records: "two loops with the same job eventually
 * disagree by one condition, and the one that differs is the one that lets"
 * something through.
 *
 * The condition that would have differed here is not hypothetical. The inventory
 * asks for HoldsPersonalData; the sweep asks for StatesRetention, which is a
 * strictly larger set because tables that merely grow implement it alone. Two
 * hand-written globs would have looked identical and answered different
 * questions.
 *
 * ── Memoised per process, not cached ─────────────────────────────
 *
 * A directory read and a class check. It runs once when somebody opens a
 * compliance answer, once a night in the sweep, and once per purge — none of
 * which is a hot path, and all of which would rather be right than fast. A
 * warmed cache is a place for a stale answer to hide, which is the one thing
 * this must not have.
 */
class DeclaringModels
{
    /** @var array<class-string, class-string[]> */
    private array $memo = [];

    /** @return array<class-string<Model&HoldsPersonalData>> */
    public function holdingPersonalData(): array
    {
        return $this->implementing(HoldsPersonalData::class);
    }

    /**
     * Every model with a retention policy — which INCLUDES every model holding
     * personal data, because HoldsPersonalData extends StatesRetention.
     *
     * @return array<class-string<Model&StatesRetention>>
     */
    public function statingRetention(): array
    {
        return $this->implementing(StatesRetention::class);
    }

    /**
     * Every model holding evidence that somebody consented to something.
     *
     * One implementor today, and that is the honest answer rather than a gap —
     * see RecordsConsent, which argues why padding the list would be worse than
     * a list of one.
     *
     * @return array<class-string<Model&RecordsConsent>>
     */
    public function recordingConsent(): array
    {
        return $this->implementing(RecordsConsent::class);
    }

    /** @return array<class-string> */
    private function implementing(string $interface): array
    {
        if (isset($this->memo[$interface])) {
            return $this->memo[$interface];
        }

        $found = [];

        foreach (glob(app_path('Models').'/*.php') ?: [] as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');

            if (! class_exists($class)) {
                continue;
            }

            if (is_subclass_of($class, $interface) && is_subclass_of($class, Model::class)) {
                $found[] = $class;
            }
        }

        sort($found);

        return $this->memo[$interface] = $found;
    }
}
