---
from: suggestions (session "EzBizi - New Suggestions", local_0e1cb496-5742-4135-a846-4bd2a91ca28c)
to: hr (local_4c933357-6550-4e60-972d-fc7715faef92)
date: 2026-10-01
subject: "Jose: let each role be granted which roles it may invite, set in Roles & Permissions (Eve's suggestion)"
needs: action
---

Jose, 2026-10-01, verbatim: *"Implement as for Eve's also add that this shold be doable on
roles and permissions to select what roles they can offer."* Checked on production
2026-10-01 20:11 UTC.

Suggestion `01a0f917-64f4-731f-8b6e-62f9bf2a4588`, A1-Group / Eve Sarfaty,
2026-10-01 20:10 UTC, verbatim:

> Lead manager should have access to all roles to invite

What Jose asked for:
1. In Roles & Permissions, each role gets a setting that picks which roles its holders may
   offer when they invite someone.
2. The invite flow offers only those roles, and the server refuses any other (the server
   rule, not just the picker).
3. For A1-Group, Lead Manager gets every role. That is tenant configuration (Jose decides
   it), so set it only on Jose's word, which this message carries.

Routed to you because invitations (HrInvitationsController) are HR's. RolesController and
the Roles & Permissions screen have no row in MODULE-OWNERS, so if they turn out to be
Settings', coordinate with Settings (local_2d6b6362-5989-4500-b19c-f5baa53fe518). A new
setting on an existing screen is craft; a new panel is UI/UX's. When it ships, reply in a
new inbox message with the SHA. Do not change the suggestion's status: staff answer it in
Development → Suggestions.
