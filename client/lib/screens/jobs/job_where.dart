import 'package:flutter/material.dart';

import '../../core/theme.dart';

/// Where a job is: its franchise, and the service area inside it.
///
/// Queue 25, Jose's pick (option 1): one "Where" column reading
/// "Illinois · Chicago", broader to narrower like the customer owner tag.
/// Jose, 2026-09-25: *"Service Areas — sub divisions within the franchise."*
///
/// **The area alone when it would repeat the franchise.** Three of A1-Group's
/// areas share their franchise's name — Connecticut, New Jersey, New York — and
/// "Connecticut · Connecticut" is one fact printed twice (the settled rule: a
/// second mark must say something the first does not).
///
/// **No area is "not placed", never an error.** About one job in twenty is
/// left empty on purpose — a retired Workiz name, a call-centre job tagged with
/// another franchise's area — because an empty area says "look at this job"
/// where a wrong one would be believed (JobServiceArea).
({String franchise, String? area}) jobWhere(Map<String, dynamic> job) {
  final franchise =
      (job['franchise'] as Map<String, dynamic>?)?['name'] as String? ?? '';
  final area = (job['service_area'] as Map<String, dynamic>?)?['name'];

  return (
    franchise: franchise,
    area: area is String && area.trim().isNotEmpty ? area : null,
  );
}

/// The words, for a line that is plain text. "Ohio" when unplaced — the mark
/// that says so is drawn beside it, not spelled into the sentence.
String whereText(Map<String, dynamic> job) {
  final (:franchise, :area) = jobWhere(job);

  if (area == null) return franchise;
  if (area.toLowerCase() == franchise.toLowerCase()) return area;

  return '$franchise · $area';
}

/// The table cell: the franchise quieter than the area it contains, so the
/// part that differs between rows is the part that reads.
class WhereLabel extends StatelessWidget {
  const WhereLabel(this.job, {super.key, required this.placeable});

  final Map<String, dynamic> job;

  /// Whether the job's franchise has areas to place it in. "Not placed" is
  /// only true of a job that COULD be placed: a franchise with no areas, or a
  /// server that has not said which it has, gets the franchise alone — or
  /// every row would be amber and the mark would mean nothing.
  final bool placeable;

  @override
  Widget build(BuildContext context) {
    final (:franchise, :area) = jobWhere(job);
    final muted = Theme.of(context).colorScheme.onSurfaceVariant;

    if (area == null && !placeable) return Text(franchise);

    if (area == null) {
      return Row(
        children: [
          Flexible(
            child: Text('$franchise ·', style: TextStyle(color: muted)),
          ),
          const SizedBox(width: 6),
          const NotPlacedMark(),
        ],
      );
    }

    if (area.toLowerCase() == franchise.toLowerCase()) return Text(area);

    return Text.rich(
      TextSpan(
        children: [
          TextSpan(
            text: '$franchise · ',
            style: TextStyle(color: muted),
          ),
          TextSpan(text: area),
        ],
      ),
    );
  }
}

/// "Not placed": amber, because it asks somebody to look, and outlined rather
/// than filled, because it is an absence and not a status.
class NotPlacedMark extends StatelessWidget {
  const NotPlacedMark({super.key});

  @override
  Widget build(BuildContext context) {
    final caution = ToneColours.of(context, Tone.caution);

    return Container(
      key: const Key('not-placed'),
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 1),
      decoration: BoxDecoration(
        color: caution.fill,
        border: Border.all(color: caution.ink),
        borderRadius: BorderRadius.circular(999),
      ),
      child: Text(
        'Not placed',
        maxLines: 1,
        style: TextStyle(
          fontSize: 11,
          fontWeight: FontWeight.w700,
          color: caution.ink,
        ),
      ),
    );
  }
}
