import '../../widgets/list_search.dart';

import 'package:desktop_drop/desktop_drop.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:pasteboard/pasteboard.dart';

import '../../core/pasted_image.dart';

import '../../core/api_client.dart';
import '../../core/files.dart';
import '../../widgets/side_panel.dart';

/// Social › Compose › From library (UI queue 105, design 1): the library in a
/// side panel, to pick from and to look after. Returns the item picked, or
/// null. A post keeps its own copy, so removing an item never breaks one.
///
/// [uploadPath] (the newsletter composer, P0 2026-11-17) adds the upload
/// area: an Upload images button, a drop target and Paste. Each file shows
/// its own progress, Cancel, and on failure the server's reason and Retry.
/// A finished upload is selected at once, so Use it is ready; the item it
/// returns carries the `url` the server answered.
Future<Map<String, dynamic>?> showMediaLibraryPanel(
  BuildContext context, {
  required ApiClient api,
  required Future<PickedBytes?> Function() pickMedia,
  required bool canEdit,
  String? uploadPath,
  String initialKind = 'all',
  bool uploadFirst = false,
}) => showSidePanel<Map<String, dynamic>>(
  context,
  builder: (panel) => _LibraryPanel(
    api: api,
    pickMedia: pickMedia,
    canEdit: canEdit,
    uploadPath: uploadPath,
    initialKind: initialKind,
    uploadFirst: uploadFirst,
  ),
);

/// The picture picker the upload area opens. A variable so a test can hand
/// back a file.
@visibleForTesting
Future<PickedBytes?> Function() libraryPickPicture = Files.pickImage;

/// The picture on the clipboard, or null. A variable for tests.
@visibleForTesting
Future<List<int>?> Function() libraryClipboardPicture = () => Pasteboard.image;

/// The largest picture the newsletter upload takes; the server says the same.
const newsletterPictureLimitBytes = 2 * 1024 * 1024;

/// One file on its way up: uploading, or failed with the server's reason.
class _Upload {
  _Upload(this.file);

  final PickedBytes file;
  String? error;
  int attempt = 0;
  bool get running => error == null;
}

class _LibraryPanel extends StatefulWidget {
  const _LibraryPanel({
    required this.api,
    required this.pickMedia,
    required this.canEdit,
    this.uploadPath,
    this.initialKind = 'all',
    this.uploadFirst = false,
  });

  final ApiClient api;
  final Future<PickedBytes?> Function() pickMedia;
  final bool canEdit;
  final String? uploadPath;
  final String initialKind;
  final bool uploadFirst;

  @override
  State<_LibraryPanel> createState() => _LibraryPanelState();
}

class _LibraryPanelState extends State<_LibraryPanel> {
  List<Map<String, dynamic>>? _items;
  late String _kind = widget.initialKind;
  final List<_Upload> _uploads = [];
  bool _dropping = false;
  String _find = '';
  String? _picked;
  String? _renaming;
  String? _removing;
  String? _error;
  bool _busy = false;
  final _name = TextEditingController();
  final Map<String, Future<Uint8List?>> _thumbs = {};

