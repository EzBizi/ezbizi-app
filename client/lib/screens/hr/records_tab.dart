import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../core/api_client.dart';
import '../../core/contracts.dart';
import '../../core/hr.dart';
import '../../core/onboarding.dart';
import '../../core/session.dart';
import '../../core/timestamps.dart';
import '../../core/ui_strings.dart';
import '../../widgets/async_body.dart';
import '../../widgets/error_note.dart';
import '../../widgets/load_more.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/side_panel.dart';
import '../../widgets/tab_bar_like.dart';
import '../../widgets/tag.dart';
import '../../widgets/work_schedule_panel.dart';
import 'onboarding_form.dart';
import 'training_card.dart';

/// The people (HR plan, "Records") — everybody on the left, one person's whole
/// record on the right, and editable.
///
/// Jose, 2026-09-24: "i'd like the users to be on a left side bar and clicking
/// on them brings out all the information, which can be edited." His pick from
/// docs/previews/hr-records-person-layouts.html is desktop 2 — the record has
/// its own row of section tabs, one section edited at a time. The phone gets 5,
/// the same sections as chips, because a sidebar does not fit on a phone.
///
/// ── What changed about editing, and what did not ────────────────────
///
/// This tab used to change two things and refuse a third: role and whether the
/// account works, never their name, phone or date of birth, on the plan's
/// one-home rule. Jose reversed the refusal. The rule survives because HR
/// writes the SAME columns Your account does — see HrPeopleController::update —
/// so there is still one home with two doors, not a copy that drifts.
///
/// Three things stay read-only, and each says why where it is drawn: email
/// (they sign in with it), what onboarding collected (it is what they signed),
/// and paid time off (the organization's policy decides it).
///
/// ── Nothing opens until somebody is chosen ─────────────────────────
///
/// On a wide screen the right-hand side starts empty rather than opening the
/// first person. Opening a record is an audited read of somebody's personal
/// details (HrPeopleController::show), and a log saying the administrator read
/// Bea's date of birth because she happened to be first alphabetically would
/// be a false entry in a record that exists to be believed.
///
/// Deactivated people are on the list on purpose. §5's rule is that deactivate
/// is not delete and the record survives, so a list that hid them would be a
/// list of current staff pretending to be a list of staff. There is no delete,
/// and its absence is a decision: payroll, the audit trail (§10) and every job
/// somebody ever ran point at this row.
class RecordsTab extends StatefulWidget {
  const RecordsTab({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  /// The open record's own section strip, for a test to find by NAME. Found
  /// by position (`.last`) it would silently re-aim the day any other strip
  /// is drawn below it, and keep passing against the wrong one.
  static const sectionsKey = _RecordsTabState.sectionsKey;

  @override
  State<RecordsTab> createState() => _RecordsTabState();
}

enum _Showing { everyone, active, deactivated }

class _RecordsTabState extends State<RecordsTab> {
  /// The open record's section strip. Public through [RecordsTab.sectionsKey].
  static const sectionsKey = Key('hr.record.sections');

  bool _loading = true;
  String? _error;

  /// Which load owns the screen, so a slow first page cannot land on top of
  /// the search somebody typed after it.
  int _request = 0;

  bool _fetching = false;

  List<Map<String, dynamic>> _people = const [];
  int _total = 0;
  int _page = 1;
  int _lastPage = 1;
  String? _pageError;

  String _search = '';
  Timer? _searchDelay;
  _Showing _showing = _Showing.everyone;

  /// What a role may be changed TO, and which of those this person may hand
  /// out. The same answer the invite form uses, from the same endpoint: §4's
  /// escalation bound is one rule and it should have one source.
  AssignmentOptions _options = AssignmentOptions.none;

  /// Why nobody's role can be changed, when it cannot.
  String? _cannotChangeRole;

  /// Whose record is open.
  String? _selected;

  /// Which onboarding of theirs is being read, full page. Replaces the record
  /// rather than opening beside it: a page of collected answers is not a pane.
  String? _reading;

  DisplayFormats get _formats =>
      widget.session.me?.display ?? DisplayFormats.fallback;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _searchDelay?.cancel();
    super.dispose();
  }

  Future<void> _load({int page = 1}) async {
    if (page > 1 && _fetching) return;

    final request = ++_request;

    setState(() {
      // A spinner only when there is nothing to keep — see AsyncBody.
      _loading = page == 1 && _people.isEmpty;
      _fetching = true;
      _error = null;
      _pageError = null;
    });

    final answers = await Future.wait([
      widget.api.get('/hr/people', {
        'page': '$page',
        if (_search.isNotEmpty) 'search': _search,
      }),
      widget.api.get('/hr/people/scope-options'),
    ]);

    if (!mounted || request != _request) return;

    final people = answers[0];
    final options = answers[1];

    setState(() {
      _loading = false;
      _fetching = false;

      if (!people.ok) {
        if (page == 1) {
          _error = people.reason;
        } else {
          _pageError = people.reason;
        }
        return;
      }

      final rows = List<Map<String, dynamic>>.from(people.data as List);
      _people = page == 1 ? rows : [..._people, ...rows];

      final meta = people.body['meta'] as Map<String, dynamic>?;
      _total = meta?['total'] as int? ?? _people.length;
      _lastPage = meta?['last_page'] as int? ?? 1;
      _page = page;

      _options = options.ok && options.data is Map
          ? AssignmentOptions.fromJson(
              Map<String, dynamic>.from(options.data as Map),
            )
          : AssignmentOptions.none;

      // The list is still worth reading without the roles; only the one control
      // that needs them goes quiet, and it says why beside itself.
      _cannotChangeRole = _whyNot(options);
    });
  }

  String? _whyNot(ApiResponse options) {
    // Somebody who may edit people but may not invite them. The one endpoint
    // that says which roles are theirs to hand out is the invite form's, so
    // there is no list to draw and the control goes quiet rather than opening
    // an empty picker.
    if (options.forbidden) {
      return 'Changing somebody’s role needs the list of roles you may hand '
          'out, and that list comes with permission to invite. Your role does '
          'not include it, so roles are read-only here.';
    }

    if (!options.ok) {
      return 'Roles could not be loaded, so nobody’s role can be changed here '
          'right now: ${options.reason}';
    }

    if (!_options.roles.any((role) => role.assignable)) {
      return 'There is no role you may hand out. A role can only be given by '
          'somebody who holds everything in it themselves.';
    }

    return null;
  }

  void _typed(String value) {
    _searchDelay?.cancel();
    _searchDelay = Timer(const Duration(milliseconds: 300), () {
      _search = value.trim();
      _load();
    });
  }

  List<Map<String, dynamic>> get _shown => switch (_showing) {
    _Showing.everyone => _people,
    _Showing.active => [
      for (final p in _people)
        if (p['is_active'] != false) p,
    ],
    _Showing.deactivated => [
      for (final p in _people)
        if (p['is_active'] == false) p,
    ],
  };

  Map<String, dynamic>? get _selectedPerson {
    for (final person in _people) {
      if (person['id']?.toString() == _selected) return person;
    }
    return null;
  }

  @override
  Widget build(BuildContext context) {
    // The list and record stay built UNDER the onboarding page rather than
    // being swapped out for it. Swapped out, the record was rebuilt on the way
    // back: back on its first section instead of Onboarding, and a second
    // audited read of somebody's personal details that nobody asked for. The
    // Stack is there even when nothing is being read, so the tree keeps its
    // shape and Flutter keeps the record's state.
    return Stack(
      fit: StackFit.expand,
      children: [
        Offstage(offstage: _reading != null, child: _listAndRecord(context)),
        if (_reading case final id?)
          OnboardingForm(
            api: widget.api,
            onboardingId: id,
            // The onboarding carries the vocabulary its own questions are
            // written in — see Onboarding.vocabulary — so there is no catalogue
            // to fetch here.
            vocabulary: ContractVocabulary.none,
            formats: _formats,
            backLabel: 'Back to ${_selectedPerson?['name'] ?? 'their record'}',
            onBack: () => setState(() => _reading = null),
          ),
      ],
    );
  }

  Widget _listAndRecord(BuildContext context) {
    return LayoutBuilder(
      // The PANE's width, not the window's: this sits inside the HR shell, and a
      // window measurement would put a sidebar into a column too narrow for it.
      builder: (context, constraints) {
        if (constraints.maxWidth >= 760) {
          return Row(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              SizedBox(width: 300, child: _sidebar(context)),
              const VerticalDivider(width: 1),
              Expanded(child: _record(context, narrow: false)),
            ],
          );
        }

        return _selected == null
            ? _sidebar(context)
            : _record(context, narrow: true);
      },
    );
  }

