<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Route as RouteObject;
use Illuminate\Support\Str;

/**
 * GET /api/v1/openapi.json: the public reference www.ezbizi.com/developers/
 * renders. Built from McpController::routes(), the table `list_endpoints`
 * reads, so an endpoint is documented the day it ships and a blocked one never
 * is. Paths and methods only: no tenant data, nothing about who may call what
 * beyond the key level each method needs.
 *
 * ponytail: no request or response schemas; add them when the routes carry
 * them (FormRequests) rather than hand-writing them here.
 */
class OpenApiController
{
    /** The lowest key level each method needs (EnforceApiKeyAccess). */
    private const LEVEL = ['GET' => 'viewer', 'POST' => 'editor', 'PUT' => 'editor', 'PATCH' => 'editor', 'DELETE' => 'full'];

    /** Laravel's resource verbs, said the way a reader would. */
    private const VERB = ['index' => 'List', 'show' => 'Get', 'store' => 'Create', 'update' => 'Update', 'destroy' => 'Delete'];

    public function __invoke(): JsonResponse
    {
        $paths = [];

        foreach (McpController::routes() as $route) {
            /** @var RouteObject $route */
            $path = '/'.substr(str_replace('?}', '}', $route->uri()), 7);
            $params = collect($route->parameterNames())->map(fn (string $name) => [
                'name' => $name, 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string'],
            ])->all();
            [$class, $action] = array_pad(explode('@', class_basename($route->getActionName())), 2, '__invoke');
            $tag = Str::headline(Str::before(explode('/', ltrim($path, '/'))[0], '{')) ?: 'General';

            foreach (array_diff($route->methods(), ['HEAD', 'OPTIONS']) as $method) {
                $paths[$path][strtolower($method)] = array_filter([
                    'tags' => [$tag],
                    'summary' => self::VERB[$action] ?? Str::headline($action === '__invoke' ? Str::before($class, 'Controller') : $action),
                    'operationId' => "{$method} {$path}",
                    'parameters' => $params ?: null,
                    'x-key-level' => self::LEVEL[$method] ?? 'full',
                    'responses' => (object) ['default' => ['description' => 'JSON']],
                ]);
            }
        }

        ksort($paths);

        return response()->json([
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'EzBizi API',
                'version' => 'v1',
                'description' => "Every endpoint an EzBizi API key can call, generated from the app's own route table "
                    .'(the same list the MCP tool `list_endpoints` returns).'."\n\n"
                    .'Send `Authorization: Bearer <key>`. A key acts as the person who made it, with exactly their '
                    .'role\'s permissions. `x-key-level` is the lowest key level a method needs: Viewer reads, Editor '
                    .'also writes, Full access also deletes. Guides: https://www.ezbizi.com/developers/',
            ],
            'servers' => [['url' => 'https://app.ezbizi.com/api/v1']],
            'security' => [['bearer' => []]],
            'components' => ['securitySchemes' => ['bearer' => ['type' => 'http', 'scheme' => 'bearer']]],
            'paths' => $paths,
        ], 200, [], JSON_UNESCAPED_SLASHES)->header('Cache-Control', 'public, max-age=3600');
    }
}
