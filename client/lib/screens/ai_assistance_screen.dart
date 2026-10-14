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
import 'package:flutter/services.dart';
import 'package:url_launcher/url_launcher.dart';

import '../core/api_client.dart';
import '../core/session.dart';
import '../core/theme.dart';
import '../widgets/ai_mark.dart';
import '../widgets/side_panel.dart';
import '../widgets/tab_bar_like.dart';
import 'ai_assistance/staff_bot_tab.dart';
import 'ai_assistance/whatsapp_groups_tab.dart';
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
      (
        'ai',
        'AI',
        Icons.forum_outlined,
        () => AssistantTab(
          api: widget.api,
          session: widget.session,
          canEdit: _may('ai_assistance.manage'),
        ),
      ),
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
    // Where the reports replacing the Rodrigo bot's are sent (UI queue 61).
    // Its own grant, and manage: every endpoint behind it asks for manage, so
    // without it the tab could only show a refusal.
    if (_may('ai_assistance.whatsapp_groups.view') &&
        _may('ai_assistance.manage'))
      (
        'whatsapp',
        'WhatsApp groups',
        Icons.groups_outlined,
        () => WhatsAppGroupsTab(api: widget.api),
      ),
    // The staff line's menu for each role (UI queue 88). Its own grant, and
    // manage, which every endpoint behind it asks for.
    if (_may('ai_assistance.staff_bot.view') && _may('ai_assistance.manage'))
      (
        'staff_bot',
        'Staff bot',
        Icons.smart_toy_outlined,
        () => StaffBotTab(api: widget.api),
      ),
    if (_may('ai_assistance.settings.view'))
      (
        'settings',
        'Settings',
        Icons.tune,
        () => SettingsScreen(
          key: const ValueKey('tour-ai_assistance-settings'),
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
            key: const ValueKey('tour-ai_assistance-tabs'),
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

/// One line of an answer: WhatsApp's *bold* as bold. A whole line wrapped in
/// underscores is the answer's footnote and is drawn apart, see [_AnswerBody].
List<InlineSpan> _boldSpans(String line, TextStyle bold) {
  final spans = <InlineSpan>[];
  var at = 0;
  for (final m in RegExp(r'\*([^*\n]+)\*').allMatches(line)) {
    if (m.start > at) spans.add(TextSpan(text: line.substring(at, m.start)));
    spans.add(TextSpan(text: m[1], style: bold));
    at = m.end;
  }
  if (at < line.length) spans.add(TextSpan(text: line.substring(at)));
  return spans;
}

final _footnote = RegExp(r'^_(.+)_$');

/// A branch card's three buttons on one line in the 340 column.
final _compact = ButtonStyle(
  visualDensity: VisualDensity.compact,
  padding: WidgetStateProperty.all(
    const EdgeInsets.symmetric(horizontal: 12),
  ),
);

/// The assistant, in the app, as a conversation (UI queue 91, Jose's 1, 4 and
/// 7; Jose, 2026-10-14: "the "AI" tab and that menu on the left aren't really
/// meant to be together. The AI tab should just be the ask bar at the bottom,
/// sort of like here where i can ask you stuff and the reply goes on top").
///
/// Each question asks the Library (`/ai-assistance/ask`, the WhatsApp line's
/// search) and AI (`/ai-assistance/answer`) at once. The answer is AI's words,
/// the Library entries it came from as chips that open the entry, and any
/// branch the words named, with Call and Map. The topics now live in Library.
/// The conversation lasts while the app is open.
class AssistantTab extends StatefulWidget {
  const AssistantTab({
    super.key,
    required this.api,
    this.session,
    this.canEdit = false,
  });

  final ApiClient api;

  /// For "Edit in Library", which opens the Library's own panel.
  final Session? session;
  final bool canEdit;

  @override
  State<AssistantTab> createState() => _AssistantTabState();
}

/// One question and what came back for it.
class _Turn {
  _Turn(this.query);

  final String query;

  /// The Library's matches and branches, once `/ask` answers.
  Map<String, dynamic>? found;

  /// AI's answer, once `/answer` does; null when it could not.
  String? reply;
  bool thinking = true;
  String? error;

  List<dynamic> get answers => found?['answers'] as List<dynamic>? ?? const [];
  List<dynamic> get sites => found?['locations'] as List<dynamic>? ?? const [];
}

class _AssistantTabState extends State<AssistantTab> {
  final _query = TextEditingController();
  final _queryFocus = FocusNode();
  final _scroll = ScrollController();

  /// Oldest first, as a conversation reads.
  final List<_Turn> _turns = [];

  /// The Library's topics, offered as first questions.
  List<String> _topics = const [];

  @override
  void initState() {
    super.initState();
    _loadTopics();
  }

  @override
  void dispose() {
    _query.dispose();
    _queryFocus.dispose();
    _scroll.dispose();
    super.dispose();
  }

  Future<void> _loadTopics() async {
    final response = await widget.api.get('/ai-assistance/menu');
    if (!mounted || !response.ok) return;
    final data = response.data;
    setState(() {
      _topics = [
        for (final s in (data is Map ? data['sections'] : null) as List? ??
            const [])
          if (s is Map && s['section'] is String) s['section'] as String,
      ].take(4).toList();
    });
  }

  Future<void> _ask([String? text]) async {
    final q = (text ?? _query.text).trim();
    if (q.length < 2) return;
    _query.clear();
    final turn = _Turn(q);
    setState(() => _turns.add(turn));
    _toBottom();

    // Both at once: the Library answers in milliseconds, AI in seconds.
    final answer = widget.api.get('/ai-assistance/answer', {'q': q});
    final found = await widget.api.get('/ai-assistance/ask', {'q': q});
    if (!mounted) return;
    setState(() {
      turn.found = found.ok
          ? found.data as Map<String, dynamic>
          : const <String, dynamic>{};
      if (!found.ok) turn.error = found.reason;
    });
    final ai = await answer;
    if (!mounted) return;
    setState(() {
      turn.thinking = false;
      final data = ai.ok ? ai.data : null;
      turn.reply = data is Map ? data['reply'] as String? : null;
    });
    _toBottom();
  }

  /// The newest answer in view, as a chat keeps it.
  void _toBottom() => WidgetsBinding.instance.addPostFrameCallback((_) {
    if (!_scroll.hasClients) return;
    _scroll.animateTo(
      _scroll.position.maxScrollExtent,
      duration: const Duration(milliseconds: 250),
      curve: Curves.easeOut,
    );
  });

  Future<void> _copy(String text) async {
    await Clipboard.setData(ClipboardData(text: text));
    if (!mounted) return;
    ScaffoldMessenger.maybeOf(
      context,
    )?.showSnackBar(const SnackBar(content: Text('Copied.')));
  }

  /// The Library's own panel, on the full entry it lists.
  Future<void> _edit(String id) async {
    final session = widget.session;
    if (session == null) return;
    final response = await widget.api.get('/ai-assistance/entries');
    if (!mounted || !response.ok) return;
    final entry = (response.data as List<dynamic>)
        .cast<Map<String, dynamic>>()
        .where((e) => e['id'] == id)
        .firstOrNull;
    if (entry == null) {
      ScaffoldMessenger.maybeOf(context)?.showSnackBar(
        const SnackBar(
          content: Text('This answer is not in the Library you can edit.'),
        ),
      );
      return;
    }
    await showSidePanel<bool>(
      context,
      builder: (_) =>
          _EntryDialog(api: widget.api, session: session, entry: entry),
    );
  }

  /// A Library entry, read whole, with Copy and (for editors) Edit.
  Future<void> _read(Map<String, dynamic> e) => showSidePanel<void>(
    context,
    builder: (panel) => SidePanel(
      title: e['title'] as String? ?? 'From the Library',
      subtitle: 'From the Library',
      actions: [
        TextButton.icon(
          onPressed: () => _copy(
            '${e['title']}\n\n${plainAnswer(e['body'] as String? ?? '')}',
          ),
          icon: const Icon(Icons.copy_outlined, size: 16),
          label: const Text('Copy'),
        ),
        if (widget.canEdit && widget.session != null)
          FilledButton.icon(
            onPressed: () {
              Navigator.of(panel).pop();
              _edit(e['id'] as String);
            },
            icon: const Icon(Icons.edit_outlined, size: 16),
            label: const Text('Edit in Library'),
          ),
      ],
      child: _AnswerBody(e['body'] as String? ?? ''),
    ),
  );

  @override
  Widget build(BuildContext context) {
    final phone = MediaQuery.sizeOf(context).width < 600;
    final side = phone ? 12.0 : 24.0;

    Widget column(Widget child) => Align(
      alignment: Alignment.topCenter,
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 760),
        child: child,
      ),
    );

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Expanded(
          child: _turns.isEmpty
              ? _hello(context)
              : ListView.builder(
                  key: const ValueKey('assistant-conversation'),
                  controller: _scroll,
                  padding: EdgeInsets.fromLTRB(side, 16, side, 8),
                  itemCount: _turns.length,
                  itemBuilder: (context, i) =>
                      column(_turnView(context, i, _turns[i])),
                ),
        ),
        SafeArea(
          top: false,
          child: Padding(
            padding: EdgeInsets.fromLTRB(side, 4, side, 12),
            child: column(_askBar(context)),
          ),
        ),
      ],
    );
  }

  Widget _hello(BuildContext context) {
    final theme = Theme.of(context);
    return Center(
      child: SingleChildScrollView(
        padding: const EdgeInsets.all(24),
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 560),
          child: Column(
            key: const ValueKey('assistant-hello'),
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(
                'What do you need to know?',
                style: theme.textTheme.titleLarge,
                textAlign: TextAlign.center,
              ),
              const SizedBox(height: 6),
              Text(
                'Ask the way you would ask a colleague: a code, a price, how '
                'we do something, or a city, state or branch code.',
                style: theme.textTheme.bodyMedium?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                ),
                textAlign: TextAlign.center,
              ),
              if (_topics.isNotEmpty) ...[
                const SizedBox(height: 16),
                Wrap(
                  spacing: 8,
                  runSpacing: 8,
                  alignment: WrapAlignment.center,
                  children: [
                    for (final t in _topics)
                      ActionChip(
                        key: ValueKey('assistant-starter-$t'),
                        label: Text(t),
                        onPressed: () => _ask(t),
                      ),
                  ],
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }

  Widget _askBar(BuildContext context) => Row(
    key: const ValueKey('tour-ai_assistance-ask'),
    children: [
      Expanded(
        child: TextField(
          key: const Key('assistant-ask'),
          controller: _query,
          focusNode: _queryFocus,
          textInputAction: TextInputAction.send,
          minLines: 1,
          maxLines: 4,
          decoration: const InputDecoration(
            hintText: 'Ask anything: a code, a price, a branch…',
            border: OutlineInputBorder(),
          ),
          onSubmitted: (_) => _ask(),
        ),
      ),
      const SizedBox(width: 8),
      IconButton.filled(
        key: const Key('assistant-send'),
        tooltip: 'Ask',
        onPressed: _ask,
        icon: const Icon(Icons.arrow_upward),
      ),
    ],
  );

  Widget _turnView(BuildContext context, int i, _Turn turn) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: scheme.onSurfaceVariant,
    );
    final answers = [
      for (final a in turn.answers)
        if (a is Map) a.cast<String, dynamic>(),
    ];
    final sites = [
      for (final s in turn.sites)
        if (s is Map) s.cast<String, dynamic>(),
    ];
    final reply = turn.reply;

    // AI's words when it has them; otherwise the one Library answer, so a
    // question is never left with nothing to read.
    final Widget words;
    if (reply != null && reply.trim().isNotEmpty) {
      words = Column(
        key: const Key('assistant-ai-answer'),
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const AiMark.badge('Answer by AI'),
          const SizedBox(height: 6),
          _AnswerBody(reply),
        ],
      );
    } else if (turn.thinking) {
      words = Row(
        key: const ValueKey('assistant-thinking'),
        children: [
          const SizedBox(
            width: 14,
            height: 14,
            child: CircularProgressIndicator(strokeWidth: 2),
          ),
          const SizedBox(width: 8),
          Text('Thinking…', style: muted),
        ],
      );
    } else if (answers.length == 1) {
      words = _AnswerBody(answers.single['body'] as String? ?? '');
    } else if (answers.isEmpty && sites.isEmpty) {
      words = Text(
        turn.error ??
            'Nothing in the Library matched that, and AI could not answer '
                'just now. Try other words.',
        style: muted,
      );
    } else {
      words = const SizedBox.shrink();
    }

    return Padding(
      key: ValueKey('assistant-turn-$i'),
      padding: const EdgeInsets.only(bottom: 16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Align(
            alignment: Alignment.centerRight,
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 520),
              child: Container(
                padding: const EdgeInsets.symmetric(
                  horizontal: 14,
                  vertical: 10,
                ),
                decoration: BoxDecoration(
                  color: scheme.primary,
                  borderRadius: const BorderRadius.only(
                    topLeft: Radius.circular(16),
                    topRight: Radius.circular(16),
                    bottomLeft: Radius.circular(16),
                    bottomRight: Radius.circular(4),
                  ),
                ),
                child: Text(
                  turn.query,
                  style: theme.textTheme.bodyMedium?.copyWith(
                    color: scheme.onPrimary,
                  ),
                ),
              ),
            ),
          ),
          const SizedBox(height: 8),
          Align(
            alignment: Alignment.centerLeft,
            child: Container(
              padding: const EdgeInsets.all(14),
              decoration: BoxDecoration(
                color: scheme.surface,
                border: Border.all(color: scheme.outlineVariant),
                borderRadius: const BorderRadius.only(
                  topLeft: Radius.circular(16),
                  topRight: Radius.circular(16),
                  bottomLeft: Radius.circular(4),
                  bottomRight: Radius.circular(16),
                ),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  words,
                  if (answers.isNotEmpty) ...[
                    const SizedBox(height: 10),
                    Wrap(
                      key: const Key('assistant-answer'),
                      spacing: 6,
                      runSpacing: 6,
                      crossAxisAlignment: WrapCrossAlignment.center,
                      children: [
                        Text('From your Library', style: muted),
                        for (final a in answers)
                          ActionChip(
                            key: ValueKey('assistant-source-${a['id']}'),
                            avatar: const Icon(
                              Icons.menu_book_outlined,
                              size: 16,
                            ),
                            label: Text(a['title'] as String? ?? 'An answer'),
                            onPressed: () => _read(a),
                          ),
                      ],
                    ),
                  ],
                  for (final site in sites.take(3)) ...[
                    const SizedBox(height: 10),
                    _site(context, site),
                  ],
                  if (sites.length > 3)
                    Text(
                      'And ${sites.length - 3} more: '
                      '${sites.skip(3).map((s) => s['company_name']).join(', ')}',
                      style: muted,
                    ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _site(BuildContext context, Map<String, dynamic> site) {
    final theme = Theme.of(context);
    final name = site['company_name'] as String? ?? '';
    final phone = (site['phone'] as String?)?.trim() ?? '';
    final address = (site['address'] as String?)?.trim() ?? '';
    final area = (site['service_area'] as String?)?.trim() ?? '';
    final map = (site['maps_url'] as String?)?.trim() ?? '';

    return Container(
      key: Key('branch-$name'),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: theme.colorScheme.surface,
        border: Border.all(color: theme.colorScheme.outlineVariant),
        borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(name, style: theme.textTheme.titleSmall),
          if (phone.isNotEmpty) Text(phone, style: theme.textTheme.bodyMedium),
          if (address.isNotEmpty)
            Text(address, style: theme.textTheme.bodySmall),
          if (area.isNotEmpty)
            Text('Serves $area', style: theme.textTheme.bodySmall),
          const SizedBox(height: 8),
          Wrap(
            spacing: 6,
            runSpacing: 6,
            children: [
              if (phone.isNotEmpty)
                FilledButton.icon(
                  style: _compact,
                  onPressed: () => launchUrl(
                    Uri(
                      scheme: 'tel',
                      path: phone.replaceAll(RegExp(r'[^\d+]'), ''),
                    ),
                  ),
                  icon: const Icon(Icons.call_outlined, size: 16),
                  label: const Text('Call'),
                ),
              if (map.isNotEmpty)
                OutlinedButton.icon(
                  style: _compact,
                  onPressed: () => launchUrl(
                    Uri.parse(map),
                    mode: LaunchMode.externalApplication,
                  ),
                  icon: const Icon(Icons.map_outlined, size: 16),
                  label: const Text('Map'),
                ),
              TextButton.icon(
                style: _compact,
                onPressed: () => _copy(
                  [name, phone, address].where((l) => l.isNotEmpty).join('\n'),
                ),
                icon: const Icon(Icons.copy_outlined, size: 16),
                label: const Text('Copy'),
              ),
            ],
          ),
        ],
      ),
    );
  }
}

/// An answer's words: *bold* drawn bold, and a whole line in underscores (the
/// standard's footnote) set apart in italics underneath.
class _AnswerBody extends StatelessWidget {
  const _AnswerBody(this.body);

  final String body;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    const bold = TextStyle(fontWeight: FontWeight.w700);
    final lines = body.trimRight().split('\n');
    final notes = [
      for (final line in lines)
        if (_footnote.firstMatch(line.trim()) case final m?) m[1]!,
    ];
    final words = [
      for (final line in lines)
        if (_footnote.firstMatch(line.trim()) == null) line,
    ].join('\n').trim();

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (words.isNotEmpty)
          SelectableText.rich(
            TextSpan(
              style: theme.textTheme.bodyLarge?.copyWith(height: 1.5),
              children: [
                for (final (i, line) in words.split('\n').indexed) ...[
                  if (i > 0) const TextSpan(text: '\n'),
                  ..._boldSpans(line, bold),
                ],
              ],
            ),
          ),
        if (notes.isNotEmpty) ...[
          const SizedBox(height: 12),
          Container(
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              color: theme.colorScheme.surfaceContainerHighest.withValues(
                alpha: 0.5,
              ),
              borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
            ),
            child: Text(
              notes.join('\n'),
              style: theme.textTheme.bodySmall?.copyWith(
                fontStyle: FontStyle.italic,
              ),
            ),
          ),
        ],
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

  Future<void> _read(Map<String, dynamic> e) => showSidePanel<void>(
    context,
    builder: (_) => SidePanel(
      key: ValueKey('library-read-${e['id']}'),
      title: e['title'] as String? ?? 'An answer',
      subtitle: e['section'] as String?,
      child: _AnswerBody(e['body'] as String? ?? ''),
    ),
  );

  Future<void> _edit([Map<String, dynamic>? entry]) async {
    // A side panel, as every other form: this was the one AlertDialog left
    // on a create path, a box over the list it was adding to.
    final saved = await showSidePanel<bool>(
      context,
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
              key: const ValueKey('tour-ai_assistance-add-answer'),
              onPressed: () => _edit(),
              icon: const Icon(Icons.add),
              label: const Text('Add answer'),
            )
          : null,
      body: ListView(
        key: const ValueKey('tour-ai_assistance-library'),
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
                  trailing: Icon(
                    widget.canEdit
                        ? Icons.edit_outlined
                        : Icons.chevron_right,
                  ),
                  // The topics the AI tab had down its side live here now
                  // (UI queue 91): anybody reads an answer, editors edit it.
                  onTap: () => widget.canEdit ? _edit(e) : _read(e),
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
    return SidePanel(
      title: widget.entry == null ? 'Add answer' : 'Edit answer',
      width: 560,
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
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
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
              onSelectionChanged: (v) => setState(() => _audience = v.first),
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
    );
  }
}
