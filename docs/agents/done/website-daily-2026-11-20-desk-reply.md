# Website daily 2026-11-20: desk reply (measured 2026-11-21 ~00:05 UTC)

1. **Live on web since 0611 (e2b00506):** 0612, 0613, 0614, 0618 (61638766), 0619 (99ea79ca), 0620 (3f99b84c, live now; Windows too).
   - Never deployed: 0615, 0616 and 0617. Their notes were written but the releases were folded into 0618, which shipped their content.
   - Rolled back: none.
   - In its backend gate: 0621 (43a211f0). It adds the Workiz job-file reads (Workiz v2, same processor and host) and two report fixes.
2. **QuickBooks:**
   - Rows on prod: 0. There are 0 `social_connections` rows with provider=quickbooks, and `services.quickbooks.client_id` is unset on prod (no Intuit app yet), so nobody, paying or not, can connect.
   - Shipped code: a new processor (Intuit QuickBooks Online), the OAuth scope `com.intuit.quickbooks.accounting` (read-only use: expenses into Financials › Markets), and outbound hosts appcenter.intuit.com, oauth.platform.intuit.com, quickbooks.api.intuit.com and sandbox-quickbooks.api.intuit.com.
   - All of it is dormant until the app exists and a tenant connects.
3. **Other changes:** none.
   - No other new processor, scope or outbound host. `git diff e2b005063..43a211f00`: the only new https hosts in app/ and config/ are the Intuit ones, plus support.google.com and app.ezbizi.com in text.
   - No new Google/Meta write.
   - Reviews now store Google's per-listing totals (a read of the existing GBP scope).
4. **Do-not list (row counts, prod):**
   - training_attempts 58, training_progress 82, training_assignments 12 (unchanged).
   - phone_ring_groups 6; phone_numbers resolving to a group: 6 (unchanged).
   - Scheduled Jobs (jobs.scheduled_for set, no workiz_jobs row): 785 (was 784).
   - Local Services leads (source=local_services): 55,428, of which 55,428 have a provider id (remote_id).
   - New publisher class under app/Support/Social: none. The only new file is QuickBooksOAuth.php, a connection (OAuth reader), not a publisher.
5. **Recruiting / rating badge / AI dispatcher:**
   - None of them sends person data to a new party or adds a public no-login page.
   - Recruiting: internal HR tables, every route inside the authenticated API group. Rows: hr applicants 0, recruiting_spend 0.
   - Rating badge: an aggregate count and average per company (13,583 / 4.9 on the A-1 sites), no person data. The existing plugin payload serves it.
   - AI dispatcher: answers through the existing Grok (xAI) staff Q&A path, so no new party.
   - Every new route since 0611 (reports/*, hr/applicants*, hr/recruiting-spend*, tasks/{task}/reminders, financials/markets*, complaints, project-board cost, newsletter editable-copy, QR center-image) sits inside the authenticated group.
