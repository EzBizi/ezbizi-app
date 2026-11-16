import 'package:flutter/material.dart';

import '../../widgets/empty_state.dart';

import '../../core/api_client.dart';
import '../../core/live_changes.dart';
import '../../core/theme.dart';
import '../../core/timestamps.dart';
import '../../widgets/async_body.dart';
import '../../widgets/module_overview.dart';

/// Fleet's General tab (design 149, options 1 and 4): fleet status as one
/// stacked bar, what needs attention with its fix, what comes due in the next
/// 30 days, and - with financials - what it cost. Two columns on a desktop,
/// one on a phone.
///
/// Everything comes from one `GET /vehicles/summary`: health, warnings, the
/// dates falling due (`dates`, soonest first, mileage last) and - with
/// financials - spending with the previous period and the top vehicles.
class FleetGeneral extends StatefulWidget {
  const FleetGeneral({
    super.key,
    required this.api,
    required this.visible,
    required this.onOpen,
    required this.canAdd,
    required this.onAdd,
  });

  final ApiClient api;
  final bool visible;

  /// A list filter in query form: `status=in_shop`, `unassigned=1`, or ''.
  final ValueChanged<String> onOpen;

  final bool canAdd;
  final VoidCallback onAdd;

  /// How far ahead Coming up looks - the server's own "soon".
  static const soonDays = 30;

  @override
  State<FleetGeneral> createState() => _FleetGeneralState();
}

/// The fix each warning carries: the button's words, and where it goes when
/// the server sends no link of its own.
const _fixes = <String, (String, String)>{
  'service_overdue': ('Book service', 'due_soon=1'),
  'service_due_soon': ('Book service', 'due_soon=1'),
  'insurance_expired': ('Renew insurance', 'insurance_expiring=1'),
  'insurance_expiring': ('Renew insurance', 'insurance_expiring=1'),
  'no_insurance': ('Add insurance', 'uninsured=1'),
  'out_of_service': ('Show them', 'status=out_of_service'),
  'active_unassigned': ('Assign keys', 'unassigned=1'),
};

class _FleetGeneralState extends State<FleetGeneral> {
  bool _loading = true;
  String? _error;
  Map<String, dynamic>? _data;
  String _period = 'month';
  int _request = 0;

  @override
  void initState() {
    super.initState();
    _load();
    // A vehicle changed anywhere moves the figures: read again quietly.
    _live = LiveChanges.of(widget.api).subscribe({'vehicles'}, (_) {
      if (!_loading) return _load(quiet: true);
    }, active: () => widget.visible && LiveChanges.visible(this)());
  }

  LiveSubscription? _live;

  @override
  void dispose() {
    _live?.cancel();
    super.dispose();
  }

  @override
  void didUpdateWidget(covariant FleetGeneral oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (widget.visible && !oldWidget.visible) _load();
  }

  /// [quiet]: a live re-read; a failure keeps the figures on screen.
  Future<void> _load({bool quiet = false}) async {
    final request = ++_request;
    if (!quiet) {
      setState(() {
        _loading = _data == null;
        _error = null;
      });
    }

    final response = await widget.api.get('/vehicles/summary', {
      'period': _period,
    });

    if (!mounted || request != _request) return;
    if (quiet && !response.ok) return;

    setState(() {
      _loading = false;
      if (response.ok) {
        _data = response.body;
      } else {
        _error = response.message;
      }
    });
  }

  void _open(String link) => widget.onOpen(link);

  @override
  Widget build(BuildContext context) {
    return AsyncBody(
      loading: _loading,
      error: _error,
      onRetry: _load,
      child: _data == null ? const SizedBox.shrink() : _body(context, _data!),
    );
  }

  Widget _body(BuildContext context, Map<String, dynamic> data) {
    final health = (data['health'] as Map?)?.cast<String, dynamic>() ?? {};

    if (health['empty'] == true) {
      return EmptyState(
        key: const ValueKey('fleet-general-empty'),
        icon: Icons.local_shipping_outlined,
        title: 'No vehicles yet',
        message: '${health['sentence'] ?? 'No vehicles to show.'}',
        actionLabel: 'Add vehicle',
        onAction: widget.canAdd ? widget.onAdd : null,
      );
    }

    final spending = data['spending'] is Map
        ? (data['spending'] as Map).cast<String, dynamic>()
        : null;

    final rows = <List<Widget>>[
      [
        _status(context, health),
        _attention(context, data['warnings'] as List? ?? const []),
      ],
      [
        if (data['dates'] is List) _comingUp(context, data['dates'] as List),
        if (spending != null) _spending(context, spending),
      ],
    ];

    return LayoutBuilder(
      builder: (context, box) {
        final wide = box.maxWidth >= 900;
        return ListView(
          padding: const EdgeInsets.all(16),
          children: [
            for (final row in rows)
              if (row.isNotEmpty)
                Padding(
                  padding: const EdgeInsets.only(bottom: 16),
                  child: wide && row.length > 1
                      ? _EqualHeightRow(children: row)
                      : Column(
                          crossAxisAlignment: CrossAxisAlignment.stretch,
                          children: [
                            for (var i = 0; i < row.length; i++) ...[
                              if (i > 0) const SizedBox(height: 16),
                              row[i],
                            ],
                          ],
                        ),
                ),
          ],
        );
      },
    );
  }

