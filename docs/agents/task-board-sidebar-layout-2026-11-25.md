# Task Board sidebar: inventory and proposed layout (desk, 2026-11-25)

Jose (11-25, on 0.0.0644's sidebar): *"wtf happened to the other company boards like the one from ezbizi, or the one from everdoors ... A1 - Operation should be at the top ... the existance of folders but also the dividers themselves, it's a mess and you can't tell what is what. I want you to review it here and on Monday and propose a proper layout."*

## What is there (measured on prod, Triplex Group org, 11-25)

- **32 company boards** (kind=company), one per company, including EzBizi and Everdoors. **All are empty** and none is shown since f697affd0.
- **A-1 Chimney has no kind=company board.** Its working company board is the Monday board "A1 - Operation" (custom, 684 tasks).
- Everdoors has an empty company board "Everdoors", but its working board is "Everdoors - Operation" (255 tasks).
- **Branch boards (kind=franchise) holding tasks:** A-1 Chimney's NW 25,001 · IL 1,825 · NJ 1,400 · OH 1,159 · CT 854 · TX 703 · GA 588 · DMV 556 · MA 249 · New York 14. Everdoors has Connecticut 24.
- **Empty auto-made branch boards:**
  - 22 named "Main", one per single-branch company.
  - 6 more named "Connecticut" (Crestline, A1 Garage, Premier, US Garage, Speedy, Roofirst).
  - Everdoors DMV, EzBizi Florida, Texas for AngiSweep and Air 75, Nationwide for Chimney Star.
  - 65 boards are empty in total.
- **Monday boards holding tasks:**
  - Under A-1 Chimney:
    - All Calls 52,684 and Outgoing Calls 839.
    - Partners: Ohio 104, Illinois 123, Nehorai-CT 117, Liraz-DMV 66, Ron-MA 32, Washington 47.
    - Project Management: Ohio PM 399, Illinois PM 130, DMV NEW 78.
  - Under Everdoors: Yosef 128, Everdoors - Brand 16, Everdoors - MA Franchise 24.
  - Triplex Group - Files 108.
  - Organization-wide: Reviews & Complaints 782, Users 150, Job Roles, Sharper - Rise-OFFICE 61.
  - Templates: New Location 25, New Brand 16, New Franchise 24.
  - Analytics Dashboard 5 (Dark Theme, dieuve's personal Monday workspace).
  - Toula Elwell 190.
- **Personal boards:** 165.

## Monday (Main workspace), as it is

- **A1 Chimney** (folder)
  - A1 - Operation
  - Partners › 9 partner boards
  - Office Management › 5 people boards
  - Marketing › Jose, Rodrigo
  - Field Management
    - Operations Management › NW, IL, OH, NJ, CT, DMV, TX, GA, MA, Outgoing Calls
    - Project Management › Ohio PM, DMV NEW, Illinois PM
    - Field Managers (empty)
- **Everdoors**
  - Everdoors - Operation
  - Partners › Yosef
- **Triplex Group**
  - Triplex Group - Files
  - Yosef (CT, MA, RI, ME, NH) (empty)
- **New Locations**
  - 3 templates
  - one sub-folder per brand; only Everdoors has boards (Brand, MA Franchise), plus a lot of empty "New Folder"s
- **Email marketing** (empty)

## Proposed layout (recommended)

One look per level, never mixed:
- **section label:** small caps
- **company row:** bold, with the company's colour dot
- **folder row:** folder icon, plain text, no colour
- **board row:** board icon

Order:

1. Search · **Favorites** · **My boards**: the Personal Task Board, then boards I own.
2. **COMPANIES**: one collapsible row per company. A-1 Chimney, Everdoors, EzBizi and Triplex Group come first, then every other company A–Z, collapsed by default. Inside each company:
   1. **The company board, always first.** A-1 Chimney → A1 - Operation. Everdoors → Everdoors - Operation, with the empty "Everdoors" company board retired into it. EzBizi → EzBizi. Triplex Group → Triplex Group - Files.
   2. **Branches**: its branch boards A–Z (A-1 Chimney: DMV, CT, GA, IL, MA, NJ, NW, New York, OH, TX). A company whose only branch is "Main" shows no branch row; that board is the same place as the company board.
   3. **Its folders**, as on Monday:
      - A-1 Chimney:
        - Office Management
        - Marketing
        - Partners
        - Field Management › Operations Management (All Calls, Outgoing Calls) / Project Management
      - Everdoors:
        - Partners › Yosef
        - New location › Brand, MA Franchise
      - Triplex Group: Yosef (CT, MA, RI, ME, NH)
3. **ORGANIZATION** (shared by every company): Reviews & Complaints, Users, Job Roles, Sharper - Rise-OFFICE.
4. **TEMPLATES**: New Location / New Brand / New Franchise Template.
5. **EVERYONE'S BOARDS**: other people's personal boards (incl. Toula Elwell, Analytics Dashboard), collapsed.

Gone:
- the separate "FOLDERS" section: every folder lives inside its company
- coloured folder names
- "Dark Theme" as a top-level folder
- empty Monday "New Folder"s

Search finds boards by board name AND company name ("ezb" → EzBizi and its boards).
