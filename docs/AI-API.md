# AI access: API keys, MCP and webhooks

An AI (or a script) acts in EzBizi **as one person**, with that person's
permissions, reach and current-view rules. Nothing here grants anything a
session does not already have.

## API keys

`GET/POST/DELETE /api/v1/me/api-keys` — `POST {"name": "Claude", "level": "viewer"|"editor"|"full"}`
returns the key once (installed clients may still send `access: read|write`; write = full).
A key is a Sanctum token carrying the `api-key` ability plus `read`, `edit` or `*`
(`App\Support\Auth\ApiKey`), set server-side, never from the client's device name.

Which level a person may make is their role's grant (Roles & permissions → API keys):
`api_keys.view` = Viewer (read), `api_keys.edit` = Editor (read and edit, no DELETE),
`api_keys.delete` = Full access. Checked again on every keyed request, so narrowing the
role downgrades or stops keys already made. Administrators hold all three.

- never idles out (`SlideIdleWindow` skips it); lives until revoked, or until a
  password reset / staff reset deletes all the person's tokens;
- not listed or revoked as a session (`ProfileController`);
- Viewer keys refuse every non-GET, Editor keys refuse DELETE (`EnforceApiKeyAccess`);
- **no** key may change password, 2FA, SMS codes, sessions, API keys, or delete
  the account.

Send it as `Authorization: Bearer <key>`, optionally with
`X-EzBizi-View: organization | company:<id> | franchise:<id>`.

## MCP

`POST /api/v1/mcp` — Streamable HTTP, stateless JSON (`McpController`). Two
tools reach the whole tenant API:

- `list_endpoints(search)` — the route table (tenant routes only; no staff, auth,
  mcp, api-keys, sessions);
- `call_api(method, path, query, body, view)` — dispatched through the kernel
  with the caller's own token, so every middleware and policy runs.
  It returns JSON `{status, complete, body}` and never cuts a body: over 100,000
  characters a list pages (`offset`/`limit`, `next_offset`) and an object comes
  back as an `outline` of its keys; `pick` (a dot path such as `data.schema`)
  returns one part.

Claude Code:

```bash
claude mcp add --transport http ezbizi https://app.ezbizi.com/api/v1/mcp --header "Authorization: Bearer <key>"
```

Not yet: OAuth (claude.ai and ChatGPT connectors require it), SSE streaming.

## Public reference

`GET /api/v1/openapi.json` (no auth, `OpenApiController`) is OpenAPI 3.1 built
from `McpController::routes()`, the same table `list_endpoints` reads; each
operation carries `x-key-level` (viewer/editor/full). The websites box copies it
hourly (`/var/www/vhosts/ezbizi.com/refresh-api-spec.sh`, ezbizi_ftp's crontab,
keeps the last good copy) to `www.ezbizi.com/ezbizi-api.json`, which
`/developers/reference/` (WordPress page, Scalar) renders. Guides:
`/developers/`.

## Webhooks

Already built as an automation action: **Automations → any trigger → Call a
webhook**. Signed (`X-EzBizi-Signature` = HMAC-SHA256 of `timestamp.body`,
`X-EzBizi-Timestamp`), SSRF-guarded, retried on 5xx/timeouts
(`App\Support\Automations\OutboundWebhook`). The builder takes the URL only;
a signing secret is set through the API.

## Activity log (every keyed call)

`EnforceApiKeyAccess` writes an `api_key.request` audit row for every call a key
makes, refused ones included: method, path, query, status, `via` (`mcp` or
`api`), level, IP. A write also keeps what was **sent** and, for PUT/PATCH/DELETE,
**before**: the GET of the same path, taken as the same person just before the
change, so it can be put back. The outer `POST /mcp` is not logged; the calls it
dispatches are, with their real paths.

`GET /api/v1/api-keys/activity` (newest first, 200 rows, `before=<iso>` to page,
`key_id=`): your own keys; an Administrator sees the organization's and may
filter `user_id=`.

## Put it back

`POST /api/v1/api-keys/activity/{event}/revert` restores a keyed PUT/PATCH on an
**allowlisted** endpoint (`App\Support\Auth\ApiKeyRevert`: website values,
customers). Only the fields the change sent; refused (409) when the record no
longer reads as the change left it (the `after` snapshot) or was already put
back; refused (422) off the allowlist or when a needed value was redacted. The
write goes out as the person pressing the button. Activity rows carry `id`,
`revertable` and `reverted_at`. Add an endpoint to the allowlist only with a test
that changes a record through a key and puts it back.
