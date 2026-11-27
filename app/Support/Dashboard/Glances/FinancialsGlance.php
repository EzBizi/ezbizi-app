<?php

namespace App\Support\Dashboard\Glances;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\SocialConnection;
use App\Models\User;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\DescribedGlance;
use App\Support\Dashboard\GlancePayload;
use App\Support\Financials\Plaid\BankView;
use App\Support\Tenancy\TenantContext;

/**
 * Financials on the dashboard: the banks this view connected through Plaid
 * (BankView, the same rule as the Bank tab). Cash in bank accounts, owed on
 * cards, and the money that came in during the period that nothing in EzBizi
 * accounts for yet (BankMatcher). Without the Bank tab, or with no bank
 * connected, it keeps the sentence the placeholder said.
 */
final class FinancialsGlance extends DescribedGlance
{
    protected const KEY = 'financials';

    protected const LABEL = 'Financials';

    protected const PERMISSION = 'financials.view';

    protected const PRESENTATIONS = ['text'];

    protected const DEFAULT_PRESENTATION = 'text';

    protected const SNAPSHOT = true;

    public const PLACEHOLDER = 'Your bases, tables and records live in the Financials tab. Open it to work in them.';

    public function resolve(User $user, DashboardPeriod $period, array $options): GlancePayload
    {
        return app(TenantContext::class)->run($user->organization, function () use ($user, $period) {
            $banks = $user->hasPermission('financials.bank.view') ? app(BankView::class)->banks($user) : collect();
            if ($banks->isEmpty()) {
                return $this->payload(['empty' => true, 'sentence' => self::PLACEHOLDER]);
            }

            $accounts = BankAccount::query()->whereIn('social_connection_id', $banks->modelKeys())->get();
            $cash = (int) $accounts->where('type', 'depository')->sum('current_cents');
            $cards = $accounts->where('type', 'credit');
            $owed = (int) $cards->sum('current_cents');
            $in = BankTransaction::query()->whereIn('social_connection_id', $banks->modelKeys())
                ->where('amount_cents', '<', 0)->where('pending', false)
                ->whereBetween('date', [$period->startsOn->toDateString(), $period->endsOn->toDateString()]);
            $deposits = (clone $in)->count();
            $unmatched = (clone $in)->whereNull('matched_type')->count();
            $broken = $banks->where('status', '!=', SocialConnection::STATUS_CONNECTED)->count();

            return $this->payload([
                'empty' => false,
                'sentences' => array_values(array_filter([
                    self::money($cash).' in '.self::count($accounts->where('type', 'depository')->count(), 'bank account').'.',
                    $cards->isNotEmpty() ? self::money($owed).' owed on '.self::count($cards->count(), 'card').'.' : null,
                    $deposits === 0 ? 'No money came in '.$period->phrase().'.'
                        : ($unmatched === 0 ? 'Every deposit '.$period->phrase().' is matched to a payment or invoice.'
                            : "{$unmatched} of ".self::count($deposits, 'deposit').' '.$period->phrase().' not matched to a payment or invoice.'),
                ])),
                'figures' => [
                    GlancePayload::figure('cash_cents', 'in bank accounts (cents)', $cash),
                    GlancePayload::figure('unmatched', 'deposits not matched', $unmatched),
                ],
                'attention' => $broken > 0
                    ? [GlancePayload::attention('caution', $broken === 1 ? '1 bank needs reconnecting' : "{$broken} banks need reconnecting")]
                    : [],
            ]);
        });
    }

    private static function money(int $cents): string
    {
        return ($cents < 0 ? '-$' : '$').number_format(abs($cents) / 100, 2);
    }

    private static function count(int $n, string $noun): string
    {
        return $n.' '.$noun.($n === 1 ? '' : 's');
    }
}
