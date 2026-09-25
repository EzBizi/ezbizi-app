import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:latlong2/latlong.dart';

import '../../core/api_client.dart';
import '../../widgets/async_body.dart';

/// Field › Map: everyone you can see, where they last reported. Tap a person
/// for their trail on a day.
///
/// flutter_map rather than google_maps_flutter because the Windows desktop app
/// has no Google Maps SDK; the tiles are still Google's (Map Tiles API), via a
/// URL the server hands over. With no key the map is a blank grid and the
/// markers still stand in the right places relative to each other.
class FieldMapTab extends StatefulWidget {
  const FieldMapTab({super.key, required this.api});

  final ApiClient api;

  @override
  State<FieldMapTab> createState() => _FieldMapTabState();
}

class _FieldMapTabState extends State<FieldMapTab> {
  final _map = MapController();

  bool _loading = true;
  String? _error;
  String? _tiles;
  List<Map<String, dynamic>> _people = const [];

  Map<String, dynamic>? _selected;
  DateTime _day = DateTime.now();
  List<LatLng> _trail = const [];

  /// Google's copyright line for what is on screen. Map Tiles policy: shown
  /// in full, bottom right, beside "Google Maps", and asked for per viewport.
  String _copyright = '';
  Timer? _attributionDebounce;

  @override
  void dispose() {
    _attributionDebounce?.cancel();
    super.dispose();
  }

  void _viewportChanged() {
    if (_tiles == null) return;
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

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _error = null);
    final responses = await Future.wait([
      widget.api.get('/field/map'),
      if (_tiles == null) widget.api.get('/field/map/tiles'),
    ]);
    if (!mounted) return;
    setState(() {
      _loading = false;
      if (!responses.first.ok) {
        _error = responses.first.message;
        return;
      }
      _people = (responses.first.body['data'] as List? ?? const [])
          .cast<Map<String, dynamic>>();
      if (responses.length > 1 && responses[1].ok) {
        _tiles = (responses[1].body['data'] as Map?)?['url'] as String?;
      }
    });
    _fit(_people.map(_at).toList());
    _viewportChanged();
  }

  Future<void> _loadTrail() async {
    final person = _selected;
    if (person == null) return;
    final id = (person['user'] as Map)['id'];
    final date =
        '${_day.year}-${_day.month.toString().padLeft(2, '0')}-${_day.day.toString().padLeft(2, '0')}';
    final response = await widget.api.get('/field/map', {
      'user_id': '$id',
      'date': date,
    });
    if (!mounted || !response.ok) return;
    final points = ((response.body['data'] as Map)['points'] as List)
        .cast<Map<String, dynamic>>()
        .map(_at)
        .toList();
    setState(() => _trail = points);
    _fit(points);
  }

  LatLng _at(Map<String, dynamic> p) =>
      LatLng((p['lat'] as num).toDouble(), (p['lng'] as num).toDouble());

  void _fit(List<LatLng> points) {
    if (points.isEmpty) return;
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) return;
      if (points.length == 1) {
        _map.move(points.first, 15);
      } else {
        _map.fitCamera(
          CameraFit.bounds(
            bounds: LatLngBounds.fromPoints(points),
            padding: const EdgeInsets.all(48),
          ),
        );
      }
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
              onMapReady: _viewportChanged,
              onPositionChanged: (_, _) => _viewportChanged(),
            ),
            children: [
              if (_tiles != null)
                TileLayer(
                  urlTemplate: _tiles,
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
                  for (final p in _people)
                    Marker(
                      point: _at(p),
                      width: 140,
                      height: 56,
                      child: GestureDetector(
                        onTap: () {
                          setState(() {
                            _selected = p;
                            _trail = const [];
                          });
                          _loadTrail();
                        },
                        child: Column(
                          children: [
                            Icon(
                              Icons.location_on,
                              size: 32,
                              color: _colour(p, scheme),
                            ),
                            Container(
                              padding: const EdgeInsets.symmetric(
                                horizontal: 4,
                              ),
                              color: scheme.surface.withValues(alpha: 0.85),
                              child: Text(
                                '${(p['user'] as Map)['name']}',
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                                style: const TextStyle(fontSize: 11),
                              ),
                            ),
                          ],
                        ),
                      ),
                    ),
                ],
              ),
            ],
          ),
          if (_tiles != null)
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
          Positioned(
            left: 12,
            top: 12,
            right: 12,
            child: Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                ActionChip(
                  avatar: const Icon(Icons.refresh, size: 18),
                  label: const Text('Refresh'),
                  onPressed: _load,
                ),
                if (_people.isEmpty)
                  const Chip(
                    label: Text('Nobody you can see has reported yet.'),
                  ),
                if (_tiles == null)
                  const Chip(
                    label: Text(
                      'Street map appears once Google Maps is set up.',
                    ),
                  ),
                if (_selected != null)
                  InputChip(
                    avatar: const Icon(Icons.timeline, size: 18),
                    label: Text(
                      '${(_selected!['user'] as Map)['name']} · '
                      '${_day.month}/${_day.day}'
                      '${_trail.isEmpty ? ' · no trail' : ''}',
                    ),
                    onPressed: () async {
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
                    onDeleted: () => setState(() {
                      _selected = null;
                      _trail = const [];
                    }),
                  ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