  // ── the list ─────────────────────────────────────────────────────

  Widget _sidebar(BuildContext context) {
    final shown = _shown;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(12, 12, 12, 8),
          child: TextField(
            decoration: const InputDecoration(
              hintText: 'Search people',
              prefixIcon: Icon(Icons.search),
              isDense: true,
              border: OutlineInputBorder(),
            ),
            onChanged: _typed,
          ),
        ),
        Padding(
          padding: const EdgeInsets.fromLTRB(12, 0, 12, 8),
          child: Wrap(
            spacing: 6,
            children: [
              for (final (value, label) in [
                (_Showing.everyone, 'Everyone'),
                (_Showing.active, 'Active'),
                (_Showing.deactivated, 'Deactivated'),
              ])
                ChoiceChip(
                  label: Text(label),
                  selected: _showing == value,
                  visualDensity: VisualDensity.compact,
                  onSelected: (_) => setState(() => _showing = value),
                ),
            ],
          ),
        ),
        const Divider(height: 1),
        Expanded(
          child: AsyncBody(
            loading: _loading,
            error: _error,
            onRetry: _load,
            child: shown.isEmpty
                ? Center(
                    child: Padding(
                      padding: const EdgeInsets.all(24),
                      child: Text(
                        _people.isEmpty && _search.isEmpty
                            ? 'Nobody here yet. Invite somebody on the '
                                  'Invitations tab and they appear the moment '
                                  'they accept.'
                            : 'Nobody matches.',
                        textAlign: TextAlign.center,
                      ),
                    ),
                  )
                : ListView.builder(
                    itemCount: shown.length + (_page < _lastPage ? 1 : 0),
                    itemBuilder: (context, i) => i == shown.length
                        ? Padding(
                            padding: const EdgeInsets.all(12),
                            child: LoadMore(
                              shown: _people.length,
                              total: _total,
                              busy: _fetching,
                              error: _pageError,
                              onMore: () => _load(page: _page + 1),
                            ),
                          )
                        : _row(context, shown[i]),
                  ),
          ),
        ),
      ],
    );
  }

  Widget _row(BuildContext context, Map<String, dynamic> person) {
    final theme = Theme.of(context);
    final id = person['id']?.toString();
    final roleName = (person['role'] as Map<String, dynamic>?)?['name'];
    final active = person['is_active'] != false;

    return Material(
      color: id == _selected
          ? theme.colorScheme.primaryContainer.withValues(alpha: 0.45)
          : Colors.transparent,
      child: InkWell(
        onTap: () => setState(() => _selected = id),
        child: Padding(
          padding: const EdgeInsets.fromLTRB(12, 10, 12, 10),
          child: Row(
            children: [
              _Initials(person['name'] as String? ?? ''),
              const SizedBox(width: 10),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      person['name'] as String? ?? '',
                      overflow: TextOverflow.ellipsis,
                      style: theme.textTheme.titleSmall?.copyWith(
                        color: active ? null : theme.colorScheme.outline,
                      ),
                    ),
                    Text(
                      [
                        person['email'] as String? ?? '',
                        _where(person),
                      ].where((part) => part.isNotEmpty).join(' · '),
                      overflow: TextOverflow.ellipsis,
                      style: theme.textTheme.bodySmall,
                    ),
                    const SizedBox(height: 4),
                    // Said on the row rather than only inside the record. "Who
                    // has no role" is the question this whole module exists to
                    // stop being unanswerable, so it is not something to open.
                    Wrap(
                      spacing: 4,
                      runSpacing: 4,
                      children: [
                        if (roleName is String)
                          Tag(roleName)
                        else
                          const Tag('No role', warn: true),
                        if (!active) const Tag('Deactivated', warn: true),
                      ],
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  // ── the record ───────────────────────────────────────────────────

  Widget _record(BuildContext context, {required bool narrow}) {
    final id = _selected;

    if (id == null) {
      final theme = Theme.of(context);

      return Center(
        child: Padding(
          padding: const EdgeInsets.all(32),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(Icons.person_search_outlined,
                  size: 40, color: theme.colorScheme.outline),
              const SizedBox(height: 12),
              Text(
                'Choose somebody on the left to see their whole record.',
                style: theme.textTheme.titleMedium,
                textAlign: TextAlign.center,
              ),
              const SizedBox(height: 6),
              Text(
                'Opening a record is logged, because it shows their personal '
                'details.',
                style: theme.textTheme.bodySmall,
                textAlign: TextAlign.center,
              ),
            ],
          ),
        ),
      );
    }

    return _PersonRecord(
      key: ValueKey(id),
      api: widget.api,
      session: widget.session,
      personId: id,
      options: _options,
      cannotChangeRole: _cannotChangeRole,
      formats: _formats,
      onBack: narrow ? () => setState(() => _selected = null) : null,
      onChanged: () => _load(),
      onOpenOnboarding: (onboardingId) =>
          setState(() => _reading = onboardingId),
    );
  }

  /// Every place they hold an assignment. A list rather than one value,
  /// because §4 allows several, and the server has already dropped the ones the
  /// reader may not see.
  static String _where(Map<String, dynamic> person) =>
      (person['assignments'] as List<dynamic>? ?? const [])
          .whereType<Map<String, dynamic>>()
          .map((assignment) => assignment['name'] as String? ?? '')
          .where((name) => name.isNotEmpty)
          .join(' · ');
}

class _Initials extends StatelessWidget {
  const _Initials(this.name, {this.size = 34});

  final String name;
  final double size;

  @override
  Widget build(BuildContext context) {
    final letters = name
        .split(RegExp(r'\s+'))
        .where((part) => part.isNotEmpty)
        .take(2)
        .map((part) => part[0].toUpperCase())
        .join();

    return CircleAvatar(
      radius: size / 2,
      child: Text(letters, style: TextStyle(fontSize: size * 0.36)),
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// One person's record
// ─────────────────────────────────────────────────────────────────────────────

/// The sections, in the order the record's tab strip draws them.
const _sections = [
  ('personal', 'Personal', Icons.badge_outlined),
  ('employment', 'Employment', Icons.work_outline),
  ('work', 'Work', Icons.schedule_outlined),
  ('onboarding', 'Onboarding', Icons.how_to_reg_outlined),
  ('timeoff', 'Time off', Icons.beach_access_outlined),
  ('training', 'Training', Icons.school_outlined),
  ('access', 'Access', Icons.lock_reset_outlined),
];

class _PersonRecord extends StatefulWidget {
  const _PersonRecord({
    super.key,
    required this.api,
    required this.session,
    required this.personId,
    required this.options,
    required this.cannotChangeRole,
    required this.formats,
    required this.onChanged,
    required this.onOpenOnboarding,
    this.onBack,
  });

  final ApiClient api;
  final Session session;
  final String personId;
  final AssignmentOptions options;
  final String? cannotChangeRole;
  final DisplayFormats formats;
  final VoidCallback onChanged;
  final ValueChanged<String> onOpenOnboarding;

  /// Only on a phone, where the record replaced the list.
  final VoidCallback? onBack;

  @override
  State<_PersonRecord> createState() => _PersonRecordState();
}

class _PersonRecordState extends State<_PersonRecord> {
  bool _loading = true;
  String? _error;
  Map<String, dynamic> _person = const {};
  String _section = 'personal';

  /// Bumped on every reload, so a section's draft resets to what was saved
  /// rather than keeping the text somebody typed before the save.
  int _version = 0;

  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _reload();
  }

  Future<void> _reload() async {
    final response = await widget.api.get('/hr/people/${widget.personId}');

    if (!mounted) return;

    setState(() {
      _loading = false;
      if (!response.ok) {
        _error = response.reason;
        return;
      }
      _error = null;
      _person = Map<String, dynamic>.from(response.data as Map);
      _version++;
    });
  }

  /// What UserPolicy::update asks for. Asked here rather than left to the
  /// server so nobody fills in a form the server was always going to refuse.
  String? get _noEdit =>
      widget.session.me?.refusal('hr.edit', 'change somebody’s record');

  /// Sends only what changed. True when it saved.
  Future<bool> _save(Map<String, dynamic> change) async {
    if (change.isEmpty) return true;

    if (change['is_active'] == false && !await _confirmDeactivate()) {
      return false;
    }

    setState(() => _busy = true);
    final response = await widget.api.patch(
      '/hr/people/${widget.personId}',
      change,
    );
    if (!mounted) return false;
    setState(() => _busy = false);

    // The rules this endpoint enforces explain themselves — switching off your
    // own account, a role that reaches further than yours, leaving the
    // organization with no administrator — so the sentence is shown, and the
    // draft is kept rather than reloaded over.
    if (!response.ok) {
      _snack(response.reason, isError: true);
      return false;
    }

    _snack('Saved.');
    await _reload();
    widget.onChanged();
    return true;
  }

  Future<bool> _confirmDeactivate() async {
    final confirmed = await showSidePanel<bool>(
      context,
      builder: (context) => SidePanel(
        title: 'Deactivate ${_person['name']}?',
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            style: FilledButton.styleFrom(
              backgroundColor: Theme.of(context).colorScheme.error,
            ),
            onPressed: () => Navigator.of(context).pop(true),
            child: const Text('Deactivate'),
          ),
        ],
        child: const Text(
          'They cannot sign in, and their assignments stop granting anything. '
          'The record stays exactly where it is — every job they ran and every '
          'audit entry naming them still has somebody to point at — and '
          'reactivating puts them back.',
        ),
      ),
    );

    return confirmed == true && mounted;
  }

  void _snack(String message, {bool isError = false}) {
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(message),
        backgroundColor: isError ? Theme.of(context).colorScheme.error : null,
        behavior: SnackBarBehavior.floating,
        width: 420,
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return AsyncBody(
      loading: _loading,
      error: _error,
      onRetry: _reload,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          _header(context),
          Material(
            color: Theme.of(context).colorScheme.surface,
            child: TabBarLike(
              // Keyed so a test can name THIS strip. The HR screen draws its
              // own module chips above it, and "Onboarding" is on both.
              key: _RecordsTabState.sectionsKey,
              labels: [for (final s in _sections) (s.$2, s.$3)],
              selected: _sections.indexWhere((s) => s.$1 == _section),
              onSelected: (i) => setState(() => _section = _sections[i].$1),
            ),
          ),
          const Divider(height: 1),
          if (_busy) const LinearProgressIndicator(minHeight: 2),
          Expanded(
            child: SingleChildScrollView(
              padding: const EdgeInsets.fromLTRB(24, 20, 24, 32),
              child: Align(
                alignment: Alignment.topLeft,
                child: ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: 760),
                  child: _body(context),
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _header(BuildContext context) {
    final theme = Theme.of(context);
    final roleName = (_person['role'] as Map<String, dynamic>?)?['name'];
    final active = _person['is_active'] != false;
    final where = _RecordsTabState._where(_person);

    return Padding(
      padding: EdgeInsets.fromLTRB(widget.onBack == null ? 24 : 8, 16, 24, 12),
      child: Row(
        children: [
          if (widget.onBack != null)
            IconButton(
              tooltip: 'All people',
              icon: const Icon(Icons.arrow_back),
              onPressed: widget.onBack,
            ),
          _Initials(_person['name'] as String? ?? '', size: 48),
          const SizedBox(width: 14),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  _person['name'] as String? ?? '',
                  style: theme.textTheme.titleLarge,
                  overflow: TextOverflow.ellipsis,
                ),
                Text(
                  [
                    if (roleName is String) roleName else 'No role',
                    if (where.isNotEmpty) where,
                  ].join(' · '),
                  style: theme.textTheme.bodySmall,
                  overflow: TextOverflow.ellipsis,
                ),
              ],
            ),
          ),
          Tag(active ? 'Active' : 'Deactivated', warn: !active),
        ],
      ),
    );
  }

  Widget _body(BuildContext context) => switch (_section) {
    'personal' => _PersonalSection(
      key: ValueKey('personal-$_version'),
      person: _person,
      formats: widget.formats,
      noEdit: _noEdit,
      onSave: _save,
    ),
    'employment' => _EmploymentSection(
      key: ValueKey('employment-$_version'),
      person: _person,
      options: widget.options,
      cannotChangeRole: widget.cannotChangeRole,
      formats: widget.formats,
      noEdit: _noEdit,
      onSave: _save,
    ),
    'work' => _work(context),
    'onboarding' => _OnboardingSection(
      api: widget.api,
      person: _person,
      formats: widget.formats,
      onOpen: widget.onOpenOnboarding,
    ),
    'timeoff' => _timeOff(context),
    'training' => TrainingCard(
      api: widget.api,
      userId: widget.personId,
      formats: widget.formats,
    ),
    _ => _access(context),
  };

  // ── Work: saved as it is changed ─────────────────────────────────

  Widget _work(BuildContext context) {
    final theme = Theme.of(context);
    final noEdit = _noEdit;
    final schedule = _person['work_schedule'];
    final days = schedule is Map && schedule['days'] is Map
        ? Map<String, dynamic>.from(schedule['days'] as Map)
        : null;
    final exempt = _person['lock_screen_exempt'] == true;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        if (noEdit != null) ...[ErrorNote(noEdit), const SizedBox(height: 12)],
        _Field(
          label: UiStrings.t('time_clock.work_schedule'),
          child: Text(
            _describeSchedule(days),
            style: theme.textTheme.bodyMedium,
          ),
        ),
        const SizedBox(height: 8),
        OutlinedButton.icon(
          icon: const Icon(Icons.edit_calendar_outlined),
          label: const Text('Change their schedule'),
          onPressed: noEdit == null ? () => _editSchedule(days) : null,
        ),
        const SizedBox(height: 20),
        SwitchListTile(
          contentPadding: EdgeInsets.zero,
          title: Text(
            exempt
                ? UiStrings.t('time_clock.exempt_on')
                : UiStrings.t('time_clock.exempt_off'),
          ),
          subtitle: const Text(
            'When the lock screen applies, their session locks outside their '
            'schedule. Saved as soon as it is switched.',
          ),
          value: !exempt,
          onChanged: noEdit == null
              ? (applies) => _save({'lock_screen_exempt': !applies})
              : null,
        ),
      ],
    );
  }

  static String _describeSchedule(Map<String, dynamic>? days) {
    if (days == null) return 'No schedule set.';

    const names = {
      'monday': 'Monday',
      'tuesday': 'Tuesday',
      'wednesday': 'Wednesday',
      'thursday': 'Thursday',
      'friday': 'Friday',
      'saturday': 'Saturday',
      'sunday': 'Sunday',
    };

    final lines = [
      for (final entry in names.entries)
        if (days[entry.key] is Map)
          '${entry.value}  ${(days[entry.key] as Map)['start']} – '
              '${(days[entry.key] as Map)['end']}',
    ];

    return lines.isEmpty ? 'Every day off.' : lines.join('\n');
  }

  Future<void> _editSchedule(Map<String, dynamic>? days) async {
    final saved = await showWorkSchedulePanel(
      context,
      title:
          '${UiStrings.t('time_clock.work_schedule')} · ${_person['name'] ?? ''}',
      days: days,
      formats: widget.formats,
    );

    if (saved == null || !mounted) return;

    final response = await widget.api.patch(
      '/hr/people/${widget.personId}/schedule',
      {'days': saved},
    );
    if (!mounted) return;
    if (!response.ok) {
      _snack(response.reason, isError: true);
      return;
    }
    _snack('Saved.');
    await _reload();
  }

  // ── Time off: read only, from the organization's policy ──────────

  Widget _timeOff(BuildContext context) {
    final theme = Theme.of(context);
    final pto = _person['pto'];

    if (pto is! Map) {
      return const Text(
        'This organization has not set up paid time off. It is set under '
        'Work Hours › Settings.',
      );
    }

    final allowance = pto['allowance'] as int? ?? 0;
    final period = switch (pto['allowance_period']) {
      'week' => 'a week',
      'month' => 'a month',
      _ => 'a year',
    };
    final from = DateTime.tryParse(pto['eligible_from']?.toString() ?? '');

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          '${allowance == 1 ? '1 day' : '$allowance days'} $period',
          style: theme.textTheme.headlineMedium,
        ),
        const SizedBox(height: 8),
        Text(
          pto['eligible'] == true
              ? 'They can use it now.'
              : from == null
              ? 'When they can use it depends on their start date, which is '
                    'not recorded. Add it under Employment.'
              : 'They can use it from ${widget.formats.formatDate(from)}.',
          style: theme.textTheme.bodyMedium,
        ),
        const SizedBox(height: 12),
        Text(
          'Read only: the allowance is the organization’s policy, set under '
          'Work Hours › Settings.',
          style: theme.textTheme.bodySmall?.copyWith(
            color: theme.colorScheme.outline,
          ),
        ),
      ],
    );
  }

  // ── Access: sign-in recovery ─────────────────────────────────────

  Widget _access(BuildContext context) {
    // Its own key, deliberately not hr.edit — see UserPolicy::resetSecurity.
    // Clearing a colleague's second factor is a bigger authority than keeping
    // their record, and the server refuses it under hr.edit alone.
    final noReset = widget.session.me?.refusal(
      'hr.security.reset',
      'reset somebody’s sign-in security',
    );
    // Never on yourself, for the reason the server gives: this endpoint is what
    // a stolen session would reach for. Your own is changed from Your account.
    final self = _person['id'] == widget.session.me?.id;
    final refused = self
        ? 'This is for somebody else. Change your own from Your account.'
        : noReset;

    Widget action(String label, IconData icon, VoidCallback run, String note) =>
        Padding(
          padding: const EdgeInsets.only(bottom: 20),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              OutlinedButton.icon(
                icon: Icon(icon),
                label: Text(label),
                onPressed: refused == null && !_busy ? run : null,
              ),
              const SizedBox(height: 6),
              Text(
                refused ?? note,
                style: Theme.of(context).textTheme.bodySmall,
              ),
            ],
          ),
        );

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        action(
          'Reset their authenticator',
          Icons.phonelink_lock_outlined,
          _resetTotp,
          'For a lost or stolen phone. They set up a new one next time they '
              'sign in.',
        ),
        action(
          'Password reset link',
          Icons.link,
          _resetLink,
          'A single-use link you hand over yourself — for somebody locked out '
              'of their mailbox too.',
        ),
      ],
    );
  }

  /// The lost-phone path. Confirmed first, because it signs them out of
  /// everything — a person mid-call on the desktop loses that session too.
  Future<void> _resetTotp() async {
    final name = _person['name'] as String? ?? 'this person';

    final confirmed = await showSidePanel<bool>(
      context,
      builder: (context) => SidePanel(
        title: 'Reset $name’s authenticator?',
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            style: FilledButton.styleFrom(
              backgroundColor: Theme.of(context).colorScheme.error,
            ),
            onPressed: () => Navigator.of(context).pop(true),
            child: const Text('Reset it'),
          ),
        ],
        child: const Text(
          'Their authenticator app stops working for EzBizi and they are '
          'signed out on every device. The next time they sign in they set '
          'up a new one themselves — you are never shown it, and neither is '
          'anybody else. Do this when the phone is lost or you think it is '
          'in the wrong hands.',
        ),
      ),
    );

    if (confirmed != true || !mounted) return;

    setState(() => _busy = true);
    final response = await widget.api.post(
      '/hr/people/${widget.personId}/totp/reset',
      const {},
    );
    if (!mounted) return;
    setState(() => _busy = false);

    if (!response.ok) {
      _snack(response.reason, isError: true);
      return;
    }

    _snack((response.body['data'] as Map?)?['note']?.toString() ?? 'Done.');
  }

  /// A single-use link the administrator hands over themselves. Returned on
  /// screen rather than emailed, because somebody locked out of their mailbox
  /// is the case this exists for.
  Future<void> _resetLink() async {
    setState(() => _busy = true);
    final response = await widget.api.post(
      '/hr/people/${widget.personId}/reset-link',
      const {},
    );
    if (!mounted) return;
    setState(() => _busy = false);

    if (!response.ok) {
      _snack(response.reason, isError: true);
      return;
    }

    final data = (response.body['data'] as Map?) ?? const {};
    final url = data['url']?.toString() ?? '';

    await showSidePanel<void>(
      context,
      builder: (context) => SidePanel(
        title: 'Password reset link for ${_person['name'] ?? 'this person'}',
        actions: [
          TextButton(
            onPressed: () async {
              await Clipboard.setData(ClipboardData(text: url));
              if (context.mounted) Navigator.of(context).pop();
            },
            child: const Text('Copy and close'),
          ),
        ],
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            SelectableText(url),
            const SizedBox(height: 12),
            Text(
              data['note']?.toString() ??
                  'Single use, valid one hour. Hand it over directly.',
              style: Theme.of(context).textTheme.bodySmall,
            ),
          ],
        ),
      ),
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// The two sections that are forms
// ─────────────────────────────────────────────────────────────────────────────

