import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:latlong2/latlong.dart';
import 'package:url_launcher/url_launcher.dart';

import '../core/api_client.dart';
import 'side_panel.dart';

/// A setting that is a place, stored as "lat, lng" (UI queue 104, design 7).
/// The row shows the coordinates; the button opens an address search with a
/// map of the pin. The search runs on Find, not on every key: the lookup is
/// limited per organization.
class LocationButton extends StatelessWidget {
  const LocationButton({
    super.key,
    required this.label,
    required this.value,
    required this.editable,
    required this.api,
    required this.onChanged,
  });

  final String label;
  final Object? value;
  final bool editable;
  final ApiClient api;
  final Future<void> Function(String value) onChanged;

  @override
  Widget build(BuildContext context) {
    final text = '${value ?? ''}'.trim();
    return OutlinedButton.icon(
      key: const ValueKey('location-setting'),
      onPressed: !editable
          ? null
          : () async {
              final picked = await showSidePanel<String>(
                context,
                builder: (_) => _LocationPanel(
                  api: api,
                  label: label,
                  initial: parseLatLng(text),
                ),
              );
              if (picked != null) await onChanged(picked);
            },
      icon: const Icon(Icons.place_outlined, size: 18),
      label: Text(text.isEmpty ? 'Set the place' : text),
    );
  }
}

/// "41.0534, -73.5387" → a point, or null.
LatLng? parseLatLng(String text) {
  final parts = text.split(',').map((p) => double.tryParse(p.trim())).toList();
  if (parts.length != 2 || parts.contains(null)) return null;
  return LatLng(parts[0]!, parts[1]!);
}

class _LocationPanel extends StatefulWidget {
  const _LocationPanel({required this.api, required this.label, this.initial});

  final ApiClient api;
  final String label;
  final LatLng? initial;

  @override
  State<_LocationPanel> createState() => _LocationPanelState();
}

class _LocationPanelState extends State<_LocationPanel> {
  final _address = TextEditingController();
  late LatLng? _point = widget.initial;
  String? _found;
  String? _note;
  bool _busy = false;
  String? _tiles;

  @override
  void initState() {
    super.initState();
    _loadTiles();
  }

  @override
  void dispose() {
    _address.dispose();
    super.dispose();
  }

  /// The map only for somebody who may see Field's map tiles; a link to
  /// Google Maps otherwise.
  Future<void> _loadTiles() async {
    final response = await widget.api.get('/field/map/tiles', {
      'theme': 'light',
    });
    if (!mounted || !response.ok) return;
    final data = response.body['data'];
    final url = data is Map ? data['url'] as String? : data as String?;
    if (url != null) setState(() => _tiles = url);
  }

  Future<void> _find() async {
    final address = _address.text.trim();
    if (address.isEmpty) return;
    setState(() {
      _busy = true;
      _note = null;
    });
    final response = await widget.api.get('/geocode', {
      'address': address,
      'for': 'time_clock',
    });
    if (!mounted) return;
    final data = response.data;
    setState(() {
      _busy = false;
      if (!response.ok) {
        _note = response.message;
      } else if (data is! Map || data['lat'] == null) {
        _note = 'No place was found for that address.';
      } else {
        _point = LatLng(
          (data['lat'] as num).toDouble(),
          (data['lng'] as num).toDouble(),
        );
        _found = data['address'] as String?;
        if (data['exact'] == false) {
          _note = 'Not an exact match: check the pin is on the place.';
        }
      }
    });
  }

  String _value(LatLng p) =>
      '${p.latitude.toStringAsFixed(6)}, ${p.longitude.toStringAsFixed(6)}';

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final point = _point;

    return SidePanel(
      title: widget.label,
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: const Text('Cancel'),
        ),
        FilledButton(
          key: const ValueKey('location-save'),
          onPressed: point == null
              ? null
              : () => Navigator.of(context).pop(_value(point)),
          child: const Text('Use this place'),
        ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Expanded(
                child: TextField(
                  key: const ValueKey('location-address'),
                  controller: _address,
                  decoration: const InputDecoration(
                    isDense: true,
                    labelText: 'Address',
                  ),
                  onSubmitted: (_) => _find(),
                ),
              ),
              const SizedBox(width: 8),
              FilledButton.tonal(
                key: const ValueKey('location-find'),
                onPressed: _busy ? null : _find,
                child: const Text('Find'),
              ),
            ],
          ),
          if (_found != null)
            Padding(
              padding: const EdgeInsets.only(top: 8),
              child: Text(_found!, style: theme.textTheme.bodyMedium),
            ),
          if (_note != null)
            Padding(
              padding: const EdgeInsets.only(top: 6),
              child: Text(_note!, style: theme.textTheme.bodySmall),
            ),
          if (point != null) ...[
            const SizedBox(height: 12),
            if (_tiles != null)
              SizedBox(
                key: const ValueKey('location-map'),
                height: 220,
                child: ClipRRect(
                  borderRadius: BorderRadius.circular(10),
                  child: FlutterMap(
                    key: ValueKey(_value(point)),
                    options: MapOptions(initialCenter: point, initialZoom: 16),
                    children: [
                      TileLayer(urlTemplate: _tiles!),
                      MarkerLayer(
                        markers: [
                          Marker(
                            point: point,
                            child: Icon(
                              Icons.location_on,
                              color: theme.colorScheme.error,
                              size: 32,
                            ),
                          ),
                        ],
                      ),
                    ],
                  ),
                ),
              ),
            Padding(
              padding: const EdgeInsets.only(top: 6),
              child: Row(
                children: [
                  Expanded(
                    child: Text(
                      _value(point),
                      style: theme.textTheme.bodySmall,
                    ),
                  ),
                  TextButton(
                    onPressed: () => launchUrl(
                      Uri.parse(
                        'https://www.google.com/maps?q=${point.latitude},${point.longitude}',
                      ),
                      mode: LaunchMode.externalApplication,
                    ),
                    child: const Text('See it on Google Maps'),
                  ),
                ],
              ),
            ),
            Text(
              'The zone size is the next setting.',
              style: theme.textTheme.bodySmall,
            ),
          ],
        ],
      ),
    );
  }
}
