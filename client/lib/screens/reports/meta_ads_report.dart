import 'package:flutter/material.dart';

import '../../widgets/empty_state.dart';
import '../../widgets/report_grid.dart';
import '../../widgets/module_overview.dart';
import '../../widgets/ai_mark.dart';
import 'report_dates.dart';
import 'report_tab.dart';

/// Facebook ad spend per campaign (Jose, 2026-10-12: "Facebook Financial
/// report + Retro filter by state and category"), filtered by the state a
/// campaign is for, its service category, and where its viewers were; with
/// the campaign mapping staff keep beneath it.
class MetaAdsReport extends ReportTab {
  const MetaAdsReport({
    super.key,
    required super.api,
    this.canEdit = false,
    super.now,
    super.formats,
    super.span,
  });

  final bool canEdit;

  @override
  State<MetaAdsReport> createState() => _MetaAdsReportState();
}

class _MetaAdsReportState extends ReportTabState<MetaAdsReport> {
  String? _state;
  String? _category;
  String? _region;
  List<Map<String, dynamic>> _campaigns = const [];
  bool _editing = false;

  @override
  String get endpoint => '/reports/meta';

  @override
  Map<String, String?> get filterParams => {
    'state': _state,
    'category': _category,
    'region': _region,
  };

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
      setState(() => error = response.message);
      return;
    }
    await Future.wait([_loadCampaigns(), load()]);
  }

  static Map<String, String> _categories(Map<String, dynamic>? data) => {
    for (final c in (data?['categories'] as List? ?? const []))
      '${c['id']}': '${c['name']}',
  };

  static num _total(List<Map<String, dynamic>> rows, String key) =>
      rows.fold<num>(0, (sum, r) => sum + ((r[key] as num?) ?? 0));

  @override
  Key get searchKey => const Key('meta-ads-report-search');
  @override
  String get searchHint => 'Find a campaign';

  @override
  List<Object?> searchFields(Map<String, dynamic> r) => [
    r['name'],
    r['state'],
    r['category'] ?? 'Uncategorised',
    r['branch'],
  ];

  @override
  List<Widget> filters(Map<String, dynamic>? data) => [
    reportFilter(
      'State',
      _state,
      {for (final s in (data?['states'] as List? ?? const [])) '$s': '$s'},
      (v) => _state = v,
      const ValueKey('meta-state'),
      load,
    ),
    reportFilter(
      'Category',
      _category,
      {..._categories(data), 'none': 'Uncategorised'},
      (v) => _category = v,
      const ValueKey('meta-category'),
      load,
    ),
    reportFilter(
      'Where viewers were',
      _region,
      {for (final r in (data?['regions'] as List? ?? const [])) '$r': '$r'},
      (v) => _region = v,
      const ValueKey('meta-region'),
      load,
    ),
  ];

  @override
  Widget get emptyState => const EmptyState(
    key: ValueKey('meta-empty'),
    icon: Icons.campaign_outlined,
    title: 'No Facebook ad spend',
    message:
        'None for these dates and filters. Facebook Ads is connected '
        'in Settings › Integrations.',
  );

  @override
  String get noMatchNoun => 'campaigns';

  @override
  Widget figures(List<Map<String, dynamic>> rows) {
    num total(String key) => _total(rows, key);
    return OverviewFigures(
      key: const ValueKey('meta-totals'),
      figures: [
        OverviewFigure(
          label: 'Spend',
          value: reportMoney(total('spend_minor')),
        ),
        OverviewFigure(label: 'Leads', value: reportCount(total('leads'))),
        OverviewFigure(
          label: 'Cost per lead',
          value: total('leads') > 0
              ? reportMoney(total('spend_minor') ~/ total('leads'))
              : '—',
        ),
        OverviewFigure(label: 'Clicks', value: reportCount(total('clicks'))),
      ],
    );
  }

  @override
  Widget table(
    List<Map<String, dynamic>> rows,
    List<Map<String, dynamic>> shown,
  ) {
    num total(String key) => _total(rows, key);
    return ReportGrid(
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
            GridCell(reportMoney(r['spend_minor'])),
            GridCell(reportCount(r['leads'])),
            r['cost_per_lead_minor'] == null
                ? const GridCell.none()
                : GridCell(reportMoney(r['cost_per_lead_minor'])),
            GridCell(reportCount(r['clicks'])),
            GridCell(reportCount(r['impressions'])),
          ],
      ],
      total: [
        const GridCell('Total'),
        const GridCell(''),
        const GridCell(''),
        const GridCell(''),
        GridCell(reportMoney(total('spend_minor'))),
        GridCell(reportCount(total('leads'))),
        total('leads') > 0
            ? GridCell(reportMoney(total('spend_minor') ~/ total('leads')))
            : const GridCell.none(),
        GridCell(reportCount(total('clicks'))),
        GridCell(reportCount(total('impressions'))),
      ],
    );
  }

  @override
  String get note =>
      'Leads are Facebook\'s own count: form, Messenger and website '
      'leads. "Where viewers were" is Facebook\'s region breakdown, kept '
      'for about 13 months; without it the figures are the campaign '
      'totals. Jobs and sales will join when Workiz jobs carry a '
      'Facebook source.';

  @override
  List<Widget> trailing(Map<String, dynamic>? data, TextStyle? muted) => [
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
          _campaignRow(context, c, _categories(data), muted),
    ],
  ];

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
