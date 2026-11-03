# Airtable retirement inventory: Triplex Group (a-1-chimney), 2026-11-03

Measured live on prod via the org's Airtable token (all 6 connections hold the same token; it sees 6 bases). Rows = full pagination. Last modified: no table has a Last Modified field, so it is max(createdTime) over all rows (= newest row added; edits are not visible).
"Read by EzBizi" = a financial_sources row maps it (hourly `ezbizi:airtable-snapshot` copies it to airtable_records; Financials/P&L/Reports texts/WhatsApp period reports read the copy; mirrored Financials tables also write back via AirtableWriter).

| base | table | rows | last modified (newest createdTime) | read by EzBizi? | maps to module | notes |
|---|---|---|---|---|---|---|
| Deposit Jobs Tracker | NJ | 450 | 2026-11-01 | Yes: snapshot source `deposits`, last copied 2026-11-03 17:17:00 | Financials (Reports > deposits) |  |
| Deposit Jobs Tracker | CT | 168 | 2026-11-01 | Yes: snapshot source `deposits`, last copied 2026-11-03 17:17:00 | Financials (Reports > deposits) |  |
| Deposit Jobs Tracker | TX | 226 | 2026-11-01 | Yes: snapshot source `deposits`, last copied 2026-11-03 17:17:00 | Financials (Reports > deposits) |  |
| Deposit Jobs Tracker | OH | 289 | 2026-11-01 | Yes: snapshot source `deposits`, last copied 2026-11-03 17:17:00 | Financials (Reports > deposits) |  |
| Deposit Jobs Tracker | GA | 121 | 2026-11-02 | Yes: snapshot source `deposits`, last copied 2026-11-03 17:17:00 | Financials (Reports > deposits) |  |
| Deposit Jobs Tracker | IL | 276 | 2026-11-01 | Yes: snapshot source `deposits`, last copied 2026-11-03 17:17:00 | Financials (Reports > deposits) |  |
| Deposit Jobs Tracker | DMV | 135 | 2026-11-02 | Yes: snapshot source `deposits`, last copied 2026-11-03 17:17:00 | Financials (Reports > deposits) |  |
| Deposit Jobs Tracker | NY | 6 | 2026-10-18 | Yes: snapshot source `deposits`, last copied 2026-11-03 17:17:00 | Financials (Reports > deposits) |  |
| Deposit Jobs Tracker | MA | 26 | 2026-11-01 | No | UNMAPPED | MA deposits table exists but no deposits source maps it |
| Deposit Jobs Tracker | NW | 4 | 2026-05-04 | Yes: snapshot source `deposits`, last copied 2026-11-03 17:17:00 | Financials (Reports > deposits) |  |
| Deposit Jobs Tracker | Everdoors | 17 | 2026-11-02 | No | UNMAPPED | Everdoors deposits, not mapped |
| Technicians Payrolls | Example | 16 | 2026-10-11 | Yes: snapshot source `payroll`, last copied 2026-11-03 17:17:00 | Financials (technician payroll); HR-adjacent |  |
| Technicians Payrolls | Table 1 copy | 0 | - | Yes: snapshot source `payroll`, last copied never (empty) | Financials (technician payroll); HR-adjacent | empty table |
| Repair Teams | NJ | 247 | 2026-11-01 | Yes: snapshot source `repair`, last copied 2026-11-03 17:17:00 | Financials (repair team sheets) |  |
| Repair Teams | CT | 123 | 2026-11-01 | Yes: snapshot source `repair`, last copied 2026-11-03 17:17:00 | Financials (repair team sheets) |  |
| Repair Teams | OHIO | 38 | 2026-10-14 | Yes: snapshot source `repair`, last copied 2026-11-03 17:17:00 | Financials (repair team sheets) |  |
| Repair Teams | TX | 91 | 2026-11-02 | Yes: snapshot source `repair`, last copied 2026-11-03 17:17:00 | Financials (repair team sheets) |  |
| Repair Teams | IL | 1 | 2026-10-05 | Yes: snapshot source `repair`, last copied 2026-11-03 17:17:00 | Financials (repair team sheets) |  |
| Repair Teams | GA | 0 | - | Yes: snapshot source `repair`, last copied never (empty) | Financials (repair team sheets) | empty table |
| Repair Teams | DMV | 9 | 2026-09-22 | Yes: snapshot source `repair`, last copied 2026-11-03 17:17:00 | Financials (repair team sheets) |  |
| Repair Teams | MA | 0 | - | Yes: snapshot source `repair`, last copied never (empty) | Financials (repair team sheets) | empty table |
| Profit And Loss | NJ | 166 | 2026-10-28 | Yes: snapshot source `pnl`, last copied 2026-11-03 17:17:00 | Financials / Profit & Loss |  |
| Profit And Loss | CT | 96 | 2026-10-28 | Yes: snapshot source `pnl`, last copied 2026-11-03 17:17:00 | Financials / Profit & Loss |  |
| Profit And Loss | TX | 114 | 2026-10-28 | Yes: snapshot source `pnl`, last copied 2026-11-03 17:17:00 | Financials / Profit & Loss |  |
| Profit And Loss | OH | 152 | 2026-10-28 | Yes: snapshot source `pnl`, last copied 2026-11-03 17:17:00 | Financials / Profit & Loss |  |
| Profit And Loss | IL | 189 | 2026-10-28 | Yes: snapshot source `pnl`, last copied 2026-11-03 17:17:00 | Financials / Profit & Loss |  |
| Profit And Loss | GA | 82 | 2026-10-28 | Yes: snapshot source `pnl`, last copied 2026-11-03 17:17:00 | Financials / Profit & Loss |  |
| Profit And Loss | DMV | 105 | 2026-10-28 | Yes: snapshot source `pnl`, last copied 2026-11-03 17:17:00 | Financials / Profit & Loss |  |
| Profit And Loss | WA | 45 | 2026-09-16 | Yes: snapshot source `pnl`, last copied 2026-11-03 17:17:00 | Financials / Profit & Loss |  |
| Profit And Loss | NW | 126 | 2026-10-28 | Yes: snapshot source `pnl`, last copied 2026-11-03 17:17:00 | Financials / Profit & Loss |  |
| Profit And Loss | NY | 24 | 2026-10-21 | Yes: snapshot source `pnl`, last copied 2026-11-03 17:17:00 | Financials / Profit & Loss |  |
| Profit And Loss | MA | 14 | 2026-10-28 | Yes: snapshot source `pnl`, last copied 2026-11-03 17:17:00 | Financials / Profit & Loss |  |
| Invoices System | Weekly Data | 10 | 2026-10-13 | Yes: snapshot source `weekly_data`, last copied 2026-11-03 18:17:01 | Financials |  |
| Invoices System | Companies | 20 | 2026-09-22 | Yes: snapshot source `companies`, last copied 2026-11-03 18:17:01 | Financials (company list) |  |
| Invoices System | Invoices | 9 | 2026-10-14 | No | UNMAPPED | nearest: Financials (invoices) / Jobs invoices |
| Invoices System | Weekly HQ Expenses | 130 | 2026-10-28 | Yes: snapshot source `hq_expenses`, last copied 2026-11-03 18:17:01 | Financials |  |
| Invoices System | Ave Office expenses per job | 17 | 2026-10-28 | Yes: snapshot source `office_per_job`, last copied 2026-11-03 18:17:01 | Financials |  |
| Invoices System | Reviews and office lease cost (Monthly) | 14 | 2026-10-12 | Yes: snapshot source `lease_reviews`, last copied 2026-11-03 18:17:01 | Financials |  |
| Invoices System | Monthly Billings | 64 | 2026-10-12 | Yes: snapshot source `monthly_bills`, last copied 2026-11-03 18:17:01 | Financials |  |
| Invoices System | Marketing Performance (ANGI SWEEP) | 24 | 2026-10-07 | Yes: snapshot source `marketing`, last copied 2026-11-03 18:17:01 | Financials + Marketing report |  |
| Invoices System | Marketing Performance (ace) Daily | 114 | 2026-11-03 | Yes: snapshot source `marketing`, last copied 2026-11-03 18:17:01 | Financials + Marketing report |  |
| Invoices System | Marketing Performance (ace) weekly | 17 | 2026-10-07 | Yes: snapshot source `marketing`, last copied 2026-11-03 18:17:01 | Financials + Marketing report |  |
| Disputes And Refunds | Refunds | 153 | 2026-11-01 | Yes: snapshot source `refunds`, last copied 2026-11-03 18:17:01 | Financials |  |
| Disputes And Refunds | Disputes | 32 | 2026-11-01 | Yes: snapshot source `disputes`, last copied 2026-11-03 18:17:01 | Financials |  |
| Disputes And Refunds | Checks Returns | 6 | 2026-10-25 | Yes: snapshot source `check_returns`, last copied 2026-11-03 18:17:01 | Financials |  |
| Disputes And Refunds | Debt & Loans | 4 | 2026-05-03 | No | UNMAPPED | nearest: Financials |

Totals: 6 bases, 46 tables; 42 read by EzBizi (all Financials-family), 4 UNMAPPED.

Removed 2026-11-03: repair source 01a1547f-ce82-7269-b350-460b5e9b97b2 (Repair Teams / tblo2IX58b5GWiU7E, markers Job / Weekly Summary). That table no longer exists in the base, which is what failed every hourly snapshot. Backup /var/backups/ezbizi/airtable-repair-source-20261103-181813.json.
