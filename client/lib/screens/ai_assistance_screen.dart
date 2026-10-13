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

/// The assistant, in the app (UI queue 40, Jose's 1 and 4): topics down the
/// left, the answer in the middle with where it came from, and what the Ask
/// field finds on the right — branches as cards with Call and Map, over the
/// questions asked today. On a phone the same steps are pages with ← back.
///
/// The answers are the Library's own words; nothing here writes them. The
/// search is the WhatsApp line's (`/ai-assistance/ask`), not an AI.
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

/// A question asked today. ponytail: kept while the app is open; a list
/// preference would keep it across sign-ins if anyone asks.
class _Asked {
  _Asked(this.query, this.found, this.at);

  final String query;
  final Map<String, dynamic> found;
  final DateTime at;

  List<dynamic> get answers => found['answers'] as List<dynamic>? ?? const [];
  List<dynamic> get sites => found['locations'] as List<dynamic>? ?? const [];

  String get summary {
    if (answers.length == 1) return answers.single['title'] as String;
    if (answers.isNotEmpty) return '${answers.length} answers';
    if (sites.isNotEmpty) {
      return sites.length == 1 ? '1 branch' : '${sites.length} branches';
    }
    return 'Nothing found';
  }
}

class _AssistantTabState extends State<AssistantTab> {
  Map<String, dynamic>? _menu;
  String? _error;
  final _query = TextEditingController();
  final _queryFocus = FocusNode();
  String? _askError;
  bool _asking = false;

  /// Grok's answer from the Library for the last question, and whether it is
  /// still being asked (Jose, 2026-10-12: "Grok answers in own words").
  String? _aiReply;
  bool _aiAsking = false;

  /// Newest first.
  final List<_Asked> _asked = [];

  /// The question whose results are on screen.
  _Asked? _current;

  /// The answer open in the middle, by id.
  String? _open;

  /// Sections unfolded on the left.
  final Set<String> _unfolded = {};

  bool _allSites = false;

  /// The phone's pages, deepest last: `section:NAME`, `answer:ID` or
  /// `results`. Empty is the topics list.
  final List<String> _pages = [];

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _query.dispose();
    _queryFocus.dispose();
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

  List<dynamic> get _sections =>
      _menu?['sections'] as List<dynamic>? ?? const [];

  /// An answer and the section it sits in, by id: as the last Ask found it
  /// when it did, with its section looked up in the menu (Ask sends none).
  (Map<String, dynamic>, String?)? _entry(String id) {
    Map<String, dynamic>? found;
    String? section;
    for (final s in _sections) {
      for (final e in s['entries'] as List<dynamic>) {
        if (e['id'] == id) (found, section) = (e, s['section'] as String);
      }
    }
    for (final e in _current?.answers ?? const []) {
      if (e['id'] == id) found = e as Map<String, dynamic>;
    }
    return found == null ? null : (found, section);
  }

  /// The same search the WhatsApp line runs on typed words: answers first,
  /// then the places the words name.
  Future<void> _ask([String? again]) async {
    final q = (again ?? _query.text).trim();
    if (q.length < 2) return;
    if (again != null) _query.text = again;
    setState(() => _asking = true);
    final response = await widget.api.get('/ai-assistance/ask', {'q': q});
    if (!mounted) return;
    setState(() {
      _asking = false;
      _askError = response.ok ? null : response.reason;
      if (!response.ok) return;
      final asked = _Asked(
        q,
        response.data as Map<String, dynamic>,
        DateTime.now(),
      );
      _asked
        ..removeWhere((a) => a.query.toLowerCase() == q.toLowerCase())
        ..insert(0, asked);
      _current = asked;
      _allSites = false;
      // One answer opens, as on WhatsApp.
      _open = asked.answers.length == 1
          ? asked.answers.single['id'] as String?
          : null;
      _pages
        ..clear()
        ..add('results');
    });
    _askAi(q);
  }

  /// Asked after the matches show, since Grok takes seconds. A later question
  /// replaces it; a stale answer is dropped.
  Future<void> _askAi(String q) async {
    setState(() {
      _aiReply = null;
      _aiAsking = true;
    });
    final response = await widget.api.get('/ai-assistance/answer', {'q': q});
    if (!mounted || _current?.query != q) return;
    setState(() {
      _aiAsking = false;
      final data = response.ok ? response.data : null;
      _aiReply = data is Map ? data['reply'] as String? : null;
    });
  }

  void _openAnswer(String id, {bool push = true}) => setState(() {
    _open = id;
    if (push) _pages.add('answer:$id');
  });