typedef _Saver = Future<bool> Function(Map<String, dynamic> change);

/// A labelled read-only value, drawn like a field so a section reads as one
/// form whether a line is editable or not.
class _Field extends StatelessWidget {
  const _Field({required this.label, required this.child, this.why});

  final String label;
  final Widget child;
  final String? why;

  @override
  Widget build(BuildContext context) => InputDecorator(
    decoration: InputDecoration(
      labelText: label,
      helperText: why,
      helperMaxLines: 3,
      border: const OutlineInputBorder(),
      enabled: false,
    ),
    child: child,
  );
}

/// Save and Discard, shown only when something changed.
class _SaveBar extends StatelessWidget {
  const _SaveBar({
    required this.label,
    required this.dirty,
    required this.onSave,
    required this.onDiscard,
  });

  final String label;
  final bool dirty;
  final VoidCallback onSave;
  final VoidCallback onDiscard;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.only(top: 20),
    // A Wrap, not a Row: at phone width with large text the two buttons do not
    // fit side by side, and a Row would paint them past the edge.
    child: Wrap(
      spacing: 8,
      runSpacing: 8,
      children: [
        FilledButton(onPressed: dirty ? onSave : null, child: Text(label)),
        TextButton(
          onPressed: dirty ? onDiscard : null,
          child: const Text('Discard'),
        ),
      ],
    ),
  );
}

