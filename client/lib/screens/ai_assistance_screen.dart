/// AI Assistance — its own module since 2026-09-24.
///
/// Jose: "a screen in the app where im able to change the replies from the
/// menu myself ... a new tab called AI Assistance, so we can also have the
/// same inside the app for people who dont have whatsapp ... 3 tabs AI -
/// Library - Settings".
///
/// AI is the assistant itself — the same answers the WhatsApp line gives,
/// read from the same server rule. Library is where they are written. Settings
/// is the assistant's name, welcome and which sites it searches.
///
/// Tabs are tracked by KEY, never by position — see work_hours_screen.dart.
library;

import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/session.dart';
import '../widgets/tab_bar_like.dart';
import 'settings_screen.dart';

class AiAssistanceScreen extends StatefulWidget {
  const AiAssistanceScreen({
    super.key,
    required this.api,
    required this.session,
  });

  final ApiClient api;
  final Session session;

  @override
  State<AiAssistanceScreen> createState() => _AiAssistanceScreenState();
}

class _AiAssistanceScreenState extends State<AiAssistanceScreen> {
  String _tab = 'ai';
  final Set<String> _shown = {'ai'};

  bool _may(String key) =>
      widget.session.me?.permissions.contains(key) ?? false;

  List<(String, String, IconData, Widget Function())> get _tabs => [
    if (_may('ai_assistance.ai.view'))
      ('ai', 'AI', Icons.forum_outlined, () => AssistantTab(api: widget.api)),
    if (_may('ai_assistance.library.view'))
      (
        'library',
        'Library',
        Icons.menu_book_outlined,
        () => LibraryTab(
          api: widget.api,
          session: widget.session,
          canEdit: _may('ai_assistance.manage'),
        ),
      ),
    if (_may('ai_assistance.settings.view'))
      (
        'settings',
        'Settings',
        Icons.tune,
        () => SettingsScreen(
          api: widget.api,
          session: widget.session,
          onlyGroup: 'ai_assistance',
        ),
      ),
  ];

  String get _active {
    final tabs = _tabs;
    if (tabs.isEmpty || tabs.any((t) => t.$1 == _tab)) return _tab;

    return tabs.first.$1;
  }

  @override
  Widget build(BuildContext context) {
    final tabs = _tabs;
    final theme = Theme.of(context);

    if (tabs.isEmpty) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(32),
          child: Text(
            'No part of AI Assistance has been opened to you yet. Somebody who '
            'can edit roles adds AI, Library or Settings under '
            'Settings › Roles & permissions.',
            textAlign: TextAlign.center,
            style: theme.textTheme.bodyMedium,
          ),
        ),
      );
    }

    final active = _active;
    final index = tabs.indexWhere((t) => t.$1 == active);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Material(
          color: theme.colorScheme.surface,
          child: TabBarLike(
            labels: [for (final tab in tabs) (tab.$2, tab.$3)],
            selected: index < 0 ? 0 : index,
            onSelected: (i) => setState(() {
              _tab = tabs[i].$1;
              _shown.add(_tab);
            }),
          ),
        ),
        const Divider(height: 1),
        Expanded(
          child: Stack(
            fit: StackFit.expand,
            children: [
              for (final tab in tabs)
                Offstage(
                  offstage: tab.$1 != active,
                  child: _shown.contains(tab.$1) || tab.$1 == active
                      ? tab.$4()
                      : const SizedBox.shrink(),
                ),
            ],
          ),
        ),
      ],
    );
  }
}

/// WhatsApp's *bold* and _italic_ markers, dropped for the app — the words
/// are written once, for both. A URL's underscores are left alone: only a
/// whole line wrapped in underscores is italic.
String plainAnswer(String text) => text
    .replaceAllMapped(RegExp(r'\*([^*\n]+)\*'), (m) => m[1]!)
    .replaceAllMapped(RegExp(r'^_(.+)_$', multiLine: true), (m) => m[1]!);

/// The assistant, in the app. Everything this person may ask about arrives
/// in one call; the server decides which answers that is.
class AssistantTab extends StatefulWidget {
  const AssistantTab({super.key, required this.api});

  final ApiClient api;

  @override
  State<AssistantTab> createState() => _AssistantTabState();
}

