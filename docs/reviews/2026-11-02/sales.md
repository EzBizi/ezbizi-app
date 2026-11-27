# Sales — review 2026-11-02

The module was **removed** on Jose's instruction (2026-10-27): "scratch it entirely, we never ended
up using it". This review checks on production that it is gone and left nothing broken behind.

## 1. Working
- The removal is live in release `20261030222016-27b215107bb2`: `ModuleRegistry::get('sales')` returns null, so there is no sidebar tab and no `sales/deals` routes.
- The `sales_deals` table is gone (`Schema::hasTable` = false). Both the create and the drop migration are recorded as run. Production had 0 rows when the table was dropped.
- `main` has no references to `SalesController`, `SalesScreen` or `key: 'sales'`.

## 2. Broken
- Nothing is broken.
- **Leftover:** the `permissions` table still has two rows, `sales.view` and `sales.manage`, and 4 `permission_role` grants point at them. They do nothing: the registry no longer declares these keys, and `withImpliedView` drops undeclared keys. I left them in place because deleting them is a production data rewrite (rule: dump first) and has no visible effect.

## 3. Cohesiveness
- Not applicable: there is no screen.

## 4. Suggestions
1. Remove the dead permission rows the next time someone runs the permissions sync or does a cleanup with a dump. Payoff: the roles data is tidier; nobody can see the difference.
2. If pipeline tracking comes back inside Project Management, reuse two lessons. Read the lead date from Workiz's own `CreatedDate`, because `jobs.created_at` is the import time. Read quotes from wherever sales actually records them: 1,340 of the 1,399 pending jobs were $0 in Workiz itself.

## 5. Needs Jose
- None.
