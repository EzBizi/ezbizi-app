<?php

namespace App\Http\Controllers\Api;

use App\Support\Http\InternalRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Route as RouteObject;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * EzBizi as an MCP server (Streamable HTTP, stateless, JSON responses).
 *
 * Two tools reach the whole API rather than a hand-picked slice of it:
 * `list_endpoints` reads the route table, `call_api` dispatches through the
 * kernel with the caller's own token. So every permission, ownership, view and
 * subscription check a person meets in the app, the AI meets too — nothing
 * here grants anything, and a new endpoint is reachable the day it ships.
 *
 * ponytail: hand-rolled JSON-RPC (four methods) instead of laravel/mcp; no SSE,
 * no OAuth. Bearer API keys work with Claude Code/Desktop, Cursor and most
 * clients. Add OAuth when a connector that demands it (claude.ai, ChatGPT) is
 * wanted.
 */
class McpController
{
    /** Marks the calls MCP dispatches, so the activity log can say so. */
    public const USER_AGENT = 'EzBizi-MCP/1.0';

    private const PROTOCOL_VERSIONS = ['2025-06-18', '2025-03-26', '2024-11-05'];

    private const MAX_BODY_CHARS = 100_000;

    /** Never reachable through call_api: credentials, the platform, and this. */
    private const BLOCKED = ['auth', 'staff', 'mcp', 'me/api-keys', 'me/sessions'];

    public function handle(Request $request): JsonResponse|Response
    {
        $message = $request->json()->all();
        $id = $message['id'] ?? null;
        $method = $message['method'] ?? null;

        // Notifications and responses carry no id and want no answer.
        if (! array_key_exists('id', $message)) {
            return response()->noContent(202);
        }

        try {
            $result = match ($method) {
                'initialize' => $this->initialize($message['params'] ?? []),
                'ping' => (object) [],
                'tools/list' => ['tools' => $this->tools()],
                'tools/call' => $this->call($request, $message['params'] ?? []),
                default => null,
            };
        } catch (Throwable $e) {
            report($e);

            return $this->reply($id, error: ['code' => -32603, 'message' => 'Internal error.']);
        }

        return $result === null
            ? $this->reply($id, error: ['code' => -32601, 'message' => "Unknown method: {$method}"])
            : $this->reply($id, $result);
    }

    private function initialize(array $params): array
    {
        $asked = $params['protocolVersion'] ?? null;

        return [
            'protocolVersion' => in_array($asked, self::PROTOCOL_VERSIONS, true) ? $asked : self::PROTOCOL_VERSIONS[0],
            'capabilities' => ['tools' => (object) []],
            'serverInfo' => ['name' => 'ezbizi', 'version' => '1.0.0'],
            'instructions' => 'EzBizi is a field-service CRM (leads, customers, jobs, scheduling, '
                .'inventory, fleet, HR, phone, marketing, automations, reports). You act as the person '
                .'who owns the API key, with exactly their permissions. Find an endpoint with '
                .'list_endpoints, then call it with call_api. Start with GET me to see who you are, '
                .'which companies you can reach, and which modules are switched on.',
        ];
    }

    private function tools(): array
    {
        return [
            [
                'name' => 'list_endpoints',
                'description' => 'List EzBizi API endpoints (method, path, handler). Filter with a word '
                    .'such as "lead", "customer", "job", "invoice". Paths are relative to /api/v1.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['search' => ['type' => 'string', 'description' => 'Word to filter by.']],
                ],
                'annotations' => ['readOnlyHint' => true],
            ],
            [
                'name' => 'call_api',
                'description' => 'Call one EzBizi API endpoint as the key owner and return its HTTP status '
                    .'and JSON. Replace {placeholders} in the path with real ids.',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['method', 'path'],
                    'properties' => [
                        'method' => ['type' => 'string', 'enum' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE']],
                        'path' => ['type' => 'string', 'description' => 'e.g. "leads" or "customers/42".'],
                        'query' => ['type' => 'object', 'description' => 'Query-string parameters.'],
                        'body' => ['type' => 'object', 'description' => 'JSON body for writes.'],
                        'view' => ['type' => 'string', 'description' => 'Optional current view: '
                            .'"organization", "company:<id>" or "franchise:<id>".'],
                    ],
                ],
                'annotations' => ['destructiveHint' => true],
            ],
        ];
    }

    private function call(Request $request, array $params): array
    {
        $args = $params['arguments'] ?? [];

        return match ($params['name'] ?? null) {
            'list_endpoints' => $this->text($this->endpoints((string) ($args['search'] ?? ''))),
            'call_api' => $this->dispatch($request, $args),
            default => $this->text('Unknown tool.', isError: true),
        };
    }

    private function endpoints(string $search): string
    {
        $lines = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RouteObject $route) => in_array('tenant', $route->gatherMiddleware(), true)
                && str_starts_with($route->uri(), 'api/v1/')
                && $this->allowed(substr($route->uri(), 7)))
            ->map(fn (RouteObject $route) => sprintf(
                '%s %s  (%s)',
                implode('|', array_diff($route->methods(), ['HEAD'])),
                substr($route->uri(), 7),
                class_basename($route->getActionName()),
            ))
            ->when($search !== '', fn ($c) => $c->filter(fn ($line) => stripos($line, $search) !== false))
            ->values();

        return $lines->isEmpty() ? 'No endpoints match.' : $lines->implode("\n");
    }

    private function dispatch(Request $request, array $args): array
    {
        $method = strtoupper((string) ($args['method'] ?? 'GET'));
        $path = trim((string) ($args['path'] ?? ''), '/');
        $path = preg_replace('#^(api/)?v1/#', '', $path);

        if (! in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)
            || ! preg_match('#^[A-Za-z0-9_\-./]+$#', $path)
            || str_contains($path, '..')
            || ! $this->allowed($path)) {
            return $this->text("Refused: {$method} {$path} is not reachable through MCP.", isError: true);
        }

        $query = is_array($args['query'] ?? null) ? $args['query'] : [];
        $body = is_array($args['body'] ?? null) ? $args['body'] : null;

        $view = $args['view'] ?? null;
        $response = InternalRequest::send(
            $request,
            $method,
            '/api/v1/'.$path.($query ? '?'.http_build_query($query) : ''),
            $body,
            headers: ['User-Agent' => self::USER_AGENT] + (is_string($view) && $view !== '' ? ['X-EzBizi-View' => $view] : []),
        );

        $status = $response->getStatusCode();
        $text = (string) $response->getContent();
        if (strlen($text) > self::MAX_BODY_CHARS) {
            $text = substr($text, 0, self::MAX_BODY_CHARS)."\n…truncated; narrow the query or paginate.";
        }

        return $this->text("HTTP {$status}\n{$text}", isError: $status >= 400);
    }

    private function allowed(string $path): bool
    {
        foreach (self::BLOCKED as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return false;
            }
        }

        return true;
    }

    private function text(string $text, bool $isError = false): array
    {
        return ['content' => [['type' => 'text', 'text' => $text]], 'isError' => $isError];
    }

    private function reply(mixed $id, mixed $result = null, ?array $error = null): JsonResponse
    {
        return response()->json(
            ['jsonrpc' => '2.0', 'id' => $id] + ($error ? ['error' => $error] : ['result' => $result]),
        );
    }
}
