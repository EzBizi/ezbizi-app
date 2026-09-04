# Newsletter is ready to merge — `session/marketing` at `5257d904`

From: the Marketing session (branch `session/marketing`, whatever it is called today)
To: the deploy desk
Date: 2026-09-04

Sent as a file rather than a message because `ezbizi-a1`, `ezbizi-5b` and the
`local_a7c13d4e` Deployment session are all unreachable, and MODULE-OWNERS.md
says plainly that there is no role address on this machine. This survives the
session that wrote it; a message would not.

## What it is

Newsletter has a screen. Jose has not seen any of it — the Integrations tab he
asked about this morning is in `09f5a946`, and `main:client/lib/screens/
settings_shell.dart` has zero occurrences of it, which is why his screenshot
shows three chips. It ships the moment this merges.

Two commits on top of what is live:

- `09f5a946` — the Newsletter screen (layout 3: blocks left, the real email
  right, rendered by `BlockRenderer` in the Email module's sandboxed iframe),
  the block editor with columns, HTML mode, and Settings › Integrations.
- the one above `5257d904` — Newsletter split into **Dashboard · Composer ·
  Settings** chips, a month calendar carrying every scheduled mailing (each
  opens in the composer until it goes), a `stats` block on `GET /newsletters`
  counted over the whole set rather than the fifty-row page, and the Newsletter
  Settings chip that connects the mailing account. Main merged in; both sides
  re-run after the merge.

## Flags

| | |
|---|---|
| Migration | **none** in these two. The four newsletter tables are already live. |
| Wire shape | **additive only.** `GET /newsletters` gains a `stats` block beside `sending`. Nothing removed or renamed. |
| Permissions | **none new here.** The five `newsletter.*` keys landed with the backend. Non-admin roles need `newsletter.view` granted on Roles or the chip disappears; administrators hold it implicitly. |
| Dependencies | none new. |
| Scheduled commands | none new. `ezbizi:send-due-newsletters` already runs every minute; the dashboard makes its work visible. |
| `client/VERSION` | **not touched.** Yours. Release-note text below. |

## Release note — written from the code, placed against whatever version you cut

```
'Newsletter: write a mailing in blocks or as HTML, with the real email '
    'shown beside the editor as you type. It sends through the mailing '
    'account your company connects — Mailchimp — so it goes out on your '
    'own list and your own sending reputation, not EzBizi\'s.',
'Newsletter opens on a dashboard: what has gone out, what is waiting, and '
    'a month calendar of everything scheduled. Tap a scheduled mailing to '
    'open and edit it until it goes.',
'Settings has an Integrations tab listing every account this view is '
    'connected to — social, mailing, advertising — and where each is '
    'managed.',
```

## Verification, all watched

Client 65/65 across `marketing_screen_test` and `settings_screen_test` (the
Settings shell changed). Backend 31/31 on Newsletter + MarketingModuleTest;
73/73 on the wider Marketing set earlier. Mutation-checked on both commits —
six separate deletions, each turned exactly the test that names it red and
nothing else.

Two layout bugs the tests caught before Jose did: `CrossAxisAlignment.stretch`
on a month grid inside a Column hands every cell infinite height, and a stat
label overflowed a 228-point card by exactly 30. Both fixed.

## One open item, deliberately not in this commit

`GET /api/v1/marketing/newsletter` is orphaned: nothing reads it, and since the
newsletter triggers moved to their own module it now returns *social* triggers
under a route named "newsletter". A wire-shape removal deserves its own commit.

## Before your merge commit into main

`./scripts/index-landmines.sh`. My worktree has its own index; the merge from
the shared tree does not.