class _AssistantTabState extends State<AssistantTab> {
  Map<String, dynamic>? _menu;
  String? _error;
  final _query = TextEditingController();
  Map<String, dynamic>? _found;
  String? _askError;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _query.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    final response = await widget.api.get('/ai-assistance/menu');
    if (!mounted) return;
    setState(() {
      _menu = response.ok ? response.data as Map<String, dynamic> : null;
      _error = response.ok ? null : response.message;
    });
  }

  /// The same search the WhatsApp line runs on typed words: answers first,
  /// then the places the words name.
  Future<void> _ask() async {
    final q = _query.text.trim();
    if (q.length < 2) return;
    final response = await widget.api.get('/ai-assistance/ask', {'q': q});
    if (!mounted) return;
    setState(() {
      _found = response.ok ? response.data as Map<String, dynamic> : null;
      _askError = response.ok ? null : response.reason;
    });
  }

  Widget _answerTile(dynamic e, {bool open = false}) => ExpansionTile(
    initiallyExpanded: open,
    title: Text(e['title'] as String),
    subtitle: e['description'] == null
        ? null
        : Text(e['description'] as String),
    childrenPadding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
    expandedCrossAxisAlignment: CrossAxisAlignment.start,
    children: [SelectableText(plainAnswer(e['body'] as String))],
  );

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final menu = _menu;

    if (_error != null) return Center(child: Text(_error!));
    if (menu == null) return const Center(child: CircularProgressIndicator());

    final sections = menu['sections'] as List<dynamic>;
    final answers = (_found?['answers'] as List<dynamic>?) ?? const [];
    final sites = (_found?['locations'] as List<dynamic>?) ?? const [];

    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Text(menu['name'] as String, style: theme.textTheme.titleLarge),
        const SizedBox(height: 4),
        Text(plainAnswer(menu['welcome'] as String)),
        const SizedBox(height: 16),
        TextField(
          controller: _query,
          decoration: InputDecoration(
            hintText: 'Ask a question, or type a city or state',
            suffixIcon: IconButton(
              icon: const Icon(Icons.search),
              tooltip: 'Ask',
              onPressed: _ask,
            ),
          ),
          onSubmitted: (_) => _ask(),
        ),
        if (_askError != null) Text(_askError!),
        if (_found != null && answers.isEmpty && sites.isEmpty)
          const Padding(
            padding: EdgeInsets.only(top: 8),
            child: Text('Nothing matched - no answer and no location.'),
          ),
        for (final e in answers)
          Card(child: _answerTile(e, open: answers.length == 1)),
        for (final site in sites)
          ListTile(
            title: Text(site['company_name'] as String? ?? ''),
            subtitle: SelectableText(
              [
                site['phone'],
                site['address'],
                if (site['service_area'] != null)
                  'Serves: ${site['service_area']}',
              ].whereType<String>().where((l) => l.isNotEmpty).join('\n'),
            ),
          ),
        const SizedBox(height: 16),
        if (sections.isEmpty)
          const Text('No answers yet. Add them on the Library tab.'),
        for (final s in sections)
          Card(
            child: ExpansionTile(
              title: Text(s['section'] as String),
              children: [
                for (final e in s['entries'] as List<dynamic>) _answerTile(e),
              ],
            ),
          ),
      ],
    );
  }
}

/// The answers, grouped as the menu groups them, and where they are edited.
class LibraryTab extends StatefulWidget {
  const LibraryTab({
    super.key,
    required this.api,
    required this.session,
    required this.canEdit,
  });

  final ApiClient api;
  final Session session;
  final bool canEdit;

  @override
  State<LibraryTab> createState() => _LibraryTabState();
}

class _LibraryTabState extends State<LibraryTab> {
  List<Map<String, dynamic>>? _entries;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final response = await widget.api.get('/ai-assistance/entries');
    if (!mounted) return;
    setState(() {
      _entries = response.ok
          ? (response.data as List<dynamic>).cast<Map<String, dynamic>>()
          : null;
      _error = response.ok ? null : response.message;
    });
  }

  /// Where an answer lives, in the words the switcher uses.
  String _place(Map<String, dynamic> e) {
    final me = widget.session.me;
    final franchise = e['franchise_id'] as String?;
    final company = e['company_id'] as String?;
    if (franchise != null) {
      return me?.franchises.where((f) => f.id == franchise).firstOrNull?.name ??
          'A place';
    }
    if (company != null) {
      return me?.companies.where((c) => c.id == company).firstOrNull?.name ??
          'A company';
    }
    return 'Whole organization';
  }

  Future<void> _edit([Map<String, dynamic>? entry]) async {
    final saved = await showDialog<bool>(
      context: context,
      builder: (_) =>
          _EntryDialog(api: widget.api, session: widget.session, entry: entry),
    );
    if (saved == true) _load();
  }

  @override
  Widget build(BuildContext context) {
    final entries = _entries;
    if (_error != null) return Center(child: Text(_error!));
    if (entries == null) {
      return const Center(child: CircularProgressIndicator());
    }

    final bySection = <String, List<Map<String, dynamic>>>{};
    for (final e in entries) {
      bySection.putIfAbsent(e['section'] as String, () => []).add(e);
    }

    return Scaffold(
      backgroundColor: Colors.transparent,
      floatingActionButton: widget.canEdit
          ? FloatingActionButton.extended(
              onPressed: () => _edit(),
              icon: const Icon(Icons.add),
              label: const Text('Add answer'),
            )
          : null,
      body: ListView(
        padding: const EdgeInsets.fromLTRB(16, 16, 16, 96),
        children: [
          if (entries.isEmpty)
            const Text(
              'No answers yet. Each answer appears in the WhatsApp menu and on '
              'the AI tab, grouped by its section.',
            ),
          for (final section in bySection.entries) ...[
            Padding(
              padding: const EdgeInsets.only(top: 12, bottom: 4),
              child: Text(
                section.value.length > 10
                    ? '${section.key} — WhatsApp shows the first 10'
                    : section.key,
                style: Theme.of(context).textTheme.titleMedium,
              ),
            ),
            for (final e in section.value)
              Card(
                child: ListTile(
                  title: Text(e['title'] as String),
                  subtitle: Text(
                    [
                      _place(e),
                      e['audience'] == 'staff' ? 'Staff only' : 'Everyone',
                    ].join(' · '),
                  ),
                  trailing: widget.canEdit
                      ? const Icon(Icons.edit_outlined)
                      : null,
                  onTap: widget.canEdit ? () => _edit(e) : null,
                ),
              ),
          ],
        ],
      ),
    );
  }
}