/// A date shown in the organization's format, chosen with a picker, sent as
/// `yyyy-MM-dd`.
class _DateField extends StatelessWidget {
  const _DateField({
    required this.label,
    required this.value,
    required this.formats,
    required this.onChanged,
    this.helper,
    this.lastDate,
    this.empty = 'Not recorded',
  });

  final String label;
  final String? value;
  final DisplayFormats formats;
  final ValueChanged<String>? onChanged;
  final String? helper;
  final DateTime? lastDate;
  final String empty;

  @override
  Widget build(BuildContext context) {
    final at = DateTime.tryParse(value ?? '');

    return InkWell(
      onTap: onChanged == null
          ? null
          : () async {
              final picked = await showDatePicker(
                context: context,
                initialDate: at ?? DateTime.now(),
                firstDate: DateTime(1900),
                lastDate: lastDate ?? DateTime(DateTime.now().year + 2),
              );
              if (picked == null) return;
              onChanged!(
                '${picked.year.toString().padLeft(4, '0')}-'
                '${picked.month.toString().padLeft(2, '0')}-'
                '${picked.day.toString().padLeft(2, '0')}',
              );
            },
      child: InputDecorator(
        decoration: InputDecoration(
          labelText: label,
          helperText: helper,
          helperMaxLines: 3,
          border: const OutlineInputBorder(),
          suffixIcon: const Icon(Icons.calendar_today_outlined, size: 18),
          enabled: onChanged != null,
        ),
        child: Text(at == null ? empty : formats.formatDate(at)),
      ),
    );
  }
}

