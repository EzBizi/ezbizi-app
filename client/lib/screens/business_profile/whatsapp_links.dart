import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/theme.dart';
import '../../widgets/status_pill.dart';

/// Each listing's "Chat on WhatsApp" link, inline in the listings table (UI
/// queue 164, option 1 + phone 4; Jose, 2026-11-02: "as you recommend").
/// Replaces 163's separate bulk screen: the same reads and writes
/// (`GET /business-profile/whatsapp-links`, `POST .../apply`), held here so
/// the table, the cards and the open listing share one selection.
///
/// The list is read from Google live, so it can take seconds and is loaded
/// after the listings, never in front of them.
class WhatsAppLinks extends ChangeNotifier {
  WhatsAppLinks(this.api);

  final ApiClient api;

  bool loading = false;
  String? error;

  /// Listing id → {current, state} as the server sent it.
  Map<String, Map<String, dynamic>> rows = const {};

  /// The organization's numbers as {number, link}, oldest first.
  List<Map<String, dynamic>> numbers = const [];

  /// The link being written; starts as the server's proposed one.
  String? link;

  final Set<String> picked = {};

  /// After an apply: id → null for set, or the failure message.
  final Map<String, String?> results = {};
  bool busy = false;

  Future<void> load() async {
    loading = true;
    error = null;
    notifyListeners();
    final r = await api.get('/business-profile/whatsapp-links');
    loading = false;
    if (!r.ok) {
      error = r.message;
    } else {
      link ??= r.body['proposed'] as String?;
      numbers = [
        for (final n in (r.body['numbers'] as List? ?? const []))
          if (n is Map) Map<String, dynamic>.from(n),
      ];
      rows = {
        for (final m in (r.body['data'] as List? ?? const []))
          if (m is Map) '${m['id']}': Map<String, dynamic>.from(m),
      };
    }
    notifyListeners();
  }

  /// missing, different, same or unreachable, against the chosen [link];
  /// null when Google has not said (still loading, or not a known listing).
  /// The server's state compares against proposed, so only "unreachable" is
  /// taken as sent.
  String? stateOf(String id) {
    final r = rows[id];
    if (r == null) return null;
    if (r['state'] == 'unreachable') return 'unreachable';
    final current = r['current'] as String?;
    if (current == null || current.isEmpty) return 'missing';
    return current == link ? 'same' : 'different';
  }

  String? currentOf(String id) => rows[id]?['current'] as String?;

  bool tickable(String id) =>
      link != null &&
      (stateOf(id) == 'missing' || stateOf(id) == 'different');

  int count(String state) => rows.keys.where((id) => stateOf(id) == state).length;

  /// How many ticked already have another link that will be replaced.
  int get replacing => picked.where((id) => stateOf(id) == 'different').length;

  List<String> get failed => [
    for (final e in results.entries)
      if (e.value != null) e.key,
  ];

  void toggle(String id) {
    if (!picked.remove(id) && tickable(id)) picked.add(id);
    notifyListeners();
  }

  /// Ticks exactly the rows in [state] that can be ticked.
  void pickState(String state) {
    picked
      ..clear()
      ..addAll(rows.keys.where((id) => stateOf(id) == state && tickable(id)));
    notifyListeners();
  }

  /// Ticks one listing alone: setting it one by one.
  void pickOnly(String id) {
    picked
      ..clear()
      ..addAll([if (tickable(id)) id]);
    notifyListeners();
  }

  void clear() {
    picked.clear();
    notifyListeners();
  }

  void choose(String next) {
    link = next;
    picked.removeWhere((id) => !tickable(id));
    notifyListeners();
  }

  Future<void> apply([List<String>? ids]) async {
    final targets = ids ?? picked.toList();
    if (targets.isEmpty) return;
    busy = true;
    error = null;
    notifyListeners();
    final r = await api.post('/business-profile/whatsapp-links/apply', {
      'targets': targets,
      'link': ?link,
    });
    busy = false;
    if (!r.ok) {
      error = r.message;
    } else {
      for (final m in (r.body['data'] as List? ?? const [])) {
        if (m is! Map) continue;
        final id = '${m['id']}';
        final ok = m['ok'] == true;
        results[id] = ok ? null : (m['message'] as String? ?? 'Not set');
        if (ok && rows[id] != null) {
          rows[id] = {...rows[id]!, 'current': link};
        }
      }
      picked.clear();
    }
    notifyListeners();
  }
}

/// The words and tones for each state, in chip order.
const waStates = <String, (String, Tone)>{
  'missing': ('Missing', Tone.caution),
  'different': ('Different', Tone.caution),
  'same': ('Set', Tone.ok),
  'unreachable': ("Can't reach", Tone.neutral),
};

/// A listing's WhatsApp cell: its tick, then its state (or what the last
/// apply did). Tapping the state ticks this listing alone.
class WhatsAppCell extends StatelessWidget {
  const WhatsAppCell({super.key, required this.links, required this.id});

