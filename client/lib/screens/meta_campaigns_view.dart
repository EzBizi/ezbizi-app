import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/job_money.dart';
import '../core/numbers.dart';
import '../core/session.dart';
import '../widgets/ad_schedule.dart';
import '../widgets/empty_state.dart';
import '../widgets/list_search.dart';
import '../widgets/open_destination.dart';
import '../widgets/search_picker.dart';
import '../widgets/side_panel.dart';

/// Meta campaigns, ad sets and ads: what they spent, what came back, and the
/// switch and budget that control them (Jose, 2026-11-04: "we need to be able
/// to control meta campaigns from the app"; design 180, option 1 + phone 4).
///
/// Everything is read live from Meta through `meta-ads/tree`; nothing here is
/// remembered, because a copy is stale the moment somebody pauses a campaign
/// in Ads Manager. A switch posts at once and offers Undo - no dialog - and the
/// tree is re-read quietly after, since pausing a campaign changes what every
/// ad set and ad beneath it reports.
class MetaCampaignsView extends StatefulWidget {
  const MetaCampaignsView({
    super.key,
    required this.api,
    required this.session,
    required this.sourceSwitch,
  });

  final ApiClient api;
  final Session session;

  /// The Google | Meta control, drawn in this view's own filter bar.
  final Widget sourceSwitch;

  @override
  State<MetaCampaignsView> createState() => _MetaCampaignsViewState();
}

const _ranges = <int, String>{
  1: 'Today',
  7: 'Last 7 days',
  30: 'Last 30 days',
  90: 'Last 90 days',
};

class _MetaCampaignsViewState extends State<MetaCampaignsView> {
  List<Map<String, dynamic>>? _accounts;
  String? _account;
  int _days = 30;

  List<Map<String, dynamic>>? _campaigns;

  /// The on/off schedules of this account, by campaign id (design 185).
  Map<String, Map<String, dynamic>> _schedules = const {};
  String? _error;
  bool _loading = false;

  final Set<String> _expanded = <String>{};
  final TextEditingController _search = TextEditingController();
  String _sortBy = 'spend';
  bool _descending = true;

  bool get _canManage =>
      widget.session.me?.can('ads_performance.manage') ?? false;

  @override
  void initState() {
    super.initState();
    _search.addListener(() => setState(() {}));
    _loadAccounts();
  }

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  Future<void> _loadAccounts() async {
    final response = await widget.api.get('/meta-ads/accounts');
    if (!mounted) return;
    setState(() {
      if (!response.ok) {
        _error = response.message;
        _accounts = const [];
        return;
      }
      _accounts = List<Map<String, dynamic>>.from(
        (response.body['data'] as List? ?? const []).cast<Map>(),
      );
      if (_accounts!.isNotEmpty) {
        _account = _accounts!.first['connection_id'] as String?;
      }
    });
    if (_account != null) await _loadTree();
  }

  static String _d(DateTime x) =>
      '${x.year.toString().padLeft(4, '0')}-'
      '${x.month.toString().padLeft(2, '0')}-'
      '${x.day.toString().padLeft(2, '0')}';

  /// The tree for the account and range. [quiet] keeps the rows on screen
  /// while it is asked again, after a switch or a budget change.
  Future<void> _loadTree({bool quiet = false}) async {
    final id = _account;
    if (id == null) return;
    final days = _days;
    if (!quiet) {
      setState(() {
        _loading = true;
        _error = null;
      });
    }
    final to = DateTime.now();
    final response = await widget.api.get('/meta-ads/tree', {
      'connection_id': id,
      'from': _d(to.subtract(Duration(days: days - 1))),
      'to': _d(to),
    });
    if (!mounted || _account != id || _days != days) return;
    setState(() {
      _loading = false;
      if (!response.ok) {
        if (!quiet) _error = response.message;
        return;
      }
      _campaigns = List<Map<String, dynamic>>.from(
        ((response.body['data'] as Map?)?['campaigns'] as List? ?? const [])
            .cast<Map>(),
      );
    });
    await _loadSchedules();
  }

