import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../core/api_client.dart';
import '../core/session.dart';
import '../widgets/search_picker.dart';

/// Sales pipeline (Jose, 2026-10-14; layouts D1 + M1 in
/// docs/previews/sales-pipeline-layouts.html). Every job "done pending
/// approval" is a deal; the salesperson sets stage, urgency, follow-up, notes.
class SalesScreen extends StatefulWidget {
  const SalesScreen({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<SalesScreen> createState() => _SalesScreenState();
}

const salesStages = {
  'follow_up': 'Follow up',
  'call_back': 'Call back',
  'sold': 'Sold',
  'ghosted': 'Ghosted',
  'disqualified': 'Disqualified',
};

const salesUrgencies = {
  'asap': 'ASAP',
  'next_week': 'Next week',
  'next_month': 'Next month',
  'later': '60+ days',
};

const _open = {'follow_up', 'call_back'};

class _SalesScreenState extends State<SalesScreen> {
  List<Map<String, dynamic>> _rows = const [];
  bool _loading = true;
  String? _error;
  String _query = '';
  // 'open' | 'due' | a stage key | 'all'
  String _filter = 'open';

  bool get _canEdit => widget.session.me?.can('sales.manage') == true;

  String get _today => DateTime.now().toIso8601String().substring(0, 10);

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final response = await widget.api.get('/sales/deals');
    if (!mounted) return;
    setState(() {
      _loading = false;
      _error = response.ok ? null : response.message;
      _rows = ((response.body['data'] as List?) ?? const [])
          .map((r) => (r as Map).cast<String, dynamic>())
          .toList();
    });
  }

  Future<void> _save(Map<String, dynamic> row, Map<String, dynamic> change) async {
    final before = Map<String, dynamic>.of(row);
    setState(() => row.addAll(change));
    final response = await widget.api.put('/sales/deals/${row['job_id']}', change);
    if (!mounted) return;
    if (!response.ok) {
      setState(() => row
        ..clear()
        ..addAll(before));
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(response.message)));
    }
  }

  bool _due(Map<String, dynamic> r) {
    final next = r['next_follow_up'] as String?;
    return _open.contains(r['stage']) && next != null && next.compareTo(_today) <= 0;
  }

  List<Map<String, dynamic>> get _visible {
    final q = _query.toLowerCase();
    final rows = _rows.where((r) {
      final ok = switch (_filter) {
        'all' => true,
        'open' => _open.contains(r['stage']),
        'due' => _due(r),
        _ => r['stage'] == _filter,
      };
      if (!ok) return false;
      if (q.isEmpty) return true;
      return [r['name'], r['phone'], r['job_number'], r['state']]
          .any((v) => '${v ?? ''}'.toLowerCase().contains(q));
    }).toList();
    // Follow-ups due first, then by date; undated last.
    rows.sort((a, b) => ((a['next_follow_up'] as String?) ?? '9999')
        .compareTo((b['next_follow_up'] as String?) ?? '9999'));
    return rows;
  }

  static String money(int? minor) {
    if (minor == null) return '—';
    final whole = (minor / 100).round().toString();
    return '\$${whole.replaceAllMapped(RegExp(r'\B(?=(\d{3})+(?!\d))'), (_) => ',')}';
  }

  int _sum(Iterable<Map<String, dynamic>> rows) =>
      rows.fold(0, (t, r) => t + ((r['quote_minor'] as int?) ?? 0));

  @override
  Widget build(BuildContext context) {
    if (_loading) return const Center(child: CircularProgressIndicator());
    if (_error != null) return Center(child: Text(_error!));
    return LayoutBuilder(
      builder: (context, c) => c.maxWidth >= 900 ? _desktop(context) : _mobile(context),
    );
  }

  // ── shared pieces ────────────────────────────────────────────

  Widget _kpis(BuildContext context, {required bool compact}) {
    final month = _today.substring(0, 7);
    final open = _rows.where((r) => _open.contains(r['stage']));
    final sold = _rows.where((r) => r['stage'] == 'sold');
    final closed = _rows.where((r) => !_open.contains(r['stage'])).length;
    final tiles = <(String, String)>[
      (money(_sum(open)), 'Total pipeline'),
      (money(_sum(sold.where((r) => '${r['sold_at'] ?? ''}'.startsWith(month)))), 'Sold this month'),
      (closed == 0 ? '—' : '${(sold.length * 100 / closed).round()}%', 'Win rate'),
      ('${open.length}', 'Active deals'),
      ('${_rows.where(_due).length}', 'Follow-ups due'),
    ];
    final shown = compact ? tiles.sublist(0, 2) : tiles;
    return Row(
      children: [
        for (final (value, label) in shown)
          Expanded(
            child: Card(
              child: Padding(
                padding: const EdgeInsets.all(12),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(value, style: Theme.of(context).textTheme.titleLarge),
                    Text(label, style: Theme.of(context).textTheme.bodySmall),
                  ],
                ),
              ),
            ),
          ),
      ],
    );
  }

  Widget _filters() {
    final options = {
      'open': 'Open',
      'due': 'Due today (${_rows.where(_due).length})',
      ...salesStages,
      'all': 'All',
    };
    return SingleChildScrollView(
      scrollDirection: Axis.horizontal,
      child: Row(
        children: [
          for (final e in options.entries)
            Padding(
              padding: const EdgeInsets.only(right: 6),
              child: ChoiceChip(
                showCheckmark: false,
                label: Text(e.value),
                selected: _filter == e.key,
                onSelected: (_) => setState(() => _filter = e.key),
              ),
            ),
        ],
      ),
    );
  }

  Widget _search() => TextField(
        decoration: const InputDecoration(
          prefixIcon: Icon(Icons.search),
          hintText: 'Search name, phone, job #',
          isDense: true,
        ),
        onChanged: (v) => setState(() => _query = v),
      );

  // A search-bar picker, not a popup menu - Jose's rule (2026-09-17).
  Widget _stage(Map<String, dynamic> r) => SizedBox(
        width: 150,
        child: SearchPickerField<String>(
          key: ValueKey('stage-${r['job_id']}'),
          value: r['stage'] as String,
          isDense: true,
          options: [for (final e in salesStages.entries) SearchOption(value: e.key, label: e.value)],
          onChanged: _canEdit ? (v) => v == null ? null : _save(r, {'stage': v}) : null,
        ),
      );

  Widget _urgency(Map<String, dynamic> r) => SizedBox(
        width: 130,
        child: SearchPickerField<String>(
          key: ValueKey('urgency-${r['job_id']}'),
          value: r['urgency'] as String?,
          hint: 'Urgency',
          isDense: true,
          allowClear: true,
          options: [for (final e in salesUrgencies.entries) SearchOption(value: e.key, label: e.value)],
          onChanged: _canEdit ? (v) => _save(r, {'urgency': v}) : null,
        ),
      );

  Widget _followUp(BuildContext context, Map<String, dynamic> r) {
    final next = r['next_follow_up'] as String?;
    return TextButton(
      onPressed: !_canEdit
          ? null
          : () async {
              final now = DateTime.now();
              final picked = await showDatePicker(
                context: context,
                initialDate: next != null ? DateTime.parse(next) : now,
                firstDate: now.subtract(const Duration(days: 365)),
                lastDate: now.add(const Duration(days: 730)),
              );
              if (picked != null) {
                _save(r, {'next_follow_up': picked.toIso8601String().substring(0, 10)});
              }
            },
      child: Text(
        next == null ? 'Set date' : (_due(r) ? 'Due $next' : next),
        style: _due(r) ? TextStyle(color: Theme.of(context).colorScheme.error) : null,
      ),
    );
  }

  Widget _notes(Map<String, dynamic> r) => TextFormField(
        key: ValueKey('notes-${r['job_id']}'),
        initialValue: r['notes'] as String? ?? '',
        enabled: _canEdit,
        maxLines: null,
        decoration: const InputDecoration(hintText: 'Add a note…', isDense: true, border: InputBorder.none),
        // Saved when the field loses focus (see _saveDraft).
        onTapOutside: (_) => FocusManager.instance.primaryFocus?.unfocus(),
        onChanged: (v) => r['_draft'] = v,
      );

  Future<void> _saveDraft(Map<String, dynamic> r) async {
    final draft = r.remove('_draft');
    if (draft is String && draft != (r['notes'] ?? '')) await _save(r, {'notes': draft});
  }

  // ── D1: the spreadsheet, made live ───────────────────────────

  Widget _desktop(BuildContext context) {
    final rows = _visible;
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        _kpis(context, compact: false),
        const SizedBox(height: 12),
        Row(children: [SizedBox(width: 320, child: _search()), const SizedBox(width: 12), Expanded(child: _filters())]),
        const SizedBox(height: 8),
        if (rows.isEmpty)
          const Padding(
            padding: EdgeInsets.all(32),
            child: Center(child: Text('No deals here. Jobs marked "Done pending approval" appear automatically.')),
          )
        else
          SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            child: DataTable(
              columnSpacing: 16,
              dataRowMaxHeight: double.infinity,
              columns: const [
                DataColumn(label: Text('Urgency')),
                DataColumn(label: Text('Stage')),
                DataColumn(label: Text('Lead date')),
                DataColumn(label: Text('Name')),
                DataColumn(label: Text('Phone')),
                DataColumn(label: Text('State')),
                DataColumn(label: Text('Job #')),
                DataColumn(label: Text('Quote'), numeric: true),
                DataColumn(label: Text('Next follow-up')),
                DataColumn(label: Text('Notes')),
              ],
              rows: [
                for (final r in rows)
                  DataRow(cells: [
                    DataCell(_urgency(r)),
                    DataCell(_stage(r)),
                    DataCell(Text(r['lead_date'] as String? ?? '—')),
                    DataCell(Text(r['name'] as String? ?? '—')),
                    DataCell(Text(r['phone'] as String? ?? '—')),
                    DataCell(Text(r['state'] as String? ?? '—')),
                    DataCell(Text('${r['job_number'] ?? '—'}')),
                    DataCell(Text(money(r['quote_minor'] as int?))),
                    DataCell(_followUp(context, r)),
                    DataCell(
                      SizedBox(
                        width: 280,
                        child: Focus(
                          onFocusChange: (has) => has ? null : _saveDraft(r),
                          child: _notes(r),
                        ),
                      ),
                    ),
                  ]),
              ],
            ),
          ),
      ],
    );
  }

  // ── M1: today's calls ────────────────────────────────────────

  Widget _mobile(BuildContext context) {
    final rows = _visible;
    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(
        padding: const EdgeInsets.all(12),
        children: [
          _kpis(context, compact: true),
          const SizedBox(height: 8),
          _search(),
          const SizedBox(height: 8),
          _filters(),
          const SizedBox(height: 8),
          if (rows.isEmpty) const Padding(padding: EdgeInsets.all(24), child: Center(child: Text('No deals here.'))),
          for (final r in rows) _card(context, r),
        ],
      ),
    );
  }

  Widget _card(BuildContext context, Map<String, dynamic> r) {
    final phone = r['phone'] as String?;
    final theme = Theme.of(context);
    return Card(
      child: ExpansionTile(
        key: ValueKey('deal-${r['job_id']}'),
        title: Row(
          children: [
            Expanded(child: Text(r['name'] as String? ?? '—', style: theme.textTheme.titleMedium)),
            Text(money(r['quote_minor'] as int?), style: theme.textTheme.titleMedium),
          ],
        ),
        subtitle: Text(
          [
            salesStages[r['stage']],
            if (r['urgency'] != null) salesUrgencies[r['urgency']],
            r['state'],
            r['job_number'],
            if (_due(r)) 'due',
          ].whereType<Object>().join(' · '),
        ),
        childrenPadding: const EdgeInsets.fromLTRB(16, 0, 16, 12),
        children: [
          Row(
            children: [
              Expanded(
                child: OutlinedButton.icon(
                  icon: const Icon(Icons.call_outlined),
                  label: const Text('Call'),
                  onPressed: phone == null ? null : () => launchUrl(Uri.parse('tel:$phone')),
                ),
              ),
              const SizedBox(width: 8),
              Expanded(
                child: OutlinedButton.icon(
                  icon: const Icon(Icons.sms_outlined),
                  label: const Text('Text'),
                  onPressed: phone == null ? null : () => launchUrl(Uri.parse('sms:$phone')),
                ),
              ),
            ],
          ),
          Wrap(
            crossAxisAlignment: WrapCrossAlignment.center,
            spacing: 12,
            children: [_stage(r), _urgency(r), _followUp(context, r)],
          ),
          Focus(onFocusChange: (has) => has ? null : _saveDraft(r), child: _notes(r)),
        ],
      ),
    );
  }
}
