import 'package:flutter/material.dart';

import '../../core/staff_session.dart';
import '../../core/tab_tours.dart';
import '../../widgets/async_body.dart';
import '../../widgets/error_note.dart';
import '../../widgets/side_panel.dart';

/// Staff › Help videos (UI queue 81): which tab's ▶ plays which video.
///
/// The same video for every organization, so it is set here and never in a
/// tenant's settings. A save changes it for everybody at once, with no
/// release; a tab with none draws no ▶.
class HelpVideosScreen extends StatefulWidget {
  const HelpVideosScreen({super.key, required this.staff});

  final StaffSession staff;

  @override
  State<HelpVideosScreen> createState() => _HelpVideosScreenState();
}

/// `m:ss`, the way a video player writes a time.
String _clock(int seconds) =>
    '${seconds ~/ 60}:${(seconds % 60).toString().padLeft(2, '0')}';

/// `2:40`, `0:42` or `160` as seconds; null when it is neither.
int? _seconds(String text) {
  final t = text.trim();
  final m = RegExp(r'^(\d+):([0-5]\d)$').firstMatch(t);
  if (m != null) return int.parse(m[1]!) * 60 + int.parse(m[2]!);
  return int.tryParse(t);
}

class _HelpVideosScreenState extends State<HelpVideosScreen> {
  List<Map<String, dynamic>> _rows = const [];
  bool _loading = true;
  String? _error;
  String? _saveError;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = _rows.isEmpty;
      _error = null;
    });
    final res = await widget.staff.api.get('/staff/help-videos');
    if (!mounted) return;
    setState(() {
      _loading = false;
      if (res.ok) {
        _rows = (res.body['data'] as List).cast<Map<String, dynamic>>();
      } else {
        _error = res.reason;
      }
    });
  }

  Future<void> _edit(Map<String, dynamic> row) async {
    final body = await showSidePanel<Map<String, dynamic>>(
      context,
      builder: (_) => _EditPanel(row: row),
    );
    if (body == null || !mounted) return;

    final module = row['module_key'] as String;
    final res = body.isEmpty
        ? await widget.staff.api.delete('/staff/help-videos/$module')
        : await widget.staff.api.put('/staff/help-videos/$module', body);
    if (!mounted) return;
    setState(() => _saveError = res.ok ? null : res.reason);
    await _load();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        PanelHeader(
          title: 'Help videos',
          note:
              'The how-to video behind each tab\'s ▶, the same for every '
              'organization. A tab with no video shows no ▶. A link must be '
              'https, to a file the app can play.',
          action: IconButton(
            tooltip: 'Refresh',
            onPressed: _load,
            icon: const Icon(Icons.refresh),
          ),
        ),
        if (_saveError != null)
          Padding(
            padding: const EdgeInsets.fromLTRB(24, 0, 24, 12),
            child: ErrorNote(_saveError!),
          ),
        Expanded(
          child: AsyncBody(
            loading: _loading,
            error: _error,
            onRetry: _load,
            child: ListView.separated(
              padding: const EdgeInsets.fromLTRB(24, 0, 24, 24),
              itemCount: _rows.length,
              separatorBuilder: (_, _) => const Divider(height: 1),
              itemBuilder: (context, i) {
                final row = _rows[i];
                final video = row['video'] as Map?;
                final tour = tabTours[row['module_key']];
                final seconds = (video?['duration_seconds'] as num?)?.toInt();
                return ListTile(
                  key: ValueKey('help-video-row-${row['module_key']}'),
                  contentPadding: EdgeInsets.zero,
                  title: Text('${row['label']}'),
                  subtitle: Text(
                    [
                      video == null
                          ? 'No video yet: ▶ hidden'
                          : '${video['url']}'
                                '${seconds == null ? '' : ' · ${_clock(seconds)}'}',
                      if (tour != null)
                        'Walkthrough v${tour.version} · '
                            '${tour.steps.length} steps',
                    ].join('\n'),
                    style: theme.textTheme.bodySmall,
                  ),
                  trailing: OutlinedButton(
                    onPressed: () => _edit(row),
                    child: Text(video == null ? 'Add a video' : 'Change'),
                  ),
                );
              },
            ),
          ),
        ),
      ],
    );
  }
}

