/// Guidelines — Jose, 2026-10-14: "a company can create cards, or situations,
/// then users can click on those cards and get step by step guides ... with a
/// search bar ... and the settings tab to create them ... colors and images".
///
/// Guidelines is the searchable card grid; Settings is where cards are made.
/// Tabs are tracked by KEY, never by position — see work_hours_screen.dart.
library;

import '../widgets/list_search.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../core/api_client.dart';
import '../core/files.dart';
import '../core/session.dart';
import '../core/theme.dart';
import '../widgets/colour_field.dart';
import '../widgets/empty_state.dart';
import '../widgets/side_panel.dart';
import '../widgets/tab_bar_like.dart';

class GuidelinesScreen extends StatefulWidget {
  const GuidelinesScreen({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<GuidelinesScreen> createState() => _GuidelinesScreenState();
}

class _GuidelinesScreenState extends State<GuidelinesScreen> {
  String _tab = 'guidelines';

  bool _may(String key) =>
      widget.session.me?.permissions.contains(key) ?? false;

  List<(String, String, IconData)> get _tabs => [
    if (_may('guidelines.guidelines.view'))
      ('guidelines', 'Guidelines', Icons.menu_book_outlined),
    if (_may('guidelines.settings.view') && _may('guidelines.manage'))
      ('settings', 'Settings', Icons.tune),
  ];

  @override
  Widget build(BuildContext context) {
    final tabs = _tabs;
    if (tabs.isEmpty) {
      return const Center(
        child: Padding(
          padding: EdgeInsets.all(32),
          child: Text(
            'No part of Guidelines has been opened to you yet. Somebody who '
            'can edit roles adds it under Settings › Roles & permissions.',
            textAlign: TextAlign.center,
          ),
        ),
      );
    }
    final active = tabs.any((t) => t.$1 == _tab) ? _tab : tabs.first.$1;
    final index = tabs.indexWhere((t) => t.$1 == active);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Material(
          color: Theme.of(context).colorScheme.surface,
          child: TabBarLike(
            key: const ValueKey('tour-guidelines-tabs'),
            labels: [for (final t in tabs) (t.$2, t.$3)],
            selected: index,
            onSelected: (i) => setState(() => _tab = tabs[i].$1),
          ),
        ),
        const Divider(height: 1),
        Expanded(
          child: GuidelinesTab(
            key: ValueKey('guidelines-$active'),
            api: widget.api,
            session: widget.session,
            editing: active == 'settings',
          ),
        ),
      ],
    );
  }
}

/// Card images, shared by both tabs, keyed by id + version.
final Map<String, Uint8List> _images = {};

/// Both tabs: the grid to read (with search), or the same grid to edit.
class GuidelinesTab extends StatefulWidget {
  const GuidelinesTab({
    super.key,
    required this.api,
    required this.session,
    required this.editing,
  });

  final ApiClient api;
  final Session session;
  final bool editing;

  @override
  State<GuidelinesTab> createState() => _GuidelinesTabState();
}

class _GuidelinesTabState extends State<GuidelinesTab> {
  List<Map<String, dynamic>>? _cards;
  String? _error;
  String _query = '';

  /// Phone: the card read inside the tab (queue 93, layout 4).
  Map<String, dynamic>? _reading;

