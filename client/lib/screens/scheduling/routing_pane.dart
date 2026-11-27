import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:latlong2/latlong.dart';

import '../../core/api_client.dart';
import '../../core/theme.dart';
import '../../core/timestamps.dart';
import '../../widgets/async_body.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/status_pill.dart';
import 'availability_suggestion.dart';

/// Scheduling › Routing (Jose, 2026-10-14: "1 technician is able to take a
/// max of 6 jobs per day … a suggested route where it says like Technician 1
/// - Technician 2 (amount of technicians based on the availability) … a
/// Routing Tab where it shows the recommended routes (actual routes being
/// done on google maps when clicked)"; UI queue 90, desktop 1 and phone 4).
///
/// The server plans the day (`GET /scheduling/routes`): a route per
/// technician, its stops in order, its straight-line miles and, with a Google
/// key, its `drive`: the road path, miles and minutes, drawn here (Jose,
/// 2026-10-14: "i want to see the google maps routing Inside the app, not via
/// an outbound link"). Routes past the area's capacity are `extra`: the day
/// needs another technician. `unplaced` jobs have no usable location, and are
/// listed so nobody forgets them.
class RoutingPane extends StatefulWidget {
  const RoutingPane({super.key, required this.api, required this.visible});

  final ApiClient api;
  final bool visible;

  /// Desktop beside the map, as Re-Scheduling.
  static const double sideAbove = 900;

  @override
  State<RoutingPane> createState() => _RoutingPaneState();
}

class _RoutingPaneState extends State<RoutingPane> {
  List<Map<String, dynamic>>? _areas;
  String? _area;
  DateTime _date = DateUtils.dateOnly(DateTime.now());

  bool _loading = true;
  String? _error;
  Map<String, dynamic>? _plan;
  int _request = 0;

  /// The route picked: drawn forward on the map, its stops shown.
  int? _picked;

  /// On a phone the cards give way to the route opened.
  bool _openOnPhone = false;

  final _map = MapController();
  bool _mapReady = false;

  @override
  void initState() {
    super.initState();
    if (widget.visible) _start();
  }

  @override
  void didUpdateWidget(covariant RoutingPane old) {
    super.didUpdateWidget(old);
    if (widget.visible && !old.visible && _areas == null) _start();
  }

  Future<void> _start() async {
    final response = await widget.api.get('/scheduling/areas', const {});
    if (!mounted) return;
    if (!response.ok) {
      setState(() {
        _loading = false;
        _error = response.message;
      });
      return;
    }
    _areas = [
      for (final a in (response.body['data'] as List? ?? const []))
        if (a is Map) a.cast<String, dynamic>(),
    ];
    _area = _areas!.isEmpty ? null : '${_areas!.first['id']}';
    await _load();
  }

  Future<void> _load() async {
    final area = _area;
    if (area == null) {
      setState(() => _loading = false);
      return;
    }
    final ticket = ++_request;
    setState(() {
      _loading = _plan == null;
      _error = null;
    });
    final response = await widget.api.get('/scheduling/routes', {
      'area_id': area,
      'date': formatCalendarDay(_date),
    });
    if (!mounted || ticket != _request) return;
    setState(() {
      _loading = false;
      _picked = null;
      _openOnPhone = false;
      if (!response.ok) {
        _error = response.message;
        return;
      }
      _plan = (response.body['data'] as Map?)?.cast<String, dynamic>();
    });
    _fit();
  }

  List<Map<String, dynamic>> get _routes => [
    for (final r in (_plan?['routes'] as List? ?? const []))
      if (r is Map) r.cast<String, dynamic>(),
  ];

  static List<Map<String, dynamic>> _stops(Map<String, dynamic> route) => [
    for (final s in (route['stops'] as List? ?? const []))
      if (s is Map) s.cast<String, dynamic>(),
  ];

  static LatLng? _at(Map<String, dynamic> stop) {
    final lat = (stop['latitude'] as num?)?.toDouble();
    final lng = (stop['longitude'] as num?)?.toDouble();
    return lat == null || lng == null || (lat == 0 && lng == 0)
        ? null
        : LatLng(lat, lng);
  }

