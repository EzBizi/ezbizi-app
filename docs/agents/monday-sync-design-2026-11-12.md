# Monday ⇄ EzBizi Task Board: sync design, 2026-11-12

Jose, 2026-11-12: *"first import Mine and Rodrigo's boards, so i can see if it all
turns well on our side, and is it possible to keep them synced to each other ? at
least till we get rid of monday"*, then *"Jose, Rodrigo, Ana, Brian, Jonathan"*.
Builds on `docs/agents/monday-migration-plan-2026-11-12.md` (the survey).

## Answer

**Yes, a two-way sync is feasible until Monday is retired**, at the size of these
boards (5 boards, ~250 items, ~270 subitems, ~1,000 updates). Every piece it needs
already exists on one side or the other: the importer is idempotent on
`external_ref = monday:<id>` (tasks, subtasks, updates), Monday has webhooks and a
GraphQL API with `updated_at`, and EzBizi has one write path for tasks
(`TaskWriter`) where an outbox can be filled. Nothing below needs a new Monday plan.

What it does **not** need: a personal API token. Jose created a Monday OAuth app
"EzBizi" (Client ID, Client Secret, Signing Secret, App ID, App Slug, in
`F:\Credentials\Monday\API.txt`; read by script, never printed).

## Token: a "Connect Monday" card (Integrations)

Checked 2026-11-12: no Monday token or key exists in `config/`, `.env.example`,
the box `.env`, or `~/ezbizi`. The only credentials are the OAuth app's.

1. **Server config** (desk puts these in the box `.env` by script from
   `F:\Credentials\Monday\API.txt`): `MONDAY_CLIENT_ID`, `MONDAY_CLIENT_SECRET`,
   `MONDAY_SIGNING_SECRET` → `config/services.php` `monday`.
2. **Redirect URL to register in the Monday app** (Developer Center → EzBizi →
   OAuth & Permissions → Redirect URLs), character for character:
   `https://app.ezbizi.com/api/v1/social/monday/callback`
   (the existing generic `social.callback` route with network `monday`; measured
   on prod with `route('social.callback', ['network' => 'monday'])`).
3. **Scopes** to tick on the same page: `me:read`, `users:read`, `boards:read`,
   `updates:read`, `assets:read`, `webhooks:write` (read side). Add `boards:write`
   and `updates:write` only when the write side ships.
4. **Connect flow**: `SocialProvider::Monday` (`monday`), org-scoped. Connect →
   `https://auth.monday.com/oauth2/authorize?client_id…&redirect_uri…&state…` →
   callback exchanges the code at `https://auth.monday.com/oauth2/token` → access
   token stored encrypted in `social_connections.credentials` (masked, never
   revealable, like every other connection); `remote_id` = Monday account id,
   `display_name` = the Monday user who connected.