class _PersonalSection extends StatefulWidget {
  const _PersonalSection({
    super.key,
    required this.person,
    required this.formats,
    required this.noEdit,
    required this.onSave,
  });

  final Map<String, dynamic> person;
  final DisplayFormats formats;
  final String? noEdit;
  final _Saver onSave;

  @override
  State<_PersonalSection> createState() => _PersonalSectionState();
}

class _PersonalSectionState extends State<_PersonalSection> {
  static const _texts = [
    'first_name',
    'middle_name',
    'last_name',
    'phone',
    'address',
    'gender',
  ];

  late final Map<String, TextEditingController> _fields = {
    for (final key in _texts)
      key: TextEditingController(text: widget.person[key]?.toString() ?? '')
        ..addListener(() => setState(() {})),
  };

  late String? _born = widget.person['date_of_birth']?.toString();

  @override
  void dispose() {
    for (final controller in _fields.values) {
      controller.dispose();
    }
    super.dispose();
  }

  Map<String, dynamic> get _change {
    final change = <String, dynamic>{};

    for (final key in _texts) {
      final now = _fields[key]!.text.trim();
      final was = widget.person[key]?.toString() ?? '';
      // An emptied optional field is sent as null, which is what the column
      // holds for "not given"; an empty string would be a different answer.
      if (now != was) change[key] = now.isEmpty ? null : now;
    }

    if (_born != widget.person['date_of_birth']?.toString()) {
      change['date_of_birth'] = _born;
    }

    return change;
  }

