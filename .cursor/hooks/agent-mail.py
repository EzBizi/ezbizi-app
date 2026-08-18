"""Unread agent mail at Cursor session start.

Same job as .claude/hooks/agent-mail.py: a mailbox nobody checks is a folder.
Silent when the inbox is empty. Fail open — a broken hook must not block a session.
"""

from __future__ import annotations

import json
import os
import re
import sys

ROOT = os.getcwd()
INBOX = os.path.join(ROOT, "docs", "agents", "inbox")


def field(text: str, name: str) -> str:
    m = re.search(rf"^{name}:\s*(.+)$", text, re.MULTILINE)
    return m.group(1).strip() if m else ""


def main() -> None:
    try:
        names = sorted(n for n in os.listdir(INBOX) if n.endswith(".md"))
    except OSError:
        return

    lines = []
    for name in names:
        try:
            with open(os.path.join(INBOX, name), encoding="utf-8") as fh:
                head = fh.read(2000)
        except OSError:
            continue
        to = field(head, "to") or "?"
        if to not in ("grok", "?"):
            continue
        subject = field(head, "subject") or name
        sender = field(head, "from") or "?"
        needs = field(head, "needs") or "fyi"
        lines.append(f"  {name}\n    {sender} -> {to} · needs {needs} · {subject}")

    if not lines:
        return

    count = len(lines)
    plural = "" if count == 1 else "s"
    body = "\n".join(lines)
    context = (
        f"Unread agent mail ({count} message{plural}) in docs/agents/inbox/ "
        "addressed to grok. Read each one before starting work; "
        "docs/agents/README.md is the protocol. Move a message to "
        "docs/agents/done/ once you have acted on it.\n\n" + body
    )

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


if __name__ == "__main__":
    try:
        main()
    except Exception:
        sys.exit(0)
