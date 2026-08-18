"""Print unread agent mail at session start, plus a no-reply stall check.

docs/agents/README.md is the protocol. Silent when the inbox has nothing for
claude and we are not waiting on an unanswered outgoing message.

`--tick` is for the live 5-minute monitor: print a wake line only when there
is incoming mail, or on the third consecutive check with no reply.
"""

from __future__ import annotations

import json
import os
import re
import sys
from pathlib import Path

ROOT = Path(os.environ.get("CLAUDE_PROJECT_DIR") or os.getcwd())
INBOX = ROOT / "docs" / "agents" / "inbox"
STATE = ROOT / ".cursor" / "agent-mail-stall.json"
ME = "claude"
OTHER = "grok"

sys.path.insert(0, str(ROOT / "scripts"))
from agent_mail_stall import STALL_AFTER, note  # noqa: E402


def field(text, name):
    m = re.search(rf"^{name}:\s*(.+)$", text, re.MULTILINE)
    return m.group(1).strip() if m else ""


def listed():
    try:
        names = sorted(n for n in os.listdir(INBOX) if n.endswith(".md"))
    except OSError:
        return [], [], []

    incoming_rows = []
    incoming = []
    outgoing = []
    for name in names:
        try:
            head = (INBOX / name).read_text(encoding="utf-8")[:2000]
        except OSError:
            continue
        to = field(head, "to") or "?"
        sender = field(head, "from") or "?"
        needs = field(head, "needs") or "fyi"
        subject = field(head, "subject") or name
        if to in (ME, "?"):
            incoming.append(name)
            incoming_rows.append((name, sender, needs, subject))
        if sender == ME and to == OTHER:
            outgoing.append(name)
    return incoming_rows, incoming, outgoing


def mail_context(rows):
    count = len(rows)
    plural = "" if count == 1 else "s"
    body = "\n".join(
        f"  {name}\n    {sender} -> {ME} · needs {needs} · {subject}"
        for name, sender, needs, subject in rows
    )
    return (
        f"Unread agent mail ({count} message{plural}) in docs/agents/inbox/. "
        "Read each one before starting work; docs/agents/README.md is the "
        "protocol. Move a message to docs/agents/done/ once you have acted "
        "on it.\n\n" + body
    )


def stall_context(waiting_on, noreply):
    names = ", ".join(waiting_on) or "outgoing mail"
    return (
        f"Grok has not replied in {noreply} inbox checks "
        f"(still sitting unanswered: {names}). Something may be amiss. "
        "Do not wait. Continue the plan. Do not ask permission to resume."
    )


def emit_session(context, system=""):
    payload = {
        "hookSpecificOutput": {
            "hookEventName": "SessionStart",
            "additionalContext": context,
        },
    }
    if system:
        payload["systemMessage"] = system
    json.dump(payload, sys.stdout)


def emit_tick(prompt):
    print("AGENT_LOOP_TICK_agent-mail " + json.dumps({"prompt": prompt}))


def main():
    tick = "--tick" in sys.argv
    rows, incoming, outgoing = listed()
    stall = note(ME, incoming, outgoing, STATE)

    if rows:
        context = mail_context(rows)
        count = len(rows)
        plural = "" if count == 1 else "s"
        if tick:
            emit_tick(
                "Unread agent mail addressed to claude. Read each one. "
                "Act on its needs field. Move handled mail to docs/agents/done/. "
                "Then continue the plan — mail is not a reason to stop."
            )
        else:
            emit_session(
                context,
                system=f"{count} unread agent message{plural} in docs/agents/inbox/",
            )
        return

    if stall["warn"]:
        context = stall_context(stall["waiting_on"], stall["noreply"] or STALL_AFTER)
        if tick:
            emit_tick(context)
        else:
            emit_session(context, system="No reply in 3 inbox checks — continue the plan")


if __name__ == "__main__":
    try:
        main()
    except Exception:
        sys.exit(0)
