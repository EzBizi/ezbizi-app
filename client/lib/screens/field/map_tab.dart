import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:latlong2/latlong.dart';

import '../../core/api_client.dart';
import '../../widgets/async_body.dart';
import 'map_overlays.dart';

/// Field › Map: everyone you can see and the day's jobs on a quiet map.
///
/// - The floating list has People and (with the Jobs grant) Jobs, searchable
///   by number or customer.
/// - A person opens the technician bar and draws their route for the day.
/// - A job opens the job card: the nearest people by drive time, and once one
///   is picked, the road, the minutes and a sentence for the caller (Jose,
///   2026-09-30: "my dispatchers would use this if someone calls asking for
///   an ETA").
///
/// flutter_map rather than google_maps_flutter because the Windows desktop app
/// has no Google Maps SDK. The tiles are Google's (Map Tiles API), styled
/// light or dark to match the app.
class FieldMapTab extends StatefulWidget {
  const FieldMapTab({super.key, required this.api, this.canSeeJobs = false});

  final ApiClient api;

  /// `jobs.view`: customer names and addresses are Jobs' data.
  final bool canSeeJobs;

  @override
  State<FieldMapTab> createState() => _FieldMapTabState();
}

/// Closest the map zooms to fit anything. A person standing still sends a
/// trail whose points are all one place, and fitting that without a ceiling
/// asks for a zoom far past the tiles (measured on the emulator, 2026-09-28).
const _fitMaxZoom = 17.0;

class _FieldMapTabState extends State<FieldMapTab> {
  final _map = MapController();

  bool _loading = true;
  String? _error;
  final Map<String, String?> _tiles = {};
  final Set<String> _tilesAsked = {};
  List<Map<String, dynamic>> _people = const [];
  List<Map<String, dynamic>> _jobs = const [];

  String _tab = 'people';
  String _query = '';

  String? _personId;
  DateTime _day = DateTime.now();
  List<LatLng> _trail = const [];

  String? _jobId;
  Map<String, dynamic>? _eta;
  Map<String, dynamic>? _route;
  List<LatLng> _road = const [];
  bool _etaLoading = false;

  /// Null until the first build: open on a wide screen, a chip on a phone.
  bool? _panelOpen;
  bool _wide = true;
  bool _legendOpen = false;
  bool _firstFit = true;

  /// Camera moves wait for this: moved before the map has its real size, the
  /// tile layer loads nothing until somebody pans (Android session, 0.0.0290).
  bool _mapReady = false;
  List<LatLng>? _pendingFit;

  Timer? _refresh;
  String _copyright = '';
  Timer? _attributionDebounce;

  @override
  void initState() {
    super.initState();
    _load();
    _refresh = Timer.periodic(const Duration(seconds: 30), (_) {
      _load();
      if (_personId != null && _isToday) _loadTrail(fit: false);
    });
  }

  @override
  void dispose() {
    _refresh?.cancel();
    _attributionDebounce?.cancel();
    super.dispose();
  }

  bool get _isToday {
    final now = DateTime.now();
    return _day.year == now.year &&
        _day.month == now.month &&
        _day.day == now.day;
  }

  Map<String, dynamic>? _person(String? id) {
    for (final p in _people) {
      if ((p['user'] as Map)['id'] == id) return p;
    }
    return null;
  }

  Map<String, dynamic>? get _job {
    for (final j in _jobs) {
      if (j['job_id'] == _jobId) return j;
    }
    return null;
  }

  Future<void> _tilesFor(String theme) async {
    if (!_tilesAsked.add(theme)) return;
    final response = await widget.api.get('/field/map/tiles', {'theme': theme});
    if (!mounted) return;
    setState(
      () => _tiles[theme] = response.ok
          ? ((response.body['data'] as Map?)?['url'] as String?)
          : null,
    );
    _viewportChanged();
  }

  Future<void> _load() async {
    final responses = await Future.wait([
      widget.api.get('/field/map'),
      if (widget.canSeeJobs) widget.api.get('/field/jobs'),
    ]);
    if (!mounted) return;
    final people = responses.first;
    setState(() {
      _loading = false;
      _error = people.ok ? null : people.message;
      if (people.ok) {
        _people = (people.body['data'] as List? ?? const [])
            .cast<Map<String, dynamic>>();
      }
      if (responses.length > 1 && responses[1].ok) {
        _jobs = (responses[1].body['data'] as List? ?? const [])
            .cast<Map<String, dynamic>>();
      }
    });
    if (people.ok && _firstFit) {
      _firstFit = false;
      _fit([
        ..._people.map(_at),
        for (final j in _jobs)
          if (j['lat'] != null) _at(j),
      ]);
    }
  }

