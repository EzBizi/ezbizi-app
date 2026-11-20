# Airtable views inventory — A-1 Group (2026-11-20)

Source: Airtable metadata API (`GET /v0/meta/bases/{base}/tables`), read-only, via the org's connection token on prod. The API gives view names/types only; filters, groups, sorts, hidden fields and colours must be read in the Airtable UI (blank column below). Field ids in formulas are replaced by field names.

## Totals

- Tables: 46
- Views: 107 — grid 106, form 1
- Formula fields: 107
- Rollup/lookup/count fields: 15

| Base | Tables | Views | Formulas |
|---|---|---|---|
| Disputes And Refunds | 4 | 13 | 4 |
| Technicians Payrolls | 2 | 4 | 4 |
| Invoices System | 10 | 19 | 10 |
| Deposit Jobs Tracker | 11 | 33 | 22 |
| Repair Teams | 8 | 16 | 56 |
| Profit And Loss | 11 | 22 | 11 |

Field types: currency 206, singleLineText 136, formula 107, singleSelect 77, date 48, number 31, checkbox 27, multipleLookupValues 15, multipleAttachments 10, multilineText 6, multipleRecordLinks 6

Formula functions used (count): DATETIME_FORMAT 101, DATEADD 88, MOD 76, WEEKDAY 76, IF 63, TODAY 38, BLANK 34

Note: `BALANCE(` in a regex count is a false positive (text "Balance (Workiz): " inside a string); the real functions are 7.
Operators used: `&` (string join) 107, `-` 209, `+` 80, `*` 26, `/` 9, `=` 27.

## Gap vs EzBizi Financials (as of main ad07b81da)

What EzBizi has: field types text/long_text/number/currency/percent/date/checkbox/single_select/multi_select/email/phone/url/rating/formula/attachment; views with `filters` (AND-only; ops is, is_not, gt, lt, empty, not_empty, contains), `sorts`, `hidden`, kanban `group_field` (single select only); formula engine `evaluateFormula` in client/lib/screens/financials/financial_cells.dart = `{Field}` refs, numbers, strings, + - * /, `&` only ("ponytail: arithmetic and joins only; add functions when asked"), no nested formulas.

Missing for parity:
1. Formula functions: IF (63), DATETIME_FORMAT (101), DATEADD (88), MOD (76), WEEKDAY (76), TODAY (38), BLANK (34); plus `=` comparison and formulas referencing other formulas (nested refs throw today).
2. Field types: multipleLookupValues (15 lookups), multipleRecordLinks (6 links between tables). No rollup or count fields exist in these bases.
3. View features (to confirm in the UI per view, since the API hides them): OR / nested filter groups; relative-date filters (today, this week, within N days) — the DATEADD/WEEKDAY/TODAY formulas suggest week-bucket filtering; filters on formula fields (need server or client eval before filtering); grid grouping (group-by rows with group headers and summary rows — EzBizi grids have no grouping); conditional row colours; field summary bar (sum/avg footers); per-view column order/width; form view (1 form view).
4. View types: 106 grid + 1 form; no kanban/calendar/gallery, so EzBizi's kanban is not needed for parity.

Effort estimate: formula functions (7 + comparison + nested refs) ~1-2 days with tests; lookups/links ~2-3 days; grid grouping + summaries ~2 days; OR filter groups + relative dates + formula-field filters ~2 days; conditional colours ~1 day; form view ~1 day; then per-view config transcription for 107 views (needs UI reading, ~10 min/view ≈ 2 days). Total ≈ 2 weeks of one session.

## Disputes And Refunds (`appe1vSSRRvKToShq`)


### Refunds (`tblxCKagahNfmjaSQ`) — 14 fields, 4 views

| Field | Type | Formula / reference / result |
|---|---|---|
| Date | date |  |
| Company Name | singleSelect | choices: A1 CHIMNEY NJ, A1 CHIMNEY CT, A1 CHIMNEY TX, A1 CHIMNEY OH, A1 CHIMNEY IL, A1 CHIMNEY GA, A1 CHIMNEY DMV, A1 CHIMNEY NW, A1 CHIMNEY WA, everdoors, , A1 CHIMNEY MA |
| Job Number | singleLineText |  |
| Technician Name | singleLineText |  |
| External Company | singleLineText |  |
| Total Jobs | currency |  |
| Refund Amount | currency |  |
| Open/Close Job | singleSelect | choices: Open, Closed |
| Tech Part | currency |  |
| External Part | formula | `IF(
   {External Company},
   {Tech Part} * 2,
   BLANK()
 )` → number |
| Deduction Status | checkbox |  |
| Refund Status | checkbox |  |
| Name & Address for Check | multilineText |  |
| Reason | multilineText |  |

| View | Type | Filters / groups / sorts / hidden fields / colours — read from UI |
|---|---|---|
| Main Table (`viwbqyEbVXOBGdLEh`) | grid | |
| Deduction List (`viwvamgDiEvLktvrt`) | grid | |
| Open Jobs (`viwyr68mZvTBZcZPP`) | grid | |
| Closed Jobs  (`viwUi1wKtP4BuTenv`) | grid | |

### Disputes (`tblxkLPCe759GoPzn`) — 14 fields, 4 views

| Field | Type | Formula / reference / result |
|---|---|---|
| Date | date |  |
| Company Name | singleSelect | choices: A1 CHIMNEY NJ, A1 CHIMNEY CT, A1 CHIMNEY TX, A1 CHIMNEY OH, A1 CHIMNEY IL, A1 CHIMNEY GA, A1 CHIMNEY DMV, A1 CHIMNEY NW, A1 CHIMNEY WA, Everdoors, , A1 CHIMNEY NY |
| Job Number | singleLineText |  |
| Technician Name | singleLineText |  |
| External Company | singleLineText |  |
| Total Jobs | currency |  |
| Dispute Amount | currency |  |
| Open/Close Job | singleSelect | choices: Open, Closed |
| Tech Part | currency |  |
| External Part | formula | `IF(
   {External Company},
   {Tech Part} * 2,
   BLANK()
 )` → number |
| Deduction Status | checkbox |  |
| Dispute Decision | singleSelect | choices:  Counter the dispute - Submitted eviences, Dispute Accepted  |
| Submitted Evidences | checkbox |  |
| Reason | multilineText |  |

| View | Type | Filters / groups / sorts / hidden fields / colours — read from UI |
|---|---|---|
| Main Table (`viwb8zjxZN6v0iqlO`) | grid | |
| Deduction List (`viwBRLLHJ7MigdLTz`) | grid | |
| Open Jobs (`viwy97NI3lbvjhEwm`) | grid | |
| Closed Jobs  (`viwU02b6xFmvOYT42`) | grid | |

### Checks Returns (`tblpTKRRhUrirBRpy`) — 14 fields, 3 views

| Field | Type | Formula / reference / result |
|---|---|---|
| Date | date |  |
| Company Name | singleSelect | choices: A1 CHIMNEY NJ, A1 CHIMNEY CT, A1 CHIMNEY TX, A1 CHIMNEY OH, A1 CHIMNEY IL, A1 CHIMNEY GA, A1 CHIMNEY DMV, A1 CHIMNEY NW, A1 CHIMNEY NY, A1 CHIMNEY MA, Everdoors,  |
| Job Number | singleLineText |  |
| Technician Name | singleLineText |  |
| External Company | singleLineText |  |
| Total Jobs | currency |  |
| Amount of check | currency |  |
| Open/Close Job | singleSelect | choices: Open, Closed |
| Tech Part | currency |  |
| External Part | formula | `IF(
   {External Company},
   {Tech Part} * 2,
   BLANK()
 )` → number |
| Deduction Status | checkbox |  |
| Type | singleSelect | choices: Stop Payment, Insufficient Funds, Other Reason |
| Workiz Tag & informed the Team | checkbox |  |
| Field 17 | singleLineText |  |

| View | Type | Filters / groups / sorts / hidden fields / colours — read from UI |
|---|---|---|
| Main Table (`viw3HylM2AsELvsbZ`) | grid | |
| Open Jobs (`viwqI6PX68xE4uGmx`) | grid | |
| Closed Jobs  (`viwMz1dlAsIEzbVUd`) | grid | |

### Debt & Loans (`tblfZyCc2sp0RohEz`) — 11 fields, 2 views

| Field | Type | Formula / reference / result |
|---|---|---|
| Date | date |  |
| Company Name | singleSelect | choices: A1 Chimney NW, A1 Chimney NJ, A1 Chimney TX, A1 Chimney CT, A1 Chimney OH, A1 Chimney IL , A1 Chimney GA, A1 Chimney DMV, A1 Chimney WA, Triplex |
| Tech Name | singleLineText |  |
| Type | singleSelect | choices: Debt, Credit |
| Debt/credit Amount | currency |  |
| Refund Amount | currency |  |
| Remaining Balance | formula | `{Debt/credit Amount} - {Refund Amount}` → number |
| Refund/ Credit Complete | checkbox |  |
| Date of Refund/credit | date |  |
| Next Payment Due | date |  |
| Notes | multilineText |  |

| View | Type | Filters / groups / sorts / hidden fields / colours — read from UI |
|---|---|---|
| Open Debt/Loans (`viwUveD4cIbIjkpR4`) | grid | |
| Closed Debt (`viw5f4GrD9WiuJOgU`) | grid | |

## Technicians Payrolls (`app5aENjfgesVBPLD`)


### Example (`tblcJbWa9kPTq0rTv`) — 10 fields, 3 views

| Field | Type | Formula / reference / result |
|---|---|---|
| Week | date |  |
| Company Name | singleSelect | choices: A1 CHIMNEY CT, A1 CHIMNEY OH, A1 CHIMNEY TX, A1 CHIMNEY GA, A1 CHIMNEY IL, A1 CHIMNEY DMV, A1 CHIMNEY NJ, A1 CHIMNEY NW, A1 CHIMNEY WA, EVERDOORS  |
| Technicians | singleSelect | choices: Neorai Maamari , Josh Cohlon , Rotem Hadad, Bar Boker , Dorel Ben Dvid, Eli Igurshin, Ronald , Kim Rudolf ,   Ali Nakhaie, Omer Weissman, Moshe Hai Zion, Duct Duct, Motti Nussbaum , Suf Ben Haim, Suheil Hamaty, Liraz Sapir , Liath Dror |
| Cash to transfer | currency |  |
| Workiz Balance | currency |  |
| Insurance | currency |  |
| Deductions | currency |  |
| Extra | currency |  |
| Balance | formula | `{Workiz Balance} - {Insurance} - {Deductions} + {Extra}` → number |
| Message | formula | `{Technicians} & "\n\n" &
 "Balance (Workiz): " & {Workiz Balance} & "\n\n" &
 "Insurance: -" & {Insurance} & "\n" &
 "Adjustments: -" & {Deductions} & "\n" &
 "Extra: +" & {Extra} & "\n\n" &
 "Balance: " & {Balance} & "\n" &
 "Cash to transfer: " & {Cash to transfer}` → singleLineText |

| View | Type | Filters / groups / sorts / hidden fields / colours — read from UI |
|---|---|---|
| Grid view (`viwJO3vvhRyywyFSZ`) | grid | |
| Form (`viwqRRQ3WVupZ1j1K`) | form | |
| Summary View (`viwswZ67mx0RKsF1p`) | grid | |

### Table 1 copy (`tblhfcWtRfg5qqhhL`) — 10 fields, 1 views

