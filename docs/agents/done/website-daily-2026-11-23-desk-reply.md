# Website daily 2026-11-23: desk reply (measured 2026-11-23 ~23:45 UTC)

1. **Live on web since 0623 (194e4c29c):**
   - 0624 (7a1d929df).
   - 0625 (dbb236eec).
   - 0626 (12a319a2b).
   - 0627 (593d1a696), live now; Windows too; iOS/macOS/Android uploading with 0627.
   - Folded: none rolled back. 0624 and 0625 were each re-cut after a failed gate:
     - 0624 failed the authorization sweep on before/after, fixed by b9a1bad72.
     - 0625 failed a stale module_tabs fixture.
   - 0628 is cutting now: Project tag cost gate, PM stage colours, phone Scheduling/Jobs, PTO first-days date. It has no new host or public page.

2. **Since 194e4c29c (`git diff 194e4c29c..593d1a696`, 165 files):**
   - New https hosts in app/ and config/: 0.
   - New OAuth scope: none.
   - Changes under app/Support/Social: 0.
   - New processor: none.
   - New Google/Meta write: none. The Google review reply write is unchanged; it now also explains removed, eKomi and unconnected reviews instead of offering Post.
   - **New public no-login routes:**
     - `POST v1/invite-links/lookup` and `/join` (throttle:invitation): the `/join?link=` page. It creates an ordinary Invitation and runs the existing invite acceptance; the email is reset to unverified and a confirmation is sent via SES. It collects nothing beyond the existing invite flow.
     - `GET v1/web/plugin/before-after` (throttle:web-plugin, site token) and `GET v1/web/plugin/before-after/image/{attachment}` (signed URL, 1 day).
     - `/r/<code>` now also arrives from a technician's Review QR. It's the same smiley/sad page; the scan is credited to the technician who made the code, and no customer is behind a QR code.
   - **Before/after on tenant websites: yes, but only after a person approves.**
     - Approving is `POST inspections/reports/{report}/before-after/{after}/approve`. It needs inspection.edit plus report reach (`authorize('view')`, b9a1bad72), and a required privacy tick: "Confirm the photos show no faces and no house numbers."
     - The feed returns only approved pairs, for the site's company. The images are 1600px copies with EXIF stripped, behind signed URLs. Withdraw removes a pair.
     - Approved pairs on prod today: **0**.
   - **View as: yes, audited.**
     - Only an Administrator with a platform-staff link and `development.view_as` can see and use the app as a role or a person.
     - Every request made while viewing-as is audit-logged with the real actor (`view_as.request`, ApplyViewAs.php:154), as are start and stop.
     - Password/TOTP/API keys/MCP/sessions/deletion are refused while viewing-as.
     - view_as_sessions on prod: 1.

3. **Row counts (prod):**
   - training_attempts 59, training_progress 83, training_assignments 12.
   - phone_ring_groups 6; phone_numbers resolving to a group: 6, of 15 in total.
   - Scheduled Jobs (jobs.scheduled_for set, no workiz_jobs row, **not soft-deleted**): Triplex Group 80, 0 in any other org.
     - The 11-21 figure of 785 did not exclude soft-deleted jobs, so it isn't comparable. Read today's number as the baseline.
   - Local Services leads (source=local_services): 55,898, of which 55,898 have a remote_id.
   - New publisher class under app/Support/Social: none.
   - Approved before/after pairs: 0.
   - Also new: invite_links 0, review_codes made by a technician 2.

4. **Site claims:**
   - Nothing on ezbizi.com is made wrong by these releases.
   - Watch if the site describes signup: sign-in no longer shows "Create an account" (staff join by invite link), though signup itself still works.
   - If any page says "invite people one at a time", invite links now exist.
   - The A-1 review badge is still live from Google's per-listing totals (plugin 1.26.36 fixed a JSON-LD separator bug Bar reported; no claim changes).
