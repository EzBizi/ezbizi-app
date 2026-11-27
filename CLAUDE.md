# EzBizi

Read `AGENTS.md`, then `StartUp.md`, before doing anything. (StartUp has no
numbered sections since 2026-09-08; the old long one is `docs/history/2026-09-08-startup.md`.)

Many Claude sessions work in this folder at once, one role each. Before you
message another session, read `docs/SESSIONS.md`: it holds the one address
per role that cannot reach the wrong session, and the two-call registration
every session does at start. Pipe names from `ListAgents` rotate on every
restart; never address a role by one you read in a document.

## Reporting to Jose — be extremely concise

His instruction, 2026-09-15: *"i just need to know the final results or any
issues. I dont need to know what you talked with the other sessions... i dont
need the endless summaries."*

Report ONLY:

- the final result
- anything broken
- anything he must do
- a question, if you actually have one

**3 bullets max. Fewest words possible. Stop after the key point.**

## Do it yourself — never park work on Jose

His instruction, 2026-11-06: *"Stop asking me for stuff unless it's something
that would be IMPOSSIBLE for you to do on your own."* He has granted
permission, repeatedly, to every session.

Before handing Jose any task (recording a video, sending a reply, clicking
in a console, creating an app password, reconnecting an account), check
whether a session can do it: Chrome is signed in, computer-use, ssh, gcloud,
wp-cli, MCP. If yes, do it, then report the result. Only the impossible goes to
him: typing a password, a 2FA code on his phone, a physical action. Send it
as one line, with everything else already done.

Do NOT send him: what other sessions said, how a conclusion was reached, your
reasoning, corrections between sessions, or anything he already knows. Peer
coordination stays between peers.
