/// Guidelines — Jose, 2026-10-14: "a company can create cards, or situations,
/// then users can click on those cards and get step by step guides ... with a
/// search bar ... and the settings tab to create them ... colors and images".
///
/// Guidelines is the searchable card grid; Settings is where cards are made.
/// Tabs are tracked by KEY, never by position — see work_hours_screen.dart.
library;

import 'dart:typed_data';

import 'package:flutter/material.dart';

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

  Future<void> _open(Map<String, dynamic> card) =>
      showSidePanel<void>(context, builder: (_) => _ReadPanel(card: card));

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

    return Scaffold(
      floatingActionButton: widget.editing
          ? FloatingActionButton.extended(
              key: const ValueKey('guidelines-add'),
              onPressed: () => _edit(),
              icon: const Icon(Icons.add),
              label: const Text('Add guideline'),
            )
          : null,
      body: CustomScrollView(
        slivers: [
          SliverPadding(
            padding: const EdgeInsets.fromLTRB(16, 16, 16, 8),
            sliver: SliverToBoxAdapter(
              child: TextField(
                key: const ValueKey('guidelines-search'),
                decoration: const InputDecoration(
                  prefixIcon: Icon(Icons.search),
                  hintText: 'Search guidelines',
                  isDense: true,
                  border: OutlineInputBorder(),
                ),
                onChanged: (v) => setState(() => _query = v),
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
                      actionLabel: widget.editing ? 'Add guideline' : null,
                      onAction: widget.editing ? () => _edit() : null,
                    )
                  : EmptyState(
                      icon: Icons.search_off,
                      title: 'No match',
                      message: 'Nothing matches "$_query".',
                    ),
            )
          else
            SliverPadding(
              padding: const EdgeInsets.fromLTRB(16, 8, 16, 96),
              sliver: SliverGrid(
                // Sibling tiles are all the same size.
                gridDelegate: const SliverGridDelegateWithMaxCrossAxisExtent(
                  maxCrossAxisExtent: 280,
                  mainAxisExtent: 220,
                  crossAxisSpacing: 12,
                  mainAxisSpacing: 12,
                ),
                delegate: SliverChildBuilderDelegate(
                  childCount: shown.length,
                  (context, i) => _CardTile(
                    card: shown[i],
                    editing: widget.editing,
                    onTap: () =>
                        widget.editing ? _edit(shown[i]) : _open(shown[i]),
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

/// The card opened: image, numbered steps, notes.
class _ReadPanel extends StatelessWidget {
  const _ReadPanel({required this.card});

  final Map<String, dynamic> card;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final accent =
        ColourField.parse(card['color'] as String? ?? '') ??
        theme.colorScheme.primary;
    final image = _images[_imageKey(card)];
    final steps = (card['steps'] as List<dynamic>? ?? const []).cast<String>();
    final notes = card['notes'] as String?;

    return SidePanel(
      key: ValueKey('guideline-read-${card['id']}'),
      title: card['title'] as String? ?? 'Guideline',
      subtitle: card['summary'] as String?,
      width: 560,
      child: Column(
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
                    radius: 14,
                    backgroundColor: accent,
                    foregroundColor: EzBiziTheme.fieldInkLight,
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
                      child: SelectableText(steps[i]),
                    ),
                  ),
                ],
              ),
            ),
          if (notes != null && notes.trim().isNotEmpty) ...[
            const Divider(height: 24),
            Text('Notes', style: theme.textTheme.titleSmall),
            const SizedBox(height: 4),
            SelectableText(notes),
          ],
        ],
      ),
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
  // ponytail: one step per line, not a reorderable list editor; add one if
  // steps grow long enough to need several lines each.
  late final _steps = TextEditingController(
    text: ((widget.card?['steps'] as List<dynamic>?) ?? const []).join('\n'),
  );
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
    for (final c in [_title, _summary, _steps, _notes, _position]) {
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
        for (final line in _steps.text.split('\n'))
          if (line.trim().isNotEmpty) line.trim(),
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
      title: widget.card == null ? 'Add guideline' : 'Edit guideline',
      width: 560,
      actions: [
        if (widget.card != null)
          TextButton(
            onPressed: _busy ? null : _delete,
            child: const Text('Delete'),
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
          _field(
            _steps,
            'Steps',
            lines: 8,
            help: 'One step per line, in order.',
            key: const ValueKey('guidelines-steps'),
          ),
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
