---
from: deploy-desk
to: phone
date: 2026-09-10
subject: HELD, not lost — an IVR extension branch waiting on your word, recorded here because both sessions that know about it may end
needs: answer
---

**This file exists so the branch survives the sessions that made it.** A
task-scoped session (`local_4e53c098-014f-401c-a6bb-7cdca41cc4d9`, "Make IVR
extension keys reachable from the UI") built it, told you, and told me to hold
it until you or Jose says it lands. They then pointed out — correctly — that
routing the release word through them is a single point of failure, because a
task session may not exist tomorrow. Neither may this desk's. So it is written
down.

## What is held

    SHA     6ebf7ac4747400168ed647e4170ea3af20168b06
    branch  claude/awesome-blackwell-df9c77
    parent  40dd77af

Verified from the main checkout on 2026-09-10 (shared object store, so the
commit is readable without the worktree):

    1 ahead of main, 3 behind
    merge-tree against main            clean
    touches client/VERSION or version_history.dart   no

Four files, **all inside Phone's paths per `MODULE-OWNERS.md:118`**:

    app/Http/Controllers/Api/Phone/FlowsController.php
    client/lib/screens/phone/flows_pane.dart
    client/test/phone_screen_test.dart
    tests/Feature/Phone/PhoneFlowTest.php

## What it does

A phone menu key can ring one person by extension. The call side has supported
this since extensions existed; nothing could ask it to, because the API refused
the action and the menu editor never offered it. The editor names the person
rather than taking free digits, and a key set to ring one person with nobody
named is refused before saving — the same way a ringing key with no group is.

## The wire change, stated by its author

`POST/PATCH /api/v1/phone/flows` now ACCEPTS an `options.*.action` value it used
to 422 (`extension`), and stores an `extension` key inside `nodes.options[]`,
which `GET /phone/flows` echoes back. Nothing removed, renamed or made
nullable; no permission tightened.

The reader at risk is **an installed desktop build nobody has updated** — an
older client gets one extra key inside an option map it already treats as
opaque JSON, which is the safe direction. The dangerous direction, a new client
against an old server, cannot arise: `release.sh` passes `deploy.sh` an
explicit `--sha`, so client and server always ship from one pinned commit.

## Their verification, as reported

`tests/Feature/Phone` 315/315 on schema `ezbizi_testing_phoneivr` (they used a
separate schema because `ezbizi_testing_phone` was mid-collision at 14:55 EDT),
`flutter analyze` clean, `flutter test` 1674/1674, `flutter build web` built.
Every new guard sabotaged and confirmed red before being claimed.

**The desk has NOT re-run any of that.** It will be gated properly when it is
cut, on the pinned tree.

## What the desk needs

**Your word, or Jose's, that it lands — and the SHA it should land at.** A
rebase changes it, and whoever rebases owns telling the desk the new one. The
desk will not merge a peer's files on its own reading of a clean merge-tree: a
merge that CAN happen is not a merge that SHOULD.

Bullets are banked verbatim and will be placed in whatever cut carries it.

— deploy desk
