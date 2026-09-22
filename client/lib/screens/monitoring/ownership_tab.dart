import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/api_client.dart';
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
/// `computer_name` needs `monitoring.specs.view` and `schedule` needs HR's own
/// `hr.hours.view`, and the endpoint omits the KEY rather than sending null —
/// so a column nobody may see is not drawn at all, instead of being drawn
/// empty. An empty column reads as "nobody has hours set", which is a claim
/// about the office rather than about the reader's permissions.
///
/// That is why the header is built from the rows rather than being a const
/// list: the shape of the table IS the answer to what this person may see.
class OwnershipTab extends StatefulWidget {
  const OwnershipTab({super.key, required this.api});

  final ApiClient api;

  /// Matches PcSpecsTab's own poll, and for the same reason: the agent beats
  /// every 60 seconds, so a chart that loaded once and sat there would show a
  /// live-looking Online column that had stopped being about now.
  static const Duration refreshEvery = Duration(seconds: 30);

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
    final showHours = _rows.any((r) => r.containsKey('schedule'));

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
              if (showHours) const DataColumn(label: Text('Schedule')),
              const DataColumn(label: Text('Assignment')),
              const DataColumn(label: Text('Office')),
            ],
            rows: [
              for (final row in _rows)
                DataRow(
                  cells: [
                    DataCell(Text(_name(row))),
                    DataCell(_orDash(context, row['role'])),
                    DataCell(_orDash(context, row['desk'])),
                    if (showPc) DataCell(_orDash(context, row['computer_name'])),
                    DataCell(_StatusCell(online: row['online'] == true)),
                    if (showHours) DataCell(_orDash(context, _hours(row['schedule']))),
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

  /// A schedule is a map of weekday to hours; the chart shows the days that
  /// have any, which is what somebody scanning for "who is in on Friday"
  /// actually wants. A plain string (the organization's business hours) is
  /// shown as it stands.
  static String? _hours(Object? schedule) {
    if (schedule is String) return schedule.isEmpty ? null : schedule;
    if (schedule is! Map || schedule.isEmpty) return null;

    return schedule.entries
        .map((e) => '${e.key} ${e.value}')
        .join(', ');
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