  void _discard() {
    for (final key in _texts) {
      _fields[key]!.text = widget.person[key]?.toString() ?? '';
    }
    setState(() => _born = widget.person['date_of_birth']?.toString());
  }

  @override
  Widget build(BuildContext context) {
    final editable = widget.noEdit == null;

    Widget text(String key, String label, {int lines = 1}) => TextField(
      controller: _fields[key],
      enabled: editable,
      maxLines: lines,
      decoration: InputDecoration(
        labelText: label,
        border: const OutlineInputBorder(),
      ),
    );

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (widget.noEdit != null) ...[
          ErrorNote(widget.noEdit!),
          const SizedBox(height: 16),
        ],
        _Pair(
          text('first_name', 'First name'),
          text('middle_name', 'Middle name'),
        ),
        const SizedBox(height: 14),
        _Pair(text('last_name', 'Last name'), text('phone', 'Phone')),
        const SizedBox(height: 14),
        text('address', 'Home address', lines: 2),
        const SizedBox(height: 14),
        _Pair(
          _DateField(
            label: 'Date of birth',
            value: _born,
            formats: widget.formats,
            lastDate: DateTime.now().subtract(const Duration(days: 1)),
            empty: 'Not given',
            onChanged: editable ? (v) => setState(() => _born = v) : null,
          ),
          text('gender', 'Gender'),
        ),
        const SizedBox(height: 14),
        _Field(
          label: 'Email',
          why:
              'They sign in with this, so it is theirs to change under Your '
              'account — changing it from here could lock them out.',
          child: Text(widget.person['email']?.toString() ?? ''),
        ),
        if (editable)
          _SaveBar(
            label: 'Save personal details',
            dirty: _change.isNotEmpty,
            onSave: () => widget.onSave(_change),
            onDiscard: _discard,
          ),
      ],
    );
  }
}

