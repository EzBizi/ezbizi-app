import '../../widgets/list_search.dart';
import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/theme.dart';
import '../../widgets/status_pill.dart';

/// Another listing a copy can go to, named "Company · Branch".
typedef ListingTarget = ({String id, String label});

/// One listing's answer to a copy: Google took it, or the sentence why not.
class CopyResult {
  const CopyResult(this.id, this.name, this.ok, this.message);

  factory CopyResult.from(Map<String, dynamic> m) => CopyResult(
    '${m['id']}',
    '${m['name'] ?? ''}',
    m['ok'] == true,
    m['message'] as String?,
  );

  final String id;
  final String name;
  final bool ok;
  final String? message;
}

/// Ticks the other listings, with a search and tick-all (design 154 #1, #4).
/// Shared by the media and services copy panels.
class ListingTargets extends StatelessWidget {
  const ListingTargets({
    super.key,
    required this.targets,
    required this.ticked,
    required this.query,
    required this.onQuery,
    required this.onChanged,
    this.trailing,
  });

  final List<ListingTarget> targets;
  final Set<String> ticked;
  final String query;
  final ValueChanged<String> onQuery;
  final ValueChanged<Set<String>> onChanged;

  /// What the row says on its right, e.g. a copy's result.
  final Widget? Function(ListingTarget)? trailing;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final q = query.trim().toLowerCase();
    final shown = [
      for (final t in targets)
        if (q.isEmpty || t.label.toLowerCase().contains(q)) t,
    ];
    final allTicked =
        targets.isNotEmpty && targets.every((t) => ticked.contains(t.id));

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        ListSearch(
          key: const ValueKey('cx-targets-search'),
          hint: 'Search locations',
          onChanged: onQuery,
        ),
        const SizedBox(height: 8),
        CheckboxListTile(
          key: const ValueKey('cx-targets-all'),
          dense: true,
          contentPadding: EdgeInsets.zero,
          controlAffinity: ListTileControlAffinity.leading,
          value: allTicked,
          title: Text(
            'All ${targets.length} other listings',
            style: theme.textTheme.bodyMedium,
          ),
          onChanged: (v) => onChanged(
            v == true ? {for (final t in targets) t.id} : <String>{},
          ),
        ),
        const Divider(height: 1),
        for (final t in shown)
          CheckboxListTile(
            key: ValueKey('cx-target-${t.id}'),
            dense: true,
            contentPadding: EdgeInsets.zero,
            controlAffinity: ListTileControlAffinity.leading,
            value: ticked.contains(t.id),
            title: Text(t.label, style: theme.textTheme.bodyMedium),
            secondary: trailing?.call(t),
            onChanged: (v) => onChanged(
              v == true ? {...ticked, t.id} : ({...ticked}..remove(t.id)),
            ),
          ),
        if (shown.isEmpty)
          Padding(
            padding: const EdgeInsets.all(12),
            child: Text(
              targets.isEmpty
                  ? 'There is no other listing to copy to.'
                  : 'No listing matches "$query".',
              style: theme.textTheme.bodySmall,
            ),
          ),
      ],
    );
  }
}

/// The pill a copy's result shows beside its listing.
Widget copyResultPill(CopyResult r) => Tooltip(
  message: r.message ?? '',
  child: StatusPill(
    r.ok ? 'Copied' : (r.message ?? 'Failed'),
    tone: r.ok ? Tone.ok : Tone.bad,
  ),
);

/// The inline sentence a refused write shows (409 while Google writes are
/// off, 502 when Google did not answer).
Widget refusedNote(BuildContext context, String message) {
  final colours = ToneColours.of(context, Tone.caution);
  return Container(
    key: const ValueKey('cx-refused'),
    margin: const EdgeInsets.only(bottom: 12),
    padding: const EdgeInsets.all(10),
    decoration: BoxDecoration(
      color: colours.fill,
      borderRadius: BorderRadius.circular(8),
    ),
    child: Text(
      message,
      style: Theme.of(
        context,
      ).textTheme.bodySmall?.copyWith(color: colours.ink),
    ),
  );
}

/// Sends a copy and turns the answer into per-listing results or a refusal.
Future<({List<CopyResult>? results, String? refused})> sendCopy(
  ApiClient api,
  String path,
  Map<String, dynamic> body,
) async {
  final r = await api.post(path, body);
  if (!r.ok) return (results: null, refused: r.message);
  return (
    results: [
      for (final m in (r.data as List? ?? const []).whereType<Map>())
        CopyResult.from(Map<String, dynamic>.from(m)),
    ],
    refused: null,
  );
}
