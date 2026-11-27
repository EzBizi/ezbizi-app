# Report Builder: what it should offer

Jose, 2026-10-09: *"We also need to improve the Report Builder, i want you to check at all the options
possible, as well as check workiz and their own report builder. i also want a preview view, as you select
the items and be able to order them too"*.

Reports owns the options and the backend; UI/UX owns the layout (gallery → Jose → build).

> **Status 2026-11-13 (from git):** backend order 1–5 below shipped 2026-10-09 —
> `POST /reports/preview` (`78d536fce`), sort/rename/CSV `GET /reports/{id}/csv`
> (`cfb7f7b5d`), is-one-of/between/empty/named periods/any-all (`59d8a2dd0`),
> group by with day/week/month buckets + totals (`2e1ae9d7a`), `workiz_jobs` and
> `workiz_records` resources (`6a0deda87`); the client builder has the live
> preview and drag-to-order columns (`2acc475d4`). Task Board tasks were added
> 2026-10-13. Not found in code: #6 schedule-from-report. "What ours does today"
> below is the 2026-10-09 starting point, not today.

## What Workiz's builder does (measured 2026-10-09, A1 Chimney - DMV, read-only, nothing saved)

- **Starts from a record type**: Job, Client, Payment, Invoice, Estimate, Commission, Timesheet.
- **The table IS the builder**: it opens already showing live rows (15 default fields, 2,372 jobs, paged
  10/20/25/50/100). There is no separate "run": every change redraws the table.
- **Fields panel**: a search box, "Fields in use" with **drag handles to reorder**, and "Unselected fields" as
  checkboxes. A Job has ~50 fields, including money (Total, Sub Total, Amount Due, Amount Paid, Cost, Profit,
  Profit Margin, Tax, Taxable Amount, Credit Card Service Fee, Labor Cost), dates (Job Date, Job End, Created,
  Conversion Date, Job Sold Date, Payment Due Date, Last Payment Date), people (Tech, Created By, Client),
  place (Address, City, State, Zip, Service Area), Source, Tags, Status/Sub-Status and custom fields.
- **Filters**: "+ Add filter" lists every field. A date filter has **Is [preset] / Between / After / Before**,
  with 20 presets ("Last 30 days" etc.). Filters show as removable chips.
- **Group by** one field (Client, Job Type, Status, Sub-Status, Tech, Zip, Service Area, Created By…). A group
  shows a count plus a **sum of every money field** and **rates** (Converted, Invoiced). Example: Job Type →
  Chimney Inspection 1,709 jobs, $923,767.01 total, 49.50% invoiced.
- **Sort** by a column, with a separate sort for the grouped view.
- **Search** box over the rows.
- **Export as…** (from Actions), **Save report** under a name ("Untitled report", renamed by pencil), and a
  "Custom reports" list.
- An "Ask AI for any report" box.

## What ours does today (main, 2026-10-09)

- 11 resources: Jobs, Customers, Branches, Services, Inventory, Fleet, Training, Leads, Scheduling, Monitoring,
  Human Resources. Joins follow the relation graph. Columns are gated by permission (job money needs
  `jobs.financials`).
- Filters: =, ≠, <, ≤, >, ≥, contains. AND only, one typed value. No date presets, no between, no "is one of".
- No sort, no group, no totals, no column renaming, no preview before saving, no reorder, no editing of a saved
  report in the UI (the server supports it), no CSV download (CSV only as an email attachment).
- Saved reports are filed to a company/branch. Emailing on a schedule goes through an Automations rule
  ("Email a report").
- Run view: plain text, money as raw cents, 5,000-row cap.

## What ours should offer (the list for the gallery)

**Building**
1. Start from a record type (as now), and the table fills in **live as fields are ticked**: the first 25 rows
   plus the total count. *Backend: preview of an unsaved definition.*
2. **Drag to reorder** columns (in the fields list and by dragging a header). Order is saved.
3. **Rename a column** in this report ("Total" → "Revenue").
4. Search the field list. Fields grouped by record, with related records (Customer of a Job, Branch…) one
   level open.

**Filtering**
5. Filter on **any field**, not only the chosen ones, shown as removable chips.
6. Dates: **presets** (Today, Yesterday, This/Last week, This/Last month, Last 7/30/90 days, This year…),
   Between, Before, After. Presets are relative, so a saved "Last month" report moves on with the calendar.
7. Text/choices: is, is not, contains, **is one of** (Status is Done or Pending), is empty / is not empty.
8. Numbers and money: =, ≠, <, >, **between**.
9. Any / all of the filters (OR as well as AND).

**Summarising**
10. **Group by** one field, or two (Branch → Tech), with dates grouped by **day / week / month**.
11. **Totals**: count, sum, average, min, max per number or money column, shown per group and as a grand-total
    row. Rates where they apply (share of jobs Done).
12. **Sort** by any column, either direction, in both the row view and the grouped view.

**Reading and sharing**
13. Money shown as money, dates in the company's format, a **CSV download**, and a PDF of what is on screen.
14. **Edit** a saved report (open it in the builder again), duplicate it, delete it.
15. **Schedule** an email straight from the report ("every Monday 7 am to …"). Today this is done in
    Automations, and should be one button here that creates that rule.
16. Paging past 5,000 rows for the screen; export keeps the cap and says so.

**More data to report on (backend, Reports)**
17. **Workiz** as resources: Workiz jobs (source, service area, tech, total, paid, due, created by, status
    dates), Workiz payments, invoices and estimates (`workiz_records`: amount, collected by, method, status).
    This is the data A-1 actually runs on, and the gap Workiz's own builder fills today.
18. Calls (RingCentral, from the Performance report) as a resource: queue, outcome, person, talk time.

**Not proposed**: an "Ask AI" box (Jose declined paid AI for this in 2026-09), and charts (not asked, and
Performance and the Dashboard already chart).

## Backend order (Reports), each piece gated and sent to the desk on its own

1. Preview endpoint for an unsaved definition: rows + count, the same permission and scope checks as a run.
2. Sort, column labels, money/date formatting, CSV download.
3. Filters: is-one-of, between, empty, date presets, any/all.
4. Group by (with date buckets) + count/sum/avg/min/max + grand total.
5. Workiz resources (jobs, payments, invoices, estimates).
6. Schedule-from-report (creates the Automations rule).
