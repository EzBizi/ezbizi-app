/// What level a thing is on, which is a fact about WHAT IT IS.
///
/// ── The rule, in the owner's words ───────────────────────────────
///
///     level 1 - organization
///     level 2 - company
///     level 3 - sub company
///     level 4 - company franchises AND franchises of sub companies
///
/// Zero-based here, because both views multiply it by something — the canvas by
/// a row pitch and the outline by an indent step — and a one-based number would
/// have each of them subtract one before it could be used.
///
/// ── Why a node's level is not how deep the walk went ─────────────
///
/// Because §3 fixes the depth of the business at four, so the kind IS the
/// level. Both views used to derive it from the parent chain instead, and both
/// were wrong in the same way and wrong on a real customer: Everdoors has
/// franchises of its own AND sub companies, and a franchise hanging off the
/// company came out on the same line as a sub company hanging off the same
/// company. The picture then said the two were peers, when a sub company is
/// plainly the bigger thing — it holds franchises of its own.
///
/// The consequence, which is intended rather than tolerated: A-1 Chimney has
/// nine franchises and no sub companies, so its franchises sit on level 3 and
/// level 2 is empty beneath it. Every place in the business is on one line,
/// which is what the rule says and what somebody reading a chart expects. The
/// blank line is named in the canvas's own note so it does not read as
/// something failing to draw.
///
/// ── Why one file rather than a constant in each view ─────────────
///
/// Because the canvas and the outline are two pictures of one business and
/// there is exactly one hierarchy. They had a copy each — the canvas passed a
/// row down its recursion, the outline typed a depth at four call sites — and
/// both copies carried the same bug and had to be found twice. One function is
/// what makes "the outline agrees with the canvas" true by construction rather
/// than by somebody remembering.
///
/// Pure Dart on purpose: no Flutter, no widgets, nothing to pump. It is a fact
/// about the business, not about a screen, so both drawing widgets can import
/// it without either importing the other — which is what a shared constant in
/// one of them would have forced.
library;

/// The three kinds a box or a row can be, and they are the SERVER's three.
///
/// A sub company is a `company` — a sub company IS a company row, and the model
/// says so with `parent_company_id` rather than with a type. A fourth kind here
/// would be the client inventing a distinction the business does not make, and
/// it would be the first thing to disagree with the arrangement the canvas
/// stores, which is written in these same three words.
///
/// They were spelled twice before this file existed — `structureOrganization`
/// in the canvas and `structureOrganizationKind` in the outline, same string,
/// two names — and two spellings of one vocabulary is the duplication this file
/// exists to end. Both widgets re-export these, so nothing that imported either
/// of them had to change.
const String structureOrganization = 'organization';
const String structureCompany = 'company';
const String structureFranchise = 'franchise';

/// The four lines, zero-based, in the order the owner wrote them.
const int kStructureLevelOrganization = 0;
const int kStructureLevelCompany = 1;
const int kStructureLevelSubCompany = 2;
const int kStructureLevelFranchise = 3;

/// How many levels there are.
///
/// §3 fixes it and nothing here is configurable, which is the one thing on this
/// screen a customer cannot change: the depth of the hierarchy is what every
/// setting, assignment and roll-up in the product is expressed in terms of.
const int kStructureLevels = 4;

/// The level of one thing.
///
/// [isSub] is only consulted for a company, and it is the same question the
/// server answers with `parent_company_id`. A franchise is level 3 whether it
/// hangs off a company or off a sub company — that is the whole of the owner's
/// fourth line, and it is why this cannot be derived from the walk.
///
/// An unrecognised kind is read as a company rather than thrown on. This is a
/// value that arrives from the network by way of the payload, and a level
/// function that threw would take the whole structure screen down over one word
/// nobody can correct from inside the app; the middle of the chart is the least
/// wrong place to draw something whose kind is unreadable.
int structureLevelOf({required String kind, required bool isSub}) =>
    switch (kind) {
      structureOrganization => kStructureLevelOrganization,
      structureFranchise => kStructureLevelFranchise,
      _ => isSub ? kStructureLevelSubCompany : kStructureLevelCompany,
    };