  bool get _canEdit =>
      widget.session.me?.permissions.contains('guidelines.manage') ?? false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final response = await widget.api.get('/guidelines');
    if (!mounted) return;
    setState(() {
      _cards = response.ok
          ? (response.data as List<dynamic>).cast<Map<String, dynamic>>()
          : null;
      _error = response.ok ? null : response.message;
    });
    for (final card in _cards ?? const <Map<String, dynamic>>[]) {
      final key = _imageKey(card);
      if (key == null || _images.containsKey(key)) continue;
      final bytes = await widget.api.getBytes(
        '/guidelines/${card['id']}/image',
      );
      if (!mounted || bytes == null) continue;
      setState(() => _images[key] = Uint8List.fromList(bytes));
    }
  }

  bool _matches(Map<String, dynamic> c) {
    final q = _query.trim().toLowerCase();
    if (q.isEmpty) return true;
    return [
      c['title'],
      c['summary'],
      c['notes'],
      ...(c['steps'] as List<dynamic>? ?? const []),
    ].whereType<String>().any((s) => s.toLowerCase().contains(q));
  }

  Future<void> _open(Map<String, dynamic> card) async {
    if (MediaQuery.sizeOf(context).width < 600) {
      setState(() => _reading = card);
      return;
    }
    await showSidePanel<void>(
      context,
      builder: (panel) => _ReadPanel(
        card: card,
        onEdit: _canEdit
            ? () {
                Navigator.of(panel).pop();
                _edit(card);
              }
            : null,
      ),
    );
  }

  /// Settings: drag to reorder, saved as each card's position.
  Future<void> _reorder(int from, int to) async {
    final cards = [..._cards!];
    if (to > from) to--;
    cards.insert(to, cards.removeAt(from));
    setState(() => _cards = cards);
    for (var i = 0; i < cards.length; i++) {
      if (cards[i]['position'] == i) continue;
      cards[i] = {...cards[i], 'position': i};
      await widget.api.patch('/guidelines/${cards[i]['id']}', {'position': i});
    }
  }

  Future<void> _edit([Map<String, dynamic>? card]) async {
    final saved = await showSidePanel<bool>(
      context,
      builder: (_) =>
          _EditPanel(api: widget.api, session: widget.session, card: card),
    );
    if (saved == true) _load();
  }

  @override
  Widget build(BuildContext context) {
    final cards = _cards;
    if (_error != null) return Center(child: Text(_error!));
    if (cards == null) return const Center(child: CircularProgressIndicator());
    final shown = cards.where(_matches).toList();
    final reading = _reading;
    if (!widget.editing && reading != null) {
      return ListView(
        key: const ValueKey('guidelines-phone-read'),
        padding: const EdgeInsets.fromLTRB(8, 8, 16, 32),
        children: [
          Align(
            alignment: Alignment.centerLeft,
            child: TextButton.icon(
              onPressed: () => setState(() => _reading = null),
              icon: const Icon(Icons.arrow_back),
              label: const Text('All guidelines'),
            ),
          ),
          Padding(
            padding: const EdgeInsets.only(left: 8),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text(
                  reading['title'] as String? ?? '',
                  style: Theme.of(context).textTheme.titleLarge,
                ),
                if ((reading['summary'] as String?)?.isNotEmpty ?? false)
                  Text(reading['summary'] as String),
                const SizedBox(height: 16),
                _StepsBody(card: reading, large: true),
              ],
            ),
          ),
        ],
      );
    }

    return Scaffold(
      floatingActionButton: widget.editing
          ? FloatingActionButton.extended(
              key: const ValueKey('guidelines-add'),
              onPressed: () => _edit(),
              icon: const Icon(Icons.add),
              label: const Text('New guideline'),
            )
          : null,
      body: CustomScrollView(
        slivers: [
          SliverPadding(
            padding: const EdgeInsets.fromLTRB(16, 16, 16, 8),
            sliver: SliverToBoxAdapter(
              child: Align(
                alignment: Alignment.centerLeft,
                child: ListSearch(
                  key: const ValueKey('guidelines-search'),
                  hint: 'Search guidelines',
                  onChanged: (v) => setState(() => _query = v),
                ),
              ),
            ),
          ),
          if (shown.isEmpty)
            SliverFillRemaining(
              hasScrollBody: false,
              child: cards.isEmpty
                  ? EmptyState(
                      icon: Icons.menu_book_outlined,
                      title: 'No guidelines yet',
                      message: widget.editing
                          ? 'Add one for each situation your team needs steps for.'
                          : 'Guidelines your company writes will appear here.',
                      actionLabel: widget.editing ? 'New guideline' : null,
                      onAction: widget.editing ? () => _edit() : null,
                    )
                  : EmptyState(
                      icon: Icons.search_off,
                      title: 'No match',
                      message: 'Nothing matches "$_query".',
                    ),
            )
          else if (widget.editing)
            SliverPadding(
              padding: const EdgeInsets.fromLTRB(16, 8, 16, 96),
              sliver: SliverReorderableList(
                itemCount: shown.length,
                // Reordering a filtered list would hide where a card lands.
                onReorder: _query.trim().isEmpty ? _reorder : (_, _) {},
                itemBuilder: (context, i) {
                  final card = shown[i];
                  final accent =
                      ColourField.parse(card['color'] as String? ?? '') ??
                      Theme.of(context).colorScheme.primary;
                  final steps = (card['steps'] as List<dynamic>? ?? []).length;
                  return Card(
                    key: ValueKey('guideline-${card['id']}'),
                    child: ListTile(
                      leading: Icon(Icons.menu_book_outlined, color: accent),
                      title: Text(card['title'] as String? ?? ''),
                      subtitle: Text('$steps ${steps == 1 ? 'step' : 'steps'}'),
                      trailing: ReorderableDragStartListener(
                        index: i,
                        child: const Icon(Icons.drag_handle),
                      ),
                      onTap: () => _edit(card),
                    ),
                  );
                },
              ),
            )
          else
            SliverPadding(
              padding: const EdgeInsets.fromLTRB(16, 8, 16, 96),
              sliver: SliverGrid(
                // Sibling tiles are all the same size.
                gridDelegate: SliverGridDelegateWithMaxCrossAxisExtent(
                  maxCrossAxisExtent: MediaQuery.sizeOf(context).width < 600
                      ? 600
                      : 280,
                  mainAxisExtent: 220,
                  crossAxisSpacing: 12,
                  mainAxisSpacing: 12,
                ),
                delegate: SliverChildBuilderDelegate(
                  childCount: shown.length,
                  (context, i) => _CardTile(
                    card: shown[i],
                    editing: widget.editing,
                    onTap: () => _open(shown[i]),
                  ),
                ),
              ),
            ),
        ],
      ),
    );
  }
}

