import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/theme.dart';
import '../../widgets/empty_state.dart';
import '../../widgets/error_note.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/status_pill.dart';

/// AI Assistance › Staff bot (Jose, 2026-10-13: "One number, menu by role ...
/// i also want to see it all in ezbizi so it can be modified as well"; UI
/// queue 88, editor 1 and WhatsApp 4).
///
/// The staff line's menus, one for each group of roles, in WhatsApp's order.
/// Each row LINKS to what answers it: a Training guide's chapter and section,
/// a Library entry, or a report EzBizi writes. Editing the guide changes what
/// the bot says. A row with nothing behind it yet shows "Needs content", and
/// the bot answers it with "coming soon".
///
/// On a computer: menus, rows and the open row's editor side by side. On a
/// phone, one step at a time inside the tab, with a back arrow at each.
class StaffBotTab extends StatefulWidget {
  const StaffBotTab({super.key, required this.api});

  final ApiClient api;

  @override
  State<StaffBotTab> createState() => _StaffBotTabState();
}

class _StaffBotTabState extends State<StaffBotTab> {
  static const _path = '/ai-assistance/staff-bot';

  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _menus = const [];
  List<Map<String, dynamic>> _roles = const [];
  List<Map<String, dynamic>> _guides = const [];
  List<Map<String, dynamic>> _entries = const [];
  List<Map<String, dynamic>> _reports = const [];
  int _max = 9;

  String? _menuId;
  String? _itemId;

  /// On a phone the steps are shown one at a time.
  bool _phoneItems = false;

