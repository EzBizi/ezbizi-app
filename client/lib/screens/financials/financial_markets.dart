import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/branch_options.dart';
import '../../core/session.dart';
import '../../widgets/list_search.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/side_panel.dart';
import 'financial_cells.dart';

String _money(num cents) =>
    formatCell(const {'type': 'currency', 'options': {}}, cents / 100);

/// The periods the Markets tab offers, as from/to dates from [today].
Map<String, (DateTime, DateTime)> marketPeriods(DateTime today) {
  final day = DateTime(today.year, today.month, today.day);
  final monday = day.subtract(Duration(days: day.weekday - 1));
  return {
    'Last week': (
      monday.subtract(const Duration(days: 7)),
      monday.subtract(const Duration(days: 1)),
    ),
    'Last 4 weeks': (
      monday.subtract(const Duration(days: 28)),
      monday.subtract(const Duration(days: 1)),
    ),
    'This month': (DateTime(day.year, day.month), day),
    'Last 3 months': (DateTime(day.year, day.month - 3, day.day), day),
  };
}

String _ymd(DateTime d) =>
    '${d.year.toString().padLeft(4, '0')}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

/// Financials → Markets (Shoval, 2026-11-20): QuickBooks expenses per market
/// (branch) by week and category, each market's closing rate and cost per
/// closed job. "Match markets" says which QuickBooks Class or Location is
/// which branch.
class FinancialMarketsTab extends StatefulWidget {
  const FinancialMarketsTab({
    super.key,
    required this.api,
    required this.me,
    this.today,
  });

  final ApiClient api;
  final Me me;

  /// Fixed in tests; now otherwise.
  final DateTime? today;

  @override
  State<FinancialMarketsTab> createState() => _FinancialMarketsTabState();
}

class _FinancialMarketsTabState extends State<FinancialMarketsTab> {
  String _period = 'Last 4 weeks';
  Map? _data;
  String? _error;
  final _search = TextEditingController();
  String _q = '';

  bool get _canEdit => widget.me.can('financials.edit');

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    final (from, to) = marketPeriods(widget.today ?? DateTime.now())[_period]!;
    final r = await widget.api.get('/financials/markets', {
      'from': _ymd(from),
      'to': _ymd(to),
    });
    if (!mounted) return;
    setState(() {
      _data = r.ok ? r.body['data'] as Map : null;
      _error = r.ok ? null : r.message;
    });
  }

  Future<void> _match() async {
    final changed = await showSidePanel<bool>(
      context,
      builder: (_) =>
          _MarketMapsPanel(api: widget.api, me: widget.me, canEdit: _canEdit),
    );
    if (changed == true) await _load();
  }

  @override
  Widget build(BuildContext context) {
    final data = _data;
    final markets = [
      for (final m in (data?['markets'] as List? ?? const []).cast<Map>())
        if (_q.isEmpty ||
            '${m['name']}'.toLowerCase().contains(_q.toLowerCase()))
          m,
    ];
    final narrow = MediaQuery.sizeOf(context).width < 600;
    final controls = [
      ListSearch(
        key: const Key('financials-markets-search'),
        controller: _search,
        onChanged: (t) => setState(() => _q = t.trim()),
        hint: 'Market',
      ),
      SizedBox(
        width: 180,
        child: SearchPickerField<String>(
          key: const Key('financials-markets-period'),
          isDense: true,
          value: _period,
          options: [
            for (final p in marketPeriods(DateTime.now()).keys)
              SearchOption(value: p, label: p),
          ],
          onChanged: (p) {
            if (p == null) return;
            setState(() => _period = p);
            _load();
          },
        ),
      ),
      OutlinedButton.icon(
        key: const Key('financials-markets-match'),
        icon: const Icon(Icons.call_split),
        label: const Text('Match markets'),
        onPressed: _match,
      ),
    ];
    return ListView(
      key: const ValueKey('tour-financials-markets'),
      padding: const EdgeInsets.all(16),
      children: [
        if (narrow) ...[
          Text('Markets', style: Theme.of(context).textTheme.titleMedium),
          const SizedBox(height: 8),
          Wrap(spacing: 8, runSpacing: 8, children: controls),
        ] else
          Row(
            children: [
              Expanded(
                child: Text(
                  'Markets',
                  style: Theme.of(context).textTheme.titleMedium,
                ),
              ),
              for (final c in controls) ...[
                c is ListSearch ? Flexible(child: c) : c,
                const SizedBox(width: 8),
              ],
            ],
          ),
        const SizedBox(height: 12),
        if (_error != null)
          Text(
            _error!,
            style: TextStyle(color: Theme.of(context).colorScheme.error),
          )
        else if (data == null)
          const Center(child: CircularProgressIndicator())
        else ...[
          if (data['quickbooks']?['connected'] != true)
            const Card(
              child: ListTile(
                leading: Icon(Icons.link_off),
                title: Text('QuickBooks is not connected'),
                subtitle: Text(
                  'Connect it in Settings › Integrations to see expenses per market. '
                  'Closing rates show without it.',
                ),
              ),
            ),
          Wrap(
            spacing: 12,
            runSpacing: 12,
            children: [
              _Tile(
                label: 'Expenses',
                value: _money(data['total_expenses_cents'] as num? ?? 0),
              ),
              _Tile(
                label: 'Average closing rate',
                value: data['average_closing_rate'] == null
                    ? '—'
                    : '${data['average_closing_rate']}%',
              ),
              _Tile(label: 'Markets', value: '${markets.length}'),
            ],
          ),
          const SizedBox(height: 12),
          if (markets.isEmpty)
            const Padding(
              padding: EdgeInsets.all(24),
              child: Center(child: Text('No markets in this view.')),
            ),
          for (final m in markets) _MarketCard(market: m),
        ],
      ],
    );
  }
}

