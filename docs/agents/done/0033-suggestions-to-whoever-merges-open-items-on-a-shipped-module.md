# Suggestions is live, and four things about it are still open

**To: whoever merges or deploys next. From: the Suggestions session.**

This file exists because the last two times my work moved, it moved without me.
`session/suggestions` was handed over **unmerged, with a stated
must-fix-before-merge precondition**, and was merged (`e718748a`) and deployed by
somebody who never saw that precondition — because I had written it in a chat
message and a git-ignored ledger, neither of which a merger reads. It reached
customers with the defect open. The fix (`600b1623`) then merged and deployed the
same way, silently, which was the right outcome by luck rather than by process.

So the open items are in a committed file this time. Borrowed from the Marketing
session, who reached the same conclusion from the same failure.

## Closed, do not re-open

- **The §9 inventory leak.** `GET /api/v1/exports/inventory` published an
  organization-wide count of a table whose own purpose sentence promises nobody in
  the organization can read it. Fixed by `Suggestion::narrowToOrganization`, and
  the resulting under-report is now disclosed in `DataInventory::caveats()`.
  Verified live: `600b1623` is an ancestor of production `3f7f222b`.

## Open — none of these is mine to decide

1. **`TabBarLike` scrolls with a seventh Development chip.** One line, but it is a
   widget shared with Automations and it lives in a file design owns. Jose has not
   ruled. Do not "tidy" it in passing.
2. **A note can be attached to a `reviewing` decision through the API**, and the
   author would read it with **no notification** — `reviewing` is silent by design.
   Contained today only because the UI offers no path. **Do not close this in the
   backend without Jose**; it is deferred deliberately, not overlooked.
3. **`docs/UI-DESIGN-QUEUE.md` asks that the note field not be *offered* for
   "Mark in review".** The shipped screen offers it and now refuses rather than
   discarding, which closes the data loss but not the requirement. Arrangement, so
   design's call.
4. **Layouts 1 and 2 reached production before Jose reviewed them.** He delegated
   the choice to me for one night on the understanding we would revisit; it shipped
   in between. If he changes them it is a release, not a rebase.
   `docs/previews/suggestions-1.html` and `suggestions-queue-2.html`.

## The one that will bite somebody else

**Suggestions is deliberately absent from `ModuleRegistry`.** That absence is the
entire mechanism stopping an organization removing it — roles, `ModuleAvailability`
and the client rail are all keyed off a registry entry. Registering it would hand
every organization the ability to switch it off and **nothing would fail**. A test
fails if you try; read it before you assume it is an oversight.

And the corollary that already cost one defect: **registry absence does not keep a
module out of every privacy surface.** The §9 inventory is derived from
`HoldsPersonalData`, exports from `ResourceMap`. Three separate roots. Reasoning
about one says nothing about the other two.

## If you are checking whether this shipped

`git merge-base --is-ancestor <sha> main` at the moment you make the claim, not a
reading carried forward. I told the website session this module was unmerged —
accurate when I measured it, false by the time they committed a `WEBSITE-CLAIMS.md`
row on my word. Line numbers drift too: a handover of mine cited `main.dart:724`
and the deployed tree has it at `:687`.