  Future<void> _loadTrail({bool fit = true}) async {
    final id = _personId;
    if (id == null) return;
    final date =
        '${_day.year}-${_day.month.toString().padLeft(2, '0')}-${_day.day.toString().padLeft(2, '0')}';
    final response = await widget.api.get('/field/map', {
      'user_id': id,
      'date': date,
    });
    if (!mounted || !response.ok || _personId != id) return;
    final points =
        (((response.body['data'] as Map?)?['points'] as List?) ?? const [])
            .cast<Map<String, dynamic>>()
            .map(_at)
            .toList();
    // Consecutive repeats dropped: standing still sends the same spot every
    // two minutes, and a line with no length is nothing to draw.
    final distinct = <LatLng>[];
    for (final p in points) {
      if (distinct.isEmpty || distinct.last != p) distinct.add(p);
    }
    setState(() => _trail = distinct);
    if (fit) _fit(distinct);
  }

  /// A person tapped. With a job open, that is "how far is this one?";
  /// otherwise it opens their bar and their route today.
  void _selectPerson(Map<String, dynamic> person) {
    final id = (person['user'] as Map)['id'] as String;
    if (_jobId != null) {
      _routeTo(id);
      return;
    }
    setState(() {
      _personId = _personId == id ? null : id;
      _trail = const [];
      _day = DateTime.now();
    });
    if (_personId != null) {
      _fit([_at(person)]);
      _loadTrail();
    }
  }

  Future<void> _selectJob(Map<String, dynamic> job) async {
    final id = job['job_id'] as String;
    if (_jobId == id) {
      _closeJob();
      return;
    }
    setState(() {
      _jobId = id;
      _personId = null;
      _trail = const [];
      _eta = null;
      _route = null;
      _road = const [];
      _etaLoading = true;
    });
    if (job['lat'] != null) _fit([_at(job)]);
    final response = await widget.api.get('/field/eta', {'job_id': id});
    if (!mounted || _jobId != id) return;
    setState(() {
      _etaLoading = false;
      _eta = response.ok
          ? response.body['data'] as Map<String, dynamic>
          : {'reason': response.message};
    });
    // Nearest first: frame the job with the people who could reach it.
    final lat = _eta?['lat'];
    if (lat != null) {
      _fit([
        LatLng((lat as num).toDouble(), (_eta!['lng'] as num).toDouble()),
        for (final c in ((_eta?['candidates'] as List?) ?? const []))
          if (_person('${(c as Map)['user']['id']}') case final p?) _at(p),
      ]);
    }
  }

  Future<void> _routeTo(String userId) async {
    final jobId = _jobId;
    if (jobId == null) return;
    setState(() => _etaLoading = true);
    final response = await widget.api.get('/field/eta', {
      'job_id': jobId,
      'user_id': userId,
    });
    if (!mounted || _jobId != jobId) return;
    final data = response.ok
        ? response.body['data'] as Map<String, dynamic>
        : {'reason': response.message};
    final road = data['polyline'] is String
        ? decodePolyline(data['polyline'] as String)
        : <LatLng>[];
    setState(() {
      _etaLoading = false;
      _route = data;
      _road = road;
    });
    final from = data['from'] as Map?;
    _fit([
      ...road,
      if (from != null) _at(from.cast<String, dynamic>()),
      if (data['lat'] != null) _at(data),
    ]);
  }

  void _closeJob() => setState(() {
    _jobId = null;
    _eta = null;
    _route = null;
    _road = const [];
  });

  LatLng _at(Map<String, dynamic> p) =>
      LatLng((p['lat'] as num).toDouble(), (p['lng'] as num).toDouble());