| Field | Type | Formula / reference / result |
|---|---|---|
| Week | date |  |
| Company Name | singleSelect | choices: A1 CHIMNEY CT, A1 CHIMNEY OH, A1 CHIMNEY TX, A1 CHIMNEY GA, A1 CHIMNEY IL, A1 CHIMNEY DMV, A1 CHIMNEY NJ, A1 CHIMNEY NW, A1 CHIMNEY WA, EVERDOORS  |
| Technicians | singleSelect | choices: Neorai Maamari , Josh Cohlon , Rotem Hadad, Bar Boker ,  |
| Cash to transfer | currency |  |
| Workiz Balance | currency |  |
| Insurance | currency |  |
| Deductions | currency |  |
| Extra | currency |  |
| Balance | formula | `{Workiz Balance} - {Insurance} - {Deductions} + {Extra}` → number |
| Message | formula | `"Balance (Workiz): " & {Workiz Balance} & "\n\n" &
 "Insurance: -" & {Insurance} & "\n" &
 "Adjustments: -" & {Deductions} & "\n" &
 "Extra: +" & {Extra} & "\n\n" &
 "Balance: " & {Balance} & "\n" &
 "Cash to transfer: " & {Cash to transfer}` → singleLineText |

| View | Type | Filters / groups / sorts / hidden fields / colours — read from UI |
|---|---|---|
| Grid view (`viwOk4vOZMZKwYvgf`) | grid | |

## Invoices System (`app6ZZgY9nHX8mMih`)


### Weekly Data (`tblzC1laiwxmLw50S`) — 17 fields, 3 views

| Field | Type | Formula / reference / result |
|---|---|---|
| Blank | date |  |
| Company Name | multipleRecordLinks | → table tblC2qB7ew6in1YTC |
| Invoice Date | date |  |
| Total Jobs | number |  |
| Office Bills | currency |  |
| Office Payrolls | currency |  |
| Google charges LS | number |  |
| Indeed | currency |  |
| Parts | currency |  |
| Royalties Payment | currency |  |
| Total | currency |  |
| Note | formula | `{Company Name} & " to HQ" & "\n" &
 "HQ bills: " & {Office Bills} & "\n" &
 "Office Payrolls: " & {Office Payrolls} & "\n" &
 "Google charges: " & {Marketing Expenses} & "\n" &
 "Indeed: " & {Indeed} & "\n" &
 "Parts: " & {Parts} & "\n" &
 "Franchise Royalties: " & {Royalties Payment} & "\n" &
 "Total: " & {Total}` → singleLineText |
| Marketing Expenses | formula | `{Google charges LS} ` → currency |
| Invoices | multipleRecordLinks | → table tbl2hMx4yL3Cski7l |
| Create Invoice | checkbox |  |
| Week | formula | `DATETIME_FORMAT(
   DATEADD({Invoice Date}, 1-DATETIME_FORMAT({Invoice Date}, 'E'), 'days'),
   'D MMM'
 )
 &
 ' - '
 &
 DATETIME_FORMAT(
   DATEADD({Invoice Date}, 7-DATETIME_FORMAT({Invoice Date}, 'E'), 'days'),
   'D MMM'
 )` → singleLineText |
| Invoices 3 | singleLineText |  |

| View | Type | Filters / groups / sorts / hidden fields / colours — read from UI |
|---|---|---|
| Grid view (`viwfddF2aET2Qxfzr`) | grid | |
| Previous Weeks (`viw9bLSCz9du2Ol05`) | grid | |
| Monthly  (`viwZCQgMclX0VHvso`) | grid | |

### Companies (`tblC2qB7ew6in1YTC`) — 12 fields, 1 views

| Field | Type | Formula / reference / result |
|---|---|---|
| Company Name | singleLineText |  |
| Bills To Name | singleLineText |  |
| Address Line 1 | singleLineText |  |
| Address Line 2 | singleLineText |  |
| City State Zip | multilineText |  |
| Invoice Prefix | singleLineText |  |
| Logo | multipleAttachments |  |
| Weekly Upload Link | singleLineText |  |
| Notes | multilineText |  |
| Attachment | multipleAttachments |  |
| Weekly Data | multipleRecordLinks | → table tblzC1laiwxmLw50S |
| Invoices | multipleRecordLinks | → table tbl2hMx4yL3Cski7l |

| View | Type | Filters / groups / sorts / hidden fields / colours — read from UI |
|---|---|---|
| Grid view (`viwajbgjIf2EBCFMv`) | grid | |

### Invoices (`tbl2hMx4yL3Cski7l`) — 22 fields, 1 views

| Field | Type | Formula / reference / result |
|---|---|---|
| Date | date |  |
| Invoice Date | multipleLookupValues | via Weekly Record; field fldhE7DBNoXC3bsRi → date |
| Company | multipleRecordLinks | → table tblC2qB7ew6in1YTC |
| Weekly Record | multipleRecordLinks | → table tblzC1laiwxmLw50S |
| Prefix | multipleLookupValues | via Company; field fldly5OPoAinFXTTG → singleLineText |
| Invoice Number | formula | `{Prefix} & DATETIME_FORMAT({Invoice Date}, "MMDDYY")` → singleLineText |
| Due Date | formula | `{Invoice Date}` → date |
| Invoice Date (from Due Date) | multipleLookupValues | via None; field fldhE7DBNoXC3bsRi |
| Jobs Created | multipleLookupValues | via Weekly Record; field fldGQEuRTkA30Zl9X → number |
| Marketing Expenses | multipleLookupValues | via Weekly Record; field fldAsvNPwdswe9sI4 → currency |
| Office Payrolls | multipleLookupValues | via Weekly Record; field fldaIDz9tQ84f4u22 → currency |
| Royalties Payment | multipleLookupValues | via Weekly Record; field fld9NADSfieQLx2MN → currency |
| Office Bills | multipleLookupValues | via Weekly Record; field fldv13NZJutLeoOJ5 → currency |
| Indeed | multipleLookupValues | via Weekly Record; field fldx2eQ5WgV4CZ9Qf → currency |
| Parts | multipleLookupValues | via Weekly Record; field fldPQPJckj5tiorkd → currency |
| Total | multipleLookupValues | via Weekly Record; field flduqK6v2SJTli2Gl → currency |
| Weekly Record copy | singleLineText |  |
| Logo | multipleLookupValues | via Company; field fldIuYTBbsId0S1j3 → multipleAttachments |
| Bill To Name | multipleLookupValues | via Company; field fldcuZ4B3dIcGIdi7 → singleLineText |
| Address Line 1 | multipleLookupValues | via Company; field fldpNuc2B7fIN5AYV → singleLineText |
| Address Line 2 | multipleLookupValues | via Company; field fld7JTcgT6QMoNlOj → multilineText |
| From field: Due Date | singleLineText |  |

| View | Type | Filters / groups / sorts / hidden fields / colours — read from UI |
|---|---|---|
| Grid view (`viwT0HKNzAOn8lxtc`) | grid | |

### Weekly HQ Expenses (`tblmV1sk6qs4QbUWU`) — 4 fields, 2 views

| Field | Type | Formula / reference / result |
|---|---|---|
| Date | date |  |
| Category Name | singleLineText |  |
| Cost | currency |  |
| Week | formula | `DATETIME_FORMAT(
   DATEADD({Date}, 1-DATETIME_FORMAT({Date}, 'E'), 'days'),
   'D MMM'
 )
 &
 ' - '
 &
 DATETIME_FORMAT(
   DATEADD({Date}, 7-DATETIME_FORMAT({Date}, 'E'), 'days'),
   'D MMM'
 )` → singleLineText |

| View | Type | Filters / groups / sorts / hidden fields / colours — read from UI |
|---|---|---|
| Grid view (`viwWG9IhZho9Z4TSx`) | grid | |
| Previous Weeks (`viwcufV3DO4UdKbln`) | grid | |

### Ave Office expenses per job (`tblDaxgxfcaUBeQPT`) — 4 fields, 2 views

| Field | Type | Formula / reference / result |
|---|---|---|
| Date | date |  |
| WEEK | formula | `DATETIME_FORMAT(
   DATEADD({Date}, 1-DATETIME_FORMAT({Date}, 'E'), 'days'),
   'D MMM'
 )
 &
 ' - '
 &
 DATETIME_FORMAT(
   DATEADD({Date}, 7-DATETIME_FORMAT({Date}, 'E'), 'days'),
   'D MMM'
 )` → singleLineText |
| Ave Office Payrolls per Job | number |  |
| Ave Office Expenses Per job | currency |  |

| View | Type | Filters / groups / sorts / hidden fields / colours — read from UI |
|---|---|---|
| Grid view (`viwdVFwu836ZK7PLw`) | grid | |
| Previous Weeks (`viw7QVqze06Efz8ef`) | grid | |

### Reviews and office lease cost (Monthly) (`tblQRWVs4Xz53H8Xf`) — 4 fields, 2 views

| Field | Type | Formula / reference / result |
|---|---|---|
| Date | date |  |
| Company name | singleSelect | choices: NJ, CT, TX, OH, IL, GA, DMV, MA, NY, NW |
| Reviews Cost | currency |  |
| Lease Cost | currency |  |

| View | Type | Filters / groups / sorts / hidden fields / colours — read from UI |
|---|---|---|
| Grid view (`viwqC4bpXOvacA7TS`) | grid | |
| Previous Weeks (`viwGqaobBlbVqgpmI`) | grid | |

### Monthly Billings (`tbl0xxoIuToQWIYmN`) — 4 fields, 2 views

| Field | Type | Formula / reference / result |
|---|---|---|
| Date | date |  |
| Company name | singleSelect | choices: NJ, CT, TX, OH, IL, GA, DMV, MA, NY, NW |
| Bills Category | singleLineText |  |
| Cost | currency |  |

| View | Type | Filters / groups / sorts / hidden fields / colours — read from UI |
|---|---|---|
| Grid view (`viwAiFEFnKkV5BXiq`) | grid | |
| Previous Weeks (`viwQ6LRr1h0GjhfLg`) | grid | |

### Marketing Performance (ANGI SWEEP) (`tblxgh8Ow0XpuKg5B`) — 11 fields, 2 views

| Field | Type | Formula / reference / result |
|---|---|---|
| DATE | date |  |
| Week | formula | `DATETIME_FORMAT(
   DATEADD({DATE}, 1-DATETIME_FORMAT({DATE}, 'E'), 'days'),
   'D MMM'
 )
 &
 ' - '
 &
 DATETIME_FORMAT(
   DATEADD({DATE}, 7-DATETIME_FORMAT({DATE}, 'E'), 'days'),
   'D MMM'
 )` → singleLineText |
| Service Area | singleSelect | choices: Connecticut, Virginia - DMV, Maryland - DMV, DC - DMV, New Jersey, Ohio, Georgia, Texas - DFW, Illinois |
| COST | currency |  |
| CALLS | number |  |
| JOBS | number |  |
| AVE COST PER JOBS | number |  |
| PROFIT | currency |  |
| TOTAL SALES | currency |  |
| NET PROFIT | currency |  |
| CONV (COST PER SALES) | number |  |

| View | Type | Filters / groups / sorts / hidden fields / colours — read from UI |
|---|---|---|
| Grid view (`viw71poLpRTuDDf1e`) | grid | |
| Previous Weeks  (`viwiWH0pTHrC6BPXH`) | grid | |

### Marketing Performance (ace) Daily (`tblqhXDymSAR6S3G6`) — 11 fields, 2 views

| Field | Type | Formula / reference / result |
|---|---|---|
| DATE | date |  |
| Week | formula | `DATETIME_FORMAT(
   DATEADD({DATE}, 1-DATETIME_FORMAT({DATE}, 'E'), 'days'),
   'D MMM'
 )
 &
 ' - '
 &
 DATETIME_FORMAT(
   DATEADD({DATE}, 7-DATETIME_FORMAT({DATE}, 'E'), 'days'),
   'D MMM'
 )` → singleLineText |