5. **The connect finishes its own setup** (Jose's 11-12 rule): in the same
   request it (a) runs `{ me { id email account { id slug } } }` — pass/fail on the
   card; (b) reads the boards the token can see and stores the board → EzBizi
   target map in `setup` (the 5 personal boards default to `personal:<email>`);
   (c) registers the webhooks below and records their ids; (d) runs the first
   poll. Each step is a row in `checks` shown on the card; a failed one is red
   with its sentence.
6. **Who connects**: Jose (jose@a-1chimney.com in Monday). A Monday OAuth token
   acts as that Monday user and sees only what he sees — fine for these 5 boards
   (he read all of them for this export). For the rest of the migration (survey
   §0: private boards, archived items) the connect should be done by a Monday
   **admin** (Shoval, Chaim, Bar, Merav or Eve). Nothing else changes.
7. **Webhook verification**: webhooks created by the app's token carry a JWT in
   the `Authorization` header signed (HS256) with the app's **Signing Secret**;
   the endpoint verifies it and refuses anything else (`401`). Registration sends
   `{"challenge": …}` once, which is echoed back. Not yet measured against a live
   Monday request: the first build step logs the first real header and the test
   pins what it shows.
   Endpoint: `POST https://app.ezbizi.com/api/v1/integrations/monday/webhook`.

## Monday → EzBizi (read side)

Smallest design: **webhooks for speed, a poll for truth.**

- **Webhooks** (per mapped board, created by the connect): `create_item`,
  `change_column_value` (status, people, date, timeline, text), `change_name`,
  `create_update`, `create_subitem`, `change_subitem_column_value`,
  `item_archived`, `item_deleted`, `move_item_to_group`. The handler stores the
  event and queues a re-read of **that item** (never trusts the payload's
  values; one GraphQL read of the item with its subitems, updates, replies and
  assets) and upserts it.
- **Poll** every 10 minutes (`ezbizi:monday-sync`, scheduler, one connection at
  a time): `items_page` with `__last_updated__ > last cursor` per mapped board,
  same upsert. Catches dropped webhooks and anything done while EzBizi was down.
- **Upsert** = the importer's code path, extended from "skip what exists" to
  "update what changed": title, group, status (done/completed_at), owners,
  dates, columns; new updates/replies/files by their `monday:` keys (already
  idempotent). Archived/deleted in Monday → task soft-deleted here (restorable).
- **Never overwrite a person's edit** (DEVELOPMENT-RULES "a recurring sweep never
  overwrites a field a person has touched"): each synced task keeps
  `monday_updated_at` (Monday's item `updated_at` at the last apply) and
  `synced_at`. Monday's change is applied when Monday's `updated_at` is newer than
  `synced_at`; if the EzBizi task also changed after `synced_at`, the conflict
  rule decides.
- **Files**: Monday asset URLs live 1 hour, so a file is downloaded in the same
  job that saw it. Same limits as uploads (`ezbizi.attachments`); what does not
  fit is named in the update ("file not carried").

## EzBizi → Monday (write side; designed, not built)

- **Outbox**: `monday_outbox` (organization_id, task_id, monday_item_id, kind
  `status|owners|due|title|update`, payload, attempts, sent_at, error). Filled in
  `TaskWriter::update()` / `comment()` only for tasks whose `external_ref` starts
  `monday:` and whose board is mapped on a live Monday connection — one place,
  so the API, automations and the app all feed it.
- **Worker** (queue, retries with backoff): `change_multiple_column_values` for
  status/people/date/name (status by label; people by Monday user id via the
  people map), `create_update` for new updates (body + "— from EzBizi, <name>").
  It records the Monday update id back as the update's `external_ref`, so the
  webhook echo is recognised and skipped.
- **Echo suppression**: a webhook event whose `userId` is the connection's
  Monday user and whose change matches an outbox row sent in the last 2 minutes
  is dropped.

## Conflict rule

**Last writer wins, by timestamp**, per task: Monday's item `updated_at` vs the
EzBizi task's `updated_at`. The loser's values are written to the task's
Activity ("Monday changed Status to Done at 10:42; EzBizi's Working on it at
10:40 was replaced"), so nothing is lost silently. Updates (comments) never
conflict: both sides append.

## Export shape (what the importer reads)

`{ people: {monday email: EzBizi email}, users: [{monday_id, name, email}],
boards: [{monday_id, name, owner_email, groups: [{monday_id, name, colour}],
items: [Item]}] }`; Item = `{monday_id, name, group, created_at, updated_at,
creator_email, status, done, completed_at, priority, people: [email], due_on,
timeline: {from, to}, columns: {title: text | {type: date|number|timeline,
value}}, subitems: [Item], updates: [{monday_id, author_email, author_name,
created_at, body, files: [File], replies: [{monday_id, …, files}]}]}`;
File = `{monday_id, name, ext, size, url}`. `--files=<dir>` holds
`<monday asset id><ext>` copies, tried before `url` (which expires in 1 h).

## Build order

1. Ship the importer fixes (this branch) and run the 5-board import.
2. `SocialProvider::Monday` + Connect card + callback + `me` check (read scopes).
3. Webhook endpoint (JWT verify, challenge) + per-item re-read upsert job.
4. `ezbizi:monday-sync` poll + conflict rule + Activity lines.
5. Write side (outbox) only after Jose has used 2-4 for a few days.
