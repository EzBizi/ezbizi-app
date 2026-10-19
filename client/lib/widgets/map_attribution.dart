import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';

import '../core/api_client.dart';

/// Google's copyright line, which must show under its map tiles. A child of a
/// FlutterMap: it reads the map's camera and asks Field's
/// `GET /field/map/attribution` for the visible area, a moment after the map
/// stops moving. Drawn at the foot of the map.
class MapAttributionLine extends StatefulWidget {
  const MapAttributionLine({super.key, required this.api});

  final ApiClient api;

  @override
  State<MapAttributionLine> createState() => _MapAttributionLineState();
}

class _MapAttributionLineState extends State<MapAttributionLine> {
  String _copyright = '';
  Timer? _debounce;
  String? _asked;

  @override
  void dispose() {
    _debounce?.cancel();
    super.dispose();
  }

  void _ask(MapCamera camera) {
    final b = camera.visibleBounds;
    final zoom = camera.zoom.round().clamp(0, 22);
    final key =
        '$zoom ${b.north.toStringAsFixed(2)} ${b.south.toStringAsFixed(2)} '
        '${b.east.toStringAsFixed(2)} ${b.west.toStringAsFixed(2)}';
    if (key == _asked) return;
    _asked = key;
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 600), () async {
      final response = await widget.api.get('/field/map/attribution', {
        'zoom': '$zoom',
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
  Widget build(BuildContext context) {
    _ask(MapCamera.of(context));
    final theme = Theme.of(context);

    return Align(
      alignment: Alignment.bottomLeft,
      child: Container(
        key: const ValueKey('map-attribution'),
        margin: const EdgeInsets.all(4),
        padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
        color: Colors.white.withValues(alpha: 0.8),
        child: Text(
          _copyright.isEmpty ? 'Google Maps' : 'Google Maps · $_copyright',
          style: theme.textTheme.labelSmall?.copyWith(
            fontSize: 10,
            color: Colors.black87,
          ),
        ),
      ),
    );
  }
}