  final _newMenu = TextEditingController();

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _newMenu.dispose();
    super.dispose();
  }

  static List<Map<String, dynamic>> _list(Object? raw) => [
    for (final r in (raw as List?) ?? const [])
      if (r is Map) Map<String, dynamic>.from(r),
  ];

  Future<void> _load() async {
    setState(() {
      _loading = _menus.isEmpty;
      _error = null;
    });
    final response = await widget.api.get(_path);
    if (!mounted) return;
    setState(() {
      _loading = false;
      if (!response.ok) {
        _error = response.message;
        return;
      }
      _menus = _list(response.body['data']);
      _roles = _list(response.body['roles']);
      _guides = _list(response.body['guides']);
      _entries = _list(response.body['entries']);
      _reports = _list(response.body['reports']);
      _max = (response.body['max_items'] as num?)?.toInt() ?? 9;
      if (_menu == null && _menus.isNotEmpty) {
        _menuId = _menus.first['id'] as String?;
      }
    });
  }

  Map<String, dynamic>? get _menu =>
      _menus.where((m) => m['id'] == _menuId).firstOrNull;

  List<Map<String, dynamic>> get _items => _list(_menu?['items']);

  Map<String, dynamic>? get _item =>
      _items.where((i) => i['id'] == _itemId).firstOrNull;

  /// One write, then the menus again: the server's word on what is stored.
  Future<bool> _write(Future<ApiResponse> call) async {
    final response = await call;
    if (!mounted) return false;
    if (!response.ok) {
      setState(() => _error = response.message);
      return false;
    }
    await _load();
    return true;
  }

  Future<void> _addMenu() async {
    final name = _newMenu.text.trim();
    if (name.isEmpty) return;
    final response = await widget.api.post('$_path/menus', {
      'name': name,
      'role_ids': [],
    });
    if (!mounted) return;
    if (!response.ok) {
      setState(() => _error = response.message);
      return;
    }
    _newMenu.clear();
    _menuId = (response.body['data'] as Map?)?['id'] as String?;
    await _load();
  }

  Future<void> _toggleRole(Map<String, dynamic> menu, String roleId) {
    final roles = [...((menu['role_ids'] as List?) ?? const []).cast<String>()];
    roles.contains(roleId) ? roles.remove(roleId) : roles.add(roleId);
    return _write(
      widget.api.put('$_path/menus/${menu['id']}', {'role_ids': roles}),
    );
  }

  Future<void> _addItem(Map<String, dynamic> menu) async {
    final response = await widget.api.post('$_path/menus/${menu['id']}/items', {
      'title': 'New topic',
      'source': 'library',
    });
    if (!mounted) return;
    if (!response.ok) {
      setState(() => _error = response.message);
      return;
    }
    _itemId = (response.body['data'] as Map?)?['id'] as String?;
    _phoneItems = true;
    await _load();
  }

  Future<void> _reorder(Map<String, dynamic> menu, int from, int to) async {
    final ids = [for (final i in _items) i['id'] as String];
    final moved = ids.removeAt(from);
    ids.insert(to > from ? to - 1 : to, moved);
    await _write(
      widget.api.put('$_path/menus/${menu['id']}/order', {'item_ids': ids}),
    );
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) return const Center(child: CircularProgressIndicator());

    return LayoutBuilder(
      builder: (context, box) {
        final wide = box.maxWidth >= 1000;
        final menu = _menu;
        final error = _error == null
            ? null
            : Padding(
                padding: const EdgeInsets.only(bottom: 12),
                child: ErrorNote(_error!),
              );

        if (_menus.isEmpty) {
          return ListView(
            padding: const EdgeInsets.all(16),
            children: [
              ?error,
              const EmptyState(
                key: Key('staff-bot-empty'),
                icon: Icons.smart_toy_outlined,
                title: 'No menus yet',
                message:
                    'Give each group of roles its own menu on the staff '
                    'WhatsApp line. Until then everybody gets the Library.',
              ),
              const SizedBox(height: 12),
              Center(child: SizedBox(width: 360, child: _menuAdder())),
            ],
          );
        }

        if (!wide) {
          // Phone: the menus, then a menu's rows, then one row.
          final item = _item;
          return ListView(
            padding: const EdgeInsets.all(16),
            children: [
              ?error,
              if (_phoneItems && menu != null && item != null) ...[
                _back('${menu['name']}', () => setState(() => _itemId = null)),
                _ItemEditor(
                  key: ValueKey('staff-bot-editor-${item['id']}'),
                  item: item,
                  guides: _guides,
                  entries: _entries,
                  reports: _reports,
                  onSave: (body) => _write(
                    widget.api.put('$_path/items/${item['id']}', body),
                  ),
                  onDelete: () async {
                    await _write(
                      widget.api.delete('$_path/items/${item['id']}'),
                    );
                    setState(() => _itemId = null);
                  },
                ),
              ] else if (_phoneItems && menu != null) ...[
                _back('Staff bot', () => setState(() => _phoneItems = false)),
                _menuHead(context, menu),
                const SizedBox(height: 8),
                _itemList(
                  context,
                  menu,
                  onOpen: (id) => setState(() => _itemId = id),
                ),
              ] else ...[
                for (final m in _menus) ...[
                  _menuCard(
                    context,
                    m,
                    onTap: () => setState(() {
                      _menuId = m['id'] as String?;
                      _itemId = null;
                      _phoneItems = true;
                    }),
                  ),
                  const SizedBox(height: 8),
                ],
                _menuAdder(),
              ],
            ],
          );
        }

        final item = _item;
        return Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              ?error,
              Expanded(
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    SizedBox(
                      width: 280,
                      child: ListView(
                        children: [
                          for (final m in _menus) ...[
                            _menuCard(
                              context,
                              m,
                              onTap: () => setState(() {
                                _menuId = m['id'] as String?;
                                _itemId = null;
                              }),
                            ),
                            const SizedBox(height: 8),
                          ],
                          _menuAdder(),
                          const SizedBox(height: 8),
                          Text(
                            'A role in no menu gets the Library. A person whose '
                            'role is in two menus gets the first one.',
                            style: Theme.of(context).textTheme.bodySmall
                                ?.copyWith(
                                  color: Theme.of(
                                    context,
                                  ).colorScheme.onSurfaceVariant,
                                ),
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: menu == null
                          ? const SizedBox.shrink()
                          : ListView(
                              children: [
                                _menuHead(context, menu),
                                const SizedBox(height: 8),
                                _itemList(
                                  context,
                                  menu,
                                  onOpen: (id) => setState(() => _itemId = id),
                                ),
                              ],
                            ),
                    ),
                    const SizedBox(width: 12),
                    SizedBox(
                      width: 400,
                      child: item == null
                          ? Card(
                              margin: EdgeInsets.zero,
                              child: Padding(
                                padding: const EdgeInsets.all(16),
                                child: Text(
                                  'Pick a row to edit it, or add one.',
                                  style: Theme.of(context).textTheme.bodyMedium,
                                ),
                              ),
                            )
                          : SingleChildScrollView(
                              child: _ItemEditor(
                                key: ValueKey('staff-bot-editor-${item['id']}'),
                                item: item,
                                guides: _guides,
                                entries: _entries,
                                reports: _reports,
                                onSave: (body) => _write(
                                  widget.api.put(
                                    '$_path/items/${item['id']}',
                                    body,
                                  ),
                                ),
                                onDelete: () async {
                                  await _write(
                                    widget.api.delete(
                                      '$_path/items/${item['id']}',
                                    ),
                                  );
                                  setState(() => _itemId = null);
                                },
                              ),
                            ),
                    ),
                  ],
                ),
              ),
            ],
          ),
        );
      },
    );
  }

  Widget _back(String label, VoidCallback onTap) => Align(
    alignment: Alignment.centerLeft,
    child: TextButton.icon(
      key: const Key('staff-bot-back'),
      onPressed: onTap,
      icon: const Icon(Icons.arrow_back),
      label: Text(label),
    ),
  );

  String _roleName(String id) =>
      '${_roles.where((r) => r['id'] == id).firstOrNull?['name'] ?? 'A role'}';

  Widget _menuCard(
    BuildContext context,
    Map<String, dynamic> m, {
    required VoidCallback onTap,
  }) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final items = _list(m['items']);
    final missing = items.where((i) => i['ready'] != true).length;
    final roles = ((m['role_ids'] as List?) ?? const []).cast<String>();
    final selected = m['id'] == _menuId;

    return Card(
      margin: EdgeInsets.zero,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
        side: BorderSide(
          color: selected ? scheme.primary : scheme.outlineVariant,
          width: selected ? 2 : 1,
        ),
      ),
      child: InkWell(
        key: ValueKey('staff-bot-menu-${m['id']}'),
        borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text('${m['name']}', style: theme.textTheme.titleSmall),
              Text(
                '${items.length} of $_max topics'
                '${missing == 0 ? '' : ' · $missing ${missing == 1 ? 'needs' : 'need'} content'}',
                style: theme.textTheme.bodySmall?.copyWith(
                  color: scheme.onSurfaceVariant,
                ),
              ),
              const SizedBox(height: 6),
              Wrap(
                spacing: 4,
                runSpacing: 4,
                children: roles.isEmpty
                    ? [const StatusPill('No roles yet', tone: Tone.caution)]
                    : [for (final r in roles) StatusPill(_roleName(r))],
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _menuAdder() => Row(
    children: [
      Expanded(
        child: TextField(
          key: const Key('staff-bot-new-menu'),
          controller: _newMenu,
          maxLength: 24,
          decoration: const InputDecoration(
            hintText: 'A new menu, e.g. Office',
            counterText: '',
            isDense: true,
          ),
          onSubmitted: (_) => _addMenu(),
        ),
      ),
      const SizedBox(width: 8),
      FilledButton.tonal(
        key: const Key('staff-bot-add-menu'),
        onPressed: _addMenu,
        child: const Text('Add menu'),
      ),
    ],
  );

  /// The menu's name and who gets it: every role as a chip, ticked or not.
  Widget _menuHead(BuildContext context, Map<String, dynamic> menu) {
    final theme = Theme.of(context);
    final roles = ((menu['role_ids'] as List?) ?? const []).cast<String>();

    return Card(
      margin: EdgeInsets.zero,
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(
                    '${menu['name']}',
                    style: theme.textTheme.titleMedium,
                  ),
                ),
                IconButton(
                  key: const Key('staff-bot-delete-menu'),
                  tooltip: 'Delete this menu',
                  onPressed: () async {
                    await _write(
                      widget.api.delete('$_path/menus/${menu['id']}'),
                    );
                    setState(() {
                      _menuId = _menus.firstOrNull?['id'] as String?;
                      _itemId = null;
                      _phoneItems = false;
                    });
                  },
                  icon: const Icon(Icons.delete_outline),
                ),
              ],
            ),
            Text('Who gets it', style: theme.textTheme.labelLarge),
            const SizedBox(height: 6),
            Wrap(
              spacing: 6,
              runSpacing: 6,
              children: [
                for (final r in _roles)
                  FilterChip(
                    key: ValueKey('staff-bot-role-${r['id']}'),
                    label: Text('${r['name']}'),
                    selected: roles.contains(r['id']),
                    onSelected: (_) => _toggleRole(menu, r['id'] as String),
                  ),
              ],
            ),
          ],
        ),
      ),
    );
  }

  /// The rows in WhatsApp's order: drag one to move it.
  Widget _itemList(
    BuildContext context,
    Map<String, dynamic> menu, {
    required ValueChanged<String> onOpen,
  }) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final items = _items;

    return Card(
      margin: EdgeInsets.zero,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          ReorderableListView(
            key: const Key('staff-bot-items'),
            shrinkWrap: true,
            physics: const NeverScrollableScrollPhysics(),
            buildDefaultDragHandles: true,
            onReorder: (from, to) => _reorder(menu, from, to),
            children: [
              for (final (n, i) in items.indexed)
                Material(
                  key: ValueKey('staff-bot-item-${i['id']}'),
                  color: i['id'] == _itemId
                      ? scheme.primaryContainer.withValues(alpha: .45)
                      : Colors.transparent,
                  child: ListTile(
                    onTap: () => onOpen(i['id'] as String),
                    leading: Text(
                      '${n + 1}',
                      style: theme.textTheme.titleSmall?.copyWith(
                        color: scheme.onSurfaceVariant,
                      ),
                    ),
                    title: Text(
                      '${i['title']}',
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    ),
                    subtitle: Text(
                      _source(i),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    ),
                    trailing: Padding(
                      padding: const EdgeInsets.only(right: 24),
                      child: i['ready'] == true
                          ? const StatusPill('Ready', tone: Tone.ok)
                          : const StatusPill(
                              'Needs content',
                              tone: Tone.caution,
                            ),
                    ),
                  ),
                ),
            ],
          ),
          if (items.length < _max)
            TextButton.icon(
              key: const Key('staff-bot-add-item'),
              onPressed: () => _addItem(menu),
              icon: const Icon(Icons.add),
              label: Text('Add a topic (${items.length} of $_max)'),
            )
          else
            Padding(
              padding: const EdgeInsets.all(12),
              child: Text(
                'WhatsApp shows $_max topics; the tenth row is Find a location.',
                style: theme.textTheme.bodySmall,
              ),
            ),
        ],
      ),
    );
  }

  String _source(Map<String, dynamic> i) => switch (i['source']) {
    'guide' => [
      i['guide_title'] ?? 'A guide',
      ?i['chapter'],
      ?i['section'],
    ].join(' › '),
    'library' => 'Library › ${i['entry_title'] ?? 'no entry picked'}',
    _ => 'From Reports',
  };
}

