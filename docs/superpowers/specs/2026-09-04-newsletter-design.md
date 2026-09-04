# Newsletter — design

**Owner decisions, 2026-09-04 (Jose).** Layout **3** from
`docs/previews/newsletter-layouts.html` — edit on the left, the real email on the
right, always visible. Block editor **with columns**. HTML mode. Live preview.
Mailchimp is a **per-company connection** the tenant brings themselves. Settings
gains an **Integrations** tab that connects and lists every connection in one
place. Newsletter stays reachable with no Mailchimp connected; **Send** is refused
with the server's sentence.

---

## 1 · Why Mailchimp is a connection and not a transport

EzBizi does not send the newsletter. The tenant's own Mailchimp does.

That is not a workaround for the SES sandbox, though it does step around it. It is
the honest ownership line: a mailing to a company's customers goes out on **that
company's** sending reputation, under their from-address, against their list and
their provider's plan. EzBizi composing it does not make EzBizi the sender, and
nothing here should make one tenant's mailing able to damage another's
deliverability — which a shared EzBizi transport would.

Consequences, stated so nobody has to rediscover them:

- The SES sandbox is irrelevant to this module. `MAIL_MAILER` is irrelevant too.
- No tenant needs their domain verified by us for a newsletter. `MailDispatcher`'s
  `asTenant()` path stays for platform mail; the newsletter does not use it.
- When SES leaves the sandbox, "send through EzBizi" becomes one more
  `NewsletterSender` implementation beside Mailchimp — not a rewrite.

## 2 · The connection reuses `social_connections`

`Mailchimp` becomes a `SocialProvider` case. The table is misnamed for it, and it
is still the right home: XOR scoping (organization **or** company **or**
franchise), encrypted credentials that never reach JSON, `SocialConnectionAccess`
view filtering, disconnect with an audit row, and the sibling-company rule are all
built and tested there. A parallel `newsletter_connections` table would be a
second copy of §3 and §4 with its own bugs.

The precedent is already set: Google Business Profile, Google Ads and Thumbtack
live there as `dataSources()` — accounts EzBizi READS from — and are filtered out
of the Marketing catalogue so nobody is offered "post to Google Ads".

Mailchimp is a third kind: an account EzBizi **sends through**. So:

```
isPublishingNetwork() = not a dataSource AND not a sendingProvider
```

`SocialProvider::all()` stays filtered to the nine publishing networks, which is
what `GoogleConnectPermissionTest` holds. Adding Mailchimp must not change that
number, and the test will say so if it does.

**Connect is an API key, not OAuth.** Mailchimp keys carry their datacentre as a
suffix (`…-us14`), which is what names the API host, so a key is sufficient to
reach the account and a wrong one fails immediately and legibly. This matches the
`connectsBy: 'password'` path Bluesky already uses, so the Connect panel needs no
new shape. OAuth is a later swap behind the same connection row if it is ever
wanted; nothing above this decision depends on which was used.

## 3 · Permissions — a submodule, drawn inside Marketing

`newsletter`, `parent: 'marketing'`, actions **view · create · edit · delete ·
send**.

`send` is a permission of its own, and that is the point of the split: writing a
mailing and putting it in front of twelve hundred customers are different acts,
and plenty of organizations will want a person who can draft but not send. It is
the same reasoning that separates `inventory.financials` from `inventory.view`.

Following `leads`: `parent` says where a module is DRAWN and nothing else. These
are new keys, granted by nobody until an organization grants them, per §4.

## 4 · Ownership — §3, and filing on the view

Every newsletter, audience and connection is XOR-scoped to organization, company
or franchise, exactly as connections and mailboxes already are.

**Filing follows the VIEW, never reach.** Standing in A-1 Chimney, a newsletter
files on A-1 Chimney; Everdoors is not in the list even though the administrator
can reach it. `filingCompanyId` / `companiesInView`, never a `me.companies` loop
or `.first`. This has leaked in Inventory, Fleet and Phone; it does not leak here,
and the proof is a widget test with a sibling company in `/me`.

Reading follows `SocialConnectionAccess`'s existing rule: an organization-level
newsletter is visible down into a company and a franchise; a sibling company's
never is.

## 5 · Automations

`newsletter.sent` and `newsletter.unsubscribed` are declared today with
`isRaised: false`. Both become **raised**, because both now fire:

- `newsletter.sent` — one per newsletter, when the provider accepts it. It carries
  which newsletter, which audience, and how many addresses it went to.
- `newsletter.unsubscribed` — when somebody leaves, from our own unsubscribe or
  from a provider webhook. It carries the address and which audience.

`ModuleRegistryTest` holds the `isRaised` claim to the source, so the flags and
the emissions cannot drift apart.

The existing declared action `newsletter.add_to_audience` becomes real: it is what
makes "a new customer joins the mailing list" a rule somebody can write.
`newsletter.send` stays declared and unimplemented — a rule that mails twelve
hundred people with nobody reading it first is not a feature.

## 6 · Data

| Table | Holds |
|---|---|
| `newsletters` | subject, `blocks` JSON, scope, status, `scheduled_for` + `scheduled_timezone`, audience ref |
| `newsletter_audiences` | name, scope, kind (`customers` \| `mailchimp`), `mailchimp_list_id` |
| `newsletter_unsubscribes` | address + scope — honoured across every audience in that scope |
| `newsletter_sends` | one row per send: provider, remote campaign id, recipient count, status, failure sentence |

`newsletter_sends` is one row per newsletter rather than per recipient, because
the provider owns per-recipient delivery. That is the difference from
`social_post_targets`, where EzBizi genuinely sends once per account.

**Unsubscribe is scoped, not global.** Leaving A-1 Chimney's list must not remove
somebody from Premier Garage's, and one tenant must never learn who unsubscribed
from another.

Wall-clock scheduling reuses the social pattern exactly: the time is stored as
typed with the clock beside it and resolved fresh, per TODO #20.

## 7 · The editor

Blocks: heading, text, image, button, divider, spacer, and a **row of columns**
holding blocks of its own. Stored as JSON; rendered to table-based HTML on the
server, because an email that only survives one client is not a newsletter.

HTML mode swaps the left pane only, so the rendered result stays visible beside
the markup — which is what an HTML mode is for. Editing raw HTML is one-way per
block: once hand-edited, that block keeps its HTML rather than being reconstructed
from fields, and says so.

Colours come from the theme. A newsletter cannot drift off-brand by accident.

## 8 · Not in this pass

Per-recipient open and click tracking (a decision about watching customers that
nobody has made — see the Marketing module's own note on why "Opened" is not a
declared trigger), A/B subject testing, and drip sequences.
