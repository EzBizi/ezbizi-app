import '../../widgets/list_search.dart';
import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../widgets/async_body.dart';
import '../../widgets/error_note.dart';
import 'guide_builder.dart';
import 'test_builder.dart';

/// Settings' own top level: guides and tests to pick from, so that layout
/// 8's storyboard (guide_builder.dart, test_builder.dart) — the module's one
/// novel pattern — never has to also invent a way to GET to a guide or a
/// test. That part is an ordinary list, the same shape every other module's
/// picker already uses, which is deliberate: layout 8's own frame in
/// `docs/previews/training-layouts.html` does not draw a list at all — it
/// shows "← All training" as a plain back link, implying the picker lives
/// one level up and the storyboard is reached by leaving it entirely, not by
/// a pane beside it (the rejected layout 7's own shape).
///
/// Selecting a row SWAPS [_open] via `setState` — never a modal, never a
/// `Navigator.push` — so [build] returns [GuideBuilder] or [TestBuilder] in
/// place of the list, the exact same in-body pattern `LearnShelves._selected`
/// already uses for its own two players (learn_shelves.dart). The app shell
/// — sidebar, header, top bar — is whatever already wraps this tab; a real
/// route replaced all of it, which is the containment bug this fixed (Jose:
/// "it should all be within the main UI"). Neither builder needs a `Scaffold`
/// of its own to supply `Material`/`Form` for its `RadioListTile`s/
/// `TextFormField`s here — rendered in-body, the app shell's own `Material`
/// (from `Scaffold`, further up the tree) already reaches them, the same way
/// it already reaches `LearnShelves`' swapped-in players. A route push is
/// what used to force the artificial `Scaffold` wrap; without a push there
/// is nothing to wrap. `training_builder_test.dart`'s own "through the real
/// route" tests still reach both by tapping through from this tab's real
/// UI, never by constructing either builder directly — the same guarantee
/// task 13's postmortem calls for, just proven without a route to push.
class TrainingSettingsTab extends StatefulWidget {
  const TrainingSettingsTab({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<TrainingSettingsTab> createState() => _TrainingSettingsTabState();
}

class _TrainingSettingsTabState extends State<TrainingSettingsTab> {
  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _guides = const [];
  List<Map<String, dynamic>> _tests = const [];

  bool _creating = false;
  String? _actionError;

  /// (type, id) of the builder open in place of the list — 'guide' or
  /// 'test'. Null when neither is open, which is when the list draws. The
  /// same shape `LearnShelves._selected` uses for its own two players.
  (String, String)? _open;

  /// What the Settings search field holds. One field for both lists: they sit
  /// under the one Settings header.
  String _query = '';
  Timer? _debounce;

  Me get _me => widget.session.me!;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _debounce?.cancel();
    super.dispose();
  }

  void _search(String value) {
    setState(() => _query = value);
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 400), _load);
  }

  /// Until GuidesController@index and TestsController@index read q, the
  /// loaded rows are filtered here too.
  List<Map<String, dynamic>> _matching(List<Map<String, dynamic>> rows, String countKey, String unit) {
    final q = _query.trim().toLowerCase();
    if (q.isEmpty) return rows;
    return [
      for (final row in rows)
        if ([
          row['title'] as String? ?? '',
          row['status'] == 'published' ? 'Published' : 'Draft',
          '${row[countKey] ?? 0} $unit',
        ].any((field) => field.toLowerCase().contains(q)))
          row,
    ];
  }

  Future<void> _load() async {
    setState(() {
      _loading = _guides.isEmpty && _tests.isEmpty;
      _error = null;
    });

    final q = _query.trim();
    final guides = await widget.api.get('/training/guides', {
      'per_page': '200',
      if (q.isNotEmpty) 'q': q,
    });
    if (!mounted) return;
    if (!guides.ok) {
      setState(() {
        _loading = false;
        _error = guides.message;
      });
      return;
    }

    final tests = await widget.api.get('/training/tests', {
      'per_page': '200',
      if (q.isNotEmpty) 'q': q,
    });
    if (!mounted) return;
    if (!tests.ok) {
      setState(() {
        _loading = false;
        _error = tests.message;
      });
      return;
    }

    setState(() {
      _loading = false;
      _guides = _rows(guides.body['data']);
      _tests = _rows(tests.body['data']);
    });
  }

  void _openGuide(String id) => setState(() => _open = ('guide', id));

  void _openTest(String id) => setState(() => _open = ('test', id));

  /// The builder's own "All training" back link (guide_builder.dart,
  /// test_builder.dart) calls this — the in-body equivalent of the old
  /// route's pop, so a title/publish-status edit made inside the builder is
  /// reflected the moment the list draws again.
  void _closeBuilder() {
    setState(() => _open = null);
    _load();
  }

  Future<void> _newGuide() async {
    setState(() {
      _creating = true;
      _actionError = null;
    });

    final response = await widget.api.post('/training/guides', {
      'title': 'Untitled guide',
      // The current view's own place — never `me.companies.first`, which
      // is reach, not view. `null` at the organization, exactly what the
      // server requires there (`PlaceInView::assert`).
      'company_id': ?_me.filingCompanyId,
      'franchise_id': ?_me.filingFranchiseId,
    });
    if (!mounted) return;
    setState(() => _creating = false);

    if (!response.ok) {
      setState(() => _actionError = response.reason);
      return;
    }

    _openGuide((response.body['data'] as Map)['id'] as String);
  }

  /// Delete a guide or a test, then reload the lists.
  ///
  /// The endpoints have existed since the module shipped — `destroy` on both
  /// controllers, policy-gated on `training.manage` and audited before the
  /// row goes — and until now nothing in the app could reach them. Jose,
  /// 2026-09-23: "no way to delete guides or tests or drafts", with three
  /// "Untitled guide" drafts stranded on this screen.
  ///
  /// A refusal is shown verbatim: the server answers a guide outside your
  /// place with a sentence naming why, and inventing a generic one here would
  /// lose it.
  Future<void> _delete(String path, String id) async {
    setState(() => _actionError = null);

    final response = await widget.api.delete('$path/$id');
    if (!mounted) return;

    if (!response.ok) {
      setState(() => _actionError = response.reason);
      return;
    }

    _load();
  }

  Future<void> _newTest() async {
    setState(() {
      _creating = true;
      _actionError = null;
    });

    final response = await widget.api.post('/training/tests', {
      'title': 'Untitled test',
      'questions': const [],
      'company_id': ?_me.filingCompanyId,
      'franchise_id': ?_me.filingFranchiseId,
    });
    if (!mounted) return;
    setState(() => _creating = false);

    if (!response.ok) {
      setState(() => _actionError = response.reason);
      return;
    }

    _openTest((response.body['data'] as Map)['id'] as String);
  }

  @override
  Widget build(BuildContext context) {
    final open = _open;
    if (open != null) {
      final (type, id) = open;
      return type == 'test'
          ? TestBuilder(api: widget.api, session: widget.session, testId: id, onLeave: _closeBuilder)
          : GuideBuilder(api: widget.api, session: widget.session, guideId: id, onLeave: _closeBuilder);
    }

    final guides = _matching(_guides, 'screen_count', 'screens');
    final tests = _matching(_tests, 'question_count', 'questions');
    final query = _query.trim();
    final bodyText = Theme.of(context).textTheme.bodyMedium;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        PanelHeader(
          title: 'Settings',
          note: 'Where a guide or a test gets written, screen by screen or question by question.',
          // Standard field height, as tall as Leads search (Jose, 2026-10-27).
          action: SizedBox(
            width: 240,
            child: ListSearch(
              key: const Key('training-settings-search'),
              hint: 'Find a guide or test',
              onChanged: _search,
            ),
          ),
        ),
        Expanded(
          child: AsyncBody(
            loading: _loading,
            error: _error,
            onRetry: _load,
            child: ListView(
              key: const Key('training.settings-list'),
              padding: const EdgeInsets.fromLTRB(24, 4, 24, 28),
              children: [
                if (_actionError != null) ...[ErrorNote(_actionError!), const SizedBox(height: 12)],
                _SettingsSectionHeader(
                  label: 'Guides',
                  buttonLabel: 'New guide',
                  onAdd: _creating ? null : _newGuide,
                ),
                const SizedBox(height: 8),
                // With a query, an empty load is "nothing matched", not "none yet".
                if (_guides.isEmpty && query.isEmpty)
                  // Scope-filtered: "no guides YET" claims the organization has
                  // none, which this list cannot know. It says what showed up.
                  const _EmptySection('No guides to show — "New guide" starts one.')
                else if (guides.isEmpty)
                  Text('No guides match "$query".', style: bodyText)
                else
                  for (final guide in guides)
                    _SettingsRow(
                      key: ValueKey('training.settings-guide-${guide['id']}'),
                      title: guide['title'] as String? ?? '',
                      published: guide['status'] == 'published',
                      trailing: '${guide['screen_count'] ?? 0} screens',
                      onTap: () => _openGuide(guide['id'] as String),
                      id: guide['id'] as String,
                      noun: 'guide',
                      onDelete: () => _delete('/training/guides', guide['id'] as String),
                    ),
                const SizedBox(height: 24),
                _SettingsSectionHeader(
                  label: 'Tests',
                  buttonLabel: 'New test',
                  onAdd: _creating ? null : _newTest,
                ),
                const SizedBox(height: 8),
                // With a query, an empty load is "nothing matched", not "none yet".
                if (_tests.isEmpty && query.isEmpty)
                  const _EmptySection('No tests to show — "New test" starts one.')
                else if (tests.isEmpty)
                  Text('No tests match "$query".', style: bodyText)
                else
                  for (final test in tests)
                    _SettingsRow(
                      key: ValueKey('training.settings-test-${test['id']}'),
                      title: test['title'] as String? ?? '',
                      published: test['status'] == 'published',
                      trailing: '${test['question_count'] ?? 0} questions',
                      onTap: () => _openTest(test['id'] as String),
                      id: test['id'] as String,
                      noun: 'test',
                      onDelete: () => _delete('/training/tests', test['id'] as String),
                    ),
              ],
            ),
          ),
        ),
      ],
    );
  }
}

