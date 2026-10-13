import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:latlong2/latlong.dart';

import '../../core/api_client.dart';
import '../../core/theme.dart';

/// Earliest and Suggested, with the route that explains the suggestion (UI
/// queue 83; Jose, 2026-10-13: "go ahea with ur recommendations" — 1 and 4).
///
/// Suggested is the open window nearest work already booked, so a technician
/// can do both. The map draws a line from the searched zip to each job that
/// day, nearest first; the ones inside the radius light up. It plays once,
/// Replay plays it again, and with animations switched off the finished
/// picture shows.
///
/// Reads `GET /scheduling/availability/suggestion?zip=` as Scheduling sends it.
class AvailabilitySuggestion extends StatelessWidget {
  const AvailabilitySuggestion({
    super.key,
    required this.api,
    required this.data,
    required this.zip,
    required this.windowChip,
    required this.longDate,
  });

  final ApiClient api;
  final Map<String, dynamic> data;
  final String zip;

  /// The pane's own window chip and date words, so these cards read exactly
  /// like the rows under them.
  final Widget Function(Map<String, dynamic> window) windowChip;
  final String Function(String ymd) longDate;

  @override
  Widget build(BuildContext context) {
    final earliest = (data['earliest'] as Map?)?.cast<String, dynamic>();
    final suggested = (data['suggested'] as Map?)?.cast<String, dynamic>();
    if (earliest == null) return const SizedBox.shrink();

    final radius = (data['radius_miles'] as num?) ?? 5;
    final days = (data['days'] as num?)?.toInt() ?? 14;
    final same = suggested?['same_as_earliest'] == true;
    final origin = (data['origin'] as Map?)?.cast<String, dynamic>();
    final shown = same || suggested == null ? earliest : suggested;
    final jobs = ((shown['jobs'] as List?) ?? const [])
        .whereType<Map>()
        .map((j) => j.cast<String, dynamic>())
        .where((j) => j['latitude'] is num && j['longitude'] is num)
        .toList();
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );

    final earliestCard = _Card(
      key: const Key('availability-earliest'),
      kicker: same ? 'Earliest · and the best fit' : 'Earliest',
      highlight: same,
      date: longDate(earliest['date'] as String),
      window: windowChip(_window(earliest)),
      why: same
          ? _nearby(earliest, radius)
          : _nearestLine(earliest['nearest_miles']),
      whyTone: same ? null : Tone.caution,
    );

