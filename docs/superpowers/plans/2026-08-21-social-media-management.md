# Social Media Management — implementation plan

> **For agentic workers:** implement task-by-task against `docs/superpowers/specs/2026-08-21-social-media-management-design.md`. Use TDD. Do not copy A1 Tools.
>
> **Do not deploy** until Mia says so. Do not put tokens in git or chat. App Review videos use sandbox test users.
>
> **Layout lock (21 Aug):** Posts tab = pages sidebar · composer left · calendar right. Accounts tab for connect. Scope = inboxes: A-1 Chimney’s Page is visible on A-1 Chimney and on A1 Group, never on Premier Garage. Preview http://localhost:8790.

**Goal:** A Marketing sub-tab where a tenant connects pages, composes once, publishes or schedules per target, and sees each result in a queue — enough UI for Meta/TikTok/LinkedIn/Pinterest review videos.

**Stack:** Laravel 13 · PHP 8.4 · MySQL 8 (`testing` in the name) · Flutter web · FileStore · `ModuleRegistry` · `TabBarLike` · `SidePanel` · `FranchiseWallTime` · encrypted tokens like mail OAuth.

**Chrome:** `client/lib/core/theme.dart`. No purple. No extra page title above the chips. Refusals are sentences from the server.

---

## Global constraints

- Front door: `StartUp.md`. STATUS wins disagreements.
- Four gates: release flag (unused) → entitlement → `marketing.*` → assignment/reach. Provider `notReadyBecause()` refuses Connect, it does not 500.
- Connections XOR-scoped (org **or** company **or** franchise), same as mailboxes.
- Wall-clock `scheduled_for` in the franchise timezone of the **view** (TODO #20).
- Per-target send + idempotent claim row (copy automation outbox, not “post then log”).
- Register client screen in `module_screens.dart` already exists (`marketing`). Do not add a second sidebar tab.
- First wave providers: `facebook_page`, `instagram_professional`, `threads`, `linkedin`, `tiktok`.
- Redirect URIs already reserved on Meta: `/api/v1/social/facebook/callback` (and instagram, threads). TikTok/LinkedIn/Pinterest as on the board.

---

## Tasks

### Task 1 — Layout lock in the real client (no API)

Replace the placeholder `SocialMediaTab` with chips **Posts** and **Accounts**. Posts is three panes: pages sidebar, composer, calendar. Compose disabled with a sentence when this view has no usable page. 375px: pages dropdown, then composer, calendar as a list.

**Verify:** widget test for chips and disabled Publish; a fixture with A-1 Chimney vs Premier Garage is Task 3.

**Done 21 Aug.** `client/lib/screens/marketing/social_media_tab.dart`. Tests in `client/test/marketing_screen_test.dart`. Connect panel lists the catalogue; OAuth is Task 4.

### Task 2 — Provider catalogue + notReadyBecause

**Done 21 Aug.** PHP enum `SocialProvider`, registry with labels and `notReadyBecause()` from env. `GET /api/v1/social/providers`. Client Accounts tab lists the server catalogue.

### Task 3 — Connections table + list

**Done 21 Aug.** Migration `social_connections`. XOR scope. List for the current view-scope (hide sibling-company connections, like inboxes v0.0.0041). Org-level pages ARE visible down into a company/franchise (owner lock, unlike inboxes). Encrypted token columns. Never in JSON.

### Task 4 — Pinterest OAuth popup (first available network)

Pinterest Trial is approved, so this is the first Connect that can actually finish. Facebook remains Task 6/7 after Meta Tech Provider.

`POST .../pinterest/start` + public HTML callback + status poll. Popup pattern from Email Google. Store refresh token. Connection row `connected`, bound to the current view.

**Verify:** feature test with HTTP fake. Live Connect needs `PINTEREST_CLIENT_ID` / `_SECRET` on the box `.env` and redirect `https://app.ezbizi.com/api/v1/social/pinterest/callback` on the Trial app. Never put tokens in git or chat.

### Task 5 — Compose + per-target queue (no live publish)

Create post + targets. Schedule wall-clock. Queue lists targets. Publish now enqueues; worker is a no-op stub that marks `sent` in testing only **or** `failed` with “Publishing is not wired yet” in production until Task 6.

**Verify:** tests for per-target rows; over-length on one target does not drop the others.

**Publish now done 28 Aug. Scheduling is not.** `social_posts` + `social_post_targets`, `POST/GET /api/v1/social/posts`, and `PublishSocialTarget` — one job per target, `tries = 1`. The composer is live: words, one picture or video, a tickbox per account, and a Sent list underneath showing each target's outcome in the server's own sentence.

The stub worker in the paragraph above was never needed. Pinterest and TikTok publishers already existed (27 Aug, reachable only from `social:post-test`), so Publish now sends for real on those two and refuses **before writing a row** for the rest — `Publishers::refusalFor()` is the one map, and `GET /social/connections` carries `can_publish` so the composer greys the account rather than queueing something that cannot go.

Still open from this task: **scheduling** (`scheduled_for` in the view's wall clock — the calendar beside the composer has nothing to draw until it lands) and **per-network body limits** (one 5,000-character cap for now; Bluesky's 300 and X's 280 belong on `SocialProvider` when either gets a publisher).

### Task 6 — Facebook Page publish (sandbox)

Worker calls Graph `/{page-id}/feed` (and photo when FileStore has an image). Store remote id. Failure sentence on the target. Idempotent.

**Verify:** HTTP fake; one retry does not create two Graph posts.

### Task 7 — Instagram professional + Threads

Same connection model, Facebook Login where the Page is linked. Separate provider keys. Compose maps to each Graph call. Skip if Meta sandbox cannot publish; UI still connects and the queue says why.

### Task 8 — LinkedIn + TikTok

Same as 7 against those consoles. TikTok may stay sandbox until their review; Connect + compose + “will publish when posting is approved” is an honest video.

### Task 9 — Grants + automations declare

`social_connection_grants`. Administrator implicit. Events `social.published` / `social.publish_failed` declared `isRaised: true` when Task 6 lands.

**Events done 28 Aug**, earlier than this planned for: they landed with Publish now rather than with Facebook, because Pinterest and TikTok already publish and a raised event that nothing declares is the same lie as a declared event nothing raises. Both are `isRaised: true` on the `marketing` module and emitted per TARGET, not per post — a post to three accounts where one refused is neither a success nor a failure, so a per-post event would have to pick one and be wrong about the others.

`social_connection_grants` is still open. Reach today is `SocialConnectionAccess` alone.

---

## File sketch (Task 1–3)

| Path | Responsibility |
|---|---|
| `app/Support/Social/SocialProvider.php` | Enum |
| `app/Support/Social/SocialProviderRegistry.php` | Labels, notReadyBecause |
| `app/Models/SocialConnection.php` | Scoped connection |
| `app/Models/SocialPost.php` / `SocialPostTarget.php` | Compose + per-target send |
| `client/lib/screens/marketing/social_media_tab.dart` | Chosen layout |
| `client/lib/screens/marketing/social_accounts_tab.dart` | Connect / list |
| `client/lib/screens/marketing/social_compose_tab.dart` | Compose |
| `client/lib/screens/marketing/social_queue_tab.dart` | Queue |

---

## Verification

- Backend and client tests green.
- Empty Connect refuses in English.
- Sibling company cannot see this company’s Page.
- Preview video path exists on the Flutter UI, not only in `docs/previews/social/`.
