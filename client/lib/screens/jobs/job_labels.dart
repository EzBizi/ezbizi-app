import 'package:flutter/material.dart';

import '../../widgets/colour_field.dart';
import '../../widgets/tag.dart';

/// The words an organization's jobs carry (2026-10-20): types, sub-statuses
/// and tags, from the list's first page (`meta.labels`). They are what the
/// pickers offer; a new word can still be typed.
class JobLabels {
  const JobLabels({
    this.jobTypes = const [],
    this.subStatuses = const [],
    this.tags = const [],
  });

  final List<String> jobTypes;
  final List<String> subStatuses;
  final List<String> tags;

  static JobLabels? of(Object? raw) {
    if (raw is! Map) return null;

    List<String> words(Object? list) => [
      if (list is List)
        for (final w in list)
          if (w is String && w.trim().isNotEmpty) w,
    ];

    return JobLabels(
      jobTypes: words(raw['job_types']),
      subStatuses: words(raw['sub_statuses']),
      tags: words(raw['tags']),
    );
  }
}

/// A job's tags as written, emoji included. Null and blanks are no tags.
List<String> jobTags(Map<String, dynamic>? job) => [
  if (job?['tags'] is List)
    for (final t in job!['tags'] as List)
      if (t is String && t.trim().isNotEmpty) t,
];

/// A job's type, or null when it has none.
String? jobType(Map<String, dynamic>? job) {
  final type = job?['job_type'];
  return type is String && type.trim().isNotEmpty ? type : null;
}

/// Whether a status is one a sub-status explains: cancelled or on hold.
///
/// Read from the value's words, as [toneForJobStatus] reads its colour,
/// because statuses are the organization's own (`Canceled`, `cancelled`,
/// `On hold`).
bool statusTakesSubStatus(String status) {
  final v = status.toLowerCase();
  return v.contains('cancel') || v.contains('hold');
}

/// The type after a job's number: a small outlined label.
class JobTypeLabel extends StatelessWidget {
  const JobTypeLabel(this.type, {super.key});

  final String type;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 1),
      decoration: BoxDecoration(
        border: Border.all(color: scheme.outline),
        borderRadius: BorderRadius.circular(6),
      ),
      child: Text(
        type,
        maxLines: 1,
        overflow: TextOverflow.ellipsis,
        style: TextStyle(
          fontSize: 10.5,
          fontWeight: FontWeight.w700,
          color: scheme.onSurfaceVariant,
        ),
      ),
    );
  }
}

/// The first [shown] tags, then "+N"; hovering shows every one.
/// Each of a job's tags in its Workiz colour, where the server knows it
/// (`tag_colours`, Jose 2026-11-10); a tag with none stays grey.
Map<String, Color> jobTagColours(Map? job) => {
  for (final MapEntry(:key, :value)
      in ((job?['tag_colours'] as Map?) ?? const {}).entries)
    '$key': ?ColourField.parse('$value'),
};

class JobTagsSummary extends StatelessWidget {
  const JobTagsSummary(
    this.tags, {
    super.key,
    this.shown = 2,
    this.colours = const {},
  });

  final List<String> tags;
  final int shown;
  final Map<String, Color> colours;

  @override
  Widget build(BuildContext context) {
    if (tags.isEmpty) return const SizedBox.shrink();

    final muted = Theme.of(context).colorScheme.onSurfaceVariant;
    final rest = tags.length - shown;

    return Tooltip(
      message: tags.join('\n'),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          for (final t in tags.take(shown))
            Flexible(child: Tag(t, colour: colours[t])),
          if (rest > 0)
            Padding(
              padding: const EdgeInsets.only(left: 6),
              child: Text(
                '+$rest',
                key: const Key('job-tags-more'),
                style: TextStyle(
                  fontSize: 11,
                  fontWeight: FontWeight.w600,
                  color: muted,
                ),
              ),
            ),
        ],
      ),
    );
  }
}
