"""Consecutive no-reply checks for agent mail.

The inbox can be empty of mail addressed to you and still be a wait: your
outgoing message is sitting there unanswered. Three such checks in a row
means something may be amiss — continue the plan, do not wait for a reply.

State lives in `.cursor/agent-mail-stall.json` (gitignored). Fail open.
"""

from __future__ import annotations

import json
import os
from pathlib import Path

STALL_AFTER = 3

# Hours of no commit before the plan itself counts as stalled. Long enough that
# lunch is not an alarm, short enough that a whole evening is not silent.
STALE_AFTER_HOURS = float(os.environ.get("AGENT_PLAN_STALE_HOURS", "4"))


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


def git_facts(root: Path) -> tuple[str, int, list[str]]:
    """(head sha, head commit epoch, work-in-progress code paths). Fail open."""
    import subprocess

    def run(args):
        try:
            out = subprocess.run(
                ["git", *args], cwd=str(root), capture_output=True, text=True, timeout=10
            )
            return out.stdout.strip() if out.returncode == 0 else ""
        except Exception:
            return ""

    head = run(["log", "-1", "--format=%H %ct"]).split()
    if len(head) != 2:
        return "", 0, []

    # Docs churn on their own; only code standing still means the plan stalled.
    wip = [
        line[3:].strip()
        for line in run(["status", "--porcelain"]).splitlines()
        if line[3:].strip().startswith(("app/", "client/", "tests/", "database/"))
    ]
    return head[0], int(head[1]), wip


def plan_stall(
    me: str,
    head_sha: str,
    head_epoch: int,
    wip: list[str],
    state_path: Path,
    now: float,
    hours: float = STALE_AFTER_HOURS,
) -> dict:
    """Has the work itself stopped moving?

    The no-reply check above only sees a wait that somebody is waiting *in*. Two
    agents with empty inboxes and nothing owed look identical whether they are
    building or not running at all — which is what happened overnight on 18 Aug,
    with Task 5 left half-written and uncommitted.

    Nothing here can wake an agent that is not running. It makes whichever agent
    IS running notice, so the answer comes from a person or the other agent
    rather than from silence.

    Warns once per HEAD: a genuinely idle evening is one line, not one every
    five minutes. A new commit re-arms it.
    """
    if not head_sha:
        return {"warn": False}

    state = _load(state_path)
    mine = state.setdefault(me, {})
    age_hours = (now - head_epoch) / 3600.0

    if mine.get("plan_head") != head_sha:
        mine["plan_head"] = head_sha
        mine["plan_warned"] = False

    warn = age_hours >= hours and not mine.get("plan_warned")
    if warn:
        mine["plan_warned"] = True
    _save(state_path, state)

    return {
        "warn": warn,
        "age_hours": round(age_hours, 1),
        "head": head_sha[:7],
        "wip": list(wip),
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

        # plan_stall: fresh commit is quiet, an old one warns exactly once,
        # and a new commit re-arms it.
        q = Path(tmp) / "plan.json"
        fresh = plan_stall("claude", "aaa", 1_000_000, [], q, now=1_000_000 + 600)
        assert fresh["warn"] is False, fresh
        old = plan_stall("claude", "aaa", 1_000_000, ["app/x.php"], q, now=1_000_000 + 6 * 3600)
        assert old["warn"] is True and old["wip"] == ["app/x.php"], old
        again = plan_stall("claude", "aaa", 1_000_000, [], q, now=1_000_000 + 9 * 3600)
        assert again["warn"] is False, again
        moved = plan_stall("claude", "bbb", 1_000_000, [], q, now=1_000_000 + 9 * 3600)
        assert moved["warn"] is True, moved
        assert plan_stall("claude", "", 0, [], q, now=0) == {"warn": False}
        print("ok")