class _EmploymentSection extends StatefulWidget {
  const _EmploymentSection({
    super.key,
    required this.person,
    required this.options,
    required this.cannotChangeRole,
    required this.formats,
    required this.noEdit,
    required this.onSave,
  });

  final Map<String, dynamic> person;
  final AssignmentOptions options;
  final String? cannotChangeRole;
  final DisplayFormats formats;
  final String? noEdit;
  final _Saver onSave;

  @override
  State<_EmploymentSection> createState() => _EmploymentSectionState();
}

class _EmploymentSectionState extends State<_EmploymentSection> {
  String? get _savedRole =>
      (widget.person['role'] as Map<String, dynamic>?)?['id']?.toString();
  bool get _savedActive => widget.person['is_active'] != false;
  String? get _savedStart => widget.person['started_on']?.toString();

  late String? _role = _savedRole;
  late bool _active = _savedActive;
  late String? _start = _savedStart;

  Map<String, dynamic> get _change => {
    if (_role != _savedRole && _role != null) 'role_id': _role,
    if (_active != _savedActive) 'is_active': _active,
    if (_start != _savedStart && _start != null) 'started_on': _start,
  };

  @override
  Widget build(BuildContext context) {
    final editable = widget.noEdit == null;
    final roleRefused = widget.noEdit ?? widget.cannotChangeRole;
    final joined = DateTime.tryParse(
      widget.person['joined_at']?.toString() ?? '',
    );

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (widget.noEdit != null) ...[
          ErrorNote(widget.noEdit!),
          const SizedBox(height: 16),
        ],
        SearchPickerField<String>(
          label: 'Role',
          value: _role,
          // Shown and refused rather than hidden. §4: nobody hands out what
          // they do not hold, and a role missing from the list leaves somebody
          // unable to tell that from "we have no such role".
          options: [
            for (final role in widget.options.roles)
              SearchOption(
                value: role.id,
                label: role.name,
                enabled: role.assignable,
                detail: role.assignable
                    ? null
                    : 'Your own role does not hold everything in this one, so '
                          'you cannot give it out.',
              ),
          ],
          // Said beside the control it is about, not once at the top of the
          // screen where nobody connects it to a greyed field.
          helperText:
              roleRefused ??
              'A role is organization-wide: changing it changes what they '
                  'reach everywhere.',
          helperMaxLines: 4,
          onChanged: roleRefused == null
              ? (value) => setState(() => _role = value)
              : null,
        ),
        const SizedBox(height: 16),
        Text('Status', style: Theme.of(context).textTheme.labelLarge),
        const SizedBox(height: 6),
        SegmentedButton<bool>(
          segments: const [
            ButtonSegment(value: true, label: Text('Active')),
            ButtonSegment(value: false, label: Text('Deactivated')),
          ],
          selected: {_active},
          onSelectionChanged: editable
              ? (value) => setState(() => _active = value.first)
              : null,
        ),
        const SizedBox(height: 16),
        _Pair(
          _DateField(
            label: 'Start date',
            value: _start,
            formats: widget.formats,
            helper: 'The day they started here. Paid time off counts from it.',
            onChanged: editable ? (v) => setState(() => _start = v) : null,
          ),
          _Field(
            label: 'Account created',
            why: 'When EzBizi got them, not when they started.',
            child: Text(
              joined == null ? '—' : widget.formats.formatDate(joined),
            ),
          ),
        ),
        const SizedBox(height: 14),
        _Field(
          label: 'Works at',
          why:
              'Set when they were invited. Moving somebody between places '
              'comes with onboarding.',
          child: Text(
            _RecordsTabState._where(widget.person).isEmpty
                ? '—'
                : _RecordsTabState._where(widget.person),
          ),
        ),
        if (editable)
          _SaveBar(
            label: 'Save employment',
            dirty: _change.isNotEmpty,
            onSave: () => widget.onSave(_change),
            onDiscard: () => setState(() {
              _role = _savedRole;
              _active = _savedActive;
              _start = _savedStart;
            }),
          ),
      ],
    );
  }
}

