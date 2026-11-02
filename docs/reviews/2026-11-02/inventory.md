# Inventory: review, 2026-11-02

Measured on production with the Viewer API key (A1-Group), 2026-11-02. Every
Inventory read was called live. The screens were not opened in a browser: there
is nothing in them to look at (see Broken).

## 1. Working

- `GET /inventory/summary` (General): 200. It returns health, warnings, movement with `previous`, `by_location` and `top_items`. The empty state is "No items to show."
- `GET /inventory/stock/items` (Stock): 200. The tiles arrive, with value null rather than 0.
- `GET /inventory/items` (Items): 200. Tiles {items, below_reorder, out_of_stock, discontinued}.
- `GET /inventory/locations` (Locations): 200. Tiles {places, storage, shops, vans}, plus `tiles_value_minor`.
- `GET /inventory/purchase-orders`, `/sales`, `/issues` (Orders): 200, each with its tiles or total.
- Back arrow on an opened item at every width (0.0.0345), tiles on every tab (0.0.0346), Dashboard attention rows (0.0.0354): all shipped.
- Per-tab permissions and money withheld without `inventory.financials`: covered by tests (171 green on 2026-10-26).

## 2. Broken

- **Nothing is failing, but nothing is in it either.** A1-Group has **0 items, 0 locations, 0 orders, 0 sales and 0 issues**. Every tab is the empty state. The only items on production are 4 demo items (`DEMO-*`) in another org. Why: Inventory was never seeded. The Workiz price book (856 rows) is services, with no stock tracked, and Jose ruled on 2026-10-06 not to load it. So no screen has been exercised by real use, and nothing in the module can prove itself until a first location and item exist.
- No fix SHAs: nothing I own is failing.

## 3. Cohesiveness (for UI/UX)

- Inventory's tiles came from UI/UX's own spec (0075), and General was redone in Fleet's layout (c25765f6), so the module should match Fleet. I didn't verify the rendered screens, because every tab is the empty state.
- The tile payload names differ between endpoints: Stock sends `below_reorder`/`out_of_stock` inside a wider block (`covered_by_order`, `open_orders`…), while Items sends the same two keys in a four-key block. The client handles both, but a reader of the API sees two shapes.

## 4. Suggestions (ranked)

1. **Seed one real location per branch** (each branch's shop or warehouse, plus its vans from Fleet). With places in place, receiving the first purchase order makes every tab live. This is the payoff that unlocks the rest.
2. **Create vans as Inventory locations from Fleet automatically.** Every vehicle is a place where stock rides, so this avoids typing each van twice.
3. **Issue parts from a Job.** Inventory issues already accept `job_id`; a "Parts used" control on the job screen would make stock move as part of normal work instead of a separate step.
4. Load the 13 Workiz items marked inventory-managed as items (no stock), only if Jose wants a starting catalogue.

## 5. Needs Jose

- Does A1-Group intend to track parts in EzBizi? If yes, which places come first (per-branch shop/warehouse, vans)? If no, should Inventory be switched off for A1-Group so its empty tabs don't show?
