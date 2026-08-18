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

        $note = $marked === 0
            ? 'None of the contract questions are marked as an expiry, so there is nothing to list yet. Mark a date field as an expiry on a contract template.'
            : null;

        return [
            'rows'  => array_slice($rows, 0, 12),
            'total' => count($rows),
            'note'  => $note,
        ];
    }
}