  final WhatsAppLinks links;
  final String id;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    if (links.rows.isEmpty) {
      return Text(
        links.loading ? '…' : '—',
        style: theme.textTheme.bodySmall,
      );
    }
    final state = links.stateOf(id);
    if (state == null) return Text('—', style: theme.textTheme.bodySmall);

    final Widget label;
    if (links.results.containsKey(id)) {
      final message = links.results[id];
      label = Text(
        message == null ? '✓ Set' : '✗ $message',
        key: ValueKey('gbp-wa-result-$id'),
        maxLines: 1,
        overflow: TextOverflow.ellipsis,
        style: TextStyle(
          color: message == null
              ? ToneColours.of(context, Tone.ok).ink
              : theme.colorScheme.error,
          fontWeight: FontWeight.w600,
        ),
      );
    } else {
      final (word, tone) = waStates[state]!;
      final pill = StatusPill(
        state == 'different' ? 'Different · Replace' : word,
        tone: tone,
      );
      label = links.tickable(id)
          ? InkWell(
              key: ValueKey('gbp-wa-set-$id'),
              onTap: () => links.pickOnly(id),
              child: pill,
            )
          : Tooltip(
              message: state == 'unreachable'
                  ? "Google account isn't connected"
                  : (links.currentOf(id) ?? ''),
              child: pill,
            );
    }

    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        SizedBox(
          width: 32,
          child: Checkbox(
            key: ValueKey('gbp-wa-tick-$id'),
            visualDensity: VisualDensity.compact,
            value: links.picked.contains(id),
            onChanged: links.tickable(id) ? (_) => links.toggle(id) : null,
          ),
        ),
        Flexible(child: label),
      ],
    );
  }
}

/// "WhatsApp missing · 5" and "different · 1": each ticks exactly its rows.
List<Widget> whatsAppPickChips(WhatsAppLinks links) => [
  for (final s in const ['missing', 'different'])
    if (links.count(s) > 0)
      ActionChip(
        key: ValueKey('gbp-wa-pick-$s'),
        avatar: const Icon(Icons.chat_outlined, size: 16),
        label: Text('WhatsApp ${waStates[s]!.$1.toLowerCase()} · ${links.count(s)}'),
        onPressed: () => links.pickState(s),
      ),
];

/// The one action bar over the table, shown while anything is ticked or a
/// failure can be retried: the number to write, then Set on N.
class WhatsAppBar extends StatelessWidget {
  const WhatsAppBar({super.key, required this.links});

  final WhatsAppLinks links;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final n = links.picked.length;
    final failed = links.failed;
    if (n == 0 && failed.isEmpty && links.error == null) {
      return const SizedBox.shrink();
    }
    return Container(
      key: const ValueKey('gbp-wa-bar'),
      margin: const EdgeInsets.fromLTRB(16, 0, 16, 8),
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      decoration: BoxDecoration(
        color: theme.colorScheme.primaryContainer,
        borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
      ),
      child: Wrap(
        spacing: 8,
        runSpacing: 6,
        crossAxisAlignment: WrapCrossAlignment.center,
        children: [
          if (n > 0) Text('$n ticked', style: theme.textTheme.titleSmall),
          if (n > 0 && links.numbers.length > 1)
            for (final num in links.numbers)
              ChoiceChip(
                key: ValueKey('gbp-wa-number-${num['number']}'),
                label: Text('${num['number']}'),
                selected: links.link == '${num['link']}',
                onSelected: links.busy
                    ? null
                    : (_) => links.choose('${num['link']}'),
              ),
          if (links.replacing > 0)
            Text(
              '${links.replacing} will be replaced',
              key: const ValueKey('gbp-wa-replace-warning'),
              style: TextStyle(
                color: ToneColours.of(context, Tone.caution).ink,
                fontWeight: FontWeight.w600,
              ),
            ),
          if (links.error != null)
            Text(
              links.error!,
              style: TextStyle(color: theme.colorScheme.error),
            ),
          if (failed.isNotEmpty)
            TextButton.icon(
              key: const ValueKey('gbp-wa-retry'),
              icon: const Icon(Icons.refresh, size: 16),
              label: const Text('Retry failed'),
              onPressed: links.busy ? null : () => links.apply(failed),
            ),
          if (n > 0) ...[
            TextButton(
              key: const ValueKey('gbp-wa-clear'),
              onPressed: links.busy ? null : links.clear,
              child: const Text('Clear'),
            ),
            FilledButton(
              key: const ValueKey('gbp-wa-apply'),
              onPressed: links.busy || links.link == null ? null : links.apply,
              child: Text(
                'Set WhatsApp on $n listing${n == 1 ? '' : 's'}',
              ),
            ),
          ],
        ],
      ),
    );
  }
}
