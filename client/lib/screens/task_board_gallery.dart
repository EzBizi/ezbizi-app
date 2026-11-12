import 'dart:typed_data';

import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/files.dart';
import '../core/timestamps.dart';
import '../widgets/empty_state.dart';
import '../widgets/side_panel.dart';
import 'task_board_files.dart';

typedef _Json = Map<String, dynamic>;

/// Every photo, video and file on a board (Jose, 2026-11-12: "a Gallery
/// View, that shows every photo-video-file on the board"). Pages of 60 from
/// GET task-boards/{id}/attachments, the next one loaded on scroll; the board's
/// own `view` decides who sees it.
class TaskBoardGallery extends StatefulWidget {
  const TaskBoardGallery({
    super.key,
    required this.api,
    required this.boardId,
    required this.formats,
    required this.onOpenTask,
  });

  final ApiClient api;
  final String boardId;
  final DisplayFormats formats;

  /// Opens the task by id; false when the board does not hold it (a subtask
  /// the table does not list), and "Go to task" then says so.
  final bool Function(String taskId) onOpenTask;

  @override
  State<TaskBoardGallery> createState() => _TaskBoardGalleryState();
}

class _TaskBoardGalleryState extends State<TaskBoardGallery> {
  final _items = <_Json>[];
  List<_Json> _people = const [];
  String? _cursor;
  bool _loading = false;
  bool _done = false;
  String? _error;

  String? _type; // photo | video | file
  String? _person;
  int? _days; // 7 | 30 | 365

  @override
  void initState() {
    super.initState();
    _reload();
  }

  void _reload() {
    _items.clear();
    _cursor = null;
    _done = false;
    _more();
  }

  Future<void> _more() async {
    if (_loading || _done) return;
    setState(() {
      _loading = true;
      _error = null;
    });
    final from = _days == null
        ? null
        : formatCalendarDay(DateTime.now().subtract(Duration(days: _days!)));
    final res = await widget.api.get(
      '/task-boards/${widget.boardId}/attachments',
      {
        'type': ?_type,
        'person': ?_person,
        'from': ?from,
        'cursor': ?_cursor,
      },
    );
    if (!mounted) return;
    setState(() {
      _loading = false;
      if (!res.ok) {
        _error = res.body['message'] as String? ?? 'The files could not load.';
        return;
      }
      _items.addAll((res.body['data'] as List? ?? const []).cast<_Json>());
      if (res.body['people'] is List) {
        _people = (res.body['people'] as List).cast<_Json>();
      }
      _cursor = res.body['next_cursor'] as String?;
      _done = _cursor == null;
    });
  }

  void _set(VoidCallback change) {
    setState(change);
    _reload();
  }

  Widget _chip(String key, String label, bool on, VoidCallback tap) => Padding(
    padding: const EdgeInsets.only(right: 6),
    child: ChoiceChip(
      key: Key(key),
      label: Text(label),
      selected: on,
      showCheckmark: false,
      onSelected: (_) => tap(),
    ),
  );

  Widget _filters() {
    return SingleChildScrollView(
      scrollDirection: Axis.horizontal,
      padding: const EdgeInsets.fromLTRB(16, 8, 16, 4),
      child: Row(
        children: [
          for (final (v, label) in const [
            (null, 'All'),
            ('photo', 'Photos'),
            ('video', 'Videos'),
            ('file', 'Files'),
          ])
            _chip(
              'gallery-type-${v ?? 'all'}',
              label,
              _type == v,
              () => _set(() => _type = v),
            ),
          const SizedBox(width: 10),
          for (final (d, label) in const [
            (null, 'Any time'),
            (7, '7 days'),
            (30, '30 days'),
            (365, 'This year'),
          ])
            _chip(
              'gallery-days-${d ?? 'any'}',
              label,
              _days == d,
              () => _set(() => _days = d),
            ),
          if (_people.length > 1) ...[
            const SizedBox(width: 10),
            _chip(
              'gallery-person-all',
              'Everyone',
              _person == null,
              () => _set(() => _person = null),
            ),
            for (final p in _people)
              _chip(
                'gallery-person-${p['id']}',
                '${p['name']}',
                _person == p['id'],
                () => _set(() => _person = p['id'] as String?),
              ),
          ],
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final Widget body;
    if (_items.isEmpty && _loading) {
      body = const Center(child: CircularProgressIndicator());
    } else if (_items.isEmpty && _error != null) {
      body = Center(
        child: Text(
          _error!,
          style: TextStyle(color: theme.colorScheme.error),
        ),
      );
    } else if (_items.isEmpty) {
      body = const EmptyState(
        key: Key('gallery-empty'),
        icon: Icons.photo_library_outlined,
        title: 'No files here yet',
        message:
            'Photos, videos and files added to this board\'s updates show '
            'up here.',
      );
    } else {
      body = NotificationListener<ScrollNotification>(
        onNotification: (n) {
          if (n.metrics.extentAfter < 600) _more();
          return false;
        },
        child: GridView.builder(
          key: const Key('gallery-grid'),
          padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
          gridDelegate: const SliverGridDelegateWithMaxCrossAxisExtent(
            maxCrossAxisExtent: 200,
            mainAxisSpacing: 10,
            crossAxisSpacing: 10,
            childAspectRatio: 0.82,
          ),
          itemCount: _items.length + (_done ? 0 : 1),
          itemBuilder: (context, i) {
            if (i >= _items.length) {
              return const Center(child: CircularProgressIndicator());
            }
            return _Tile(
              api: widget.api,
              item: _items[i],
              formats: widget.formats,
              onTap: () => _open(_items[i]),
            );
          },
        ),
      );
    }
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _filters(),
        Expanded(child: body),
      ],
    );
  }

  Future<void> _open(_Json a) async {
    final task = a['task'] as _Json?;
    void goToTask() {
      Navigator.of(context).maybePop();
      if (task == null || !widget.onOpenTask('${task['id']}')) {
        ScaffoldMessenger.maybeOf(context)?.showSnackBar(
          const SnackBar(content: Text('That task is not on this board now.')),
        );
      }
    }

    await showSidePanel<void>(
      context,
      builder: (_) => a['inline_url'] is String
          ? TaskAttachmentViewer(
              api: widget.api,
              attachment: a,
              onGoToTask: goToTask,
            )
          : _FilePanel(api: widget.api, item: a, onGoToTask: goToTask),
    );
  }
}

class _Tile extends StatelessWidget {
  const _Tile({
    required this.api,
    required this.item,
    required this.formats,
    required this.onTap,
  });

