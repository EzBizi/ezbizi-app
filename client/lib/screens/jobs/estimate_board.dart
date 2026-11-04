import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/job_money.dart';
import '../../core/numbers.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../widgets/error_note.dart';
import '../../widgets/status_pill.dart';
import 'estimates_tab.dart' show estimateStatus;

/// Each status's allowed next ones, from the list's `meta.transitions`.
typedef Transitions = Map<String, List<String>>;

Transitions readTransitions(Object? raw) => raw is Map
    ? {
        for (final e in raw.entries)
          if (e.value is List)
            '${e.key}': [for (final s in e.value as List) '$s'],
      }
    : const {};

/// Move one estimate; null when it moved, else the server's reason.
Future<String?> moveEstimate(ApiClient api, String id, String to) async {
  final response = await api.patch('/estimates/$id', {'status': to});
  return response.ok ? null : response.message;
}

/// A phone's "Move to": a sheet listing only the moves the server allows.
Future<String?> moveSheet(
  BuildContext context,
  String customer,
  List<String> allowed,
) => showModalBottomSheet<String>(
  context: context,
  showDragHandle: true,
  builder: (context) => SafeArea(
    child: Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(24, 0, 24, 8),
          child: Text(
            'Move $customer to',
            style: Theme.of(context).textTheme.titleSmall,
          ),
        ),
        if (allowed.isEmpty)
          const ListTile(title: Text('It can\'t move from here.')),
        for (final s in allowed)
          ListTile(
            key: ValueKey('estimate-move-$s'),
            leading: ToneDot(estimateStatus(s).$2),
            title: Text(estimateStatus(s).$1),
            onTap: () => Navigator.of(context).pop(s),
          ),
        const SizedBox(height: 8),
      ],
    ),
  ),
);

/// Jobs › Estimates as a board (design 172, option 1; Jose 2026-11-03, "as u
/// recommend"): a column per status, a card dragged between them, the columns
/// it may not go to faded, Archived a shut amber strip that still takes drops.
class EstimateBoard extends StatefulWidget {
  const EstimateBoard({
    super.key,
    required this.api,
    required this.me,
    required this.search,
    required this.counts,
    required this.transitions,
    required this.generation,
    required this.selectedId,
    required this.onOpen,
    required this.onMoved,
  });

  final ApiClient api;
  final Me me;
  final String? search;
  final Map<String, int> counts;
  final Transitions transitions;

  /// Bumped by the list each time it reloads: the board reloads with it.
  final int generation;
  final String? selectedId;
  final ValueChanged<String> onOpen;
  final VoidCallback onMoved;

  static const statuses = ['draft', 'sent', 'accepted', 'declined', 'archived'];
  static const perColumn = 25;

  @override
  State<EstimateBoard> createState() => _EstimateBoardState();
}

class _Column {
  List<Map<String, dynamic>> rows = const [];
  int page = 0;
  int lastPage = 1;
  bool fetching = false;
  String? error;
}

class _EstimateBoardState extends State<EstimateBoard> {
  final _columns = {for (final s in EstimateBoard.statuses) s: _Column()};
  bool _archivedOpen = false;

  /// The status of the card being dragged, so the others can fade.
  String? _dragging;

  @override
  void initState() {
    super.initState();
    _reloadAll();
  }

  @override
  void didUpdateWidget(EstimateBoard old) {
    super.didUpdateWidget(old);
    if (old.generation != widget.generation || old.search != widget.search) {
      _reloadAll();
    }
  }

  void _reloadAll() {
    for (final s in EstimateBoard.statuses) {
      // ponytail: the shut Archived column loads only when opened.
      if (s != 'archived' || _archivedOpen) _load(s);
    }
  }

