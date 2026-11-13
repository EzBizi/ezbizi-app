import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/job_money.dart';
import '../core/numbers.dart';
import '../widgets/ad_change_confirm.dart';
import '../widgets/side_panel.dart';
import 'ad_campaigns_shell.dart';

/// Meta campaigns, ad sets and ads: what they spent, what came back, and the
/// switch and budget that control them (Jose, 2026-11-04: "we need to be able
/// to control meta campaigns from the app"; design 180, option 1 + phone 4).
///
/// Everything is read live from Meta through `meta-ads/tree`; nothing here is
/// remembered, because a copy is stale the moment somebody pauses a campaign
/// in Ads Manager. A switch asks to confirm in a side panel, then posts and offers Undo, and the
/// tree is re-read quietly after, since pausing a campaign changes what every
/// ad set and ad beneath it reports.
class MetaCampaignsView extends AdCampaignsView {
  const MetaCampaignsView({
    super.key,
    required super.api,
    required super.session,
    required super.sourceSwitch,
  });

  @override
  State<MetaCampaignsView> createState() => _MetaCampaignsViewState();
}

class _MetaCampaignsViewState
    extends AdCampaignsState<MetaCampaignsView, (Map<String, dynamic>, int)> {
  final Set<String> _expanded = <String>{};

  @override
  String get keyPrefix => 'meta';
  @override
  Key get rangeKey => const ValueKey('meta-ads-range');
  @override
  Key get searchKey => const ValueKey('meta-ads-search');
  @override
  Key get tableKey => const ValueKey('meta-ads-table');
  @override
  String get accountsPath => '/meta-ads/accounts';
  @override
  String get campaignsPath => '/meta-ads/tree';
  @override
  String get accountNumberField => 'account_id';
  @override
  String get emptyTitle => 'No Meta ad account connected';
  @override
  String get emptyMessage =>
      'Connect Facebook in Settings › Integrations to see and '
      'control your Meta campaigns here.';
  @override
  String get noCampaignsText => 'This ad account has no campaigns.';
  @override
  String get resultsField => 'leads';
  @override
  String get resultsLabel => 'Leads';
  @override
  String get costPerLabel => 'Cost per lead';
  @override
  String get tableHeading => 'CAMPAIGN · AD SET · AD';
  @override
  double get tableMinWidth => 1210;
  @override
  double get headerStart => 40;

  @override
  void onAccountChanged() => _expanded.clear();

  /// The clock chip, on campaigns only: the level a business schedules.
  Widget _scheduleCell(Map<String, dynamic> node) => node['level'] == 'campaign'
      ? scheduleChip(node)
      : const SizedBox.shrink();

  // ── Figures ────────────────────────────────────────────────────────────

  static List<Map<String, dynamic>> _children(Map<String, dynamic> node) =>
      List<Map<String, dynamic>>.from(
        (node['children'] as List? ?? const []).cast<Map>(),
      );

  bool _matches(Map<String, dynamic> node) =>
      needle.isEmpty ||
      '${node['name']}'.toLowerCase().contains(needle) ||
      _children(node).any(_matches);

  bool _open(Map<String, dynamic> node) =>
      _expanded.contains(node['id']) ||
      (needle.isNotEmpty &&
          !'${node['name']}'.toLowerCase().contains(needle) &&
          _children(node).any(_matches));

  /// Every visible row, depth-first, with its depth.
  @override
  List<(Map<String, dynamic>, int)> visibleRows() {
    final out = <(Map<String, dynamic>, int)>[];
    void walk(List<Map<String, dynamic>> nodes, int depth) {
      for (final node in sorted(nodes)) {
        if (!_matches(node)) continue;
        out.add((node, depth));
        if (_open(node)) walk(_children(node), depth + 1);
      }
    }

    walk(campaigns ?? const [], 0);
    return out;
  }

  // ── Changes ────────────────────────────────────────────────────────────

  /// Confirmed first (Jose, 2026-11-09: confirmation before any change);
  /// Undo, being the reverse change, asks again.
  Future<void> _toggle(Map<String, dynamic> node, bool on) async {
    final ok = await confirmAdChange(
      context,
      title: on ? 'Turn on ${node['name']}?' : 'Pause ${node['name']}?',
      change: on
          ? 'Meta starts delivering it again.'
          : 'Meta stops delivering it until it is turned on.',
    );
    if (!ok || !mounted) return;
    final before = node['status'];
    final status = on ? 'ACTIVE' : 'PAUSED';
    setState(() => node['status'] = status);

    final response = await widget.api.post('/meta-ads/status', {
      'connection_id': account,
      'object_id': node['id'],
      'status': status,
    });
    if (!mounted) return;
    final messenger = ScaffoldMessenger.of(context);
    messenger.hideCurrentSnackBar();

    if (!response.ok) {
      setState(() => node['status'] = before);
      messenger.showSnackBar(SnackBar(content: Text(response.message)));
      return;
    }
    messenger.showSnackBar(
      SnackBar(
        content: Text('${node['name']} ${on ? 'on' : 'paused'}'),
        action: SnackBarAction(
          label: 'UNDO',
          onPressed: () => _toggle(node, !on),
        ),
      ),
    );
    load(quiet: true);
  }

  Future<void> _editBudget(Map<String, dynamic> node) async {
    final saved = await showSidePanel<bool>(
      context,
      builder: (_) => _BudgetPanel(
        api: widget.api,
        connectionId: account!,
        node: node,
        days: days,
      ),
    );
    if (saved == true && mounted) load(quiet: true);
  }

  // ── Drawing ────────────────────────────────────────────────────────────

  @override
  Widget? kpiNote(List<Map<String, dynamic>> campaigns, double spend) {
    // UI/UX audit 11-13 §10.1: $9,018 under 21 paused campaigns read as a
    // bug; Meta agreed to the cent, all of it spent before the pause (11-09).
    final allPaused =
        spend > 0 &&
        campaigns.isNotEmpty &&
        campaigns.every((c) => c['status'] != 'ACTIVE');
    if (!allPaused) return null;
    return Text(
      'Every campaign is paused now. This spend is from before they '
      'were paused.',
      key: const ValueKey('meta-kpi-all-paused'),
      style: Theme.of(context).textTheme.bodySmall,
    );
  }

  /// "Paused by campaign", "Paused", "Disapproved"… or null when it runs.
  static String? _why(Map<String, dynamic> node) {
    final effective = '${node['effective_status'] ?? ''}';
    if (node['status'] == 'PAUSED') return 'Paused';
    if (effective == 'CAMPAIGN_PAUSED') return 'Paused by campaign';
    if (effective == 'ADSET_PAUSED') return 'Paused by ad set';
    if (effective.isEmpty || effective == 'ACTIVE') return null;
    final word = effective.toLowerCase().replaceAll('_', ' ');
    return word[0].toUpperCase() + word.substring(1);
  }

  Widget _onCell(Map<String, dynamic> node, ThemeData theme) {
    final why = _why(node);
    final muted = theme.textTheme.bodySmall;
    if (!canManage) {
      return Text(why ?? 'Active', style: why == null ? null : muted);
    }
    final held = const {
      'CAMPAIGN_PAUSED',
      'ADSET_PAUSED',
    }.contains(node['effective_status']);
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Opacity(
          opacity: held && node['status'] == 'ACTIVE' ? 0.45 : 1,
          child: Switch(
            key: ValueKey('meta-on-${node['id']}'),
            value: node['status'] == 'ACTIVE',
            onChanged: (on) => _toggle(node, on),
          ),
        ),
        if (why != null) ...[
          const SizedBox(width: 6),
          Flexible(
            child: Text(
              why,
              style: muted,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
            ),
          ),
        ],
      ],
    );
  }

  Widget _budgetCell(Map<String, dynamic> node, ThemeData theme) {
    final muted = theme.textTheme.bodySmall;
    final daily = node['daily_budget'];
    if (daily != null) {
      if (!canManage) return Text(adMoney(daily));
      return InkWell(
        key: ValueKey('meta-budget-${node['id']}'),
        onTap: () => _editBudget(node),
        child: Text(
          '${adMoney(daily)} ✎',
          style: TextStyle(color: theme.colorScheme.primary),
        ),
      );
    }
    if (node['lifetime_budget'] != null) {
      return Text('${adMoney(node['lifetime_budget'])} lifetime', style: muted);
    }
    return Text(switch (node['level']) {
      'campaign' => 'Ad set budgets',
      'adset' => 'Campaign budget',
      _ => '—',
    }, style: muted);
  }

  @override
  List<(String, String?, double)> get columns => const [
    ('On', null, 190),
    ('Schedule', null, 250),
    ('Budget / day', 'daily_budget', 130),
    ('Spend', 'spend', 100),
    ('Leads', 'leads', 70),
    ('Clicks', 'clicks', 80),
    ('Per lead', 'cost_per_lead', 90),
  ];

  @override
  Widget tableRow((Map<String, dynamic>, int) row, ThemeData theme) {
    final (node, depth) = row;
    final kids = _children(node).isNotEmpty;
    final open = _open(node);
    return Container(
      key: ValueKey('meta-row-${node['id']}'),
      color: depth > 0 ? theme.scaffoldBackgroundColor : null,
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
      child: Row(
        children: [
          Expanded(
            child: Padding(
              padding: EdgeInsets.only(left: depth * 20.0),
              child: Row(
                children: [
                  SizedBox(
                    width: 28,
                    child: kids
                        ? IconButton(
                            key: ValueKey('meta-expand-${node['id']}'),
                            padding: EdgeInsets.zero,
                            iconSize: 18,
                            tooltip: open ? 'Collapse' : 'Expand',
                            icon: Icon(
                              open ? Icons.expand_more : Icons.chevron_right,
                            ),
                            onPressed: () => setState(
                              () => open
                                  ? _expanded.remove(node['id'])
                                  : _expanded.add('${node['id']}'),
                            ),
                          )
                        : null,
                  ),
                  Flexible(
                    child: Text(
                      '${node['name']}',
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: depth == 0
                          ? const TextStyle(fontWeight: FontWeight.w600)
                          : null,
                    ),
                  ),
                ],
              ),
            ),
          ),
          SizedBox(width: 190, child: _onCell(node, theme)),
          SizedBox(width: 250, child: _scheduleCell(node)),
          SizedBox(width: 130, child: _budgetCell(node, theme)),
          for (final (key, money) in const [
            ('spend', true),
            ('leads', false),
            ('clicks', false),
            ('cost_per_lead', true),
          ])
            SizedBox(
              width: switch (key) {
                'spend' => 100,
                'leads' => 70,
                'clicks' => 80,
                _ => 90,
              },
              child: Text(
                node[key] == null
                    ? '—'
                    : money
                    ? adMoney(node[key])
                    : formatCount(adNumber(node[key]).round()),
                textAlign: TextAlign.end,
              ),
            ),
        ],
      ),
    );
  }

  @override
  Widget card((Map<String, dynamic>, int) row, ThemeData theme) {
    final muted = theme.textTheme.bodySmall;
    final (node, depth) = row;
    final kids = _children(node).isNotEmpty;
    final open = _open(node);
    final perLead = node['cost_per_lead'];
    return Padding(
      padding: EdgeInsets.only(left: depth * 14.0, bottom: 8),
      child: Card(
        key: ValueKey('meta-card-${node['id']}'),
        margin: EdgeInsets.zero,
        color: depth > 0 ? theme.scaffoldBackgroundColor : null,
        child: InkWell(
          onTap: kids
              ? () => setState(
                  () => open
                      ? _expanded.remove(node['id'])
                      : _expanded.add('${node['id']}'),
                )
              : null,
          child: Padding(
            padding: const EdgeInsets.fromLTRB(12, 8, 8, 10),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    if (kids)
                      Icon(
                        open ? Icons.expand_more : Icons.chevron_right,
                        size: 18,
                      ),
                    Expanded(
                      child: Text(
                        '${node['name']}',
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(fontWeight: FontWeight.w600),
                      ),
                    ),
                    if (canManage)
                      _onCell(node, theme)
                    else
                      Text(_why(node) ?? 'Active', style: muted),
                  ],
                ),
                if (node['level'] == 'campaign') _scheduleCell(node),
                Text(
                  [
                    adMoney(node['spend']),
                    '${formatCount(adNumber(node['leads']).round())} leads',
                    if (perLead != null) '${adMoney(perLead)}/lead',
                  ].join(' · '),
                  style: muted,
                ),
                const SizedBox(height: 4),
                _budgetCell(node, theme),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// The daily budget of one campaign or ad set. Meta's refusal is shown here
/// as it said it, in the one red on the screen.
class _BudgetPanel extends StatefulWidget {
  const _BudgetPanel({
    required this.api,
    required this.connectionId,
    required this.node,
    required this.days,
  });

  final ApiClient api;
  final String connectionId;
  final Map<String, dynamic> node;
  final int days;

  @override
  State<_BudgetPanel> createState() => _BudgetPanelState();
}

class _BudgetPanelState extends State<_BudgetPanel> {
  late final TextEditingController _amount = TextEditingController(
    text: ((widget.node['daily_budget'] as num?)?.toDouble() ?? 0)
        .toStringAsFixed(2),
  );
  String? _error;
  bool _saving = false;

  @override
  void dispose() {
    _amount.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    final amount = double.tryParse(_amount.text.replaceAll(r'$', '').trim());
    if (amount == null || amount <= 0) {
      setState(() => _error = 'Enter an amount, like 45.00.');
      return;
    }
    setState(() {
      _saving = true;
      _error = null;
    });
    final response = await widget.api.post('/meta-ads/budget', {
      'connection_id': widget.connectionId,
      'object_id': widget.node['id'],
      'daily_budget': amount,
    });
    if (!mounted) return;
    if (!response.ok) {
      setState(() {
        _saving = false;
        _error = response.message;
      });
      return;
    }
    widget.node['daily_budget'] = amount;
    Navigator.of(context).pop(true);
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final now = formatMoney(
      (((widget.node['daily_budget'] as num?) ?? 0) * 100).round(),
    );
    final spent = formatMoney(
      ((((widget.node['spend'] as num?) ?? 0) / widget.days) * 100).round(),
    );

    return SidePanel(
      title: 'Daily budget · ${widget.node['name']}',
      onClose: () => Navigator.of(context).pop(),
      actions: [
        TextButton(
          onPressed: _saving ? null : () => Navigator.of(context).pop(),
          child: const Text('Cancel'),
        ),
        FilledButton(
          key: const ValueKey('meta-budget-save'),
          onPressed: _saving ? null : _save,
          child: const Text('Save'),
        ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (widget.node['level'] == 'campaign')
            Padding(
              padding: const EdgeInsets.only(bottom: 12),
              child: Text(
                'Applies to the whole campaign, shared by its ad sets.',
                style: theme.textTheme.bodySmall,
              ),
            ),
          TextField(
            key: const ValueKey('meta-budget-amount'),
            controller: _amount,
            autofocus: true,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            decoration: const InputDecoration(
              prefixText: r'$ ',
              labelText: 'Per day',
            ),
            onSubmitted: (_) => _save(),
          ),
          const SizedBox(height: 10),
          Text(
            'Now $now a day · last ${widget.days} '
            '${widget.days == 1 ? 'day' : 'days'} spent $spent a day',
            style: theme.textTheme.bodySmall,
          ),
          if (_error != null) ...[
            const SizedBox(height: 10),
            Text(
              _error!,
              key: const ValueKey('meta-budget-error'),
              style: TextStyle(color: theme.colorScheme.error),
            ),
          ],
        ],
      ),
    );
  }
}
