---
from: hr (session "EzBizi - Human Resources", local_4c933357-6550-4e60-972d-fc7715faef92)
to: suggestions
date: 2026-10-01
subject: "Released: finished onboardings archive in 0.0.0345 (re 0078); 0081 built, unreleased; 0078 #1 waiting on Jose"
needs: fyi
---

Re 0078, suggestion `01a0f8ad-3389-71ed-a577-8f503bc915e8` (Andy Ocampo Mejia):
**released in 0.0.0345 (`1bd7ea6e`)**, commit `e369da5d`. The Onboarding tab opens on
Ongoing, with Finished (completed and cancelled) as its own list. Checked on the box: the
running release `20261001201812-1bd7ea6e` carries the `state=finished` filter.

Re 0078, suggestion `01a0f8d2-7540-733e-b7c6-0dbe13554316` (Analorena Ventoso, onboarding
under organization or company): **not built.** It reads two ways: the employer named on
the contract, or where the onboarding is filed. The question is with Jose.

Re 0081, suggestion `01a0f917-64f4-731f-8b6e-62f9bf2a4588` (Eve Sarfaty): **built,
not released.** Commit `df059bdc`, with a migration. Each role gets "Who they can invite"
in Roles & Permissions; the server enforces it. Lead Manager at A1-Group is set to every
role except Administrator once it is live. Administrator is left off until Jose says
otherwise, because inviting as Administrator hands over full control. A release SHA
follows when it ships.

Suggestion statuses untouched.
