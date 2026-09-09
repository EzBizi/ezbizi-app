<?php

namespace App\Support\Dashboard\Widgets\Hr;

use App\Models\Onboarding;
use App\Models\OnboardingAnswer;
use App\Models\User;
use App\Support\Contracts\FieldDefinition;
use App\Support\Dashboard\Widget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Dates marked as an expiry on a contract, coming due (TODO #26).
 *
 * Until a template marks a date field with `is_expiry`, there is nothing to
 * count. The panel says so rather than guessing that every date is a licence.
 */
class ExpiringCertifications implements Widget
{
    use ScopesToPlaces;

    public const KEY = 'hr.certifications';

    public function key(): string { return self::KEY; }

    public function label(): string { return 'Expiring certifications'; }

    public function permission(): string { return 'hr.view'; }

    public function options(User $user): array
    {
        return ['days' => $this->periodOption(60)];
    }

    public function resolve(User $user, array $options): array
    {
        $places = $this->placesInView($user);
        $until  = now()->addDays(max(1, min((int) ($options['days'] ?? 60), 365)))->startOfDay();

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
            'note'  => $note,
        ];
    }
}
