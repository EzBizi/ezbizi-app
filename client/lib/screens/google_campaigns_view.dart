import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/job_money.dart';
import '../core/numbers.dart';
import '../widgets/ad_change_confirm.dart';
import '../widgets/side_panel.dart';
import 'ad_campaigns_shell.dart';

/// Marketing › Ads › Google (Jose, 2026-11-09): run Google campaigns the way
/// MetaCampaignsView runs Meta's - the same filter bar, tiles and table - with
/// a switch, an on/off schedule, the daily budget and the bid target per
/// campaign. Campaign level only: Google's rules for tools that show ad
/// groups and ads are a different, larger obligation (see ads_performance).
///
/// Read live from Google every time; every change is confirmed first, then
/// sent, then the list is read again.
class GoogleCampaignsView extends AdCampaignsView {
  const GoogleCampaignsView({
    super.key,
    required super.api,
    required super.session,
    required super.sourceSwitch,
  });

  @override
  State<GoogleCampaignsView> createState() => _GoogleCampaignsViewState();
}

class _GoogleCampaignsViewState
    extends AdCampaignsState<GoogleCampaignsView, Map<String, dynamic>> {
  @override
  String get keyPrefix => 'google';
  @override
  Key get rangeKey => const ValueKey('google-ads-range');
  @override
  Key get tableKey => const ValueKey('google-ads-table');
  @override
  String get accountsPath => '/ads/accounts';
  @override
  String get campaignsPath => '/ads-manager/google/campaigns';
  @override
  String get accountNumberField => 'customer_id';

  // A manager holds accounts, not campaigns.
  @override
  bool keepAccount(Map account) => account['is_manager'] != true;

  /// Managers hold no campaigns, so they are not offered; each entry says it
  /// is a campaign account, as two can share a name with their manager
  /// ("A1 Chimney - Chicago", Rodrigo 01a1ef57).
  @override
  String accountDetail(Map account) =>
      'Campaign account · ${googleCustomerId('${account['customer_id']}')}';

  @override
  bool isActive(Map<String, dynamic> row) => row['status'] == 'ENABLED';

  @override
  Map<String, dynamic> mapOf(Map<String, dynamic> row) => row;

  @override
  String get emptyTitle => 'No Google Ads account connected';
  @override
  String get emptyMessage =>
      'Connect Google Ads in Settings › Integrations to see and '
      'control your Google campaigns here.';
  @override
  String get noCampaignsText => 'This Google Ads account has no campaigns.';
  @override
  String get resultsField => 'conversions';
  @override
  String get resultsLabel => 'Conversions';
  @override
  String get costPerLabel => 'Cost per conversion';
  @override
  String get tableHeading => 'CAMPAIGN';
  @override
  double get tableMinWidth => 1350;
  @override
  double get headerStart => 12;

  // ── Figures ────────────────────────────────────────────────────────────

  @override
  List<Map<String, dynamic>> visibleRows() => sorted([
    for (final c in campaigns ?? const <Map<String, dynamic>>[])
      if (needle.isEmpty ||
          '${c['name']} ${titleOf(c)}'.toLowerCase().contains(needle))
        c,
  ]);

  static bool _lsa(Map<String, dynamic> c) => c['type'] == 'LOCAL_SERVICES';

  /// Google names a Local Services campaign "LocalServicesCampaign:
  /// SystemGenerated:000630b3eb55"; shown as "Local Services · (account)"
  /// (UI/UX audit 2026-11-13 §9.11). The raw name stays in the tooltip.
  @override
  String titleOf(Map<String, dynamic> c) {
    final name = '${c['name']}';
    if (!name.startsWith('LocalServicesCampaign:')) return name;
    final owner =
        c['account'] ??
        accounts?.firstWhere(
          (a) => a['connection_id'] == connectionOf(c),
          orElse: () => const {},
        )['name'];
    return owner == null ? 'Local Services' : 'Local Services · $owner';
  }

  /// A Local Services budget is set per WEEK; Google's API reports it as
  /// DAILY = week / 7 (measured 2026-11-13: Columbus $28,571.43 = $200,000
  /// a week). Shown per day, it read as a day's budget above a month's spend.
  /// [lsaWeekly] undoes the cents Google rounded the division to.
  static String _budgetText(Map<String, dynamic> c) => _lsa(c)
      ? '${adMoney(lsaWeekly(adNumber(c['daily_budget'])))} / week'
      : '${adMoney(c['daily_budget'])} / day';

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
      title: on ? 'Turn on ${titleOf(c)}?' : 'Pause ${titleOf(c)}?',
      change: on
          ? 'Google starts showing this campaign again.'
          : 'Google stops showing this campaign until it is turned on.',
      detail: _lsa(c)
          ? 'A Local Services campaign: the phone stops or starts ringing for this branch.'
          : null,
    );
    if (!ok || !mounted) return;
    final response = await widget.api.post('/ads-manager/google/status', {
      'connection_id': connectionOf(c),
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
                ? '${titleOf(c)} ${on ? 'on' : 'paused'}'
                : response.message,
          ),
        ),
      );
    if (response.ok) load(quiet: true);
  }

  Future<void> _edit(Map<String, dynamic> c, {required bool target}) async {
    final sent = await showSidePanel<double>(
      context,
      builder: (_) => _AmountPanel(
        api: widget.api,
        connectionId: connectionOf(c),
        campaign: {...c, 'name': titleOf(c)},
        target: target,
        days: days,
      ),
    );
    if (sent == null || !mounted) return;
    // Google's own read-back, not our request (Rodrigo, 01a1ef57: "provider-
    // confirmed or pending").
    await load(quiet: true);
    if (!mounted) return;
    final field = target ? 'target' : 'daily_budget';
    final now = (campaigns ?? const []).firstWhere(
      (r) => '${r['id']}' == '${c['id']}',
      orElse: () => const {},
    )[field];
    final confirmed = now is num && (now - sent).abs() < 0.01;
    ScaffoldMessenger.of(context)
      ..hideCurrentSnackBar()
      ..showSnackBar(
        SnackBar(
          key: const ValueKey('google-change-readback'),
          content: Text(
            confirmed
                ? 'Google confirms the new ${target ? 'target' : 'budget'}.'
                : 'Saved. Google does not show it yet; it can take a minute.',
          ),
        ),
      );
  }

  // ── Drawing ────────────────────────────────────────────────────────────

  Widget _onCell(Map<String, dynamic> c, ThemeData theme) {
    final why = _why(c);
    final muted = theme.textTheme.bodySmall;
    if (!canManage) {
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
    if (!canManage) return Text(_budgetText(c));
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
        ? '${(adNumber(t) * 100).round()}% ROAS'
        : '${adMoney(t)} CPA';
  }

  Widget _targetCell(Map<String, dynamic> c, ThemeData theme) {
    final muted = theme.textTheme.bodySmall;
    if (c['target_kind'] == null) {
      return Text(_bidding('${c['bidding'] ?? ''}'), style: muted);
    }
    if (c['portfolio'] == true) {
      return Text('${_targetText(c)} · shared', style: muted);
    }
    if (!canManage) return Text(_targetText(c));
    return _link(
      'google-target-${c['id']}',
      _targetText(c),
      () => _edit(c, target: true),
      theme,
    );
  }

  @override
  List<(String, String?, double)> get columns => const [
    ('On', null, 190),
    ('Schedule', null, 250),
    ('Budget', 'daily_budget', 130),
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
            titleOf(c),
            key: ValueKey('google-name-${c['id']}'),
            maxLines: lines,
            overflow: TextOverflow.ellipsis,
            style: const TextStyle(fontWeight: FontWeight.w600),
          ),
        ),
      ),
      if (_lsa(c) && titleOf(c) == '${c['name']}') ...[
        const SizedBox(width: 6),
        Text('Local Services', style: theme.textTheme.labelSmall),
      ],
    ],
  );

  @override
  Widget tableRow(Map<String, dynamic> c, ThemeData theme) => Padding(
    key: ValueKey('google-row-${c['id']}'),
    padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
    child: Row(
      children: [
        Expanded(child: _name(c, theme)),
        SizedBox(width: 190, child: _onCell(c, theme)),
        SizedBox(width: 250, child: scheduleChip(c)),
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
                  ? adMoney(c[key])
                  : formatCount(adNumber(c[key]).round()),
              textAlign: TextAlign.end,
            ),
          ),
      ],
    ),
  );

  @override
  Widget card(Map<String, dynamic> c, ThemeData theme) {
    final muted = theme.textTheme.bodySmall;
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
              scheduleChip(c),
              Text(
                [
                  adMoney(c['spend']),
                  '${formatCount(adNumber(c['conversions']).round())} conv.',
                  if (per != null) '${adMoney(per)}/conv.',
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
    // Before and after, side by side, before anything is sent (Rodrigo, 01a1ef57).
    final ok = await confirmAdChange(
      context,
      title: widget.target
          ? 'Change the bid target?'
          : 'Change the ${_weekly ? 'weekly' : 'daily'} budget?',
      change: '${_show(_start())} → ${_show(_amount.text)}',
      detail: '${widget.campaign['name']}. Google applies it right away.',
    );
    if (!ok || !mounted) return;
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
    Navigator.of(context).pop(
      widget.target
          ? (_roas ? typed / 100 : typed)
          : (_weekly ? typed / 7 : typed),
    );
  }

  /// A typed or current amount as the panel means it: 350% or $45.00 / day.
  String _show(String text) {
    final v = double.tryParse(text.replaceAll(RegExp(r'[$%,]'), '').trim());
    if (v == null) return 'not set';
    if (_roas) return '${v.round()}%';
    return '${adMoney(v)}${widget.target
        ? ''
        : _weekly
        ? ' / week'
        : ' / day'}';
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final c = widget.campaign;
    final spent = formatMoney(
      ((((c['spend'] as num?) ?? 0) / widget.days) * 100).round(),
    );

    return SidePanel(
      title:
          '${widget.target
              ? 'Bid target'
              : _weekly
              ? 'Weekly budget'
              : 'Daily budget'} · ${c['name']}',
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

/// A Google Ads customer id as Google writes it: 123-456-7890.
String googleCustomerId(String id) => id.length == 10
    ? '${id.substring(0, 3)}-${id.substring(3, 6)}-${id.substring(6)}'
    : id;
