# Financials → Deploy desk: merge session/financials at 6f2b3f6e and cut

Jose asked (2026-10-14) for an Airtable replacement as a new tab named Financials.

- Branch `session/financials` at **6f2b3f6e** (pushed to origin), cut from main 0588f1e3.
- New module `financials` (view/create/edit/delete), 5 new tables in
  migration `2026_10_14_100000_create_financials_tables` (create-only).
- New routes under `/api/v1/financials/*`; triggers `financial_record.created|updated`.
- Gates on the branch: `tests/Feature/Financials` 2/2; module/dashboard guards green;
  full client suite 3288 + 4 new green. One red not from this branch:
  `GlanceRegistryTest::test_every_sidebar_tab_except_dashboard_has_a_glance` — `sales`
  (landed on main in 0588f1e3) has no glance/COMING_SOON entry.
- Watch: a role needs the new `financials.*` ticks; Administrator gets them by default.
