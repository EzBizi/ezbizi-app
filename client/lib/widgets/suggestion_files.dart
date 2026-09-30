import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/files.dart';

/// What a suggestion is about and what came with it: the sidebar tab it names
/// and its attached screenshots or notes, each one a chip that downloads it.
///
/// Shared by the author's tab and the staff queue. [base] is the row's own
/// path (`/suggestions/{id}` or `/staff/suggestions/{id}`); each file is
/// `$base/files/{index}` below it.
class SuggestionAbout extends StatefulWidget {
  const SuggestionAbout({
    super.key,
    required this.api,
    required this.base,
    required this.row,
  });

  final ApiClient api;
  final String base;
  final Map<String, dynamic> row;

  @override
  State<SuggestionAbout> createState() => _SuggestionAboutState();
}

class _SuggestionAboutState extends State<SuggestionAbout> {
  String? _error;

  Future<void> _open(Map<String, dynamic> file) async {
    setState(() => _error = null);
    final response = await widget.api.getBytesResponse(
      '${widget.base}/files/${file['index']}',
    );
    if (!mounted) return;
    final bytes = response.bytes;
    if (bytes == null) {
      setState(() => _error = response.message);
      return;
    }
    Files.download(file['name'] as String? ?? 'file', bytes);
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final label = widget.row['module_label'] as String?;
    final files = List<Map<String, dynamic>>.from(
      widget.row['files'] as List? ?? const [],
    );

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          'About: ${label ?? 'Something new'}',
          style: theme.textTheme.labelMedium,
        ),
        if (files.isNotEmpty) ...[
          const SizedBox(height: 8),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              for (final file in files)
                ActionChip(
                  avatar: Icon(fileIcon(file['mime'] as String?), size: 18),
                  label: Text(file['name'] as String? ?? 'file'),
                  tooltip: 'Download',
                  onPressed: () => _open(file),
                ),
            ],
          ),
        ],
        if (_error != null) ...[
          const SizedBox(height: 6),
          Text(
            _error!,
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.error,
            ),
          ),
        ],
      ],
    );
  }
}

/// A picture, a PDF, or a written note.
IconData fileIcon(String? mimeOrName) {
  final s = (mimeOrName ?? '').toLowerCase();
  if (s.startsWith('image/') ||
      RegExp(r'\.(png|jpe?g|gif|webp|heic|heif)$').hasMatch(s)) {
    return Icons.image_outlined;
  }
  if (s.contains('pdf')) return Icons.picture_as_pdf_outlined;
  return Icons.description_outlined;
}