class _SettingsSectionHeader extends StatelessWidget {
  const _SettingsSectionHeader({required this.label, required this.buttonLabel, required this.onAdd});

  final String label;
  final String buttonLabel;
  final VoidCallback? onAdd;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        Expanded(child: Text(label, style: Theme.of(context).textTheme.titleMedium)),
        OutlinedButton.icon(
          onPressed: onAdd,
          icon: const Icon(Icons.add, size: 16),
          label: Text(buttonLabel),
        ),
      ],
    );
  }
}

/// One guide or test in the Settings lists, with its own delete.
///
/// ── The confirmation is INSIDE THE ROW, and that is load-bearing ──
///
/// A dialog naming the title cannot identify WHICH row it is about when the
/// titles are identical, and they routinely are: "New guide" creates
/// "Untitled guide" every time, so the screen that prompted this had three
/// rows with one name (Jose, 2026-09-23). Confirming in place is what makes
/// the answer unambiguous — the question is attached to the thing, not to its
/// name. It is also the house shape for a destructive confirm, the same one
/// scheduling uses for deleting a service area.
///
/// The sentence does not promise the row is empty. A guide can be published,
/// collect progress, and be set back to draft, so "Draft" on the subtitle is
/// not a guarantee that nothing is behind it — and `training_progress`,
/// `training_assignments` and `training_attempts` all cascade with the row
/// (migration `create_training_tables`). So it names what goes without
/// claiming a count it has not been given.
class _SettingsRow extends StatefulWidget {
  const _SettingsRow({
    super.key,
    required this.title,
    required this.published,
    required this.trailing,
    required this.onTap,
    required this.id,
    required this.noun,
    required this.onDelete,
  });

