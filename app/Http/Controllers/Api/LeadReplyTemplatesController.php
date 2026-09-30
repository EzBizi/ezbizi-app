<?php

namespace App\Http\Controllers\Api;

use App\Models\Company;
use App\Support\Access\Refusal;
use App\Support\Settings\SettingsResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The reply templates, edited where they are used: Leads > Manage > Templates
 * (Jose, 2026-09-30: "add a template manager so they can be changed from
 * within the app"). The same `leads.reply_templates` setting Settings shows,
 * one list per company the reader reaches, so each brand signs its own name.
 */
class LeadReplyTemplatesController extends Controller
{
    private const KEY = 'leads.reply_templates';

    public function __construct(private readonly SettingsResolver $settings) {}

    /** GET /api/v1/leads/reply-templates */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('leads.view'), Response::HTTP_FORBIDDEN, Refusal::permission('see the reply templates')->message());

        return response()->json([
            'data' => $user->reachableCompanies()->sortBy('name')->values()->map(fn (Company $c) => [
                'company' => ['id' => $c->getKey(), 'name' => $c->name],
                'templates' => $this->rows($this->settings->get(self::KEY, $c)),
            ])->all(),
            'meta' => ['may_edit' => $user->hasPermission('leads.edit')],
        ]);
    }

    /** PUT /api/v1/leads/reply-templates/{company} */
    public function update(Request $request, Company $company): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('leads.edit'), Response::HTTP_FORBIDDEN, Refusal::permission('change the reply templates')->message());
        abort_unless($user->reachableCompanies()->contains(fn ($c) => $c->is($company)), Response::HTTP_NOT_FOUND);

        $input = $request->validate([
            'templates' => ['required', 'array', 'min:1', 'max:30'],
            'templates.*.name' => ['required', 'string', 'max:40'],
            'templates.*.text' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $this->settings->set(self::KEY, $company, array_map(
                fn ($t) => ['label' => trim($t['name']), 'value' => trim($t['text'])],
                $input['templates'],
            ));
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json(['data' => [
            'company' => ['id' => $company->getKey(), 'name' => $company->name],
            'templates' => $this->rows($this->settings->get(self::KEY, $company)),
        ]]);
    }

    /** @return list<array{name: string, text: string}> */
    private function rows(mixed $value): array
    {
        return array_values(array_map(fn ($t) => [
            'name' => (string) ($t['label'] ?? $t),
            'text' => (string) ($t['value'] ?? $t),
        ], (array) $value));
    }
}