  Future<void> _load(String status, {bool more = false}) async {
    final c = _columns[status]!;
    if (more && c.fetching) return;
    final page = more ? c.page + 1 : 1;
    setState(() {
      c.fetching = true;
      c.error = null;
    });

    final response = await widget.api.get('/estimates', {
      'status': status,
      'page': '$page',
      'per_page': '${EstimateBoard.perColumn}',
      'search': ?widget.search,
    });
    if (!mounted) return;

    setState(() {
      c.fetching = false;
      if (!response.ok) {
        c.error = response.message;
        return;
      }
      final rows = List<Map<String, dynamic>>.from(response.data as List);
      c.rows = more ? [...c.rows, ...rows] : rows;
      c.page = page;
      final meta = response.body['meta'] as Map<String, dynamic>?;
      c.lastPage = meta?['last_page'] as int? ?? 1;
    });
  }

  bool _allowed(String from, String to) =>
      widget.transitions[from]?.contains(to) ?? false;

  Future<void> _drop(Map<String, dynamic> row, String to) async {
    final reason = await moveEstimate(widget.api, '${row['id']}', to);
    if (!mounted) return;
    if (reason != null) {
      // The card was never taken out of its column, so it is back already.
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(reason)));
      return;
    }
    widget.onMoved();
  }

  @override
  Widget build(BuildContext context) => Padding(
    key: const ValueKey('estimate-board'),
    padding: const EdgeInsets.fromLTRB(24, 4, 24, 24),
    child: Row(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        for (final s in EstimateBoard.statuses.take(4)) ...[
          Expanded(child: _target(s, _column(context, s))),
          const SizedBox(width: 10),
        ],
        if (_archivedOpen)
          Expanded(child: _target('archived', _column(context, 'archived')))
        else
          SizedBox(width: 46, child: _target('archived', _shut(context))),
      ],
    ),
  );

  /// A column as somewhere to drop: faded while a card that may not go there
  /// is held, outlined while one that may is over it.
  Widget _target(String status, Widget child) =>
      DragTarget<Map<String, dynamic>>(
        onWillAcceptWithDetails: (d) => _allowed('${d.data['status']}', status),
        onAcceptWithDetails: (d) => _drop(d.data, status),
        builder: (context, over, _) {
          final scheme = Theme.of(context).colorScheme;
          final refused =
              _dragging != null &&
              _dragging != status &&
              !_allowed(_dragging!, status);
          return AnimatedOpacity(
            key: ValueKey(
              'estimate-column-$status${refused ? '-refused' : ''}',
            ),
            duration: const Duration(milliseconds: 120),
            opacity: refused ? 0.4 : 1,
            child: DecoratedBox(
              position: DecorationPosition.foreground,
              decoration: BoxDecoration(
                borderRadius: BorderRadius.circular(10),
                border: over.isNotEmpty
                    ? Border.all(color: scheme.primary, width: 2)
                    : null,
              ),
              child: child,
            ),
          );
        },
      );

  Widget _shut(BuildContext context) {
    final tone = ToneColours.of(context, Tone.caution);
    return Material(
      color: tone.fill,
      borderRadius: BorderRadius.circular(10),
      child: InkWell(
        key: const ValueKey('estimate-archived-open'),
        borderRadius: BorderRadius.circular(10),
        onTap: () {
          setState(() => _archivedOpen = true);
          _load('archived');
        },
        child: Padding(
          padding: const EdgeInsets.symmetric(vertical: 12),
          child: RotatedBox(
            quarterTurns: 1,
            child: Text(
              '▸ Archived · ${formatCount(widget.counts['archived'] ?? 0)}',
              style: TextStyle(color: tone.ink, fontWeight: FontWeight.w800),
            ),
          ),
        ),
      ),
    );
  }

  Widget _column(BuildContext context, String status) {
    final scheme = Theme.of(context).colorScheme;
    final c = _columns[status]!;
    final (label, tone) = estimateStatus(status);

    return Container(
      padding: const EdgeInsets.all(8),
      decoration: BoxDecoration(
        color: scheme.surfaceContainerLow,
        border: Border.all(color: scheme.outlineVariant),
        borderRadius: BorderRadius.circular(10),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(4, 2, 4, 8),
            child: Row(
              children: [
                ToneDot(tone),
                const SizedBox(width: 6),
                Text(
                  label,
                  style: const TextStyle(fontWeight: FontWeight.w800),
                ),
                const SizedBox(width: 6),
                Text(
                  formatCount(widget.counts[status] ?? 0),
                  style: TextStyle(color: scheme.onSurfaceVariant),
                ),
                const Spacer(),
                if (status == 'archived')
                  IconButton(
                    tooltip: 'Shut',
                    visualDensity: VisualDensity.compact,
                    onPressed: () => setState(() => _archivedOpen = false),
                    icon: const Icon(Icons.chevron_right, size: 18),
                  ),
              ],
            ),
          ),
          if (c.error != null) ErrorNote(c.error!),
          Expanded(
            child: ListView(
              children: [
                for (final row in c.rows)
                  Padding(
                    padding: const EdgeInsets.only(bottom: 6),
                    child: _draggable(context, row, status),
                  ),
                if (c.page < c.lastPage)
                  TextButton(
                    key: ValueKey('estimate-more-$status'),
                    onPressed: c.fetching
                        ? null
                        : () => _load(status, more: true),
                    child: Text(c.fetching ? 'Loading…' : 'Show 25 more'),
                  ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _draggable(
    BuildContext context,
    Map<String, dynamic> row,
    String status,
  ) {
    final card = EstimateCard(
      row: row,
      me: widget.me,
      selected: '${row['id']}' == widget.selectedId,
      onTap: () => widget.onOpen('${row['id']}'),
    );
    return Draggable<Map<String, dynamic>>(
      data: row,
      onDragStarted: () => setState(() => _dragging = status),
      onDragEnd: (_) => setState(() => _dragging = null),
      feedback: SizedBox(
        width: 220,
        child: Transform.rotate(
          angle: -0.035,
          child: Material(
            elevation: 8,
            borderRadius: BorderRadius.circular(10),
            child: card,
          ),
        ),
      ),
      childWhenDragging: Opacity(opacity: 0.3, child: card),
      child: card,
    );
  }
}

/// One estimate on the board: customer, amount, date, job and who made it.
class EstimateCard extends StatelessWidget {
  const EstimateCard({
    super.key,
    required this.row,
    required this.me,
    required this.onTap,
    this.selected = false,
    this.trailing,
  });

  final Map<String, dynamic> row;
  final Me me;
  final VoidCallback onTap;
  final bool selected;

  /// A phone's "Move to".
  final Widget? trailing;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final muted = TextStyle(color: scheme.onSurfaceVariant, fontSize: 12);
    final at = DateTime.tryParse('${row['created_at'] ?? ''}')?.toLocal();
    final job = (row['job'] as Map?)?['number'];

    return Material(
      color: scheme.surface,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(10),
        side: BorderSide(
          color: selected ? scheme.primary : scheme.outlineVariant,
          width: selected ? 2 : 1,
        ),
      ),
      child: InkWell(
        key: ValueKey('estimate-card-${row['id']}'),
        borderRadius: BorderRadius.circular(10),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(10, 8, 10, 8),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Row(
                children: [
                  Expanded(
                    child: Text(
                      '${(row['customer'] as Map?)?['name'] ?? ''}',
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(fontWeight: FontWeight.w700),
                    ),
                  ),
                  Text(
                    formatMoney(
                      (row['total_minor'] as num?)?.toInt() ?? 0,
                      currency: '${row['currency'] ?? 'USD'}',
                      cents: false,
                    ),
                    style: const TextStyle(fontWeight: FontWeight.w800),
                  ),
                ],
              ),
              const SizedBox(height: 2),
              Row(
                children: [
                  Expanded(
                    child: Text(
                      [
                        if (at != null) me.display.formatDate(at),
                        job == null ? 'No job' : 'Job $job',
                      ].join(' · '),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: muted,
                    ),
                  ),
                  ?trailing,
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}
