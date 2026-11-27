import 'dart:typed_data';

import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/files.dart';
import '../../widgets/error_note.dart';

/// The `photo` field on an inspection screen.
///
/// The picture goes up as an ordinary attachment on the RUN the moment it is
/// taken, and the answer holds its id. That order is deliberate: a technician
/// on a roof with one bar of signal should not be carrying four photographs in
/// memory until they reach the end of the inspection and press Submit. Each one
/// is safely on the server as soon as it is taken, and the answer is a short
/// list of ids that costs nothing to re-save on every keystroke elsewhere.
///
/// At submit the server re-points those attachments from the run to the report.
/// Nothing here has to know that, which is the point — this widget only ever
/// talks about the run.
class InspectionPhotoField extends StatefulWidget {
  const InspectionPhotoField({
    super.key,
    required this.api,
    required this.runId,
    required this.label,
    required this.maxCount,
    required this.ids,
    required this.onChanged,
  });

  final ApiClient api;
  final String runId;
  final String label;
  final int maxCount;
  final List<String> ids;
  final ValueChanged<List<String>> onChanged;

  @override
  State<InspectionPhotoField> createState() => _InspectionPhotoFieldState();
}

class _InspectionPhotoFieldState extends State<InspectionPhotoField> {
  final Map<String, Uint8List> _thumbs = {};
  bool _busy = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _loadThumbs();
  }

  @override
  void didUpdateWidget(InspectionPhotoField old) {
    super.didUpdateWidget(old);
    if (old.ids != widget.ids) _loadThumbs();
  }

  Future<void> _loadThumbs() async {
    for (final id in widget.ids) {
      if (_thumbs.containsKey(id)) continue;
      final bytes = await widget.api.getBytes('/attachments/$id');
      if (!mounted || bytes == null) continue;
      setState(() => _thumbs[id] = Uint8List.fromList(bytes));
    }
  }

  Future<void> _add({bool camera = false}) async {
    final picked = camera ? await Files.takePhoto() : await Files.pickImage();
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
        'attachable_type': 'inspection_runs',
        'attachable_id': widget.runId,
      },
    );

    if (!mounted) return;
    setState(() => _busy = false);

    if (!response.ok) {
      setState(() => _error = response.reason);
      return;
    }

    final id = (response.data as Map?)?['id'] as String?;
    if (id == null) return;

    setState(() => _thumbs[id] = Uint8List.fromList(picked.bytes));
    widget.onChanged([...widget.ids, id]);
  }

  Future<void> _remove(String id) async {
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

    setState(() => _thumbs.remove(id));
    widget.onChanged([...widget.ids]..remove(id));
  }

  @override
  Widget build(BuildContext context) {
    final full = widget.ids.length >= widget.maxCount;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(widget.label, style: Theme.of(context).textTheme.labelLarge),
        const SizedBox(height: 8),
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            for (final id in widget.ids)
              _Thumb(
                bytes: _thumbs[id],
                onRemove: _busy ? null : () => _remove(id),
              ),
            if (!full)
              OutlinedButton.icon(
                key: Key('add-photo-${widget.label}'),
                onPressed: _busy ? null : _add,
                icon: const Icon(Icons.add_a_photo_outlined),
                label: Text(widget.ids.isEmpty ? 'Add photo' : 'Add another'),
              ),
            // The camera straight away on a phone (audit 11-27).
            if (!full && Files.canTakePhoto)
              OutlinedButton.icon(
                key: Key('take-photo-${widget.label}'),
                onPressed: _busy ? null : () => _add(camera: true),
                icon: const Icon(Icons.photo_camera_outlined),
                label: const Text('Take photo'),
              ),
          ],
        ),
        if (full)
          Padding(
            padding: const EdgeInsets.only(top: 6),
            child: Text(
              widget.maxCount == 1
                  ? 'One photo. Remove it to take a different one.'
                  : 'That is all ${widget.maxCount} for this field.',
              style: Theme.of(context).textTheme.bodySmall,
            ),
          ),
        if (_error != null) ...[const SizedBox(height: 8), ErrorNote(_error!)],
      ],
    );
  }
}

class _Thumb extends StatelessWidget {
  const _Thumb({required this.bytes, required this.onRemove});

  final Uint8List? bytes;
  final VoidCallback? onRemove;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: 88,
      height: 88,
      child: Stack(
        fit: StackFit.expand,
        children: [
          ClipRRect(
            borderRadius: BorderRadius.circular(8),
            child: bytes == null
                ? ColoredBox(
                    color: Theme.of(
                      context,
                    ).colorScheme.surfaceContainerHighest,
                    child: const Center(
                      child: SizedBox(
                        width: 18,
                        height: 18,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      ),
                    ),
                  )
                : Image.memory(bytes!, fit: BoxFit.cover, cacheWidth: 256),
          ),
          Align(
            alignment: Alignment.topRight,
            child: IconButton(
              tooltip: 'Remove',
              onPressed: onRemove,
              icon: const Icon(Icons.cancel, size: 20),
            ),
          ),
        ],
      ),
    );
  }
}
