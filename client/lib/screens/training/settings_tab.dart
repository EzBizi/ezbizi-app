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
  String? _createError;

  /// (type, id) of the builder open in place of the list — 'guide' or
  /// 'test'. Null when neither is open, which is when the list draws. The
  /// same shape `LearnShelves._selected` uses for its own two players.
  (String, String)? _open;

  Me get _me => widget.session.me!;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = _guides.isEmpty && _tests.isEmpty;
      _error = null;
    });

    final guides = await widget.api.get('/training/guides', {'per_page': '200'});
    if (!mounted) return;
    if (!guides.ok) {
      setState(() {
        _loading = false;
        _error = guides.message;
      });
      return;
    }

    final tests = await widget.api.get('/training/tests', {'per_page': '200'});
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
      _createError = null;
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
      setState(() => _createError = response.reason);
      return;
    }

    _openGuide((response.body['data'] as Map)['id'] as String);
  }

  Future<void> _newTest() async {
    setState(() {
      _creating = true;
      _createError = null;
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
      setState(() => _createError = response.reason);
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

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const PanelHeader(
          title: 'Settings',
          note: 'Where a guide or a test gets written, screen by screen or question by question.',
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
                if (_createError != null) ...[ErrorNote(_createError!), const SizedBox(height: 12)],
                _SettingsSectionHeader(
                  label: 'Guides',
                  buttonLabel: 'New guide',
                  onAdd: _creating ? null : _newGuide,
                ),
                const SizedBox(height: 8),
                if (_guides.isEmpty)
                  const _EmptySection('No guides yet — "New guide" starts one.')
                else
                  for (final guide in _guides)
                    _SettingsRow(
                      key: ValueKey('training.settings-guide-${guide['id']}'),
                      title: guide['title'] as String? ?? '',
                      published: guide['status'] == 'published',
                      trailing: '${guide['screen_count'] ?? 0} screens',
                      onTap: () => _openGuide(guide['id'] as String),
                    ),
                const SizedBox(height: 24),
                _SettingsSectionHeader(
                  label: 'Tests',
                  buttonLabel: 'New test',
                  onAdd: _creating ? null : _newTest,
                ),
                const SizedBox(height: 8),
                if (_tests.isEmpty)
                  const _EmptySection('No tests yet — "New test" starts one.')
                else
                  for (final test in _tests)
                    _SettingsRow(
                      key: ValueKey('training.settings-test-${test['id']}'),
                      title: test['title'] as String? ?? '',
                      published: test['status'] == 'published',
                      trailing: '${test['question_count'] ?? 0} questions',
                      onTap: () => _openTest(test['id'] as String),
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

class _SettingsRow extends StatelessWidget {
  const _SettingsRow({
    super.key,
    required this.title,
    required this.published,
    required this.trailing,
    required this.onTap,
  });

  final String title;
  final bool published;
  final String trailing;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Card(
      child: ListTile(
        onTap: onTap,
        title: Text(title.isEmpty ? 'Untitled' : title),
        subtitle: Text(
          '${published ? 'Published' : 'Draft'} · $trailing',
          style: theme.textTheme.bodySmall,
        ),
        trailing: const Icon(Icons.chevron_right),
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