class _Tile extends StatelessWidget {
  const _Tile({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) => SizedBox(
    width: 200,
    child: Card(
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(label, style: Theme.of(context).textTheme.labelMedium),
            const SizedBox(height: 4),
            Text(value, style: Theme.of(context).textTheme.titleLarge),
          ],
        ),
      ),
    ),
  );
}

class _MarketCard extends StatelessWidget {
  const _MarketCard({required this.market});

  final Map market;

  @override
  Widget build(BuildContext context) {
    final m = market;
    final rate = m['closing_rate'];
    final perJob = m['cost_per_closed_job_cents'];
    final facts = [
      'Expenses ${_money(m['expenses_cents'] as num? ?? 0)}',
      if (m['franchise_id'] != null)
        'Closing rate ${rate == null ? '—' : '$rate%'} (${m['leads_won'] ?? 0} of ${m['leads'] ?? 0} leads)',
      if (m['franchise_id'] != null) 'Closed jobs ${m['closed_jobs'] ?? 0}',
      if (m['franchise_id'] != null)
        'Per closed job ${perJob == null ? '—' : _money(perJob as num)}',
    ];
    final weeks = (m['weeks'] as List? ?? const []).cast<Map>();
    final categories = (m['by_category'] as List? ?? const []).cast<Map>();
    return Card(
      child: ExpansionTile(
        key: Key('financials-market-${m['franchise_id'] ?? 'unassigned'}'),
        title: Text('${m['name']}'),
        subtitle: Text(facts.join(' · ')),
        childrenPadding: const EdgeInsets.fromLTRB(16, 0, 16, 12),
        expandedCrossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (weeks.isEmpty)
            const Text('No expenses in these dates.')
          else ...[
            Text('By category', style: Theme.of(context).textTheme.labelLarge),
            for (final c in categories)
              _line('${c['category']}', c['cents'] as num),
            const Divider(),
            Text('By week', style: Theme.of(context).textTheme.labelLarge),
            for (final w in weeks) ...[
              _line(
                'Week of ${w['week_start']}',
                w['cents'] as num,
                bold: true,
              ),
              for (final c in (w['by_category'] as List).cast<Map>())
                Padding(
                  padding: const EdgeInsets.only(left: 16),
                  child: _line('${c['category']}', c['cents'] as num),
                ),
            ],
          ],
        ],
      ),
    );
  }

  Widget _line(String label, num cents, {bool bold = false}) => Padding(
    padding: const EdgeInsets.symmetric(vertical: 2),
    child: Row(
      children: [
        Expanded(
          child: Text(
            label,
            style: bold ? const TextStyle(fontWeight: FontWeight.w600) : null,
          ),
        ),
        Text(_money(cents)),
      ],
    ),
  );
}

