import 'package:flutter/material.dart';

import '../../widgets/empty_state.dart';
import '../../widgets/report_grid.dart';
import '../../widgets/module_overview.dart';
import '../../core/numbers.dart';
import '../../core/job_money.dart';
import '../../core/api_client.dart';
import '../../core/timestamps.dart';
import '../../widgets/date_range_picker.dart';
import '../../widgets/ai_mark.dart';
import '../../widgets/error_note.dart';
import 'report_dates.dart';

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
    this.formats = DisplayFormats.fallback,
  });

  final ApiClient api;
  final bool canEdit;
  final DateTime Function()? now;
  final DisplayFormats formats;

  @override
  State<MetaAdsReport> createState() => _MetaAdsReportState();
}

class _MetaAdsReportState extends State<MetaAdsReport> {
  late DateSpan _span = DateSpan(
    DateTime(_today.year, _today.month - 1),
    DateTime(_today.year, _today.month, 0),
  );
  String? _state;
  String? _category;
  String? _region;
  Map<String, dynamic>? _data;
  List<Map<String, dynamic>> _campaigns = const [];
  bool _editing = false;
  String? _error;
  String _query = '';

  DateTime get _today => DateSpan.single((widget.now ?? DateTime.now)()).from;

  static String _day(DateTime d) =>
      '${d.year}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final (from, to) = (_span.from, _span.to);
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

  /// Money as every Reports tab writes it (UI queue 87).
  static String _money(Object? minor) =>
      minor is num ? formatMoney(minor.round()) : '—';

  static String _count(Object? n) => n is num ? formatCount(n.round()) : '—';

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
    // The figures and the Total row stay the whole report's; the search
    // narrows the rows.
    final query = _query.trim().toLowerCase();
    final shown = query.isEmpty
        ? rows
        : [
            for (final r in rows)
              if ([
                r['name'],
                r['state'],
                r['category'] ?? 'Uncategorised',
                r['branch'],
              ].any((v) => '${v ?? ''}'.toLowerCase().contains(query)))
                r,
          ];

    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        ReportDates(
          key: const ValueKey('tour-report-dates'),
          initial: _span,
          today: _today,
          formats: widget.formats,
          onChanged: (span) {
            setState(() => _span = span);
            _load();
          },
        ),
        const SizedBox(height: 12),
        Wrap(
          spacing: 12,
          runSpacing: 12,
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            // As tall as the buttons beside it (Jose, 2026-10-20).
            SizedBox(
              width: 240,
              height: 40,
              child: TextField(
                key: const Key('meta-ads-report-search'),
                textAlignVertical: TextAlignVertical.center,
                decoration: const InputDecoration(
                  isDense: true,
                  contentPadding: EdgeInsets.symmetric(horizontal: 12),
                  prefixIconConstraints: BoxConstraints(minWidth: 36),
                  prefixIcon: Icon(Icons.search, size: 18),
                  hintText: 'Find a campaign',
                  border: OutlineInputBorder(),
                ),
                onChanged: (v) => setState(() => _query = v),
              ),
            ),
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
          const EmptyState(
            key: ValueKey('meta-empty'),
            icon: Icons.campaign_outlined,
            title: 'No Facebook ad spend',
            message:
                'None for these dates and filters. Facebook Ads is connected '
                'in Settings › Integrations.',
          )
        else ...[
          // UI queue 87, desktop 1: the figures, then the one table.
          OverviewFigures(
            key: const ValueKey('meta-totals'),
            figures: [
              OverviewFigure(
                label: 'Spend',
                value: _money(total('spend_minor')),
              ),
              OverviewFigure(label: 'Leads', value: _count(total('leads'))),
              OverviewFigure(
                label: 'Cost per lead',
                value: total('leads') > 0
                    ? _money(total('spend_minor') ~/ total('leads'))
                    : '—',
              ),
              OverviewFigure(label: 'Clicks', value: _count(total('clicks'))),
            ],
          ),
          const SizedBox(height: 12),
          if (shown.isEmpty)
            Text(
              'No campaigns match "${_query.trim()}".',
              style: theme.textTheme.bodyMedium,
            )
          else
            ReportGrid(
              key: const ValueKey('meta-table'),
              columns: const [
                GridColumn('Campaign', pinned: true),
                GridColumn('State'),
                GridColumn('Category'),
                GridColumn('Branch'),
                GridColumn('Spend', numeric: true, main: true),
                GridColumn('Leads', numeric: true, main: true),
                GridColumn('Cost per lead', numeric: true, main: true),
                GridColumn('Clicks', numeric: true),
                GridColumn('Impressions', numeric: true),
              ],
              rows: [
                for (final r in shown)
                  [
                    GridCell('${r['name']}'),
                    r['state'] == null
                        ? const GridCell.none()
                        : GridCell('${r['state']}'),
                    GridCell('${r['category'] ?? 'Uncategorised'}'),
                    r['branch'] == null
                        ? const GridCell.none()
                        : GridCell('${r['branch']}'),
                    GridCell(_money(r['spend_minor'])),
                    GridCell(_count(r['leads'])),
                    r['cost_per_lead_minor'] == null
                        ? const GridCell.none()
                        : GridCell(_money(r['cost_per_lead_minor'])),
                    GridCell(_count(r['clicks'])),
                    GridCell(_count(r['impressions'])),
                  ],
              ],
              total: [
                const GridCell('Total'),
                const GridCell(''),
                const GridCell(''),
                const GridCell(''),
                GridCell(_money(total('spend_minor'))),
                GridCell(_count(total('leads'))),
                total('leads') > 0
                    ? GridCell(_money(total('spend_minor') ~/ total('leads')))
                    : const GridCell.none(),
                GridCell(_count(total('clicks'))),
                GridCell(_count(total('impressions'))),
              ],
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