| Service Area | singleSelect | choices: Connecticut, MD Baltiomr, MD DC, New Jersey, Atlanta, Cleveland , Columbus, Chicago |
| COST | currency |  |
| Clicks | number |  |
| JOBS | number |  |
| AVE COST PER JOBS | number |  |
| PROFIT | currency |  |
| TOTAL SALES | currency |  |
| NET PROFIT | currency |  |
| CONV (COST PER SALES) | number |  |

| View | Type | Filters / groups / sorts / hidden fields / colours — read from UI |
|---|---|---|
| Grid view (`viw025TvfJwWfL2CJ`) | grid | |
| Previous Weeks  (`viwbXnv9Jz44IJCyc`) | grid | |

### Marketing Performance (ace) weekly (`tblXCVvrqnN304ERY`) — 11 fields, 2 views

| Field | Type | Formula / reference / result |
|---|---|---|
| DATE | date |  |
| Week | formula | `DATETIME_FORMAT(
   DATEADD({DATE}, 1-DATETIME_FORMAT({DATE}, 'E'), 'days'),
   'D MMM'
 )
 &
 ' - '
 &
 DATETIME_FORMAT(
   DATEADD({DATE}, 7-DATETIME_FORMAT({DATE}, 'E'), 'days'),
   'D MMM'
 )` → singleLineText |
| Service Area | singleSelect | choices: Connecticut, MD Baltiomr, MD DC, New Jersey, Atlanta, Cleveland, Columbus, Chicago, DFW |
| COST | currency |  |
| Clicks | number |  |
| JOBS | number |  |
| AVE COST PER JOBS | number |  |
| PROFIT | currency |  |
| TOTAL SALES | currency |  |
| NET PROFIT | currency |  |
| CONV (COST PER SALES) | number |  |

| View | Type | Filters / groups / sorts / hidden fields / colours — read from UI |
|---|---|---|
| Grid view (`viwxn3LojeJ89XDNB`) | grid | |
| Previous Weeks  (`viwIiln2N4hgCVdJ4`) | grid | |

## Deposit Jobs Tracker (`appadTMQltMGmGrse`)


### NJ (`tbljbx2p8nsQ7adRL`) — 10 fields, 3 views

| Field | Type | Formula / reference / result |
|---|---|---|
| Date | date |  |
| Technician | singleSelect | choices: Menahem Maamari, Akiva, Motty Nussbaum, Eli Shemesh, ron paz, Kim, Nahman Melici, Ori Avner Karva , Guy Buganim, Angel Canela, Shuki Janch, Yalli Regev, Shahar Tavidi, Ori Avner Karva, Yosef Nasi, Yehonatan Aron, Shuki Janach, Shlomo Dahan, Yehuda Dahan, Guy Shimonov, Yoel Baruch, Noga Hadad |
| Job Number | singleLineText |  |
| Total Job Amount | currency |  |
| Amount Paid | currency |  |
| Payment Method | singleSelect | choices: Cash, Check, Credit Card, Other, Bank Transfer, Zelle |
| Status Of Payment | checkbox |  |
| Status Of Job | singleSelect | choices: Open, Closed |
| Outstanding Balance | formula | `{Total Job Amount} - {Amount Paid}` → number |
| Current Week | formula | `IF(
   DATETIME_FORMAT(
     DATEADD({Date}, -MOD(WEEKDAY({Date}) + 6, 7), 'days'),
     'YYYY-MM-DD'
   )
   =
   DATETIME_FORMAT(
     DATEADD(TODAY(), -MOD(WEEKDAY(TODAY()) + 6, 7), 'days'),
     'YYYY-MM-DD'
   ),
   "Current Week",
   "Previous Weeks"
 )` → singleLineText |

| View | Type | Filters / groups / sorts / hidden fields / colours — read from UI |
|---|---|---|
| Current Week (`viwwJ7Nev6bknf4TI`) | grid | |
| Previous Weeks Jobs (`viwCX8v81QiyxuQQB`) | grid | |
| Closed Jobs (`viwgvjSBXv9FH57K5`) | grid | |

### CT (`tbl81ZTowT57Ex9UD`) — 10 fields, 3 views

| Field | Type | Formula / reference / result |
|---|---|---|
| Date | date |  |
| Technician | singleSelect | choices: Neorai Maamari, Josh Colon, Zack Schwartz, Adir Shemesh, amit, Ben Klezkin, Moshe Gafni, Ohad Aharoni, Yehonatan Lederman, Michal Eduardo, Martin Steuer, David Essis |
| Job Number | singleLineText |  |
| Total Job Amount | currency |  |
| Amount Paid | currency |  |
| Payment Method | singleSelect | choices: Cash, Check, Credit Card, Other, Bank Transfer |
| Status Of Payment | checkbox |  |
| Status Of Job | singleSelect | choices: Open, Closed |
| Outstanding Balance | formula | `{Total Job Amount} - {Amount Paid}` → number |
| Current Week | formula | `IF(
   DATETIME_FORMAT(
     DATEADD({Date}, -MOD(WEEKDAY({Date}) + 6, 7), 'days'),
     'YYYY-MM-DD'
   )
   =
   DATETIME_FORMAT(
     DATEADD(TODAY(), -MOD(WEEKDAY(TODAY()) + 6, 7), 'days'),
     'YYYY-MM-DD'
   ),
   "Current Week",
   "Previous Weeks"
 )` → singleLineText |

| View | Type | Filters / groups / sorts / hidden fields / colours — read from UI |
|---|---|---|
| Current Week (`viwlzzEdTCOBUC0WA`) | grid | |
| Previous Weeks Jobs (`viw8Gf0qDfUOZefcl`) | grid | |
| Closed Jobs (`viwFp2mrsD3dhmHCv`) | grid | |

### TX (`tblGzCgHbjzrH3gfO`) — 10 fields, 3 views

| Field | Type | Formula / reference / result |
|---|---|---|
| Date | date |  |
| Technician | singleSelect | choices: Rotem Hadad, Amit Weiss, Ronald Rivera, Leroy Sapir, Liath Dror, Daniel Meir, Ben Hezroni, David Mizrahi, Tal Menahem, TXBUILDER |
| Job Number | singleLineText |  |
| Total Job Amount | currency |  |
| Amount Paid | currency |  |
| Payment Method | singleSelect | choices: Cash, Check, Credit Card, Finance, Zelle |
| Status Of Payment | checkbox |  |
| Status Of Job | singleSelect | choices: Open, Closed |
| Outstanding Balance | formula | `{Total Job Amount} - {Amount Paid}` → number |
| Current Week | formula | `IF(
   DATETIME_FORMAT(
     DATEADD({Date}, -MOD(WEEKDAY({Date}) + 6, 7), 'days'),
     'YYYY-MM-DD'
   )
   =
   DATETIME_FORMAT(
     DATEADD(TODAY(), -MOD(WEEKDAY(TODAY()) + 6, 7), 'days'),
     'YYYY-MM-DD'
   ),
   "Current Week",
   "Previous Weeks"
 )` → singleLineText |

| View | Type | Filters / groups / sorts / hidden fields / colours — read from UI |
|---|---|---|
| Current Week Jobs (`viwT7c1wy2iVX87hL`) | grid | |
| Previous Weeks Jobs  (`viwbUgdIj82XqXLu7`) | grid | |
| Closed Jobs (`viwbZ1v0WHYtp13W3`) | grid | |

### OH (`tblMvDkzefX4qqq5W`) — 10 fields, 3 views

| Field | Type | Formula / reference / result |
|---|---|---|
| Date | date |  |
| Technician | singleSelect | choices: Avihay Malka, Bar boker, Dorel Ben David, Eli Igrushin, Ron Balili, Jorge Sarabia Del Carpio, Yahav Zaid, Ivan Sharkov, Zane Redomod, Chaim Perez, David Ben David, Tzvi Levenhartz, Albert Udod, Fabian P, Ron Turgeman, Artem Maliuk, Eddie Fainshtein |
| Job Number | singleLineText |  |
| Total Job Amount | currency |  |
| Amount Paid | currency |  |
| Payment Method | singleSelect | choices: Cash, Check, Credit Card, Other, Bank Transfer, Zelle |
| Status Of Payment | checkbox |  |
| Status Of Job | singleSelect | choices: Open, Closed |
| Outstanding Balance | formula | `{Total Job Amount} - {Amount Paid}` → number |
| Current Week | formula | `IF(
   DATETIME_FORMAT(
     DATEADD({Date}, -MOD(WEEKDAY({Date}) + 6, 7), 'days'),
     'YYYY-MM-DD'
   )
   =
   DATETIME_FORMAT(
     DATEADD(TODAY(), -MOD(WEEKDAY(TODAY()) + 6, 7), 'days'),
     'YYYY-MM-DD'
   ),
   "Current Week",
   "Previous Weeks"
 )` → singleLineText |

| View | Type | Filters / groups / sorts / hidden fields / colours — read from UI |
|---|---|---|
| This Week Jobs (`viwZ3d5oBYGyGvh7T`) | grid | |
| Previous Weeks Jobs (`viwG2ND6XCvUcpVVe`) | grid | |
| Closed  Jobs (`viwYkgB58R7mQT4So`) | grid | |

### GA (`tblxLlYqi1uMsjEUc`) — 10 fields, 3 views

| Field | Type | Formula / reference / result |
|---|---|---|
| Date | date |  |
| Technician | singleSelect | choices: Nate Mullakand, Kim Rudolf, Jacob, Danylo Yakovlev, David Cohen, Yaroslav Kushnirenko, Ryan Ford |
| Job Number | singleLineText |  |
| Total Job Amount | currency |  |
| Amount Paid | currency |  |
| Payment Method | singleSelect | choices: Cash, Check, Credit Card, Other, Bank Transfer, Zelle |
| Status Of Payment | checkbox |  |
| Status Of Job | singleSelect | choices: Open, Closed |
| Outstanding Balance | formula | `{Total Job Amount} - {Amount Paid}` → number |
| Current Week | formula | `IF(
   DATETIME_FORMAT(
     DATEADD({Date}, -MOD(WEEKDAY({Date}) + 6, 7), 'days'),
     'YYYY-MM-DD'
   )
   =
   DATETIME_FORMAT(
     DATEADD(TODAY(), -MOD(WEEKDAY(TODAY()) + 6, 7), 'days'),
     'YYYY-MM-DD'
   ),
   "Current Week",
   "Previous Weeks"
 )` → singleLineText |

| View | Type | Filters / groups / sorts / hidden fields / colours — read from UI |
|---|---|---|
| Current Week Jobs (`viwKjVJfFKdgIovW9`) | grid | |
| Previous Weeks Jobs (`viwtYtKRZUuYOpZWL`) | grid | |
| Closed Jobs (`viw3ehYmHaOQfW3Dg`) | grid | |

### IL (`tblxmCTEwSXPahQyd`) — 10 fields, 3 views

| Field | Type | Formula / reference / result |
|---|---|---|
| Date | date |  |
| Technician | singleSelect | choices: Moses Hai Zion, Omer Weissman, Ron Turgeman, Jonatan Abada , Tal Menahem, Jonatan Abada, Jade Cijo, Sean, Itay Weinberg, Yoav Peter, Yuval M |
| Job Number | singleLineText |  |
| Total Job Amount | currency |  |
| Amount Paid | currency |  |
| Payment Method | singleSelect | choices: Cash, Check, Credit Card, Other, Bank Transfer, Zelle |
| Status Of Payment | checkbox |  |
| Status Of Job | singleSelect | choices: Open, Closed |
| Outstanding Balance | formula | `{Total Job Amount} - {Amount Paid}` → number |
| Current Week | formula | `IF(
   DATETIME_FORMAT(
     DATEADD({Date}, -MOD(WEEKDAY({Date}) + 6, 7), 'days'),
     'YYYY-MM-DD'
   )
   =
   DATETIME_FORMAT(
     DATEADD(TODAY(), -MOD(WEEKDAY(TODAY()) + 6, 7), 'days'),
     'YYYY-MM-DD'
   ),
   "Current Week",
   "Previous Weeks"
 )` → singleLineText |

