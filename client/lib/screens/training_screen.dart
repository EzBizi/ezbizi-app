import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/session.dart';
import '../widgets/async_body.dart';
import '../widgets/error_note.dart';
import '../widgets/tab_bar_like.dart';
import 'training/dashboard_list.dart';
import 'training/guide_builder.dart';
import 'training/learn_shelves.dart';
import 'training/test_builder.dart';

/// Training: the tab shell (task 12), Learn (task 12), Dashboard (task 14)
/// and Settings (task 15) — layouts 1, 6 and 8 of
/// `docs/previews/training-layouts.html`.
///
/// Three tabs, the same shape `InventoryScreen` already uses: a
/// `TabBarLike`, a hairline `Divider`, and an `Offstage` stack over the
/// bodies so a tab is built only once it has actually been visited.
///
/// ── Dashboard is gated on `training.monitor`, Settings on `training.manage` ──
///
/// `DashboardController` refuses every one of its routes to anybody without
/// `training.monitor` (never `.manage`, never `.view` — its own docblock),
/// and `TrainingGuidePolicy`/`TrainingTestPolicy` refuse every one of
/// theirs — read and write alike — to anybody without `training.manage`
/// (their own docblocks, "one permission, one controller"). A control that
/// is offered must work, so the chip itself follows the same rule HR's
/// shell already uses for Clock in–out (`me.permissions.contains
/// ('hr.view')`): [_canMonitor] and [_canManage] decide whether a tab is
/// even IN [_tabs] at all, rather than drawing it and letting the first
/// request 403. A viewer who lacks the permission never sees the chip.
class TrainingScreen extends StatefulWidget {
  const TrainingScreen({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<TrainingScreen> createState() => _TrainingScreenState();
}

class _TrainingScreenState extends State<TrainingScreen> {
  static const _learn = 0;

  int _tab = _learn;

  final Set<int> _shown = {_learn};

  /// `training.monitor` — the fourth of the module's four permissions
  /// (`DashboardController`'s own docblock), never `.manage` and never
  /// `.view`. Read off `session.me`, which every other screen in this
  /// module already assumes is populated by the time it builds (see
  /// `LearnShelves`'s own `session.me!`).
  bool get _canMonitor => widget.session.me?.can('training.monitor') ?? false;

  /// `training.manage` — the permission `TrainingGuidePolicy` and
  /// `TrainingTestPolicy` check on every verb, read included (their own
  /// docblocks). Settings is the authoring surface, not the Learn tab a
  /// learner reads from, so this is deliberately not `training.view`.
  bool get _canManage => widget.session.me?.can('training.manage') ?? false;

  /// Learn · Dashboard (only with `training.monitor`) · Settings (only with
  /// `training.manage`). One list rather than a fixed-length one with a
  /// slot conditionally swapped for a placeholder — the same shape HR's own
  /// `_tabs` uses for Clock in–out — so a viewer without a permission never
  /// has that chip at all, rather than one that opens onto a 403.
  List<(String, IconData, Widget Function())> get _tabs => [
    (
      'Learn',
      Icons.menu_book_outlined,
      () => LearnShelves(api: widget.api, session: widget.session),
    ),
    if (_canMonitor)
      (
        'Dashboard',
        Icons.query_stats_outlined,
        () => TrainingDashboard(api: widget.api, session: widget.session),
      ),
    if (_canManage)
      (
        'Settings',
        Icons.tune_outlined,
        () => TrainingSettingsTab(api: widget.api, session: widget.session),
      ),
  ];

  void _select(int tab) => setState(() {
    _tab = tab;
    _shown.add(tab);
  });

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Material(
          color: Theme.of(context).colorScheme.surface,
          child: TabBarLike(
            labels: [for (final tab in _tabs) (tab.$1, tab.$2)],
            selected: _tab,
            onSelected: _select,
          ),
        ),
        const Divider(height: 1),
        Expanded(
          child: Stack(
            fit: StackFit.expand,
            children: [
              for (var i = 0; i < _tabs.length; i++)
                Offstage(
                  offstage: i != _tab,
                  child: _shown.contains(i)
                      ? _tabs[i].$3()
                      : const SizedBox.shrink(),
                ),
            ],
          ),
        ),
      ],
    );
  }
}

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
/// Selecting a row PUSHES a real route — never a modal, never a pane — to
/// [GuideBuilder] or [TestBuilder], each wrapped in its own `Scaffold` the
/// same way `GuidePlayer._openTest` wraps `TestPlayer` (see that file's own
/// docblock on why the wrap is not optional: the pushed widget's own
/// `RadioListTile`s/`TextFormField`s need a `Material`/`Form` ancestor a bare
/// route does not supply). `training_builder_test.dart`'s own "through the
/// real route" test reaches both this way, not by constructing either
/// builder directly, guarding the exact class of bug task 13's postmortem
/// describes.
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

  void _openGuide(String id) {
    Navigator.of(context)
        .push(
          MaterialPageRoute<void>(
            builder: (routeContext) => Scaffold(
              body: GuideBuilder(
                api: widget.api,
                session: widget.session,
                guideId: id,
                onLeave: () => Navigator.of(routeContext).pop(),
              ),
            ),
          ),
        )
        .then((_) => _load());
  }

  void _openTest(String id) {
    Navigator.of(context)
        .push(
          MaterialPageRoute<void>(
            builder: (routeContext) => Scaffold(
              body: TestBuilder(
                api: widget.api,
                session: widget.session,
                testId: id,
                onLeave: () => Navigator.of(routeContext).pop(),
              ),
            ),
          ),
        )
        .then((_) => _load());
  }

  Future<void> _newGuide() async {
    setState(() {
      _creating = true;
      _createError = null;
    });

    final response = await widget.api.post('/training/guides', {'title': 'Untitled guide'});
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