/// Two fields side by side when there is room, stacked when there is not.
class _Pair extends StatelessWidget {
  const _Pair(this.left, this.right);

  final Widget left;
  final Widget right;

  @override
  Widget build(BuildContext context) => LayoutBuilder(
    builder: (context, constraints) => constraints.maxWidth < 480
        ? Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [left, const SizedBox(height: 14), right],
          )
        : Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(child: left),
              const SizedBox(width: 14),
              Expanded(child: right),
            ],
          ),
  );
}

/// What onboarding collected: their onboardings, each opened full page.
///
/// Read only, and not re-drawn here. The answers are answers to one pinned
/// contract version's questions, masked where sensitive with an audited
/// reveal, and the form widget is what draws them — a second renderer would be
/// a second place for a sensitive value to be shown in the clear by accident.
class _OnboardingSection extends StatefulWidget {
  const _OnboardingSection({
    required this.api,
    required this.person,
    required this.formats,
    required this.onOpen,
  });

  final ApiClient api;
  final Map<String, dynamic> person;
  final DisplayFormats formats;
  final ValueChanged<String> onOpen;

  @override
  State<_OnboardingSection> createState() => _OnboardingSectionState();
}

class _OnboardingSectionState extends State<_OnboardingSection> {
  bool _loading = true;
  String? _error;
  List<OnboardingSummary> _theirs = const [];

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final id = widget.person['id']?.toString();
    final response = await widget.api.get('/hr/onboardings', {
      'user_id': '$id',
      'per_page': '50',
    });

    if (!mounted) return;

    setState(() {
      _loading = false;
      if (!response.ok) {
        _error = response.reason;
        return;
      }
      // Filtered here as well as on the server: the endpoint used to answer
      // with everything the reader may see, and an older record past the first
      // page was reported as never collected.
      _theirs = OnboardingSummary.listFrom(
        response.data,
      ).where((row) => row.userId == id).toList();
    });
  }

  @override
  Widget build(BuildContext context) {
    return AsyncBody(
      loading: _loading,
      error: _error,
      onRetry: _load,
      child: _theirs.isEmpty
          ? Text(
              'Nothing has been collected about ${widget.person['name']} yet. '
              'Onboarding is what asks the questions, and nobody has started '
              'one for them.',
            )
          : Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text(
                  'What they filled in and signed. Read only — it is the record '
                  'of what they agreed to.',
                  style: Theme.of(context).textTheme.bodySmall,
                ),
                const SizedBox(height: 8),
                for (final row in _theirs)
                  Card(
                    child: ListTile(
                      title: Text(row.contractName),
                      subtitle: Text(
                        [
                          if (row.version != null) 'version ${row.version}',
                          onboardingStatusLabel(row.status),
                          formatStamp(
                            row.completedAt ?? row.startedAt,
                            '',
                            formats: widget.formats,
                          ),
                        ].where((part) => part.isNotEmpty).join(' · '),
                      ),
                      trailing: const Icon(Icons.chevron_right),
                      onTap: () => widget.onOpen(row.id),
                    ),
                  ),
              ],
            ),
    );
  }
}
