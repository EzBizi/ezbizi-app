import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../widgets/error_note.dart';

/// Thumbtack lead spend per category and branch (Jose, 2026-10-13: "add the
/// Thumbtack financials as well"). Only leads EzBizi received: Thumbtack
/// cannot list the rest, so this is not the whole Thumbtack bill.
class ThumbtackReport extends StatefulWidget {
  const ThumbtackReport({super.key, required this.api, this.now});

  final ApiClient api;
  final DateTime Function()? now;

  @override
  State<ThumbtackReport> createState() => _ThumbtackReportState();
}

class _ThumbtackReportState extends State<ThumbtackReport> {
  String _period = 'last_month';
  String? _category;
  String? _branch;
  String? _state;
  Map<String, dynamic>? _data;
  String? _error;

  static const _periods = {
    'this_month': 'This month',
    'last_month': 'Last month',
    'last_12': 'Last 12 months',
  };

  (DateTime, DateTime) _range() {
    final now = (widget.now ?? DateTime.now)();
    final today = DateTime(now.year, now.month, now.day);

    return switch (_period) {
      'this_month' => (DateTime(today.year, today.month), today),
      'last_12' => (DateTime(today.year - 1, today.month, today.day), today),
      _ => (
        DateTime(today.year, today.month - 1),
        DateTime(today.year, today.month, 0),
      ),
    };
  }

  static String _day(DateTime d) =>
      '${d.year}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final (from, to) = _range();
    final response = await widget.api.get('/reports/thumbtack', {
      'from': _day(from),
      'to': _day(to),
      'category': ?_category,
      'branch': ?_branch,
      'state': ?_state,
    });
    if (!mounted) return;
    setState(() {
      if (response.ok) {
        _data = (response.data as Map).cast<String, dynamic>();
        _error = null;
      } else {
        _error = response.message;
      }
    });
  }

  static String _money(Object? minor) {
    if (minor is! num) return '—';
    final cents = minor.round();
    final digits = (cents ~/ 100).toString().replaceAllMapped(
      RegExp(r'\B(?=(\d{3})+(?!\d))'),
      (_) => ',',
    );

    return '\$$digits.${(cents % 100).toString().padLeft(2, '0')}';
  }

  Widget _filter(
    String label,
    String? value,
    Map<String, String> options,
    ValueChanged<String?> pick,
    Key key,
  ) => DropdownMenu<String?>(
    key: key,
    enableFilter: true,
    requestFocusOnTap: true,
    label: Text(label),
    initialSelection: value,
    onSelected: (v) {
      pick(v);
      _load();
    },
    dropdownMenuEntries: [
      const DropdownMenuEntry(value: null, label: 'All'),
      for (final o in options.entries)
        DropdownMenuEntry(value: o.key, label: o.value),
    ],
  );

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    final data = _data;
    final rows = [
      for (final r in (data?['rows'] as List? ?? const []))
        (r as Map).cast<String, dynamic>(),
    ];
    int total(String key) =>
        rows.fold<int>(0, (sum, r) => sum + ((r[key] as num?) ?? 0).toInt());
    final spend = total('spend_minor');
    final charged = total('charged');
    final booked = total('booked');

    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Wrap(
          spacing: 6,
          runSpacing: 8,
          children: [
            for (final p in _periods.entries)
              ChoiceChip(
                key: ValueKey('thumbtack-${p.key}'),
                label: Text(p.value),
                selected: _period == p.key,
                showCheckmark: false,
                onSelected: (_) {
                  setState(() => _period = p.key);
                  _load();
                },
              ),
          ],
        ),
        const SizedBox(height: 12),
        Wrap(
          spacing: 12,
          runSpacing: 12,
          children: [
            _filter(
              'Category',
              _category,
              {
                for (final c in (data?['categories'] as List? ?? const []))
                  '$c': '$c',
              },
              (v) => _category = v,
              const ValueKey('thumbtack-category'),
            ),
            _filter(
              'State',
              _state,
              {
                for (final s in (data?['states'] as List? ?? const []))
                  '$s': '$s',
              },
              (v) => _state = v,
              const ValueKey('thumbtack-state'),
            ),
            _filter(
              'Branch',
              _branch,
              {
                for (final b in (data?['branches'] as List? ?? const []))
                  '${b['id']}': '${b['name']}',
                'none': 'Unassigned',
              },
              (v) => _branch = v,
              const ValueKey('thumbtack-branch'),
            ),
          ],
        ),
        const SizedBox(height: 12),
        if (_error != null) ErrorNote(_error!),
        if (data == null && _error == null)
          const Center(child: CircularProgressIndicator())
        else if (rows.isEmpty)
          Text('No Thumbtack leads in these dates.', style: muted)
        else ...[
          Text(
            '${_money(spend)} spent · $charged charged leads · '
            '${charged > 0 ? _money(spend ~/ charged) : '—'} per lead · '
            '$booked booked',
            key: const ValueKey('thumbtack-totals'),
            style: theme.textTheme.titleMedium,
          ),
          const SizedBox(height: 8),
          SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            child: DataTable(
              columns: const [
                DataColumn(label: Text('Category')),
                DataColumn(label: Text('State')),
                DataColumn(label: Text('Branch')),
                DataColumn(label: Text('Leads'), numeric: true),
                DataColumn(label: Text('Refunded'), numeric: true),
                DataColumn(label: Text('Spend'), numeric: true),
                DataColumn(label: Text('Cost per lead'), numeric: true),
                DataColumn(label: Text('Booked'), numeric: true),
                DataColumn(label: Text('Cost per booked'), numeric: true),
              ],
              rows: [
                for (final r in rows)
                  DataRow(
                    cells: [
                      DataCell(Text('${r['category'] ?? 'No category'}')),
                      DataCell(Text('${r['state'] ?? '—'}')),
                      DataCell(Text('${r['branch'] ?? 'Unassigned'}')),
                      DataCell(Text('${r['leads']}')),
                      DataCell(Text('${r['credited']}')),
                      DataCell(Text(_money(r['spend_minor']))),
                      DataCell(Text(_money(r['cost_per_lead_minor']))),
                      DataCell(Text('${r['booked']}')),
                      DataCell(Text(_money(r['cost_per_booked_minor']))),
                    ],
                  ),
              ],
            ),
          ),
        ],
        const SizedBox(height: 12),
        Text(
          'Only the Thumbtack leads EzBizi received are here: Thumbtack '
          'cannot list the others, so this is not the whole Thumbtack bill. '
          'Spend counts charged leads; refunded (credited) ones are left out. '
          'Booked means the lead was matched to a job.',
          style: muted,
        ),
      ],
    );
  }
}
