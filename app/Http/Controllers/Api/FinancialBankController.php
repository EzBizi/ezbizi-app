<?php

namespace App\Http\Controllers\Api;

use App\Jobs\RecheckPlaidItem;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\SocialConnection;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Financials\Plaid\BankMatcher;
use App\Support\Financials\Plaid\BankView;
use App\Support\Financials\Plaid\PlaidClient;
use App\Support\Social\PlaidOAuth;
use App\Support\Social\SocialOAuthRefused;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Console\QueuedCommand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Financials › Bank (Jose, 2026-11-24): the banks this view connected through
 * Plaid, their accounts and balances, and their transactions, searchable.
 * Reading is the tab (financials.bank.view, route middleware). Connecting,
 * reconnecting and refreshing are financials.edit, as connecting QuickBooks is
 * (SocialProvider::connectPermission); connecting itself goes through
 * /social/plaid/start like every other provider.
 *
 * WHICH BANKS A VIEW SEES is stricter than social pages: a bank is money, so
 * it is seen only from the place it was connected or above it. The
 * organization view sees every bank; a company view sees its own and its
 * branches'; a branch view sees its own. An organization's bank does not
 * appear inside a company or a branch.
 */
class FinancialBankController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly BankView $view,
        private readonly BankMatcher $matcher,
    ) {}

    /** Every bank in view, its accounts with balances, and the totals. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $banks = $this->banks($user);
        $accounts = BankAccount::query()->whereIn('social_connection_id', $banks->modelKeys())
            ->orderBy('type')->orderBy('name')->get()->groupBy('social_connection_id');

        return response()->json(['data' => [
            'ready' => PlaidClient::ready(),
            'can_connect' => $user->hasPermission(SocialProvider::Plaid->connectPermission()),
            'banks' => $banks->map(fn (SocialConnection $c) => [
                'id' => $c->getKey(),
                'name' => $c->display_name,
                'status' => $c->status,
                'status_reason' => $c->status === SocialConnection::STATUS_CONNECTED ? null : $c->status_reason,
                'synced_at' => $c->setup['plaid']['synced_at'] ?? null,
                // NOT_READY / INITIAL_UPDATE_COMPLETE while Plaid is still reading history.
                'history' => $c->setup['plaid']['update_status'] ?? null,
                'accounts' => ($accounts[$c->getKey()] ?? collect())->map(fn (BankAccount $a) => [
                    'id' => $a->getKey(),
                    'name' => $a->name,
                    'official_name' => $a->official_name,
                    'mask' => $a->mask,
                    'type' => $a->type,
                    'subtype' => $a->subtype,
                    'current_cents' => $a->current_cents,
                    'available_cents' => $a->available_cents,
                    'limit_cents' => $a->limit_cents,
                    'currency' => $a->currency,
                    'balances_at' => $a->balances_at?->toIso8601String(),
                ])->values(),
            ])->values(),
        ]]);
    }

    /** Transactions in view, newest first: search, account, dates, in/out. 100 a page. */
    public function transactions(Request $request): JsonResponse
    {
        $input = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'account_id' => ['nullable', 'uuid'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'direction' => ['nullable', 'in:in,out'],
            'unmatched' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = BankTransaction::query()->with('account:id,name,mask')
            ->whereIn('social_connection_id', $this->banks($request->user())->modelKeys())
            ->when($input['account_id'] ?? null, fn ($q, $id) => $q->where('bank_account_id', $id))
            ->when($input['from'] ?? null, fn ($q, $d) => $q->where('date', '>=', $d))
            ->when($input['to'] ?? null, fn ($q, $d) => $q->where('date', '<=', $d))
            ->when(($input['direction'] ?? null) === 'out', fn ($q) => $q->where('amount_cents', '>', 0))
            ->when(($input['direction'] ?? null) === 'in', fn ($q) => $q->where('amount_cents', '<', 0))
            // Money in that nothing in EzBizi accounts for yet.
            ->when($request->boolean('unmatched'), fn ($q) => $q->where('amount_cents', '<', 0)->whereNull('matched_type'))
            ->when(trim((string) ($input['search'] ?? '')), function ($q, $s) {
                $like = '%'.addcslashes($s, '%_\\').'%';
                $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('merchant_name', 'like', $like)
                    ->orWhere('category', 'like', $like)->orWhere('category_detailed', 'like', $like));
            })
            ->orderByDesc('date')->orderByDesc('created_at');

        $page = $query->paginate(100);
        $matches = BankMatcher::describe($page->items());

        return response()->json([
            'data' => collect($page->items())->map(fn (BankTransaction $t) => [
                'id' => $t->getKey(),
                'date' => $t->date?->toDateString(),
                'name' => $t->name,
                'merchant_name' => $t->merchant_name,
                'amount_cents' => $t->amount_cents,
                'currency' => $t->currency,
                'category' => $t->category,
                'category_detailed' => $t->category_detailed,
                'pending' => $t->pending,
                'account' => $t->account ? ['id' => $t->account->getKey(), 'name' => $t->account->name, 'mask' => $t->account->mask] : null,
                'match' => $matches[(string) $t->getKey()] ?? null,
            ])->values(),
            'meta' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /** What this money in could be: recorded payments and open invoices in the bank's own place (BankMatcher). */
    public function candidates(Request $request, BankTransaction $transaction): JsonResponse
    {
        $this->visible($request, $transaction);

        return response()->json(['data' => [
            'match' => BankMatcher::describe([$transaction])[(string) $transaction->getKey()] ?? null,
            'candidates' => $this->matcher->candidates($transaction),
        ]]);
    }

    /** A person says what this money in was for. Only a current candidate is accepted. */
    public function match(Request $request, BankTransaction $transaction): JsonResponse
    {
        $user = $this->changer($request, $transaction);
        $input = $request->validate(['type' => ['required', 'in:'.implode(',', BankMatcher::TYPES)], 'id' => ['required', 'uuid']]);
        abort_unless($this->matcher->offers($transaction, $input['type'], $input['id']), Response::HTTP_UNPROCESSABLE_ENTITY,
            'That is not one of the payments or invoices this deposit could be. Reload and choose again.');
        $transaction->forceFill(['matched_type' => $input['type'], 'matched_id' => $input['id'], 'matched_by' => 'person', 'matched_at' => now()])->save();
        $this->audit->log('financials.bank.matched', $transaction, ['type' => $input['type'], 'id' => $input['id']], actor: $user);

        return response()->json(['data' => ['match' => BankMatcher::describe([$transaction])[(string) $transaction->getKey()] ?? null]]);
    }

    /** Undo a match, a person's or the sync's. */
    public function unmatch(Request $request, BankTransaction $transaction): JsonResponse
    {
        $user = $this->changer($request, $transaction);
        $was = ['type' => $transaction->matched_type, 'id' => $transaction->matched_id, 'by' => $transaction->matched_by];
        $transaction->forceFill(['matched_type' => null, 'matched_id' => null, 'matched_by' => null, 'matched_at' => null])->save();
        $this->audit->log('financials.bank.unmatched', $transaction, $was, actor: $user);

        return response()->json(['data' => ['match' => null]]);
    }

    /**
     * Update mode: the bank wants the person to sign in again. A Hosted Link
     * page for THIS bank login (the access_token stays the same); Plaid
     * returns to /social/plaid/relinked, which re-checks with Plaid.
     */
    public function reconnect(Request $request, SocialConnection $connection, PlaidOAuth $plaid): JsonResponse
    {
        $user = $this->editable($request, $connection);
        $state = Str::random(64);
        try {
            $link = $plaid->linkToken(['access_token' => (string) ($connection->credentials['access_token'] ?? '')], $state, PlaidOAuth::RELINKED_PATH);
        } catch (SocialOAuthRefused $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }
        Cache::put(PlaidOAuth::cacheKey('relink:'.$state), (string) $connection->getKey(), now()->addMinutes(30));
        $this->audit->log('financials.bank.reconnect_started', $connection, ['bank' => $connection->display_name], actor: $user);

        return response()->json(['data' => ['authorization_url' => $link['hosted_link_url']]]);
    }

    /** Read this bank now. */
    public function refresh(Request $request, SocialConnection $connection): JsonResponse
    {
        $this->editable($request, $connection);
        QueuedCommand::dispatch(['ezbizi:plaid-sync', ['--connection' => (string) $connection->getKey()]]);

        return response()->json(['data' => ['queued' => true]], Response::HTTP_ACCEPTED);
    }

    /** The Plaid connections this view sees (BankView). */
    private function banks(User $user): \Illuminate\Database\Eloquent\Collection
    {
        return $this->view->banks($user);
    }

    private function visible(Request $request, BankTransaction $transaction): void
    {
        abort_unless($this->banks($request->user())->contains(fn ($c) => (string) $c->getKey() === (string) $transaction->social_connection_id), Response::HTTP_NOT_FOUND);
    }

    private function changer(Request $request, BankTransaction $transaction): User
    {
        $this->visible($request, $transaction);
        $user = $request->user();
        abort_unless($user->hasPermission('financials.edit'), Response::HTTP_FORBIDDEN, 'Your role cannot match bank transactions.');

        return $user;
    }

    private function editable(Request $request, SocialConnection $connection): User
    {
        $user = $request->user();
        abort_unless($connection->provider === SocialProvider::Plaid->value, Response::HTTP_NOT_FOUND);
        abort_unless($this->banks($user)->contains(fn ($c) => $c->is($connection)), Response::HTTP_NOT_FOUND);
        abort_unless($user->hasPermission(SocialProvider::Plaid->connectPermission()), Response::HTTP_FORBIDDEN, 'Your role cannot change bank connections.');

        return $user;
    }
}