| View | Type | Filters / groups / sorts / hidden fields / colours — read from UI |
|---|---|---|
| Current Week Jobs (`viwKUcEtTBGjqmHAa`) | grid | |
| Previous Weeks Jobs (`viw111I4ZXr2XYDhT`) | grid | |
| Closed Jobs (`viwEHLxmh7WzR8O1Z`) | grid | |

### DMV (`tblhykuZPesNQgy1i`) — 11 fields, 3 views

| Field | Type | Formula / reference / result |
|---|---|---|
| Date | date |  |
| Technician | singleSelect | choices: Ali Nakhaie, Liraz Sapir, , Jonatan Chance, Ron Levi, Barry Kraeif, Hen D Solutions, Jacob Block, Second Opinion Chimney, Asaf AAA Chimney |
| Job Number | singleLineText |  |
| Total Job Amount | currency |  |
| Amount Paid | currency |  |
| Payment Method | singleSelect | choices: Cash, Check, Credit Card, Other, Bank Transfer |
| Status Of Payment | checkbox |  |
| Status Of Job | singleSelect | choices: Open, Closed |
| Outstanding Balance | formula | `{Total Job Amount} - {Amount Paid}` → number |
| Current Week | formula | `IF(
   DATETIME_FORMAT(
     DATEADD({Date}, -MOD(WEEKDAY({Date}) + 6, 7), 'days'),
     'YYYY-MM-DD'
   )
   =
   DATETIME_FORMAT(
     DATEADD(TODAY(), -MOD(WEEKDAY(TODAY()) + 6, 7), 'days'),
     'YYYY-MM-DD'
   ),
   "Current Week",
   "Previous Weeks"
 )` → singleLineText |
| Field 11 | singleLineText |  |

| View | Type | Filters / groups / sorts / hidden fields / colours — read from UI |
|---|---|---|
| Current Week Jobs (`viwu6UfOcXbh6lp3f`) | grid | |
| Previous Weeks Jobs (`viwKIv0oOIinxJTmS`) | grid | |
| Closed Jobs (`viwU2pLZ0GJsFpERV`) | grid | |

### NY (`tblz2mqN2mssahf7j`) — 11 fields, 3 views

| Field | Type | Formula / reference / result |
|---|---|---|
| Date | date |  |
| Technician | singleSelect | choices: Ali Nakhaie, Liraz Sapir, , Jonatan Chance, Ron Levi, Eliayu Bitton, Raz Eliezer |
| Job Number | singleLineText |  |
| Total Job Amount | currency |  |
| Amount Paid | currency |  |
| Payment Method | singleSelect | choices: Cash, Check, Credit Card, Other, Bank Transfer |
| Status Of Payment | checkbox |  |
| Status Of Job | singleSelect | choices: Open, Closed |
| Outstanding Balance | formula | `{Total Job Amount} - {Amount Paid}` → number |
| Current Week | formula | `IF(
   DATETIME_FORMAT(
     DATEADD({Date}, -MOD(WEEKDAY({Date}) + 6, 7), 'days'),
     'YYYY-MM-DD'
   )
   =
   DATETIME_FORMAT(
     DATEADD(TODAY(), -MOD(WEEKDAY(TODAY()) + 6, 7), 'days'),
     'YYYY-MM-DD'
   ),
   "Current Week",
   "Previous Weeks"
 )` → singleLineText |
| Field 11 | singleLineText |  |

| View | Type | Filters / groups / sorts / hidden fields / colours — read from UI |
|---|---|---|
| Current Week Jobs (`viwMAWbCp5bWqm69g`) | grid | |
| Previous Weeks Jobs (`viw2cxWc1Qi2RKAsT`) | grid | |
| Closed Jobs (`viwcwrHNdOJ7ZqlXW`) | grid | |

### MA (`tblioaUWzVPIVvFoZ`) — 11 fields, 3 views

| Field | Type | Formula / reference / result |
|---|---|---|
| Date | date |  |
| Technician | singleSelect | choices: Ali Nakhaie, Liraz Sapir, , Jonatan Chance, Ron Levi, Ron Paz, Nissim Maor Fachima |
| Job Number | singleLineText |  |
| Total Job Amount | currency |  |
| Amount Paid | currency |  |
| Payment Method | singleSelect | choices: Cash, Check, Credit Card, Other, Bank Transfer, Zelle |
| Status Of Payment | checkbox |  |
| Status Of Job | singleSelect | choices: Open, Closed |
| Outstanding Balance | formula | `{Total Job Amount} - {Amount Paid}` → number |
| Current Week | formula | `IF(
   DATETIME_FORMAT(
     DATEADD({Date}, -MOD(WEEKDAY({Date}) + 6, 7), 'days'),
     'YYYY-MM-DD'
   )
   =
   DATETIME_FORMAT(
     DATEADD(TODAY(), -MOD(WEEKDAY(TODAY()) + 6, 7), 'days'),
     'YYYY-MM-DD'
   ),
   "Current Week",
   "Previous Weeks"
 )` → singleLineText |
| Field 11 | singleLineText |  |

| View | Type | Filters / groups / sorts / hidden fields / colours — read from UI |
|---|---|---|
| Current Week Jobs (`viwvWKFLWEycbAwqW`) | grid | |
| Previous Weeks Jobs (`viwLylqlypFiCY0Jz`) | grid | |
| Closed Jobs (`viwVSfbWKn6nKELeC`) | grid | |

### NW (`tblBi6eDJL6MDVRSK`) — 10 fields, 3 views

| Field | Type | Formula / reference / result |
|---|---|---|
| Date | date |  |
| Technician | singleSelect | choices: Suheil Hamaty, Shlomi Perez, Tal Menachem, Neria Cohen |
| Job Number | singleLineText |  |
| Total Job Amount | currency |  |
| Amount Paid | currency |  |
| Payment Method | singleSelect | choices: Credit Card, Check, Zelle, Finance, Cash |
| Status Of Payment | checkbox |  |
| Status Of Job | singleSelect | choices: Open, Closed |
| Outstanding Balance | formula | `{Total Job Amount} - {Amount Paid}` → number |
| Current Week | formula | `IF(
   DATETIME_FORMAT(
     DATEADD({Date}, -MOD(WEEKDAY({Date}) + 6, 7), 'days'),
     'YYYY-MM-DD'
   )
   =
   DATETIME_FORMAT(
     DATEADD(TODAY(), -MOD(WEEKDAY(TODAY()) + 6, 7), 'days'),
     'YYYY-MM-DD'
   ),
   "Current Week",
   "Previous Weeks"
 )` → singleLineText |

| View | Type | Filters / groups / sorts / hidden fields / colours — read from UI |
|---|---|---|
| Current Week Jobs (`viwOQGZs6uPgT0IUH`) | grid | |
| Previous Weeks Jobs (`viwhzBpePaBeDkDtV`) | grid | |
| Closed Jobs (`viwGmBWUKaPjy1KYa`) | grid | |

### Everdoors (`tblOBF6bQ3dQHe5Ji`) — 10 fields, 3 views

| Field | Type | Formula / reference / result |
|---|---|---|
| Date | date |  |
| Technician | singleSelect | choices: YOSEF, Gal Reuveni, Elad Shalev, Tzuriel, Noam, Michael, Nir,  Yoav Refaeli, Yuval weinberger |
| Job Number | singleLineText |  |
| Total Job Amount | currency |  |
| Amount Paid | currency |  |
| Payment Method | singleSelect | choices: Credit Card, Check, Zelle, Finance, Cash |
| Status Of Payment | checkbox |  |
| Status Of Job | singleSelect | choices: Open, Closed |
| Outstanding Balance | formula | `{Total Job Amount} - {Amount Paid}` → number |
| Current Week | formula | `IF(
   DATETIME_FORMAT(
     DATEADD({Date}, -MOD(WEEKDAY({Date}) + 6, 7), 'days'),
     'YYYY-MM-DD'
   )
   =
   DATETIME_FORMAT(
     DATEADD(TODAY(), -MOD(WEEKDAY(TODAY()) + 6, 7), 'days'),
     'YYYY-MM-DD'
   ),
   "Current Week",
   "Previous Weeks"
 )` → singleLineText |

| View | Type | Filters / groups / sorts / hidden fields / colours — read from UI |
|---|---|---|
| Current Week Jobs (`viw19fR0dMWkXjWLf`) | grid | |
| Previous Weeks Jobs (`viwuSahMWsIiHDRkt`) | grid | |
| Closed Jobs (`viwTFaOsRsWnCkYPI`) | grid | |

## Repair Teams (`appzD9I7nNkALmw4l`)


### NJ (`tbliTYwmQl2KXzoxh`) — 24 fields, 2 views

| Field | Type | Formula / reference / result |
|---|---|---|
| Date | date |  |
| Team | singleSelect | choices: Darren, John, Ricardo, External Contractor, Kris, Angel, Eugene, Gavin |
| Technicians | singleSelect | choices: Guy Buganim, Meni Maamari, Ori Avner Karva, Ron Paz, Shuki Janach, Yally Regev, Shachar, Angel, Yehuda Dahan, Shlomo Dahan, Yosef Nasi, Jonatan Aron, Guy Shimonov, Noga Hadad, Yoel, Ishai, Shoval |
| Record type | singleSelect | choices: Job, Weekly Summary |
| Job Number | number |  |
| Job Amount | currency |  |
| Parts Type | singleLineText |  |
| Parts Cost | currency |  |
| Company Parts | currency |  |
| Tech Share | formula | `IF(
     {Total Contracting Profit},
     BLANK(),
     {Company Parts} * 0.25
 )` → number |
| External Company | checkbox |  |
| External Company Share | formula | `IF({External Company}, {Company Parts} / 2, BLANK())` → number |
| Total Contracting Profit | currency |  |
| Total Tech Share | formula | `{Company Parts} * 0.25` → number |
| Total External Share | formula | `{Company Parts} * 0.50` → number |
| Week - Dates  | formula | `DATETIME_FORMAT(
   DATEADD(
     {Date},
     -MOD(WEEKDAY({Date}) + 6, 7),
     "days"
   ),
   "D MMM"
 )
 &
 " - "
 &
 DATETIME_FORMAT(
   DATEADD(
     {Date},
     6-MOD(WEEKDAY({Date}) + 6, 7),
     "days"
   ),
   "D MMM"
 )` → singleLineText |
| Current Week | formula | `IF(
   {Date},
   IF(
     DATETIME_FORMAT(
       DATEADD(
         {Date},
         -MOD(WEEKDAY({Date}) + 6, 7),
         "days"
       ),
       "YYYY-MM-DD"
     )
     =
     DATETIME_FORMAT(
       DATEADD(
         TODAY(),
         -MOD(WEEKDAY(TODAY()) + 6, 7),
         "days"
       ),
       "YYYY-MM-DD"
     ),
     "Current",
     "Old"
   )
 )` → singleLineText |
| Employee Cost | currency |  |
| Helper Cost | currency |  |
| Helper Name | singleLineText |  |
| Gas Cost | currency |  |
| Ave Operation Cost | formula | `IF({Record type}="Weekly Summary",375,BLANK())` → number |
| Total Hours  | number |  |
| Attachment  | multipleAttachments |  |

| View | Type | Filters / groups / sorts / hidden fields / colours — read from UI |
|---|---|---|
| Current Week (`viw4MPLvyzqH96YZv`) | grid | |
| Previous Weeks (`viwXEVl5mijN9OO4S`) | grid | |

### CT (`tblLaCAXBIFj2H5hu`) — 24 fields, 2 views

