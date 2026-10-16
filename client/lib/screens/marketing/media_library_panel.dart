import 'dart:typed_data';

import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/files.dart';
import '../../widgets/side_panel.dart';

/// Social › Compose › From library (UI queue 105, design 1): the library in a
/// side panel, to pick from and to look after. Returns the item picked, or
/// null. A post keeps its own copy, so removing an item never breaks one.
Future<Map<String, dynamic>?> showMediaLibraryPanel(
  BuildContext context, {
  required ApiClient api,
  required Future<PickedBytes?> Function() pickMedia,
  required bool canEdit,
}) => showSidePanel<Map<String, dynamic>>(
  context,
  builder: (panel) =>
      _LibraryPanel(api: api, pickMedia: pickMedia, canEdit: canEdit),
);

class _LibraryPanel extends StatefulWidget {
  const _LibraryPanel({
    required this.api,
    required this.pickMedia,
    required this.canEdit,
  });

  final ApiClient api;
  final Future<PickedBytes?> Function() pickMedia;
  final bool canEdit;

  @override
  State<_LibraryPanel> createState() => _LibraryPanelState();
}

class _LibraryPanelState extends State<_LibraryPanel> {
  List<Map<String, dynamic>>? _items;
  String _kind = 'all';
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
  }

  @override
  void dispose() {
    _name.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    final response = await widget.api.get('/social/library');
    if (!mounted) return;
    setState(() {
      _items = response.ok && response.data is List
          ? (response.data as List).whereType<Map<String, dynamic>>().toList()
          : const [];
      if (!response.ok) _error = response.message;
    });
  }

  Future<Uint8List?> _thumb(String id) => _thumbs[id] ??= widget.api
      .getBytes('/social/library/$id/file')
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
                child: TextField(
                  key: const ValueKey('library-find'),
                  decoration: const InputDecoration(
                    isDense: true,
                    prefixIcon: Icon(Icons.search, size: 18),
                    hintText: 'Search',
                  ),
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
      child: InkWell(
        onTap: () => setState(() => _picked = id),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            AspectRatio(
              aspectRatio: 4 / 3,
              child: video
                  ? ColoredBox(
                      color: theme.colorScheme.surfaceContainerHighest,
                      child: const Icon(Icons.play_circle_outline, size: 32),
                    )
                  : FutureBuilder<Uint8List?>(
                      future: _thumb(id),
                      builder: (context, snap) => snap.data == null
                          ? ColoredBox(
                              color: theme.colorScheme.surfaceContainerHighest,
                            )
                          : Image.memory(snap.data!, fit: BoxFit.cover),
                    ),
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
                          PopupMenuItem(value: 'rename', child: Text('Rename')),
                          PopupMenuItem(value: 'remove', child: Text('Remove')),
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
    );
  }
}
