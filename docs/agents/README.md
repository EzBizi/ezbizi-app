# Agent mail

Two coding agents work on this repo — **Claude** (Claude Code) and **Grok**
(Cursor). They never share a chat window. Until this existed, everything one of
them learned reached the other only because Mia retyped it, which meant reviews,
production findings and design corrections moved at the speed of a human courier
and lost detail on the way.

This directory is the channel instead. It is a directory and a naming rule. There
is no service, no queue, no daemon, and nothing to start.

**Mia is not part of the loop for reviews, findings, or corrections.** She is
still the only one who can touch AWS, Google Admin and Azure, and the only one who
grants SSH or a deploy. Everything else the two agents settle between themselves,
here.

---

## The protocol

**To send:** write one Markdown file into `inbox/`.

```
inbox/0007-claude-to-grok-mail-still-cannot-send.md
```

`NNNN-<from>-to-<to>-<slug>.md`. Take the next free number. Two agents writing at
the same moment pick different filenames, so there is nothing to lock and nothing
to merge.

**To read:** list `inbox/`. That listing is the unread pile. Anything not
addressed to you is not yours to move — read it if it is useful, leave it there.

**When you have acted on a message:** `git mv` it to `done/`. Moving it is the
acknowledgement; there is no separate flag. If the sender needs an answer rather
than an action, send a new message that cites the number.

**Replies are new messages.** No threading, and nobody edits a message someone
else wrote. A message is a record of what the other agent was working from —
correcting it in place destroys that.

## What a message looks like

Frontmatter, then prose. Short enough to read in one pass.

```markdown
---
from: claude
to: grok
date: 2026-08-18
subject: Mail still cannot send in production
needs: action        # action | answer | fyi
---

MAIL_MAILER=ses, but AWS_ACCESS_KEY_ID and AWS_SECRET_ACCESS_KEY are empty on the
box and MailStatus::delivers() returns false naming both. Checked 18 Aug 18:05 UTC.

The signup verification gate must stay out until this is fixed, or every new
account is locked out.
```

`needs:` is the only field that changes behaviour:

| Value | What the receiver owes |
|---|---|
| `action` | Do the thing, then move the file to `done/`. |
| `answer` | Write a reply message, then move this one to `done/`. |
| `fyi` | Read it, move it to `done/`. Nothing is owed. |

## When to send without being asked

The system only pays off if neither agent waits for Mia to prompt it. Send a
message when you:

- finish a review of the other agent's plan, spec, or code;
- ship something the other agent reviewed, so they know what landed;
- disagree with a finding — say so directly rather than quietly ignoring it;
- learn something about production the other agent would otherwise re-derive;
- hit a decision that needs context only the other agent has.

## Never wait on mail

Mail is not turn-taking. Two agents politely waiting for each other is the one
failure this system can produce that looks exactly like working — inboxes empty,
everyone responsive, nothing being built.

**A closed thread returns you to the plan.** When you move a message to `done/`
and nothing is owed, do not stop and wait for the next one. Go back to whatever
task you were on and keep going. The other agent will interrupt you if they need
to; that is what the watcher is for.

**The three-message rule.** If three messages go by without the main work
advancing — check `git log` for commits against the plan, not the mail — assume
something is amiss and act on it:

- Say so in the next message: "we have traded three messages and the plan has not
  moved since `<commit>`; I am resuming `<task>`."
- Then resume. Do not ask permission to resume your own plan.

**Side-quests are the usual cause.** An urgent-looking finding — a broken
watchdog, a bad default, a deploy hazard — will pull both agents off the plan and
keep them there, because each reply is genuinely useful. Useful is not the same
as the work. Fix the thing, file it, go back.

It happened on 18 Aug: the Inspection build stopped at Task 3 (`3a1a9c1`) while
four messages and three commits went into the peer watchdog. Nobody was idle and
nobody was wrong; the module just did not move.

## Rules

- **Say when you checked.** Production moves. A claim about the box without a
  timestamp is a claim about a box that may no longer exist.
- **Cite files and lines**, so the other agent can verify instead of trust.
- **Never put a secret in a message.** These files are committed. Name the key,
  never the value — `AWS_SECRET_ACCESS_KEY is empty`, not what it should be.
- **Commit the message with your work.** A message sitting uncommitted in a
  working tree the other agent cannot see has not been sent.
- **Correct yourself by sending another message**, not by editing history. A
  message 0003 saying "0001 was wrong, here is why" is worth more than a clean
  0001.
- **This directory is not the plan.** Decisions belong in `docs/EZBIZI-TODO.md`
  and `docs/EZBIZI-STATUS.md`. Mail is how a finding gets carried and a decision
  gets proposed; once it is settled, write it where the project keeps settled
  things and let the message go to `done/`.

---

## How each agent is made to check

A mailbox nobody checks is a folder. Both sides are wired to look without being
told, in whatever way their harness allows.

### Claude — SessionStart hook

`.claude/settings.json` runs `.claude/hooks/agent-mail.py` on every session start.
The script lists `inbox/`, reads the frontmatter of each message, and returns the
list as `systemMessage` (so Mia sees the count) and `additionalContext` (so the
model actually reads it). It prints nothing when the inbox is empty — no message
is not news.

Verify it by hand at any time:

```bash
python .claude/hooks/agent-mail.py
```

That covers mail that arrived while Claude was away. Mail that arrives **during**
a session is caught by a second, live watcher: a persistent background monitor
polling `inbox/` every 5 minutes, which interrupts the session with the sender,
the `needs:` value and the subject the moment a new file appears. The hook is the
cold start; the monitor is the doorbell. Neither waits for Mia to say "go look."

The monitor filters on `to: claude`. Without that it fires on outgoing mail too,
and an agent being notified about the message it just wrote trains it to ignore
the notification.

### Grok — Cursor session-start hook plus a 5-minute doorbell

`.cursor/rules/agent-mail.mdc` carries `alwaysApply: true`, so Cursor loads the
protocol into every session. `.cursor/hooks.json` runs
`.cursor/hooks/agent-mail.py` on `sessionStart` and injects unread mail addressed
`to: grok` (silent when the inbox is empty).

Mail that arrives **during** a session is caught by a live 5-minute loop in that
session (`AGENT_LOOP_TICK_agent-mail`), which is the same doorbell as Claude's
monitor: interrupt, read, act, move to `done/`. Session-start is the cold start;
the loop is the doorbell. Neither waits for Mia to say "go look."

If Grok ever runs somewhere Cursor hooks do not load, the fallback is
`StartUp.md` section 2, which lists `docs/agents/inbox/` as required reading —
the same front door both agents already use.

Verify the hook by hand:

```bash
python .cursor/hooks/agent-mail.py
```

<!-- ponytail: polling, not push, on both sides — 5 min on Claude's live monitor,
     session start everywhere else. Fine while two agents share one repo on one
     machine. A third agent or a second machine would want a real filesystem
     watch instead of a wider poll. -->

## Why it is this dumb

The channel worked before it had a name: a review file left in
`docs/superpowers/plans/` was picked up and shipped against in `4f214e3` without
anybody being asked to look. The repo is the one thing both agents already read,
both already write, and Mia already reviews. Anything fancier would be a second
place to check, and a second place to forget.
