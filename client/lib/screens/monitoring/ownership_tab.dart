import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../widgets/async_body.dart';

/// The ownership chart — Monitoring's General tab (Jose, 2026-09-22).
///
///   "an Ownership Chart, where basically the Information Displayed on Map is
///    presented in a sort of table fashion"
///
/// The same office the Map draws, as rows. Who, what they do, which desk,
/// which machine, whether they are on, the hours they are expected, where they
/// report, and which plan they sit on.
///
/// ── Columns appear only when the server sent them ────────────────
///
/// `computer_name` needs `monitoring.specs.view`, and `work_days`/`shift` need
/// HR's own
/// `hr.hours.view`, and the endpoint omits the KEY rather than sending null —
/// so a column nobody may see is not drawn at all, instead of being drawn
/// empty. An empty column reads as "nobody has hours set", which is a claim
/// about the office rather than about the reader's permissions.
///
/// That is why the header is built from the rows rather than being a const
/// list: the shape of the table IS the answer to what this person may see.
class OwnershipTab extends StatefulWidget {
  const OwnershipTab({super.key, required this.api, required this.session});

  final ApiClient api;

  /// Read for one thing: whether this person may rename a desk. The grant is
  /// `monitoring.map.manage` — the same one that lets them move the tile on
  /// the Map, because a desk's name is part of the plan either way.
  final Session session;

  /// Matches PcSpecsTab's own poll, and for the same reason: the agent beats
  /// every 60 seconds, so a chart that loaded once and sat there would show a
  /// live-looking Online column that had stopped being about now.
  static const Duration refreshEvery = Duration(seconds: 30);

  static const String deskHint = 'Tap to rename';

  static const String emptyNote =
      'Nobody to show yet. People appear here once they can be seen from your '
      'level of the business.';

  @override
  State<OwnershipTab> createState() => _OwnershipTabState();
}

class _OwnershipTabState extends State<OwnershipTab> {
  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _rows = const [];
  Timer? _refresh;

  @override
  void initState() {
    super.initState();
    _load();
    _refresh = Timer.periodic(OwnershipTab.refreshEvery, (_) => _load());
  }

  @override
  void dispose() {
    _refresh?.cancel();
    super.dispose();
  }

  Future<void> _load() async {
    // A spinner only when there is nothing to keep — see AsyncBody.
    setState(() {
      _loading = _rows.isEmpty;
      _error = null;
    });

    final response = await widget.api.get('/monitoring/ownership');

    if (!mounted) return;

    setState(() {
      _loading = false;

      if (!response.ok) {
        _error = response.message;
        return;
      }

      _rows = (response.data as List<dynamic>).cast<Map<String, dynamic>>();
    });
  }

  @override
  Widget build(BuildContext context) {
    return AsyncBody(
      loading: _loading,
      error: _error,
      onRetry: _load,
      child: _rows.isEmpty
          ? Padding(
              padding: const EdgeInsets.all(24),
              child: Text(
                OwnershipTab.emptyNote,
                style: Theme.of(context).textTheme.bodyMedium,
              ),
            )
          : _table(context),
    );
  }

  Widget _table(BuildContext context) {
    // Asked of the payload, not assumed: these two are withheld per viewer.
    final showPc = _rows.any((r) => r.containsKey('computer_name'));
    final showHours = _rows.any((r) => r.containsKey('work_days'));
    final canRename =
        widget.session.me?.can('monitoring.map.manage') ?? false;

    return Padding(
      padding: const EdgeInsets.all(16),
      child: Card(
        clipBehavior: Clip.antiAlias,
        child: SingleChildScrollView(
          // Eight columns do not fit a laptop, let alone a phone. The TABLE
          // scrolls sideways inside its card rather than the page scrolling,
          // so the rest of the screen stays put.
          scrollDirection: Axis.horizontal,
          child: DataTable(
            columns: [
              const DataColumn(label: Text('User')),
              const DataColumn(label: Text('Role')),
              const DataColumn(label: Text('Desk')),
              if (showPc) const DataColumn(label: Text('PC name')),
              const DataColumn(label: Text('Status')),
              if (showHours) const DataColumn(label: Text('Work days')),
              if (showHours) const DataColumn(label: Text('Shift')),
              const DataColumn(label: Text('Assignment')),
              const DataColumn(label: Text('Office')),
            ],
            rows: [
              for (final row in _rows)
                DataRow(
                  cells: [
                    DataCell(Text(_name(row))),
                    DataCell(_orDash(context, row['role'])),
                    DataCell(_DeskCell(
                      // Keyed by the node so a rename rebuilds the right cell
                      // and an edit in progress does not hop rows on refresh.
                      key: ValueKey('desk-${row['node_id'] ?? row['user']?['id']}'),
                      label: row['desk'] as String?,
                      planId: row['plan_id'] as String?,
                      nodeId: row['node_id'] as String?,
                      canRename: canRename,
                      api: widget.api,
                      onRenamed: (name) => setState(() => row['desk'] = name),
                    )),
                    if (showPc) DataCell(_orDash(context, row['computer_name'])),
                    DataCell(_StatusCell(online: row['online'] == true)),
                    if (showHours) DataCell(_orDash(context, row['work_days'])),
                    if (showHours) DataCell(_orDash(context, row['shift'])),
                    DataCell(_orDash(context, row['assignment'])),
                    DataCell(_orDash(context, row['office'])),
                  ],
                ),
            ],
          ),
        ),
      ),
    );
  }

