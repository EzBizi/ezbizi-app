<?php

namespace App\Support\Dashboard\Widgets\Hr;

use App\Models\Onboarding;
use App\Models\OnboardingAnswer;
use App\Models\PersonCertification;
use App\Models\User;
use App\Support\Contracts\FieldDefinition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Dates marked as an expiry on a contract, coming due (TODO #26).
 *
 * Until a template marks a date field with `is_expiry`, there is nothing to
 * count. The panel says so rather than guessing that every date is a licence.
 */
class ExpiringCertifications
{
    use ScopesToPlaces;

    public function resolve(User $user, array $options): array
    {
        $places = $this->placesInView($user);
        $days   = max(1, min((int) ($options['days'] ?? 60), 365));
        $until  = now()->addDays($days)->startOfDay();

        $onboardings = Onboarding::query()
            ->with(['contractVersion', 'user:id,name'])
            ->where(function (Builder $visible) use ($places) {
                $visible->whereHas(
                    'invitation',
                    fn (Builder $i) => $this->constrainToPlaces($i, $places),
                );

                if ($places['organization'] !== null) {
                    $visible->orWhereNull('invitation_id');
                }
            })
            ->get();

        $marked = 0;
        $rows   = [];

        foreach ($onboardings as $onboarding) {
            $fields = $onboarding->contractVersion?->fieldSet()->fields ?? [];

            foreach ($fields as $field) {
                if (! $field instanceof FieldDefinition || ! $field->isExpiry) {
                    continue;
                }

                $marked++;

                $answer = OnboardingAnswer::query()
                    ->where('onboarding_id', $onboarding->getKey())
                    ->where('field_key', $field->key)
                    ->first();

                if ($answer === null || $answer->value === null || $answer->value === '') {
                    continue;
                }

                try {
                    $expires = Carbon::parse($answer->value)->startOfDay();
                } catch (\Throwable) {
                    continue;
                }

                if ($expires->gt($until) || $expires->lt(now()->startOfDay()->subYear())) {
                    continue;
                }

                $rows[] = [
                    'title'      => $onboarding->user?->name ?? 'Somebody',
                    'field'      => $field->label,
                    'expires_on' => $expires->toDateString(),
                ];
            }
        }

        // Certifications recorded on the person (Jose, 2026-11-20), in the same view.
        $held = PersonCertification::query()->with('user:id,name')
            ->whereHas('user', fn (Builder $u) => $u->where(function (Builder $visible) use ($places) {
                $visible->whereHas('assignments', fn (Builder $a) => $this->constrainToPlaces($a, $places));
                if ($places['organization'] !== null) {
                    $visible->orWhereDoesntHave('assignments');
                }
            }))
            ->whereNotNull('expires_on')->whereDate('expires_on', '<=', $until)
            ->whereDate('expires_on', '>=', now()->startOfDay()->subYear())->get();
        $marked += $held->count();
        foreach ($held as $c) {
            $rows[] = [
                'title'      => $c->user?->name ?? 'Somebody',
                'field'      => $c->name,
                'expires_on' => $c->expires_on->toDateString(),
            ];
        }

        usort($rows, fn (array $a, array $b) => strcmp($a['expires_on'], $b['expires_on']));

        // `$marked` is counted over the onboardings THIS READER CAN SEE, so the
        // old wording — "None of the contract questions are marked as an
        // expiry" — claimed the organization's templates from a view-scoped
        // number. A franchise manager whose visible onboardings happen to use a
        // template without an expiry field was told none exists anywhere, and a
        // reader with no onboardings at all got the same sentence about
        // questions they simply cannot see. Same class as UI/UX's sixteen
        // (2026-09-09); this one is a widget note rather than an empty state,
        // which is why the sweep did not catch it. The remedy stays, because
        // whoever can act on it is reading the same box.
        $note = $marked === 0
            ? 'No contract question in this view is marked as an expiry, so there is nothing to track. A date field on a contract template can be marked as one.'
            : null;

        return [
            'rows'  => array_slice($rows, 0, 12),
            'total' => count($rows),
            // Already past, of the total: the rest are still ahead.
            'lapsed' => count(array_filter($rows, fn (array $r) => $r['expires_on'] < now()->toDateString())),
            'note'  => $note,
            // The window this answer was computed over, so a sentence naming it
            // cannot drift from the query it describes. `days` is an option, so
            // a caller that widens it and a sentence that says "60" would
            // disagree silently — and the sentence is the half people act on.
            'days'  => $days,
        ];
    }
}
