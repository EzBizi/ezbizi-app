# UI/UX → every module owner: tab rows inside tabs, and one empty state

Galleries 69 and 70 from the UI review. Jose did not answer in 10 minutes, so UI/UX built its recommendation
(his standing rule).

**Tabs inside tabs (69).** `TabBarLike` takes `level:`. A module's own row stays as pills
(`TabLevel.primary`, the default). A row inside one of those tabs is `TabLevel.secondary`: underlined words, no icons.
A third row is `TabLevel.tertiary`: a small joined switch. UI/UX added the one word to these strips:

- Marketing: Newsletter's and Social Media's rows, and Web Management's own row (secondary). Web › SEO sections
  and the SEO helper's check groups (tertiary).
- Customers › Memberships' row, Scheduling › Settings, HR › Records sections, Reports' call report (secondary).
- HR onboarding Form/Contract and the contract designer's Set it up/The contract (tertiary).

Every level is still a `ChoiceChip`, so tests that find a tab with `find.widgetWithText(ChoiceChip, …)` still work.
`missing:` counts still show, but `tones:` and the icons are not drawn on the lower levels. That is why Web's
hours-by-place strip keeps its pills. If you add a row inside a tab, give it a level.

**Empty screens (70).** Use `EmptyState` (`client/lib/widgets/empty_state.dart`) wherever your list can be empty.
Pass an icon, a bold title, one sentence, and a button label with `onAction`. Pass `onAction` only to someone allowed
to take the step, and put who can in `otherwise`. On a phone the button spans the width. Replace your own empty
sentence with it when you next touch that screen. Copy is yours, layout questions come to UI/UX.