| Field | Type | Formula / reference / result |
|---|---|---|
| Date | date |  |
| Team | singleSelect | choices: Josh , External Contractor, John Clement |
| Technician | singleSelect | choices: Adir Shemesh, Nehorai Maamari, Ben, Moshe Gafni, Yehonatan, Ohad Aharoni, David Essis, Oz, Michael |
| Record type | singleSelect | choices: Job, Weekly Summary |
| Job Number | number |  |
| Job Amount | currency |  |
| Parts Type | singleLineText |  |
| Parts Cost | currency |  |
| Company Parts | currency |  |
| Tech Share | formula | `IF(
     {Total Contracting Profit},
     BLANK(),
     {Company Parts} * 0.25
 )` → number |
| External Company | checkbox |  |
| External Company Share | formula | `IF({External Company}, {Company Parts} / 2, BLANK())` → number |
| Total Contracting Profit | currency |  |
| Total Tech Share | formula | `{Company Parts} * 0.25` → number |
| Total External Share | formula | `IF(
     {External Company},
     {Company Parts} * 0.5,
     BLANK()
 )` → number |
| Week - Dates  | formula | `DATETIME_FORMAT(
   DATEADD(
     {Date},
     -MOD(WEEKDAY({Date}) + 6, 7),
     "days"
   ),
   "D MMM"
 )
 &
 " - "
 &
 DATETIME_FORMAT(
   DATEADD(
     {Date},
     6-MOD(WEEKDAY({Date}) + 6, 7),
     "days"
   ),
   "D MMM"
 )` → singleLineText |
| Current Week | formula | `IF(
   {Date},
   IF(
     DATETIME_FORMAT(
       DATEADD(
         {Date},
         -MOD(WEEKDAY({Date}) + 6, 7),
         "days"
       ),
       "YYYY-MM-DD"
     )
     =
     DATETIME_FORMAT(
       DATEADD(
         TODAY(),
         -MOD(WEEKDAY(TODAY()) + 6, 7),
         "days"
       ),
       "YYYY-MM-DD"
     ),
     "Current",
     "Old"
   )
 )` → singleLineText |
| Employee Cost | currency |  |
| Helper Cost | currency |  |
| Helper Name | singleLineText |  |
| Gas Cost | currency |  |
| Ave Operation Cost | formula | `IF({Record type}="Weekly Summary",375,BLANK())` → number |
| Total Hours  | number |  |
| Attachment  | multipleAttachments |  |

| View | Type | Filters / groups / sorts / hidden fields / colours — read from UI |
|---|---|---|
| Current Week (`viwx3tP6jW3geeFJI`) | grid | |
| Previous Weeks (`viwqVzpG7FWmeWvO5`) | grid | |

### OHIO (`tblDUxGZlTStCb2UH`) — 25 fields, 2 views

| Field | Type | Formula / reference / result |
|---|---|---|
| Date | date |  |
| Team | singleSelect | choices: KENNI, External Contractor, Andrew  |
| Technician | singleSelect | choices: Bar Boker, Ivan Sharkov, Michael Pachi, Yhav Zaid |
| Record type | singleSelect | choices: Job, Weekly Summary |
| Job Number | number |  |
| Job Amount | currency |  |
| Parts Type | singleLineText |  |
| Parts Cost | currency |  |
| Company Parts | currency |  |
| Tech Share | formula | `IF(
     {Total Contracting Profit},
     BLANK(),
     {Company Parts} * 0.25
 )` → number |
| Attachment  | multipleAttachments |  |
| External Company | checkbox |  |
| External Company Share | formula | `IF({External Company}, {Company Parts} / 2, BLANK())` → number |
| Total Contracting Profit | currency |  |
| Total Tech Share | formula | `{Company Parts} * 0.25` → number |
| Total External Share | formula | `{Company Parts} * 0.50` → number |
| Week - Dates  | formula | `DATETIME_FORMAT(
   DATEADD(
     {Date},
     -MOD(WEEKDAY({Date}) + 6, 7),
     "days"
   ),
   "D MMM"
 )
 &
 " - "
 &
 DATETIME_FORMAT(
   DATEADD(
     {Date},
     6-MOD(WEEKDAY({Date}) + 6, 7),
     "days"
   ),
   "D MMM"
 )` → singleLineText |
| Current Week | formula | `IF(
   {Date},
   IF(
     DATETIME_FORMAT(
       DATEADD(
         {Date},
         -MOD(WEEKDAY({Date}) + 6, 7),
         "days"
       ),
       "YYYY-MM-DD"
     )
     =
     DATETIME_FORMAT(
       DATEADD(
         TODAY(),
         -MOD(WEEKDAY(TODAY()) + 6, 7),
         "days"
       ),
       "YYYY-MM-DD"
     ),
     "Current",
     "Old"
   )
 )` → singleLineText |
| Employee Cost | currency |  |
| Helper Cost | currency |  |
| Helper Name | singleLineText |  |
| Gas Cost | currency |  |
| Ave Operation Cost | formula | `IF({Record type}="Weekly Summary",375,BLANK())` → number |
| Total Hours  | number |  |
| Tech Name | singleSelect | choices: Bar Boker, Yahav, Tzvi, Ivan, Zane |

| View | Type | Filters / groups / sorts / hidden fields / colours — read from UI |
|---|---|---|
| Current Week (`viwpNoV837gqOICmV`) | grid | |
| Previous Weeks (`viwiFuvIRQ9wOqsri`) | grid | |

### TX (`tblhW7kHAtYzleqde`) — 24 fields, 2 views

| Field | Type | Formula / reference / result |
|---|---|---|
| Date | date |  |
| Team | singleSelect | choices: Alejandro & Kleverson, External Contractor |
| Technician | singleSelect | choices: Daniel Meir, David Mizrahi, Liath Dror, Ben Hezroni, Ronald Rivera, Rotem Hadad, Tal Menachem |
| Record type | singleSelect | choices: Job, Weekly Summary |
| Job Number | number |  |
| Job Amount | currency |  |
| Parts Type | singleLineText |  |
| Parts Cost | currency |  |
| Company Parts | currency |  |
| Tech Share | formula | `{Company Parts} * 0.25` → number |
| External Company | checkbox |  |
| External Company Share | formula | `IF({External Company}, {Company Parts} / 2, BLANK())` → number |
| Total Contracting Profit | currency |  |
| Total Tech Share | formula | `IF(
     {Total Contracting Profit},
     BLANK(),
     {Company Parts} * 0.25
 )` → number |
| Total External Share | formula | `IF(
     {External Company},
     {Company Parts} * 0.5,
     BLANK()
 )` → number |
| Week - Dates  | formula | `DATETIME_FORMAT(
   DATEADD(
     {Date},
     -MOD(WEEKDAY({Date}) + 6, 7),
     "days"
   ),
   "D MMM"
 )
 &
 " - "
 &
 DATETIME_FORMAT(
   DATEADD(
     {Date},
     6-MOD(WEEKDAY({Date}) + 6, 7),
     "days"
   ),
   "D MMM"
 )` → singleLineText |
| Current Week | formula | `IF(
   {Date},
   IF(
     DATETIME_FORMAT(
       DATEADD(
         {Date},
         -MOD(WEEKDAY({Date}) + 6, 7),
         "days"
       ),
       "YYYY-MM-DD"
     )
     =
     DATETIME_FORMAT(
       DATEADD(
         TODAY(),
         -MOD(WEEKDAY(TODAY()) + 6, 7),
         "days"
       ),
       "YYYY-MM-DD"
     ),
     "Current",
     "Old"
   )
 )` → singleLineText |
| Employee Cost | currency |  |
| Helper Cost | currency |  |
| Helper Name | singleLineText |  |
| Gas Cost | currency |  |
| Ave Operation Cost | formula | `IF({Record type}="Weekly Summary",375,BLANK())` → number |
| Total Hours  | number |  |
| Attachment  | multipleAttachments |  |

| View | Type | Filters / groups / sorts / hidden fields / colours — read from UI |
|---|---|---|
| Current Week (`viw3PYzQiHmwxL0Fs`) | grid | |
| Previous Weeks (`viwWH49q6qfCxtQKP`) | grid | |

### IL (`tblM8GHLc5ssy12nj`) — 24 fields, 2 views

| Field | Type | Formula / reference / result |
|---|---|---|
| Date | date |  |
| Team | singleSelect | choices: Juan, Oscar, Team c, External Contractor |
| Record type | singleSelect | choices: Job, Weekly Summary |
| Job Number | number |  |
| Job Amount | currency |  |
| Parts Type | singleLineText |  |
| Parts Cost | currency |  |
| Company Parts | currency |  |
| Tech Share | formula | `{Company Parts} * 0.25` → number |
| External Company | checkbox |  |
| External Company Share | formula | `IF({External Company}, {Company Parts} / 2, BLANK())` → number |
| Total Contracting Profit | currency |  |
| Total Tech Share | formula | `IF(
     {Total Contracting Profit},
     BLANK(),
     {Company Parts} * 0.25
 )` → number |
| Total External Share | formula | `IF({External Company}, {Company Parts} / 2, BLANK())` → number |
| Week - Dates  | formula | `DATETIME_FORMAT(
   DATEADD(
     {Date},
     -MOD(WEEKDAY({Date}) + 6, 7),
     "days"
   ),
   "D MMM"
 )
 &
 " - "
 &
 DATETIME_FORMAT(
   DATEADD(
     {Date},
     6-MOD(WEEKDAY({Date}) + 6, 7),
     "days"
   ),
   "D MMM"
 )` → singleLineText |
| Current Week | formula | `IF(
   {Date},
   IF(
     DATETIME_FORMAT(
       DATEADD(
         {Date},
         -MOD(WEEKDAY({Date}) + 6, 7),
         "days"
       ),
       "YYYY-MM-DD"
     )
     =
     DATETIME_FORMAT(
       DATEADD(
         TODAY(),
         -MOD(WEEKDAY(TODAY()) + 6, 7),
         "days"
       ),
       "YYYY-MM-DD"
     ),
     "Current",
     "Old"
   )
 )` → singleLineText |
| Employee Cost | currency |  |
| Helper Cost | currency |  |
| Helper Name | singleLineText |  |
| Gas Cost | currency |  |
| Ave Operation Cost | formula | `IF({Record type}="Weekly Summary",375,BLANK())` → number |
| Total Hours  | number |  |
| Attachment  | multipleAttachments |  |
| Technician | singleSelect | choices: Jonatan Abada, Moses Hai Zion, Omer Weissman, Ron Turgeman, Shlomi Perez, Tal Menahem |

| View | Type | Filters / groups / sorts / hidden fields / colours — read from UI |
|---|---|---|
| Current Week (`viwy1xWUUjQpKyCPx`) | grid | |
| Previous Weeks (`viwrTDwuI2JvKgsUU`) | grid | |

### GA (`tblhc4CJLOkMCNopv`) — 24 fields, 2 views

| Field | Type | Formula / reference / result |
|---|---|---|
| Date | date |  |
| Team | singleSelect | choices: Team A, Team B, Team c, External Contractor |
| Technician | singleSelect | choices: Netanel, Kim, Kim Rudolf, Nate Mullakand |
| Record type | singleSelect | choices: Job, Weekly Summary |
| Job Number | number |  |
| Job Amount | currency |  |
| Parts Type | singleLineText |  |
| Parts Cost | currency |  |
| Company Parts | currency |  |
| Tech Share | formula | `IF(
     {Total Contracting Profit},
     BLANK(),
     {Company Parts} * 0.25
 )` → number |
