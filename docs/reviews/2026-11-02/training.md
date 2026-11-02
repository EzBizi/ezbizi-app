# Training: review, 2026-11-02

Measured on production (app box, tenant `Triplex Group` 019fd712…, the organization that holds A1's training; see section 5) and through the API as the MCP key owner.

## 1. Working

- **Guides:**
  - Technician Training Guide: published, 165 topics, 0 empty.
  - Dispatcher Training Guide: published, 27 topics, 0 empty.
  - Dispatcher Introduction Video: published, 1 topic.
- **Videos:** all 67 are alive (Vimeo/YouTube oEmbed checked one by one), 57 + 9 + 1. The 9 Dispatcher videos restored on 10-12 come from YouTube.
- **Images:** every held image's file exists in storage (0 missing across all guides). Signed links serve image/jpeg; a tampered link gets 403 (checked 09-30; the route is unchanged since).
- **Learner view:** `GET /training/learn` returns 200 with both guides as topics with blocks, plus `last_read_at`.
- **History:**
  - 70 progress rows, 45 of them completed; 30 touched since 10-01.
  - 52 test attempts, 5 since 10-01.
  - 1 assignment.
- **Reader:** chapter › section › topic outline, sequential lock (enforced on the server), Complete & Continue, in-app video on web, Windows, Android, iOS and macOS. Shipped 0.0.0328.
- **Learn tab:** UI/UX's Continue / Assigned / Library layout; Continue picks the guide with the latest `last_read_at`.
- **Dashboard box:** the "due to retake" figures, plus an attention row only when somebody is due.
- **Grok staff bot:** `GuideScreens::topics()` / `plainText()` read by AssistantBrain (0.0.0424).

## 2. Broken

- **Links built inside an internal API call say `http://localhost`** (not Training's code; reported to the desk).
  - Evidence: through MCP `call_api GET training/learn`, every image link is `http://localhost/api/v1/training/media/…?signature=…` and every `embed_url` is `http://localhost/api/v1/training/video/…`.
  - Why: `App\Support\Http\InternalRequest::send` builds the sub-request with `Request::create('/'.$uri)` and no host, so `route()` and signed URLs inside it use `localhost`. `config('app.url')` is correct (`https://app.ezbizi.com`).
  - Effect: any link an API key, MCP or the bot gets back from an internal call is dead. The app itself is unaffected, because its requests carry the real host.
  - Fix: create the sub-request on `$as->getSchemeAndHttpHost().'/'.ltrim($uri, '/')`.
  - Training's own bot path is safe: plainText sends the public video URL and skips images.
- **`training:import-a1-tools` targets the organization by name `A1-Group`, which no longer exists.** The tenant was renamed `Triplex Group`, and a new `A-1 Group` was created 11-01. A re-run now stops with "Expected exactly one organization named A1-Group, found 0", which is safe; nothing is written. The import is finished, so this only matters if it's ever re-run (the 7 missing how-to videos would go in through the builder, not a re-import). Not fixed: see section 5.
- Nothing else is broken. There are no Training entries in failed_jobs.

## 3. Cohesiveness (for UI/UX)

- The reader's progress card and chapter cards use `controlRadius`, and the done ticks use `Tone.ok` (3ea72b40). The Complete & Continue button is green (clockIn), kept to match A1; it is the only green filled button in the app.
- The builder's block editor uses up/down arrow icon buttons per block. Elsewhere, reordering is drag or chevrons (the screen strip uses chevrons). Worth one rule.
- The video play card is a dark `inverseSurface` panel with "Play video", where images show inline. Fine, but it is a new pattern.

## 4. Suggestions (ranked)

1. Fix InternalRequest's host (section 2). It unbreaks every link that MCP or the bot returns, app-wide.
2. Get the 7 missing Dispatcher how-to videos (Workiz scheduling, job source, rescheduling, ETA call, complaint handling, cancellation, RingCentral). Their topics are text-only until then.
3. Assign the guides by role: only 1 assignment exists, so the Assigned shelf and "overdue" mean nothing yet. A1 targeted technician / dispatcher roles.
4. A "watched" readout for videos, which A1 had (gating was removed in A1 on 08-11): cheap, and shows whether people actually watch.
5. Retire the `A1-Group` name constant in the importer, or take an `--org` id, if any re-import is ever planned.

## 5. Needs Jose

- The A1 training now lives in the organization named **Triplex Group** (renamed from A1-Group), and a new, empty **A-1 Group** organization was created on 11-01. Is the training meant to move to the new A-1 Group, or stay where it is?
- Should Technician and Dispatcher guides be assigned to those roles (it starts due-dates and "overdue" counts), or stay in the library only?