  Future<void> _loadSchedules() async {
    final id = _account;
    if (id == null) return;
    final response = await widget.api.get('/ad-schedules', {
      'connection_id': id,
    });
    if (!mounted || _account != id || !response.ok) return;
    setState(
      () => _schedules = {
        for (final row
            in (response.body['data'] as List? ?? const []).cast<Map>())
          '${row['object_id']}': Map<String, dynamic>.from(row),
      },
    );
  }

  Future<void> _editSchedule(Map<String, dynamic> node) async {
    final changed = await editAdSchedule(
      context,
      api: widget.api,
      connectionId: _account!,
      objectId: '${node['id']}',
      objectName: '${node['name']}',
      schedule: _schedules['${node['id']}'],
      canManage: _canManage,
    );
    if (changed && mounted) _loadSchedules();
  }

  /// The clock chip, on campaigns only: the level a business schedules.
  Widget _scheduleCell(Map<String, dynamic> node) => node['level'] == 'campaign'
      ? Align(
          alignment: AlignmentDirectional.centerStart,
          child: AdScheduleChip(
            key: ValueKey('meta-schedule-${node['id']}'),
            schedule: _schedules['${node['id']}'],
            canManage: _canManage,
            onTap: () => _editSchedule(node),
          ),
        )
      : const SizedBox.shrink();

  // ── Figures ────────────────────────────────────────────────────────────

  static double _num(dynamic v) => (v as num?)?.toDouble() ?? 0;

  static String _money(dynamic v) => formatMoney((_num(v) * 100).round());

  static List<Map<String, dynamic>> _children(Map<String, dynamic> node) =>
      List<Map<String, dynamic>>.from(
        (node['children'] as List? ?? const []).cast<Map>(),
      );

  List<Map<String, dynamic>> _sorted(List<Map<String, dynamic>> rows) {
    final sorted = [...rows];
    sorted.sort((a, b) {
      final x = _num(a[_sortBy]);
      final y = _num(b[_sortBy]);
      return _descending ? y.compareTo(x) : x.compareTo(y);
    });
    return sorted;
  }

  String get _needle => _search.text.trim().toLowerCase();

  bool _matches(Map<String, dynamic> node) =>
      _needle.isEmpty ||
      '${node['name']}'.toLowerCase().contains(_needle) ||
      _children(node).any(_matches);

  bool _open(Map<String, dynamic> node) =>
      _expanded.contains(node['id']) ||
      (_needle.isNotEmpty &&
          !'${node['name']}'.toLowerCase().contains(_needle) &&
          _children(node).any(_matches));

  /// Every visible row, depth-first, with its depth.
  List<(Map<String, dynamic>, int)> _rows() {
    final out = <(Map<String, dynamic>, int)>[];
    void walk(List<Map<String, dynamic>> nodes, int depth) {
      for (final node in _sorted(nodes)) {
        if (!_matches(node)) continue;
        out.add((node, depth));
        if (_open(node)) walk(_children(node), depth + 1);
      }
    }

    walk(_campaigns ?? const [], 0);
    return out;
  }

  // ── Changes ────────────────────────────────────────────────────────────