    final cards = <Widget>[
      earliestCard,
      if (suggested != null && !same)
        _Card(
          key: const Key('availability-suggested'),
          kicker: 'Suggested · near booked work',
          highlight: true,
          date: longDate(suggested['date'] as String),
          window: windowChip(_window(suggested)),
          why: _nearby(suggested, radius),
          technicians: _names(suggested['technicians']),
        ),
    ];

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        LayoutBuilder(
          builder: (context, box) => box.maxWidth < 520 || cards.length == 1
              ? Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    for (final (i, c) in cards.indexed) ...[
                      if (i > 0) const SizedBox(height: 10),
                      c,
                    ],
                  ],
                )
              : IntrinsicHeight(
                  child: Row(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      Expanded(child: cards[0]),
                      const SizedBox(width: 12),
                      Expanded(child: cards[1]),
                    ],
                  ),
                ),
        ),
        const SizedBox(height: 8),
        if (same)
          Text(
            'Nothing later is nearer booked work, so the earliest window is '
            'the one to book.',
            key: const Key('availability-same-as-earliest'),
            style: muted,
          )
        else if (suggested == null)
          Text(
            'No suggestion: no booked job is within ${_miles(radius)} of $zip '
            'in the next $days days. The radius is in Settings › Scheduling.',
            key: const Key('availability-no-suggestion'),
            style: muted,
          ),
        if (origin != null &&
            origin['latitude'] is num &&
            origin['longitude'] is num &&
            jobs.isNotEmpty) ...[
          const SizedBox(height: 10),
          RouteMap(
            key: ValueKey('availability-route-${shown['date']}'),
            api: api,
            origin: LatLng(
              (origin['latitude'] as num).toDouble(),
              (origin['longitude'] as num).toDouble(),
            ),
            originLabel: zip,
            radiusMiles: radius.toDouble(),
            jobs: jobs,
            caption:
                'Straight-line miles · ${longDate(shown['date'] as String)}'
                "'s booked jobs",
          ),
        ],
      ],
    );
  }

  static Map<String, dynamic> _window(Map<String, dynamic> day) =>
      (day['window'] as Map?)?.cast<String, dynamic>() ?? const {};

  static String _miles(num miles) {
    final text = miles == miles.roundToDouble()
        ? '${miles.toInt()}'
        : miles.toStringAsFixed(1);
    return '$text mi';
  }

  static String _nearestLine(Object? nearest) => nearest is num
      ? 'Nearest booked job that day: ${_miles(nearest)} away.'
      : 'No other job is booked that day.';

  static String _nearby(Map<String, dynamic> day, num radius) {
    final n = (day['nearby'] as num?)?.toInt() ?? 0;
    final nearest = day['nearest_miles'];
    final jobs = n == 1 ? '1 job' : '$n jobs';
    return nearest is num
        ? '$jobs within ${_miles(radius)} that day, the nearest '
              '${_miles(nearest)} away.'
        : '$jobs within ${_miles(radius)} that day.';
  }

  /// Technicians as names, whether the server sends strings or people.
  static List<String> _names(Object? raw) => [
    for (final t in (raw as List?) ?? const [])
      if (t is String)
        t
      else if (t is Map && t['name'] is String)
        t['name'] as String,
  ];
}

class _Card extends StatelessWidget {
  const _Card({
    super.key,
    required this.kicker,
    required this.date,
    required this.window,
    required this.why,
    this.highlight = false,
    this.whyTone,
    this.technicians = const [],
  });

  final String kicker;
  final String date;
  final Widget window;
  final String why;
  final bool highlight;
  final Tone? whyTone;
  final List<String> technicians;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;

    return Container(
      padding: const EdgeInsets.fromLTRB(14, 12, 14, 12),
      decoration: BoxDecoration(
        color: scheme.surface,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(
          color: highlight ? scheme.primary : EzBiziTheme.edge(context),
          width: highlight ? 2 : 1,
        ),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            kicker.toUpperCase(),
            style: theme.textTheme.labelSmall?.copyWith(
              color: highlight ? scheme.primary : scheme.onSurfaceVariant,
              letterSpacing: 0.8,
              fontWeight: FontWeight.w700,
            ),
          ),
          const SizedBox(height: 4),
          Text(
            date,
            style: theme.textTheme.titleMedium?.copyWith(
              fontWeight: FontWeight.w800,
              color: ToneColours.of(context, Tone.ok).ink,
            ),
          ),
          const SizedBox(height: 8),
          window,
          const SizedBox(height: 8),
          Text(
            why,
            style: theme.textTheme.bodySmall?.copyWith(
              color: whyTone == null
                  ? scheme.onSurface
                  : ToneColours.of(context, whyTone!).ink,
              fontWeight: whyTone == null ? null : FontWeight.w600,
            ),
          ),
          if (technicians.isNotEmpty) ...[
            const SizedBox(height: 8),
            Wrap(
              spacing: 6,
              runSpacing: 4,
              children: [
                for (final name in technicians)
                  Chip(
                    visualDensity: VisualDensity.compact,
                    avatar: const Icon(Icons.person_outline, size: 16),
                    label: Text(name, overflow: TextOverflow.ellipsis),
                  ),
              ],
            ),
          ],
        ],
      ),
    );
  }
}

/// The searched zip, the radius ring, and a line drawn out to each job in
/// turn, nearest first. Not interactive: it sits inside a scrolling pane,
/// and a map that took the scroll would trap it.
class RouteMap extends StatefulWidget {
  const RouteMap({
    super.key,
    required this.api,
    required this.origin,
    required this.originLabel,
    required this.radiusMiles,
    required this.jobs,
    required this.caption,
  });

