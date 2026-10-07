<?php

namespace App\Http\Controllers\Api;

use App\Models\Customer;
use App\Models\Job;
use App\Models\SocialConnection;
use App\Models\WorkizClient;
use App\Models\WorkizJob;
use App\Support\Scoping\ViewScope;
use App\Support\Social\SocialProvider;
use App\Support\Workiz\WorkizV2;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/customers/{customer}/workiz-notes
 *
 * The notes staff leave on this customer's Workiz jobs, per Workiz client
 * (Customers, 2026-10-07). A-1 keeps its notes on jobs: client notes were
 * empty on every one of 213 clients read, and CT job 200022994 holds one.
 * They are stored with each mirrored job, beside its description, whenever
 * the sync reads it, so the page asks Workiz nothing. Only v2 carries them,
 * and a branch still on v1 says so. They are job content, so a reader sees
 * only the branches they can open jobs in, as the customer's money does.
 */
class CustomerWorkizNotesController extends Controller
{
    public function __invoke(Request $request, Customer $customer): JsonResponse
    {
        $this->authorize('view', $customer);

        $clients = WorkizClient::query()->where('customer_id', $customer->getKey())
            ->whereIn('franchise_id', ViewScope::forUser($request->user())->franchiseIds())
            ->with('franchise:id,name')->get();
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
                default => null,
            };

            if ($error !== null) {
                return $answer + ['error' => $error];
            }

            $jobs = WorkizJob::query()->where('franchise_id', $client->franchise_id)->where('client_id', $client->client_id)
                ->get(['job_id', 'serial_id', 'payload']);
            $numbers = Job::query()->whereIn('id', $jobs->pluck('job_id')->filter())->pluck('number', 'id');

            $answer['notes'] = $jobs->flatMap(fn (WorkizJob $job) => collect($job->payload['Notes'] ?? [])
                ->filter(fn ($note) => is_array($note))
                ->map(fn (array $note) => [
                    'text' => (string) ($note['Note'] ?? ''),
                    'created_at' => $note['Timestamp'] ?? null,
                    'author' => $note['TechName'] ?? null,
                    'job' => ['id' => $job->job_id, 'number' => $numbers[$job->job_id] ?? null, 'workiz_number' => $job->serial_id === null ? null : (int) $job->serial_id],
                ]))
                ->sortByDesc('created_at')->values()->all();

            return $answer;
        })->values()]);
    }
}