| External Company | checkbox |  |
| External Company Share | formula | `IF({External Company}, {Company Parts} / 2, BLANK())` → number |
| Total Contracting Profit | currency |  |
| Total Tech Share | formula | `{Company Parts} * 0.25` → number |
| Total External Share | formula | `{Company Parts} * 0.50` → number |
| Week - Dates  | formula | `DATETIME_FORMAT(
   DATEADD(
     {Date},
     -MOD(WEEKDAY({Date}) + 6, 7),
     "days"
   ),
   "D MMM"
 )
 &
 " - "
 &
 DATETIME_FORMAT(
   DATEADD(
     {Date},
     6-MOD(WEEKDAY({Date}) + 6, 7),
     "days"
   ),
   "D MMM"
 )` → singleLineText |
| Current Week | formula | `IF(
   {Date},
   IF(
     DATETIME_FORMAT(
       DATEADD(
         {Date},
         -MOD(WEEKDAY({Date}) + 6, 7),
         "days"
       ),
       "YYYY-MM-DD"
     )
     =
     DATETIME_FORMAT(
       DATEADD(
         TODAY(),
         -MOD(WEEKDAY(TODAY()) + 6, 7),
         "days"
       ),
       "YYYY-MM-DD"
     ),
     "Current",
     "Old"
   )
 )` → singleLineText |
| Employee Cost | currency |  |
| Helper Cost | currency |  |
| Helper Name | singleLineText |  |
| Gas Cost | currency |  |
| Ave Operation Cost | formula | `IF({Record type}="Weekly Summary",375,BLANK())` → number |
| Total Hours  | number |  |
| Attachment  | multipleAttachments |  |

| View | Type | Filters / groups / sorts / hidden fields / colours — read from UI |
|---|---|---|
| Current Week (`viw35VRSt2IJOkYRJ`) | grid | |
| Previous Weeks (`viwWX1rshLBPO2OW6`) | grid | |

### DMV (`tbl7GHGQpvlyWg4dG`) — 24 fields, 2 views

| Field | Type | Formula / reference / result |
|---|---|---|
| Date | date |  |
| Team | singleSelect | choices: Team A, Team B, Team c, External Contractor |
| Technician | singleSelect | choices: Liraz, Jonathon Chance, Leroy Sapir, Ron Levi, Barry Kraeif, Jacob Block, Chaim Perez |
| Record type | singleSelect | choices: Job, Weekly Summary |
| Job Number | number |  |
| Job Amount | currency |  |
| Parts Type | singleLineText |  |
| Parts Cost | currency |  |
| Company Parts | currency |  |
| Tech Share | formula | `IF(
     {Total Contracting Profit},
     BLANK(),
     {Company Parts} * 0.25
 )` → number |
| External Company | checkbox |  |
| External Company Share | formula | `IF({External Company}, {Company Parts} / 2, BLANK())` → number |
| Total Contracting Profit | currency |  |
| Total Tech Share | formula | `IF(
     {Total Contracting Profit},
     BLANK(),
     {Company Parts} * 0.25
 )` → number |
| Total External Share | formula | `IF(
     {Total Contracting Profit},
     BLANK(),
     IF(
         {External Company},
         {Company Parts} * 0.5
     )
 )` → number |
| Week - Dates  | formula | `DATETIME_FORMAT(
   DATEADD(
     {Date},
     -MOD(WEEKDAY({Date}) + 6, 7),
     "days"
   ),
   "D MMM"
 )
 &
 " - "
 &
 DATETIME_FORMAT(
   DATEADD(
     {Date},
     6-MOD(WEEKDAY({Date}) + 6, 7),
     "days"
   ),
   "D MMM"
 )` → singleLineText |
| Current Week | formula | `IF(
   {Date},
   IF(
     DATETIME_FORMAT(
       DATEADD(
         {Date},
         -MOD(WEEKDAY({Date}) + 6, 7),
         "days"
       ),
       "YYYY-MM-DD"
     )
     =
     DATETIME_FORMAT(
       DATEADD(
         TODAY(),
         -MOD(WEEKDAY(TODAY()) + 6, 7),
         "days"
       ),
       "YYYY-MM-DD"
     ),
     "Current",
     "Old"
   )
 )` → singleLineText |
| Employee Cost | currency |  |
| Helper Cost | currency |  |
| Helper Name | singleLineText |  |
| Gas Cost | currency |  |
| Ave Operation Cost | formula | `IF({Record type}="Weekly Summary",375,BLANK())` → number |
| Total Hours  | number |  |
| Attachment  | multipleAttachments |  |

| View | Type | Filters / groups / sorts / hidden fields / colours — read from UI |
|---|---|---|
| Current Week (`viwTzyVZ7JJv8NEFU`) | grid | |
| Previous Weeks (`viwMrEvzVsCB8vuKh`) | grid | |

### MA (`tbl9IYzaoNxKAZJNx`) — 24 fields, 2 views

| Field | Type | Formula / reference / result |
|---|---|---|
| Date | date |  |
| Team | singleSelect | choices: Team A, Team B, Team c, External Contractor |
| Technician | singleSelect | choices: Ron |
| Record type | singleSelect | choices: Job, Weekly Summary |
| Job Number | number |  |
| Job Amount | currency |  |
| Parts Type | singleLineText |  |
| Parts Cost | currency |  |
| Company Parts | currency |  |
| Tech Share | formula | `IF(
     {Total Contracting Profit},
     BLANK(),
     {Company Parts} * 0.25
 )` → number |
| External Company | checkbox |  |
| External Company Share | formula | `IF({External Company}, {Company Parts} / 2, BLANK())` → number |
| Total Contracting Profit | currency |  |
| Total Tech Share | formula | `IF(
     {Total Contracting Profit},
     BLANK(),
     {Company Parts} * 0.25
 )` → number |
| Total External Share | formula | `IF(
     {Total Contracting Profit},
     BLANK(),
     IF(
         {External Company},
         {Company Parts} * 0.5
     )
 )` → number |
| Week - Dates  | formula | `DATETIME_FORMAT(
   DATEADD(
     {Date},
     -MOD(WEEKDAY({Date}) + 6, 7),
     "days"
   ),
   "D MMM"
 )
 &
 " - "
 &
 DATETIME_FORMAT(
   DATEADD(
     {Date},
     6-MOD(WEEKDAY({Date}) + 6, 7),
     "days"
   ),
   "D MMM"
 )` → singleLineText |
| Current Week | formula | `IF(
   {Date},
   IF(
     DATETIME_FORMAT(
       DATEADD(
         {Date},
         -MOD(WEEKDAY({Date}) + 6, 7),
         "days"
       ),
       "YYYY-MM-DD"
     )
     =
     DATETIME_FORMAT(
       DATEADD(
         TODAY(),
         -MOD(WEEKDAY(TODAY()) + 6, 7),
         "days"
       ),
       "YYYY-MM-DD"
     ),
     "Current",
     "Old"
   )
 )` → singleLineText |
| Employee Cost | currency |  |
| Helper Cost | currency |  |
| Helper Name | singleLineText |  |
| Gas Cost | currency |  |
| Ave Operation Cost | formula | `IF({Record type}="Weekly Summary",375,BLANK())` → number |
| Total Hours  | number |  |
| Attachment  | multipleAttachments |  |

| View | Type | Filters / groups / sorts / hidden fields / colours — read from UI |
|---|---|---|
| Current Week (`viwVBPOj61VHMwjfL`) | grid | |
| Previous Weeks (`viwOtVoTUKONMe9k8`) | grid | |

## Profit And Loss (`appWhbwht0xgB5Yut`)


### NJ (`tblDUxGZlTStCb2UH`) — 18 fields, 2 views

| Field | Type | Formula / reference / result |
|---|---|---|
| Date | date |  |
| Week | formula | `DATETIME_FORMAT(
   DATEADD(
     {Date},
     -MOD(WEEKDAY({Date}) + 6, 7),
     "days"
   ),
   "D MMM"
 )
 &
 " - "
 &
 DATETIME_FORMAT(
   DATEADD(
     {Date},
     6-MOD(WEEKDAY({Date}) + 6, 7),
     "days"
   ),
   "D MMM"
 )` → singleLineText |
| Income | singleLineText |  |
| Income Amount | currency |  |
| Marketing | singleLineText |  |
| Marketing Amount | currency |  |
| HQ Office Expenses | singleLineText |  |
| HQ Office Expenses Amount | currency |  |
| External  companies | singleLineText |  |
| External  companies Amount | currency |  |
| Owner Distributions & Royalitys | singleLineText |  |
| Owner Distributions & Royalitys Amount | currency |  |
| Tech Expenses | singleLineText |  |
| Tech Expenses Amount | currency |  |
| Production Expenses | singleLineText |  |
| Production Expenses Amount | currency |  |
| Bank Expenses | singleLineText |  |
| Bank Expenses Amount | currency |  |

| View | Type | Filters / groups / sorts / hidden fields / colours — read from UI |
|---|---|---|
| Grid view (`viwpNoV837gqOICmV`) | grid | |
| Previous Weeks (`viwWLyZCOgolEWhTp`) | grid | |

### CT (`tblhqHFPpYAiW8WPw`) — 18 fields, 2 views

| Field | Type | Formula / reference / result |
|---|---|---|
| Date | date |  |
| Week | formula | `DATETIME_FORMAT(
   DATEADD(
     {Date},
     -MOD(WEEKDAY({Date}) + 6, 7),
     "days"
   ),
   "D MMM"
 )
 &
 " - "
 &
 DATETIME_FORMAT(
   DATEADD(
     {Date},
     6-MOD(WEEKDAY({Date}) + 6, 7),
     "days"
   ),
   "D MMM"
 )` → singleLineText |
| Income | singleLineText |  |
| Income Amount | currency |  |
| Marketing | singleLineText |  |
| Marketing Amount | currency |  |
| HQ Office Expenses | singleLineText |  |
| HQ Office Expenses Amount | currency |  |
| External  companies | singleLineText |  |
| External  companies Amount | currency |  |
| Owner Distributions & Royalitys | singleLineText |  |
| Owner Distributions & Royalitys Amount | currency |  |
| Tech Expenses | singleLineText |  |
| Tech Expenses Amount | currency |  |
| Production Expenses | singleLineText |  |
| Production Expenses Amount | currency |  |
| Bank Expenses | singleLineText |  |
| Bank Expenses Amount | currency |  |

| View | Type | Filters / groups / sorts / hidden fields / colours — read from UI |
|---|---|---|
| Grid view (`viw3jyUY7cYf8FwhK`) | grid | |
| Previous Weeks (`viw4q8WIxopq85HSd`) | grid | |

### TX (`tbltOW4Ye5LqwKaLa`) — 18 fields, 2 views

| Field | Type | Formula / reference / result |
|---|---|---|
| Date | date |  |
| Week | formula | `DATETIME_FORMAT(
   DATEADD(
     {Date},
     -MOD(WEEKDAY({Date}) + 6, 7),
     "days"
   ),
   "D MMM"
 )
 &
 " - "
 &
 DATETIME_FORMAT(
   DATEADD(
     {Date},
     6-MOD(WEEKDAY({Date}) + 6, 7),
     "days"
   ),
   "D MMM"
 )` → singleLineText |
| Income | singleLineText |  |
| Income Amount | currency |  |
| Marketing | singleLineText |  |
| Marketing Amount | currency |  |
| HQ Office Expenses | singleLineText |  |
| HQ Office Expenses Amount | currency |  |
| External  companies | singleLineText |  |
| External  companies Amount | currency |  |
| Owner Distributions & Royalitys | singleLineText |  |
| Owner Distributions & Royalitys Amount | currency |  |
| Tech Expenses | singleLineText |  |
| Tech Expenses Amount | currency |  |
| Production Expenses | singleLineText |  |
| Production Expenses Amount | currency |  |
| Bank Expenses | singleLineText |  |
| Bank Expenses Amount | currency |  |

