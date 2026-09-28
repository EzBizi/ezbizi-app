import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:latlong2/latlong.dart';

import '../../core/api_client.dart';
import '../../widgets/async_body.dart';

/// Field › Map: everyone you can see on a quiet map, a slim floating list of
/// who is tracked (since when, how far, how fast), and the selected person's
/// route for the day.
///
/// flutter_map rather than google_maps_flutter because the Windows desktop app
/// has no Google Maps SDK. The tiles are Google's (Map Tiles API), styled
/// light or dark to match the app (Jose, 2026-09-28, after A1 Tools' map).
class FieldMapTab extends StatefulWidget {
  const FieldMapTab({super.key, required this.api});

  final ApiClient api;

  @override
  State<FieldMapTab> createState() => _FieldMapTabState();
}

/// Closest the map zooms to fit anything. A person standing still sends a
/// trail whose points are all one place, and fitting that without a ceiling
/// asks for infinite zoom, which is what crashed the app on tapping a pin.
const _fitMaxZoom = 17.0;

class _FieldMapTabState extends State<FieldMapTab> {
  final _map = MapController();

  bool _loading = true;
  String? _error;
  final Map<String, String?> _tiles = {};
  final Set<String> _tilesAsked = {};
  List<Map<String, dynamic>> _people = const [];

  String? _selectedId;
  DateTime _day = DateTime.now();
  List<LatLng> _trail = const [];

  /// Null until the first build: open on a wide screen, folded to a chip on a phone.
  bool? _panelOpen;
  bool _wide = true;
  bool _firstFit = true;

  Timer? _refresh;
  String _copyright = '';
  Timer? _attributionDebounce;

