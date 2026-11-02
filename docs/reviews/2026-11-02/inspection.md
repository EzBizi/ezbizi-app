# Inspection — review 2026-11-02

Measured on production (release 20261030222016-27b215107bb2) with read-only SQL and
renders, and the live A1 link redirect. No production data was changed.

## 1. Working

- **Forms:** Triplex Group "Chimney Inspection" is published at **v5**: the A1 form re-cut from 140 real October reports, with 7 system types, 4 screens and 120 fields. EzBizi Demo "Level 1 Chimney Inspection" is at v1.
- **Prefill on start:** Name, email, phone and address come from the client; the date comes from the job and lands in the date box (0.0.0373).
- **Step bar** on the form (0.0.0492): one step per screen asked, with ticks and tap-to-go-back.
- **Report PDF:** renders on prod (demo report, 0.08 s). It has the header (job, date, technician), footer (company, phone, page), Pass/Fail result, PASS/FAIL/N/A badges and the summary page (0.0.0493). It prints only the sections the inspection asked (7fbf4e82).
- **Testing tab** (UI-UX c365772bc) runs on `POST /inspections/{id}/rehearse`. It saves nothing (tested), lists versions, and Builder-only people can search (856feb0c7).
- **New Inspection search** finds clients by phone typed any way and shows address and branch (6256d9a77).
- **Old A1 report links:** `app.a-1chimney.com/api/inspection_report_view.php?id=27&t=…` → 302 → `app.ezbizi.com/api/v1/inspections/a1/27` → 200. A wrong token gets 404. All 13 captures are stored in EzBizi.
- **Report link for Workiz:** signed and permanent; archiving the report switches it off. Rule "Inspection report to Workiz" (inspection.submitted) is **on** for Triplex Group.
- **Dashboard glance:** figures plus attention for drafts nobody came back to.
- No failed jobs mentioning Inspection in the last 14 days.

## 2. Broken

- **Nobody files inspections in EzBizi yet.** Triplex Group has **0 reports** in EzBizi, while about 245 a week still arrive in Workiz from the old system (measured 10-16).
  - So the Workiz rule has never fired, and nothing above has been exercised by a real technician.
  - Not a code fault; the cutover hasn't happened (see 5).
- **3 inspections started since 09-25 and never finished** (Triplex, still on v4). They are test starts, and the dashboard flags them as overdue. Discard them, or press Start again to move them to v5.
- **No customer email or text on submit.** The old system emails and texts the report. EzBizi has the `inspection_report.email` action, but no rule uses it for Triplex Group. Configuring it is a tenant decision (see 5).
- **The PDF footer phone is mostly blank.** Only **5 of 33** Triplex companies have a phone, so most footers show the company name alone; 29 of 33 do have a logo. The old reports showed a branch phone and email, and EzBizi has no branch email field.
- **The PDF can't attach to Workiz itself** until the Workiz session's web-login upload ships. Today only the link is written.
- Two empty "Untitled inspection" drafts (Demo and Triplex) are left from opening the Builder. They're harmless clutter.

## 3. Cohesiveness

- The step bar is the automation builder's timeline, now the shared `StepTimeline`; UI-UX will move the automation builder onto it.
- The report PDF uses a plain sans-serif and its own colours (#2e7d32 / #c62828) rather than the app theme. That's fine for print, but it doesn't match invoices (DejaVu, centred logo). The two customer PDFs should share one header and footer.
- In the Builder, finding wording follows A1's capitalisation ("Meets Industry Standards"), while the rest of EzBizi uses sentence case.

## 4. Suggestions (ranked)

1. **Cut over one branch.** Have techs file there for a week and compare against their Workiz reports. That's the only way to prove the module end to end.
2. **Add the email and SMS rule on submit** for the cutover branch. That's parity with the old system.
3. **A branch phone and email on the report footer** (franchise-level contact). It needs a field that doesn't exist yet.
4. **Terms and conditions on the report**, authored per company as a note on the Sign off screen. The old reports end with T&C and both signatures.
5. **A photo gallery page**, optional, because it doubles PDF size.
6. **One shared PDF header and footer** for invoices and inspection reports.

## 5. Needs Jose

- Which branch cuts over first, and when?
- Should EzBizi email and text the customer the report on submit, like the old system? Same wording?
- Terms and conditions: one shared text, or one per branch (the old ones name the city and state)?
- Where should the branch phone and email on reports come from? Fill in company phones, or add a branch contact?