String? _imageKey(Map<String, dynamic> card) =>
    card['has_image'] == true ? '${card['id']}:${card['image_version']}' : null;

class _CardTile extends StatelessWidget {
  const _CardTile({
    required this.card,
    required this.editing,
    required this.onTap,
  });

  final Map<String, dynamic> card;
  final bool editing;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final accent =
        ColourField.parse(card['color'] as String? ?? '') ??
        theme.colorScheme.primary;
    final image = _images[_imageKey(card)];
    final steps = (card['steps'] as List<dynamic>? ?? const []).length;

    return Card(
      key: ValueKey('guideline-${card['id']}'),
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: onTap,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            SizedBox(
              height: 96,
              child: image != null
                  ? Image.memory(image, fit: BoxFit.cover)
                  : Icon(Icons.menu_book_outlined, size: 40, color: accent),
            ),
            Container(height: 6, color: accent),
            Expanded(
              child: Padding(
                padding: const EdgeInsets.all(12),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      card['title'] as String? ?? '',
                      style: theme.textTheme.titleMedium,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                    ),
                    if ((card['summary'] as String?)?.isNotEmpty ?? false)
                      Text(
                        card['summary'] as String,
                        style: theme.textTheme.bodySmall,
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                      ),
                    const Spacer(),
                    Row(
                      children: [
                        Text(
                          '$steps ${steps == 1 ? 'step' : 'steps'}',
                          style: theme.textTheme.labelSmall,
                        ),
                        const Spacer(),
                        Icon(
                          editing ? Icons.edit_outlined : Icons.chevron_right,
                          size: 18,
                        ),
                      ],
                    ),
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// The card opened on desktop, over the right of the grid (queue 93, 1).
class _ReadPanel extends StatelessWidget {
  const _ReadPanel({required this.card, this.onEdit});

  final Map<String, dynamic> card;
  final VoidCallback? onEdit;

  @override
  Widget build(BuildContext context) {
    final steps = (card['steps'] as List<dynamic>? ?? const []).cast<String>();
    return SidePanel(
      key: ValueKey('guideline-read-${card['id']}'),
      title: card['title'] as String? ?? 'Guideline',
      subtitle: card['summary'] as String?,
      width: 560,
      actions: [
        TextButton.icon(
          onPressed: () {
            Clipboard.setData(
              ClipboardData(
                text: [
                  card['title'],
                  for (var i = 0; i < steps.length; i++)
                    '${i + 1}. ${steps[i]}',
                  if ((card['notes'] as String?)?.isNotEmpty ?? false)
                    card['notes'],
                ].join('\n'),
              ),
            );
            ScaffoldMessenger.maybeOf(
              context,
            )?.showSnackBar(const SnackBar(content: Text('Copied')));
          },
          icon: const Icon(Icons.copy),
          label: const Text('Copy'),
        ),
        if (onEdit != null)
          FilledButton.icon(
            key: const ValueKey('guideline-read-edit'),
            onPressed: onEdit,
            icon: const Icon(Icons.edit_outlined),
            label: const Text('Edit'),
          ),
      ],
      child: _StepsBody(card: card),
    );
  }
}

/// Image, numbered steps in the card's colour, then notes in a muted box.
class _StepsBody extends StatelessWidget {
  const _StepsBody({required this.card, this.large = false});

  final Map<String, dynamic> card;
  final bool large;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final accent =
        ColourField.parse(card['color'] as String? ?? '') ??
        theme.colorScheme.primary;
    final image = _images[_imageKey(card)];
    final steps = (card['steps'] as List<dynamic>? ?? const []).cast<String>();
    final notes = card['notes'] as String?;
    final text = large ? theme.textTheme.bodyLarge : theme.textTheme.bodyMedium;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (image != null)
          Padding(
            padding: const EdgeInsets.only(bottom: 16),
            child: ClipRRect(
              borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
              child: Image.memory(image, fit: BoxFit.cover),
            ),
          ),
        for (var i = 0; i < steps.length; i++)
          Padding(
            padding: const EdgeInsets.only(bottom: 12),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                CircleAvatar(
                  radius: large ? 18 : 14,
                  backgroundColor: accent,
                  child: Text(
                    '${i + 1}',
                    style: theme.textTheme.labelMedium?.copyWith(
                      color: EzBiziTheme.fieldInkLight,
                    ),
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Padding(
                    padding: const EdgeInsets.only(top: 4),
                    child: SelectableText(steps[i], style: text),
                  ),
                ),
              ],
            ),
          ),
        if (notes != null && notes.trim().isNotEmpty)
          Container(
            margin: const EdgeInsets.only(top: 8),
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              color: theme.colorScheme.surfaceContainerHighest,
              borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('Notes', style: theme.textTheme.titleSmall),
                const SizedBox(height: 4),
                SelectableText(notes),
              ],
            ),
          ),
      ],
    );
  }
}

