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

Claude Code:

```bash
claude mcp add --transport http ezbizi https://app.ezbizi.com/api/v1/mcp --header "Authorization: Bearer <key>"
```

Not yet: OAuth (claude.ai and ChatGPT connectors require it), SSE streaming.

## Webhooks

Already built as an automation action: **Automations → any trigger → Call a
webhook**. Signed (`X-EzBizi-Signature` = HMAC-SHA256 of `timestamp.body`,
`X-EzBizi-Timestamp`), SSRF-guarded, retried on 5xx/timeouts
(`App\Support\Automations\OutboundWebhook`). The builder takes the URL only;
a signing secret is set through the API.