/// Returns the PUT body, `{}` to remove the video, or null to leave it.
class _EditPanel extends StatefulWidget {
  const _EditPanel({required this.row});

  final Map<String, dynamic> row;

  @override
  State<_EditPanel> createState() => _EditPanelState();
}

class _EditPanelState extends State<_EditPanel> {
  late final Map? _video = widget.row['video'] as Map?;
  late final _url = TextEditingController(text: _video?['url'] as String?);
  late final _title = TextEditingController(text: _video?['title'] as String?);
  late final _length = TextEditingController(
    text: _video?['duration_seconds'] == null
        ? ''
        : _clock((_video!['duration_seconds'] as num).toInt()),
  );
  late final _captions = TextEditingController(
    text: _video?['captions_url'] as String?,
  );
  late final _chapters = TextEditingController(
    text: [
      for (final c in (_video?['chapters'] as List? ?? const []).cast<Map>())
        '${_clock((c['at'] as num).toInt())} ${c['label']}',
    ].join('\n'),
  );
  String? _problem;

  @override
  void dispose() {
    for (final c in [_url, _title, _length, _captions, _chapters]) {
      c.dispose();
    }
    super.dispose();
  }

  void _save() {
    final chapters = <Map<String, dynamic>>[];
    for (final line in _chapters.text.split('\n')) {
      if (line.trim().isEmpty) continue;
      final m = RegExp(r'^\s*(\S+)\s+(.+)$').firstMatch(line);
      final at = m == null ? null : _seconds(m[1]!);
      if (at == null) {
        setState(
          () => _problem =
              'Each chapter is a time, then its name: "0:42 Booking a job".',
        );
        return;
      }
      chapters.add({'at': at, 'label': m![2]!.trim()});
    }
    final length = _length.text.trim().isEmpty ? null : _seconds(_length.text);
    if (_length.text.trim().isNotEmpty && length == null) {
      setState(() => _problem = 'Write the length as 2:40.');
      return;
    }

    String? blank(TextEditingController c) =>
        c.text.trim().isEmpty ? null : c.text.trim();

    Navigator.of(context).pop(<String, dynamic>{
      'url': _url.text.trim(),
      'title': blank(_title),
      'duration_seconds': length,
      'chapters': chapters,
      'captions_url': blank(_captions),
    });
  }

  @override
  Widget build(BuildContext context) {
    InputDecoration field(String label, [String? hint]) => InputDecoration(
      labelText: label,
      hintText: hint,
      border: const OutlineInputBorder(),
    );

    return SidePanel(
      title: '${widget.row['label']} video',
      actions: [
        if (_video != null)
          TextButton(
            key: const Key('help-video-remove'),
            onPressed: () =>
                Navigator.of(context).pop(const <String, dynamic>{}),
            child: const Text('Remove the video'),
          ),
        FilledButton(
          key: const Key('help-video-save'),
          onPressed: _save,
          child: const Text('Save'),
        ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          TextField(
            key: const Key('help-video-url'),
            controller: _url,
            decoration: field('Video link', 'https://…/scheduling.mp4'),
          ),
          const SizedBox(height: 12),
          TextField(
            controller: _title,
            decoration: field('Title', 'How ${widget.row['label']} works'),
          ),
          const SizedBox(height: 12),
          TextField(
            key: const Key('help-video-length'),
            controller: _length,
            decoration: field('Length', '2:40'),
          ),
          const SizedBox(height: 12),
          TextField(
            key: const Key('help-video-chapters'),
            controller: _chapters,
            minLines: 3,
            maxLines: 10,
            decoration: field('Chapters, one a line', '0:42 Booking a job'),
          ),
          const SizedBox(height: 12),
          TextField(
            controller: _captions,
            decoration: field(
              'Captions link (.vtt)',
              'https://…/scheduling.vtt',
            ),
          ),
          if (_problem != null) ...[
            const SizedBox(height: 12),
            ErrorNote(_problem!),
          ],
        ],
      ),
    );
  }
}
