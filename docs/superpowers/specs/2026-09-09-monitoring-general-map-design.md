# Monitoring — General tab (floor-plan map) — design

Date: 2026-09-09. Status: approved to build (owner decisions below). Owner: Monitoring session.
Parent module: the Monitoring module (PC Specs / Camera Monitoring / Desktop Monitoring already live).

A new **General** sub-tab that shows an organization's machines laid out on a
floor plan: an editor places "computer" tiles on a grid and draws walls, each
tile is assigned to a person, and the tile's colour is that person's live
online/offline status. Clicking a tile opens that person's summary in a right
sidebar; with nothing selected the sidebar shows a summary of the whole plan.

---

## Decisions locked (owner, 2026-09-09)

1. **Tile colours**: green = the assigned person is online; red = assigned but
   offline; grey = no person assigned yet.
2. **Scope follows Org › Company › Franchise.** Plans are scoped exactly like
   `assignments`/`invitations` (the three `assigned_*` columns), so `ScopeReach`
   gives the visibility rule for free: an org-level viewer sees every plan; a
   company-level viewer sees that company's plans and its franchises' plans; a
   franchise-level viewer sees only that franchise's. Multiple named plans are
   allowed (an org/company/franchise may have several offices).
3. **A tile is assigned to a PERSON.** Colour reflects that person's status via
   the existing "seen recently" logic; only people the viewer can see (§3 reach)
   resolve to a status.
4. **Editing** the map (place/move tiles, draw walls, assign people, name a
   plan) needs a NEW `monitoring.map.manage` grant, set per role like every
   other monitoring permission. **Viewing** uses the existing `monitoring.view`.
5. Deferred (named below): a real floor-plan *image* under the grid, pan/zoom
   beyond fit-to-canvas, and live push — v1 polls status.

---

## Architecture

Backend: one scoped table holding each plan as a document + a small controller.
Client: a new `GeneralTab` with an interactive canvas and a summary sidebar.

### 1. Data — `monitoring_floor_plans` (its own migration file)

Columns: `id` (uuid), `assigned_organization_id` (always the org),
`assigned_company_id` (nullable), `assigned_franchise_id` (nullable), `name`,
`layout` (JSON), `created_by` (nullable FK→users, nullOnDelete), timestamps.
`BelongsToTenant`, `HasUuids`. The three `assigned_*` columns are the SAME shape
`assignments` carries, so `ScopeReach::constrain()` narrows a plan query to the
viewer's reach with no new predicate.

- org-level plan → only `assigned_organization_id` set (visible to whoever
  reaches the whole org).
- company-level → `assigned_company_id` set.
- franchise-level → `assigned_franchise_id` set.

`layout` JSON is the whole plan, edited and saved as a unit:

```json
{
  "grid": { "cols": 40, "rows": 28 },
  "walls": [ { "x1": 2, "y1": 2, "x2": 12, "y2": 2 }, ... ],
  "nodes": [ { "id": "n1", "x": 4, "y": 6, "label": "Front desk", "user_id": "..." }, ... ]
}
```

`// ponytail:` walls are pure geometry and nodes are a small list — a JSON
document the org edits and saves whole, not a set of rows. If per-node querying
("which plans is this person on") ever earns its place, nodes promote to a
`monitoring_map_nodes` table then; not before.