  final ApiClient api;
  final _Json item;
  final DisplayFormats formats;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final kind = item['kind'];
    final task = item['task'] as _Json?;
    final Widget face;
    if (item['thumbnail_url'] is String) {
      face = _Thumb(api: api, url: '${item['thumbnail_url']}', id: '${item['id']}');
    } else {
      face = Container(
        color: scheme.surfaceContainerHighest,
        alignment: Alignment.center,
        padding: const EdgeInsets.all(8),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(
              kind == 'video'
                  ? Icons.play_circle_outline
                  : fileIcon(item['mime'] as String?),
              size: 40,
              color: scheme.onSurfaceVariant,
            ),
            const SizedBox(height: 6),
            Text(
              '${item['name']}',
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
              textAlign: TextAlign.center,
              style: theme.textTheme.bodySmall,
            ),
          ],
        ),
      );
    }
    return InkWell(
      key: ValueKey('gallery-tile-${item['id']}'),
      borderRadius: BorderRadius.circular(10),
      onTap: onTap,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Expanded(
            child: Container(
              clipBehavior: Clip.antiAlias,
              decoration: BoxDecoration(
                borderRadius: BorderRadius.circular(10),
                border: Border.all(color: scheme.outlineVariant),
              ),
              child: face,
            ),
          ),
          const SizedBox(height: 4),
          Text(
            '${task?['name'] ?? 'A removed task'}',
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: theme.textTheme.labelMedium,
          ),
          Text(
            formatListStamp(item['created_at'], '', formats: formats),
            style: theme.textTheme.bodySmall?.copyWith(
              color: scheme.onSurfaceVariant,
            ),
          ),
        ],
      ),
    );
  }
}

/// A thumbnail, fetched with the session's token when the tile is built
/// (the grid builds only what is on screen, so this is the lazy load).
class _Thumb extends StatefulWidget {
  const _Thumb({required this.api, required this.url, required this.id});

  final ApiClient api;
  final String url;
  final String id;

  @override
  State<_Thumb> createState() => _ThumbState();
}

class _ThumbState extends State<_Thumb> {
  Uint8List? _bytes;
  bool _failed = false;

  @override
  void initState() {
    super.initState();
    fetchTaskAttachment(widget.api, widget.url).then((res) {
      if (!mounted) return;
      setState(() {
        if (res.bytes == null) {
          _failed = true;
        } else {
          _bytes = Uint8List.fromList(res.bytes!);
        }
      });
    });
  }

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    if (_bytes != null) {
      return Image.memory(
        _bytes!,
        key: ValueKey('gallery-thumb-${widget.id}'),
        fit: BoxFit.cover,
        cacheWidth: 400,
        errorBuilder: (_, _, _) =>
            Icon(Icons.broken_image_outlined, color: scheme.outline),
      );
    }
    return Container(
      color: scheme.surfaceContainerHighest,
      child: Icon(
        _failed ? Icons.broken_image_outlined : Icons.image_outlined,
        color: scheme.outline,
      ),
    );
  }
}

/// A video or another file: what it is, Download, Go to task.
class _FilePanel extends StatefulWidget {
  const _FilePanel({
    required this.api,
    required this.item,
    required this.onGoToTask,
  });

  final ApiClient api;
  final _Json item;
  final VoidCallback onGoToTask;

  @override
  State<_FilePanel> createState() => _FilePanelState();
}

class _FilePanelState extends State<_FilePanel> {
  bool _busy = false;

  Future<void> _download() async {
    setState(() => _busy = true);
    final res = await fetchTaskAttachment(widget.api, '${widget.item['url']}');
    if (!mounted) return;
    setState(() => _busy = false);
    if (res.bytes != null) Files.download('${widget.item['name']}', res.bytes!);
  }

  @override
  Widget build(BuildContext context) {
    final a = widget.item;
    final scheme = Theme.of(context).colorScheme;
    return SidePanel(
      key: const Key('gallery-file-panel'),
      title: '${a['name']}',
      subtitle: a['size'] is num ? fileSizeLabel(a['size'] as num) : null,
      actions: [
        OutlinedButton.icon(
          key: const Key('gallery-go-to-task'),
          onPressed: widget.onGoToTask,
          icon: const Icon(Icons.open_in_new, size: 18),
          label: const Text('Go to task'),
        ),
        FilledButton.icon(
          key: const Key('gallery-download'),
          onPressed: _busy ? null : _download,
          icon: const Icon(Icons.download_outlined, size: 18),
          label: const Text('Download'),
        ),
      ],
      child: Center(
        child: Icon(
          a['kind'] == 'video'
              ? Icons.movie_outlined
              : fileIcon(a['mime'] as String?),
          size: 72,
          color: scheme.onSurfaceVariant,
        ),
      ),
    );
  }
}
