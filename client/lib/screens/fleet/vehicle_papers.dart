import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/files.dart';
import '../../widgets/error_note.dart';

/// Papers hang off the child row they belong to, not the vehicle (spec §7).
class VehiclePapers extends StatefulWidget {
  const VehiclePapers({
    super.key,
    required this.api,
    required this.attachableType,
    required this.attachableId,
    required this.canEdit,
  });

  final ApiClient api;
  final String attachableType;
  final String attachableId;
  final bool canEdit;

  @override
  State<VehiclePapers> createState() => _VehiclePapersState();
}

class _VehiclePapersState extends State<VehiclePapers> {
  List<Map<String, dynamic>> _files = const [];
  String? _accept;
  bool _loading = true;
  bool _busy = false;

  /// Only the newest load may land: a slow answer for the previous record
  /// must not replace this one's files, or Remove would aim at its file
  /// (audit 2026-11-27).
  int _loadSeq = 0;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void didUpdateWidget(covariant VehiclePapers oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.attachableId != widget.attachableId ||
        oldWidget.attachableType != widget.attachableType) {
      _load();
    }
  }

  Future<void> _load() async {
    final seq = ++_loadSeq;
    setState(() {
      _loading = _files.isEmpty;
      _error = null;
    });

    final response = await widget.api.get('/attachments', {
      'attachable_type': widget.attachableType,
      'attachable_id': widget.attachableId,
    });

    if (!mounted || seq != _loadSeq) return;

    if (!response.ok) {
      setState(() {
        _loading = false;
        _error = response.message;
      });
      return;
    }

    final limits = response.body['limits'];
    final extensions = limits is Map ? limits['extensions'] : null;

    setState(() {
      _loading = false;
      _files = [
        for (final row in (response.data as List? ?? const []))
          if (row is Map) Map<String, dynamic>.from(row),
      ];
      if (extensions is List && extensions.isNotEmpty) {
        _accept = [
          for (final ext in extensions) '.$ext',
        ].join(',');
      }
    });
  }

  Future<void> _add() async {
    final picked = await Files.pickBytes(accept: _accept ?? '');
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
        'attachable_type': widget.attachableType,
        'attachable_id': widget.attachableId,
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

  Future<void> _open(Map<String, dynamic> file) async {
    final id = file['id'] as String?;
    if (id == null) return;

    final bytes = await widget.api.getBytes('/attachments/$id');
    if (bytes == null || !mounted) {
      if (mounted) {
        setState(() => _error = 'Could not download that file.');
      }
      return;
    }

    Files.download(file['original_name'] as String? ?? 'file', bytes);
  }

  Future<void> _remove(Map<String, dynamic> file) async {
    final id = file['id'] as String?;
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

    await _load();
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) {
      return const Padding(
        padding: EdgeInsets.symmetric(vertical: 8),
        child: LinearProgressIndicator(),
      );
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (_files.isEmpty)
          Text(
            'No papers.',
            style: Theme.of(context).textTheme.bodyMedium,
          ),
        for (final file in _files)
          ListTile(
            contentPadding: EdgeInsets.zero,
            dense: true,
            leading: const Icon(Icons.attach_file, size: 18),
            title: Text(file['original_name'] as String? ?? 'File'),
            onTap: () => _open(file),
            trailing: widget.canEdit
                ? IconButton(
                    icon: const Icon(Icons.delete_outline),
                    tooltip: 'Remove this paper',
                    onPressed: _busy ? null : () => _remove(file),
                  )
                : null,
          ),
        if (widget.canEdit)
          Align(
            alignment: Alignment.centerLeft,
            child: TextButton.icon(
              onPressed: _busy ? null : _add,
              icon: const Icon(Icons.upload_file, size: 18),
              label: const Text('Add paper'),
            ),
          ),
        if (_error != null) ...[
          const SizedBox(height: 8),
          ErrorNote(_error!),
        ],
      ],
    );
  }
}