/// Which QuickBooks Class or Location is which market. Matched by name on
/// every sync until somebody chooses here; a choice is kept.
class _MarketMapsPanel extends StatefulWidget {
  const _MarketMapsPanel({
    required this.api,
    required this.me,
    required this.canEdit,
  });

  final ApiClient api;
  final Me me;
  final bool canEdit;

  @override
  State<_MarketMapsPanel> createState() => _MarketMapsPanelState();
}

class _MarketMapsPanelState extends State<_MarketMapsPanel> {
  List<Map>? _maps;
  List<Map> _franchises = const [];
  String? _error;
  bool _changed = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final r = await widget.api.get('/financials/markets/maps');
    if (!mounted) return;
    setState(() {
      _maps = r.ok ? (r.body['data']['maps'] as List).cast<Map>() : null;
      _franchises = r.ok
          ? (r.body['data']['franchises'] as List).cast<Map>()
          : const [];
      _error = r.ok ? null : r.message;
    });
  }

  Future<void> _set(Map map, String? franchiseId) async {
    final r = await widget.api.patch('/financials/markets/maps/${map['id']}', {
      'franchise_id': franchiseId,
    });
    if (!mounted) return;
    if (!r.ok) {
      setState(() => _error = r.message);
      return;
    }
    _changed = true;
    final saved = r.body['data'] as Map;
    setState(() {
      _maps = [for (final m in _maps!) m['id'] == saved['id'] ? saved : m];
      _error = null;
    });
  }

  @override
  Widget build(BuildContext context) {
    final maps = _maps;
    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (didPop, _) {
        if (!didPop) Navigator.of(context).pop(_changed);
      },
      child: SidePanel(
        title: 'Match markets',
        subtitle:
            'Which branch each QuickBooks class or location counts for. '
            'A location wins over a class.',
        onClose: () => Navigator.of(context).pop(_changed),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            if (_error != null)
              Padding(
                padding: const EdgeInsets.only(bottom: 8),
                child: Text(
                  _error!,
                  style: TextStyle(color: Theme.of(context).colorScheme.error),
                ),
              ),
            if (maps == null && _error == null)
              const Center(child: CircularProgressIndicator())
            else if (maps != null && maps.isEmpty)
              const Text(
                'Nothing to match yet: connect QuickBooks, and its classes and '
                'locations appear here after the first read.',
              )
            else
              for (final m in maps ?? const <Map>[])
                Padding(
                  padding: const EdgeInsets.only(bottom: 8),
                  child: Row(
                    children: [
                      Expanded(
                        child: Text(
                          '${m['dimension_label']}: ${m['name']}'
                          '${m['mapped_by'] == 'name' && m['franchise_id'] != null ? ' (by name)' : ''}',
                        ),
                      ),
                      SizedBox(
                        width: 200,
                        child: SearchPickerField<String>(
                          key: Key('financials-market-map-${m['id']}'),
                          isDense: true,
                          allowClear: true,
                          hint: 'No market',
                          value: m['franchise_id'] as String?,
                          options: [
                            for (final f in _franchises)
                              SearchOption(
                                value: f['id'] as String,
                                label: branchLabelOf(
                                  widget.me,
                                  f['id'] as String,
                                  '${f['name']}',
                                ),
                              ),
                            // Mapped to a branch outside this view: shown, not offered.
                            if (m['franchise_id'] != null &&
                                !_franchises.any(
                                  (f) => f['id'] == m['franchise_id'],
                                ))
                              SearchOption(
                                value: m['franchise_id'] as String,
                                label: '${m['franchise'] ?? 'Another branch'}',
                                enabled: false,
                              ),
                          ],
                          onChanged: widget.canEdit ? (v) => _set(m, v) : null,
                        ),
                      ),
                    ],
                  ),
                ),
          ],
        ),
      ),
    );
  }
}
