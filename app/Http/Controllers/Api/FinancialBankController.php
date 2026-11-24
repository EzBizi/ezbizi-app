<?php

namespace App\Http\Controllers\Api;

use App\Jobs\RecheckPlaidItem;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\SocialConnection;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Financials\Plaid\PlaidClient;
use App\Support\Scoping\ViewScope;
use App\Support\Social\PlaidOAuth;
use App\Support\Social\SocialConnectionAccess;
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
        private readonly SocialConnectionAccess $access,
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
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = BankTransaction::query()->with('account:id,name,mask')
            ->whereIn('social_connection_id', $this->banks($request->user())->modelKeys())
            ->when($input['account_id'] ?? null, fn ($q, $id) => $q->where('bank_account_id', $id))
            ->when($input['from'] ?? null, fn ($q, $d) => $q->where('date', '>=', $d))
            ->when($input['to'] ?? null, fn ($q, $d) => $q->where('date', '<=', $d))
            ->when(($input['direction'] ?? null) === 'out', fn ($q) => $q->where('amount_cents', '>', 0))
            ->when(($input['direction'] ?? null) === 'in', fn ($q) => $q->where('amount_cents', '<', 0))
            ->when(trim((string) ($input['search'] ?? '')), function ($q, $s) {
                $like = '%'.addcslashes($s, '%_\\').'%';
                $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('merchant_name', 'like', $like)
                    ->orWhere('category', 'like', $like)->orWhere('category_detailed', 'like', $like));
            })
            ->orderByDesc('date')->orderByDesc('created_at');

        $page = $query->paginate(100);

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
            ])->values(),
            'meta' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
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

    /** The Plaid connections this view sees (see the class comment). */
    private function banks(User $user): \Illuminate\Database\Eloquent\Collection
    {
        $view = ViewScope::forUser($user);

        return SocialConnection::query()->where('provider', SocialProvider::Plaid->value)->orderBy('display_name')->get()
            ->filter(fn (SocialConnection $c) => self::sees($view, $c) && $this->access->reaches($user, $c))
            ->values();
    }

    public static function sees(ViewScope $view, SocialConnection $c): bool
    {
        return match ($c->scope_type) {
            SocialConnection::SCOPE_ORGANIZATION => $view->isOrganization(),
            SocialConnection::SCOPE_COMPANY => $view->isOrganization() ? true : $view->type === ViewScope::COMPANY && in_array($c->scope_id, $view->companyIds(), true),
            SocialConnection::SCOPE_FRANCHISE => in_array($c->scope_id, $view->franchiseIds(), true),
            default => false,
        };
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
