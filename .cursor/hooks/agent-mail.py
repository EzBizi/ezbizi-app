"""Unread agent mail at Cursor session start, plus a no-reply stall check.

Same job as .claude/hooks/agent-mail.py. Silent when there is nothing to grok
and we are not waiting on an unanswered outgoing message.

`--tick` is the 5-minute doorbell: prints AGENT_LOOP_TICK_agent-mail only when
there is incoming mail, or on the third consecutive check with no reply.
"""

from __future__ import annotations

import json
import os
import re
import sys
from pathlib import Path

ROOT = Path(os.getcwd())
INBOX = ROOT / "docs" / "agents" / "inbox"
STATE = ROOT / ".cursor" / "agent-mail-stall.json"
ME = "grok"
OTHER = "claude"

sys.path.insert(0, str(ROOT / "scripts"))
from agent_mail_stall import (  # noqa: E402
    STALL_AFTER,
    git_facts,
    note,
    plan_stall,
)


def field(text: str, name: str) -> str:
    m = re.search(rf"^{name}:\s*(.+)$", text, re.MULTILINE)
    return m.group(1).strip() if m else ""


def listed() -> tuple[list[tuple[str, str, str, str]], list[str], list[str]]:
    try:
        names = sorted(n for n in os.listdir(INBOX) if n.endswith(".md"))
    except OSError:
        return [], [], []

    incoming_rows: list[tuple[str, str, str, str]] = []
    incoming: list[str] = []
    outgoing: list[str] = []
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
        # Only mail that asked for something counts as a wait. An outgoing
        # `fyi` is owed nothing, so counting it made the stall check cry
        # "no reply" over a message that never wanted one — which it did on
        # 19 Aug over the deploy notice.
        if sender == ME and to == OTHER and needs in ("action", "answer"):
            outgoing.append(name)
    return incoming_rows, incoming, outgoing


def mail_context(rows: list[tuple[str, str, str, str]]) -> str:
    count = len(rows)
    plural = "" if count == 1 else "s"
    body = "\n".join(
        f"  {name}\n    {sender} -> {ME} · needs {needs} · {subject}"
        for name, sender, needs, subject in rows
    )
    return (
        f"Unread agent mail ({count} message{plural}) in docs/agents/inbox/ "
        "addressed to grok. Read each one before starting work; "
        "docs/agents/README.md is the protocol. Move a message to "
        "docs/agents/done/ once you have acted on it.\n\n" + body
    )


def stall_context(waiting_on: list[str], noreply: int) -> str:
    names = ", ".join(waiting_on) or "outgoing mail"
    return (
        f"Claude has not replied in {noreply} inbox checks "
        f"(still sitting unanswered: {names}). Something may be amiss. "
        "Do not wait. Continue the plan. Do not ask permission to resume. "
        "Do not mention mail to Mia unless the work itself needs her."
    )


def plan_context(idle: dict) -> str:
    wip = idle["wip"]
    left = (
        "Uncommitted work is sitting in the tree: " + ", ".join(wip[:6]) + ". "
        if wip
        else "Nothing is uncommitted, so it stopped between tasks. "
    )
    return (
        f"No commit for {idle['age_hours']} hours (HEAD {idle['head']}). {left}"
        f"{OTHER.title()} may not be running — nothing can wake an agent that is "
        "not open. Check the plan for the next unfinished task and either carry it "
        "yourself or tell Mia which one is stuck and why. Do not wait for mail; "
        "an empty inbox is not a signal here."
    )


def emit_session(context: str) -> None:
    json.dump(
        {
            "additional_context": context,
            "hookSpecificOutput": {
                "hookEventName": "sessionStart",
                "additionalContext": context,
            },
        },
        sys.stdout,
    )


def emit_tick(prompt: str) -> None:
    print("AGENT_LOOP_TICK_agent-mail " + json.dumps({"prompt": prompt}))


def main() -> None:
    tick = "--tick" in sys.argv
    rows, incoming, outgoing = listed()
    stall = note(ME, incoming, outgoing, STATE)

    if rows:
        context = mail_context(rows)
        if tick:
            emit_tick(
                "Unread agent mail addressed to grok. Read each one. "
                "Act on its needs field. Move handled mail to docs/agents/done/. "
                "Then continue the plan — mail is not a reason to stop."
            )
        else:
            emit_session(context)
        return

    if stall["warn"]:
        context = stall_context(stall["waiting_on"], stall["noreply"] or STALL_AFTER)
        if tick:
            emit_tick(context)
        else:
            emit_session(context)
        return

    # Both inboxes empty and nothing owed looks the same whether the other agent
    # is building or is not running at all. This is the only check that sees it.
    import time

    sha, epoch, wip = git_facts(ROOT)
    idle = plan_stall(ME, sha, epoch, wip, STATE, now=time.time())
    if idle["warn"]:
        context = plan_context(idle)
        if tick:
            emit_tick(context)
        else:
            emit_session(context)


if __name__ == "__main__":
    try:
        main()
    except Exception:
        sys.exit(0)
