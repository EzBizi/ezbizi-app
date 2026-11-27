import 'dart:typed_data';

import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/files.dart';
import '../../widgets/error_note.dart';
import 'vehicle_card.dart';

/// Photos on the vehicle itself (`attachable_type=vehicles`).
class VehiclePhotosCard extends StatefulWidget {
  const VehiclePhotosCard({
    super.key,
    required this.api,
    required this.vehicleId,
    required this.canEdit,
  });

  final ApiClient api;
  final String vehicleId;
  final bool canEdit;

  @override
  State<VehiclePhotosCard> createState() => _VehiclePhotosCardState();
}

class _VehiclePhotosCardState extends State<VehiclePhotosCard> {
  List<Map<String, dynamic>> _photos = const [];

  /// Only the newest load may land: a slow answer for the previous record
  /// must not replace this one's files, or Remove would aim at its file
  /// (audit 2026-11-27).
  int _loadSeq = 0;
  final Map<String, Uint8List> _bytes = {};
  bool _loading = true;
  bool _busy = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void didUpdateWidget(covariant VehiclePhotosCard oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.vehicleId != widget.vehicleId) _load();
  }

  Future<void> _load() async {
    final seq = ++_loadSeq;
    setState(() {
      _loading = _photos.isEmpty;
      _error = null;
    });

    final response = await widget.api.get('/attachments', {
      'attachable_type': 'vehicles',
      'attachable_id': widget.vehicleId,
    });

    if (!mounted || seq != _loadSeq) return;

    if (!response.ok) {
      setState(() {
        _loading = false;
        _error = response.message;
      });
      return;
    }

    final rows = [
      for (final row in (response.data as List? ?? const []))
        if (row is Map) Map<String, dynamic>.from(row),
    ];

    setState(() {
      _loading = false;
      _photos = rows;
    });

    for (final photo in rows) {
      final id = photo['id'] as String?;
      final mime = photo['mime'] as String? ?? '';
      if (id == null || _bytes.containsKey(id) || !mime.startsWith('image/')) {
        continue;
      }
      final bytes = await widget.api.getBytes('/attachments/$id');
      if (!mounted || bytes == null) continue;
      setState(() => _bytes[id] = Uint8List.fromList(bytes));
    }
  }

  Future<void> _add() async {
    final picked = await Files.pickImage();
    if (picked == null || !mounted) return;

    setState(() {
      _busy = true;
      _error = null;
    });

    final response = await widget.api.postFile(
      '/attachments',
      'file',
      picked.name,
      picked.bytes,
      fields: {
        'attachable_type': 'vehicles',
        'attachable_id': widget.vehicleId,
      },
    );

    if (!mounted) return;

    setState(() => _busy = false);

    if (!response.ok) {
      setState(() => _error = response.reason);
      return;
    }

    await _load();
  }

  Future<void> _remove(Map<String, dynamic> photo) async {
    final id = photo['id'] as String?;
    if (id == null) return;

    setState(() {
      _busy = true;
      _error = null;
    });

    final response = await widget.api.delete('/attachments/$id');

    if (!mounted) return;

    setState(() => _busy = false);

    if (!response.ok) {
      setState(() => _error = response.reason);
      return;
    }

    _bytes.remove(id);
    await _load();
  }

  @override
  Widget build(BuildContext context) {
    return FleetSectionCard(
      title: 'Photos',
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (_loading)
            const Padding(
              padding: EdgeInsets.symmetric(vertical: 8),
              child: LinearProgressIndicator(),
            )
          else if (_photos.isEmpty)
            Text(
              'No photos yet.',
              style: Theme.of(context).textTheme.bodyMedium,
            )
          else
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                for (final photo in _photos) _tile(context, photo),
              ],
            ),
          if (widget.canEdit) ...[
            const SizedBox(height: 8),
            Align(
              alignment: Alignment.centerLeft,
              child: FilledButton.tonalIcon(
                onPressed: _busy ? null : _add,
                icon: const Icon(Icons.add_a_photo, size: 18),
                label: const Text('Add photo'),
              ),
            ),
          ],
          if (_error != null) ...[
            const SizedBox(height: 8),
            ErrorNote(_error!),
          ],
        ],
      ),
    );
  }

  Widget _tile(BuildContext context, Map<String, dynamic> photo) {
    final id = photo['id'] as String? ?? '';
    final name = photo['original_name'] as String? ?? 'Photo';
    final bytes = _bytes[id];

    return SizedBox(
      width: 140,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          AspectRatio(
            aspectRatio: 4 / 3,
            child: DecoratedBox(
              decoration: BoxDecoration(
                color: Theme.of(context).colorScheme.surfaceContainerHighest,
                borderRadius: BorderRadius.circular(8),
              ),
              child: bytes == null
                  ? const Center(child: Icon(Icons.image_outlined))
                  : ClipRRect(
                      borderRadius: BorderRadius.circular(8),
                      child: Image.memory(bytes, fit: BoxFit.cover),
                    ),
            ),
          ),
          const SizedBox(height: 4),
          Text(name, maxLines: 1, overflow: TextOverflow.ellipsis),
          if (widget.canEdit)
            Align(
              alignment: Alignment.centerLeft,
              child: TextButton(
                onPressed: _busy ? null : () => _remove(photo),
                child: const Text('Remove'),
              ),
            ),
        ],
      ),
    );
  }
}
