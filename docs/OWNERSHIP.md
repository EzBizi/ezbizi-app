# Tenant and ownership contract

The hierarchy is Organization → Company → Sub Company → Franchise. Parent relationships are write-once. Build plan §3 and §4 remain the architecture reference.

| Concept | Meaning | Source |
|---|---|---|
| Tenant identity | Organization whose data this request/job may access | Authenticated actor and `TenantContext` |
| Reach | All places the actor has assignment authority | `ScopeReach`, user assignment methods, `/me.companies`, `/me.franchises` |
| Current view | Subset displayed by the initiating client | Validated `X-EzBizi-View`, `ViewScope` |
| Record owner | Organization and explicit scope persisted on the resource | Owner columns and module policy |
| Permission | Action allowed by release flag, entitlement and role | Module gate and policy |

All applicable checks must hold. Organization resources can be inherited into company/franchise views for reading; authority never flows upward. A company view can manage authorized descendants, never its parent or a sibling. Reach is an upper bound, not the default place to file.

## Requests and clients

New clients send `X-EzBizi-View: organization`, `company:<id>` or `franchise:<id>`, including on multipart requests. The server refuses malformed or inaccessible explicit views. A header applies only to its authenticated actor, not other users resolved internally. Organization view still requires hard assignment authority for organization writes.

Installed clients without the header retain the validated `navigation.scope` preference fallback. New sessions initialize from that preference, but another device changing it cannot redirect an explicit-view request. Session generations discard delayed replies after logout, account changes and view changes. All authenticated transports handle unauthorized responses, including staff sessions.

Only the top-bar switcher lists full reach. Add/Connect panels, defaults and other selectors use `companiesInView`, `franchisesInView` and `filingCompanyId`. Test with a sibling in `/me`, even for an organization administrator. Inventory/Fleet/Phone are worked examples; backend checks remain mandatory.

## Writes and exceptions

Use `OwnedScope::mayWrite(User, organizationId, scopeType, scopeId)` for owned resources alongside module permissions. It intersects same-tenant active-user authority with current view and persisted owner. Never infer owner from visibility, the first reachable company, or the actor's preference. Missing company/franchise owner IDs must refuse, not become organization ownership. Null IDs are valid only where an explicit organization-scope model contract defines that representation.

Customers deliberately share organization-wide semantics and retain an owning-franchise accountability tag. Their dedicated policy and relationships define access; do not mechanically replace them with the owned-resource helper. Shared customer visibility does not authorize changing separately owned jobs, stock, mailings or connections. Document and test other exceptions beside their policies.

Apply view filters before pagination, totals and exports. Out-of-view lookups should not reveal sibling existence. Reading an inherited audience or connection does not authorize changing its owner.

## Delayed work and concurrency

Persist tenant, scope and selected account when admitting work. Scheduled newsletters use their stored connection, never the first account found later. Automation events retain original subject scope after deletion. Workers enter `TenantContext` before tenant reads/writes and validate saved authority where required; request headers and current preferences cannot relocate delayed work.

Serialize competing transitions on the same parent in the same lock order: newsletter admission/edit/delete, inventory cancellation/movement and provider callbacks. Durable action outcomes and stable idempotency identity prevent successful actions repeating on retry. An unknown provider send outcome requires checking the account before another send.

## Negative regression matrix

| Scenario | Required result |
|---|---|
| Foreign tenant resource/account | No cross-tenant read or write |
| Company view with reachable sibling in `/me` | No sibling filing, mutation, export or pagination leak |
| Franchise view of inherited parent resource | Read may succeed; upward write refuses |
| Organization view with lower-level assignment | Organization writes still refuse |
| Company and subcompany | Downward write requires reach; child cannot write upward |
| Explicit malformed/inaccessible header | Refuse instead of widening |
| Legacy header absence | Preserve validated preference fallback |
| Two clients changing preferences | Explicit requests retain their initiating views |
| Missing owner fields, wrong tenant, deleted owner | Refuse incomplete ownership; never broaden it |
| Logout/view change before delayed response | Response cannot repopulate or relocate session |
| Disconnect/deletion before scheduled work | Retain saved identity; scoped event or explicit failure |
| Concurrent submit/callback/cancel | One valid transition, no duplicate side effect |

Assert persisted owners and recipient/action targets, not only status codes. Include authorized positive cases so blanket refusal cannot masquerade as correctness.