  final ApiClient api;
  final LatLng origin;
  final String originLabel;
  final double radiusMiles;
  final List<Map<String, dynamic>> jobs;
  final String caption;

  @override
  State<RouteMap> createState() => _RouteMapState();
}

/// Field's street map, when the organization has one set up; asked once a
/// session per theme and shared by every map here.
final _tiles = <String, Future<String?>>{};

class _RouteMapState extends State<RouteMap>
    with SingleTickerProviderStateMixin {
  late final AnimationController _draw = AnimationController(
    vsync: this,
    // A line per job, quick enough that ten of them finish in four seconds.
    duration: Duration(milliseconds: math.min(600 * widget.jobs.length, 4000)),
  );
  String? _tileUrl;

  late final List<Map<String, dynamic>> _ordered = [...widget.jobs]
    ..sort(
      (a, b) =>
          ((a['miles'] as num?) ?? 0).compareTo((b['miles'] as num?) ?? 0),
    );

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    final theme = Theme.of(context).brightness == Brightness.dark
        ? 'dark'
        : 'light';
    (_tiles[theme] ??= widget.api
            .get('/field/map/tiles', {'theme': theme})
            .then(
              (r) => switch (r.body['data']) {
                {'url': final String url} when r.ok => url,
                _ => null,
              },
            ))
        .then((url) {
          if (mounted && url != _tileUrl) setState(() => _tileUrl = url);
        });

    // Switched-off animations get the finished picture.
    if (MediaQuery.disableAnimationsOf(context)) {
      _draw.value = 1;
    } else if (!_draw.isAnimating && _draw.value == 0) {
      _draw.forward();
    }
  }

  @override
  void dispose() {
    _draw.dispose();
    super.dispose();
  }

  LatLng _at(Map<String, dynamic> job) => LatLng(
    (job['latitude'] as num).toDouble(),
    (job['longitude'] as num).toDouble(),
  );

  bool _near(Map<String, dynamic> job) =>
      job['nearby'] == true ||
      ((job['miles'] as num?) ?? double.infinity) <= widget.radiusMiles;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final ok = ToneColours.of(context, Tone.ok);
    final far = scheme.outline;
    final phone = MediaQuery.sizeOf(context).width < 600;
    // The jobs, and the ring's own edges, so the radius is never cut off.
    final lat = widget.radiusMiles / 69;
    final lng = lat / math.cos(widget.origin.latitude * math.pi / 180);
    final points = [
      for (final (dy, dx) in [(lat, 0.0), (-lat, 0.0), (0.0, lng), (0.0, -lng)])
        LatLng(widget.origin.latitude + dy, widget.origin.longitude + dx),
      for (final j in _ordered) _at(j),
    ];

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        ClipRRect(
          borderRadius: BorderRadius.circular(12),
          child: SizedBox(
            height: phone ? 160 : 200,
            child: Stack(
              children: [
                FlutterMap(
                  options: MapOptions(
                    initialCameraFit: CameraFit.coordinates(
                      coordinates: points,
                      padding: const EdgeInsets.all(28),
                      maxZoom: 13,
                    ),
                    backgroundColor: scheme.surfaceContainerHighest,
                    interactionOptions: const InteractionOptions(
                      flags: InteractiveFlag.none,
                    ),
                  ),
                  children: [
                    if (_tileUrl != null)
                      TileLayer(
                        urlTemplate: _tileUrl,
                        userAgentPackageName: 'com.ezbizi.app',
                      ),
                    CircleLayer(
                      circles: [
                        CircleMarker(
                          point: widget.origin,
                          radius: widget.radiusMiles * 1609.344,
                          useRadiusInMeter: true,
                          color: ok.fill.withValues(alpha: 0.45),
                          borderColor: ok.ink.withValues(alpha: 0.5),
                          borderStrokeWidth: 1,
                        ),
                      ],
                    ),
                    AnimatedBuilder(
                      animation: _draw,
                      builder: (context, _) {
                        final t = _draw.value * _ordered.length;
                        return PolylineLayer(
                          polylines: [
                            for (final (i, job) in _ordered.indexed)
                              if (t > i)
                                Polyline(
                                  points: [
                                    widget.origin,
                                    _lerp(
                                      widget.origin,
                                      _at(job),
                                      (t - i).clamp(0, 1).toDouble(),
                                    ),
                                  ],
                                  strokeWidth: _near(job) ? 3 : 2,
                                  color: _near(job) ? ok.ink : far,
                                  pattern: _near(job)
                                      ? const StrokePattern.solid()
                                      : StrokePattern.dashed(
                                          segments: const [6, 5],
                                        ),
                                ),
                          ],
                        );
                      },
                    ),
                    // Each job's dot lands as its line reaches it.
                    AnimatedBuilder(
                      animation: _draw,
                      builder: (context, _) {
                        final t = _draw.value * _ordered.length;
                        return MarkerLayer(
                          markers: [
                            for (final (i, job) in _ordered.indexed)
                              if (t >= i + 1)
                                Marker(
                                  point: _at(job),
                                  width: 16,
                                  height: 16,
                                  child: _Dot(
                                    colour: _near(job) ? ok.ink : far,
                                  ),
                                ),
                          ],
                        );
                      },
                    ),
                    MarkerLayer(
                      markers: [
                        Marker(
                          point: widget.origin,
                          width: 22,
                          height: 22,
                          child: _Dot(colour: scheme.primary, size: 18),
                        ),
                      ],
                    ),
                  ],
                ),
                Positioned(
                  right: 6,
                  top: 6,
                  child: Material(
                    color: scheme.surface,
                    borderRadius: BorderRadius.circular(8),
                    child: TextButton.icon(
                      key: const Key('availability-route-replay'),
                      style: TextButton.styleFrom(
                        visualDensity: VisualDensity.compact,
                      ),
                      onPressed: () => _draw.forward(from: 0),
                      icon: const Icon(Icons.replay, size: 16),
                      label: const Text('Replay'),
                    ),
                  ),
                ),
              ],
            ),
          ),
        ),
        const SizedBox(height: 6),
        Wrap(
          spacing: 12,
          runSpacing: 4,
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            _Legend(colour: scheme.primary, label: widget.originLabel),
            _Legend(
              colour: ok.ink,
              label:
                  'Within ${AvailabilitySuggestion._miles(widget.radiusMiles)}',
            ),
            _Legend(colour: far, label: 'Farther'),
            Text(
              widget.caption,
              style: theme.textTheme.bodySmall?.copyWith(
                color: scheme.onSurfaceVariant,
              ),
            ),
          ],
        ),
      ],
    );
  }

  static LatLng _lerp(LatLng a, LatLng b, double t) => LatLng(
    a.latitude + (b.latitude - a.latitude) * t,
    a.longitude + (b.longitude - a.longitude) * t,
  );
}

class _Dot extends StatelessWidget {
  const _Dot({required this.colour, this.size = 12});

  final Color colour;
  final double size;

  @override
  Widget build(BuildContext context) => Center(
    child: Container(
      width: size,
      height: size,
      decoration: BoxDecoration(
        color: colour,
        shape: BoxShape.circle,
        border: Border.all(
          color: Theme.of(context).colorScheme.surface,
          width: 2,
        ),
      ),
    ),
  );
}

class _Legend extends StatelessWidget {
  const _Legend({required this.colour, required this.label});

  final Color colour;
  final String label;

  @override
  Widget build(BuildContext context) => Row(
    mainAxisSize: MainAxisSize.min,
    children: [
      _Dot(colour: colour, size: 10),
      const SizedBox(width: 4),
      Text(label, style: Theme.of(context).textTheme.bodySmall),
    ],
  );
}
