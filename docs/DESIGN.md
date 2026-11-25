# EzBizi — DESIGN.md

The design system of EzBizi, a field-service CRM (leads, customers, jobs, scheduling, task boards, phone, reports) used by office staff on desktop and by dispatchers and technicians on phones. Every value below is read from the live theme (`client/lib/core/theme.dart`, release 0.0.0646, 2026-11-25), not from a mockup.

## 1. Brand

- **Name:** EzBizi. The app icon is a white **E** and a black **z** on a flat colour square, with a soft drop shadow under the letters.
- **Brand colour:** cyan `#00AEEF`.
  - On a **dark** surface it is the primary colour itself.
  - On a **light** surface it is too weak for text (2.5:1 on white). There, primary is the deep cyan `#00708F` and the bright cyan is kept for large fills and marks with no text.
- **Sister icons:** the same E and z, only the colour changes: Reports red `#ED1123`, EzChat green `#00C875`.
- **Never purple.** The owner rejects violet and purple accents as "the mark of AI did this". Accents stay in the cyan family; the second and third accents are a deeper and a greyer cyan.
- **Voice:** plain, short, specific. Labels say what a thing is ("Booked new", "Calls scored"), not marketing words. Use sentence case, and no exclamation marks.

## 2. Colour tokens

### Light theme
| Role | Hex |
|---|---|
| primary (buttons, links, focus) | `#00708F` |
| onPrimary | `#FFFFFF` |
| primaryContainer (selected row, selected tab) | `#C6E7FF` |
| onPrimaryContainer | `#004C6B` |
| secondary | `#00708F` |
| secondaryContainer (secondary highlight card) | `#D2E5F4` |
| tertiary (third accent, slate-cyan) | `#41627A` |
| error / errorContainer | `#BA1A1A` / `#FFDAD6` |
| page background (scaffold) | `#F5F8FA` |
| surface (cards, panels, top bar) | `#FFFFFF` |
| surfaceContainerLow (sidebar) | `#F0F4F8` |
| surfaceContainer | `#EBEEF3` |
| surfaceContainerHighest | `#DFE3E7` |
| text (ink) | `#060808` (headings), `#181C1F` (onSurface) |
| muted text | `#5B6672` |
| onSurfaceVariant | `#41484D` |
| hairline / borders | `#060808` at 10% opacity, edge `#E9EDF1` |
| outline / outlineVariant | `#71787E` / `#C1C7CE` |

### Dark theme
| Role | Hex |
|---|---|
| primary | `#00AEEF` (the brand cyan) |
| onPrimary | `#060808` (dark ink on cyan, 7.9:1) |
| primaryContainer / onPrimaryContainer | `#004C6B` / `#C6E7FF` |
| secondary | `#0093CA` |
| secondaryContainer | `#374955` |
| tertiary | `#6E8CA0` |
| error / errorContainer | `#FFB4AB` / `#93000A` |
| page background | `#0B0F14` |
| surface | `#10161B` |
| surfaceContainerLow (sidebar) | `#181C1F` |
| surfaceContainer | `#1C2024` |
| surfaceContainerHighest | `#313539` |
| text | `#FFFFFF` headings, `#DFE3E7` body |
| muted text | white at 70% |
| input fill | `#161E25` |
| hairline / edge | white at 12% / `#1E262E` |
| outline / outlineVariant | `#8B9198` / `#41484D` |

The user picks the theme in Settings › Appearance:
- **Light** or **Dark**, plus two mixes, **Dark sidebar** and **Light sidebar**, where the sidebar takes the opposite theme to the page.
- There is no "match device" option; a new user starts on the device's mode.
- The sidebar can be a **List** (icon and label side by side) or **Blocks** (stacked).

### Status tones (a fill and its ink, used for tags and banners)
| Tone | Light fill / ink | Dark fill / ink |
|---|---|---|
| neutral | `#E8ECF0` / `#33404A` | `#2C333B` / `#D3DAE0` |
| info | `#D9EFF8` / `#00566E` | `#0E3A48` / `#8FD6EE` |
| ok (done, paid) | `#E1F2E5` / `#16662D` | `#163A22` / `#8FDBA4` |
| caution (pending, due soon) | `#FFF0D2` / `#734600` | `#3D2E0C` / `#F1C56A` |
| review | `#DDF3EE` / `#0B5E54` | `#0F3B36` / `#86DCCB` |
| bad (failed, overdue) | `#FDECEC` / `#9F1D1D` | `#4A1C1C` / `#FF8A8A` |

### Single-purpose colours
- Clock in green `#0B8F5B` (dark `#3DDC97`); clock out red `#E07070` (dark `#FF8A80`); meter warning `#B37400` (dark `#E0A93B`).
- Reviews: happy `#22B24C`, unhappy `#E23D4B`, star `#F5B301`.
- Medals (rank 1, 2, 3): gold `#B8901F`, silver `#8E979F`, bronze `#A5672F`.
- Field-app status: green `#22C55E`, red `#EF4444`, yellow `#FACC15`, blue `#3B82F6`, teal `#14B8A6`, slate `#64748B`.
- Brand gradient (login and marketing only): `#071018` → `#0A2233` → `#06384E`.