  /// The road through the stops when Google gave one, else straight lines
  /// between them.
  static List<LatLng> _path(Map<String, dynamic> route) {
    final road = _decode('${(route['drive'] as Map?)?['polyline'] ?? ''}');
    return road.length > 1 ? road : [for (final s in _stops(route)) ?_at(s)];
  }

  /// Google's encoded polyline, precision 5 (its published algorithm). A
  /// string cut short stops at the last whole point.
  static List<LatLng> _decode(String s) {
    final points = <LatLng>[];
    final at = [0, 0];
    var i = 0;
    while (i < s.length) {
      for (var k = 0; k < 2; k++) {
        var shift = 0, result = 0, b = 0x20;
        while (b >= 0x20) {
          if (i >= s.length) return points;
          b = s.codeUnitAt(i++) - 63;
          result |= (b & 0x1f) << shift;
          shift += 5;
        }
        // Not `~`: on the web, bitwise operators work on unsigned 32-bit
        // values, so ~2 is 4294967293 and every westward step went east.
        at[k] += (result & 1) != 0 ? -(result >> 1) - 1 : result >> 1;
      }
      points.add(LatLng(at[0] / 1e5, at[1] / 1e5));
    }
    return points;
  }

  /// Route colours from the app's own categorical set; an extra route is the
  /// caution colour, so "needs another technician" reads on the map too.
  Color _colour(BuildContext context, int i) {
    final route = _routes[i];
    if (route['extra'] == true) {
      return ToneColours.of(context, Tone.caution).ink;
    }
    const palette = EzBiziTheme.avatarPalette;
    return palette[i % palette.length];
  }

  void _fit() {
    if (!_mapReady) return;
    final points = [for (final r in _routes) ..._path(r)];
    if (points.isEmpty) return;
    _map.fitCamera(
      CameraFit.coordinates(
        coordinates: points,
        padding: const EdgeInsets.all(36),
        maxZoom: 14,
      ),
    );
  }

  Future<void> _pickDate() async {
    final now = DateUtils.dateOnly(DateTime.now());
    final picked = await showDatePicker(
      context: context,
      initialDate: _date,
      firstDate: now.subtract(const Duration(days: 30)),
      lastDate: now.add(const Duration(days: 120)),
    );
    if (picked == null || !mounted) return;
    setState(() => _date = picked);
    _load();
  }

  void _step(int days) {
    setState(() => _date = _date.add(Duration(days: days)));
    _load();
  }

  String get _dayLabel =>
      '${shortWeekdays[_date.weekday - 1]} ${shortMonths[_date.month - 1]} ${_date.day}';

  static String _span(Map<String, dynamic> stop) => [
    if (stop['time'] case final String t when t.isNotEmpty) JobDotCard.clock(t),
    if (stop['end_time'] case final String t when t.isNotEmpty)
      JobDotCard.clock(t),
  ].join('–');

  static String _miles(Object? m) {
    final n = (m as num?)?.toDouble() ?? 0;
    return '${n == n.roundToDouble() ? n.toInt() : n.toStringAsFixed(1)} mi';
  }

