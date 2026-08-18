"""Consecutive no-reply checks for agent mail.

The inbox can be empty of mail addressed to you and still be a wait: your
outgoing message is sitting there unanswered. Three such checks in a row
means something may be amiss — continue the plan, do not wait for a reply.

State lives in `.cursor/agent-mail-stall.json` (gitignored). Fail open.
"""

from __future__ import annotations

import json
from pathlib import Path

STALL_AFTER = 3


def note(
    me: str,
    incoming: list[str],
    outgoing: list[str],
    state_path: Path,
) -> dict:
    """Update the counter. `warn` is true only on the tick that crosses STALL_AFTER."""
    state = _load(state_path)
    mine = state.setdefault(me, {"noreply": 0, "waiting_on": [], "warned": False})

    if incoming:
        mine["noreply"] = 0
        mine["waiting_on"] = []
        mine["warned"] = False
        _save(state_path, state)
        return {"noreply": 0, "warn": False, "waiting_on": []}

    if not outgoing:
        mine["noreply"] = 0
        mine["waiting_on"] = []
        mine["warned"] = False
        _save(state_path, state)
        return {"noreply": 0, "warn": False, "waiting_on": []}

    if outgoing != mine.get("waiting_on"):
        mine["noreply"] = 0
        mine["waiting_on"] = list(outgoing)
        mine["warned"] = False

    mine["noreply"] = int(mine.get("noreply") or 0) + 1
    warn = mine["noreply"] >= STALL_AFTER and not mine.get("warned")
    if warn:
        mine["warned"] = True
    _save(state_path, state)
    return {
        "noreply": mine["noreply"],
        "warn": warn,
        "waiting_on": list(outgoing),
    }


def _load(path: Path) -> dict:
    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return {}


def _save(path: Path, state: dict) -> None:
    try:
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(json.dumps(state, indent=2) + "\n", encoding="utf-8")
    except OSError:
        pass


if __name__ == "__main__":
    import tempfile

    with tempfile.TemporaryDirectory() as tmp:
        p = Path(tmp) / "stall.json"
        a = note("grok", incoming=[], outgoing=["0018.md"], state_path=p)
        b = note("grok", incoming=[], outgoing=["0018.md"], state_path=p)
        c = note("grok", incoming=[], outgoing=["0018.md"], state_path=p)
        d = note("grok", incoming=[], outgoing=["0018.md"], state_path=p)
        assert a == {"noreply": 1, "warn": False, "waiting_on": ["0018.md"]}, a
        assert b == {"noreply": 2, "warn": False, "waiting_on": ["0018.md"]}, b
        assert c["warn"] is True and c["noreply"] == 3, c
        assert d["warn"] is False and d["noreply"] == 4, d
        reset = note("grok", incoming=["0019.md"], outgoing=["0018.md"], state_path=p)
        assert reset["noreply"] == 0 and reset["warn"] is False, reset
        print("ok")