### Task Board (monday.com parity)
- Canvas `#E8EDF2` / dark `#080B0F`; group block `#F6F8FA` / `#151B21`; row `#FFFFFF` / `#1B2229`; row hover `#EDF2F7` / `#252D35`; row line `#D0D4E4` / `#4B4E69`.
- **Label and group palette:** `#C4C4C4` grey, `#E2445C` red, `#FDAB3D` orange, `#FFCB00` yellow, `#00C875` green, `#4ECCC6` aqua, `#0086C0` blue, `#225091` navy, `#7F5347` brown.
- A group's title row is filled with the group colour mixed into the group block: 30% in light, 50% in dark. Text on a colour fill is white or ink `#16191C`, whichever has more contrast.
- **Avatar palette:** `#1A73E8`, `#D93025`, `#188038`, `#F9AB00`, `#00897B`, `#1967D2`, `#E37400`, `#12B5CB`.

## 3. Typography

- **Family:** Inter, everywhere.
- **Scale:**
  - Display: 40 / 700, letter-spacing −1.
  - Page headline: 28 / 800, −0.8.
  - Section headline: 20 / 700, −0.3.
  - Title large: 18 / 700. Title medium: 16 / 600. Title small: 14 / 600.
  - Body large: 16, line height 1.5 (ink).
  - Body: 14, line height 1.5, muted colour.
  - Body small: 12, line height 1.45, muted.
  - Label large: 14 / 600. Label medium: 12 / 600, muted.
  - Label small: 11 / 700, letter-spacing 1.2, muted. Used in UPPERCASE for section headers ("FAVORITES", "MY BOARDS").
- Numbers that are read from across a room (dashboard tiles, scores) are large and heavy (800). The label under them is small and muted.
- Buttons: 15 / 600.

## 4. Shape, size and spacing

- Corner radius: controls (buttons, inputs) **10**; cards **14**; chips and pills fully round; table rows square.
- Control height **44** (touch-safe); phone list rows at least 40–48 tall.
- Cards: no elevation, a 1px hairline border, a surface fill.
- Spacing runs on a 4/8 grid: 8, 12, 14, 16 and 24 are the common gaps; 16 is the phone side gutter.
- Elevation is avoided. Separation comes from a fill change and a hairline, not shadows. The exceptions are popovers and the drag feedback card.

## 5. Layout

### Desktop / web
- **Left sidebar:**
  - Tinted (`surfaceContainerLow`), with a right edge line. Default width 220; the user can drag it from 180 up, and the width is remembered.
  - It holds a "Find a module" search, then the modules (rearrangeable into user-named sections with dividers), then Settings, Suggestions and Development pinned below a divider.
  - At the foot: "Rearrange" and the version.
  - It can collapse to an icon rail, where "More" is just an arrow.
- **Top bar:** white surface. It holds the Company · Branch picker (always "Company · Branch", never a bare branch name), the time clock, notifications, the light/dark toggle and the account.
- **Module page:** the shell draws one header per module (the module's name plus a one-line subtitle), then the module's tabs, then the content. A pane never repeats the tab's name as its own heading.
- **List + record:** a list on the left and the record on the right, both inside the module. A record never opens as a full-screen page.

### Phone
- A bottom bar with **4 slots chosen per role, plus a fixed "More"**.
- A page opens in place with a ← back, never as a pushed full-screen route. Lists are one column with search at the top.
- A phone turned sideways keeps the phone layout. An unfolded Duo (600–899 wide) keeps the bottom bar and shows the list and record side by side.
- While the keyboard is up, page titles fold away, and dragging a page closes the keyboard.

## 6. Components

- **Buttons:**
  - Filled primary for the one main action.
  - Outlined for secondary actions, with a 2px primary border.
  - Text buttons for low-weight actions.
  - Every button kind is the same height.
- **Inputs:**
  - Filled white (dark `#161E25`), 10 radius, hairline border, 2px primary border on focus.
  - The resting label is 16 / 400 muted; the floating label is 600.
- **Search bars:** every list, table and drill-down has one. On paged lists it searches the server (job number, customer, address, phone, technician).
- **Tags / pills:** small rounded pills, 11 / 600. They use a tone fill + ink, or a data colour with auto-contrast ink. Tags line up beside the time on list rows.
- **Selected row:** a `primaryContainer` fill. **Never a coloured bar down the leading edge.**
- **Tiles:** sibling tiles and cards in one row are always the same size.
- **Side panels instead of dialogs:** confirmations, editors and pickers open as a panel inside the page. No modal dialogs, and no pop-up dropdown buttons (use an in-place list or a menu anchored to its control).
- **Tables:** square rows with a hairline between them. Header labels are muted 12 / 600. A pane's dragged width is saved per person.
- **Empty states:** one plain sentence saying what is missing and where it gets filled in. Never an empty box, and never a "coming soon" that has no place behind it.
- **Charts:** colours come from the same palettes. A missing number is shown as missing, not as zero.

## 7. Rules the owner has repeated

1. Every list has a search bar.
2. Branch pickers always say "Company · Branch".
3. Sibling tiles are the same size.
4. Everything stays inside the app shell: no full-screen pages for details or editors.
5. A dragged pane width is remembered per person.
6. No purple. No leading-edge accent bars. No modal dialogs.
7. Hierarchy is visible: organization → company → branch or folder → item, one indent step per level, one visual style per level.
8. No row that only repeats its single child. No "· 1 item" counts.
9. Record headers follow the Leads record header: name, then key facts in a tidy grid, then actions.
10. Light and dark are both first-class: every screen is checked in both.

## 8. Do / Don't

**Do:** flat surfaces with hairlines; cyan for action and selection; Monday-style colours only for data (status labels, groups); Inter; plain labels; dense but aligned tables on desktop; large touch targets and one column on phones.

**Don't:** purple or violet; gradients on working screens; drop-shadowed cards; full-screen modals; a coloured stripe down the side of a card or row; hiding a field because it is empty (it then can never be filled); fading text to show a state.
