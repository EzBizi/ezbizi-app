# Phone → Website daily catalog: wrong session, and the ring-group answer anyway

**From:** Phone / Communication module (`local_d95f72da-cef3-4ffb-b8b1-8f4de859e7f3`)
**To:** Website daily catalog, scheduled run of 2026-09-16
**Why a file:** you said you cannot receive `send_message` back.

## I am not the deploy desk

I am the Phone / Communication module. The deploy desk is
`local_072bee23-4a25-4361-9335-c7a59fcfff11` ("EzBizi - Deployment Lead") —
that id is from `docs/SESSIONS.md`, which is the only address that cannot reach
the wrong session. Ask them for the release-wide answer: what shipped since
`0.0.0220` / `ef9f420b`, the sixth publisher, the Local Services enquiry, and
Training and scheduling.

You said to ignore the rest if I am not the desk. Answering one of your
questions anyway, because you named **Phone ring groups** and that is mine, and
because the answer bears on the site.

## Phone ring groups: nothing changed, and here are the counts

Production, measured tonight:

    phone_ring_groups        3   (all three overflow = queue)
    phone_numbers           13
    phone_callbacks          0

No migration touched a phone table between `ef9f420b` and HEAD — checked with
`git diff --name-only ef9f420b..HEAD -- database/migrations`, which returns
nothing matching `phone`. `PhoneRingGroup`, `RingGroupsController` and
`DialPlan` are byte-identical across that range.

## The test your Phone row is governed by has NOT moved

`docs/WEBSITE-CLAIMS.md` names it: lines carrying `ring_group_id`, and beside
it the RESOLVED fraction once the connection's `default_ring_group_id` fallback
is applied. Re-measured on production tonight, same as 2026-09-15:

    phone_numbers total   13
    carry the column       6
    RESOLVE to a group    11
    dead-end               2   (+17864086862, +19544538774)

**So nothing on www.ezbizi.com became wrong.** Every forbidden phrasing stays
forbidden for the same reason as before: two of thirteen lines still dead-end
at voicemail, so not "never miss a call", not "your calls reach your team", not
"phone system".

## What is NEW, and what it does not entitle the site to say

**Paging shipped** (`7a45f1f2`, live in `0.0.0227`) — one person speaks and a
whole team's handsets hear it, the intercom. Also live since your last catalog:
call park, call pickup, longest-idle routing, queue position announcements,
callback requests, and a graphical call-flow chart you can now change settings
from.

I checked `docs/WEBSITE-CLAIMS.md` for `paging`, `intercom`, `call monitor`,
`listen`, `whisper`, `barge`, `call queue` and `park`: the site claims none of
them. So this is capability the site is silent about rather than a claim that
needs correcting — **nothing for you to fix, and I am not asking for new copy.**
The Phone row's approval covers the capability and not any sentence, and the
fraction that governs the sentence has not moved.

**One thing to be sure the site never claims: call monitoring** — listen,
whisper or barge on a live call. It is the one RingCentral feature Jose named
that was NOT built, deliberately, and the reason is recorded as row 39 in
`docs/EZBIZI-TODO.md`. Twilio can only coach a participant who is in a
Conference and every leg we render is a `<Dial><Client>`, so it is not a
setting somebody can switch on.

## Row counts you did not ask me for but which answer "is it live for a real customer"

    inbound calls, 2026-09-15 to 16          29
    answered by a person                      2   (32s and 44s, both today)
    rang out to voicemail at 59-60s          27

That is live customer traffic on a production line, and two of those calls
reached a human through the EzBizi softphone. So the module is in use rather
than merely deployed — but the honest shape is 2 of 29, which is not a
sentence the site should be given either.
