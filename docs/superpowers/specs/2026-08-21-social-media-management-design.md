# Social Media Management — design

**Date:** 2026-08-21  
**Status:** layout **locked 21 Aug**. Preview: http://localhost:8790  
**Product:** EzBizi customer app (`app.ezbizi.com`) · Marketing › Social Media Management

This is a new module. It does not copy A1 Tools code. Connections follow Email (tenant signs in as themselves). Compose is one post to many targets. Schedule is wall-clock time in the **franchise timezone** (TODO #20). App Review videos must walk the real UI: connect → compose → publish/schedule → see it in the queue.

---

## 1. What it is

A company connects the pages and profiles it posts as, writes a post once, and sends it to the targets it picks — now, or on a schedule.

| Surface | What it is |
|---|---|
| **Posts** | The dashboard. Pages sidebar · composer on the left · **calendar on the right**. |
| **Accounts** | Connect, disconnect, grants. Like Email › Settings. A connection is created at the **view you are in**. |

The sidebar already says Marketing. The chip strip names Social Media Management (and Newsletter). Do not print “Social Media Management” again as a page title above the chips.

---

## 2. What it is not

- Not a social network. Not ads, not Messenger, not inbox replies, not “listen to mentions.”
- Not EzBizi’s own Page. The tenant’s Page, the tenant’s login.
- Not a personal Facebook wall (Meta does not offer it). Instagram consumer accounts cannot publish.
- Not a second application. Calendar lives **on the Posts tab**, next to the composer, not as its own product.
- Not stored copies of every remote post forever. We store **our** compose and **our** send attempts. Remote permalinks are kept when the network returns them.

---

## 3. Four gates (StartUp.md §4)

```
release flag → entitlement → permission → assignment
```

Plus the Email leftover, which is not a fifth gate but a **provider readiness** check:

| Gate | How it shows up here |
|---|---|
| Release flag | Not used in v1. The tab exists when Marketing is on. |
| Entitlement | Subscription includes Marketing. Lapsed org is read-only like everything else. |
| Permission | `marketing.view` draws the tab. Connecting needs `marketing.edit` (or a dedicated `social.connect` if we split — **do not split in v1**; Email did not). Publishing uses the same edit. |
| Assignment | XOR scope, **same rule as inboxes (v0.0.0041)**. See §3.1. |
| Provider ready | `notReadyBecause()` on the provider. No Facebook app env → Connect is refused with a sentence, not a 500. |

A role may be granted **which connections** it can post as, the way inboxes are granted. Administrator holds every connection in reach. Other roles see a connection only after a grant.

### 3.1 Visibility — locked (owner, 21 Aug)

A Facebook Page added on **A-1 Chimney** is listed on A-1 Chimney and on **A1 Group** (organization view, because the group owns A-1 Chimney). **Premier Garage does not see it and cannot post as it.** Sibling companies never share a connection. Authority does not flow **up** or **sideways**.

| You are viewing | You see |
|---|---|
| Organization (A1 Group) | Org-level pages, plus every company’s and franchise’s below |
| Company (A-1 Chimney) | Org-level + that company + its franchises. Not Premier Garage |
| Franchise (e.g. Georgia) | Org-level + parent company + that franchise. Not Illinois |

Connecting while the switcher is on A-1 Chimney **binds the connection to A-1 Chimney**. Connecting on A1 Group binds it to the organization (every company can use it). Connecting on Georgia binds it to that franchise only.

Same query discipline as inboxes: filter by current view-scope on **both** Accounts and the Posts sidebar. A Premier user with `marketing.edit` still cannot target A-1 Chimney’s Page.

---

## 4. Catalogue (code, not tenant-invented)

Nine names already on the shell. v1 **first wave** is the five App Review needs:

| Key | What you connect | v1 |
|---|---|---|
| `facebook_page` | Facebook Page | yes |
| `instagram_professional` | Instagram professional (via Facebook Login when linked to a Page) | yes |
| `threads` | Threads profile | yes |
| `linkedin` | Company Page and/or member, as the LinkedIn app allows | yes |
| `tiktok` | TikTok account (Login Kit + Content Posting) | yes |
| `pinterest` | Pinterest (Trial already approved) | **first available** — Connect built 21 Aug |
| `youtube` | YouTube channel | after |
| `x` | X | after (paid API) |
| `bluesky` | Bluesky | after (no console) |

A provider the Cloud/Meta app is not ready for stays in the catalogue and says why. It is not hidden: hiding it looks like a lost permission.

Personal + business: offer **both** only where the API allows a distinct login (LinkedIn member vs company). Facebook is Page only.

---

## 5. Layout (locked 21 Aug)

Preview: http://localhost:8790

Marketing chips: Newsletter · **Social Media Management**. Inside SMM, two chips:

```
[ Pages sidebar ] [ Composer ] [ Calendar ]
```

| Pane | Width | Job |
|---|---|---|
| Pages | ~220px | Connections **in this view**. Checkboxes are the publish targets. Connect at the bottom. |
| Composer | flex | One post. Publish now / Schedule. Disabled with a sentence if nothing in the sidebar is usable. |
| Calendar | ~340px+ | **Weekly Calendar** (this week, no arrows) on top, **Monthly Calendar** below with previous/next, in the **view’s** timezone. Day cells are square, sized to fit the pane so the whole month is on screen. Drops from Schedule land here, one chip per target. |

**Accounts** chip: catalogue + connect + disconnect. No composer.

375px: pages become a dropdown, composer full width, calendar becomes a list under it. No second app.

No modals for connect. **SidePanel**, OAuth popup, same as Google mail.

Earlier options A–G are discarded. Do not ship a Queue-only tab: the calendar **is** the schedule surface. Failed sends show on the calendar chip and in a one-line activity under the composer.

---

## 6. Compose rules

- One body of text. Per-network length is shown as a remaining count on each selected target (X is shorter than Facebook). Over-limit on a target **blocks that target**, not the whole send.
- Images/video via FileStore. A network that cannot take the asset is unchecked with a sentence.
- **Publish now** and **Schedule** are two buttons. Schedule asks for a local datetime; stored as wall-clock in the **target connection’s franchise timezone**. If the connection is at company/org, use the franchise the user is viewing, else the company’s first franchise — **no**: viewing scope’s franchise if the switcher is on a franchise; if on a company, pick timezone from that company’s default franchise (existing settings). Document in code next to `FranchiseWallTime`.
- A send is **per target**. Facebook can succeed and TikTok fail. The queue shows each.
- Idempotency: a retry of the same target does not double-post (claim a run row first, like automations).

---

## 7. Data (shape, not migration SQL yet)

| Table | Role |
|---|---|
| `social_providers` | Not a table. Enum in code, like `MailboxProvider`. |
| `social_connections` | One connected page/profile. XOR scope. Encrypted tokens. Status `connected` / `broken` / `revoked`. |
| `social_connection_grants` | Role may post as this connection. |
| `social_posts` | The compose (body, files, created_by, view-scope). |
| `social_post_targets` | Connection + wall-clock `scheduled_for` + status + remote id + last error sentence. |

Tokens never in a response. Never in the repo.

---

## 8. App Review video (acceptance of the UI)

A recording of the **real** Flutter UI, sandbox logins, that shows:

1. Marketing › Social Media Management.
2. Accounts empty, Compose refused with a sentence.
3. Connect Facebook Page (OAuth popup).
4. The Page listed at this company.
5. Compose a post, that Page checked, Publish now.
6. Queue shows Sent (or Scheduled) for that Page.

Until a network is in production access, the video uses that network’s **sandbox / test user**. Do not wait for Meta Tech Provider to build the screens.

---

## 9. Out of v1

GBP (parked). Ads. Messenger. Comments. Insights. Cross-posting into Newsletter. Bluesky/X until those consoles exist. A week calendar unless Option C is chosen.

Automations: declare `social.published` / `social.publish_failed` when the first send lands (`isRaised: false` until then). No social **actions** in v1 (we do not post from a rule until the poster is trusted).
