/// How the organization's canvas arrangement is read and sent back.
///
/// ONE arrangement for the whole organization, not one each. It is a tenant
/// setting (§2.1 category 5) written by `OrganizationCanvasController::save`
/// and read off the tree payload `GET /companies` answers with, beside the
/// structure it describes — so everybody in the business opens the same
/// picture, and a colleague's change arrives with the next fetch rather than at
/// the next sign-in.
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
/// [canvasLayoutFrom] therefore reads the WHOLE list, and [canvasBoxes] writes
/// the whole thing back.
library;

import 'package:flutter/widgets.dart';

import '../../widgets/structure_canvas.dart';

/// The boxes of an arrangement, whichever shape the arrangement arrived in.
///
/// Takes the whole `tree['canvas']` value rather than a map to look a key up in,
/// because it is no longer stored under a key in anybody's preferences. It
/// arrives on the tree payload beside the structure it describes and it is the
/// same value the save endpoint echoes back, so one reader serves both and there
/// is no key name for the two of them to disagree about.
///
/// ── Two shapes, and the older one is not hypothetical ────────────
///
/// An arrangement is `{orientation, boxes}` now and used to be the boxes alone.
/// A bare list is therefore read as the boxes, which is what a server that has
/// not been deployed yet still sends and what
/// `OrganizationCanvasController::clean` still accepts from a column written
/// before today. A client that only understood the new shape would open the tab
/// with every box at its automatic place, and the first drag would write that
/// over an arrangement nobody asked to lose.
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
/// The `kind` is filtered against the kinds that actually HAVE a position, which
/// is now two rather than three. Not fussiness twice over: the map this returns
/// is what [canvasBoxes] sends back, and the server refuses an unknown kind with
/// a 422 — so a junk word surviving the read would turn every future save into a
/// refusal nobody could clear by dragging.
///
/// A stored `franchise` coordinate is the case that is neither junk nor
/// unknown, and it is dropped here for a different reason: a place is a row in a
/// list attached to its company now, so a position for one describes something
/// with nowhere to sit. Every arrangement saved before today has them —
/// this feature shipped this morning with places as boxes — and the server drops
/// them on the way out too, from the one function that cleans both directions.
/// Keeping them on this side would mean a map holding keys that name nothing on
/// screen, which is a real thing this map is supposed to hold (see below) and
/// would then include one kind that can never come back.
///
/// The id's SHAPE is left to the server, which judges it with `Str::isUuid`; a
/// second pattern written here is the thing that would come to disagree with it.
///
/// It is also used on the way back in, on the response to a save, and on every
/// refetch that carries somebody else's arrangement — which is the same trick
/// `OrganizationCanvasController::clean` plays by serving both directions: one
/// reading of what a stored box looks like, so the two cannot drift apart.
Map<String, Offset> canvasLayoutFrom(Object? stored) {
  final boxes = stored is Map ? stored['boxes'] : stored;

  if (boxes is! List) return const {};

  final places = <String, Offset>{};

  for (final entry in boxes) {
    if (entry is! Map) continue;

    final kind = entry['kind'];
    final id = entry['id'];
    final x = entry['x'];
    final y = entry['y'];

    if (kind is! String || id is! String || id.isEmpty) continue;

    // Both questions, in the order the server asks them: a word the vocabulary
    // knows, and a kind that has somewhere to sit.
    if (!kStructureKinds.contains(kind) || !structureHasBox(kind)) continue;

    // Not finite is not a coordinate. A NaN reaching a `Transform` throws, and
    // the box it names would take the whole screen down with it — so the entry
    // is dropped here rather than drawn.
    if (x is! num || y is! num || !x.isFinite || !y.isFinite) continue;

    places['$kind:$id'] = Offset(x.toDouble(), y.toDouble());
  }

  return places;
}

/// Which way the arrangement says the chart flows.
///
/// Read from the same value [canvasLayoutFrom] reads the boxes from, for the
/// same reason there is one reader for the boxes: the tree payload, the echo on
/// a save and a colleague's arrangement arriving on a refetch are three arrivals
/// of one thing, and three readings of it is three chances to disagree.
///
/// ── Why the orientation is stored and the zoom is not ────────────
///
/// Because it is a fact about the PICTURE rather than about where somebody
/// happens to be looking. Two colleagues on the phone describing "the box on the
/// left" are describing the same box only if the chart flows the same way for
/// both of them; whereas one of them zooming in moves nothing for the other and
/// must not. That line is drawn in exactly the same place the viewport offset
/// draws it inside the canvas, which is never reported anywhere at all.
///
/// A value this build does not recognise reads as the default rather than
/// throwing — see [structureOrientationFrom], which is the one reading of that
/// word, shared with everything else that has to make sense of it.
String canvasOrientationFrom(Object? stored) =>
    structureOrientationFrom(stored is Map ? stored['orientation'] : null);

/// An arrangement in the shape the save endpoint accepts.
///
/// Rounded to whole pixels, because that is what the server stores: sub-pixel
/// precision buys nothing on screen and costs bytes in a column every colleague
/// reads. Rounding here rather than leaving it to the server means the numbers
/// this client sent and the numbers it gets echoed back are the same numbers, so
/// applying the echo is not a visible correction of a value nobody chose.
///
/// A key that splits into an empty id is dropped rather than sent. It can only
/// come from a blob written by something that was not this app, and the server
/// would refuse the whole save over it — one unusable entry must not be able to
/// wedge somebody's arrangement permanently.
///
/// A place's coordinate is NOT filtered out here, and that is deliberate rather
/// than an omission: one cannot get in. [canvasLayoutFrom] is the only door into
/// the map this serialises — the tree, the echo on a save, a colleague's
/// arrangement, all three — and it drops them at that door. A second filter here
/// would be a second answer to "which kinds have a position", which is the
/// duplication the whole of `structure_levels.dart` exists to prevent.
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

/// The whole body `PUT /organization/canvas` takes.
///
/// One function so the wire shape is spelled once, which is the same discipline
/// `OrganizationCanvasController::clean` follows from the other end: an
/// arrangement is the boxes AND the direction they flow in, and a caller that
/// assembled the map itself would be a caller that could send half of one.
///
/// The orientation is always sent, even when nothing about it changed. The
/// endpoint reads an ABSENT orientation as "keep what is stored" — which is what
/// makes a save from an older client safe — and a client that has one is not an
/// older client. Leaving it out when it happened not to have changed would make
/// which fields are on the wire depend on what somebody did last, and the day
/// that matters is the day two people flip it in opposite directions.
Map<String, dynamic> canvasArrangement({
  required Map<String, Offset> positions,
  required String orientation,
}) => {
  'orientation': orientation,
  'boxes': canvasBoxes(positions),
};