/// One row: its words as WhatsApp's list shows them, and where its answer
/// comes from.
class _ItemEditor extends StatefulWidget {
  const _ItemEditor({
    super.key,
    required this.item,
    required this.guides,
    required this.entries,
    required this.reports,
    required this.onSave,
    required this.onDelete,
  });

  final Map<String, dynamic> item;
  final List<Map<String, dynamic>> guides;
  final List<Map<String, dynamic>> entries;
  final List<Map<String, dynamic>> reports;
  final Future<bool> Function(Map<String, dynamic> body) onSave;
  final Future<void> Function() onDelete;

  @override
  State<_ItemEditor> createState() => _ItemEditorState();
}

class _ItemEditorState extends State<_ItemEditor> {
  late final _title = TextEditingController(
    text: '${widget.item['title'] ?? ''}',
  );
  late final _line = TextEditingController(
    text: '${widget.item['line'] ?? ''}',
  );
  late String _source = '${widget.item['source'] ?? 'library'}';
  late String? _guide = widget.item['training_guide_id'] as String?;
  late String? _chapter = widget.item['chapter'] as String?;
  late String? _section = widget.item['section'] as String?;
  late String? _entry = widget.item['assistant_entry_id'] as String?;
  late String? _report = widget.item['report'] as String?;
  bool _saving = false;

