import '../widgets/list_search.dart';
import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/session.dart';
import '../core/theme.dart';

import '../core/device_key_store_io.dart'
    if (dart.library.js_interop) '../core/device_key_store_web.dart'
    as store;

/// Task Board's boards as a tree down the left (UI queue 152, 1; Jose,
/// 2026-10-27: "add the left navigation sidebar as well, people would
/// obviously only see what they have access to, like monday has"). It
/// replaces the "Other boards" picker.
///
/// Built from GET /task-boards only: a board's `section` (mine, shared,
/// place, everyone), `kind`, `company_name`, `franchise_id` and `owner`. A
/// custom board with a `franchise_id` sits under that branch's board; one
/// without sits under its company.
/// Everyone's boards appear only when the list holds some, which it does only
/// for tasks.view_all.
///
/// What is folded, whether the tree is collapsed to a strip and its dragged
/// width are kept on this device under [storeKey].
class BoardTree extends StatefulWidget {
  const BoardTree({
    super.key,
    required this.boards,
    required this.selected,
    required this.onOpen,
    required this.storeKey,
    this.onNewBoard,
    this.onDropTask,
    this.docked = true,
    this.api,
    this.canArrange = false,
    this.onArranged,
    this.session,
  });

  /// Reads the organization's folders; null: no folders (older callers).
  final ApiClient? api;

  /// Whose favorites (`task_board.favorites`, theirs alone; design 200).
  final Session? session;

  /// May make folders, colour and move boards (tasks.create_boards).
  final bool canArrange;

  /// A board's folder, colour or place changed: the board list reloads.
  final VoidCallback? onArranged;

  /// Monday's folder and board colours, as data like a status's colour.
  static const palette = EzBiziTheme.boardPalette;

  final List<Map<String, dynamic>> boards;

  /// The index of the board in view.
  final int selected;
  final ValueChanged<int> onOpen;

  /// Null hides "New board" (no tasks.create_boards).
  final VoidCallback? onNewBoard;

  /// A task dragged from the board dropped on board [index]: moved there.
  /// Null: boards in the tree take no drops.
  final void Function(int index, String taskId)? onDropTask;

  /// Prefix of the device-kept fold, collapse and width.
  final String storeKey;

  /// A sidebar beside the board (collapsible, draggable); false on a phone,
  /// where the tree fills the screen.
  final bool docked;

  static const double minWidth = 200;
  static const double maxWidth = 420;
  static const double defaultWidth = 264;

  @override
  State<BoardTree> createState() => _BoardTreeState();
}

class _BoardTreeState extends State<BoardTree> {
  final _search = TextEditingController();
  late final Set<String> _folded = {
    ...?store
        .readItem('${widget.storeKey}_folded')
        ?.split('\n')
        .where((s) => s.isNotEmpty),
  };
  late bool _collapsed =
      store.readItem('${widget.storeKey}_collapsed') == 'true';
  late double _width =
      (double.tryParse(store.readItem('${widget.storeKey}_width') ?? '') ??
              BoardTree.defaultWidth)
          .clamp(BoardTree.minWidth, BoardTree.maxWidth);

  // ── Folders and colours (Jose 11-13, Monday parity) ───────────

  List<Map<String, dynamic>> _folders = const [];

  /// The automatic company and branch groups' colours, the organization's:
  /// keyed `company:` plus the company's name, or `branch:` plus its
  /// franchise id.
  Map<String, String> _groupColours = const {};
  String? _renamingFolder;
  final _folderName = TextEditingController();

  @override
  void initState() {
    super.initState();
    _loadFolders();
  }

  Future<void> _loadFolders() async {
    final api = widget.api;
    if (api == null) return;
    final r = await api.get('/task-board-folders');
    final g = await api.get('/task-board-group-colours');
    if (mounted && g.ok && g.data is Map) {
      setState(
        () => _groupColours = {
          for (final e in (g.data as Map).entries)
            if (e.value is String) '${e.key}': e.value as String,
        },
      );
    }
    if (!mounted || !r.ok) return;
    setState(
      () => _folders = [
        for (final f in (r.data as List? ?? const []).whereType<Map>())
          f.cast<String, dynamic>(),
      ],
    );
  }

  static Color? _colour(Object? hex) {
    if (hex is! String || hex.length != 7) return null;
    final v = int.tryParse(hex.substring(1), radix: 16);
    return v == null ? null : Color(v | 0xFF000000);
  }

  static String _hex(Color c) =>
      '#${(c.toARGB32() & 0xFFFFFF).toRadixString(16).padLeft(6, '0')}';

