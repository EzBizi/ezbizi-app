/// How a canvas arrangement is stored, read and sent back.
///
/// ── Why this file is here rather than in `lib/core/` ─────────────
///
/// The plan put the node model, the automatic layout and this reader in one
/// `core/structure_layout.dart`. The first two ended up in
/// `widgets/structure_canvas.dart` instead, because that widget had to stand on
/// its own — it takes the tree and a layout and talks to nothing — and there
/// must not be a second [StructureNode] to disagree with it.
///
/// What is left is the persistence half, and it needs [structureKindOf] and
/// [structureIdOf] to take a `kind:id` key apart again. A file in `core/` that
/// imported a widget would invert the direction every other file in `core/`
/// respects; a file here importing a widget is what every screen does. So it
/// sits beside the tab that owns the saving, and the key format stays spelled
/// in exactly one place — which is the thing that actually matters, because two
/// spellings of it is how a stored position stops matching the box it names.
///
/// ── What this deliberately does NOT do ───────────────────────────
///
/// It does not check whether an id names something on screen. A stored position
/// for a company nobody can see this afternoon — a role being edited, a module
/// switched off and back on, a scope narrowed — is an arrangement to be kept,
/// not junk to be swept up. `OrganizationCanvasController::save` keeps those
/// entries on purpose, and a client that read only what it could draw would
/// defeat that rule from the other side: the first nudge of any box would
/// quietly drop every position belonging to something that happened to be away.
/// [savedCanvasLayout] therefore reads the WHOLE blob, and [canvasBoxes] writes
/// the whole thing back.
library;

import 'package:flutter/widgets.dart';

import '../../widgets/structure_canvas.dart';

/// Where one person's arrangement lives inside their preferences.
///
/// The same key the server writes, spelled once on this side. It is a per-user
/// preference (§2.1 category 6) in `users.preferences`, which is why it arrives
/// on `/me` with the theme and needs no request of its own — and why it is not
/// a tenant setting: two people sharing a role must be able to disagree about
/// where a box sits.
const String organizationCanvasPreference = 'organization.canvas';

/// The arrangement this user last saved, keyed the way the canvas keys a box.
///
/// Read defensively at every level, and not as belt-and-braces. This is JSON out
/// of a column that can have been written by an older build of this app, edited
/// by hand, or restored from a backup taken before this feature existed — so
/// nothing about its shape is promised. A screen that threw because somebody's
/// stored arrangement was a string would be a screen nobody could open and
/// nobody could fix, which is the reason `savedPanelOrder` reads its list the
/// same way.
///
/// A bad entry costs its own box and nothing else. Bailing on the whole list
/// because one row lost its `y` would throw away an arrangement over a box that
/// could simply have been drawn at its automatic place.
///
/// The `kind` is filtered against the three the canvas draws. Not fussiness: the
/// list this returns is the list [canvasBoxes] sends back, and the server
/// refuses an unknown kind with a 422 — so a single junk word surviving the read
/// would turn every future save into a refusal nobody could clear by dragging.
/// The id's SHAPE is left to the server, which judges it with `Str::isUuid`; a
/// second pattern written here is the thing that would come to disagree with it.
///
/// It is also used on the way back in, on the response to a save, which is the
/// same trick `OrganizationCanvasController::normalised` plays by serving both
/// directions: one reading of what a stored box looks like, so the two cannot
/// drift apart.
Map<String, Offset> savedCanvasLayout(Map<String, dynamic> preferences) {
  final stored = preferences[organizationCanvasPreference];

  if (stored is! List) return const {};

  final places = <String, Offset>{};

  for (final entry in stored) {
    if (entry is! Map) continue;

    final kind = entry['kind'];
    final id = entry['id'];
    final x = entry['x'];
    final y = entry['y'];

    if (kind is! String || id is! String || id.isEmpty) continue;

    if (kind != structureOrganization &&
        kind != structureCompany &&
        kind != structureFranchise) {
      continue;
    }

    // Not finite is not a coordinate. A NaN reaching a `Transform` throws, and
    // the box it names would take the whole screen down with it — so the entry
    // is dropped here rather than drawn.
    if (x is! num || y is! num || !x.isFinite || !y.isFinite) continue;

    places['$kind:$id'] = Offset(x.toDouble(), y.toDouble());
  }

  return places;
}

/// An arrangement in the shape the save endpoint accepts.
///
/// Rounded to whole pixels, because that is what the server stores: sub-pixel
/// precision buys nothing on screen and costs bytes in a column read on every
/// `/me`. Rounding here rather than leaving it to the server means the numbers
/// this client sent and the numbers it gets echoed back are the same numbers, so
/// applying the echo is not a visible correction of a value nobody chose.
///
/// A key that splits into an empty id is dropped rather than sent. It can only
/// come from a blob written by something that was not this app, and the server
/// would refuse the whole save over it — one unusable entry must not be able to
/// wedge somebody's arrangement permanently.
List<Map<String, dynamic>> canvasBoxes(Map<String, Offset> positions) {
  final boxes = <Map<String, dynamic>>[];

  for (final entry in positions.entries) {
    final kind = structureKindOf(entry.key);
    final id = structureIdOf(entry.key);

    if (kind.isEmpty || id.isEmpty) continue;

    boxes.add({
      'kind': kind,
      'id': id,
      'x': entry.value.dx.round(),
      'y': entry.value.dy.round(),
    });
  }

  return boxes;
}