  @override
  void initState() {
    super.initState();
    _load();
    // Live enough to watch somebody drive; the phone uploads about once a minute.
    _refresh = Timer.periodic(const Duration(seconds: 30), (_) {
      _load();
      if (_selectedId != null && _isToday) _loadTrail(fit: false);
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

  Map<String, dynamic>? get _selected {
    for (final p in _people) {
      if ((p['user'] as Map)['id'] == _selectedId) return p;
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
    final response = await widget.api.get('/field/map');
    if (!mounted) return;
    setState(() {
      _loading = false;
      _error = response.ok ? null : response.message;
      if (response.ok) {
        _people = (response.body['data'] as List? ?? const [])
            .cast<Map<String, dynamic>>();
      }
    });
    if (response.ok && _firstFit) {
      _firstFit = false;
      _fit(_people.map(_at).toList());
    }
  }

  Future<void> _loadTrail({bool fit = true}) async {
    final id = _selectedId;
    if (id == null) return;
    final date =
        '${_day.year}-${_day.month.toString().padLeft(2, '0')}-${_day.day.toString().padLeft(2, '0')}';
    final response = await widget.api.get('/field/map', {
      'user_id': id,
      'date': date,
    });
    if (!mounted || !response.ok || _selectedId != id) return;
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
    if (fit) _fit(points);
  }

  void _select(Map<String, dynamic> person) {
    final id = (person['user'] as Map)['id'] as String;
    setState(() {
      _selectedId = _selectedId == id ? null : id;
      _trail = const [];
      _day = DateTime.now();
    });
    if (_selectedId != null) {
      _fit([_at(person)]);
      _loadTrail();
    }
  }

  LatLng _at(Map<String, dynamic> p) =>
      LatLng((p['lat'] as num).toDouble(), (p['lng'] as num).toDouble());

  /// Fits any set of points without ever asking for infinite zoom.
  void _fit(List<LatLng> points) {
    if (points.isEmpty) return;
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
            // Clear of the floating list on a wide screen.
            padding: EdgeInsets.fromLTRB(_wide ? 300 : 48, 48, 48, 48),
            maxZoom: _fitMaxZoom,
          ),
        );
      }
    });
  }

  void _viewportChanged() {
    if (_tiles.values.every((u) => u == null)) return;
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
    final selected = _selected;

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
              onMapReady: _viewportChanged,
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
                  for (final p in _people)
                    Marker(
                      point: _at(p),
                      width: 140,
                      height: 64,
                      alignment: Alignment.topCenter,
                      child: GestureDetector(
                        onTap: () => _select(p),
                        child: _Pin(
                          name: '${(p['user'] as Map)['name']}',
                          colour: _colour(p, scheme),
                          selected: (p['user'] as Map)['id'] == _selectedId,
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
                    child: _Panel(
                      people: _people,
                      selectedId: _selectedId,
                      colour: (p) => _colour(p, scheme),
                      onSelect: _select,
                      onRefresh: _load,
                      onClose: () => setState(() => _panelOpen = false),
                      day: selected == null ? null : _day,
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
          if (tiles == null)
            Positioned(
              right: 12,
              top: 12,
              child: Chip(
                label: const Text(
                  'Street map appears once Google Maps is set up.',
                ),
                backgroundColor: scheme.surface,
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

class _Pin extends StatelessWidget {
  const _Pin({
    required this.name,
    required this.colour,
    required this.selected,
  });

  final String name;
  final Color colour;
  final bool selected;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        Icon(Icons.location_on, size: selected ? 38 : 30, color: colour),
        Container(
          padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 1),
          decoration: BoxDecoration(
            color: scheme.surface.withValues(alpha: 0.9),
            borderRadius: BorderRadius.circular(4),
          ),
          child: Text(
            name,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: TextStyle(
              fontSize: 11,
              fontWeight: selected ? FontWeight.w600 : FontWeight.w400,
              color: scheme.onSurface,
            ),
          ),
        ),
      ],
    );
  }
}

/// The floating list: slim on purpose (Jose: "not so bulky").
class _Panel extends StatelessWidget {
  const _Panel({
    required this.people,
    required this.selectedId,
    required this.colour,
    required this.onSelect,
    required this.onRefresh,
    required this.onClose,
    required this.day,
    required this.trailEmpty,
    required this.onPickDay,
  });

  final List<Map<String, dynamic>> people;
  final String? selectedId;
  final Color Function(Map<String, dynamic>) colour;
  final void Function(Map<String, dynamic>) onSelect;
  final VoidCallback onRefresh;
  final VoidCallback? onClose;
  final DateTime? day;
  final bool trailEmpty;
  final VoidCallback onPickDay;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: scheme.onSurfaceVariant,
    );
    final reporting = people.where((p) => p['fresh'] == true).length;

    return Material(
      elevation: 3,
      color: scheme.surface.withValues(alpha: 0.96),
      borderRadius: BorderRadius.circular(12),
      clipBehavior: Clip.antiAlias,
      child: SizedBox(
        width: 264,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(12, 8, 4, 4),
              child: Row(
                children: [
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text('Tracked', style: theme.textTheme.titleSmall),
                        Text(
                          '$reporting of ${people.length} reporting now',
                          style: muted,
                        ),
                      ],
                    ),
                  ),
                  IconButton(
                    tooltip: 'Refresh',
                    visualDensity: VisualDensity.compact,
                    icon: const Icon(Icons.refresh, size: 18),
                    onPressed: onRefresh,
                  ),
                  if (onClose != null)
                    IconButton(
                      tooltip: 'Hide',
                      visualDensity: VisualDensity.compact,
                      icon: const Icon(Icons.close, size: 18),
                      onPressed: onClose,
                    ),
                ],
              ),
            ),
            const Divider(height: 1),
            if (people.isEmpty)
              Padding(
                padding: const EdgeInsets.all(12),
                child: Text(
                  'Nobody you can see has reported yet.',
                  style: muted,
                ),
              ),
            Flexible(
              child: ListView(
                shrinkWrap: true,
                padding: EdgeInsets.zero,
                children: [
                  for (final p in people)
                    _Row(
                      person: p,
                      colour: colour(p),
                      selected: (p['user'] as Map)['id'] == selectedId,
                      onTap: () => onSelect(p),
                      day: (p['user'] as Map)['id'] == selectedId ? day : null,
                      trailEmpty: trailEmpty,
                      onPickDay: onPickDay,
                    ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _Row extends StatelessWidget {
  const _Row({
    required this.person,
    required this.colour,
    required this.selected,
    required this.onTap,
    required this.day,
    required this.trailEmpty,
    required this.onPickDay,
  });

  final Map<String, dynamic> person;
  final Color colour;
  final bool selected;
  final VoidCallback onTap;
  final DateTime? day;
  final bool trailEmpty;
  final VoidCallback onPickDay;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: scheme.onSurfaceVariant,
    );
    final since = _parse(person['since']);
    final seen = _parse(person['last_seen_at']);
    final speed = person['speed_mph'];
    final job = person['at_job'] as Map?;

    return Material(
      color: selected
          ? scheme.primaryContainer.withValues(alpha: 0.5)
          : scheme.surface.withValues(alpha: 0),
      child: InkWell(
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(12, 8, 12, 8),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Padding(
                padding: const EdgeInsets.only(top: 5),
                child: Container(
                  width: 8,
                  height: 8,
                  decoration: BoxDecoration(
                    color: colour,
                    shape: BoxShape.circle,
                  ),
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      '${(person['user'] as Map)['name']}',
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: theme.textTheme.bodyMedium?.copyWith(
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                    if (since != null)
                      Text(
                        'Since ${_clock(since)} · ${_span(DateTime.now().difference(since))}',
                        style: muted,
                      ),
                    Text(
                      [
                        if (seen != null) 'Seen ${_ago(seen)}',
                        if (speed is num && speed > 0) '$speed mph',
                        '${person['distance_mi'] ?? 0} mi',
                      ].join(' · '),
                      style: muted,
                    ),
                    if (job != null)
                      Text(
                        'At ${job['title'] ?? job['number'] ?? 'a job'}',
                        style: muted?.copyWith(color: scheme.tertiary),
                      ),
                    if (person['stopped'] == true)
                      Text(
                        'Stopped too long',
                        style: muted?.copyWith(color: scheme.error),
                      ),
                    if (person['silent'] == true)
                      Text(
                        'Lost signal',
                        style: muted?.copyWith(color: scheme.error),
                      ),
                    if (day != null)
                      Padding(
                        padding: const EdgeInsets.only(top: 4),
                        child: InkWell(
                          onTap: onPickDay,
                          child: Row(
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              Icon(
                                Icons.timeline,
                                size: 14,
                                color: scheme.primary,
                              ),
                              const SizedBox(width: 4),
                              Text(
                                'Route ${_dayLabel(day!)}${trailEmpty ? ' · none' : ''}',
                                style: muted?.copyWith(color: scheme.primary),
                              ),
                              Icon(
                                Icons.arrow_drop_down,
                                size: 16,
                                color: scheme.primary,
                              ),
                            ],
                          ),
                        ),
                      ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

DateTime? _parse(Object? iso) =>
    iso is String ? DateTime.tryParse(iso)?.toLocal() : null;

String _clock(DateTime t) {
  final h = t.hour % 12 == 0 ? 12 : t.hour % 12;
  return '$h:${t.minute.toString().padLeft(2, '0')} ${t.hour < 12 ? 'AM' : 'PM'}';
}

String _span(Duration d) => d.inHours > 0
    ? '${d.inHours}h ${d.inMinutes % 60}m'
    : '${d.inMinutes.clamp(0, 59)}m';

String _ago(DateTime t) {
  final d = DateTime.now().difference(t);
  if (d.inMinutes < 1) return 'just now';
  if (d.inMinutes < 60) return '${d.inMinutes} min ago';
  if (d.inHours < 24) return '${d.inHours}h ago';

  return '${t.month}/${t.day}';
}

String _dayLabel(DateTime d) {
  final now = DateTime.now();
  return d.year == now.year && d.month == now.month && d.day == now.day
      ? 'today'
      : '${d.month}/${d.day}';
}