  // --- Fleet status -------------------------------------------------------

  Widget _status(BuildContext context, Map<String, dynamic> health) {
    final theme = Theme.of(context);
    final links = (health['links'] as Map?)?.cast<String, dynamic>() ?? {};
    int count(String key) => (health[key] as num?)?.toInt() ?? 0;
    VoidCallback? link(String key) =>
        links[key] is String ? () => _open(links[key] as String) : null;

    final parts = [
      ('active', 'Active', Tone.ok),
      ('in_shop', 'In shop', Tone.caution),
      ('out_of_service', 'Out of service', Tone.bad),
    ];

    return OverviewSection(
      key: const Key('fleet-general-status'),
      title: 'Fleet status',
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          InkWell(
            key: const Key('fleet-general-vehicles'),
            onTap: link('vehicles'),
            child: Text(
              '${count('vehicles')} '
              '${count('vehicles') == 1 ? 'vehicle' : 'vehicles'}',
              style: theme.textTheme.headlineSmall?.copyWith(
                fontWeight: FontWeight.w800,
              ),
            ),
          ),
          const SizedBox(height: 12),
          ClipRRect(
            borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
            child: SizedBox(
              height: 16,
              child: Row(
                // Stretch: a childless ColoredBox under a loose height is 0px.
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  for (final (key, _, tone) in parts)
                    if (count(key) > 0)
                      Expanded(
                        flex: count(key),
                        child: ColoredBox(
                          key: Key('fleet-status-segment-$key'),
                          color: ToneColours.of(context, tone).ink,
                        ),
                      ),
                ],
              ),
            ),
          ),
          const SizedBox(height: 12),
          Wrap(
            spacing: 16,
            runSpacing: 8,
            children: [
              for (final (key, label, tone) in parts)
                InkWell(
                  key: Key('fleet-general-${key.replaceAll('_', '-')}'),
                  onTap: link(key),
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Container(
                        width: 10,
                        height: 10,
                        decoration: BoxDecoration(
                          color: ToneColours.of(context, tone).ink,
                          shape: BoxShape.circle,
                        ),
                      ),
                      const SizedBox(width: 6),
                      Text('$label ${count(key)}'),
                    ],
                  ),
                ),
            ],
          ),
          const SizedBox(height: 12),
          Wrap(
            spacing: 6,
            children: [
              InkWell(
                key: const Key('fleet-general-owned'),
                onTap: link('owned'),
                child: Text('${count('owned')} owned'),
              ),
              const Text('·'),
              InkWell(
                key: const Key('fleet-general-rented'),
                onTap: link('rented'),
                child: Text('${count('rented')} rented'),
              ),
            ],
          ),
        ],
      ),
    );
  }

  // --- Needs attention ----------------------------------------------------

  Widget _attention(BuildContext context, List<dynamic> warnings) {
    final theme = Theme.of(context);
    final rows = [
      for (final raw in warnings)
        if (raw is Map) raw.cast<String, dynamic>(),
    ];

    return OverviewSection(
      key: const ValueKey('tour-fleet_management-attention'),
      title: 'Needs attention',
      child: rows.isEmpty
          ? Text(
              'Nothing needs attention.',
              style: theme.textTheme.bodyMedium?.copyWith(
                color: ToneColours.of(context, Tone.ok).ink,
              ),
            )
          : Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [for (final row in rows) _attentionRow(context, row)],
            ),
    );
  }

  Widget _attentionRow(BuildContext context, Map<String, dynamic> row) {
    final key = '${row['key']}';
    final fix = _fixes[key];
    final bad = const {'service_overdue', 'insurance_expired'}.contains(key);
    final link = row['link'] is String ? row['link'] as String : fix?.$2;

    return Padding(
      key: Key('fleet-attention-$key'),
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        children: [
          Icon(
            bad ? Icons.error_outline : Icons.warning_amber_rounded,
            size: 18,
            color: ToneColours.of(context, bad ? Tone.bad : Tone.caution).ink,
          ),
          const SizedBox(width: 8),
          Expanded(child: Text('${row['sentence'] ?? ''}')),
          if (fix != null && link != null) ...[
            const SizedBox(width: 8),
            OutlinedButton(
              key: Key('fleet-fix-$key'),
              onPressed: () => _open(link),
              child: Text(fix.$1),
            ),
          ],
        ],
      ),
    );
  }

  // --- Coming up ----------------------------------------------------------

  static const _kinds = {
    'registration': 'Registration',
    'inspection': 'Inspection',
    'insurance': 'Insurance',
    'service': 'Service',
    'service_miles': 'Service',
  };

  Widget _comingUp(BuildContext context, List<dynamic> dates) {
    final theme = Theme.of(context);
    // The server's order: soonest first, mileage after the dates.
    final items = [
      for (final raw in dates)
        if (raw is Map) raw.cast<String, dynamic>(),
    ];

    const shown = 8;
    final bad = ToneColours.of(context, Tone.bad).ink;

    String when(Map<String, dynamic> item) {
      if (item['kind'] == 'service_miles') {
        final left = (item['miles_left'] as num?)?.toInt() ?? 0;
        return left < 0 ? '${-left} mi past' : '$left mi left';
      }
      final day = DateTime.tryParse('${item['on'] ?? ''}');
      return day == null ? '' : _date(day);
    }

    return OverviewSection(
      key: const Key('fleet-general-coming-up'),
      title: 'Coming up, next 30 days',
      child: items.isEmpty
          ? Text(
              'Nothing is due in the next 30 days.',
              style: theme.textTheme.bodyMedium?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            )
          : Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                for (final (i, item) in items.take(shown).indexed)
                  Padding(
                    key: Key('fleet-coming-$i'),
                    padding: const EdgeInsets.symmetric(vertical: 4),
                    child: Row(
                      children: [
                        SizedBox(
                          width: 96,
                          child: Text(
                            when(item),
                            key: Key('fleet-coming-when-$i'),
                            style: TextStyle(
                              fontWeight: FontWeight.w700,
                              color: item['overdue'] == true ? bad : null,
                            ),
                          ),
                        ),
                        Expanded(
                          child: Text(
                            '${_kinds[item['kind']] ?? item['kind']} · '
                            '${item['label'] ?? ''}',
                            overflow: TextOverflow.ellipsis,
                          ),
                        ),
                        if (item['overdue'] == true)
                          Text(
                            'Overdue',
                            key: Key('fleet-coming-overdue-$i'),
                            style: TextStyle(
                              color: bad,
                              fontWeight: FontWeight.w700,
                            ),
                          ),
                      ],
                    ),
                  ),
                if (items.length > shown)
                  Text(
                    'and ${items.length - shown} more',
                    style: theme.textTheme.bodySmall,
                  ),
              ],
            ),
    );
  }

  static String _date(DateTime day) =>
      '${shortMonths[day.month - 1]} ${day.day}';

  // --- Spending -----------------------------------------------------------

  Widget _spending(BuildContext context, Map<String, dynamic> spending) {
    final theme = Theme.of(context);
    final total = (spending['total_minor'] as num?)?.toInt() ?? 0;
    final categories = [
      for (final c in (spending['categories'] as List? ?? const []))
        if (c is Map)
          (
            '${c['label'] ?? c['key']}',
            (c['amount_minor'] as num?)?.toInt() ?? 0,
          ),
    ];
    final top = [
      for (final v in (spending['vehicles'] as List? ?? const []).take(5))
        if (v is Map)
          (
            '${v['label'] ?? v['id']}',
            (v['amount_minor'] as num?)?.toInt() ?? 0,
          ),
    ];
    final most = categories.fold<int>(0, (m, c) => c.$2 > m ? c.$2 : m);

    return OverviewSection(
      key: const Key('fleet-general-spending'),
      title: 'Spending',
      trailing: OverviewPeriodPicker(
        key: const ValueKey('tour-fleet_management-period'),
        period: _period,
        onChanged: (period) {
          setState(() => _period = period);
          _load();
        },
      ),
      child: total == 0
          ? Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'No costs were recorded in this period.',
                  style: theme.textTheme.bodyMedium?.copyWith(
                    color: theme.colorScheme.onSurfaceVariant,
                  ),
                ),
                const SizedBox(height: 8),
                TextButton(
                  key: const Key('fleet-general-record-cost'),
                  onPressed: () => _open(''),
                  child: const Text('Record a cost ›'),
                ),
              ],
            )
          : Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text(
                  _money(total),
                  key: const Key('fleet-general-spending-total'),
                  style: theme.textTheme.headlineSmall?.copyWith(
                    fontWeight: FontWeight.w800,
                  ),
                ),
                if (_versus(spending, total) case final versus?)
                  Text(
                    versus.$1,
                    key: const Key('fleet-general-spending-versus'),
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: ToneColours.of(context, versus.$2).ink,
                    ),
                  ),
                const SizedBox(height: 12),
                for (final (label, amount) in categories)
                  Padding(
                    padding: const EdgeInsets.symmetric(vertical: 4),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        Row(
                          children: [
                            Expanded(child: Text(label)),
                            Text(
                              _money(amount),
                              style: const TextStyle(
                                fontWeight: FontWeight.w700,
                              ),
                            ),
                          ],
                        ),
                        const SizedBox(height: 4),
                        ClipRRect(
                          borderRadius: BorderRadius.circular(4),
                          child: SizedBox(
                            height: 8,
                            child: ColoredBox(
                              color: EzBiziTheme.edge(context),
                              child: Align(
                                alignment: Alignment.centerLeft,
                                child: FractionallySizedBox(
                                  widthFactor: most == 0 ? 0 : amount / most,
                                  heightFactor: 1,
                                  child: ColoredBox(
                                    color: theme.colorScheme.primary,
                                  ),
                                ),
                              ),
                            ),
                          ),
                        ),
                      ],
                    ),
                  ),
                if (top.isNotEmpty) ...[
                  const SizedBox(height: 12),
                  Text('Top vehicles', style: theme.textTheme.titleSmall),
                  const SizedBox(height: 4),
                  for (final (i, (label, amount)) in top.indexed)
                    Padding(
                      key: Key('fleet-top-$i'),
                      padding: const EdgeInsets.symmetric(vertical: 2),
                      child: Row(
                        children: [
                          Expanded(
                            child: Text(label, overflow: TextOverflow.ellipsis),
                          ),
                          Text(_money(amount)),
                        ],
                      ),
                    ),
                ],
              ],
            ),
    );
  }

  /// "▲ 12% vs the same days last period", or null with nothing to compare.
  /// More spent is caution, less is ok.
  static (String, Tone)? _versus(Map<String, dynamic> spending, int total) {
    final previous = (spending['previous_total_minor'] as num?)?.toInt();
    if (previous == null || previous <= 0) return null;
    final change = ((total - previous) * 100 / previous).round();
    if (change == 0) {
      return ('Same as the same days last period', Tone.neutral);
    }
    return (
      '${change > 0 ? '▲' : '▼'} ${change.abs()}% vs the same days last period',
      change > 0 ? Tone.caution : Tone.ok,
    );
  }

  /// Cents as "$5,057.00".
  static String _money(int cents) {
    final whole = (cents.abs() ~/ 100).toString().replaceAllMapped(
      RegExp(r'\B(?=(\d{3})+(?!\d))'),
      (_) => ',',
    );
    final part = (cents.abs() % 100).toString().padLeft(2, '0');
    return '${cents < 0 ? '-' : ''}\$$whole.$part';
  }
}