  void _openSection(Map<String, dynamic> section, {required bool phone}) {
    final entries = section['entries'] as List<dynamic>;
    final name = section['section'] as String;
    // A section with one answer is that answer.
    if (entries.length == 1) {
      _openAnswer(entries.single['id'] as String, push: phone);
      return;
    }
    setState(() {
      if (phone) {
        _pages.add('section:$name');
      } else if (!_unfolded.remove(name)) {
        _unfolded.add(name);
      }
    });
  }

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
    final saved = await showSidePanel<bool>(
      context,
      builder: (_) =>
          _EntryDialog(api: widget.api, session: session, entry: entry),
    );
    if (saved == true) _load();
  }

  @override
  Widget build(BuildContext context) {
    final menu = _menu;

    if (_error != null) return Center(child: Text(_error!));
    if (menu == null) return const Center(child: CircularProgressIndicator());

    return LayoutBuilder(
      builder: (context, box) =>
          box.maxWidth >= 1100 ? _wide(context, menu) : _phone(context, menu),
    );
  }

  // ── desktop: topics | answer | branches ───────────────────────────

  Widget _wide(BuildContext context, Map<String, dynamic> menu) {
    final theme = Theme.of(context);
    final side = theme.colorScheme.surfaceContainerLow;

    return Row(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Container(
          width: 300,
          color: side,
          child: ListView(
            padding: const EdgeInsets.fromLTRB(12, 16, 12, 24),
            children: [
              _bot(context, menu),
              const SizedBox(height: 16),
              _label(context, 'Topics'),
              if (_sections.isEmpty)
                const Padding(
                  padding: EdgeInsets.all(8),
                  child: Text('No answers yet. Add them on the Library tab.'),
                ),
              for (final section in _sections) ..._topic(context, section),
              const SizedBox(height: 16),
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 8),
                child: Text(
                  'A section with one answer opens it. Branches: type a '
                  'city, a state or a branch code above.',
                  style: theme.textTheme.bodySmall,
                ),
              ),
            ],
          ),
        ),
        Expanded(
          child: ListView(
            padding: const EdgeInsets.fromLTRB(24, 16, 24, 32),
            children: [
              Center(
                child: ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: 720),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      _askField(context),
                      _status(context),
                      const SizedBox(height: 12),
                      ..._middle(context),
                    ],
                  ),
                ),
              ),
            ],
          ),
        ),
        Container(
          width: 340,
          color: side,
          child: ListView(
            padding: const EdgeInsets.fromLTRB(12, 16, 12, 24),
            children: [
              ..._branches(context),
              if (_asked.isNotEmpty) ...[
                const SizedBox(height: 16),
                _label(context, 'Asked today'),
                for (final asked in _asked) _askedRow(context, asked),
              ],
            ],
          ),
        ),
      ],
    );
  }

  List<Widget> _topic(BuildContext context, dynamic section) {
    final name = section['section'] as String;
    final entries = section['entries'] as List<dynamic>;
    final single = entries.length == 1;
    final unfolded =
        _unfolded.contains(name) ||
        (!single && entries.any((e) => e['id'] == _open));

    return [
      _row(
        context,
        key: Key('topic-$name'),
        icon: single
            ? Icons.description_outlined
            : unfolded
            ? Icons.expand_more
            : Icons.chevron_right,
        text: name,
        trailing: single ? null : '${entries.length}',
        bold: true,
        selected: single && entries.single['id'] == _open,
        onTap: () =>
            _openSection(section as Map<String, dynamic>, phone: false),
      ),
      if (!single && unfolded)
        for (final e in entries)
          Padding(
            padding: const EdgeInsets.only(left: 22),
            child: _row(
              context,
              key: Key('answer-${e['id']}'),
              text: e['title'] as String,
              selected: e['id'] == _open,
              onTap: () => _openAnswer(e['id'] as String, push: false),
            ),
          ),
    ];
  }

  Widget _row(
    BuildContext context, {
    Key? key,
    IconData? icon,
    required String text,
    String? trailing,
    bool bold = false,
    bool selected = false,
    required VoidCallback onTap,
  }) {
    final theme = Theme.of(context);
    final radius = BorderRadius.circular(EzBiziTheme.controlRadius);
    return Material(
      key: key,
      color: selected ? theme.colorScheme.primaryContainer : Colors.transparent,
      borderRadius: radius,
      child: InkWell(
        borderRadius: radius,
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 8),
          child: Row(
            children: [
              if (icon != null) ...[
                Icon(
                  icon,
                  size: 18,
                  color: theme.colorScheme.onSurfaceVariant,
                ),
                const SizedBox(width: 6),
              ],
              Expanded(
                child: Text(
                  text,
                  style:
                      (bold
                              ? theme.textTheme.titleSmall
                              : theme.textTheme.bodyMedium)
                          ?.copyWith(
                            color: selected ? theme.colorScheme.primary : null,
                          ),
                ),
              ),
              if (trailing != null)
                Text(trailing, style: theme.textTheme.bodySmall),
            ],
          ),
        ),
      ),
    );
  }

  List<Widget> _middle(BuildContext context) {
    final theme = Theme.of(context);
    final open = _open == null ? null : _entry(_open!);
    final found = _current?.answers ?? const [];

    if (open != null) return [_answer(context, open.$1, open.$2)];

    if (found.length > 1) {
      return [
        for (final e in found)
          Card(
            margin: const EdgeInsets.only(bottom: 8),
            child: ListTile(
              title: Text(e['title'] as String),
              subtitle: e['description'] == null
                  ? null
                  : Text(e['description'] as String),
              trailing: const Icon(Icons.chevron_right),
              onTap: () => _openAnswer(e['id'] as String, push: false),
            ),
          ),
      ];
    }

    return [
      Padding(
        padding: const EdgeInsets.only(top: 24),
        child: Text(
          'Pick a topic on the left, or ask above.',
          textAlign: TextAlign.center,
          style: theme.textTheme.bodyMedium,
        ),
      ),
    ];
  }

  // ── the pieces both layouts share ────────────────────────────────

  Widget _bot(BuildContext context, Map<String, dynamic> menu) {
    final theme = Theme.of(context);
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        CircleAvatar(
          radius: 16,
          backgroundColor: theme.colorScheme.primaryContainer,
          child: Icon(
            Icons.smart_toy_outlined,
            size: 18,
            color: theme.colorScheme.primary,
          ),
        ),
        const SizedBox(width: 10),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(menu['name'] as String, style: theme.textTheme.titleSmall),
              const SizedBox(height: 2),
              Text(
                plainAnswer(menu['welcome'] as String),
                style: theme.textTheme.bodySmall,
              ),
            ],
          ),
        ),
      ],
    );
  }

  Widget _label(BuildContext context, String text) => Padding(
    padding: const EdgeInsets.fromLTRB(8, 4, 8, 6),
    child: Text(
      text.toUpperCase(),
      style: Theme.of(context).textTheme.labelSmall?.copyWith(
        letterSpacing: 1.1,
        color: Theme.of(context).colorScheme.onSurfaceVariant,
      ),
    ),
  );

  Widget _askField(BuildContext context) => Row(
    key: const ValueKey('tour-ai_assistance-ask'),
    children: [
      Expanded(
        child: TextField(
          controller: _query,
          focusNode: _queryFocus,
          textInputAction: TextInputAction.search,
          decoration: const InputDecoration(
            hintText: 'Ask a question, or a city, state or branch code',
            prefixIcon: Icon(Icons.search, size: 20),
          ),
          onSubmitted: (_) => _ask(),
        ),
      ),
      const SizedBox(width: 8),
      FilledButton(
        onPressed: _asking ? null : _ask,
        child: Text(_asking ? 'Asking…' : 'Ask'),
      ),
    ],
  );

  Widget _status(BuildContext context) {
    final theme = Theme.of(context);
    final current = _current;
    final text =
        _askError ??
        (current == null
            ? null
            : [
                switch (current.answers.length) {
                  0 => 'No answer for “${current.query}”.',
                  1 => '1 answer for “${current.query}”, opened.',
                  final n => '$n answers for “${current.query}”.',
                },
                switch (current.sites.length) {
                  0 => 'No branch matched.',
                  1 => '1 branch matched.',
                  final n => '$n branches matched.',
                },
              ].join(' '));
    if (text == null) return const SizedBox.shrink();
    final reply = _aiReply;
    return Padding(
      padding: const EdgeInsets.only(top: 8),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(text, style: theme.textTheme.bodySmall),
          if (_aiAsking)
            Padding(
              padding: const EdgeInsets.only(top: 8),
              child: Text(
                'AI is reading the Library…',
                key: const Key('assistant-ai-asking'),
                style: theme.textTheme.bodySmall,
              ),
            )
          else if (reply != null && reply.isNotEmpty)
            Padding(
              key: const Key('assistant-ai-answer'),
              padding: const EdgeInsets.only(top: 8),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const AiMark.badge(
                    'Answer by AI',
                    tooltip:
                        'Written by AI from your Library. Open the matching answers to check it.',
                  ),
                  const SizedBox(height: 4),
                  SelectableText(reply, style: theme.textTheme.bodyMedium),
                ],
              ),
            ),
        ],
      ),
    );
  }

  /// One answer, with where it came from, its footnote and the rest of its
  /// section.
  Widget _answer(
    BuildContext context,
    Map<String, dynamic> e,
    String? section, {
    bool phone = false,
  }) {
    final theme = Theme.of(context);
    final id = e['id'] as String;
    final others = [
      for (final s in _sections)
        if (s['section'] == section)
          for (final other in s['entries'] as List<dynamic>)
            if (other['id'] != id) other,
    ];

    return Container(
      key: const Key('assistant-answer'),
      padding: const EdgeInsets.all(20),
      decoration: BoxDecoration(
        color: theme.colorScheme.surface,
        border: Border.all(color: theme.colorScheme.outlineVariant),
        borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Wrap(
            crossAxisAlignment: WrapCrossAlignment.center,
            spacing: 4,
            children: [
              Icon(
                Icons.menu_book_outlined,
                size: 16,
                color: theme.colorScheme.onSurfaceVariant,
              ),
              Text(
                section == null
                    ? 'From the Library'
                    : 'From the Library · $section › ${e['title']}',
                style: theme.textTheme.bodySmall,
              ),
              TextButton.icon(
                onPressed: () => _copy(
                  '${e['title']}\n\n${plainAnswer(e['body'] as String)}',
                ),
                icon: const Icon(Icons.copy_outlined, size: 16),
                label: const Text('Copy'),
              ),
              if (widget.canEdit && widget.session != null)
                TextButton.icon(
                  onPressed: () => _edit(id),
                  icon: const Icon(Icons.edit_outlined, size: 16),
                  label: const Text('Edit in Library'),
                ),
            ],
          ),
          const SizedBox(height: 8),
          Text(e['title'] as String, style: theme.textTheme.titleLarge),
          if (e['description'] != null) ...[
            const SizedBox(height: 2),
            Text(
              e['description'] as String,
              style: theme.textTheme.bodyMedium?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
          ],
          const SizedBox(height: 14),
          _AnswerBody(e['body'] as String),
          if (others.isNotEmpty) ...[
            const SizedBox(height: 18),
            _label(context, 'More in $section'),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                for (final other in others)
                  OutlinedButton(
                    onPressed: () =>
                        _openAnswer(other['id'] as String, push: phone),
                    child: Text(other['title'] as String),
                  ),
              ],
            ),
          ],
        ],
      ),
    );
  }

  List<Widget> _branches(BuildContext context) {
    final theme = Theme.of(context);
    final current = _current;
    if (current == null || current.sites.isEmpty) {
      return [
        _label(context, 'Branches'),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 8),
          child: Text(
            current == null
                ? 'Type a city, a state or a branch code to find a branch. '
                      'It lands here with Call and Map.'
                : 'No branch matched “${current.query}”.',
            style: theme.textTheme.bodySmall,
          ),
        ),
      ];
    }

    final sites = current.sites;
    final shown = _allSites ? sites : sites.take(3).toList();

    return [
      Padding(
        padding: const EdgeInsets.fromLTRB(8, 4, 8, 8),
        child: Row(
          children: [
            Expanded(
              child: Text(
                'Branches for “${current.query}”',
                style: theme.textTheme.titleSmall,
              ),
            ),
            Text('${sites.length}', style: theme.textTheme.bodySmall),
          ],
        ),
      ),
      for (final site in shown) _site(context, site as Map<String, dynamic>),
      if (shown.length < sites.length)
        TextButton(
          onPressed: () => setState(() => _allSites = true),
          child: Text(
            'Show ${sites.length - shown.length} more: '
            '${sites.skip(3).map((s) => s['company_name']).join(', ')}',
          ),
        ),
    ];
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
      margin: const EdgeInsets.only(bottom: 8),
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

  Widget _askedRow(BuildContext context, _Asked asked) {
    final theme = Theme.of(context);
    return InkWell(
      key: Key('asked-${asked.query}'),
      borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
      onTap: () => _ask(asked.query),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 6),
        child: Row(
          children: [
            Text(asked.query, style: theme.textTheme.titleSmall),
            const SizedBox(width: 8),
            Expanded(
              child: Text(
                asked.summary,
                overflow: TextOverflow.ellipsis,
                style: theme.textTheme.bodySmall,
              ),
            ),
            Text(
              TimeOfDay.fromDateTime(asked.at).format(context),
              style: theme.textTheme.bodySmall,
            ),
          ],
        ),
      ),
    );
  }

  // ── phone: topics, then a page per step ──────────────────────────

  Widget _phone(BuildContext context, Map<String, dynamic> menu) {
    final theme = Theme.of(context);
    final page = _pages.isEmpty ? null : _pages.last;

    Widget back(String label) => Align(
      alignment: Alignment.centerLeft,
      child: TextButton.icon(
        key: const Key('assistant-back'),
        onPressed: () => setState(() {
          _pages.removeLast();
          if (_pages.isEmpty || !_pages.last.startsWith('answer:')) {
            _open = _pages.isNotEmpty && _pages.last == 'results'
                ? _open
                : null;
          }
        }),
        icon: const Icon(Icons.arrow_back, size: 18),
        label: Text(label),
      ),
    );

    final children = <Widget>[];

    if (page == null) {
      children.addAll([
        _bot(context, menu),
        const SizedBox(height: 12),
        _askField(context),
        if (_askError != null) _status(context),
        if (_asked.isNotEmpty) ...[
          const SizedBox(height: 10),
          Wrap(
            spacing: 6,
            runSpacing: 6,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              Text('Today', style: theme.textTheme.bodySmall),
              for (final asked in _asked)
                ActionChip(
                  label: Text(asked.query),
                  onPressed: () => _ask(asked.query),
                ),
            ],
          ),
        ],
        const SizedBox(height: 12),
        if (_sections.isEmpty)
          const Text('No answers yet. Add them on the Library tab.'),
        for (final section in _sections)
          _phoneRow(
            context,
            key: Key('topic-${section['section']}'),
            title: section['section'] as String,
            detail: _sectionDetail(section),
            onTap: () =>
                _openSection(section as Map<String, dynamic>, phone: true),
          ),
        _phoneRow(
          context,
          title: 'Find a branch',
          detail: 'Phone, address, service area',
          icon: Icons.storefront_outlined,
          onTap: () => _queryFocus.requestFocus(),
        ),
      ]);
    } else if (page.startsWith('section:')) {
      final name = page.substring('section:'.length);
      final section = _sections.firstWhere((s) => s['section'] == name);
      children.addAll([
        back('Topics'),
        Text(name, style: theme.textTheme.titleLarge),
        const SizedBox(height: 8),
        for (final e in section['entries'] as List<dynamic>)
          _phoneRow(
            context,
            key: Key('answer-${e['id']}'),
            title: e['title'] as String,
            detail: e['description'] as String?,
            onTap: () => _openAnswer(e['id'] as String),
          ),
      ]);
    } else if (page.startsWith('answer:')) {
      final open = _entry(page.substring('answer:'.length));
      children.addAll([
        back(_pages.length > 1 ? 'Back' : 'Topics'),
        if (open != null) _answer(context, open.$1, open.$2, phone: true),
      ]);
    } else {
      final current = _current!;
      children.addAll([
        back('Topics'),
        _askField(context),
        _status(context),
        const SizedBox(height: 12),
        if (current.answers.length == 1 && _open != null)
          if (_entry(_open!) case final open?)
            _answer(context, open.$1, open.$2, phone: true),
        if (current.answers.length > 1)
          for (final e in current.answers)
            _phoneRow(
              context,
              title: e['title'] as String,
              detail: e['description'] as String?,
              onTap: () => _openAnswer(e['id'] as String),
            ),
        if (current.sites.isNotEmpty) ...[
          const SizedBox(height: 12),
          ..._branches(context),
        ],
      ]);
    }

    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 32),
      children: children,
    );
  }

  String? _sectionDetail(dynamic section) {
    final entries = section['entries'] as List<dynamic>;
    if (entries.length == 1) return entries.single['description'] as String?;
    return '${entries.length} topics · '
        '${entries.take(2).map((e) => e['title']).join(', ')}…';
  }

  Widget _phoneRow(
    BuildContext context, {
    Key? key,
    required String title,
    String? detail,
    IconData? icon,
    required VoidCallback onTap,
  }) {
    final theme = Theme.of(context);
    return Card(
      key: key,
      margin: const EdgeInsets.only(bottom: 8),
      child: ListTile(
        leading: icon == null ? null : Icon(icon),
        title: Text(title, style: theme.textTheme.titleSmall),
        subtitle: detail == null
            ? null
            : Text(detail, maxLines: 1, overflow: TextOverflow.ellipsis),
        trailing: const Icon(Icons.chevron_right),
        onTap: onTap,
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