  static String _minutes(Object? m) {
    final n = (m as num?)?.toInt() ?? 0;
    return n < 60 ? '$n min' : '${n ~/ 60} h ${n % 60} min';
  }

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, box) {
        final side = box.maxWidth >= RoutingPane.sideAbove;
        return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            _bar(context),
            Expanded(
              child: AsyncBody(
                loading: _loading,
                error: _error,
                onRetry: _plan == null ? _start : _load,
                child: _body(context, side: side),
              ),
            ),
          ],
        );
      },
    );
  }

  Widget _bar(BuildContext context) {
    final theme = Theme.of(context);
    final routes = _routes;
    final extra = routes.where((r) => r['extra'] == true).length;
    final jobs = routes.fold<int>(0, (n, r) => n + _stops(r).length);

    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 8),
      child: Wrap(
        spacing: 10,
        runSpacing: 8,
        crossAxisAlignment: WrapCrossAlignment.center,
        children: [
          SizedBox(
            width: 220,
            child: SearchPickerField<String>(
              key: const ValueKey('routing-area'),
              label: 'Area',
              isDense: true,
              value: _area,
              searchFrom: 8,
              options: [
                for (final a in _areas ?? const <Map<String, dynamic>>[])
                  SearchOption(value: '${a['id']}', label: '${a['name']}'),
              ],
              onChanged: (v) {
                if (v == null || v == _area) return;
                setState(() => _area = v);
                _load();
              },
            ),
          ),
          Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              IconButton(
                key: const ValueKey('routing-day-back'),
                tooltip: 'The day before',
                onPressed: () => _step(-1),
                icon: const Icon(Icons.chevron_left),
              ),
              OutlinedButton.icon(
                key: const ValueKey('routing-day'),
                onPressed: _pickDate,
                icon: const Icon(Icons.event_outlined, size: 18),
                label: Text(_dayLabel),
              ),
              IconButton(
                key: const ValueKey('routing-day-next'),
                tooltip: 'The day after',
                onPressed: () => _step(1),
                icon: const Icon(Icons.chevron_right),
              ),
            ],
          ),
          if (_plan != null)
            Text(
              '$jobs ${jobs == 1 ? 'job' : 'jobs'} · '
              '${_plan!['technicians'] ?? 0} technicians available'
              '${extra > 0 ? ' · $extra more needed' : ''}',
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
        ],
      ),
    );
  }

  Widget _body(BuildContext context, {required bool side}) {
    if (_area == null) {
      return const Center(
        child: Padding(
          padding: EdgeInsets.all(32),
          child: Text(
            'No service areas yet. Add one in Scheduling › Settings to '
            'plan its routes.',
            textAlign: TextAlign.center,
          ),
        ),
      );
    }
    final routes = _routes;
    List<Map<String, dynamic>> jobs(String key) => [
      for (final u in (_plan?[key] as List? ?? const []))
        if (u is Map) u.cast<String, dynamic>(),
    ];
    final unplaced = jobs('unplaced');
    // 60 miles or more from the day's other jobs: nearly always booked in
    // the wrong area, so kept off the routes (Scheduling 44b28e96).
    final elsewhere = jobs('elsewhere');
    if (routes.every((r) => _stops(r).isEmpty) &&
        unplaced.isEmpty &&
        elsewhere.isEmpty) {
      return Center(
        key: const ValueKey('routing-empty'),
        child: Padding(
          padding: const EdgeInsets.all(32),
          child: Text(
            'No jobs booked in ${_plan?['area_name'] ?? 'this area'} on '
            '$_dayLabel.',
            textAlign: TextAlign.center,
          ),
        ),
      );
    }

    final cards = [
      for (final (i, _) in routes.indexed)
        if (_stops(routes[i]).isNotEmpty) _card(context, i, side: side),
      if (elsewhere.isNotEmpty)
        _unplaced(
          context,
          elsewhere,
          key: const ValueKey('routing-elsewhere'),
          title: 'Far outside this area · ${elsewhere.length}',
          why:
              "60 miles or more from the day's other jobs, so they are on no "
              "route. Check the booking's area.",
        ),
      if (unplaced.isNotEmpty) _unplaced(context, unplaced),
    ];

    if (side) {
      return Padding(
        padding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Expanded(child: _mapView(context)),
            const SizedBox(width: 12),
            SizedBox(
              width: 390,
              child: ListView(
                key: const ValueKey('routing-routes'),
                children: [
                  for (final c in cards)
                    Padding(
                      padding: const EdgeInsets.only(bottom: 8),
                      child: c,
                    ),
                ],
              ),
            ),
          ],
        ),
      );
    }

    // Phone 4: the route opened, in the tab, with ← back.
    final picked = _picked;
    if (_openOnPhone && picked != null && picked < routes.length) {
      return ListView(
        key: const ValueKey('routing-route-page'),
        padding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
        children: [
          Align(
            alignment: Alignment.centerLeft,
            child: TextButton.icon(
              key: const ValueKey('routing-back'),
              onPressed: () => setState(() => _openOnPhone = false),
              icon: const Icon(Icons.arrow_back, size: 18),
              label: const Text('All routes'),
            ),
          ),
          _card(context, picked, side: true, framed: false),
        ],
      );
    }
    return ListView(
      key: const ValueKey('routing-routes'),
      padding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
      children: [
        SizedBox(height: 220, child: _mapView(context)),
        const SizedBox(height: 12),
        for (final c in cards)
          Padding(padding: const EdgeInsets.only(bottom: 8), child: c),
      ],
    );
  }

  Widget _mapView(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final routes = _routes;
    final picked = _picked;

    double alpha(int i) => picked == null || picked == i ? 1 : 0.3;

    return ClipRRect(
      key: const ValueKey('routing-map'),
      borderRadius: BorderRadius.circular(12),
      child: Stack(
        children: [
          FutureBuilder<String?>(
            future: mapTileUrl(widget.api, Theme.of(context).brightness),
            builder: (context, tiles) => FlutterMap(
              mapController: _map,
              options: MapOptions(
                backgroundColor: scheme.surfaceContainerHighest,
                initialCenter: const LatLng(39.5, -98.35),
                initialZoom: 4,
                onMapReady: () {
                  _mapReady = true;
                  _fit();
                },
              ),
              children: [
                if (tiles.data != null)
                  TileLayer(
                    urlTemplate: tiles.data,
                    userAgentPackageName: 'com.ezbizi.app',
                  ),
                PolylineLayer(
                  polylines: [
                    for (final (i, r) in routes.indexed)
                      Polyline(
                        points: _path(r),
                        strokeWidth: picked == i ? 5 : 3,
                        color: _colour(context, i).withValues(alpha: alpha(i)),
                        pattern: r['extra'] == true
                            ? StrokePattern.dashed(segments: const [8, 6])
                            : const StrokePattern.solid(),
                      ),
                  ],
                ),
                MarkerLayer(
                  markers: [
                    for (final (i, r) in routes.indexed)
                      for (final (n, s) in _stops(r).indexed)
                        if (_at(s) case final at?)
                          Marker(
                            point: at,
                            width: 22,
                            height: 22,
                            child: Opacity(
                              opacity: alpha(i),
                              child: _StopBadge(
                                n: n + 1,
                                colour: _colour(context, i),
                                approximate: s['approximate'] == true,
                              ),
                            ),
                          ),
                  ],
                ),
              ],
            ),
          ),
          Positioned(
            top: 8,
            right: 8,
            child: Material(
              color: scheme.surface,
              borderRadius: BorderRadius.circular(8),
              child: IconButton(
                key: const ValueKey('routing-fit'),
                tooltip: 'Fit every route',
                visualDensity: VisualDensity.compact,
                onPressed: () {
                  setState(() => _picked = null);
                  _fit();
                },
                icon: const Icon(Icons.fit_screen_outlined, size: 18),
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _card(
    BuildContext context,
    int i, {
    required bool side,
    bool framed = true,
  }) {
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    final route = _routes[i];
    final stops = _stops(route);
    final extra = route['extra'] == true;
    final open = side && (_picked == i || !framed);
    final caution = ToneColours.of(context, Tone.caution);

    final content = Padding(
      padding: const EdgeInsets.all(12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Container(
                width: 12,
                height: 12,
                decoration: BoxDecoration(
                  color: _colour(context, i),
                  borderRadius: BorderRadius.circular(3),
                ),
              ),
              const SizedBox(width: 8),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      'Technician ${route['technician']}',
                      style: theme.textTheme.titleSmall,
                      overflow: TextOverflow.ellipsis,
                    ),
                    Text(
                      '${stops.length} ${stops.length == 1 ? 'stop' : 'stops'}'
                      '${switch (route['drive']) {
                        // Google's road miles and minutes.
                        {'miles': final mi, 'minutes': final mins} => ' · ${_miles(mi)} · ${_minutes(mins)} drive',
                        // No key, or Google refused: as the crow flies, said
                        // so, as the road is longer (Grok's review).
                        _ => ' · ≈ ${_miles(route['miles'])} straight-line',
                      }}',
                      style: muted,
                      overflow: TextOverflow.ellipsis,
                    ),
                  ],
                ),
              ),
            ],
          ),
          if (extra) ...[
            const SizedBox(height: 6),
            const Align(
              alignment: Alignment.centerLeft,
              child: StatusPill('Needs another technician', tone: Tone.caution),
            ),
          ],
          if (!open && stops.isNotEmpty) ...[
            const SizedBox(height: 4),
            Text(
              stops
                  .map((s) => '${(s['customer'] as Map?)?['name'] ?? '—'}')
                  .join(' › '),
              style: muted,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
            ),
          ],
          if (open)
            for (final (n, s) in stops.indexed) _stop(context, i, n, s),
        ],
      ),
    );

    if (!framed) return content;
    return Card(
      key: ValueKey('routing-route-$i'),
      margin: EdgeInsets.zero,
      color: extra ? caution.fill : null,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(12),
        side: BorderSide(
          color: _picked == i
              ? theme.colorScheme.primary
              : theme.colorScheme.outlineVariant,
          width: _picked == i ? 2 : 1,
        ),
      ),
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: () => setState(() {
          _picked = _picked == i && side ? null : i;
          if (!side) _openOnPhone = true;
        }),
        child: content,
      ),
    );
  }

  Widget _stop(BuildContext context, int route, int n, Map<String, dynamic> s) {
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    final customer = (s['customer'] as Map?)?.cast<String, dynamic>() ?? {};
    final what = [
      if (s['number'] != null) '#${s['number']}',
      if (s['service'] != null) '${s['service']}',
      if (customer['name'] != null) '${customer['name']}',
    ].join(' · ');

    return Padding(
      key: ValueKey('routing-stop-$route-$n'),
      padding: const EdgeInsets.only(top: 8),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _StopBadge(
            n: n + 1,
            colour: _colour(context, route),
            approximate: s['approximate'] == true,
          ),
          const SizedBox(width: 8),
          SizedBox(
            width: 92,
            child: Text(_span(s), style: theme.textTheme.labelMedium),
          ),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(what, style: theme.textTheme.bodyMedium),
                if (customer['address'] != null)
                  Text('${customer['address']}', style: muted),
                if (s['approximate'] == true)
                  Text(
                    'Approximate location: the centre of its zip code.',
                    style: muted,
                  ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _unplaced(
    BuildContext context,
    List<Map<String, dynamic>> jobs, {
    Key key = const ValueKey('routing-unplaced'),
    String? title,
    String why =
        'Workiz has no usable address for these, so they are on no route. '
        'Place them by hand.',
  }) {
    final theme = Theme.of(context);
    final caution = ToneColours.of(context, Tone.caution);
    return Card(
      key: key,
      margin: EdgeInsets.zero,
      color: caution.fill,
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              title ?? 'No location · ${jobs.length}',
              style: theme.textTheme.titleSmall,
            ),
            const SizedBox(height: 2),
            Text(why, style: theme.textTheme.bodySmall),
            for (final j in jobs)
              Padding(
                padding: const EdgeInsets.only(top: 6),
                child: Text(
                  [
                    if (j['number'] != null) '#${j['number']}',
                    if (j['service'] != null) '${j['service']}',
                    if ((j['customer'] as Map?)?['name'] != null)
                      '${(j['customer'] as Map)['name']}',
                    _span(j),
                  ].where((t) => t.isNotEmpty).join(' · '),
                ),
              ),
          ],
        ),
      ),
    );
  }
}

/// A stop's number on its route's colour; hollow when its place is only its
/// zip code's centre.
class _StopBadge extends StatelessWidget {
  const _StopBadge({
    required this.n,
    required this.colour,
    required this.approximate,
  });

  final int n;
  final Color colour;
  final bool approximate;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return Tooltip(
      message: approximate ? 'Approximate location' : 'Stop $n',
      child: Container(
        width: 22,
        height: 22,
        alignment: Alignment.center,
        decoration: BoxDecoration(
          color: approximate ? scheme.surface : colour,
          shape: BoxShape.circle,
          border: Border.all(
            color: approximate ? colour : scheme.surface,
            width: 2,
          ),
        ),
        child: Text(
          '$n',
          style: TextStyle(
            fontSize: 11,
            fontWeight: FontWeight.w800,
            color: approximate ? colour : scheme.surface,
          ),
        ),
      ),
    );
  }
}
