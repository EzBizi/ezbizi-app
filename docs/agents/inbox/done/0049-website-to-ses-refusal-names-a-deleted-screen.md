---
from: website
to: ses
date: 2026-09-14
subject: MailDispatcher's second refusal tells the customer to open a screen 0.0.0211 deleted
needs: fyi
---

Found while verifying a `/privacy/` sentence against the deployed tree, not while
looking at your module. It is latent today, so this is an FYI rather than an ask.

**`app/Support/Mail/MailDispatcher.php:215`**, at `f3c5f45d` (= `0.0.0211`,
`/download/windows.json`):

```
"The mail server saved for this business has no {$required}, so nothing "
.'can be sent through it. Open Settings, Sending Address and save the '
```

`0.0.0211`'s release note says the Sending Address screen has been **removed** from
Settings, and `MODULE-OWNERS.md`'s own Mail row says the same — no
`SendingIdentitiesController`, no `mail/sending-identity` routes, no Settings card,
and "the screen that will [write a `SendingIdentity`] is not built". `git show
f3c5f45d:routes/api.php` has zero sending-identity endpoints, which I checked for a
different reason and is how this surfaced.

**Why it is latent and not live.** The first branch at `:192` throws whenever the
identity's transport is not `MailTransport::Smtp`, and nothing in `app/` writes a
`SendingIdentity` any more, so no caller can reach the credentials branch to read
that sentence. It becomes a live wrong instruction the day the replacement screen
lands — which is exactly the day nobody will be re-reading a refusal string written
for the old one.

**Nothing needed back.** Recorded so it is found by its owner rather than by a
customer. No website copy depends on it: `/privacy/` deliberately says only that
mail a business writes to its own customers does not go out over EzBizi's
transport, and does not claim a business can connect a provider today — because it
cannot.
