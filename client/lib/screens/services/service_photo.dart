import 'dart:typed_data';
import 'dart:ui' as ui;

import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/files.dart';
import '../../widgets/error_note.dart';

/// One square photo on a service. 1:1 is required.
class ServicePhoto extends StatefulWidget {
  const ServicePhoto({
    super.key,
    required this.api,
    required this.serviceId,
    required this.photo,
    required this.canEdit,
    required this.onChanged,
  });

  final ApiClient api;
  final String serviceId;
  final Map<String, dynamic>? photo;
  final bool canEdit;
  final VoidCallback onChanged;

  @override
  State<ServicePhoto> createState() => _ServicePhotoState();
}

class _ServicePhotoState extends State<ServicePhoto> {
  Uint8List? _bytes;
  bool _busy = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _loadBytes();
  }

  @override
  void didUpdateWidget(covariant ServicePhoto oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.photo?['id'] != widget.photo?['id']) {
      _bytes = null;
      _loadBytes();
    }
  }

  Future<void> _loadBytes() async {
    final id = widget.photo?['id'] as String?;
    final mime = widget.photo?['mime'] as String? ?? '';
    if (id == null || !mime.startsWith('image/')) return;

    final bytes = await widget.api.getBytes('/attachments/$id');
    if (!mounted || bytes == null) return;
    setState(() => _bytes = Uint8List.fromList(bytes));
  }

  Future<void> _add() async {
    final picked = await Files.pickImage();
    if (picked == null || !mounted) return;

    final squareError = await squarePhotoError(picked.bytes);
    if (squareError != null) {
      setState(() => _error = squareError);
      return;
    }

    setState(() {
      _busy = true;
      _error = null;
    });

    // Upload first; the server drops the old photo only once this one is
    // stored, so a failed upload keeps the photo the service already had.
    final existing = widget.photo?['id'] as String?;

    final response = await widget.api.postFile(
      '/attachments',
      'file',
      picked.name,
      picked.bytes,
      fields: {
        'attachable_type': 'services',
        'attachable_id': widget.serviceId,
        if (existing != null) 'replace': '1',
      },
    );

    if (!mounted) return;

    setState(() => _busy = false);

    if (!response.ok) {
      setState(() => _error = response.reason);
      return;
    }

    widget.onChanged();
  }

  Future<void> _remove() async {
    final id = widget.photo?['id'] as String?;
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

    widget.onChanged();
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text('Photo', style: Theme.of(context).textTheme.titleSmall),
        const SizedBox(height: 8),
        Align(
          alignment: Alignment.centerLeft,
          child: SizedBox(
            width: 160,
            height: 160,
            child: DecoratedBox(
              decoration: BoxDecoration(
                border: Border.all(color: Theme.of(context).dividerColor),
                borderRadius: BorderRadius.circular(8),
              ),
              child: ClipRRect(
                borderRadius: BorderRadius.circular(8),
                child: _bytes == null
                    ? const Center(child: Icon(Icons.photo_outlined, size: 40))
                    : Image.memory(_bytes!, fit: BoxFit.cover),
              ),
            ),
          ),
        ),
        const SizedBox(height: 8),
        Text('Square (1:1).', style: Theme.of(context).textTheme.bodySmall),
        if (_error != null) ...[const SizedBox(height: 8), ErrorNote(_error!)],
        if (widget.canEdit) ...[
          const SizedBox(height: 8),
          Wrap(
            spacing: 8,
            children: [
              FilledButton.tonalIcon(
                onPressed: _busy ? null : _add,
                icon: const Icon(Icons.add_a_photo, size: 18),
                label: Text(
                  widget.photo == null ? 'Add photo' : 'Replace photo',
                ),
              ),
              if (widget.photo != null)
                OutlinedButton(
                  onPressed: _busy ? null : _remove,
                  child: const Text('Remove photo'),
                ),
            ],
          ),
        ],
      ],
    );
  }
}

Future<String?> squarePhotoError(List<int> bytes) async {
  try {
    final codec = await ui.instantiateImageCodec(Uint8List.fromList(bytes));
    final frame = await codec.getNextFrame();
    if (frame.image.width != frame.image.height) {
      return 'The photo has to be square (1:1).';
    }
    return null;
  } catch (_) {
    return 'The photo has to be square (1:1).';
  }
}