class _EditPanel extends StatefulWidget {
  const _EditPanel({required this.api, required this.session, this.card});

  final ApiClient api;
  final Session session;
  final Map<String, dynamic>? card;

  @override
  State<_EditPanel> createState() => _EditPanelState();
}

class _EditPanelState extends State<_EditPanel> {
  late final _title = TextEditingController(
    text: widget.card?['title'] as String? ?? '',
  );
  late final _summary = TextEditingController(
    text: widget.card?['summary'] as String? ?? '',
  );
  late final List<TextEditingController> _steps = [
    for (final step in (widget.card?['steps'] as List<dynamic>?) ?? const [])
      TextEditingController(text: step as String),
    if ((widget.card?['steps'] as List<dynamic>? ?? const []).isEmpty)
      TextEditingController(),
  ];
  bool _confirmDelete = false;
  late final _notes = TextEditingController(
    text: widget.card?['notes'] as String? ?? '',
  );
  late final _position = TextEditingController(
    text: '${widget.card?['position'] ?? 0}',
  );
  late String? _color = widget.card?['color'] as String?;
  PickedBytes? _newImage;
  bool _removeImage = false;
  String? _error;
  bool _busy = false;

  @override
  void dispose() {
    for (final c in [_title, _summary, ..._steps, _notes, _position]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _save() async {
    setState(() => _busy = true);
    final me = widget.session.me;
    final fields = <String, dynamic>{
      'title': _title.text.trim(),
      'summary': _summary.text.trim().isEmpty ? null : _summary.text.trim(),
      'color': _color,
      'steps': [
        for (final c in _steps)
          if (c.text.trim().isNotEmpty) c.text.trim(),
      ],
      'notes': _notes.text.trim().isEmpty ? null : _notes.text.trim(),
      'position': int.tryParse(_position.text.trim()) ?? 0,
    };
    final id = widget.card?['id'];
    var response = id == null
        ? await widget.api.post('/guidelines', {
            ...fields,
            // Filed at the current view.
            'company_id': ?me?.filingCompanyId,
            'franchise_id': ?me?.filingFranchiseId,
          })
        : await widget.api.patch('/guidelines/$id', fields);
    final savedId = response.ok ? (response.data as Map)['id'] as String : null;
    if (savedId != null && _newImage != null) {
      response = await widget.api.postFile(
        '/guidelines/$savedId/image',
        'image',
        _newImage!.name,
        _newImage!.bytes,
      );
    } else if (savedId != null && _removeImage) {
      response = await widget.api.delete('/guidelines/$savedId/image');
    }
    if (!mounted) return;
    if (response.ok) {
      Navigator.of(context).pop(true);
      return;
    }
    setState(() {
      _busy = false;
      _error = response.reason;
    });
  }

  Future<void> _delete() async {
    // Asks once, in place: the button becomes the confirmation.
    if (!_confirmDelete) {
      setState(() => _confirmDelete = true);
      return;
    }
    setState(() => _busy = true);
    final response = await widget.api.delete(
      '/guidelines/${widget.card!['id']}',
    );
    if (!mounted) return;
    if (response.ok) {
      Navigator.of(context).pop(true);
      return;
    }
    setState(() {
      _busy = false;
      _error = response.reason;
    });
  }

  Future<void> _pickImage() async {
    final picked = await Files.pickImage();
    if (picked == null || !mounted) return;
    setState(() {
      _newImage = picked;
      _removeImage = false;
    });
  }

  Widget _field(
    TextEditingController c,
    String label, {
    int? max,
    int lines = 1,
    String? help,
    Key? key,
  }) => Padding(
    padding: const EdgeInsets.only(bottom: 12),
    child: TextField(
      key: key,
      controller: c,
      maxLength: max,
      minLines: lines,
      maxLines: lines == 1 ? 1 : null,
      decoration: InputDecoration(labelText: label, helperText: help),
    ),
  );

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final hasImage =
        _newImage != null ||
        (!_removeImage && widget.card?['has_image'] == true);

    return SidePanel(
      title: widget.card == null ? 'New guideline' : 'Edit guideline',
      width: 560,
      actions: [
        if (widget.card != null)
          TextButton(
            key: const ValueKey('guidelines-delete'),
            onPressed: _busy ? null : _delete,
            style: TextButton.styleFrom(
              foregroundColor: Theme.of(context).colorScheme.error,
            ),
            child: Text(_confirmDelete ? 'Delete for good?' : 'Delete'),
          ),
        TextButton(
          onPressed: _busy ? null : () => Navigator.of(context).pop(false),
          child: const Text('Cancel'),
        ),
        FilledButton(
          key: const ValueKey('guidelines-save'),
          onPressed: _busy ? null : _save,
          child: const Text('Save'),
        ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          _field(
            _title,
            'Title',
            max: 120,
            key: const ValueKey('guidelines-title'),
          ),
          _field(_summary, 'Short description (optional)', max: 255),
          Text('Steps', style: theme.textTheme.titleSmall),
          ReorderableListView(
            shrinkWrap: true,
            physics: const NeverScrollableScrollPhysics(),
            buildDefaultDragHandles: false,
            onReorder: (from, to) => setState(() {
              if (to > from) to--;
              _steps.insert(to, _steps.removeAt(from));
            }),
            children: [
              for (var i = 0; i < _steps.length; i++)
                Row(
                  key: ObjectKey(_steps[i]),
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Padding(
                      padding: const EdgeInsets.only(top: 14, right: 8),
                      child: Text('${i + 1}.'),
                    ),
                    Expanded(
                      child: TextField(
                        key: ValueKey('guidelines-step-$i'),
                        controller: _steps[i],
                        minLines: 1,
                        maxLines: null,
                        maxLength: 2000,
                        decoration: const InputDecoration(counterText: ''),
                      ),
                    ),
                    IconButton(
                      tooltip: 'Remove step',
                      onPressed: _steps.length == 1
                          ? null
                          : () => setState(() => _steps.removeAt(i).dispose()),
                      icon: const Icon(Icons.close),
                    ),
                    ReorderableDragStartListener(
                      index: i,
                      child: const Padding(
                        padding: EdgeInsets.only(top: 12),
                        child: Icon(Icons.drag_handle),
                      ),
                    ),
                  ],
                ),
            ],
          ),
          Align(
            alignment: Alignment.centerLeft,
            child: TextButton.icon(
              key: const ValueKey('guidelines-add-step'),
              onPressed: () =>
                  setState(() => _steps.add(TextEditingController())),
              icon: const Icon(Icons.add),
              label: const Text('Add step'),
            ),
          ),
          const SizedBox(height: 12),
          _field(_notes, 'Notes (optional)', lines: 3),
          ColourField(
            label: 'Color',
            value: _color ?? '',
            hint: "The card's accent; empty uses the default",
            onChanged: (v) =>
                setState(() => _color = v.trim().isEmpty ? null : v.trim()),
          ),
          const SizedBox(height: 16),
          Text('Image (optional)', style: theme.textTheme.titleSmall),
          const SizedBox(height: 8),
          if (_newImage != null)
            Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: Image.memory(
                Uint8List.fromList(_newImage!.bytes),
                height: 120,
                fit: BoxFit.cover,
              ),
            ),
          Wrap(
            spacing: 8,
            children: [
              OutlinedButton.icon(
                onPressed: _busy ? null : _pickImage,
                icon: const Icon(Icons.image_outlined),
                label: Text(hasImage ? 'Replace image' : 'Add image'),
              ),
              if (hasImage)
                TextButton(
                  onPressed: _busy
                      ? null
                      : () => setState(() {
                          _newImage = null;
                          _removeImage = widget.card?['has_image'] == true;
                        }),
                  child: const Text('Remove image'),
                ),
            ],
          ),
          const SizedBox(height: 12),
          _field(_position, 'Order', help: 'Lower numbers come first.'),
          if (_error != null)
            Text(_error!, style: TextStyle(color: theme.colorScheme.error)),
        ],
      ),
    );
  }
}