  Future<void> _toggle(Map<String, dynamic> node, bool on) async {
    final before = node['status'];
    final status = on ? 'ACTIVE' : 'PAUSED';
    setState(() => node['status'] = status);

    final response = await widget.api.post('/meta-ads/status', {
      'connection_id': _account,
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
    _loadTree(quiet: true);
  }

  Future<void> _editBudget(Map<String, dynamic> node) async {
    final saved = await showSidePanel<bool>(
      context,
      builder: (_) => _BudgetPanel(
        api: widget.api,
        connectionId: _account!,
        node: node,
        days: _days,
      ),
    );
    if (saved == true && mounted) _loadTree(quiet: true);
  }

  // ── Drawing ────────────────────────────────────────────────────────────

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final phone = MediaQuery.sizeOf(context).width < 600;

    if (_accounts == null) {
      return Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          widget.sourceSwitch,
          const Expanded(child: Center(child: CircularProgressIndicator())),
        ],
      );
    }
    if (_accounts!.isEmpty) {
      final open = OpenDestination.of(context);
      return Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          widget.sourceSwitch,
          Expanded(
            child: EmptyState(
              key: const ValueKey('meta-ads-empty'),
              icon: Icons.campaign_outlined,
              title: 'No Meta ad account connected',
              message:
                  _error ??
                  'Connect Facebook in Settings › Integrations to see and '
                      'control your Meta campaigns here.',
              actionLabel: open == null ? null : 'Open Integrations',
              actionIcon: Icons.open_in_new,
              onAction: open == null ? null : () => open('settings'),
            ),
          ),
        ],
      );
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _filters(phone),
        const SizedBox(height: 12),
        if (_loading)
          const Expanded(child: Center(child: CircularProgressIndicator()))
        else if (_error != null)
          Expanded(
            child: Center(
              child: Text(_error!, style: theme.textTheme.bodyMedium),
            ),
          )
        else ...[
          _kpis(phone),
          const SizedBox(height: 12),
          Expanded(child: phone ? _cards(theme) : _table(theme)),
        ],
      ],
    );
  }

  Widget _filters(bool phone) {
    return Wrap(
      spacing: 10,
      runSpacing: 10,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        widget.sourceSwitch,
        if (_accounts!.length > 1)
          SizedBox(
            width: 240,
            child: SearchPickerField<String>(
              key: const ValueKey('meta-ads-account'),
              value: _account,
              isDense: true,
              options: [
                for (final a in _accounts!)
                  SearchOption(
                    value: '${a['connection_id']}',
                    label: '${a['name']}',
                    detail: '${a['account_id']}',
                  ),
              ],
              onChanged: (id) {
                if (id == null) return;
                setState(() {
                  _account = id;
                  _expanded.clear();
                });
                _loadTree();
              },
            ),
          ),
        PopupMenuButton<int>(
          key: const ValueKey('meta-ads-range'),
          initialValue: _days,
          onSelected: (days) {
            setState(() => _days = days);
            _loadTree();
          },
          itemBuilder: (_) => [
            for (final e in _ranges.entries)
              PopupMenuItem(value: e.key, child: Text(e.value)),
          ],
          child: Chip(
            label: Text(_ranges[_days]!),
            avatar: const Icon(Icons.date_range, size: 18),
          ),
        ),
        ListSearch(
          key: const ValueKey('meta-ads-search'),
          controller: _search,
          hint: 'Search campaigns',
          width: 260,
          onClear: _search.clear,
        ),
      ],
    );
  }

  Widget _kpis(bool phone) {
    final campaigns = _campaigns ?? const [];
    final spend = campaigns.fold<double>(0, (s, c) => s + _num(c['spend']));
    final leads = campaigns.fold<double>(0, (s, c) => s + _num(c['leads']));
    final clicks = campaigns.fold<double>(0, (s, c) => s + _num(c['clicks']));
    final tiles = {
      'Spend': _money(spend),
      'Leads': formatCount(leads.round()),
      'Cost per lead': leads > 0 ? _money(spend / leads) : '—',
      'Clicks': formatCount(clicks.round()),
    };
    final theme = Theme.of(context);

    return LayoutBuilder(
      builder: (context, box) {
        final width = phone ? (box.maxWidth - 10) / 2 : (box.maxWidth - 30) / 4;
        return Wrap(
          spacing: 10,
          runSpacing: 10,
          children: [
            for (final e in tiles.entries)
              SizedBox(
                width: width,
                child: Card(
                  margin: EdgeInsets.zero,
                  child: Padding(
                    padding: const EdgeInsets.all(12),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(e.key, style: theme.textTheme.bodySmall),
                        const SizedBox(height: 4),
                        Text(
                          e.value,
                          key: ValueKey('meta-kpi-${e.key}'),
                          style: theme.textTheme.titleLarge,
                        ),
                      ],
                    ),
                  ),
                ),
              ),
          ],
        );
      },
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
    if (!_canManage) {
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
      if (!_canManage) return Text(_money(daily));
      return InkWell(
        key: ValueKey('meta-budget-${node['id']}'),
        onTap: () => _editBudget(node),
        child: Text(
          '${_money(daily)} ✎',
          style: TextStyle(color: theme.colorScheme.primary),
        ),
      );
    }
    if (node['lifetime_budget'] != null) {
      return Text('${_money(node['lifetime_budget'])} lifetime', style: muted);
    }
    return Text(switch (node['level']) {
      'campaign' => 'Ad set budgets',
      'adset' => 'Campaign budget',
      _ => '—',
    }, style: muted);
  }

  static const _columns = <(String, String?, double)>[
    ('On', null, 190),
    ('Schedule', null, 250),
    ('Budget / day', 'daily_budget', 130),
    ('Spend', 'spend', 100),
    ('Leads', 'leads', 70),
    ('Clicks', 'clicks', 80),
    ('Per lead', 'cost_per_lead', 90),
  ];

  Widget _table(ThemeData theme) {
    final rows = _rows();
    final head = theme.textTheme.labelSmall;

    Widget header() => Row(
      children: [
        Expanded(child: Text('CAMPAIGN · AD SET · AD', style: head)),
        for (final (label, key, width) in _columns)
          SizedBox(
            width: width,
            child: key == null
                ? Text(label.toUpperCase(), style: head)
                : InkWell(
                    key: ValueKey('meta-sort-$key'),
                    onTap: () => setState(() {
                      _descending = _sortBy == key ? !_descending : true;
                      _sortBy = key;
                    }),
                    child: Text(
                      '${label.toUpperCase()}'
                      '${_sortBy == key ? (_descending ? ' ↓' : ' ↑') : ''}',
                      style: head,
                      textAlign: label == 'Budget / day' ? null : TextAlign.end,
                    ),
                  ),
          ),
      ],
    );

    Widget row(Map<String, dynamic> node, int depth) {
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
                      ? _money(node[key])
                      : formatCount(_num(node[key]).round()),
                  textAlign: TextAlign.end,
                ),
              ),
          ],
        ),
      );
    }

    return Card(
      margin: EdgeInsets.zero,
      clipBehavior: Clip.antiAlias,
      child: LayoutBuilder(
        builder: (context, box) {
          final width = box.maxWidth < 1210 ? 1210.0 : box.maxWidth;
          return SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            child: SizedBox(
              width: width,
              child: Column(
                children: [
                  Padding(
                    padding: const EdgeInsets.fromLTRB(40, 10, 12, 8),
                    child: header(),
                  ),
                  const Divider(height: 1),
                  Expanded(
                    child: rows.isEmpty
                        ? Center(
                            child: Text(
                              _needle.isEmpty
                                  ? 'This ad account has no campaigns.'
                                  : 'Nothing matches "${_search.text}".',
                            ),
                          )
                        : ListView.separated(
                            key: const ValueKey('meta-ads-table'),
                            itemCount: rows.length,
                            separatorBuilder: (_, _) =>
                                const Divider(height: 1),
                            itemBuilder: (_, i) => row(rows[i].$1, rows[i].$2),
                          ),
                  ),
                ],
              ),
            ),
          );
        },
      ),
    );
  }

  Widget _cards(ThemeData theme) {
    final rows = _rows();
    final muted = theme.textTheme.bodySmall;
    if (rows.isEmpty) {
      return Center(
        child: Text(
          _needle.isEmpty
              ? 'This ad account has no campaigns.'
              : 'Nothing matches "${_search.text}".',
        ),
      );
    }
    return ListView.builder(
      key: const ValueKey('meta-ads-cards'),
      itemCount: rows.length,
      itemBuilder: (_, i) {
        final (node, depth) = rows[i];
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
                        if (_canManage)
                          _onCell(node, theme)
                        else
                          Text(_why(node) ?? 'Active', style: muted),
                      ],
                    ),
                    if (node['level'] == 'campaign') _scheduleCell(node),
                    Text(
                      [
                        _money(node['spend']),
                        '${formatCount(_num(node['leads']).round())} leads',
                        if (perLead != null) '${_money(perLead)}/lead',
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
      },
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