  /// Fits any set of points without ever asking for infinite zoom.
  void _fit(List<LatLng> points) {
    if (points.isEmpty) return;
    if (!_mapReady) {
      _pendingFit = points;
      return;
    }
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) return;
      final bounds = LatLngBounds.fromPoints(points);
      final tiny =
          (bounds.north - bounds.south).abs() < 0.0005 &&
          (bounds.east - bounds.west).abs() < 0.0005;
      if (tiny) {
        _map.move(bounds.center, 16);
      } else {
        _map.fitCamera(
          CameraFit.bounds(
            bounds: bounds,
            // Clear of the floating list, the bar and the job card.
            padding: EdgeInsets.fromLTRB(
              _wide ? 300 : 48,
              72,
              _wide && _jobId != null ? 340 : 48,
              48,
            ),
            maxZoom: _fitMaxZoom,
          ),
        );
      }
    });
  }

  void _viewportChanged() {
    if (!_mapReady || _tiles.values.every((u) => u == null)) return;
    _attributionDebounce?.cancel();
    _attributionDebounce = Timer(const Duration(milliseconds: 600), () async {
      final camera = _map.camera;
      final b = camera.visibleBounds;
      final response = await widget.api.get('/field/map/attribution', {
        'zoom': '${camera.zoom.round().clamp(0, 22)}',
        'north': '${b.north.clamp(-90, 90)}',
        'south': '${b.south.clamp(-90, 90)}',
        'east': '${b.east.clamp(-180, 180)}',
        'west': '${b.west.clamp(-180, 180)}',
      });
      if (!mounted || !response.ok) return;
      setState(
        () => _copyright =
            ((response.body['data'] as Map?)?['copyright'] as String?) ?? '',
      );
    });
  }

  Color _colour(Map<String, dynamic> p, ColorScheme scheme) {
    if (p['silent'] == true || p['fresh'] != true) return scheme.outline;
    if (p['stopped'] == true) return scheme.error;
    if (p['at_job'] != null) return scheme.tertiary;

    return scheme.primary;
  }

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final theme = Theme.of(context).brightness == Brightness.dark
        ? 'dark'
        : 'light';
    _tilesFor(theme);
    final tiles = _tiles[theme];
    final size = MediaQuery.sizeOf(context);
    final wide = _wide = size.width >= 600;
    final panelOpen = _panelOpen ?? wide;
    final person = _person(_personId);
    final job = _job;

    return AsyncBody(
      loading: _loading,
      error: _error,
      onRetry: _load,
      child: Stack(
        children: [
          FlutterMap(
            mapController: _map,
            options: MapOptions(
              initialCenter: const LatLng(39.5, -98.35), // the contiguous US
              initialZoom: 4,
              minZoom: 3,
              maxZoom: 19,
              backgroundColor: scheme.surfaceContainerHighest,
              onMapReady: () {
                _mapReady = true;
                final pending = _pendingFit;
                _pendingFit = null;
                if (pending != null) _fit(pending);
                _viewportChanged();
              },
              onPositionChanged: (_, _) => _viewportChanged(),
            ),
            children: [
              if (tiles != null)
                TileLayer(
                  key: ValueKey(theme),
                  urlTemplate: tiles,
                  userAgentPackageName: 'com.ezbizi.app',
                ),
              if (_trail.length > 1)
                PolylineLayer(
                  polylines: [
                    Polyline(
                      points: _trail,
                      strokeWidth: 4,
                      color: scheme.primary,
                    ),
                  ],
                ),
              if (_road.length > 1)
                PolylineLayer(
                  polylines: [
                    Polyline(
                      points: _road,
                      strokeWidth: 5,
                      color: scheme.tertiary,
                      pattern: StrokePattern.dashed(segments: const [10, 6]),
                    ),
                  ],
                ),
              MarkerLayer(
                markers: [
                  if (_trail.isNotEmpty)
                    Marker(
                      point: _trail.first,
                      width: 14,
                      height: 14,
                      child: Container(
                        decoration: BoxDecoration(
                          shape: BoxShape.circle,
                          color: scheme.surface,
                          border: Border.all(color: scheme.primary, width: 3),
                        ),
                      ),
                    ),
                  for (final j in _jobs)
                    if (j['lat'] != null)
                      Marker(
                        point: _at(j),
                        width: 150,
                        height: j['job_id'] == _jobId ? 52 : 26,
                        alignment: Alignment.bottomCenter,
                        child: GestureDetector(
                          onTap: () => _selectJob(j),
                          child: FieldJobPin(
                            label: '${j['customer'] ?? '#${j['number']}'}',
                            colour: j['job_id'] == _jobId
                                ? scheme.tertiary
                                : j['tech'] == null
                                ? scheme.outline
                                : scheme.secondary,
                            selected: j['job_id'] == _jobId,
                          ),
                        ),
                      ),
                  for (final p in _people)
                    Marker(
                      point: _at(p),
                      width: 140,
                      height: 64,
                      alignment: Alignment.topCenter,
                      child: GestureDetector(
                        onTap: () => _selectPerson(p),
                        child: FieldPersonPin(
                          name: '${(p['user'] as Map)['name']}',
                          colour: _colour(p, scheme),
                          selected:
                              (p['user'] as Map)['id'] == _personId ||
                              (_route?['user'] as Map?)?['id'] ==
                                  (p['user'] as Map)['id'],
                        ),
                      ),
                    ),
                ],
              ),
            ],
          ),
          Positioned(
            left: 12,
            top: 12,
            child: panelOpen
                ? ConstrainedBox(
                    constraints: BoxConstraints(maxHeight: size.height * 0.6),
                    child: FieldMapPanel(
                      people: _people,
                      jobs: _jobs,
                      showJobs: widget.canSeeJobs,
                      tab: _tab,
                      onTab: (t) => setState(() => _tab = t),
                      query: _query,
                      onQuery: (q) => setState(() => _query = q),
                      selectedPersonId: _personId,
                      selectedJobId: _jobId,
                      personColour: (p) => _colour(p, scheme),
                      onPerson: _selectPerson,
                      onJob: _selectJob,
                      onRefresh: _load,
                      onClose: () => setState(() => _panelOpen = false),
                      day: person == null ? null : _day,
                      trailEmpty: _trail.isEmpty,
                      onPickDay: () async {
                        final picked = await showDatePicker(
                          context: context,
                          initialDate: _day,
                          firstDate: DateTime.now().subtract(
                            const Duration(days: 90),
                          ),
                          lastDate: DateTime.now(),
                        );
                        if (picked == null) return;
                        setState(() => _day = picked);
                        _loadTrail();
                      },
                    ),
                  )
                : ActionChip(
                    avatar: const Icon(Icons.people_outline, size: 18),
                    label: Text('Tracked · ${_people.length}'),
                    onPressed: () => setState(() => _panelOpen = true),
                  ),
          ),
          if (person != null)
            Positioned(
              left: wide && panelOpen ? 288 : 12,
              right: 12,
              top: wide || !panelOpen ? 12 : size.height * 0.6 + 24,
              child: FieldPersonBar(
                person: person,
                colour: _colour(person, scheme),
                onClose: () => setState(() {
                  _personId = null;
                  _trail = const [];
                }),
              ),
            ),
          if (job != null)
            Positioned(
              right: 12,
              // Above the legend bar on a wide screen.
              bottom: wide ? 44 : 28,
              left: wide ? null : 12,
              child: FieldJobCard(
                job: job,
                eta: _eta,
                route: _route,
                loading: _etaLoading,
                onPerson: _routeTo,
                onClose: _closeJob,
              ),
            ),
          if (tiles == null)
            Positioned(
              right: 12,
              // Below the folded list on a phone, beside it on a wide screen.
              top: wide ? 12 : 60,
              child: Chip(
                label: const Text(
                  'Street map appears once Google Maps is set up.',
                ),
                backgroundColor: scheme.surface,
              ),
            ),
          // The legend: a bar along the bottom on a wide screen, a button that
          // opens it on a phone.
          if (wide)
            Positioned(
              left: 0,
              bottom: 0,
              right: tiles != null ? 300 : 0,
              child: Align(
                alignment: Alignment.bottomLeft,
                child: FieldMapLegend(showJobs: widget.canSeeJobs),
              ),
            )
          else if (_legendOpen)
            Positioned(
              left: 12,
              right: 12,
              bottom: 28,
              child: FieldMapLegend(
                showJobs: widget.canSeeJobs,
                onClose: () => setState(() => _legendOpen = false),
              ),
            )
          else if (job == null)
            Positioned(
              left: 12,
              bottom: 28,
              child: ActionChip(
                avatar: const Icon(Icons.info_outline, size: 18),
                label: const Text('Legend'),
                onPressed: () => setState(() => _legendOpen = true),
              ),
            ),
          if (tiles != null)
            Positioned(
              right: 0,
              bottom: 0,
              child: Container(
                padding: const EdgeInsets.fromLTRB(10, 4, 10, 5),
                color: scheme.surface.withValues(alpha: 0.85),
                child: Text(
                  _copyright.isEmpty
                      ? 'Google Maps'
                      : 'Google Maps · $_copyright',
                  style: TextStyle(fontSize: 11, color: scheme.onSurface),
                ),
              ),
            ),
        ],
      ),
    );
  }
}