class _EntryDialog extends StatefulWidget {
  const _EntryDialog({required this.api, required this.session, this.entry});

  final ApiClient api;
  final Session session;
  final Map<String, dynamic>? entry;

  @override
  State<_EntryDialog> createState() => _EntryDialogState();
}

class _EntryDialogState extends State<_EntryDialog> {
  late final _section = TextEditingController(
    text: widget.entry?['section'] as String? ?? '',
  );
  late final _title = TextEditingController(
    text: widget.entry?['title'] as String? ?? '',
  );
  late final _description = TextEditingController(
    text: widget.entry?['description'] as String? ?? '',
  );
  late final _body = TextEditingController(
    text: widget.entry?['body'] as String? ?? '',
  );
  late final _position = TextEditingController(
    text: '${widget.entry?['position'] ?? 0}',
  );
  late String _audience = widget.entry?['audience'] as String? ?? 'public';
  String? _error;
  bool _busy = false;

  @override
  void dispose() {
    for (final c in [_section, _title, _description, _body, _position]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _save() async {
    setState(() => _busy = true);
    final me = widget.session.me;
    final fields = <String, dynamic>{
      'section': _section.text.trim(),
      'title': _title.text.trim(),
      'description': _description.text.trim().isEmpty
          ? null
          : _description.text.trim(),
      'body': _body.text,
      'audience': _audience,
      'position': int.tryParse(_position.text.trim()) ?? 0,
    };
    final id = widget.entry?['id'];
    final response = id == null
        ? await widget.api.post('/ai-assistance/entries', {
            ...fields,
            // Filed at the current view, as Training files a guide.
            'company_id': ?me?.filingCompanyId,
            'franchise_id': ?me?.filingFranchiseId,
          })
        : await widget.api.patch('/ai-assistance/entries/$id', fields);
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
      '/ai-assistance/entries/${widget.entry!['id']}',
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

  Widget _field(
    TextEditingController c,
    String label, {
    int? max,
    int lines = 1,
    String? help,
  }) => Padding(
    padding: const EdgeInsets.only(bottom: 12),
    child: TextField(
      controller: c,
      maxLength: max,
      minLines: lines,
      maxLines: lines == 1 ? 1 : null,
      decoration: InputDecoration(labelText: label, helperText: help),
    ),
  );

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: Text(widget.entry == null ? 'Add answer' : 'Edit answer'),
      content: SizedBox(
        width: 560,
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              _field(
                _section,
                'Section',
                max: 24,
                help: 'Answers with the same section are grouped in the menu.',
              ),
              _field(_title, 'Title', max: 24),
              _field(_description, 'Description (optional)', max: 72),
              _field(
                _body,
                'Answer',
                max: 4096,
                lines: 8,
                help: 'WhatsApp shows *bold* and _italic_.',
              ),
              // Two choices, both shown: no popup (no_popup_dropdown_test).
              Align(
                alignment: Alignment.centerLeft,
                child: SegmentedButton<String>(
                  segments: const [
                    ButtonSegment(value: 'public', label: Text('Everyone')),
                    ButtonSegment(value: 'staff', label: Text('Staff only')),
                  ],
                  selected: {_audience},
                  onSelectionChanged: (v) =>
                      setState(() => _audience = v.first),
                ),
              ),
              const Padding(
                padding: EdgeInsets.only(top: 4),
                child: Text(
                  'On WhatsApp, staff are active users whose phone is on file.',
                ),
              ),
              const SizedBox(height: 12),
              _field(_position, 'Order', help: 'Lower numbers come first.'),
              if (_error != null)
                Text(
                  _error!,
                  style: TextStyle(color: Theme.of(context).colorScheme.error),
                ),
            ],
          ),
        ),
      ),
      actions: [
        if (widget.entry != null)
          TextButton(
            onPressed: _busy ? null : _delete,
            child: const Text('Delete'),
          ),
        TextButton(
          onPressed: _busy ? null : () => Navigator.of(context).pop(false),
          child: const Text('Cancel'),
        ),
        FilledButton(
          onPressed: _busy ? null : _save,
          child: const Text('Save'),
        ),
      ],
    );
  }
}
