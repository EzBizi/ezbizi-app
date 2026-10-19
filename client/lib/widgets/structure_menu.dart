import 'package:flutter/material.dart';

import 'refused.dart';

/// What can be done to a company or a place, wherever it is drawn.
///
/// ── Why this is one widget and not three copies ──────────────────
///
/// The structure is now drawn three ways — the list, the canvas and the narrow
/// outline — and all three have to offer the SAME acts under the SAME answers,
/// because they are three pictures of one thing. §4 has one home, and it is the
/// server: every entry below is enabled or refused by a boolean the server sent
/// on the node itself, read at the point of use out of the payload rather than
/// copied into a field somewhere on the way.
///
/// That is not a style preference, it is the specific bug this codebase has
/// already had. "Add a place" was drawn from `may_edit` — `companies.edit`,
/// which is not the permission it needs — so it was taken away from people
/// holding `franchises.create` and offered to people who did not hold it, and
/// either way it said nothing. One menu means that answer is wrong in at most
/// one place, and the fix lands everywhere at once.
///
/// ── Refused rather than hidden ───────────────────────────────────
///
/// An entry somebody cannot use stays in the menu with the reason under it, per
/// [refusedMenuItem]. Greying a row and saying nothing is indistinguishable from
/// a broken menu, and removing it entirely invites the reader to conclude the
/// app is missing a feature — which is the one conclusion that never leads them
/// to the role that would fix it.
///
/// The exception is an act that does not EXIST at this position, as opposed to
/// one this person may not perform. §3 fixes the depth, so a sub company can
/// never hold a sub company: there is no rule to explain and no role that would
/// grant it, so the caller passes a null callback and the entry is absent.
class CompanyActionsMenu extends StatelessWidget {
  const CompanyActionsMenu({
    super.key,
    required this.company,
    required this.onEdit,
    required this.onToggleActive,
    required this.onAddFranchise,
    required this.onAddSubCompany,
    required this.onMove,
  });

  /// The server's own map for this company, untouched — the §4 booleans are
  /// read straight off it.
  final Map<String, dynamic> company;

  final VoidCallback onEdit;
  final VoidCallback onToggleActive;
  final VoidCallback onAddFranchise;

  /// Null when this company cannot hold one at all (§3), which is different
  /// from not being allowed to add one.
  final VoidCallback? onAddSubCompany;

  /// Make it a sub company, or make it a company again (Jose, 2026-09-14).
  ///
  /// Not nullable, unlike [onAddSubCompany]: this act EXISTS at every position
  /// — a sub company can always be promoted and a company can always be
  /// demoted somewhere — so when it is unavailable there is a rule to explain,
  /// and `may_reparent_reason` explains it.
  final VoidCallback onMove;

  @override
  Widget build(BuildContext context) {
    final active = company['is_active'] as bool? ?? true;
    final no = companyRefusals(company);
    final mayEdit = no.edit == null;
    final mayAddFranchise = no.addFranchise == null;
    final noAddFranchise = no.addFranchise;
    final mayMove = no.move == null;
    final noMove = no.move;
    final mayAddSub = no.addSub == null;
    final noAddSub = no.addSub;
    final name = company['name'] as String? ?? '';

    // Drawn whenever ANY entry in it is reachable, and drawn as nothing at all
    // when none is. Hiding the whole menu from somebody who may add a place but
    // not rename the company is how a control that exists becomes one nobody
    // can find; keeping an empty one is a button that opens a menu of four
    // sentences saying no, which is worse than the absence.
    if (!mayEdit && !mayAddFranchise && !mayAddSub && !mayMove) {
      return const SizedBox.shrink();
    }

    return PopupMenuButton<String>(
      icon: const Icon(Icons.more_horiz, size: 20),
      tooltip: 'What to do with $name',
      itemBuilder: (context) => [
        refusedMenuItem(
          context,
          value: 'edit',
          label: 'Edit details',
          reason: no.edit,
        ),
        refusedMenuItem(
          context,
          value: 'franchise',
          label: 'Add a place',
          reason: noAddFranchise,
        ),
        if (onAddSubCompany != null)
          refusedMenuItem(
            context,
            value: 'sub',
            label: 'Add a sub company',
            reason: noAddSub,
          ),
        // One entry rather than two. Which direction it goes is the picker's
        // question, and a menu offering "Make it a sub company" beside "Make it
        // a company again" would have one of the two greyed out on every
        // company in the business, permanently, explaining a rule about where
        // it already is.
        //
        // NOT "Move it", which was the first wording and collided with a verb
        // this product has already spent. This menu is drawn on the canvas as
        // well as the list, and `structure_canvas.dart` spends THREE of its
        // numbered points -- 4, 5 and 6 -- establishing that dragging a box
        // changes the picture and not the business: overlap means nothing and
        // never reads as "it went inside", the connector never detaches
        // because it is derived from the tree, and the cursor is `move`
        // because "`grab` is 'pick this up and carry it somewhere'; `move` is
        // 'reposition this'". So "Move it" would have named, three inches
        // away, the exact operation those three points exist to say a drag is
        // NOT.
        //
        // Raised by UI/UX, who read that file before saying so -- and who
        // corrected this comment's own count from two points to three, by
        // reading it again rather than repeating mine.
        refusedMenuItem(
          context,
          value: 'move',
          label: 'Change what it sits under',
          reason: noMove,
        ),
        refusedMenuItem(
          context,
          value: 'active',
          label: active ? 'Switch off' : 'Switch back on',
          reason: no.toggle,
        ),
      ],
      onSelected: (v) => switch (v) {
        'edit' => onEdit(),
        'franchise' => onAddFranchise(),
        'sub' => onAddSubCompany?.call(),
        'move' => onMove(),
        _ => onToggleActive(),
      },
    );
  }
}