  @override
  void dispose() {
    _title.dispose();
    _line.dispose();
    super.dispose();
  }

  List<Map<String, dynamic>> get _chapters => [
    for (final c
        in (widget.guides
                    .where((g) => g['id'] == _guide)
                    .firstOrNull?['chapters']
                as List?) ??
            const [])
      if (c is Map) Map<String, dynamic>.from(c),
  ];

  List<String> get _sections => [
    for (final s
        in (_chapters
                    .where((c) => c['name'] == _chapter)
                    .firstOrNull?['sections']
                as List?) ??
            const [])
      '$s',
  ];

  Future<void> _save() async {
    setState(() => _saving = true);
    await widget.onSave({
      'title': _title.text.trim(),
      'line': _line.text.trim().isEmpty ? null : _line.text.trim(),
      'source': _source,
      'training_guide_id': _source == 'guide' ? _guide : null,
      'chapter': _source == 'guide' ? _chapter : null,
      'section': _source == 'guide' ? _section : null,
      'assistant_entry_id': _source == 'library' ? _entry : null,
      'report': _source == 'report' ? _report : null,
    });
    if (mounted) setState(() => _saving = false);
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );

    return Card(
      margin: EdgeInsets.zero,
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(_title.text, style: theme.textTheme.titleMedium),
                ),
                widget.item['ready'] == true
                    ? const StatusPill('Ready', tone: Tone.ok)
                    : const StatusPill('Needs content', tone: Tone.caution),
              ],
            ),
            const SizedBox(height: 12),
            TextField(
              key: const Key('staff-bot-title'),
              controller: _title,
              maxLength: 24,
              decoration: const InputDecoration(labelText: 'Title in the menu'),
              onChanged: (_) => setState(() {}),
            ),
            TextField(
              key: const Key('staff-bot-line'),
              controller: _line,
              maxLength: 72,
              decoration: const InputDecoration(labelText: 'Line under it'),
            ),
            const SizedBox(height: 8),
            Text('The answer comes from', style: theme.textTheme.labelLarge),
            const SizedBox(height: 6),
            SegmentedButton<String>(
              key: const Key('staff-bot-source'),
              showSelectedIcon: false,
              segments: const [
                ButtonSegment(
                  value: 'guide',
                  icon: Icon(Icons.menu_book_outlined),
                  label: Text('Training guide'),
                ),
                ButtonSegment(
                  value: 'library',
                  icon: Icon(Icons.article_outlined),
                  label: Text('Library'),
                ),
                ButtonSegment(
                  value: 'report',
                  icon: Icon(Icons.insights_outlined),
                  label: Text('Reports'),
                ),
              ],
              selected: {_source},
              onSelectionChanged: (v) => setState(() => _source = v.first),
            ),
            const SizedBox(height: 12),
            if (_source == 'guide') ...[
              SearchPickerField<String?>(
                key: const Key('staff-bot-guide'),
                value: _guide,
                label: 'Guide',
                options: [
                  for (final g in widget.guides)
                    SearchOption<String?>(
                      value: g['id'] as String?,
                      label: '${g['title']}',
                    ),
                ],
                onChanged: (v) => setState(() {
                  _guide = v;
                  _chapter = null;
                  _section = null;
                }),
              ),
              const SizedBox(height: 10),
              SearchPickerField<String?>(
                key: const Key('staff-bot-chapter'),
                value: _chapter,
                label: 'Chapter',
                options: [
                  const SearchOption<String?>(
                    value: null,
                    label: 'The whole guide',
                  ),
                  for (final c in _chapters)
                    if (c['name'] != null)
                      SearchOption<String?>(
                        value: '${c['name']}',
                        label: '${c['name']}',
                      ),
                ],
                onChanged: (v) => setState(() {
                  _chapter = v;
                  _section = null;
                }),
              ),
              const SizedBox(height: 10),
              SearchPickerField<String?>(
                key: const Key('staff-bot-section'),
                value: _section,
                label: 'Section',
                options: [
                  const SearchOption<String?>(
                    value: null,
                    label: 'The whole chapter',
                  ),
                  for (final s in _sections)
                    SearchOption<String?>(value: s, label: s),
                ],
                onChanged: (v) => setState(() => _section = v),
              ),
              const SizedBox(height: 6),
              Text(
                'Linked, not copied: change the guide and the bot says the new words.',
                style: muted,
              ),
            ] else if (_source == 'library') ...[
              SearchPickerField<String?>(
                key: const Key('staff-bot-entry'),
                value: _entry,
                label: 'Library entry',
                options: [
                  for (final e in widget.entries)
                    SearchOption<String?>(
                      value: e['id'] as String?,
                      label: '${e['section']} › ${e['title']}',
                    ),
                ],
                onChanged: (v) => setState(() => _entry = v),
              ),
              const SizedBox(height: 6),
              Text(
                'For what no guide covers yet: write it in the Library, with its '
                'link, video or photos, and pick it here.',
                style: muted,
              ),
            ] else ...[
              SearchPickerField<String?>(
                key: const Key('staff-bot-report'),
                value: _report,
                label: 'Report',
                options: [
                  for (final r in widget.reports)
                    SearchOption<String?>(
                      value: r['key'] as String?,
                      label: '${r['label']}',
                    ),
                ],
                onChanged: (v) => setState(() => _report = v),
              ),
              const SizedBox(height: 6),
              Text(
                "Written by Reports each time somebody asks: today's new-client "
                'calls, answered, booked and the conversion.',
                style: muted,
              ),
            ],
            const SizedBox(height: 16),
            Row(
              children: [
                TextButton.icon(
                  key: const Key('staff-bot-delete-item'),
                  onPressed: widget.onDelete,
                  icon: const Icon(Icons.delete_outline),
                  label: const Text('Remove'),
                ),
                const Spacer(),
                FilledButton(
                  key: const Key('staff-bot-save'),
                  onPressed: _saving || _title.text.trim().isEmpty
                      ? null
                      : _save,
                  child: Text(_saving ? 'Saving…' : 'Save'),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}
