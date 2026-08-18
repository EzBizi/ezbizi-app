"""Print unread agent mail at session start.

docs/agents/README.md is the protocol: two coding agents leave each other files in
docs/agents/inbox/ instead of routing everything through Mia. A mailbox nobody
checks is just a folder, so this runs on SessionStart and puts the unread list
into the model's context and on Mia's screen.

Silent when the inbox is empty — no message is not news.
"""

import json
import os
import re
import sys

ROOT = os.environ.get("CLAUDE_PROJECT_DIR") or os.getcwd()
INBOX = os.path.join(ROOT, "docs", "agents", "inbox")


def field(text, name):
    """Read one frontmatter value. Absent is fine — the filename still names it."""
    m = re.search(rf"^{name}:\s*(.+)$", text, re.MULTILINE)
    return m.group(1).strip() if m else ""


def main():
    try:
        names = sorted(n for n in os.listdir(INBOX) if n.endswith(".md"))
    except OSError:
        return  # no inbox in this project; nothing to say

    lines = []
    for name in names:
        try:
            with open(os.path.join(INBOX, name), encoding="utf-8") as fh:
                head = fh.read(2000)
        except OSError:
            continue
        to = field(head, "to") or "?"
        # Mail addressed to the other agent is not news here. Being told about the
        # message you just wrote trains you to ignore the notification. "?" stays
        # in, so a message with no `to:` is seen by somebody rather than nobody.
        if to not in ("claude", "?"):
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

    json.dump(
        {
            "systemMessage": f"{count} unread agent message{plural} in docs/agents/inbox/",
            "hookSpecificOutput": {
                "hookEventName": "SessionStart",
                "additionalContext": (
                    f"Unread agent mail ({count} message{plural}) in docs/agents/inbox/. "
                    "Read each one before starting work; docs/agents/README.md is the "
                    "protocol. Move a message to docs/agents/done/ once you have acted "
                    "on it.\n\n" + body
                ),
            },
        },
        sys.stdout,
    )


main()