| View | Type | Filters / groups / sorts / hidden fields / colours — read from UI |
|---|---|---|
| Grid view (`viwfHNj7Wj9nIhKdo`) | grid | |
| Previous Weeks (`viw1DD6DGlafitH4h`) | grid | |

### OH (`tblpKJk2XEMlkX4lG`) — 18 fields, 2 views

| Field | Type | Formula / reference / result |
|---|---|---|
| Date | date |  |
| Week | formula | `DATETIME_FORMAT(
   DATEADD(
     {Date},
     -MOD(WEEKDAY({Date}) + 6, 7),
     "days"
   ),
   "D MMM"
 )
 &
 " - "
 &
 DATETIME_FORMAT(
   DATEADD(
     {Date},
     6-MOD(WEEKDAY({Date}) + 6, 7),
     "days"
   ),
   "D MMM"
 )` → singleLineText |
| Income | singleLineText |  |
| Income Amount | currency |  |
| Marketing | singleLineText |  |
| Marketing Amount | currency |  |
| HQ Office Expenses | singleLineText |  |
| HQ Office Expenses Amount | currency |  |
| External  companies | singleLineText |  |
| External  companies Amount | currency |  |
| Owner Distributions & Royalitys | singleLineText |  |
| Owner Distributions & Royalitys Amount | currency |  |
| Tech Expenses | singleLineText |  |
| Tech Expenses Amount | currency |  |
| Production Expenses | singleLineText |  |
| Production Expenses Amount | currency |  |
| Bank Expenses | singleLineText |  |
| Bank Expenses Amount | currency |  |

| View | Type | Filters / groups / sorts / hidden fields / colours — read from UI |
|---|---|---|
| Grid view (`viwbDAzbFSaiwuENU`) | grid | |
| Previous Weeks (`viwlSdEFWoh9ZQYoR`) | grid | |

### IL (`tblMZBfVXxXmNzNTd`) — 18 fields, 2 views

| Field | Type | Formula / reference / result |
|---|---|---|
| Date | date |  |
| Week | formula | `DATETIME_FORMAT(
   DATEADD(
     {Date},
     -MOD(WEEKDAY({Date}) + 6, 7),
     "days"
   ),
   "D MMM"
 )
 &
 " - "
 &
 DATETIME_FORMAT(
   DATEADD(
     {Date},
     6-MOD(WEEKDAY({Date}) + 6, 7),
     "days"
   ),
   "D MMM"
 )` → singleLineText |
| Income | singleLineText |  |
| Income Amount | currency |  |
| Marketing | singleLineText |  |
| Marketing Amount | currency |  |
| HQ Office Expenses | singleLineText |  |
| HQ Office Expenses Amount | currency |  |
| External  companies | singleLineText |  |
| External  companies Amount | currency |  |
| Owner Distributions & Royalitys | singleLineText |  |
| Owner Distributions & Royalitys Amount | currency |  |
| Tech Expenses | singleLineText |  |
| Tech Expenses Amount | currency |  |
| Production Expenses | singleLineText |  |
| Production Expenses Amount | currency |  |
| Bank Expenses | singleLineText |  |
| Bank Expenses Amount | currency |  |

| View | Type | Filters / groups / sorts / hidden fields / colours — read from UI |
|---|---|---|
| Grid view (`viwySsu4FLljZ6nlr`) | grid | |
| Previous Weeks (`viwCcb6QQGfOmbuCN`) | grid | |

### GA (`tbl1YNRbZeLgaJChN`) — 18 fields, 2 views

| Field | Type | Formula / reference / result |
|---|---|---|
| Date | date |  |
| Week | formula | `DATETIME_FORMAT(
   DATEADD(
     {Date},
     -MOD(WEEKDAY({Date}) + 6, 7),
     "days"
   ),
   "D MMM"
 )
 &
 " - "
 &
 DATETIME_FORMAT(
   DATEADD(
     {Date},
     6-MOD(WEEKDAY({Date}) + 6, 7),
     "days"
   ),
   "D MMM"
 )` → singleLineText |
| Income | singleLineText |  |
| Income Amount | currency |  |
| Marketing | singleLineText |  |
| Marketing Amount | currency |  |
| HQ Office Expenses | singleLineText |  |
| HQ Office Expenses Amount | currency |  |
| External  companies | singleLineText |  |
| External  companies Amount | currency |  |
| Owner Distributions & Royalitys | singleLineText |  |
| Owner Distributions & Royalitys Amount | currency |  |
| Tech Expenses | singleLineText |  |
| Tech Expenses Amount | currency |  |
| Production Expenses | singleLineText |  |
| Production Expenses Amount | currency |  |
| Bank Expenses | singleLineText |  |
| Bank Expenses Amount | currency |  |

| View | Type | Filters / groups / sorts / hidden fields / colours — read from UI |
|---|---|---|
| Grid view (`viwNRE6kHs9dmgcJ1`) | grid | |
| Previous Weeks (`viwxAdQ8PQMu1Kucr`) | grid | |

### DMV (`tblENNwJUcW7ySb8P`) — 18 fields, 2 views

| Field | Type | Formula / reference / result |
|---|---|---|
| Date | date |  |
| Week | formula | `DATETIME_FORMAT(
   DATEADD(
     {Date},
     -MOD(WEEKDAY({Date}) + 6, 7),
     "days"
   ),
   "D MMM"
 )
 &
 " - "
 &
 DATETIME_FORMAT(
   DATEADD(
     {Date},
     6-MOD(WEEKDAY({Date}) + 6, 7),
     "days"
   ),
   "D MMM"
 )` → singleLineText |
| Income | singleLineText |  |
| Income Amount | currency |  |
| Marketing | singleLineText |  |
| Marketing Amount | currency |  |
| HQ Office Expenses | singleLineText |  |
| HQ Office Expenses Amount | currency |  |
| External  companies | singleLineText |  |
| External  companies Amount | currency |  |
| Owner Distributions & Royalitys | singleLineText |  |
| Owner Distributions & Royalitys Amount | currency |  |
| Tech Expenses | singleLineText |  |
| Tech Expenses Amount | currency |  |
| Production Expenses | singleLineText |  |
| Production Expenses Amount | currency |  |
| Bank Expenses | singleLineText |  |
| Bank Expenses Amount | currency |  |

| View | Type | Filters / groups / sorts / hidden fields / colours — read from UI |
|---|---|---|
| Grid view (`viwqGELSCqk4KpLA3`) | grid | |
| Previous Weeks (`viw348G55WbrZ1j8o`) | grid | |

### WA (`tblifiOssYgLOaz3X`) — 18 fields, 2 views

| Field | Type | Formula / reference / result |
|---|---|---|
| Date | date |  |
| Week | formula | `DATETIME_FORMAT(
   DATEADD(
     {Date},
     -MOD(WEEKDAY({Date}) + 6, 7),
     "days"
   ),
   "D MMM"
 )
 &
 " - "
 &
 DATETIME_FORMAT(
   DATEADD(
     {Date},
     6-MOD(WEEKDAY({Date}) + 6, 7),
     "days"
   ),
   "D MMM"
 )` → singleLineText |
| Income | singleLineText |  |
| Income Amount | currency |  |
| Marketing | singleLineText |  |
| Marketing Amount | currency |  |
| HQ Office Expenses | singleLineText |  |
| HQ Office Expenses Amount | currency |  |
| External  companies | singleLineText |  |
| External  companies Amount | currency |  |
| Owner Distributions & Royalitys | singleLineText |  |
| Owner Distributions & Royalitys Amount | currency |  |
| Tech Expenses | singleLineText |  |
| Tech Expenses Amount | currency |  |
| Production Expenses | singleLineText |  |
| Production Expenses Amount | currency |  |
| Bank Expenses | singleLineText |  |
| Bank Expenses Amount | currency |  |

| View | Type | Filters / groups / sorts / hidden fields / colours — read from UI |
|---|---|---|
| Grid view (`viw4893BacEI0H9vb`) | grid | |
| Previous Weeks (`viw5U9A0Nt5CtZH0Z`) | grid | |

### NW (`tbl06qeoIY5E7PfxH`) — 14 fields, 2 views

| Field | Type | Formula / reference / result |
|---|---|---|
| Date | date |  |
| Week | formula | `DATETIME_FORMAT(
   DATEADD(
     {Date},
     -MOD(WEEKDAY({Date}) + 6, 7),
     "days"
   ),
   "D MMM"
 )
 &
 " - "
 &
 DATETIME_FORMAT(
   DATEADD(
     {Date},
     6-MOD(WEEKDAY({Date}) + 6, 7),
     "days"
   ),
   "D MMM"
 )` → singleLineText |
| Income | singleLineText |  |
| Income Amount | currency |  |
| Marketing | singleLineText |  |
| Marketing Amount | currency |  |
| HQ Office Expenses | singleLineText |  |
| HQ Office Expenses Amount | currency |  |
| External  companies | singleLineText |  |
| External  companies Amount | currency |  |
| Bank Expenses | singleLineText |  |
| Bank Expenses Amount | currency |  |
| Tech Expenses | singleLineText |  |
| Tech Expenses Amount | currency |  |

| View | Type | Filters / groups / sorts / hidden fields / colours — read from UI |
|---|---|---|
| Grid view (`viwMZhtxqctBjmPZV`) | grid | |
| Previous Weeks (`viweA3WCPqEsFooCb`) | grid | |

### NY (`tblunO193lu8YMcIq`) — 14 fields, 2 views

| Field | Type | Formula / reference / result |
|---|---|---|
| Date | date |  |
| Week | formula | `DATETIME_FORMAT(
   DATEADD(
     {Date},
     -MOD(WEEKDAY({Date}) + 6, 7),
     "days"
   ),
   "D MMM"
 )
 &
 " - "
 &
 DATETIME_FORMAT(
   DATEADD(
     {Date},
     6-MOD(WEEKDAY({Date}) + 6, 7),
     "days"
   ),
   "D MMM"
 )` → singleLineText |
| Income | singleLineText |  |
| Income Amount | currency |  |
| Marketing | singleLineText |  |
| Marketing Amount | currency |  |
| HQ Office Expenses | singleLineText |  |
| HQ Office Expenses Amount | currency |  |
| External  companies | singleLineText |  |
| External  companies Amount | currency |  |
| Bank Expenses | singleLineText |  |
| Bank Expenses Amount | currency |  |
| Tech Expenses | singleLineText |  |
| Tech Expenses Amount | currency |  |

| View | Type | Filters / groups / sorts / hidden fields / colours — read from UI |
|---|---|---|
| Grid view (`viwggFgiLzS5ajMaE`) | grid | |
| Previous Weeks (`viwIRrJnaN3WwllNU`) | grid | |

### MA (`tblhIqc5V5yH6m0H0`) — 18 fields, 2 views

| Field | Type | Formula / reference / result |
|---|---|---|
| Date | date |  |
| Week | formula | `DATETIME_FORMAT(
   DATEADD(
     {Date},
     -MOD(WEEKDAY({Date}) + 6, 7),
     "days"
   ),
   "D MMM"
 )
 &
 " - "
 &
 DATETIME_FORMAT(
   DATEADD(
     {Date},
     6-MOD(WEEKDAY({Date}) + 6, 7),
     "days"
   ),
   "D MMM"
 )` → singleLineText |
| Income | singleLineText |  |
| Income Amount | currency |  |
| Marketing | singleLineText |  |
| Marketing Amount | currency |  |
| HQ Office Expenses | singleLineText |  |
| HQ Office Expenses Amount | currency |  |
| External  companies | singleLineText |  |
| External  companies Amount | currency |  |
| Tech Expenses | singleLineText |  |
| Tech Expenses Amount | currency |  |
| Bank Expenses | singleLineText |  |
| Bank Expenses Amount | currency |  |
| Owner Distributions & Royalitys | singleLineText |  |
| Owner Distributions & Royalitys Amount | currency |  |
| Production Expenses | singleLineText |  |
| Production Expenses Amount | currency |  |