  Future<void> _call(Future<ApiResponse> Function(ApiClient) run) async {
    final api = widget.api;
    if (api == null) return;
    final r = await run(api);
    if (!mounted) return;
    if (!r.ok) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(r.reason)));
    }
    await _loadFolders();
    widget.onArranged?.call();
  }

  Future<void> _patchBoard(String id, Map<String, dynamic> body) =>
      _call((api) => api.patch('/task-boards/$id', body));

  Future<void> _setGroupColour(String group, String? colour) => _call(
    (api) => api.patch('/task-board-group-colours', {
      'group': group,
      'colour': colour,
    }),
  );

  Future<void> _patchFolder(String id, Map<String, dynamic> body) =>
      _call((api) => api.patch('/task-board-folders/$id', body));

  Future<void> _newFolder({String? parent}) async {
    final api = widget.api;
    if (api == null) return;
    final r = await api.post('/task-board-folders', {
      'name': 'New folder',
      'parent_id': ?parent,
    });
    if (!mounted) return;
    await _loadFolders();
    if (r.ok && r.data is Map) {
      setState(() {
        _renamingFolder = '${(r.data as Map)['id']}';
        _folderName.text = 'New folder';
        if (parent != null) _folded.remove('folder:$parent');
      });
    }
  }

  /// Drops board [boardId] just before board [before] in that board's
  /// folder, renumbering that folder's boards.
  Future<void> _reorder(String boardId, Map<String, dynamic> before) async {
    final folder = before['folder_id'];
    final siblings = [
      for (final b in widget.boards)
        if (b['folder_id'] == folder && '${b['id']}' != boardId) b,
    ]..sort(_byPlace);
    final at = siblings.indexWhere((b) => b['id'] == before['id']);
    final order = [for (final b in siblings) '${b['id']}']
      ..insert(at < 0 ? siblings.length : at, boardId);
    final api = widget.api;
    if (api == null) return;
    // The new order shows at once (Jose 11-20: it took about a minute, one
    // awaited PATCH per board): the rows are renumbered here, then the
    // writes go out together and the list is read back once they land.
    final writes = <Future<ApiResponse>>[];
    for (final (i, id) in order.indexed) {
      final b = widget.boards.where((x) => '${x['id']}' == id).firstOrNull;
      if (b == null) continue;
      if (id == boardId) {
        b['folder_id'] = folder;
        b['position'] = i;
        writes.add(
          api.patch('/task-boards/$id', {'folder_id': folder, 'position': i}),
        );
      } else if (b['position'] != i) {
        b['position'] = i;
        writes.add(api.patch('/task-boards/$id', {'position': i}));
      }
    }
    setState(() {});
    final answers = await Future.wait(writes);
    if (!mounted) return;
    final refused = answers.where((r) => !r.ok).firstOrNull;
    if (refused != null) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(refused.reason)));
    }
    widget.onArranged?.call();
  }

  Widget _folderDraggable(String id, String name, Widget child) =>
      widget.canArrange && widget.api != null
      ? Draggable<String>(
          key: ValueKey('folder-drag-$id'),
          data: 'folder:$id',
          feedback: Material(
            elevation: 6,
            borderRadius: BorderRadius.circular(8),
            child: Padding(padding: const EdgeInsets.all(8), child: Text(name)),
          ),
          childWhenDragging: Opacity(opacity: 0.4, child: child),
          child: child,
        )
      : child;

  /// Drops folder [folderId] just before [before], among that folder's
  /// siblings; shown at once, as boards are.
  Future<void> _reorderFolder(
    String folderId,
    Map<String, dynamic> before,
  ) async {
    final api = widget.api;
    if (api == null) return;
    final parent = before['parent_id'];
    final siblings = [
      for (final f in _folders)
        if (f['parent_id'] == parent && '${f['id']}' != folderId) f,
    ]..sort(_byPlace);
    final at = siblings.indexWhere((f) => f['id'] == before['id']);
    final order = [for (final f in siblings) '${f['id']}']
      ..insert(at < 0 ? siblings.length : at, folderId);
    final writes = <Future<ApiResponse>>[];
    for (final (i, id) in order.indexed) {
      final f = _folders.where((x) => '${x['id']}' == id).firstOrNull;
      if (f == null) continue;
      if (id == folderId) {
        f['parent_id'] = parent;
        f['position'] = i;
        writes.add(
          api.patch('/task-board-folders/$id', {
            'parent_id': parent,
            'position': i,
          }),
        );
      } else if (f['position'] != i) {
        f['position'] = i;
        writes.add(api.patch('/task-board-folders/$id', {'position': i}));
      }
    }
    setState(() {});
    final answers = await Future.wait(writes);
    if (!mounted) return;
    final refused = answers.where((r) => !r.ok).firstOrNull;
    if (refused != null) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(refused.reason)));
    }
    await _loadFolders();
  }

  /// This person's starred boards, in their order.
  List<String> get _favorites => [
    for (final id
        in (widget.session?.me?.preferences['task_board.favorites'] as List?) ??
            const [])
      '$id',
  ];

  void _saveFavorites(List<String> ids) {
    widget.session?.setPreference('task_board.favorites', ids);
    setState(() {});
  }

  void _toggleFavorite(String id) {
    final now = _favorites;
    _saveFavorites(now.contains(id) ? (now..remove(id)) : [...now, id]);
  }

  /// A starred board dropped on another takes its place in Favorites.
  void _moveFavorite(String id, String before) {
    final now = _favorites..remove(id);
    final at = now.indexOf(before);
    now.insert(at < 0 ? now.length : at, id);
    _saveFavorites(now);
  }

  /// The arranged order (shared: task_boards.position), and with none set a
  /// company's own board first (Jose 11-18: A-1 Chimney at the top), then by name.
  int _byPlace(Map<String, dynamic> a, Map<String, dynamic> b) {
    final pa = (a['position'] as num?) ?? 1000000;
    final pb = (b['position'] as num?) ?? 1000000;
    if (pa != pb) return pa.compareTo(pb);
    // A company's own board, then its custom boards, then its branches.
    int rank(Map<String, dynamic> x) => switch (x['kind']) {
      'organization' || 'company' => 0,
      'custom' => 1,
      _ => 2,
    };
    return rank(a) != rank(b)
        ? rank(a) - rank(b)
        : _name(a).compareTo(_name(b));
  }

  /// Move up/down in its own section and folder (the board's ⋯ menu, for the
  /// keyboard; Jose 11-18: boards could only change group, not order).
  /// Its place among the boards it moves with: (index, count).
  (int, int) _placeIn(String boardId) {
    final me = widget.boards.firstWhere((b) => '${b['id']}' == boardId);
    final row = [
      for (final b in widget.boards)
        if (b['folder_id'] == me['folder_id'] && b['section'] == me['section'])
          b,
    ]..sort(_byPlace);
    return (row.indexWhere((b) => '${b['id']}' == boardId), row.length);
  }

  Future<void> _shift(String boardId, int by) async {
    final me = widget.boards.firstWhere((b) => '${b['id']}' == boardId);
    final row = [
      for (final b in widget.boards)
        if (b['folder_id'] == me['folder_id'] && b['section'] == me['section'])
          b,
    ]..sort(_byPlace);
    final at = row.indexWhere((b) => '${b['id']}' == boardId);
    final to = at + by;
    if (at < 0 || to < 0 || to >= row.length) return;
    if (by < 0) {
      await _reorder(boardId, row[to]);
    } else if (to + 1 < row.length) {
      await _reorder(boardId, row[to + 1]);
    } else {
      // To the end: the last one goes before me.
      await _reorder('${row[to]['id']}', me);
    }
  }

  List<Widget> _colourItems(
    String keyPrefix,
    Object? current,
    ValueChanged<String?> pick,
  ) => [
    for (final c in BoardTree.palette)
      MenuItemButton(
        key: ValueKey('$keyPrefix-${_hex(c)}'),
        leadingIcon: Icon(
          _hex(c) == current ? Icons.check_circle : Icons.circle,
          color: c,
          size: 18,
        ),
        onPressed: () => pick(_hex(c)),
        child: Text(_hex(c).toUpperCase()),
      ),
    MenuItemButton(
      key: ValueKey('$keyPrefix-none'),
      leadingIcon: const Icon(Icons.circle_outlined, size: 18),
      onPressed: () => pick(null),
      child: const Text('No colour'),
    ),
  ];

  List<Widget> _folderPicks(String boardId, Object? current) => [
    MenuItemButton(
      onPressed: current == null
          ? null
          : () => _patchBoard(boardId, {'folder_id': null}),
      child: const Text('No folder'),
    ),
    for (final f in _folders)
      MenuItemButton(
        key: ValueKey('board-folder-$boardId-${f['id']}'),
        leadingIcon: Icon(Icons.folder, size: 18, color: _colour(f['colour'])),
        onPressed: f['id'] == current
            ? null
            : () => _patchBoard(boardId, {'folder_id': f['id']}),
        child: Text('${f['name']}'),
      ),
  ];

  /// Right-click (or long-press) on [child] opens [items] at the pointer.
  Widget _contextMenu(List<Widget> items, Widget child) {
    if (!widget.canArrange || widget.api == null) return child;
    final menu = MenuController();
    return MenuAnchor(
      controller: menu,
      menuChildren: items,
      child: GestureDetector(
        onSecondaryTapDown: (d) => menu.open(position: d.localPosition),
        onLongPressStart: (d) => menu.open(position: d.localPosition),
        child: child,
      ),
    );
  }

  /// A folder and, inside it, its subfolders then its boards in order.
  List<Widget> _folderRows(
    BuildContext context,
    String? parent,
    double indent,
    Widget Function(int i, double indent) board,
    bool Function(int i) matches,
  ) {
    final theme = Theme.of(context);
    final mine = [
      for (final f in _folders)
        if (f['parent_id'] == parent) f,
    ]..sort(_byPlace);
    final out = <Widget>[];
    for (final f in mine) {
      final id = '${f['id']}';
      final colour = _colour(f['colour']) ?? theme.colorScheme.onSurfaceVariant;
      final boards = [
        for (final (i, b) in widget.boards.indexed)
          if (b['folder_id'] == id &&
              matches(i) &&
              !(b['kind'] == 'user' && b['section'] == 'mine'))
            i,
      ]..sort((a, b) => _byPlace(widget.boards[a], widget.boards[b]));
      final inner = [
        ..._folderRows(context, id, indent + 14, board, matches),
        for (final i in boards) board(i, indent + 14),
      ];
      final searching = _search.text.trim().isNotEmpty;
      if (searching && inner.isEmpty) continue;
      final open = searching || !_folded.contains('folder:$id');
      final head = _renamingFolder == id
          ? Padding(
              padding: EdgeInsets.fromLTRB(8 + indent, 4, 10, 4),
              child: TextField(
                key: ValueKey('folder-name-$id'),
                controller: _folderName,
                autofocus: true,
                decoration: const InputDecoration(isDense: true),
                onSubmitted: (v) {
                  setState(() => _renamingFolder = null);
                  if (v.trim().isNotEmpty) _patchFolder(id, {'name': v.trim()});
                },
              ),
            )
          : _folderDraggable(
              id,
              '${f['name']}',
              InkWell(
                key: ValueKey('tasks-fold-folder:$id'),
                onTap: searching ? null : () => _fold('folder:$id'),
                child: Padding(
                  padding: EdgeInsets.fromLTRB(6 + indent, 6, 10, 6),
                  child: Row(
                    children: [
                      Icon(
                        open ? Icons.arrow_drop_down : Icons.arrow_right,
                        size: 20,
                        color: colour,
                      ),
                      Icon(
                        open ? Icons.folder_open : Icons.folder,
                        size: 18,
                        color: colour,
                      ),
                      const SizedBox(width: 6),
                      Expanded(
                        child: Text(
                          '${f['name']}',
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: theme.textTheme.labelLarge?.copyWith(
                            color: _colour(f['colour']),
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            );
      out.add(
        DragTarget<String>(
          key: ValueKey('folder-drop-$id'),
          onWillAcceptWithDetails: (d) =>
              widget.canArrange &&
              (d.data.startsWith('board:') ||
                  d.data.startsWith('folder:') && d.data != 'folder:$id'),
          onAcceptWithDetails: (d) => d.data.startsWith('folder:')
              ? _reorderFolder(d.data.substring(7), f)
              : _patchBoard(d.data.substring(6), {'folder_id': id}),
          builder: (context, hovering, _) => DecoratedBox(
            decoration: BoxDecoration(
              border: hovering.isEmpty
                  ? null
                  : Border.all(color: theme.colorScheme.primary, width: 2),
            ),
            child: _contextMenu([
              MenuItemButton(
                key: ValueKey('folder-rename-$id'),
                leadingIcon: const Icon(Icons.edit_outlined, size: 18),
                onPressed: () => setState(() {
                  _renamingFolder = id;
                  _folderName.text = '${f['name']}';
                }),
                child: const Text('Rename'),
              ),
              SubmenuButton(
                key: ValueKey('folder-colour-$id'),
                leadingIcon: const Icon(Icons.palette_outlined, size: 18),
                menuChildren: _colourItems(
                  'folder-colour-$id',
                  f['colour'],
                  (c) => _patchFolder(id, {'colour': c}),
                ),
                child: const Text('Change colour'),
              ),
              MenuItemButton(
                key: ValueKey('folder-sub-$id'),
                leadingIcon: const Icon(
                  Icons.create_new_folder_outlined,
                  size: 18,
                ),
                onPressed: () => _newFolder(parent: id),
                child: const Text('New subfolder'),
              ),
              MenuItemButton(
                key: ValueKey('folder-delete-$id'),
                leadingIcon: const Icon(Icons.delete_outline, size: 18),
                onPressed: () =>
                    _call((api) => api.delete('/task-board-folders/$id')),
                child: const Text('Delete folder (boards stay)'),
              ),
            ], head),
          ),
        ),
      );
      if (open) out.addAll(inner);
    }
    return out;
  }

  @override
  void dispose() {
    _search.dispose();
    _folderName.dispose();
    super.dispose();
  }

  void _fold(String key) {
    setState(
      () => _folded.contains(key) ? _folded.remove(key) : _folded.add(key),
    );
    store.writeItem(
      '${widget.storeKey}_folded',
      _folded.join('\n'),
      persist: true,
    );
  }

  void _collapse(bool to) {
    setState(() => _collapsed = to);
    store.writeItem('${widget.storeKey}_collapsed', '$to', persist: true);
  }

  // The board's own name, as stored (Monday's, desk 11-18): never the owner's.
  String _name(Map<String, dynamic> b) => b['kind'] != 'user'
      ? '${b['name']}'
      : b['section'] == 'everyone' || b['section'] == 'shared'
      ? '${b['name'] ?? "${_owner(b) ?? 'Someone'}'s board"}'
      : 'My board';

  String? _owner(Map<String, dynamic> b) =>
      (b['owner'] as Map?)?['name'] as String?;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    if (widget.docked && _collapsed) {
      return Container(
        key: const ValueKey('tasks-boards'),
        width: 40,
        decoration: BoxDecoration(
          color: theme.colorScheme.surface,
          border: Border(right: BorderSide(color: theme.dividerColor)),
        ),
        alignment: Alignment.topCenter,
        child: IconButton(
          key: const Key('tasks-boards-expand'),
          tooltip: 'Show boards',
          icon: const Icon(Icons.keyboard_double_arrow_right, size: 18),
          onPressed: () => _collapse(false),
        ),
      );
    }

    final tree = Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(10, 10, 6, 6),
          child: Row(
            children: [
              Expanded(
                child: ListSearch(
                  key: const ValueKey('tasks-boards-search'),
                  controller: _search,
                  hint: 'Find a board',
                  onChanged: (_) => setState(() {}),
                ),
              ),
              if (widget.docked)
                IconButton(
                  key: const Key('tasks-boards-collapse'),
                  tooltip: 'Hide boards',
                  icon: const Icon(Icons.keyboard_double_arrow_left, size: 18),
                  onPressed: () => _collapse(true),
                ),
            ],
          ),
        ),
        Expanded(
          child: SingleChildScrollView(
            padding: const EdgeInsets.only(bottom: 12),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: _rows(context),
            ),
          ),
        ),
      ],
    );

    if (!widget.docked) {
      return KeyedSubtree(key: const ValueKey('tasks-boards'), child: tree);
    }
    return SizedBox(
      key: const ValueKey('tasks-boards'),
      width: _width,
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Expanded(
            child: ColoredBox(color: theme.colorScheme.surface, child: tree),
          ),
          MouseRegion(
            cursor: SystemMouseCursors.resizeColumn,
            child: GestureDetector(
              key: const Key('tasks-boards-edge'),
              behavior: HitTestBehavior.opaque,
              onHorizontalDragUpdate: (d) => setState(
                () => _width = (_width + d.delta.dx).clamp(
                  BoardTree.minWidth,
                  BoardTree.maxWidth,
                ),
              ),
              onHorizontalDragEnd: (_) => store.writeItem(
                '${widget.storeKey}_width',
                _width.round().toString(),
                persist: true,
              ),
              child: Container(
                width: 6,
                decoration: BoxDecoration(
                  border: Border(right: BorderSide(color: theme.dividerColor)),
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }

  List<Widget> _rows(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    final query = _search.text.trim().toLowerCase();
    final searching = query.isNotEmpty;
    final boards = widget.boards;

    bool matches(int i, [String? within]) {
      if (!searching) return true;
      final b = boards[i];
      return [
        _name(b),
        '${b['company_name'] ?? ''}',
        _owner(b) ?? '',
        ?within,
      ].any((s) => s.toLowerCase().contains(query));
    }

    Widget tile(
      Map<String, dynamic> b,
      int i,
      bool on,
      String text,
      double indent,
      String? from,
    ) {
      // A branch's automatic board falls back to its branch's colour.
      final branch = b['kind'] == 'franchise' && b['franchise_id'] != null
          ? 'branch:${b['franchise_id']}'
          : null;
      final colour =
          _colour(b['colour']) ??
          (branch == null ? null : _colour(_groupColours[branch]));
      final id = '${b['id']}';
      return _contextMenu(
        [
          if (branch != null)
            SubmenuButton(
              key: ValueKey('group-colour-$branch'),
              leadingIcon: const Icon(Icons.storefront_outlined, size: 18),
              menuChildren: _colourItems(
                'group-colour-$branch',
                _groupColours[branch],
                (c) => _setGroupColour(branch, c),
              ),
              child: const Text('Branch colour'),
            ),
          SubmenuButton(
            key: ValueKey('board-colour-$id'),
            leadingIcon: const Icon(Icons.palette_outlined, size: 18),
            menuChildren: _colourItems(
              'board-colour-$id',
              b['colour'],
              (c) => _patchBoard(id, {'colour': c}),
            ),
            child: const Text('Change colour'),
          ),
          MenuItemButton(
            key: ValueKey('board-up-$id'),
            leadingIcon: const Icon(Icons.arrow_upward, size: 18),
            // Already first: nothing to do, so it says so (Jose 11-19: "seems to do nothing").
            onPressed: _placeIn(id).$1 <= 0 ? null : () => _shift(id, -1),
            child: const Text('Move up'),
          ),
          MenuItemButton(
            key: ValueKey('board-down-$id'),
            leadingIcon: const Icon(Icons.arrow_downward, size: 18),
            onPressed: _placeIn(id).$1 >= _placeIn(id).$2 - 1
                ? null
                : () => _shift(id, 1),
            child: const Text('Move down'),
          ),
          SubmenuButton(
            key: ValueKey('board-move-$id'),
            leadingIcon: const Icon(Icons.drive_file_move_outline, size: 18),
            menuChildren: _folderPicks(id, b['folder_id']),
            child: const Text('Move to folder'),
          ),
        ],
        Material(
          color: on ? theme.colorScheme.primaryContainer : Colors.transparent,
          child: InkWell(
            key: ValueKey('tasks-board-${b['id']}'),
            onTap: () => widget.onOpen(i),
            child: Padding(
              padding: EdgeInsets.fromLTRB(12 + indent, 7, 10, 7),
              child: Row(
                children: [
                  Icon(
                    switch (b['kind']) {
                      'user'
                          when b['section'] == 'mine' || b['section'] == null =>
                        Icons.star_outline,
                      'user' => Icons.person_outline,
                      'franchise' => Icons.storefront_outlined,
                      'custom' when b['visibility'] == 'private' =>
                        Icons.lock_outline,
                      'custom' => Icons.dashboard_customize_outlined,
                      _ => Icons.business_outlined,
                    },
                    size: 18,
                    color: colour,
                  ),
                  const SizedBox(width: 8),
                  Expanded(
                    // The owner is a tooltip, never part of the name (desk 11-18).
                    child: Tooltip(
                      message: from == null ? '' : 'Owner: $from',
                      child: Text(
                        text,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: on
                            ? TextStyle(
                                fontWeight: FontWeight.w700,
                                color: theme.colorScheme.onPrimaryContainer,
                              )
                            : null,
                      ),
                    ),
                  ),
                  // No "N open" (Jose 11-20: Monday shows none).
                  if (widget.session != null)
                    InkWell(
                      key: ValueKey('board-star-$id'),
                      borderRadius: BorderRadius.circular(12),
                      onTap: () => _toggleFavorite(id),
                      child: Tooltip(
                        message: _favorites.contains(id)
                            ? 'Remove from Favorites'
                            : 'Add to Favorites',
                        child: Padding(
                          padding: const EdgeInsets.all(2),
                          child: Icon(
                            _favorites.contains(id)
                                ? Icons.star
                                : Icons.star_border,
                            size: 16,
                            color: _favorites.contains(id)
                                ? theme.colorScheme.tertiary
                                : theme.colorScheme.onSurfaceVariant.withValues(
                                    alpha: 0.45,
                                  ),
                          ),
                        ),
                      ),
                    ),
                ],
              ),
            ),
          ),
        ),
      );
    }

    Widget board(
      int i, {
      double indent = 0,
      String? from,
      String? label,
      bool favorite = false,
    }) {
      final b = boards[i];
      final on = i == widget.selected;
      final text = label ?? _name(b);
      final drop = widget.onDropTask;
      // Favorites are one's own: anybody arranges them.
      final arrange = favorite || widget.canArrange && widget.api != null;
      Widget draggable(Widget w) => arrange && b['deleted'] != true
          ? Draggable<String>(
              key: ValueKey('tasks-board-drag-${b['id']}'),
              data: 'board:${b['id']}',
              feedback: Material(
                elevation: 4,
                borderRadius: BorderRadius.circular(8),
                child: Padding(
                  padding: const EdgeInsets.all(8),
                  child: Text(text),
                ),
              ),
              childWhenDragging: Opacity(opacity: 0.4, child: w),
              child: w,
            )
          : w;
      if ((drop != null && !on || arrange) && b['deleted'] != true) {
        return DragTarget<String>(
          key: ValueKey('tasks-board-drop-${b['id']}'),
          onWillAcceptWithDetails: (d) => d.data.startsWith('board:')
              ? arrange && d.data != 'board:${b['id']}'
              : drop != null && !on,
          onAcceptWithDetails: (d) => d.data.startsWith('board:')
              ? favorite
                    ? _moveFavorite(d.data.substring(6), '${b['id']}')
                    : _reorder(d.data.substring(6), b)
              : drop!(i, d.data),
          builder: (context, hovering, _) => DecoratedBox(
            decoration: BoxDecoration(
              border: hovering.isEmpty
                  ? null
                  : Border.all(color: theme.colorScheme.primary, width: 2),
            ),
            child: draggable(tile(b, i, on, text, indent, from)),
          ),
        );
      }
      return draggable(tile(b, i, on, text, indent, from));
    }

    Widget header(String text) => Padding(
      padding: const EdgeInsets.fromLTRB(12, 14, 10, 4),
      child: Text(
        text.toUpperCase(),
        style: muted?.copyWith(fontWeight: FontWeight.w800, letterSpacing: 0.6),
      ),
    );

    // A folding group. Searching opens every group that holds a match.
    List<Widget> group(
      String key,
      String label,
      List<Widget> children, {
      double indent = 0,
      bool asHeader = false,
      String? colourKey,
    }) {
      if (children.isEmpty) return const [];
      final open = searching || !_folded.contains(key);
      final tint = colourKey == null ? null : _colour(_groupColours[colourKey]);
      final row = InkWell(
        key: ValueKey('tasks-fold-$key'),
        onTap: searching ? null : () => _fold(key),
        child: Padding(
          padding: EdgeInsets.fromLTRB(
            6 + indent,
            asHeader ? 12 : 6,
            10,
            asHeader ? 4 : 6,
          ),
          child: Row(
            children: [
              Icon(
                open ? Icons.arrow_drop_down : Icons.arrow_right,
                size: 20,
                color: tint ?? theme.colorScheme.onSurfaceVariant,
              ),
              const SizedBox(width: 2),
              if (tint != null) ...[
                Icon(
                  Icons.circle,
                  key: ValueKey('group-tint-$colourKey'),
                  size: 10,
                  color: tint,
                ),
                const SizedBox(width: 6),
              ],
              Expanded(
                child: Text(
                  asHeader ? label.toUpperCase() : label,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: asHeader
                      ? muted?.copyWith(
                          fontWeight: FontWeight.w800,
                          letterSpacing: 0.6,
                        )
                      : theme.textTheme.labelLarge,
                ),
              ),
            ],
          ),
        ),
      );
      return [
        if (colourKey == null)
          row
        else
          _contextMenu([
            SubmenuButton(
              key: ValueKey('group-colour-$colourKey'),
              leadingIcon: const Icon(Icons.palette_outlined, size: 18),
              menuChildren: _colourItems(
                'group-colour-$colourKey',
                _groupColours[colourKey],
                (c) => _setGroupColour(colourKey, c),
              ),
              child: const Text('Change colour'),
            ),
          ], row),
        if (open) ...children,
      ];
    }

    final sectioned = boards.any((b) => b['section'] != null);
    final folderIds = {for (final f in _folders) '${f['id']}'};
    // My own board is the first entry, above every section, even when it was
    // filed in a folder (Jose, 2026-11-16).
    final personal = boards.indexWhere(
      (b) =>
          b['kind'] == 'user' &&
          (sectioned ? b['section'] == 'mine' : b['archived'] != true),
    );
    bool loose(Map<String, dynamic> b) =>
        !folderIds.contains(b['folder_id']) &&
        (personal < 0 || !identical(b, boards[personal]));
    List<int> inSection(String key) => [
      for (final (i, b) in boards.indexed)
        if (b['section'] == key && loose(b)) i,
    ]..sort((a, b) => _byPlace(boards[a], boards[b]));
    // An older server without sections: the personal board I own is mine,
    // everything else is a place board.
    final mine = sectioned
        ? inSection('mine')
        : [
            for (final (i, b) in boards.indexed)
              if (b['kind'] == 'user' && loose(b)) i,
          ];
    final shared = sectioned ? inSection('shared') : const <int>[];
    final places = sectioned
        ? inSection('place')
        : [
            for (final (i, b) in boards.indexed)
              if (b['kind'] != 'user' && loose(b)) i,
          ];
    final everyone = sectioned ? inSection('everyone') : const <int>[];

    // Companies, each with its own board and custom boards first, then its
    // branches' boards.
    final byCompany = <String, List<int>>{};
    for (final i in places) {
      byCompany
          .putIfAbsent('${boards[i]['company_name'] ?? ''}', () => [])
          .add(i);
    }
    final companies = byCompany.keys.toList()
      ..sort((a, b) => a.isEmpty ? -1 : (b.isEmpty ? 1 : a.compareTo(b)));
    int rank(int i) => switch (boards[i]['kind']) {
      'organization' || 'company' => 0,
      'custom' => 1,
      _ => 2,
    };

    final companyRows = <Widget>[
      for (final company in companies)
        ...() {
          int byName(int a, int b) =>
              _name(boards[a]).compareTo(_name(boards[b]));
          final all = byCompany[company]!;
          // A custom board with a franchise_id sits under that branch's
          // board; without one, or when that branch is not listed, under
          // its company.
          final branchIds = {
            for (final i in all)
              if (rank(i) == 2 && boards[i]['franchise_id'] != null)
                '${boards[i]['franchise_id']}',
          };
          String? branchOf(int i) {
            final f = boards[i]['franchise_id'];
            return rank(i) == 1 && f != null && branchIds.contains('$f')
                ? '$f'
                : null;
          }

          final top = all.where((i) => branchOf(i) == null).toList()
            ..sort((a, b) => _byPlace(boards[a], boards[b]));
          final children = <Widget>[];
          for (final i in top) {
            final under = rank(i) == 2 && boards[i]['franchise_id'] != null
                ? (all
                      .where(
                        (c) => branchOf(c) == '${boards[i]['franchise_id']}',
                      )
                      .toList()
                    ..sort(byName))
                : const <int>[];
            final shownUnder = matches(i)
                ? under
                : under.where((c) => matches(c)).toList();
            if (!matches(i) && shownUnder.isEmpty) continue;
            children.add(
              board(
                i,
                indent: 14,
                // Found by a search, a branch says its company.
                label: searching && company.isNotEmpty && rank(i) == 2
                    ? '$company · ${_name(boards[i])}'
                    : null,
              ),
            );
            for (final c in shownUnder) {
              children.add(board(c, indent: 30));
            }
          }
          return group(
            'company:$company',
            company.isEmpty ? 'Organization' : company,
            children,
            colourKey: 'company:$company',
          );
        }(),
    ];

    final people = <String, List<int>>{};
    for (final i in everyone) {
      final who = _owner(boards[i]) ?? 'Someone';
      if (matches(i)) people.putIfAbsent(who, () => []).add(i);
    }
    final names = people.keys.toList()..sort();

    final favourites = [
      for (final id in _favorites)
        if (boards.indexWhere((b) => '${b['id']}' == id) case final i
            when i >= 0 && matches(i))
          i,
    ];
    final rows = <Widget>[
      ...group('favorites', 'Favorites', asHeader: true, [
        // Its own subtree: the same board is listed again below, with the
        // same keys, and two siblings may not share one.
        for (final i in favourites)
          KeyedSubtree(
            key: ValueKey('favorite-${boards[i]['id']}'),
            child: board(i, favorite: true),
          ),
      ]),
      if (personal >= 0 && matches(personal))
        board(personal, label: 'Personal Task Board'),
      if (mine.any(matches)) header('My boards'),
      for (final i in mine)
        if (matches(i)) board(i),
      ...group('shared', 'Team boards', asHeader: true, [
        for (final i in shared)
          if (matches(i)) board(i, from: _owner(boards[i])),
      ]),
      ...group(
        'folders',
        'Folders',
        asHeader: true,
        _folderRows(context, null, 0, (i, x) => board(i, indent: x), matches),
      ),
      ...group('companies', 'All markets', asHeader: true, companyRows),
      ...group('everyone', "Everyone's boards", asHeader: true, [
        for (final person in names)
          ...group(
            'person:$person',
            '$person · ${people[person]!.length} '
                '${people[person]!.length == 1 ? 'board' : 'boards'}',
            indent: 8,
            [for (final i in people[person]!) board(i, indent: 22)],
          ),
      ]),
      if (searching &&
          !mine.any(matches) &&
          shared.every((i) => !matches(i)) &&
          companyRows.isEmpty &&
          names.isEmpty)
        Padding(
          padding: const EdgeInsets.all(14),
          child: Text(
            'No board matches "${_search.text.trim()}".',
            style: muted,
          ),
        ),
      if (widget.canArrange && widget.api != null)
        InkWell(
          key: const Key('tasks-new-folder'),
          onTap: () => _newFolder(),
          child: Padding(
            padding: const EdgeInsets.fromLTRB(12, 12, 10, 0),
            child: Row(
              children: [
                Icon(
                  Icons.create_new_folder_outlined,
                  size: 18,
                  color: theme.colorScheme.primary,
                ),
                const SizedBox(width: 8),
                Text(
                  'New folder',
                  style: TextStyle(
                    color: theme.colorScheme.primary,
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ],
            ),
          ),
        ),
      if (widget.onNewBoard != null)
        InkWell(
          key: const Key('tasks-new-board'),
          onTap: widget.onNewBoard,
          child: Padding(
            padding: const EdgeInsets.fromLTRB(12, 12, 10, 8),
            child: Row(
              children: [
                Icon(Icons.add, size: 18, color: theme.colorScheme.primary),
                const SizedBox(width: 8),
                Text(
                  'New board',
                  style: TextStyle(
                    color: theme.colorScheme.primary,
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ],
            ),
          ),
        ),
    ];
    return rows;
  }
}
