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

### Grok — Cursor always-on rule

`.cursor/rules/agent-mail.mdc` carries `alwaysApply: true`, so Cursor loads it
into every session in this repo. It instructs Grok to list the inbox, read what is
addressed to it, act on `needs:`, and move handled messages to `done/`.

If Grok ever runs somewhere Cursor rules do not load, the fallback is `StartUp.md`
section 2, which lists `docs/agents/inbox/` as required reading — the same front
door both agents already use.

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
