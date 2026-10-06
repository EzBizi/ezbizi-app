<?php

namespace App\Http\Controllers\Api;

use App\Models\Customer;
use App\Models\SocialConnection;
use App\Models\WorkizClient;
use App\Support\Social\SocialProvider;
use App\Support\Workiz\WorkizV2;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/customers/{customer}/workiz-notes
 *
 * The notes each branch's Workiz holds about this customer, read live (Jose,
 * 2026-10-06, via Customers). Free text about a person, so never copied into
 * EzBizi: every request asks Workiz, with the branch's own key. A branch that
 * cannot answer says why for its client, and the others still answer.
 */
class CustomerWorkizNotesController extends Controller
{
    public function __invoke(Customer $customer): JsonResponse
    {
        $this->authorize('view', $customer);

        $clients = WorkizClient::query()->where('customer_id', $customer->getKey())->with('franchise:id,name')->get();
        $connections = SocialConnection::query()->where('provider', SocialProvider::Workiz->value)
            ->whereIn('scope_id', $clients->pluck('franchise_id')->unique())->get()->keyBy('scope_id');

        return response()->json(['data' => $clients->map(function (WorkizClient $client) use ($connections) {
            $answer = [
                'client_id' => (int) $client->client_id,
                'franchise' => ['id' => $client->franchise_id, 'name' => $client->franchise?->name],
                'notes' => [],
            ];
            $connection = $connections->get($client->franchise_id);

            $error = match (true) {
                $connection === null => 'This branch has no Workiz connection.',
                ! WorkizV2::uses($connection) => 'This branch is not on its Workiz v2 key.',
                $client->v2_id === null => 'Not matched to Workiz yet.',
                default => null,
            };

            if ($error !== null) {
                return $answer + ['error' => $error];
            }

            $read = WorkizV2::send($connection, 'GET', '/clients/'.rawurlencode($client->v2_id).'/notes', ['page' => 1, 'pageSize' => 100], 10);

            // Only Workiz's own refusal or silence can change on a second try;
            // the reasons above cannot (UI/UX, 2026-10-06).
            if ($read['refused'] !== null) {
                return $answer + ['error' => $read['refused'], 'retry' => true];
            }

            $answer['notes'] = collect($read['data']['data'] ?? [])->filter(fn ($note) => is_array($note))
                ->map(fn (array $note) => ['text' => (string) ($note['note'] ?? ''), 'created_at' => $note['createdAt'] ?? null, 'author' => $note['techName'] ?? null])
                ->sortByDesc('created_at')->values()->all();

            return $answer;
        })->values()]);
    }
}