**Retention/§9**: a plan is org configuration, not personal data — a node holds
a `user_id` reference (not a person's data). Plain `StatesRetention` /
`forever()`, no `HoldsPersonalData`. Deleting a user leaves a dangling
`user_id` in a node, which resolves to grey (unassigned) — handled at read time.

### 2. Endpoints — `FloorPlansController` (Api/Monitoring)

All under the main `v1` group. Device/heartbeat endpoints are untouched.

- `GET /api/v1/monitoring/floor-plans` — list plans the viewer can see
  (`ScopeReach::constrain`), each with its scope label. Gated `monitoring.view`.
- `GET /api/v1/monitoring/floor-plans/{id}` — one plan's `layout` PLUS a
  resolved `status` map: for each node's `user_id`, the person's name +
  online/offline, computed only for people the viewer can see. Gated
  `monitoring.view`, §3-scoped through the reach.
- `POST /api/v1/monitoring/floor-plans` — create (name + scope + optional
  layout). Gated `monitoring.map.manage`; `ScopeReach::assertCovers()` validates
  the chosen scope is at or below the actor's level. Audited
  `monitoring.map.created`.
- `PUT /api/v1/monitoring/floor-plans/{id}` — replace name + `layout` (the whole
  document). Gated `.manage`, §3-scoped, validates every node `user_id` is a
  person the actor can see (`constrainToVisiblePeople`). Audited
  `monitoring.map.updated`.
- `DELETE /api/v1/monitoring/floor-plans/{id}` — gated `.manage`, §3-scoped,
  audited `monitoring.map.deleted`.

Audit rides the module's existing `auditActionPrefixes: ['monitoring']`, so
`monitoring.map.*` rows satisfy §2.2 from day one.

### 3. Status resolution

A node's colour = its assigned person's status, reusing the same
"seen within the window" logic PC Specs already uses (a person is online if any
of their monitored devices beat within the window). The `{id}` endpoint returns,
per node: `assigned` (bool), `online` (bool), and the person's display name —
resolved ONLY for people the viewer can see; an invisible or deleted assignee is
reported `assigned: false` so it renders grey, never leaking a name.

### 4. Client — `GeneralTab`

- **Plan picker** at the top: the plans the viewer can see, grouped by scope
  (org / each company / each franchise), so the org view lists them all and a
  franchise view lists only its own. Remembers the last-opened plan per viewer
  (local only).
- **Canvas** (fit-to-canvas, no zoom in v1): a `CustomPaint` grid + wall layer
  underneath, computer tiles positioned on top. Tiles are coloured by status
  (green/red/grey) and labelled.
- **View mode** (default; `monitoring.view`): tap a tile → the right sidebar
  shows that person's summary — name, online/offline + "seen X ago", their PC
  specs if their machine runs the agent, and a jump to Desktop Monitoring for
  them. Nothing selected → a general summary of the plan: online / total,
  offline count, unassigned count.
- **Edit mode** (toggle shown only with `monitoring.map.manage`): add a computer
  (drops a tile, snap to grid), drag to move, delete; draw a wall (tap two grid
  points → a segment; diagonals allowed); assign a person to a tile from a
  searchable list of visible people (the same list Desktop Monitoring uses);
  rename the plan; create/delete a plan and pick its scope. Save writes the
  whole `layout` via `PUT`.
- Honesty rule (module-wide): a tile never shows a colour more confident than
  the data — an assignee whose status can't be resolved is grey, not green.
- No `dart:io`; the canvas is pure Flutter (works on web + desktop). No colour
  literals — status colours come from theme tokens (a green/red/grey trio added
  to the theme if not already present, so the ratchet stays clean).

---

## Permissions

- `monitoring.view` (existing) — see the General tab and plans.
- `monitoring.map.manage` (NEW) — edit plans. Add to the `monitoring`
  `ModuleDefinition` actions (add-only); it is a write action, so it is audited
  (covered by the existing `monitoring` audit prefix).

## Testing

- Backend (MySQL gate): a plan created at company scope is visible to that
  company's and its franchises' viewers and to an org viewer, and NOT to a
  sibling company (the load-bearing reach test, two `provisionTenant`/company
  arms); `assertCovers` refuses creating a plan above your level; a `PUT` with a
  node naming a person you cannot see is refused; the `{id}` endpoint resolves
  online/offline correctly and reports an unseen assignee as unassigned; create/
  update/delete each write one `monitoring.map.*` audit row (non-vacuous, like
  CamerasTest); gating: no `map.manage` → create/update/delete refused with a
  sentence; `monitoring.view` still lists/reads.
- Client (`flutter analyze` + `flutter test` + `flutter build web` gate): the
  canvas renders walls + tiles; a tile's colour follows the resolved status
  (green online / red offline / grey unassigned) — with a control case per
  colour; edit controls appear only with `.manage`; assigning a person posts the
  updated layout; 375px has no overflow.

## Deferred (named)

- A real floor-plan **image** uploaded under the grid (v1 is grid + drawn
  walls). — its own slice; the `layout` gains a `background` key then.
- **Pan/zoom** beyond fit-to-canvas.
- **Live push** of status — v1 polls the `{id}` endpoint every ~30s (and on a
  manual refresh); a websocket is out of scope for the whole module until it
  earns its keep (see Remote Control's own note).
- **Per-node history** / a nodes table — only if per-node queries appear.

## Open questions (none block the build)

- Whether a plan should also be pinnable to a specific building/floor label
  beyond its scope — deferred; the `name` carries that for v1.
