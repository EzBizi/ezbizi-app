# Mac store screenshots are up. Seven of them, and here is why not eleven.

From: the Apple session
To: the deploy desk
Date: 2026-09-04

Your pipeline worked. `scripts/store-screenshots.sh` needed nothing from me
except the file you could not write, and everything below is what happened
after that.

## What is on the version page now

macOS App Version 0.0.0150, **7 of 10 Screenshots**, saved. Verified by
reloading the page after saving, not by the button going grey — the count
survives a fresh load, so it is on Apple's side and not in a form buffer.

Build 0.0.0150 (150) is still the attached build. I did **not** touch
**Add for Review**. Jose gates submission.

    01-sign-in     02-dashboard   03-customers   04-jobs
    06-inventory   07-fleet       11-hr

## The file you were blocked on

`~/ezbizi-signing/screenshot.env` exists on the relay now. Same handling as
the Android key: the password came out of the credentials file on this
machine, went over stdin, and was checked by length only. It is not in the
repo, not in a transcript and not in this note.

## Two changes to the driver — `159f834a`

`client/integration_test/store_screenshots_test.dart`, both found by looking
at the PNGs rather than at the exit code.

**Scope.** Your first run was not capturing the demo tenant, it was capturing
one franchise of it: `Northgate Garage Doors > Northgate - Orlando`. That is
why the dashboard read 'Nobody on the books yet' and Customers listed three.
The data was never thin, the window onto it was. The driver now opens the
scope switcher and taps the organization row before it captures anything, and
`ScopeSwitcher` needs its own import — `main.dart` imports scope_switcher.dart
but does not re-export it, so `app.ScopeSwitcher` does not resolve.
`NavigationSidebar` is different, that one is declared in main.dart.

It is deliberately best-effort. Switcher missing, row missing, either one — it
prints a line and carries on capturing the narrow view, because a narrow
screenshot beats no screenshot.

**Sign-in on desktop.** You skipped it, reasoning that a login page sells
nothing on a Mac listing. That reasoning is sound and I would have made the
same call; Jose asked for it, so it is back. It also turns out to be the
strongest frame in the set — the left half of that screen is a product hero
with a chart and this week's numbers, not a bare form, so 2.3.3's 'not merely
the title art, login page, or splash screen' is not in play.

Only `client/integration_test/store_screenshots_test.dart` is in that commit.
`scripts/` is yours and I did not touch it. `index-landmines.sh` after the
commit: 0 stale, 0 live, nothing staged.

## The four I dropped, and what they are actually telling you

Not pipeline faults. Every one of them is the demo tenant having no data:

| Frame | What it showed |
|---|---|
| `05-inspection` | 'Search for a client by name, or a job by its number, to start.' |
| `08-scheduling` | 'Search a zip code or an area name to see its week' |
| `09-communication` | 'No phone lines yet. Add one in Settings.' |
| `10-marketing` | Newsletter tab, **Not built yet** badge, a page of what it cannot do |

They are in `Distribution/Apple/screenshots/macos/rejected/` rather than
deleted, so the next person can see what was rejected and why.

Three of those are worth fixing on the server, and the fix is worth more than
the screenshots: Inspection and Scheduling both open on a **search prompt with
no default listing**, which is a real first-run experience for a real customer,
not just a demo gap. A tenant with thirteen customers and twenty-four jobs
opens Inspection and is asked to search. Worth someone's time independently of
the store.

`10-marketing` is different — that one is honest. Newsletter genuinely is not
built. Leave it.

## Also for you

`b8d1015` — the `.gitignore` fix for `store-screenshots/` — still needs to
ride a deploy. It was root-anchored and `flutter drive` runs in `client/`, so
the frames were never ignored at all. I told you twice that they were and I
was wrong both times.

## Still Jose's, not ours

- **EU trader status** blocks any submission, macOS included. Account Holder only.
- **`ITSAppUsesNonExemptEncryption`** — the macOS build shows Missing Compliance in TestFlight.
- **App Review Notes** claims 'requests no device permissions at all'. True when written for 0.0.0129, false from 0.0.0138 on now that calling ships. It needs amending before the next submission of either platform, and Notes is editable at any time.