/// Cards side by side at one height. Not IntrinsicHeight: the period picker
/// (a SegmentedButton) cannot answer an intrinsic size, so each card is
/// measured after layout and every one is given the tallest's height.
class _EqualHeightRow extends StatefulWidget {
  const _EqualHeightRow({required this.children});

  final List<Widget> children;

  @override
  State<_EqualHeightRow> createState() => _EqualHeightRowState();
}

class _EqualHeightRowState extends State<_EqualHeightRow> {
  final _keys = <GlobalKey>[];
  double _height = 0;

  @override
  void didUpdateWidget(covariant _EqualHeightRow oldWidget) {
    super.didUpdateWidget(oldWidget);
    // Content may have shrunk: measure from natural heights again.
    _height = 0;
  }

  void _measure(Duration _) {
    if (!mounted) return;
    var tallest = 0.0;
    for (final key in _keys) {
      final box = key.currentContext?.findRenderObject();
      if (box is RenderBox && box.hasSize && box.size.height > tallest) {
        tallest = box.size.height;
      }
    }
    if ((tallest - _height).abs() > 0.5) setState(() => _height = tallest);
  }

  @override
  Widget build(BuildContext context) {
    while (_keys.length < widget.children.length) {
      _keys.add(GlobalKey());
    }
    WidgetsBinding.instance.addPostFrameCallback(_measure);

    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        for (var i = 0; i < widget.children.length; i++) ...[
          if (i > 0) const SizedBox(width: 16),
          Expanded(
            child: ConstrainedBox(
              key: _keys[i],
              constraints: BoxConstraints(minHeight: _height),
              child: widget.children[i],
            ),
          ),
        ],
      ],
    );
  }
}