  final String title;
  final bool published;
  final String trailing;
  final VoidCallback onTap;

  /// Identifies this row's controls. NOT the title: "New guide" creates
  /// "Untitled guide" every time, so titles collide and keying on one would
  /// give three rows the same widget key.
  final String id;

  /// "guide" or "test" — the word the confirmation uses for this row.
  final String noun;
  final VoidCallback onDelete;

  @override
  State<_SettingsRow> createState() => _SettingsRowState();
}

class _SettingsRowState extends State<_SettingsRow> {
  bool _confirming = false;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final name = widget.title.isEmpty ? 'Untitled' : widget.title;

    if (_confirming) {
      return Card(
        child: ListTile(
          title: Text('Delete this ${widget.noun}?'),
          subtitle: Text(
            'Anything recorded against it — who it was assigned to, how far '
            'they got, what they scored — goes with it, and it cannot be undone.',
            style: theme.textTheme.bodySmall,
          ),
          trailing: Wrap(
            spacing: 4,
            children: [
              TextButton(
                key: Key('training.settings-cancel-delete-${widget.id}'),
                onPressed: () => setState(() => _confirming = false),
                child: const Text('Keep it'),
              ),
              TextButton(
                key: Key('training.settings-confirm-delete-${widget.id}'),
                onPressed: () {
                  setState(() => _confirming = false);
                  widget.onDelete();
                },
                style: TextButton.styleFrom(foregroundColor: theme.colorScheme.error),
                child: const Text('Delete'),
              ),
            ],
          ),
        ),
      );
    }

    return Card(
      child: ListTile(
        onTap: widget.onTap,
        title: Text(name),
        subtitle: Text(
          '${widget.published ? 'Published' : 'Draft'} · ${widget.trailing}',
          style: theme.textTheme.bodySmall,
        ),
        trailing: Wrap(
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            IconButton(
              key: Key('training.settings-delete-${widget.id}'),
              onPressed: () => setState(() => _confirming = true),
              icon: const Icon(Icons.delete_outline, size: 18),
              tooltip: 'Delete this ${widget.noun}',
            ),
            const Icon(Icons.chevron_right),
          ],
        ),
      ),
    );
  }
}

class _EmptySection extends StatelessWidget {
  const _EmptySection(this.text);

  final String text;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Text(text, style: theme.textTheme.bodyMedium?.copyWith(color: theme.colorScheme.onSurfaceVariant));
  }
}

List<Map<String, dynamic>> _rows(Object? raw) => raw is List
    ? raw.whereType<Map>().map((e) => Map<String, dynamic>.from(e)).toList()
    : const [];
