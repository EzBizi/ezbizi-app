import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:latlong2/latlong.dart';

import '../core/api_client.dart';
import '../core/branch_options.dart';
import '../core/session.dart';
import '../core/theme.dart';
import 'map_attribution.dart';
import 'search_picker.dart';

/// Leads › Manage › Stats (A1 takeover, UI queue 107, design 1 and 4): one
/// page for a period and a branch. The four numbers, a map (offices, leads by
/// ZIP, jobs), then source, top markets, dispatchers, why leads were lost,
/// services booked and a day × time-of-day heatmap. Numbers from
/// `GET /leads/stats`; the map from Field's coverage and ZIP centres.
class LeadsStats extends StatefulWidget {
  const LeadsStats({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<LeadsStats> createState() => _LeadsStatsState();
}

const _periods = {
  '7': 'Last 7 days',
  '30': 'Last 30 days',
  '90': 'Last 3 months',
  '365': 'Last 12 months',
};

const _sourceWords = {
  'ls_phone_call': 'LS Call',
  'ls_message': 'LS Text',
  'ls_booking': 'LS Booking',
  'ringcentral': 'Phone',
  'website_chat': 'Chat',
  'website_form': 'Forms',
  'website_booking': 'Online booking',
  'thumbtack': 'Thumbtack',
  'facebook': 'Messenger',
  'instagram': 'Instagram',
  'whatsapp': 'WhatsApp',
  'workiz': 'Workiz',
};

class _LeadsStatsState extends State<LeadsStats> {
  String _period = '30';
  String? _franchise;
  bool _loading = true;
  String? _error;
  Map<String, dynamic> _stats = const {};

  // The map's layers.
  bool _offices = true;
  bool _leads = true;
  bool _jobs = false;
  final Set<String> _outcomes = {'book', 'support', 'archive', 'unclassified'};
  String? _tiles;
  List<Map<String, dynamic>> _officePoints = const [];
  List<Map<String, dynamic>> _jobPoints = const [];
  List<Map<String, dynamic>> _zipRows = const [];
  Map<String, LatLng> _zipPoints = const {};

  int _request = 0;

  @override
  void initState() {
    super.initState();
    _load();
  }

  (String, String) get _range {
    final now = DateTime.now();
    final from = now.subtract(Duration(days: int.parse(_period) - 1));
    String day(DateTime d) =>
        '${d.year}-${'${d.month}'.padLeft(2, '0')}-${'${d.day}'.padLeft(2, '0')}';
    return (day(from), day(now));
  }

  Future<void> _load() async {
    final request = ++_request;
    setState(() {
      _loading = _stats.isEmpty;
      _error = null;
    });
    final (from, to) = _range;
    final query = {'from': from, 'to': to, 'franchise_id': ?_franchise};
    final results = await Future.wait([
      widget.api.get('/leads/stats', query),
      widget.api.get('/leads/stats/points', {'from': from, 'to': to}),
      widget.api.get('/field/coverage', {'from': from, 'to': to}),
      if (_tiles == null)
        widget.api.get('/field/map/tiles', {'theme': 'light'}),
    ]);
    if (!mounted || request != _request) return;
    final stats = results[0];
    final points = results[1];
    final coverage = results[2];

    setState(() {
      _loading = false;
      if (!stats.ok) {
        _error = stats.message;
        return;
      }
      _stats = stats.data is Map<String, dynamic>
          ? stats.data as Map<String, dynamic>
          : const {};
      _zipRows = points.ok && points.data is List
          ? (points.data as List).whereType<Map<String, dynamic>>().toList()
          : const [];
      final cov = coverage.ok && coverage.data is Map
          ? coverage.data as Map
          : const {};
      _officePoints = (cov['offices'] as List? ?? const [])
          .whereType<Map<String, dynamic>>()
          .toList();
      _jobPoints = (cov['jobs'] as List? ?? const [])
          .whereType<Map<String, dynamic>>()
          .toList();
      if (results.length > 3 && results[3].ok) {
        _tiles = (results[3].body['data'] as Map?)?['url'] as String?;
      }
    });
    await _placeZips();
  }

  /// The ZIP centres for the leads layer, asked once per set of ZIPs.
  Future<void> _placeZips() async {
    final missing = [
      for (final r in _zipRows)
        if (!_zipPoints.containsKey('${r['zip']}')) '${r['zip']}',
    ];
    // Field asks Google for at most 25 new ZIPs a call, so ask in 25s.
    for (var i = 0; i < missing.length; i += 25) {
      final batch = missing.sublist(i, math.min(i + 25, missing.length));
      final response = await widget.api.get('/field/geocode-zips', {
        'zips': batch.join(','),
      });
      if (!mounted || !response.ok || response.data is! Map) return;
      final found = <String, LatLng>{..._zipPoints};
      for (final e in (response.data as Map).entries) {
        final p = e.value;
        if (p is Map && p['lat'] is num && p['lng'] is num) {
          found['${e.key}'] = LatLng(
            (p['lat'] as num).toDouble(),
            (p['lng'] as num).toDouble(),
          );
        }
      }
      setState(() => _zipPoints = found);
    }
  }

  int _int(Object? v) => (v as num?)?.toInt() ?? 0;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    if (_loading) return const Center(child: CircularProgressIndicator());
    if (_error != null) {
      return Center(child: Text(_error!, style: theme.textTheme.bodyMedium));
    }
    final places = widget.session.me?.switchableFranchises ?? const [];

    return LayoutBuilder(
      builder: (context, box) {
        final wide = box.maxWidth >= 900;
        final cards = [
          _bars(
            context,
            'By source',
            [
              for (final e in (_stats['by_source'] as Map? ?? const {}).entries)
                ('${_sourceWords[e.key] ?? e.key}', _int(e.value)),
            ]..sort((a, b) => b.$2.compareTo(a.$2)),
          ),
          _bars(context, 'Why lost', [
            for (final r
                in ((_stats['loss'] as Map?)?['reasons'] as List? ?? const [])
                    .whereType<Map>())
              ('${r['reason']}', _int(r['count'])),
          ]),
          _bars(context, 'Services booked', [
            for (final r
                in (_stats['services_booked'] as List? ?? const [])
                    .whereType<Map>())
              ('${r['service']}', _int(r['count'])),
          ]),
          _heatmap(context),
        ];

        return ListView(
          key: const ValueKey('leads-stats'),
          padding: const EdgeInsets.all(16),
          children: [
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                SizedBox(
                  width: 170,
                  child: SearchPickerField<String>(
                    key: const ValueKey('leads-stats-period'),
                    value: _period,
                    isDense: true,
                    options: [
                      for (final e in _periods.entries)
                        SearchOption(value: e.key, label: e.value),
                    ],
                    onChanged: (v) {
                      setState(() => _period = v ?? '30');
                      _load();
                    },
                  ),
                ),
                if (places.length > 1)
                  SizedBox(
                    width: 200,
                    child: SearchPickerField<String?>(
                      key: const ValueKey('leads-stats-branch'),
                      value: _franchise,
                      isDense: true,
                      options: [
                        const SearchOption(value: null, label: 'All branches'),
                        for (final b in branchOptions(
                          places,
                          widget.session.me?.companies ?? const [],
                        ))
                          SearchOption(value: b.id, label: b.label),
                      ],
                      onChanged: (v) {
                        setState(() => _franchise = v);
                        _load();
                      },
                    ),
                  ),
              ],
            ),
            const SizedBox(height: 12),
            _numbers(context, wide),
            const SizedBox(height: 12),
            if (wide)
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Expanded(flex: 3, child: _map(context)),
                  const SizedBox(width: 12),
                  Expanded(flex: 2, child: _marketsAndPeople(context)),
                ],
              )
            else ...[
              _map(context),
              const SizedBox(height: 12),
              _marketsAndPeople(context),
            ],
            const SizedBox(height: 12),
            if (wide)
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  for (final (i, c) in cards.indexed) ...[
                    if (i > 0) const SizedBox(width: 12),
                    Expanded(child: c),
                  ],
                ],
              )
            else
              for (final c in cards) ...[c, const SizedBox(height: 12)],
          ],
        );
      },
    );
  }

  Widget _card(BuildContext context, String title, Widget child, {Key? key}) {
    final theme = Theme.of(context);
    return Card(
      key: key,
      margin: EdgeInsets.zero,
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(title, style: theme.textTheme.titleSmall),
            const SizedBox(height: 8),
            child,
          ],
        ),
      ),
    );
  }

  Widget _numbers(BuildContext context, bool wide) {
    final theme = Theme.of(context);
    final total = _int(_stats['total']);
    final booked = _int(_stats['booked']);
    final rate = ((_stats['booking_rate'] as num?) ?? 0).toDouble();
    final lost = ((_stats['loss'] as Map?)?['statuses'] as Map? ?? const {})
        .values
        .fold<int>(0, (a, v) => a + _int(v));

    Widget tile(String label, String value, {Widget? lead, Key? key}) => Card(
      key: key,
      margin: EdgeInsets.zero,
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Row(
          children: [
            if (lead != null) ...[lead, const SizedBox(width: 10)],
            Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(value, style: theme.textTheme.headlineSmall),
                Text(label, style: theme.textTheme.bodySmall),
              ],
            ),
          ],
        ),
      ),
    );

    final tiles = [
      tile('Leads', '$total', key: const ValueKey('leads-stats-total')),
      tile('Booked', '$booked'),
      tile(
        'Booking rate',
        '${(rate * 100).round()}%',
        key: const ValueKey('leads-stats-rate'),
        lead: SizedBox(
          width: 40,
          height: 40,
          child: CircularProgressIndicator(
            value: rate.clamp(0, 1),
            strokeWidth: 6,
            backgroundColor: theme.colorScheme.surfaceContainerHighest,
          ),
        ),
      ),
      tile('Lost', '$lost'),
    ];

    return GridView.count(
      shrinkWrap: true,
      physics: const NeverScrollableScrollPhysics(),
      crossAxisCount: wide ? 4 : 2,
      mainAxisSpacing: 8,
      crossAxisSpacing: 8,
      childAspectRatio: wide ? 3.2 : 2.2,
      children: tiles,
    );
  }

  Widget _bars(BuildContext context, String title, List<(String, int)> rows) {
    final theme = Theme.of(context);
    final top = rows.fold<int>(1, (a, r) => math.max(a, r.$2));
    return _card(
      context,
      title,
      rows.isEmpty
          ? Text('None in this period.', style: theme.textTheme.bodySmall)
          : Column(
              children: [
                for (final (label, n) in rows.take(8))
                  Padding(
                    padding: const EdgeInsets.only(bottom: 6),
                    child: Row(
                      children: [
                        SizedBox(
                          width: 110,
                          child: Text(
                            label,
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: theme.textTheme.bodySmall,
                          ),
                        ),
                        Expanded(
                          child: LinearProgressIndicator(
                            value: n / top,
                            minHeight: 8,
                            borderRadius: BorderRadius.circular(4),
                          ),
                        ),
                        const SizedBox(width: 8),
                        Text('$n', style: theme.textTheme.bodySmall),
                      ],
                    ),
                  ),
              ],
            ),
    );
  }

  Widget _marketsAndPeople(BuildContext context) {
    String rate(Map r) {
      final t = _int(r['total']);
      return t == 0
          ? ''
          : ' · ${(_int(r['booked']) * 100 / t).round()}% booked';
    }

    final markets = (_stats['markets'] as List? ?? const []).whereType<Map>();
    final people = (_stats['dispatchers'] as List? ?? const [])
        .whereType<Map>();
    final small = Theme.of(context).textTheme.bodySmall;

    return _card(
      context,
      'Top markets',
      key: const ValueKey('leads-stats-markets'),
      Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (markets.isEmpty) Text('None in this period.', style: small),
          for (final m in markets.take(6))
            Text('${m['market']}: ${_int(m['total'])}${rate(m)}', style: small),
          const SizedBox(height: 10),
          Text('Dispatchers', style: Theme.of(context).textTheme.titleSmall),
          const SizedBox(height: 4),
          if (people.isEmpty) Text('None in this period.', style: small),
          for (final d in people.take(8))
            Text(
              '${d['dispatcher']}: ${_int(d['total'])}${rate(d)}',
              style: small,
            ),
        ],
      ),
    );
  }

  Widget _heatmap(BuildContext context) {
    final theme = Theme.of(context);
    final slots = (_stats['time_slots'] as Map?) ?? const {};
    final days = [for (final d in slots['days'] as List? ?? const []) '$d'];
    final cols = [for (final s in slots['slots'] as List? ?? const []) '$s'];
    final counts = (slots['counts'] as Map?) ?? const {};
    int at(String d, String s) => _int((counts[d] as Map?)?[s]);
    var top = 1;
    for (final d in days) {
      for (final s in cols) {
        top = math.max(top, at(d, s));
      }
    }

    return _card(
      context,
      'When they call',
      key: const ValueKey('leads-stats-heatmap'),
      days.isEmpty
          ? Text('None in this period.', style: theme.textTheme.bodySmall)
          : Table(
              columnWidths: const {0: FixedColumnWidth(34)},
              children: [
                TableRow(
                  children: [
                    const SizedBox(),
                    for (final s in cols)
                      Text(
                        s,
                        textAlign: TextAlign.center,
                        style: theme.textTheme.labelSmall,
                      ),
                  ],
                ),
                for (final d in days)
                  TableRow(
                    children: [
                      Text(d, style: theme.textTheme.labelSmall),
                      for (final s in cols)
                        Tooltip(
                          message: '$d $s: ${at(d, s)}',
                          child: Container(
                            height: 16,
                            margin: const EdgeInsets.all(1),
                            decoration: BoxDecoration(
                              color: Color.lerp(
                                theme.colorScheme.surfaceContainerHighest,
                                theme.colorScheme.primary,
                                at(d, s) / top,
                              ),
                              borderRadius: BorderRadius.circular(2),
                            ),
                          ),
                        ),
                    ],
                  ),
              ],
            ),
    );
  }

  Widget _map(BuildContext context) {
    final theme = Theme.of(context);
    Widget chip(String label, bool on, ValueChanged<bool> set, String key) =>
        FilterChip(
          key: ValueKey('leads-stats-layer-$key'),
          label: Text(label),
          selected: on,
          visualDensity: VisualDensity.compact,
          onSelected: (v) => setState(() => set(v)),
        );

    final leadMarkers = <Marker>[
      if (_leads)
        for (final r in _zipRows)
          if (_zipPoints['${r['zip']}'] case final p?)
            if (_outcomes.any((o) => _int(r[o]) > 0))
              Marker(
                point: p,
                width: 10.0 + math.min(18, _int(r['total']) * 2),
                height: 10.0 + math.min(18, _int(r['total']) * 2),
                child: Tooltip(
                  message: '${r['zip']}: ${_int(r['total'])} leads',
                  child: Container(
                    decoration: BoxDecoration(
                      shape: BoxShape.circle,
                      color: theme.colorScheme.primary.withValues(alpha: 0.6),
                    ),
                  ),
                ),
              ),
    ];
    // Each office's leads and booking rate, from /leads/stats `offices`
    // matched on company_id: sized by leads, red to green by booking rate.
    final byOffice = {
      for (final o in (_stats['offices'] as List? ?? const []).whereType<Map>())
        '${o['company_id']}': o,
    };
    final mostLeads = byOffice.values.fold<int>(
      1,
      (a, o) => math.max(a, _int(o['total'])),
    );
    final officeMarkers = <Marker>[
      if (_offices)
        for (final o in _officePoints)
          if (o['lat'] is num && o['lng'] is num)
            () {
              final mine = byOffice['${o['company_id']}'];
              final total = _int(mine?['total']);
              final rate = total == 0 ? 0.0 : _int(mine?['booked']) / total;
              final size = 18.0 + 22 * total / mostLeads;
              return Marker(
                point: LatLng(
                  (o['lat'] as num).toDouble(),
                  (o['lng'] as num).toDouble(),
                ),
                width: size,
                height: size,
                child: Tooltip(
                  message: mine == null
                      ? '${o['name'] ?? ''}'
                      : '${o['name'] ?? ''}: $total leads, '
                            '${(rate * 100).round()}% booked',
                  child: Container(
                    key: ValueKey('leads-stats-office-${o['company_id']}'),
                    decoration: BoxDecoration(
                      shape: BoxShape.circle,
                      color: mine == null
                          ? theme.colorScheme.tertiary
                          : Color.lerp(
                              theme.colorScheme.error,
                              StateColour.of(context).on,
                              rate,
                            ),
                      border: Border.all(
                        color: theme.colorScheme.surface,
                        width: 2,
                      ),
                    ),
                  ),
                ),
              );
            }(),
    ];
    final jobMarkers = <Marker>[
      if (_jobs)
        for (final j in _jobPoints)
          if (j['lat'] is num && j['lng'] is num)
            Marker(
              point: LatLng(
                (j['lat'] as num).toDouble(),
                (j['lng'] as num).toDouble(),
              ),
              width: 8,
              height: 8,
              child: Container(
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  color: theme.colorScheme.secondary,
                ),
              ),
            ),
    ];
    final all = [...officeMarkers, ...leadMarkers, ...jobMarkers];
    final center = all.isEmpty
        ? const LatLng(39.5, -98.35)
        : LatLng(
            all.map((m) => m.point.latitude).reduce((a, b) => a + b) /
                all.length,
            all.map((m) => m.point.longitude).reduce((a, b) => a + b) /
                all.length,
          );

    return _card(
      context,
      'Map',
      key: const ValueKey('leads-stats-map'),
      Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Wrap(
            spacing: 6,
            runSpacing: 6,
            children: [
              chip('Offices', _offices, (v) => _offices = v, 'offices'),
              chip('Leads', _leads, (v) => _leads = v, 'leads'),
              chip('Jobs', _jobs, (v) => _jobs = v, 'jobs'),
              for (final (o, label) in const [
                ('book', 'Book'),
                ('support', 'Support'),
                ('archive', 'Archive'),
              ])
                chip(
                  label,
                  _outcomes.contains(o),
                  (v) => v ? _outcomes.add(o) : _outcomes.remove(o),
                  o,
                ),
            ],
          ),
          const SizedBox(height: 8),
          SizedBox(
            height: 280,
            child: _tiles == null
                ? Container(
                    alignment: Alignment.center,
                    decoration: BoxDecoration(
                      color: theme.colorScheme.surfaceContainerHighest,
                      borderRadius: BorderRadius.circular(8),
                    ),
                    child: Text(
                      'The map needs access to Field’s map.',
                      style: theme.textTheme.bodySmall,
                    ),
                  )
                : ClipRRect(
                    borderRadius: BorderRadius.circular(8),
                    child: FlutterMap(
                      options: MapOptions(
                        initialCenter: center,
                        initialZoom: all.isEmpty ? 3.5 : 8,
                      ),
                      children: [
                        TileLayer(urlTemplate: _tiles!),
                        MarkerLayer(markers: all),
                        // Google's copyright, which its tiles require.
                        MapAttributionLine(api: widget.api),
                      ],
                    ),
                  ),
          ),
        ],
      ),
    );
  }
}
