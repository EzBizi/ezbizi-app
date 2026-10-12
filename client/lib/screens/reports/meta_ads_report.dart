import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../widgets/ai_mark.dart';
import '../../widgets/error_note.dart';

/// Facebook ad spend per campaign (Jose, 2026-10-12: "Facebook Financial
/// report + Retro filter by state and category"), filtered by the state a
/// campaign is for, its service category, and where its viewers were; with
/// the campaign mapping staff keep beneath it.
class MetaAdsReport extends StatefulWidget {
  const MetaAdsReport({
    super.key,
    required this.api,
    this.canEdit = false,
    this.now,
  });

  final ApiClient api;
  final bool canEdit;
  final DateTime Function()? now;

  @override
  State<MetaAdsReport> createState() => _MetaAdsReportState();
}

class _MetaAdsReportState extends State<MetaAdsReport> {
  String _period = 'last_month';
  String? _state;
  String? _category;
  String? _region;
  Map<String, dynamic>? _data;
  List<Map<String, dynamic>> _campaigns = const [];
  bool _editing = false;
  String? _error;

  static const _periods = {
    'last_week': 'Last week',
    'this_month': 'This month',
    'last_month': 'Last month',
    'last_12': 'Last 12 months',
  };

  (DateTime, DateTime) _range() {
    final now = (widget.now ?? DateTime.now)();
    final today = DateTime(now.year, now.month, now.day);
    final monday = today.subtract(Duration(days: today.weekday - 1));

    return switch (_period) {
      'last_week' => (
        monday.subtract(const Duration(days: 7)),
        monday.subtract(const Duration(days: 1)),
      ),
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
    final response = await widget.api.get('/reports/meta', {
      'from': _day(from),
      'to': _day(to),
      'state': ?_state,
      'category': ?_category,
      'region': ?_region,
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

  Future<void> _loadCampaigns() async {
    final response = await widget.api.get('/reports/meta/campaigns');
    if (!mounted || !response.ok) return;
    setState(
      () => _campaigns = [
        for (final c in (response.data as List))
          (c as Map).cast<String, dynamic>(),
      ],
    );
  }

  Future<void> _save(String id, Map<String, dynamic> change) async {
    final response = await widget.api.put(
      '/reports/meta/campaigns/$id',
      change,
    );
    if (!mounted) return;
    if (!response.ok) {
      setState(() => _error = response.message);
      return;
    }
    await Future.wait([_loadCampaigns(), _load()]);
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
    final categories = {
      for (final c in (data?['categories'] as List? ?? const []))
        '${c['id']}': '${c['name']}',
    };
    num total(String key) =>
        rows.fold<num>(0, (sum, r) => sum + ((r[key] as num?) ?? 0));

    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Wrap(
          spacing: 6,
          runSpacing: 8,
          children: [
            for (final p in _periods.entries)
              ChoiceChip(
                key: ValueKey('meta-${p.key}'),
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
              'State',
              _state,
              {
                for (final s in (data?['states'] as List? ?? const []))
                  '$s': '$s',
              },
              (v) => _state = v,
              const ValueKey('meta-state'),
            ),
            _filter(
              'Category',
              _category,
              {...categories, 'none': 'Uncategorised'},
              (v) => _category = v,
              const ValueKey('meta-category'),
            ),
            _filter(
              'Where viewers were',
              _region,
              {
                for (final r in (data?['regions'] as List? ?? const []))
                  '$r': '$r',
              },
              (v) => _region = v,
              const ValueKey('meta-region'),
            ),
          ],
        ),
        const SizedBox(height: 12),
        if (_error != null) ErrorNote(_error!),
        if (data == null && _error == null)
          const Center(child: CircularProgressIndicator())
        else if (rows.isEmpty)
          Text(
            'No Facebook ad spend for these dates and filters. Facebook Ads '
            'is connected in Settings › Integrations.',
            style: muted,
          )
        else ...[
          Text(
            '${_money(total('spend_minor'))} spent · ${total('leads')} leads · '
            '${total('leads') > 0 ? _money(total('spend_minor') ~/ total('leads')) : '—'} per lead',
            key: const ValueKey('meta-totals'),
            style: theme.textTheme.titleMedium,
          ),
          const SizedBox(height: 8),
          SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            child: DataTable(
              columns: const [
                DataColumn(label: Text('Campaign')),
                DataColumn(label: Text('State')),
                DataColumn(label: Text('Category')),
                DataColumn(label: Text('Branch')),
                DataColumn(label: Text('Spend'), numeric: true),
                DataColumn(label: Text('Leads'), numeric: true),
                DataColumn(label: Text('Cost per lead'), numeric: true),
                DataColumn(label: Text('Clicks'), numeric: true),
                DataColumn(label: Text('Impressions'), numeric: true),
              ],
              rows: [
                for (final r in rows)
                  DataRow(
                    cells: [
                      DataCell(Text('${r['name']}')),
                      DataCell(Text('${r['state'] ?? '—'}')),
                      DataCell(Text('${r['category'] ?? 'Uncategorised'}')),
                      DataCell(Text('${r['branch'] ?? '—'}')),
                      DataCell(Text(_money(r['spend_minor']))),
                      DataCell(Text('${r['leads']}')),
                      DataCell(Text(_money(r['cost_per_lead_minor']))),
                      DataCell(Text('${r['clicks']}')),
                      DataCell(Text('${r['impressions']}')),
                    ],
                  ),
              ],
            ),
          ),
        ],
        const SizedBox(height: 12),
        Text(
          'Leads are Facebook\'s own count: form, Messenger and website '
          'leads. "Where viewers were" is Facebook\'s region breakdown, kept '
          'for about 13 months; without it the figures are the campaign '
          'totals. Jobs and sales will join when Workiz jobs carry a '
          'Facebook source.',
          style: muted,
        ),
        if (widget.canEdit) ...[
          const SizedBox(height: 16),
          Align(
            alignment: Alignment.centerLeft,
            child: TextButton.icon(
              key: const ValueKey('meta-edit-campaigns'),
              icon: Icon(_editing ? Icons.expand_less : Icons.expand_more),
              label: const Text('Campaign state, category and branch'),
              onPressed: () {
                setState(() => _editing = !_editing);
                if (_editing) _loadCampaigns();
              },
            ),
          ),
          if (_editing)
            for (final c in _campaigns)
              _campaignRow(context, c, categories, muted),
        ],
      ],
    );
  }

  Widget _campaignRow(
    BuildContext context,
    Map<String, dynamic> c,
    Map<String, String> categories,
    TextStyle? muted,
  ) {
    final id = '${c['id']}';
    final suggested = c['suggested_service_group_id'] as String?;
    final showSuggestion =
        suggested != null && suggested != c['service_group_id'];

    return Padding(
      key: ValueKey('meta-campaign-$id'),
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Wrap(
        spacing: 12,
        runSpacing: 6,
        crossAxisAlignment: WrapCrossAlignment.center,
        children: [
          SizedBox(width: 260, child: Text('${c['name']}')),
          SizedBox(
            width: 90,
            child: TextFormField(
              key: ValueKey('meta-state-$id'),
              initialValue: '${c['state'] ?? ''}',
              maxLength: 2,
              textCapitalization: TextCapitalization.characters,
              decoration: const InputDecoration(
                labelText: 'State',
                counterText: '',
              ),
              onFieldSubmitted: (v) => _save(id, {
                'state': v.trim().isEmpty ? null : v.trim().toUpperCase(),
              }),
            ),
          ),
          DropdownMenu<String?>(
            enableFilter: true,
            requestFocusOnTap: true,
            key: ValueKey('meta-category-$id'),
            label: const Text('Category'),
            initialSelection: c['service_group_id'] as String?,
            onSelected: (v) => _save(id, {'service_group_id': v}),
            dropdownMenuEntries: [
              const DropdownMenuEntry(value: null, label: 'Uncategorised'),
              for (final g in categories.entries)
                DropdownMenuEntry(value: g.key, label: g.value),
            ],
          ),
          if (showSuggestion)
            Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                const AiMark(),
                const SizedBox(width: 4),
                Text(categories[suggested] ?? 'Suggested', style: muted),
                TextButton(
                  key: ValueKey('meta-use-suggestion-$id'),
                  onPressed: () => _save(id, {'service_group_id': suggested}),
                  child: const Text('Use suggestion'),
                ),
              ],
            ),
        ],
      ),
    );
  }
}