| View | Type | Filters / groups / sorts / hidden fields / colours — read from UI |
|---|---|---|
| Grid view (`viw3BhreDjWEiTA9e`) | grid | |
| Previous Weeks (`viwvc3Uj2x7vEV9Mu`) | grid | |

---

## Airtable view settings read from the UI (read-only), 2026-11-20
Toolbar label "Hide fields"/"Sort"/"Color" with no count = none set.

### Disputes And Refunds › Refunds (footer: Sum on Total Jobs, Refund Amount, Tech Part, External Part)
- Main Table: filter OR of 3: [Open/Close Job is Open AND Refund Status unchecked] OR [Open/Close Job is Closed AND Deduction Status unchecked AND Refund Status unchecked] OR [Open/Close Job is empty]. Group Company Name First→Last. Sort none. Hidden none. Colour none.
- Deduction List: filter AND-group [Open/Close Job is Closed AND Refund Status checked AND Deduction Status unchecked]. Group Company Name First→Last. Sort/hidden/colour none.
- Open Jobs: filter Open/Close Job is not Closed. Group Company Name First→Last. Sort/hidden/colour none.
- Closed Jobs: filter Open/Close Job is not Open AND Deduction Status checked. Group Company Name First→Last. Sort/hidden/colour none.

### Disputes And Refunds › Disputes (footer sums on money columns)
- Main Table: filter OR of 2: [Open/Close Job is Open AND Submitted Ev… unchecked] OR [Open/Close Job is Closed AND Deduction Status unchecked AND Submitted Ev… unchecked] (plus an empty top-level group). Group Company Name First→Last. Sort/hidden/colour none.
- Deduction List: filter AND-group [Open/Close Job is Closed AND Deduction Status unchecked]. Group Company Name. Rest none.
- Open Jobs: filter Open/Close Job is not Closed OR Dispute Decision is "Dispute Accepted" (OR a blank "Date is exact date" condition, inert). Group Company Name. Rest none.
- Closed Jobs: filter Open/Close Job is not Open AND Deduction Status checked. Group Company Name. Rest none.

### Disputes And Refunds › Checks Returns
- Main Table: filter [AND-group: Open/Close Job is Closed AND Deduction Status unchecked] OR Open/Close Job is empty. Group Company Name First→Last. Sort Type First→Last (auto-sort on). Hidden/colour none.
- Open Jobs: filter Open/Close Job is not Closed. Group Company Name. Rest none.
- Closed Jobs: filter Open/Close Job is not Open AND Deduction Status checked. Group Company Name. Rest none.

### Disputes And Refunds › Debt & Loans
- Open Debt/Loans: filter Refund/ Credit Complete unchecked. Group Type First→Last. Colour: rows where Type is Credit = cyan/teal. Sort/hidden none.
- Closed Debt: filter Refund/ Credit Complete checked. No group. Sort/hidden/colour none.

### Technicians Payrolls › Example (footer: Sum on money columns + Balance)
- Grid view: no filter. Group Company Name First→Last. Sort/hidden/colour none.
- Form: form view (fields only).
- Summary View: no filter. Group Company Name First→Last. 7 hidden fields: shows only Week, Technicians, Balance (Sum). Sort/colour none.
### Technicians Payrolls › Table 1 copy (0 rows)
- Grid view: nothing set.

### Invoices System › Weekly Data (footer: Blank "Empty" count; Sum on Total Jobs and money/number columns)
- Grid view: nothing set (manual order; a hand-entered totals row 10).
- Previous Weeks: filter Invoice Date is before "one week ago" (relative, PST). Group Week (formula) A→Z. Column order differs: …Google charges LS, Royalties Payment, Total, Note, Marketing Expenses, Invoices. Sort/hidden/colour none.
- Monthly: nothing set; manual row order (totals row 9 above MA). Same column order as Previous Weeks.
### Invoices System › Companies — Grid view: nothing set (manual order).
### Invoices System › Invoices — Grid view: nothing set.
### Invoices System › Weekly HQ Expenses
- Grid view: filter Date is after "one week ago" (PST). Nothing else.
- Previous Weeks: filter Date is before "one week ago" (PST). Group Week (formula) A→Z. Sort Date Latest→Earliest (auto). Hidden/colour none.
### Invoices System › Ave Office expenses per job
- Grid view: filter Date is after "one week ago" (PST). Group WEEK (formula) A→Z. Rest none.
- Previous weeks: filter Date is before "one week ago" (PST). Sort Date Latest→Earliest. No group.
### Invoices System › Reviews and office lease cost (Monthly)
- Grid view: filter Date is after "one week ago" (PST). Rest none.
- Previous weeks: filter Date is before "one week ago" (PST). Sort Date Latest→Earliest. No group.
### Invoices System › Monthly Billings
- Grid view: filter Date is after "one week ago" (PST). Rest none.
- Previous weeks: filter Date is before "one week ago" (PST). Group Company name First→Last. Sort Date Latest→Earliest.
### Invoices System › Marketing Performance (ANGI SWEEP), (ace) Daily, (ace) weekly — same pattern in all three
- Grid view: filter DATE is after "one week ago" (PST). Group Week (formula) A→Z, then Service Area First→Last. Sort none.
- Previous weeks: filter DATE is before "one week ago" (PST). Group Service Area First→Last. Sort DATE Latest→Earliest (auto).
- Footers: Sum on every numeric/money column. Hidden/colour none.

### Deposit Jobs Tracker (one table per market; footers Sum on Total Amount, Amount Paid, Outstanding Balance)
Pattern per table: 
- Current Week: filter Status Of Job is not Closed AND Current Week (formula) is "Current Week". No group. Sort Status Of Job <dir>, then Date Latest→Earliest. Colour: Status Of Job is Closed = red.
- Previous weeks Jobs: filter Status Of Job is not Closed AND Current Week is "Previous Weeks". Group Technician First→Last. Sort Status Of Job <dir>, then Date Latest→Earliest. Colour: Status Of Job is Closed = red.
- Closed Jobs: filter Status Of Job is Closed. Nothing else.
- NJ: <dir> = Last→First (both views); Current Week view in NJ ALSO grouped by Technician First→Last.
- CT: <dir> = First→Last.
- TX: <dir> = First→Last.
- OH: views named "This Week Jobs", "Previous Weeks Jobs", "Closed Jobs". This Week Jobs IS grouped by Technician First→Last. <dir> = First→Last. Colour has a 2nd blue rule with an empty condition (Date is exact date, blank — inert) in both views.
- GA: views "Current Week Jobs", "Previous Weeks Jobs", "Closed Jobs". <dir> = First→Last. Previous Weeks Jobs hides 1 field: Current Week. Current Week Jobs not grouped.
- IL: views "Current Week Jobs", "Previous Weeks Jobs", "Closed Jobs". Previous Weeks Jobs: hides Current Week; sort is Date Latest→Earliest FIRST, then Status Of Job First→Last. Current Week Jobs: not grouped; sort Status First→Last, Date Latest→Earliest. Closed Jobs: ALSO grouped by Technician First→Last.
- DMV: views "Current Week Jobs", "Previous Weeks Jobs", "Closed Jobs". <dir> = First→Last. Colour rule (Status Of Job is Closed) is BLUE, not red. Extra text column "Field 11". Current Week Jobs not grouped.
- NY: same as DMV (views Current/Previous Weeks Jobs/Closed Jobs; <dir> First→Last; colour BLUE; Field 11 column; Current Week Jobs not grouped).
- MA: same as DMV/NY (blue colour, Field 11, <dir> First→Last, Current Week Jobs not grouped).
- NW: <dir> First→Last; colour red. Previous Weeks Jobs is NOT grouped (filter order: Current Week is "Previous Weeks" AND Status Of Job is not Closed). Current Week Jobs not grouped.
- Everdoors: colour red. Previous Weeks Jobs: NOT grouped; filter Current Week is "Previous Weeks" AND Status Of Job is not Closed; sort Status First→Last, Date Latest→Earliest, Technician First→Last (3 sorts). Current Week Jobs: not grouped, sort Status First→Last, Date Latest→Earliest. Closed Jobs: Status Of Job is Closed.

### Repair Teams (one table per market; footers Sum on money/number columns)
- NJ Current Week: filter Current Week (formula) is "Current" AND (blank "Date is exact date" — inert). Group Team First→Last. Sort/hidden/colour none.
- NJ Previous Weeks: filter Current Week is "Old". Group Week - Dates (formula) Z→A, then Team First→Last. Hidden: Current Week. Sort/colour none.
- CT Current Week: filter Current Week is "Current". Group Team First→Last. Rest none.
- CT Previous Weeks: filter Current Week is "Old". Group Week - Dates Z→A, Team First→Last. Sort Date Latest→Earliest. Hidden: Current Week, Attachment.
- OHIO Current Week: filter Current Week is "Current". Group Team First→Last. Rest none.
- OHIO Previous Weeks: filter Current Week is "Old". Group Week - Dates Z→A, Team First→Last, Technician First→Last (3 levels). Hidden: Current Week. Sort/colour none. (OHIO has extra "Tech Name" column.)
- TX Current Week: filter Current Week is "Current". Group Team First→Last. Rest none.
- TX Previous Weeks: filter Current Week is "Old". Group Week - Dates Z→A, Team First→Last. Hidden: Current Week, Attachment. Sort/colour none.
- IL Current Week: filter Current Week is "Current". Group Team First→Last. Rest none.
- IL Previous Weeks: filter Current Week is "Old". Group Week - Dates A→Z (not Z→A), Team First→Last, Technician First→Last. Hidden: Current Week, Attachment. (IL has extra "Technician" column.)
- GA Current Week: filter Current Week is "Current". Group Team First→Last. Rest none.
- GA Previous Weeks: filter Current Week is "Old". Group Week - Dates A→Z, Team First→Last. Hidden: Current Week, Attachment. Sort/colour none.
- DMV Current Week: filter Current Week is "Current Week" (text differs from other markets' "Current"). Group Team First→Last. Rest none.
- DMV Previous Weeks: filter Current Week is "Old". Group Week - Dates A→Z, Team First→Last. Hidden: Current Week, Attachment.
- MA Current Week: filter Current Week is "Current Week". Group Team First→Last. Rest none.
- MA Previous Weeks: filter Current Week is "Old". Group Week - Dates A→Z, Team First→Last. Hidden: Current Week, Attachment.

### Profit And Loss (one table per market; footers Sum on money columns)
- NJ Grid view: filter Date is after "one week ago" (PST). Group Week (formula) A→Z. Sort/hidden/colour none.
- NJ Previous Weeks: filter Date is before "one week ago" (PST). Group Week A→Z. Sort/hidden/colour none.
- CT, TX, OH, IL, GA, DMV, WA, NW, NY, MA: identical to NJ (Grid view: Date after "one week ago" PST, group Week A→Z; Previous Weeks: Date before "one week ago" PST, group Week A→Z). No sort/hidden/colour on any.

### Gaps this adds to the rebuild list
- Relative-date filters ("one week ago", PST) — 30+ views.
- Nested AND-groups inside OR (Disputes & Refunds main views).
- Group by formula fields (Week, Week - Dates, WEEK) with A→Z / Z→A, up to 3 group levels.
- Conditional row colours (Status Of Job is Closed → red or blue; Type is Credit → teal).
- Per-view hidden fields; per-view column order; per-view manual row order (Invoices › Weekly Data Monthly).
- Group-header and footer Sum aggregates on every money column.
- Formula-text filters (Current Week is "Current"/"Current Week"/"Old"/"Previous Weeks" — values differ by market).