/// Why each act on [company] is refused, or null where it is not — read off
/// the server's own map, with the sentence it sent where it sent one.
///
/// One function, because the menu above and the List view's button row offer
/// the same acts and must give the same answers (UI queue 111).
({
  String? edit,
  String? addFranchise,
  String? addSub,
  String? move,
  String? toggle,
})
companyRefusals(Map<String, dynamic> company) {
  final name = company['name'] as String? ?? '';
  final mayEdit = company['may_edit'] as bool? ?? false;

  return (
    edit: mayEdit ? null : 'Your role does not include changing a company.',
    addFranchise: company['may_add_franchise'] as bool? ?? false
        ? null
        : company['may_add_franchise_reason'] as String? ??
              'Adding a place to $name is not yours to do.',
    addSub: company['may_add_sub_company'] as bool? ?? false
        ? null
        : company['may_add_sub_company_reason'] as String? ??
              'Adding a sub company to $name is not yours to do.',
    move: company['may_reparent'] as bool? ?? false
        ? null
        : company['may_reparent_reason'] as String? ??
              'Moving $name is not yours to do.',
    toggle: mayEdit
        ? null
        : 'Your role does not include switching a company off or back on.',
  );
}

/// What can be done to a place, wherever it is drawn.
///
/// The whole menu is gated on one answer rather than entry by entry, because
/// both acts are the same permission asked against the same place — there is no
/// state where renaming is allowed and switching off is not. So [refusedMenuItem]
/// would print the identical sentence twice, and the refusal goes on the anchor
/// instead, where a tooltip is actually reachable.
class FranchiseActionsMenu extends StatelessWidget {
  const FranchiseActionsMenu({
    super.key,
    required this.franchise,
    required this.onRename,
    required this.onToggle,
    this.child,
  });

  /// The server's own map for this place, untouched.
  final Map<String, dynamic> franchise;

  final VoidCallback onRename;
  final VoidCallback onToggle;

  /// What the menu hangs off. The list draws the whole chip as the anchor, so
  /// the place and the way to act on it are one target; the canvas box and the
  /// outline row have their own body already and want only the handle, so they
  /// leave this null and get the same icon the company menu uses.
  final Widget? child;

  @override
  Widget build(BuildContext context) {
    final active = franchise['is_active'] as bool? ?? true;
    final mayEdit = franchise['may_edit'] as bool? ?? false;
    final name = franchise['name'] as String? ?? '';

    // The timezone and the currency are facts about this place that are worth
    // having somewhere, and the anchor's tooltip is the only surface on a chip
    // that can carry them.
    //
    // Present on all three views today, and the guard is tolerance rather than a
    // branch anything takes. `CompaniesController::index` puts `timezone` and
    // `currency` on every franchise it sends, and that ONE payload is what all
    // three views read — the list's chip, `StructureNode.raw` on a canvas box
    // and `StructureOutlineRow.raw` on an outline row are the same untouched
    // map. So every caller in this app reaches the first branch, and the second
    // is here for a wire shape that omits them rather than for a caller that
    // does. (This comment used to claim the opposite — that a canvas box and an
    // outline row carry no such payload — which would have had the next reader
    // believe those two views get a stripped map, and that map is also where
    // `may_edit` and the rest of the §4 answers are read from.)
    final facts = franchise.containsKey('timezone')
        ? '${franchise['timezone']} · ${franchise['currency']}'
        : name;

    return PopupMenuButton<String>(
      enabled: mayEdit,
      // A menu that will not open, over something that looks like every other
      // one, is indistinguishable from a bug. The server's own sentence says
      // which half of §4 is missing.
      tooltip: mayEdit
          ? facts
          : '$facts — '
                '${franchise['may_edit_reason'] as String? ?? 'This one is not yours to change.'}',
      itemBuilder: (_) => [
        const PopupMenuItem(value: 'rename', child: Text('Rename')),
        PopupMenuItem(
          value: 'active',
          child: Text(active ? 'Switch off' : 'Switch back on'),
        ),
      ],
      onSelected: (v) => v == 'rename' ? onRename() : onToggle(),
      icon: child == null ? const Icon(Icons.more_horiz, size: 20) : null,
      child: child,
    );
  }
}
