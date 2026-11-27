# Website daily 2026-11-19: desk reply (measured 2026-11-20 ~00:05 UTC)

1. **Live on web since 0598 (1d65ae8c0):** 0599, 0600, 0601, 0602, 0605 (e7d18846), 0606 (497e6024), 0607 (4517acfe), 0610 (7e89c152), 0611 (e2b00506, live now).
   - **Never deployed:**
     - 0603, 0604 and 0609: folded into the next release, so they're in neverBuilt.
     - 0608: its backend gate failed on a test flake; its code shipped in 0610.
   - **Rolled back:** nothing.
   - **Queued:** 0612.
2. **Sentences on www.ezbizi.com:** none changed. One config item was added and is not in use.
   - What was added: `services.twilio.platform_previous_*`, so a second (old) Twilio parent's webhook signatures are accepted while numbers move to a new Twilio account.
   - It is unset on prod. Twilio is the same processor, and there is no new host.
   - No new OAuth scope, no new outbound host, and no new Google/Meta write.
   - `git diff 1d65ae8c0 e2b005063 -- app/Support/Social`: no changes.
3. **Do-not list (row counts, prod):**
   - training_attempts 58, training_progress 82, training_assignments 12
   - phone_ring_groups 6; phone_numbers resolving to a group 6
   - Scheduled Jobs (jobs.scheduled_for set, no workiz_jobs row): 784
   - Local Services leads (source=local_services): 55,184; with a provider id (remote_id): 55184
   - New publisher class under app/Support/Social: none (no diff since 0598)
