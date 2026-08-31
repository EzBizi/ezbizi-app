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

**Fixed 28 Aug, from a real publish on production.** Three defects the tests could not have found, because all three needed a live network:

1. **TikTok refused every post** — "Please review our integration guidelines", which names no field and reads like the video was rejected. It was not. `Publishers::for()` defaulted to Direct Post, which requires TikTok's audit; the app is unaudited, so that endpoint was never going to answer anything else. Circular, too: the audit submission needs a filmed demo of a working publish. `services.tiktok.direct_post` (default **false**) now sends posts to the creator's drafts, which needs only `video.upload` and works from day one — so there is a real publish to film.
2. **"Waiting to send" was a lie.** The composer read the Sent list once and never again, so a target that failed two seconds later sat on screen claiming to be in flight. A failure displayed as progress is worse than an error: an error sends somebody looking. It now polls until the queue answers, and says so plainly if the queue stops answering rather than spinning forever.
3. **An attached file showed only its name.** It is displayed now — images decode from the bytes in hand, video gets its name and size. A filename is not confirmation when the cost of the wrong file is a public account under the tenant's own name.

4. **A refusal that arrived too late.** Pinterest ACCEPTS video, so the network-level check passed and a row was written — and only the publisher knew it sends images only. `SocialPublisher::cannotSend()` now answers that up front, so all three refusals (no publisher · the network will not take this kind · we have not built that half) happen before anything is queued. The order matters and is pinned in `SocialPostsController`: telling somebody with a text-only post that our video poster is missing is true and not their problem.

**Known gap, not a defect: Pinterest video publishing does not exist.** The connection is healthy, which makes it very easy to mistake for a failure — which is exactly what the sentence says. Whoever builds it: `PinterestPublisher::cannotSend()` is the one place that claims it cannot, and `publish()` reads that rather than repeating it.

**Scheduling done 28 Aug — Task 5 is complete.** `scheduled_for` + `scheduled_timezone` on `social_posts`, a `scheduled` target status distinct from `queued`, and `ezbizi:publish-due-social-posts` every minute.

Wall clock, per TODO #20: the time is stored exactly as typed and the CLOCK is stored beside it, resolved fresh at dispatch. An instant computed at compose time would drift if a branch's timezone were ever corrected — and the promise somebody made was about a clock on a wall, not a moment in physics. The clock comes from the VIEW and is never sent by the client, which could otherwise send one the screen was not showing.

A sweep rather than a delayed job, for the reason the automations outbox gives: changing your mind means a row stops being due, not hunting a job to cancel. `scheduled` is deliberately not `queued` — "waiting to send" for a post due next Tuesday is the same lie the composer told in August, only slower — and the client does not poll a scheduled post for the same reason.

A time already past is REFUSED rather than published immediately: somebody who mistypes the day has made a mistake, and a post cannot be unsent.

**Video playback done 28 Aug.** `video_player`, fed from an object URL over the bytes already in hand — nothing is uploaded to preview, which matters because the file is being looked at precisely because nobody is sure it is the right one. Web only; off the web `Files.previewUrl` answers null and the name-and-size label stands, which is honest rather than a broken player.

Still open from this task: **per-network body limits** (one 5,000-character cap for now; Bluesky's 300 and X's 280 belong on `SocialProvider` when either gets a publisher).

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
