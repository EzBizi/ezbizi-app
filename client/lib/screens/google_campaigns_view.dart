import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/job_money.dart';
import '../core/numbers.dart';
import '../core/session.dart';
import '../widgets/ad_change_confirm.dart';
import '../widgets/ad_schedule.dart';
import '../widgets/empty_state.dart';
import '../widgets/list_search.dart';
import '../widgets/open_destination.dart';
import '../widgets/search_picker.dart';
import '../widgets/side_panel.dart';

/// Marketing › Ads › Google (Jose, 2026-11-09): run Google campaigns the way
/// MetaCampaignsView runs Meta's - the same filter bar, tiles and table - with
/// a switch, an on/off schedule, the daily budget and the bid target per
/// campaign. Campaign level only: Google's rules for tools that show ad
/// groups and ads are a different, larger obligation (see ads_performance).
///
/// Read live from Google every time; every change is confirmed first, then
/// sent, then the list is read again.
class GoogleCampaignsView extends StatefulWidget {
  const GoogleCampaignsView({
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
  State<GoogleCampaignsView> createState() => _GoogleCampaignsViewState();
}

const _ranges = <int, String>{
  1: 'Today',
  7: 'Last 7 days',
  30: 'Last 30 days',
  90: 'Last 90 days',
};

class _GoogleCampaignsViewState extends State<GoogleCampaignsView> {
  List<Map<String, dynamic>>? _accounts;
  String? _account;
  int _days = 30;

  List<Map<String, dynamic>>? _campaigns;
  Map<String, Map<String, dynamic>> _schedules = const {};
  String? _error;
  bool _loading = false;

  final TextEditingController _search = TextEditingController();
  String _sortBy = 'spend';
  bool _descending = true;

  bool get _canManage => widget.session.me?.can('ads_manager.manage') ?? false;

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
    final response = await widget.api.get('/ads/accounts');
    if (!mounted) return;
    setState(() {
      if (!response.ok) {
        _error = response.message;
        _accounts = const [];
        return;
      }
      // A manager holds accounts, not campaigns.
      _accounts = [
        for (final a
            in (response.body['data'] as List? ?? const []).cast<Map>())
          if (a['is_manager'] != true) Map<String, dynamic>.from(a),
      ];
      if (_accounts!.isNotEmpty) {
        _account = _accounts!.first['connection_id'] as String?;
      }
    });
    if (_account != null) await _load();
  }

  static String _d(DateTime x) =>
      '${x.year.toString().padLeft(4, '0')}-'
      '${x.month.toString().padLeft(2, '0')}-'
      '${x.day.toString().padLeft(2, '0')}';

  Future<void> _load({bool quiet = false}) async {
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
    final response = await widget.api.get('/ads-manager/google/campaigns', {
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

  // ── Figures ────────────────────────────────────────────────────────────

  static double _num(dynamic v) => (v as num?)?.toDouble() ?? 0;

  static String _money(dynamic v) => formatMoney((_num(v) * 100).round());

  String get _needle => _search.text.trim().toLowerCase();

  List<Map<String, dynamic>> _rows() {
    final rows = [
      for (final c in _campaigns ?? const <Map<String, dynamic>>[])
        if (_needle.isEmpty || '${c['name']} ${_title(c)}'.toLowerCase().contains(_needle))
          c,
    ];
    rows.sort((a, b) {
      final x = _num(a[_sortBy]);
      final y = _num(b[_sortBy]);
      return _descending ? y.compareTo(x) : x.compareTo(y);
    });
    return rows;
  }

  static bool _lsa(Map<String, dynamic> c) => c['type'] == 'LOCAL_SERVICES';

  /// Google names a Local Services campaign "LocalServicesCampaign:
  /// SystemGenerated:000630b3eb55"; shown as "Local Services · (account)"
  /// (UI/UX audit 2026-11-13 §9.11). The raw name stays in the tooltip.
  String _title(Map<String, dynamic> c) {
    final name = '${c['name']}';
    if (!name.startsWith('LocalServicesCampaign:')) return name;
    final account = _accounts
        ?.firstWhere((a) => a['connection_id'] == _account, orElse: () => const {})['name'];
    return account == null ? 'Local Services' : 'Local Services · $account';
  }

  /// A Local Services budget is set per WEEK; Google's API reports it as
  /// DAILY = week / 7 (measured 2026-11-13: Columbus $28,571.43 = $200,000
  /// a week). Shown per day, it read as a day's budget above a month's spend.
  /// [lsaWeekly] undoes the cents Google rounded the division to.
  static String _budgetText(Map<String, dynamic> c) => _lsa(c)
      ? '${_money(lsaWeekly(_num(c['daily_budget'])))} / week'
      : _money(c['daily_budget']);

  /// "Limited", "Not eligible"… when it is on and still not fully showing.
  static String? _why(Map<String, dynamic> c) {
    if (c['status'] == 'PAUSED') return 'Paused';
    final p = '${c['primary_status'] ?? ''}';
    if (p.isEmpty || p == 'ELIGIBLE') return null;
    final word = p.toLowerCase().replaceAll('_', ' ');
    return word[0].toUpperCase() + word.substring(1);
  }

  // ── Changes ────────────────────────────────────────────────────────────

  Future<void> _toggle(Map<String, dynamic> c, bool on) async {
    final ok = await confirmAdChange(
      context,
      title: on ? 'Turn on ${_title(c)}?' : 'Pause ${_title(c)}?',
      change: on
          ? 'Google starts showing this campaign again.'
          : 'Google stops showing this campaign until it is turned on.',
      detail: _lsa(c)
          ? 'A Local Services campaign: the phone stops or starts ringing for this branch.'
          : null,
    );
    if (!ok || !mounted) return;
    final response = await widget.api.post('/ads-manager/google/status', {
      'connection_id': _account,
      'campaign_id': c['id'],
      'status': on ? 'ENABLED' : 'PAUSED',
    });
    if (!mounted) return;
    ScaffoldMessenger.of(context)
      ..hideCurrentSnackBar()
      ..showSnackBar(
        SnackBar(
          content: Text(
            response.ok
                ? '${_title(c)} ${on ? 'on' : 'paused'}'
                : response.message,
          ),
        ),
      );
    if (response.ok) _load(quiet: true);
  }

  Future<void> _edit(Map<String, dynamic> c, {required bool target}) async {
    final saved = await showSidePanel<bool>(
      context,
      builder: (_) => _AmountPanel(
        api: widget.api,
        connectionId: _account!,
        campaign: {...c, 'name': _title(c)},
        target: target,
        days: _days,
      ),
    );
    if (saved == true && mounted) _load(quiet: true);
  }

  Future<void> _editSchedule(Map<String, dynamic> c) async {
    final changed = await editAdSchedule(
      context,
      api: widget.api,
      connectionId: _account!,
      objectId: '${c['id']}',
      objectName: _title(c),
      schedule: _schedules['${c['id']}'],
      canManage: _canManage,
    );
    if (changed && mounted) _loadSchedules();
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
              key: const ValueKey('google-ads-empty'),
              icon: Icons.campaign_outlined,
              title: 'No Google Ads account connected',
              message:
                  _error ??
                  'Connect Google Ads in Settings › Integrations to see and '
                      'control your Google campaigns here.',
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
        _filters(),
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

  Widget _filters() {
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
              key: const ValueKey('google-ads-account'),
              value: _account,
              isDense: true,
              options: [
                for (final a in _accounts!)
                  SearchOption(
                    value: '${a['connection_id']}',
                    label: '${a['name']}',
                    detail: '${a['customer_id']}',
                  ),
              ],
              onChanged: (id) {
                if (id == null) return;
                setState(() => _account = id);
                _load();
              },
            ),
          ),
        PopupMenuButton<int>(
          key: const ValueKey('google-ads-range'),
          initialValue: _days,
          onSelected: (days) {
            setState(() => _days = days);
            _load();
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
          key: const ValueKey('google-ads-search'),
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
    final conv = campaigns.fold<double>(
      0,
      (s, c) => s + _num(c['conversions']),
    );
    final clicks = campaigns.fold<double>(0, (s, c) => s + _num(c['clicks']));
    final tiles = {
      'Spend': _money(spend),
      'Conversions': formatCount(conv.round()),
      'Cost per conversion': conv > 0 ? _money(spend / conv) : '—',
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
                          key: ValueKey('google-kpi-${e.key}'),
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

  Widget _onCell(Map<String, dynamic> c, ThemeData theme) {
    final why = _why(c);
    final muted = theme.textTheme.bodySmall;
    if (!_canManage) {
      return Text(why ?? 'Active', style: why == null ? null : muted);
    }
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Switch(
          key: ValueKey('google-on-${c['id']}'),
          value: c['status'] == 'ENABLED',
          onChanged: (on) => _toggle(c, on),
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

  Widget _scheduleCell(Map<String, dynamic> c) => Align(
    alignment: AlignmentDirectional.centerStart,
    child: AdScheduleChip(
      key: ValueKey('google-schedule-${c['id']}'),
      schedule: _schedules['${c['id']}'],
      canManage: _canManage,
      onTap: () => _editSchedule(c),
    ),
  );

  Widget _link(String key, String text, VoidCallback onTap, ThemeData theme) =>
      InkWell(
        key: ValueKey(key),
        onTap: onTap,
        child: Text(
          '$text ✎',
          style: TextStyle(color: theme.colorScheme.primary),
        ),
      );

  Widget _budgetCell(Map<String, dynamic> c, ThemeData theme) {
    final daily = c['daily_budget'];
    if (daily == null) return Text('—', style: theme.textTheme.bodySmall);
    if (c['shared_budget'] == true) {
      return Text('${_budgetText(c)} shared', style: theme.textTheme.bodySmall);
    }
    if (!_canManage) return Text(_budgetText(c));
    return _link(
      'google-budget-${c['id']}',
      _budgetText(c),
      () => _edit(c, target: false),
      theme,
    );
  }

  static String _bidding(String type) => switch (type) {
    'MANUAL_CPA' => 'Per lead',
    'MANUAL_CPC' => 'Manual CPC',
    'MAXIMIZE_CLICKS' || 'TARGET_SPEND' => 'Max clicks',
    'TARGET_IMPRESSION_SHARE' => 'Impression share',
    '' => '—',
    _ => type.toLowerCase().replaceAll('_', ' '),
  };

  static String _targetText(Map<String, dynamic> c) {
    final t = c['target'];
    if (t == null) return 'No target';
    return c['target_kind'] == 'roas'
        ? '${(_num(t) * 100).round()}% ROAS'
        : '${_money(t)} CPA';
  }

  Widget _targetCell(Map<String, dynamic> c, ThemeData theme) {
    final muted = theme.textTheme.bodySmall;
    if (c['target_kind'] == null) {
      return Text(_bidding('${c['bidding'] ?? ''}'), style: muted);
    }
    if (c['portfolio'] == true) {
      return Text('${_targetText(c)} · shared', style: muted);
    }
    if (!_canManage) return Text(_targetText(c));
    return _link(
      'google-target-${c['id']}',
      _targetText(c),
      () => _edit(c, target: true),
      theme,
    );
  }

  static const _columns = <(String, String?, double)>[
    ('On', null, 190),
    ('Schedule', null, 250),
    ('Budget / day', 'daily_budget', 130),
    ('Bid target', null, 140),
    ('Spend', 'spend', 100),
    ('Conv.', 'conversions', 70),
    ('Clicks', 'clicks', 80),
    ('Per conv.', 'cost_per_conversion', 90),
  ];

  Widget _name(Map<String, dynamic> c, ThemeData theme, {int lines = 1}) => Row(
    children: [
      Flexible(
        child: Tooltip(
          message: '${c['name']}',
          child: Text(
            _title(c),
            key: ValueKey('google-name-${c['id']}'),
            maxLines: lines,
            overflow: TextOverflow.ellipsis,
            style: const TextStyle(fontWeight: FontWeight.w600),
          ),
        ),
      ),
      if (_lsa(c) && _title(c) == '${c['name']}') ...[
        const SizedBox(width: 6),
        Text('Local Services', style: theme.textTheme.labelSmall),
      ],
    ],
  );

  Widget _table(ThemeData theme) {
    final rows = _rows();
    final head = theme.textTheme.labelSmall;

    Widget header() => Row(
      children: [
        Expanded(child: Text('CAMPAIGN', style: head)),
        for (final (label, key, width) in _columns)
          SizedBox(
            width: width,
            child: key == null
                ? Text(label.toUpperCase(), style: head)
                : InkWell(
                    key: ValueKey('google-sort-$key'),
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

    Widget row(Map<String, dynamic> c) => Padding(
      key: ValueKey('google-row-${c['id']}'),
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
      child: Row(
        children: [
          Expanded(child: _name(c, theme)),
          SizedBox(width: 190, child: _onCell(c, theme)),
          SizedBox(width: 250, child: _scheduleCell(c)),
          SizedBox(width: 130, child: _budgetCell(c, theme)),
          SizedBox(width: 140, child: _targetCell(c, theme)),
          for (final (key, money, width) in const [
            ('spend', true, 100.0),
            ('conversions', false, 70.0),
            ('clicks', false, 80.0),
            ('cost_per_conversion', true, 90.0),
          ])
            SizedBox(
              width: width,
              child: Text(
                c[key] == null
                    ? '—'
                    : money
                    ? _money(c[key])
                    : formatCount(_num(c[key]).round()),
                textAlign: TextAlign.end,
              ),
            ),
        ],
      ),
    );

    return Card(
      margin: EdgeInsets.zero,
      clipBehavior: Clip.antiAlias,
      child: LayoutBuilder(
        builder: (context, box) {
          final width = box.maxWidth < 1350 ? 1350.0 : box.maxWidth;
          return SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            child: SizedBox(
              width: width,
              child: Column(
                children: [
                  Padding(
                    padding: const EdgeInsets.fromLTRB(12, 10, 12, 8),
                    child: header(),
                  ),
                  const Divider(height: 1),
                  Expanded(
                    child: rows.isEmpty
                        ? Center(child: Text(_emptyText))
                        : ListView.separated(
                            key: const ValueKey('google-ads-table'),
                            itemCount: rows.length,
                            separatorBuilder: (_, _) =>
                                const Divider(height: 1),
                            itemBuilder: (_, i) => row(rows[i]),
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

  String get _emptyText => _needle.isEmpty
      ? 'This Google Ads account has no campaigns.'
      : 'Nothing matches "${_search.text}".';

  Widget _cards(ThemeData theme) {
    final rows = _rows();
    final muted = theme.textTheme.bodySmall;
    if (rows.isEmpty) return Center(child: Text(_emptyText));
    return ListView.builder(
      key: const ValueKey('google-ads-cards'),
      itemCount: rows.length,
      itemBuilder: (_, i) {
        final c = rows[i];
        final per = c['cost_per_conversion'];
        return Padding(
          padding: const EdgeInsets.only(bottom: 8),
          child: Card(
            key: ValueKey('google-card-${c['id']}'),
            margin: EdgeInsets.zero,
            child: Padding(
              padding: const EdgeInsets.fromLTRB(12, 8, 8, 10),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Expanded(child: _name(c, theme, lines: 2)),
                      _onCell(c, theme),
                    ],
                  ),
                  _scheduleCell(c),
                  Text(
                    [
                      _money(c['spend']),
                      '${formatCount(_num(c['conversions']).round())} conv.',
                      if (per != null) '${_money(per)}/conv.',
                    ].join(' · '),
                    style: muted,
                  ),
                  const SizedBox(height: 4),
                  Wrap(
                    spacing: 16,
                    children: [_budgetCell(c, theme), _targetCell(c, theme)],
                  ),
                ],
              ),
            ),
          ),
        );
      },
    );
  }
}

/// The daily budget, or the bid target, of one campaign. Save is the
/// confirmation; Google's refusal is shown as it said it.
class _AmountPanel extends StatefulWidget {
  const _AmountPanel({
    required this.api,
    required this.connectionId,
    required this.campaign,
    required this.target,
    required this.days,
  });

  final ApiClient api;
  final String connectionId;
  final Map<String, dynamic> campaign;
  final bool target;
  final int days;

  @override
  State<_AmountPanel> createState() => _AmountPanelState();
}

class _AmountPanelState extends State<_AmountPanel> {
  late final bool _roas =
      widget.target && widget.campaign['target_kind'] == 'roas';
  // A Local Services budget is typed per week, as it is set in LSA.
  late final bool _weekly =
      !widget.target && widget.campaign['type'] == 'LOCAL_SERVICES';
  late final TextEditingController _amount = TextEditingController(
    text: _start(),
  );
  String? _error;
  bool _saving = false;

  String _start() {
    final v =
        (widget.campaign[widget.target ? 'target' : 'daily_budget'] as num?)
            ?.toDouble();
    if (v == null) return '';
    if (_weekly) return lsaWeekly(v).toStringAsFixed(2);
    return _roas ? (v * 100).round().toString() : v.toStringAsFixed(2);
  }

  @override
  void dispose() {
    _amount.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    final typed = double.tryParse(
      _amount.text.replaceAll(RegExp(r'[$%,]'), '').trim(),
    );
    if (typed == null || typed <= 0) {
      setState(
        () => _error = _roas
            ? 'Enter a percentage, like 350.'
            : 'Enter an amount, like 45.00.',
      );
      return;
    }
    setState(() {
      _saving = true;
      _error = null;
    });
    final response = await widget.api.post(
      widget.target
          ? '/ads-manager/google/target'
          : '/ads-manager/google/budget',
      {
        'connection_id': widget.connectionId,
        'campaign_id': widget.campaign['id'],
        if (widget.target)
          'target': _roas ? typed / 100 : typed
        else
          'daily_budget': _weekly ? typed / 7 : typed,
      },
    );
    if (!mounted) return;
    if (!response.ok) {
      setState(() {
        _saving = false;
        _error = response.message;
      });
      return;
    }
    Navigator.of(context).pop(true);
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final c = widget.campaign;
    final spent = formatMoney(
      ((((c['spend'] as num?) ?? 0) / widget.days) * 100).round(),
    );

    return SidePanel(
      title: '${widget.target ? 'Bid target' : _weekly ? 'Weekly budget' : 'Daily budget'} · ${c['name']}',
      onClose: () => Navigator.of(context).pop(),
      actions: [
        TextButton(
          onPressed: _saving ? null : () => Navigator.of(context).pop(),
          child: const Text('Cancel'),
        ),
        FilledButton(
          key: const ValueKey('google-amount-save'),
          onPressed: _saving ? null : _save,
          child: const Text('Save to Google'),
        ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          TextField(
            key: const ValueKey('google-amount'),
            controller: _amount,
            autofocus: true,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            decoration: InputDecoration(
              prefixText: _roas ? null : r'$ ',
              suffixText: _roas ? '%' : null,
              labelText: widget.target
                  ? (_roas
                        ? 'Target return on ad spend'
                        : 'Target cost per conversion')
                  : _weekly
                  ? 'Per week'
                  : 'Per day',
            ),
            onSubmitted: (_) => _save(),
          ),
          const SizedBox(height: 10),
          Text(
            widget.target
                ? 'Google bids to reach this on average; it changes the campaign at once.'
                : _weekly
                ? 'Last ${widget.days} ${widget.days == 1 ? 'day' : 'days'} spent $spent a day. '
                      'Local Services budgets are weekly, as in Local Services Ads.'
                : 'Last ${widget.days} ${widget.days == 1 ? 'day' : 'days'} spent $spent a day. '
                      'Google may spend up to twice this on a busy day.',
            style: theme.textTheme.bodySmall,
          ),
          if (_error != null) ...[
            const SizedBox(height: 10),
            Text(
              _error!,
              key: const ValueKey('google-amount-error'),
              style: TextStyle(color: theme.colorScheme.error),
            ),
          ],
        ],
      ),
    );
  }
}

/// A Local Services weekly budget from Google's per-day figure (week / 7,
/// rounded to cents): $857.14 is $6,000, not $5,999.98. Whole dollars when
/// within the rounding Google did, else to the cent.
double lsaWeekly(num daily) {
  final week = daily * 7;
  final whole = week.roundToDouble();
  return (week - whole).abs() <= 0.035 ? whole : (week * 100).round() / 100;
}