  static String _name(Map<String, dynamic> row) {
    final user = row['user'];
    return user is Map ? (user['name'] as String? ?? 'Unknown') : 'Unknown';
  }

  /// An em dash, never an empty cell: a blank reads as a rendering fault, and
  /// a dash says "nothing recorded" — which for Desk, Role and Assignment is
  /// a real and actionable answer.
  static Widget _orDash(BuildContext context, Object? value) {
    final text = value?.toString();

    if (text == null || text.isEmpty) {
      return Text(
        '—',
        style: TextStyle(color: Theme.of(context).colorScheme.outlineVariant),
      );
    }

    return Text(text);
  }
}

/// Online, said the way the rest of Monitoring says it.
///
/// A bolt and a struck-through bolt beside the word, not a coloured dot: the
/// same rule PcSpecsTab and the floor plan follow, so a person reading the
/// chart and the map sees one visual language rather than two.
class _StatusCell extends StatelessWidget {
  const _StatusCell({required this.online});

  final bool online;

  @override
  Widget build(BuildContext context) {
    final colour = online
        ? EzBiziTheme.clockIn(context)
        : Theme.of(context).colorScheme.outlineVariant;

    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Icon(
          online ? Icons.flash_on : Icons.flash_off,
          size: 13,
          color: colour,
        ),
        const SizedBox(width: 6),
        // The word as well as the shape — a screenshot pasted into a ticket
        // loses nothing, and neither does anybody reading it in grey.
        Text(online ? 'Online' : 'Offline'),
      ],
    );
  }
}

/// The Desk cell, which renames the desk in place.
///
/// Jose, 2026-09-23: "I should be able to edit the desk name myself." The chart
/// is where somebody NOTICES a desk is called the wrong thing — they are
/// reading down a column of them — so the fix belongs there rather than three
/// clicks away on the Map.
///
/// ── Why it sends only the label ──────────────────────────────────
///
/// `PUT /monitoring/floor-plans/{plan}/nodes/{node}/label` touches that one
/// node and leaves the rest of the layout as stored. The alternative — fetch
/// the plan, change one string, send the document back — would silently
/// overwrite anything somebody moved on the Map in between.
///
/// ── What it does when it cannot ──────────────────────────────────
///
/// A person without `monitoring.map.manage`, or a row whose person sits on no
/// plan, gets the plain dash and no tap target at all. An editable-looking
/// cell that refuses on save is worse than one that never offered.
class _DeskCell extends StatefulWidget {
  const _DeskCell({
    super.key,
    required this.label,
    required this.planId,
    required this.nodeId,
    required this.canRename,
    required this.api,
    required this.onRenamed,
  });

  final String? label;
  final String? planId;
  final String? nodeId;
  final bool canRename;
  final ApiClient api;
  final ValueChanged<String> onRenamed;

  @override
  State<_DeskCell> createState() => _DeskCellState();
}

class _DeskCellState extends State<_DeskCell> {
  bool _editing = false;
  bool _saving = false;
  String? _error;
  late TextEditingController _controller;

  bool get _editable =>
      widget.canRename && widget.planId != null && widget.nodeId != null;

  @override
  void initState() {
    super.initState();
    _controller = TextEditingController(text: widget.label ?? '');
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    final name = _controller.text.trim();

    if (name.isEmpty || name == widget.label) {
      setState(() => _editing = false);
      return;
    }

    setState(() {
      _saving = true;
      _error = null;
    });

    final response = await widget.api.put(
      '/monitoring/floor-plans/${widget.planId}/nodes/${widget.nodeId}/label',
      {'label': name},
    );

    if (!mounted) return;

    setState(() {
      _saving = false;

      if (!response.ok) {
        // Stays in edit mode holding what they typed. Dropping back to the
        // old name on a failed save looks exactly like a save that worked.
        _error = response.message;
        return;
      }

      _editing = false;
    });

    if (response.ok) widget.onRenamed(name);
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    if (!_editing) {
      final text = widget.label?.isNotEmpty == true
          ? Text(widget.label!)
          : Text('—', style: TextStyle(color: theme.colorScheme.outlineVariant));

      if (!_editable) return text;

      return Tooltip(
        message: OwnershipTab.deskHint,
        child: InkWell(
          onTap: () => setState(() {
            _controller.text = widget.label ?? '';
            _editing = true;
          }),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              text,
              const SizedBox(width: 6),
              Icon(
                Icons.edit_outlined,
                size: 13,
                color: theme.colorScheme.outlineVariant,
              ),
            ],
          ),
        ),
      );
    }

    return SizedBox(
      width: 180,
      child: TextField(
        controller: _controller,
        autofocus: true,
        enabled: !_saving,
        maxLength: 60,
        decoration: InputDecoration(
          isDense: true,
          counterText: '',
          errorText: _error,
          // The way out, for somebody who opened this by accident.
          suffixIcon: IconButton(
            icon: const Icon(Icons.close, size: 16),
            tooltip: 'Cancel',
            onPressed: _saving ? null : () => setState(() => _editing = false),
          ),
        ),
        onSubmitted: (_) => _save(),
        // Tapping away saves rather than discarding: somebody who typed a name
        // and clicked the next row meant the name.
        onTapOutside: (_) => _saving ? null : _save(),
      ),
    );
  }
}
