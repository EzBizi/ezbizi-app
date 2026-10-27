import 'package:flutter/material.dart';

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
    this.docked = true,
  });

  final List<Map<String, dynamic>> boards;

  /// The index of the board in view.
  final int selected;
  final ValueChanged<int> onOpen;

  /// Null hides "New board" (no tasks.create_boards).
  final VoidCallback? onNewBoard;

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

  @override
  void dispose() {
    _search.dispose();
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

  String _name(Map<String, dynamic> b) => b['kind'] != 'user'
      ? '${b['name']}'
      : b['section'] == 'everyone' || b['section'] == 'shared'
      ? "${_owner(b) ?? 'Someone'}'s board"
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
                child: TextField(
                  key: const ValueKey('tasks-boards-search'),
                  controller: _search,
                  decoration: const InputDecoration(
                    isDense: true,
                    prefixIcon: Icon(Icons.search, size: 18),
                    hintText: 'Find a board',
                    border: OutlineInputBorder(),
                  ),
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

    Widget board(int i, {double indent = 0, String? from, String? label}) {
      final b = boards[i];
      final on = i == widget.selected;
      final text = label ?? _name(b);
      return Material(
        color: on ? theme.colorScheme.primaryContainer : Colors.transparent,
        child: InkWell(
          key: ValueKey('tasks-board-${b['id']}'),
          onTap: () => widget.onOpen(i),
          child: Padding(
            padding: EdgeInsets.fromLTRB(12 + indent, 7, 10, 7),
            child: Row(
              children: [
                Icon(switch (b['kind']) {
                  'user' when b['section'] == 'mine' || b['section'] == null =>
                    Icons.star_outline,
                  'user' => Icons.person_outline,
                  'franchise' => Icons.storefront_outlined,
                  'custom' when b['visibility'] == 'private' =>
                    Icons.lock_outline,
                  'custom' => Icons.dashboard_customize_outlined,
                  _ => Icons.business_outlined,
                }, size: 18),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    from == null ? text : '$text · from $from',
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
                if ((b['open_for_me'] ?? 0) != 0)
                  Text('${b['open_for_me']} open', style: muted),
              ],
            ),
          ),
        ),
      );
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
    }) {
      if (children.isEmpty) return const [];
      final open = searching || !_folded.contains(key);
      return [
        InkWell(
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
                  color: theme.colorScheme.onSurfaceVariant,
                ),
                const SizedBox(width: 2),
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
        ),
        if (open) ...children,
      ];
    }

    final sectioned = boards.any((b) => b['section'] != null);
    List<int> inSection(String key) => [
      for (final (i, b) in boards.indexed)
        if (b['section'] == key) i,
    ];
    // An older server without sections: the personal board I own is mine,
    // everything else is a place board.
    final mine = sectioned
        ? inSection('mine')
        : [
            for (final (i, b) in boards.indexed)
              if (b['kind'] == 'user') i,
          ];
    final shared = sectioned ? inSection('shared') : const <int>[];
    final places = sectioned
        ? inSection('place')
        : [
            for (final (i, b) in boards.indexed)
              if (b['kind'] != 'user') i,
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
            ..sort(
              (a, b) => rank(a) != rank(b) ? rank(a) - rank(b) : byName(a, b),
            );
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
          );
        }(),
    ];

    final people = <String, List<int>>{};
    for (final i in everyone) {
      final who = _owner(boards[i]) ?? 'Someone';
      if (matches(i)) people.putIfAbsent(who, () => []).add(i);
    }
    final names = people.keys.toList()..sort();

    final rows = <Widget>[
      if (mine.any(matches)) header(sectioned ? 'My boards' : 'My board'),
      for (final i in mine)
        if (matches(i)) board(i),
      ...group('shared', 'Shared with me', asHeader: true, [
        for (final i in shared)
          if (matches(i)) board(i, from: _owner(boards[i])),
      ]),
      ...group('companies', 'Companies', asHeader: true, companyRows),
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