  @override
  void initState() {
    super.initState();
    _load();
    if (widget.uploadFirst && widget.uploadPath != null) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted) _choosePictures();
      });
    }
  }

  Future<void> _choosePictures() async {
    final file = await libraryPickPicture();
    if (file != null && mounted) _start(file);
  }

  Future<void> _paste() async {
    final raw = await libraryClipboardPicture();
    if (!mounted) return;
    final bytes = raw == null ? null : pastedImageAsPng(raw);
    if (bytes == null) {
      setState(() => _error = 'There is no picture on the clipboard to paste.');
      return;
    }
    _start(PickedBytes('Pasted picture.png', bytes));
  }

  Future<void> _drop(List<DropItem> items) async {
    setState(() => _dropping = false);
    for (final item in items) {
      if (item is DropItemDirectory) continue;
      final bytes = await item.readAsBytes();
      if (!mounted) return;
      _start(PickedBytes(item.name, bytes));
    }
  }

  void _start(PickedBytes file) {
    final upload = _Upload(file);
    setState(() {
      _error = null;
      _uploads.add(upload);
    });
    _send(upload);
  }

  /// Sends one file. The editor and this panel stay usable meanwhile; a
  /// cancelled upload's answer is ignored (the server keeps at most one copy
  /// of the same picture, so a late arrival duplicates nothing).
  Future<void> _send(_Upload upload) async {
    final attempt = ++upload.attempt;
    setState(() => upload.error = null);
    if (upload.file.bytes.length > newsletterPictureLimitBytes) {
      setState(
        () => upload.error =
            'That picture is larger than 2 MB, the most an email picture may be. '
            'Make it smaller and try again.',
      );
      return;
    }
    final response = await widget.api.postFile(
      widget.uploadPath!,
      'file',
      upload.file.name,
      upload.file.bytes,
    );
    if (!mounted || !_uploads.contains(upload) || upload.attempt != attempt) {
      return;
    }
    final data = response.ok ? response.data : null;
    if (data is! Map<String, dynamic> || data['id'] == null) {
      setState(
        () => upload.error = response.ok
            ? 'The upload finished without a picture. Try again.'
            : response.reason,
      );
      return;
    }
    final id = '${data['id']}';
    _thumbs[id] = Future.value(Uint8List.fromList(upload.file.bytes));
    setState(() {
      _uploads.remove(upload);
      final items = [...?_items]..removeWhere((i) => '${i['id']}' == id);
      _items = [data, ...items];
      _picked = id;
      if (_kind == 'video') _kind = 'image';
    });
  }

  @override
  void dispose() {
    _name.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    // The newsletter picker lists through its own endpoint (P1a), so a
    // newsletter-only role can pick a picture without Social Media access.
    final response = widget.uploadPath != null
        ? await widget.api.get('/newsletter/images')
        : await widget.api.get('/social/library');
    if (!mounted) return;
    setState(() {
      final loaded = response.ok && response.data is List
          ? (response.data as List).whereType<Map<String, dynamic>>().toList()
          : <Map<String, dynamic>>[];
      // An upload that finished before the list arrived stays on top.
      final kept = [
        for (final i in _items ?? const <Map<String, dynamic>>[])
          if (i['url'] != null && !loaded.any((l) => l['id'] == i['id'])) i,
      ];
      _items = [...kept, ...loaded];
      if (!response.ok) _error = response.message;
    });
  }

  Future<Uint8List?> _thumb(String id) => _thumbs[id] ??=
      (widget.uploadPath != null
              ? widget.api.getBytes('/newsletter/images/$id/file')
              : widget.api.getBytes('/social/library/$id/file'))
          .then((b) => b == null ? null : Uint8List.fromList(b));

  Future<void> _add() async {
    final file = await widget.pickMedia();
    if (file == null || !mounted) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    final response = await widget.api.postFile(
      '/social/library',
      'file',
      file.name,
      file.bytes,
    );
    if (!mounted) return;
    setState(() {
      _busy = false;
      if (!response.ok) _error = response.message;
    });
    await _load();
  }

  Future<void> _rename(String id) async {
    final name = _name.text.trim();
    if (name.isEmpty) return;
    final response = await widget.api.patch('/social/library/$id', {
      'name': name,
    });
    if (!mounted) return;
    setState(() {
      _renaming = null;
      if (!response.ok) _error = response.message;
    });
    await _load();
  }

  Future<void> _remove(String id) async {
    final response = await widget.api.delete('/social/library/$id');
    if (!mounted) return;
    setState(() {
      _removing = null;
      if (_picked == id) _picked = null;
      if (!response.ok) _error = response.message;
    });
    await _load();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final items = _items;
    final shown = [
      for (final i in items ?? const <Map<String, dynamic>>[])
        if ((_kind == 'all' || i['kind'] == _kind) &&
            '${i['name'] ?? ''}'.toLowerCase().contains(_find.toLowerCase()))
          i,
    ];
    final picked = shown.where((i) => i['id'] == _picked).firstOrNull;

    return SidePanel(
      title: 'Library',
      subtitle: 'Pictures and videos kept to use again',
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: const Text('Cancel'),
        ),
        FilledButton(
          key: const ValueKey('library-use'),
          onPressed: picked == null
              ? null
              : () => Navigator.of(context).pop(picked),
          child: const Text('Use it'),
        ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Expanded(
                child: ListSearch(
                  key: const ValueKey('library-find'),
                  hint: 'Search',
                  onChanged: (v) => setState(() => _find = v),
                ),
              ),
              if (widget.canEdit) ...[
                const SizedBox(width: 8),
                OutlinedButton.icon(
                  key: const ValueKey('library-add'),
                  onPressed: _busy ? null : _add,
                  icon: const Icon(Icons.add, size: 18),
                  label: const Text('Add'),
                ),
              ],
            ],
          ),
          const SizedBox(height: 8),
          Wrap(
            spacing: 6,
            children: [
              for (final (k, label) in const [
                ('all', 'All'),
                ('image', 'Pictures'),
                ('video', 'Videos'),
              ])
                ChoiceChip(
                  label: Text(label),
                  selected: _kind == k,
                  onSelected: (_) => setState(() => _kind = k),
                ),
            ],
          ),
          if (widget.uploadPath != null) ...[
            const SizedBox(height: 10),
            _uploadArea(theme),
          ],
          if (_error != null)
            Padding(
              padding: const EdgeInsets.only(top: 8),
              child: Text(
                _error!,
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.error,
                ),
              ),
            ),
          const SizedBox(height: 10),
          if (items == null)
            const LinearProgressIndicator()
          else if (shown.isEmpty)
            Text(
              items.isEmpty
                  ? 'Nothing in the library yet.'
                  : 'Nothing matches that.',
              style: theme.textTheme.bodySmall,
            )
          else
            LayoutBuilder(
              builder: (context, box) {
                final across = box.maxWidth < 320 ? 2 : 3;
                final width = (box.maxWidth - 8 * (across - 1)) / across;
                return Wrap(
                  spacing: 8,
                  runSpacing: 8,
                  children: [
                    for (final i in shown)
                      SizedBox(width: width, child: _tile(theme, i)),
                  ],
                );
              },
            ),
        ],
      ),
    );
  }

  Widget _uploadArea(ThemeData theme) {
    final area = Container(
      key: const ValueKey('library-upload-area'),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: _dropping
            ? theme.colorScheme.primaryContainer
            : theme.colorScheme.surfaceContainerLow,
        border: Border.all(
          color: _dropping
              ? theme.colorScheme.primary
              : theme.colorScheme.outlineVariant,
          width: _dropping ? 2 : 1,
        ),
        borderRadius: BorderRadius.circular(8),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Wrap(
            spacing: 8,
            runSpacing: 8,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              FilledButton.icon(
                key: const ValueKey('library-upload'),
                onPressed: _choosePictures,
                icon: const Icon(Icons.upload, size: 18),
                label: const Text('Upload images'),
              ),
              OutlinedButton.icon(
                key: const ValueKey('library-paste'),
                onPressed: _paste,
                icon: const Icon(Icons.content_paste, size: 18),
                label: const Text('Paste'),
              ),
            ],
          ),
          const SizedBox(height: 6),
          Text(
            _dropping
                ? 'Drop the pictures to upload them'
                : 'Or drop pictures here. PNG, JPEG, GIF or WebP, up to 2 MB each.',
            style: theme.textTheme.bodySmall,
          ),
          for (final u in _uploads) _uploadRow(theme, u),
        ],
      ),
    );

    return DropTarget(
      onDragEntered: (_) => setState(() => _dropping = true),
      onDragExited: (_) => setState(() => _dropping = false),
      onDragDone: (details) => _drop(details.files),
      child: area,
    );
  }

  Widget _uploadRow(ThemeData theme, _Upload u) {
    final index = _uploads.indexOf(u);
    return Padding(
      key: ValueKey('library-upload-$index'),
      padding: const EdgeInsets.only(top: 8),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  u.file.name,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: theme.textTheme.bodySmall,
                ),
              ),
              if (!u.running)
                TextButton(
                  key: ValueKey('library-upload-retry-$index'),
                  onPressed: () => _send(u),
                  child: const Text('Retry'),
                ),
              TextButton(
                key: ValueKey('library-upload-cancel-$index'),
                onPressed: () => setState(() => _uploads.remove(u)),
                child: Text(u.running ? 'Cancel' : 'Dismiss'),
              ),
            ],
          ),
          if (u.running)
            Semantics(
              label: 'Uploading ${u.file.name}',
              child: const LinearProgressIndicator(),
            )
          else
            Text(
              u.error!,
              key: ValueKey('library-upload-error-$index'),
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.error,
              ),
            ),
        ],
      ),
    );
  }

  Widget _tile(ThemeData theme, Map<String, dynamic> item) {
    final id = '${item['id']}';
    final selected = _picked == id;
    final video = item['kind'] == 'video';

    return Material(
      key: ValueKey('library-item-$id'),
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(8),
        side: BorderSide(
          color: selected
              ? theme.colorScheme.primary
              : theme.colorScheme.outlineVariant,
          width: selected ? 2 : 1,
        ),
      ),
      clipBehavior: Clip.antiAlias,
      child: Semantics(
        selected: selected,
        button: true,
        label: '${item['name'] ?? ''}',
        child: InkWell(
          onTap: () => setState(() => _picked = id),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Stack(
                children: [
                  AspectRatio(
                    aspectRatio: 4 / 3,
                    child: video
                        ? ColoredBox(
                            color: theme.colorScheme.surfaceContainerHighest,
                            child: const Icon(
                              Icons.play_circle_outline,
                              size: 32,
                            ),
                          )
                        : FutureBuilder<Uint8List?>(
                            future: _thumb(id),
                            builder: (context, snap) => snap.data == null
                                ? ColoredBox(
                                    color: theme
                                        .colorScheme
                                        .surfaceContainerHighest,
                                  )
                                : Image.memory(snap.data!, fit: BoxFit.cover),
                          ),
                  ),
                  if (selected)
                    Positioned(
                      top: 4,
                      right: 4,
                      child: Icon(
                        Icons.check_circle,
                        key: ValueKey('library-selected-$id'),
                        color: theme.colorScheme.primary,
                      ),
                    ),
                ],
              ),
              if (_renaming == id)
                Padding(
                  padding: const EdgeInsets.all(4),
                  child: TextField(
                    key: ValueKey('library-rename-$id'),
                    controller: _name,
                    autofocus: true,
                    maxLength: 120,
                    decoration: const InputDecoration(
                      isDense: true,
                      counterText: '',
                    ),
                    onSubmitted: (_) => _rename(id),
                  ),
                )
              else
                Padding(
                  padding: const EdgeInsets.fromLTRB(6, 4, 0, 4),
                  child: Row(
                    children: [
                      Expanded(
                        child: Text(
                          '${item['name'] ?? ''}',
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: theme.textTheme.bodySmall,
                        ),
                      ),
                      if (widget.canEdit)
                        PopupMenuButton<String>(
                          key: ValueKey('library-menu-$id'),
                          iconSize: 18,
                          padding: EdgeInsets.zero,
                          onSelected: (act) => setState(() {
                            if (act == 'rename') {
                              _renaming = id;
                              _name.text = '${item['name'] ?? ''}';
                            } else {
                              _removing = id;
                            }
                          }),
                          itemBuilder: (_) => const [
                            PopupMenuItem(
                              value: 'rename',
                              child: Text('Rename'),
                            ),
                            PopupMenuItem(
                              value: 'remove',
                              child: Text('Remove'),
                            ),
                          ],
                        ),
                    ],
                  ),
                ),
              // Remove asks once more, in place.
              if (_removing == id)
                Padding(
                  padding: const EdgeInsets.fromLTRB(4, 0, 4, 4),
                  child: Row(
                    children: [
                      TextButton(
                        onPressed: () => setState(() => _removing = null),
                        child: const Text('Keep'),
                      ),
                      const Spacer(),
                      TextButton(
                        key: ValueKey('library-remove-$id'),
                        style: TextButton.styleFrom(
                          foregroundColor: theme.colorScheme.error,
                        ),
                        onPressed: () => _remove(id),
                        child: const Text('Remove'),
                      ),
                    ],
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }
}
