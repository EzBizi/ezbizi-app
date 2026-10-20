import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../core/api_client.dart';
import '../../core/contracts.dart';
import '../../core/hr.dart';
import '../../core/onboarding.dart';
import '../../core/phone_numbers.dart';
import '../../core/session.dart';
import '../../core/timestamps.dart';
import '../../core/ui_strings.dart';
import '../../widgets/address_fields.dart';
import '../../widgets/async_body.dart';
import '../../widgets/calendar_day_field.dart';
import '../../widgets/error_note.dart';
import '../../widgets/load_more.dart';
import '../../widgets/not_filled_in.dart';
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

  /// The role filter above the list.
  static const roleFilterKey = Key('hr.records.role');
  static const placeFilterKey = Key('hr.records.place');

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

  /// One role, or every role when null (Jose, 2026-10-01). Asked of the
  /// server, because the list arrives a page at a time.
  String? _roleId;

  /// One company with everything under it, or one franchise, or everywhere
  /// when null (Jose, 2026-10-01: "adding division by company too aside from
  /// role"): `company:<id>` or `franchise:<id>`. Asked of the server too.
  String? _place;

  /// What a role may be changed TO, and which of those this person may hand
  /// out. The same answer the invite form uses, from the same endpoint: §4's
  /// escalation bound is one rule and it should have one source.
  AssignmentOptions _options = AssignmentOptions.none;

  /// Why nobody's role can be changed, when it cannot.
  String? _cannotChangeRole;

  /// Whose record is open.
  String? _selected;

  /// The list's width, dragged by its edge (Jose, 2026-09-29: "I want to be
  /// able to resize the sidebar"), kept as a preference so it is where it
  /// was left after leaving the tab or closing the app.
  late double _listWidth = widget.session.me?.hrRecordsListWidth ?? 300;

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

  /// The companies and franchises this person can see, each named by its
  /// company ("A-1 Chimney › Ohio"), in that order.
  List<SearchOption<String>> get _places {
    final me = widget.session.me;
    if (me == null) return const [];
    final companies = {for (final c in me.companies) c.id: c};
    String pathOf(String? companyId) {
      final names = <String>[];
      final seen = <String>{};
      for (
        var c = companies[companyId];
        c != null && seen.add(c.id);
        c = companies[c.parentCompanyId]
      ) {
        names.insert(0, c.name);
      }
      return names.join(' › ');
    }

    final rows = [
      for (final c in me.companies)
        SearchOption(value: 'company:${c.id}', label: pathOf(c.id)),
      for (final f in me.franchises)
        SearchOption(
          value: 'franchise:${f.id}',
          label: [
            pathOf(f.companyId),
            f.name,
          ].where((part) => part.isNotEmpty).join(' › '),
        ),
    ]..sort((a, b) => a.label.toLowerCase().compareTo(b.label.toLowerCase()));
    return rows;
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
        'role_id': ?_roleId,
        if (_place?.split(':') case [final kind, final id]) '${kind}_id': id,
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
          // At least 240 for the list and 420 for the record, whatever the
          // window: the handle cannot squeeze either of them out.
          final widest = constraints.maxWidth - 420;
          final width = _listWidth.clamp(240.0, widest);

          return Row(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              SizedBox(width: width, child: _sidebar(context)),
              // The edge is the handle, as on Leads: an 8px target drawn as
              // the 1px line. A double-click puts it back.
              MouseRegion(
                cursor: SystemMouseCursors.resizeLeftRight,
                child: GestureDetector(
                  key: const ValueKey('hr-list-edge'),
                  behavior: HitTestBehavior.opaque,
                  onHorizontalDragUpdate: (d) => setState(
                    () => _listWidth =
                        _listWidth.clamp(240.0, widest) + d.delta.dx,
                  ),
                  onHorizontalDragEnd: (_) =>
                      _keepWidth(_listWidth.clamp(240.0, widest)),
                  onDoubleTap: () => _keepWidth(300),
                  child: const SizedBox(
                    width: 8,
                    child: Center(child: VerticalDivider(width: 1)),
                  ),
                ),
              ),
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

  void _keepWidth(double width) {
    setState(() => _listWidth = width);
    widget.session.setPreference('hr.records.list_width', width.round());
  }

  // ── the list ─────────────────────────────────────────────────────

  Widget _sidebar(BuildContext context) {
    final shown = _shown;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          key: const ValueKey('tour-hr-records-search'),
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
          key: const ValueKey('tour-hr-records-showing'),
          padding: const EdgeInsets.fromLTRB(12, 0, 12, 8),
          child: Wrap(
            spacing: 6,
            children: [
              for (final (value, label) in [
                (_Showing.everyone, 'Everyone'),
                (_Showing.active, 'Active'),
                (_Showing.deactivated, 'Inactive'),
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
        if (_options.roles.isNotEmpty)
          Padding(
            padding: const EdgeInsets.fromLTRB(12, 0, 12, 8),
            child: SearchPickerField<String>(
              key: RecordsTab.roleFilterKey,
              value: _roleId ?? '',
              label: 'Filter by role',
              border: const OutlineInputBorder(),
              options: [
                const SearchOption(value: '', label: 'All roles'),
                for (final role in _options.roles)
                  SearchOption(value: role.id, label: role.name),
              ],
              onChanged: (value) {
                setState(() => _roleId = (value ?? '').isEmpty ? null : value);
                _load();
              },
            ),
          ),
        if (_places case final places when places.length > 1)
          Padding(
            padding: const EdgeInsets.fromLTRB(12, 0, 12, 8),
            child: SearchPickerField<String>(
              key: RecordsTab.placeFilterKey,
              value: _place ?? '',
              label: 'Filter by company',
              border: const OutlineInputBorder(),
              options: [
                const SearchOption(value: '', label: 'All companies'),
                ...places,
              ],
              onChanged: (value) {
                setState(() => _place = (value ?? '').isEmpty ? null : value);
                _load();
              },
            ),
          ),
        const Divider(height: 1),
        Expanded(
          child: AsyncBody(
            key: const ValueKey('tour-hr-records-list'),
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
    final missing = (person['missing'] as num?)?.toInt() ?? 0;

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
                    Row(
                      children: [
                        Expanded(
                          child: Text(
                            person['name'] as String? ?? '',
                            overflow: TextOverflow.ellipsis,
                            style: theme.textTheme.titleSmall?.copyWith(
                              color: active ? null : theme.colorScheme.outline,
                            ),
                          ),
                        ),
                        // The amber boxes on their record, counted by the
                        // server (HrPeopleController::missing), as each
                        // website counts on Web Management (Jose, 2026-09-29).
                        MissingCount(
                          missing,
                          tooltip:
                              '$missing ${missing == 1 ? 'field' : 'fields'} '
                              'not filled in',
                        ),
                      ],
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
                        if (!active) const Tag('Inactive', warn: true),
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
        key: const ValueKey('tour-hr-records-pick'),
        child: Padding(
          padding: const EdgeInsets.all(32),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(
                Icons.person_search_outlined,
                size: 40,
                color: theme.colorScheme.outline,
              ),
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
      onDeleted: () {
        setState(() => _selected = null);
        _load();
      },
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

/// The personal boxes counted when empty, by the rule
/// HrPeopleController::missing counts the list's number by. With the start
/// date, under Employment, they are every box that number covers.
/// The personal boxes that count as not filled in when empty — the same six
/// as HrPeopleController::missing. Not middle name: most people have none, and
/// nothing can record "has none", so it would stay amber and keep the count
/// above zero for ever.
const _personalBoxes = [
  'first_name',
  'last_name',
  'phone',
  'address',
  'gender',
  'date_of_birth',
];

bool _blank(Object? value) => (value?.toString() ?? '').trim().isEmpty;

/// The sections, in the order the record's tab strip draws them.
///
/// Pay is drawn only for somebody the server sent pay to: `pay_rates` is null,
/// not an empty list, for a reader without `hr.pay.view`, so the tab is absent
/// rather than a pane that could only ever refuse. That makes the list depend
/// on the reader — which is why the record holds its section as a KEY and
/// derives the live one each build (see `_active`), the shape hr_screen.dart's
/// `_activeTab` set.
List<(String, String, IconData)> _sectionsFor(Map<String, dynamic> person) => [
  ('personal', 'Personal', Icons.badge_outlined),
  ('contacts', 'Emergency contacts', Icons.contact_phone_outlined),
  ('employment', 'Employment', Icons.work_outline),
  if (person['pay_rates'] != null) ('pay', 'Pay', Icons.payments_outlined),
  ('work', 'Work', Icons.schedule_outlined),
  ('equipment', 'Equipment', Icons.handyman_outlined),
  ('onboarding', 'Onboarding', Icons.how_to_reg_outlined),
  ('timeoff', 'Time off', Icons.beach_access_outlined),
  ('training', 'Training', Icons.school_outlined),
  ('notes', 'Notes', Icons.sticky_note_2_outlined),
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
    this.onDeleted,
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

  /// They were deleted for good: the record closes and the list reloads.
  final VoidCallback? onDeleted;

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
        title: 'Make ${_person['name']} inactive?',
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
            child: const Text('Make inactive'),
          ),
        ],
        child: const Text(
          'They cannot sign in, and their assignments stop granting anything. '
          'The record stays exactly where it is — every job they ran and every '
          'audit entry naming them still has somebody to point at — and '
          'making them active again puts them back.',
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
      // On a phone the person and the section strip scroll away with the
      // form: standing, they and the HR chrome above took 270 of 800 pixels.
      child: HeaderAboveList(
        header: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            _header(context),
            Material(
              color: Theme.of(context).colorScheme.surface,
              child: TabBarLike(
                level: TabLevel.secondary,
                // Keyed so a test can name THIS strip. The HR screen draws its
                // own module chips above it, and "Onboarding" is on both.
                key: _RecordsTabState.sectionsKey,
                labels: [for (final s in _shown) (s.$2, s.$3)],
                missing: [for (final s in _shown) _emptyIn(s.$1)],
                selected: _shown.indexWhere((s) => s.$1 == _active),
                onSelected: (i) => setState(() => _section = _shown[i].$1),
              ),
            ),
            const Divider(height: 1),
            if (_busy) const LinearProgressIndicator(minHeight: 2),
          ],
        ),
        list: SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(24, 20, 24, 32),
          child: Align(
            alignment: Alignment.topLeft,
            child: ConstrainedBox(
              key: const ValueKey('tour-hr-record-section'),
              constraints: const BoxConstraints(maxWidth: 760),
              child: _body(context),
            ),
          ),
        ),
      ),
    );
  }

  /// The empty boxes in one section of the saved record, for its tab, so the
  /// number on the list can be found.
  int _emptyIn(String section) => switch (section) {
    'personal' => _personalBoxes.where((key) => _blank(_person[key])).length,
    'employment' => _blank(_person['started_on']) ? 1 : 0,
    // The lists that count when empty, by HrPeopleController::index's rule.
    // Pay is null for a reader without pay, and then there is no Pay tab.
    'contacts' => _isEmptyList(_person['emergency_contacts']) ? 1 : 0,
    'pay' => _isEmptyList(_person['pay_rates']) ? 1 : 0,
    'work' => _person['work_schedule'] == null ? 1 : 0,
    _ => 0,
  };

  static bool _isEmptyList(Object? value) => value is List && value.isEmpty;

  Widget _header(BuildContext context) {
    final theme = Theme.of(context);
    final roleName = (_person['role'] as Map<String, dynamic>?)?['name'];
    final active = _person['is_active'] != false;
    final where = _RecordsTabState._where(_person);

    return Padding(
      key: const ValueKey('tour-hr-record-header'),
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
          Tag(active ? 'Active' : 'Inactive', warn: !active),
        ],
      ),
    );
  }

  List<(String, String, IconData)> get _shown => _sectionsFor(_person);

  /// The section being shown, derived against the list drawn THIS build. A
  /// section that has gone — Pay, after a role change removed it — falls back
  /// to the first one rather than lighting no chip over a blank pane.
  String get _active {
    final shown = _shown;

    return shown.any((s) => s.$1 == _section) ? _section : shown.first.$1;
  }

  List<Map<String, dynamic>> _rows(String key) => [
    for (final row in (_person[key] as List? ?? const []))
      if (row is Map) Map<String, dynamic>.from(row),
  ];

  Widget _body(BuildContext context) => switch (_active) {
    'contacts' => _DetailList(
      key: ValueKey('contacts-$_version'),
      kind: _DetailKind.contacts,
      rows: _rows('emergency_contacts'),
      personId: widget.personId,
      api: widget.api,
      formats: widget.formats,
      refused: _noEdit,
      onChanged: _reload,
    ),
    'pay' => _DetailList(
      key: ValueKey('pay-$_version'),
      kind: _DetailKind.pay,
      rows: _rows('pay_rates'),
      personId: widget.personId,
      api: widget.api,
      formats: widget.formats,
      // Its own key: somebody who keeps the people list is not thereby
      // somebody who changes what they are paid.
      refused:
          widget.session.me?.refusal('hr.pay.edit', 'change pay rates') ??
          _noEdit,
      onChanged: _reload,
    ),
    'equipment' => _DetailList(
      key: ValueKey('equipment-$_version'),
      kind: _DetailKind.equipment,
      rows: _rows('equipment'),
      personId: widget.personId,
      api: widget.api,
      formats: widget.formats,
      refused: _noEdit,
      onChanged: _reload,
    ),
    'notes' => _DetailList(
      key: ValueKey('notes-$_version'),
      kind: _DetailKind.notes,
      rows: _rows('notes'),
      personId: widget.personId,
      api: widget.api,
      formats: widget.formats,
      refused: _noEdit,
      onChanged: _reload,
    ),
    'personal' => _PersonalSection(
      key: ValueKey('personal-$_version'),
      api: widget.api,
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
          empty: schedule == null,
          child: Text(
            _describeSchedule(days),
            style: theme.textTheme.bodyMedium,
          ),
        ),
        const SizedBox(height: 8),
        OutlinedButton.icon(
          key: const ValueKey('tour-hr-record-schedule'),
          icon: const Icon(Icons.edit_calendar_outlined),
          label: const Text('Change their schedule'),
          onPressed: noEdit == null ? () => _editSchedule(days) : null,
        ),
        const SizedBox(height: 20),
        SwitchListTile(
          key: const ValueKey('tour-hr-record-lock'),
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
        // Jose, 2026-10-20: "different than deactivation, we are missing
        // full deletion". Its own key (UserPolicy::deletePermanently).
        Builder(
          builder: (context) {
            final refusedDelete = self
                ? 'This is for somebody else. Delete your own account from '
                      'Your account.'
                : widget.session.me?.refusal(
                    'hr.delete',
                    'delete people permanently',
                  );
            return Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                OutlinedButton.icon(
                  key: const Key('delete-permanently'),
                  style: OutlinedButton.styleFrom(
                    foregroundColor: Theme.of(context).colorScheme.error,
                  ),
                  icon: const Icon(Icons.delete_forever_outlined),
                  label: const Text('Delete permanently'),
                  onPressed: refusedDelete == null && !_busy
                      ? _deletePermanently
                      : null,
                ),
                const SizedBox(height: 6),
                Text(
                  refusedDelete ??
                      'Erases them and their personal data everywhere, so '
                          'their email and phone can be used for a new account. '
                          'Unlike Deactivate, it cannot be undone.',
                  style: Theme.of(context).textTheme.bodySmall,
                ),
              ],
            );
          },
        ),
      ],
    );
  }

  Future<void> _deletePermanently() async {
    final deleted = await showSidePanel<bool>(
      context,
      builder: (_) => _DeletePanel(
        api: widget.api,
        personId: widget.personId,
        name: _person['name'] as String? ?? '',
      ),
    );
    if (deleted == true && mounted) widget.onDeleted?.call();
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
  const _Field({
    required this.label,
    required this.child,
    this.why,
    this.empty = false,
  });

  final String label;
  final Widget child;
  final String? why;

  /// Amber and "Not filled in", as an empty Personal box is.
  final bool empty;

  @override
  Widget build(BuildContext context) => InputDecorator(
    isEmpty: empty,
    decoration: notFilledIn(
      context,
      InputDecoration(
        labelText: label,
        helperText: why,
        helperMaxLines: 3,
        border: const OutlineInputBorder(),
        // Enabled while empty: a disabled box draws no amber border.
        enabled: empty,
      ),
      empty: empty,
    ),
    child: empty ? const SizedBox.shrink() : child,
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

class _PersonalSection extends StatefulWidget {
  const _PersonalSection({
    super.key,
    required this.api,
    required this.person,
    required this.formats,
    required this.noEdit,
    required this.onSave,
  });

  final ApiClient api;
  final Map<String, dynamic> person;
  final DisplayFormats formats;
  final String? noEdit;
  final _Saver onSave;

  @override
  State<_PersonalSection> createState() => _PersonalSectionState();
}

class _PersonalSectionState extends State<_PersonalSection> {
  // The home address in its parts (Jose, 2026-09-30): the server composes
  // the one line contracts print from them.
  static const _texts = [
    'first_name',
    'middle_name',
    'last_name',
    'phone',
    'address_line1',
    'address_line2',
    'city',
    'state',
    'postal_code',
    'country',
    'gender',
  ];

  static const _addressParts = [
    'address_line1',
    'address_line2',
    'city',
    'state',
    'postal_code',
    'country',
  ];

  /// What is stored for a box. A record with the one-line address and no
  /// parts at all (a server from before the parts) keeps that line as its
  /// street, rather than showing the address as gone.
  String _stored(String key) {
    final value = widget.person[key]?.toString() ?? '';
    if (key != 'address_line1' || value.isNotEmpty) return value;
    final noParts = _addressParts.every((k) => _blank(widget.person[k]));
    return noParts ? widget.person['address']?.toString() ?? '' : value;
  }

  /// What a box shows for what is stored: a phone as `+1 (214) 968-9819`,
  /// even one saved as bare digits before this was formatted.
  String _shown(String key) {
    final value = _stored(key);
    return key == 'phone' ? formatPhoneNumber(toE164(value) ?? value) : value;
  }

  late final Map<String, TextEditingController> _fields = {
    for (final key in _texts)
      key: TextEditingController(text: _shown(key))
        ..addListener(() => setState(() {})),
  };

  late String? _born = widget.person['date_of_birth']?.toString();
  late final _bornDay = TextEditingController(text: _born ?? '');

  @override
  void dispose() {
    for (final controller in _fields.values) {
      controller.dispose();
    }
    _bornDay.dispose();
    super.dispose();
  }

  Map<String, dynamic> get _change {
    final change = <String, dynamic>{};

    for (final key in _texts) {
      final now = _fields[key]!.text.trim();
      final was = _stored(key);
      // The phone is compared and sent as E.164, whatever it looks like on
      // screen: formatting a stored number is not a change to it.
      if (key == 'phone') {
        if (toE164(now) != toE164(was)) change[key] = toE164(now);
        continue;
      }
      // An emptied optional field is sent as null, which is what the column
      // holds for "not given"; an empty string would be a different answer.
      if (now != was) change[key] = now.isEmpty ? null : now;
    }

    // The address goes whole when any part of it changed: it is one address,
    // and the one line contracts print is composed from all of its parts.
    if (_addressParts.any(change.containsKey)) {
      for (final key in _addressParts) {
        final now = _fields[key]!.text.trim();
        change[key] = now.isEmpty ? null : now;
      }
    }

    if (_born != widget.person['date_of_birth']?.toString()) {
      change['date_of_birth'] = _born;
    }

    return change;
  }

  void _discard() {
    for (final key in _texts) {
      _fields[key]!.text = _shown(key);
    }
    setState(() => _born = widget.person['date_of_birth']?.toString());
    _bornDay.text = _born ?? '';
  }

  @override
  Widget build(BuildContext context) {
    final editable = widget.noEdit == null;

    Widget text(String key, String label, {int lines = 1}) => TextField(
      controller: _fields[key],
      enabled: editable,
      maxLines: lines,
      // A phone formats as it is typed, as it does on Your account.
      keyboardType: key == 'phone' ? TextInputType.phone : null,
      inputFormatters: key == 'phone'
          ? const [PhoneNumberInputFormatter()]
          : null,
      // Amber and saying so when empty, label above: as Web Management's
      // fields (Jose, 2026-09-29, "like we do on marketing SEO").
      decoration: notFilledIn(
        context,
        InputDecoration(labelText: label, border: const OutlineInputBorder()),
        empty: _personalBoxes.contains(key) && _blank(_fields[key]!.text),
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
        // The lookup and the parts it fills, as on Your account.
        AddressFields(
          key: const ValueKey('tour-hr-record-address'),
          api: widget.api,
          line1: _fields['address_line1']!,
          line2: _fields['address_line2']!,
          city: _fields['city']!,
          state: _fields['state']!,
          postal: _fields['postal_code']!,
          country: _fields['country']!,
          enabled: editable,
          flagEmpty: true,
        ),
        const SizedBox(height: 14),
        _Pair(
          // Typed or picked inline, the house date control, not a modal
          // date picker; amber while empty like the boxes beside it.
          CalendarDayField(
            key: const ValueKey('hr-date-of-birth'),
            controller: _bornDay,
            formats: widget.formats,
            label: 'Date of birth',
            enabled: editable,
            firstDate: DateTime(1900),
            lastDate: DateTime.now().subtract(const Duration(days: 1)),
            flagEmpty: true,
            onChanged: (v) => setState(() => _born = v.isEmpty ? null : v),
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

  /// Where they work as `type:id`, when it is one place; null for none or
  /// several, which the picker shows as text until somebody picks one.
  String? get _savedPlace {
    final rows = (widget.person['assignments'] as List<dynamic>? ?? const [])
        .whereType<Map<String, dynamic>>()
        .toList();
    return rows.length == 1
        ? '${rows.single['type']}:${rows.single['scope_id']}'
        : null;
  }

  late String? _role = _savedRole;
  late String? _place = _savedPlace;
  late bool _active = _savedActive;
  late String? _start = _savedStart;
  late final _startDay = TextEditingController(text: _savedStart ?? '');

  @override
  void dispose() {
    _startDay.dispose();
    super.dispose();
  }

  Map<String, dynamic> get _change => {
    if (_role != _savedRole && _role != null) 'role_id': _role,
    if (_active != _savedActive) 'is_active': _active,
    if (_start != _savedStart && _start != null) 'started_on': _start,
    if (_place != _savedPlace && _place != null) ...{
      'scope_type': _place!.split(':').first,
      'scope_id': _place!.split(':').last,
    },
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
          key: const ValueKey('tour-hr-record-role'),
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
          key: const ValueKey('tour-hr-record-status'),
          segments: const [
            ButtonSegment(value: true, label: Text('Active')),
            ButtonSegment(value: false, label: Text('Inactive')),
          ],
          selected: {_active},
          onSelectionChanged: editable
              ? (value) => setState(() => _active = value.first)
              : null,
        ),
        const SizedBox(height: 16),
        _Pair(
          CalendarDayField(
            key: const ValueKey('hr-start-date'),
            controller: _startDay,
            formats: widget.formats,
            label: 'Start date',
            helper: 'The day they started here. Paid time off counts from it.',
            enabled: editable,
            flagEmpty: true,
            onChanged: (v) => setState(() => _start = v.isEmpty ? null : v),
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
        // Editable (Mia, 2026-10-02: "assigning andy to A1-Group instead so
        // she has full access"). Only places the editor reaches are offered,
        // the same list the invitation uses, and the server checks it again.
        if (editable && widget.options.scopes.isNotEmpty)
          SearchPickerField<String>(
            key: const ValueKey('hr-works-at'),
            value: _place,
            label: 'Works at',
            hint: _RecordsTabState._where(widget.person).isEmpty
                ? null
                : _RecordsTabState._where(widget.person),
            helperText:
                'Everything beneath it comes with it: a company covers its '
                'franchises, the organization covers every company. Moving '
                'them replaces where they were.',
            helperMaxLines: 3,
            border: const OutlineInputBorder(),
            options: [
              for (final scope in widget.options.scopes)
                SearchOption(
                  value: scope.value,
                  label: scope.isActive
                      ? scope.pathLabel
                      : '${scope.pathLabel} — deactivated',
                  enabled: scope.isActive,
                ),
            ],
            onChanged: (value) => setState(() => _place = value),
          )
        else
          _Field(
            label: 'Works at',
            why: 'Set when they were invited.',
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
              _place = _savedPlace;
              _active = _savedActive;
              _start = _savedStart;
              _startDay.text = _savedStart ?? '';
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
                      trailing: Row(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          if (row.downloadable && row.status == 'completed')
                            IconButton(
                              key: Key('record-onboarding-download-${row.id}'),
                              tooltip: 'Download the signed PDF',
                              icon: const Icon(
                                Icons.download_outlined,
                                size: 20,
                              ),
                              onPressed: () async {
                                final refusal = await downloadSignedPdf(
                                  widget.api,
                                  row,
                                );
                                if (refusal == null || !context.mounted) return;
                                ScaffoldMessenger.of(context).showSnackBar(
                                  SnackBar(content: Text(refusal)),
                                );
                              },
                            ),
                          const Icon(Icons.chevron_right),
                        ],
                      ),
                      onTap: () => widget.onOpen(row.id),
                    ),
                  ),
              ],
            ),
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// The four lists of 2026-09-29: emergency contacts, pay, equipment, notes
// ─────────────────────────────────────────────────────────────────────────────

enum _FieldType { text, multiline, date, number, choice }

class _FieldSpec {
  const _FieldSpec(
    this.key,
    this.label,
    this.type, {
    this.required = false,
    this.options = const {},
  });

  final String key;
  final String label;
  final _FieldType type;
  final bool required;

  /// value => label, for [_FieldType.choice].
  final Map<String, String> options;
}

/// One kind of list on a person's record, and everything that differs between
/// the four: where it is written, what a row says, and what the form asks.
class _DetailKind {
  const _DetailKind({
    required this.path,
    required this.noun,
    required this.empty,
    required this.fields,
    required this.title,
    required this.subtitle,
    this.intro,
    this.returnable = false,
    this.counted = false,
  });

  /// The `{kind}` in `hr/people/{user}/details/{kind}`.
  final String path;
  final String noun;
  final String empty;
  final String? intro;
  final List<_FieldSpec> fields;
  final String Function(Map<String, dynamic> row, DisplayFormats formats) title;
  final String Function(Map<String, dynamic> row, DisplayFormats formats)
  subtitle;

  /// Equipment is handed back rather than deleted: the row stays as the record
  /// of what was returned.
  final bool returnable;

  /// Empty counts as not filled in: amber, and one on the list's number
  /// (HrPeopleController::index). Not notes or equipment: most people have
  /// none of either, and that is not a gap.
  final bool counted;

  static String _date(Object? iso, DisplayFormats formats) {
    final at = DateTime.tryParse(iso?.toString() ?? '');
    return at == null ? '' : formats.formatDate(at);
  }

  static String _join(List<String?> parts) =>
      parts.where((p) => p != null && p.trim().isNotEmpty).join(' · ');

  static final contacts = _DetailKind(
    path: 'emergency-contacts',
    noun: 'emergency contact',
    empty: 'No emergency contact recorded.',
    counted: true,
    fields: const [
      _FieldSpec('name', 'Name', _FieldType.text, required: true),
      _FieldSpec('relationship', 'Relationship', _FieldType.text),
      _FieldSpec('phone', 'Phone', _FieldType.text),
    ],
    title: (row, _) => row['name']?.toString() ?? '',
    subtitle: (row, _) =>
        _join([row['relationship']?.toString(), row['phone']?.toString()]),
  );

  static const _periods = {
    'hour': 'an hour',
    'week': 'a week',
    'month': 'a month',
    'year': 'a year',
  };

  static final pay = _DetailKind(
    path: 'pay-rates',
    noun: 'pay rate',
    empty: 'No pay rate recorded.',
    counted: true,
    intro:
        'A raise is a new rate from a new date; the old one stays as history. '
        'Seen and changed only by roles given pay.',
    fields: const [
      _FieldSpec(
        'basis',
        'Paid',
        _FieldType.choice,
        required: true,
        options: {'hourly': 'Hourly', 'salary': 'Salary'},
      ),
      _FieldSpec('amount', 'Amount', _FieldType.number, required: true),
      _FieldSpec(
        'period',
        'Per',
        _FieldType.choice,
        required: true,
        options: {
          'hour': 'Hour',
          'week': 'Week',
          'month': 'Month',
          'year': 'Year',
        },
      ),
      _FieldSpec('effective_on', 'From', _FieldType.date, required: true),
      _FieldSpec('note', 'Note', _FieldType.multiline),
    ],
    title: (row, _) =>
        '${row['amount']} ${_periods[row['period']] ?? row['period']}',
    subtitle: (row, formats) => _join([
      row['basis'] == 'salary' ? 'Salary' : 'Hourly',
      'from ${_date(row['effective_on'], formats)}',
      row['note']?.toString(),
    ]),
  );

  static final equipment = _DetailKind(
    path: 'equipment',
    noun: 'item',
    empty: 'Nothing recorded as held.',
    returnable: true,
    fields: const [
      _FieldSpec('name', 'Item', _FieldType.text, required: true),
      _FieldSpec(
        'category',
        'Kind',
        _FieldType.choice,
        required: true,
        options: {'tool': 'Tool', 'equipment': 'Equipment'},
      ),
      _FieldSpec('quantity', 'Quantity', _FieldType.number),
      _FieldSpec('identifier', 'Serial or asset tag', _FieldType.text),
      _FieldSpec('issued_on', 'Issued', _FieldType.date),
      _FieldSpec('note', 'Note', _FieldType.multiline),
    ],
    title: (row, _) {
      final quantity = row['quantity'] as int? ?? 1;
      return quantity > 1 ? '${row['name']} ×$quantity' : '${row['name']}';
    },
    subtitle: (row, formats) => _join([
      row['category'] == 'tool' ? 'Tool' : 'Equipment',
      row['identifier']?.toString(),
      if (row['issued_on'] != null)
        'issued ${_date(row['issued_on'], formats)}',
      row['returned_on'] == null
          ? 'still held'
          : 'returned ${_date(row['returned_on'], formats)}',
    ]),
  );

  static final notes = _DetailKind(
    path: 'notes',
    noun: 'note',
    empty: 'No notes yet.',
    intro: 'Kept for HR. The person does not see these.',
    fields: const [
      _FieldSpec('body', 'Note', _FieldType.multiline, required: true),
    ],
    title: (row, _) => row['body']?.toString() ?? '',
    subtitle: (row, formats) =>
        _join([row['author']?.toString(), _date(row['created_at'], formats)]),
  );
}

/// One of the four lists: its rows, and add, change, remove.
class _DetailList extends StatefulWidget {
  const _DetailList({
    super.key,
    required this.kind,
    required this.rows,
    required this.personId,
    required this.api,
    required this.formats,
    required this.refused,
    required this.onChanged,
  });

  final _DetailKind kind;
  final List<Map<String, dynamic>> rows;
  final String personId;
  final ApiClient api;
  final DisplayFormats formats;

  /// Why this reader may not change the list, or null when they may.
  final String? refused;
  final Future<void> Function() onChanged;

  @override
  State<_DetailList> createState() => _DetailListState();
}

class _DetailListState extends State<_DetailList> {
  bool _busy = false;

  String get _base =>
      '/hr/people/${widget.personId}/details/${widget.kind.path}';

  Future<void> _write(Future<ApiResponse> Function() call) async {
    setState(() => _busy = true);
    final response = await call();
    if (!mounted) return;
    setState(() => _busy = false);

    if (!response.ok) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(response.reason),
          backgroundColor: Theme.of(context).colorScheme.error,
          behavior: SnackBarBehavior.floating,
          width: 420,
        ),
      );
      return;
    }

    await widget.onChanged();
  }

  Future<void> _edit([Map<String, dynamic>? row]) async {
    final values = await showSidePanel<Map<String, dynamic>>(
      context,
      builder: (_) =>
          _DetailForm(kind: widget.kind, initial: row, formats: widget.formats),
    );

    if (values == null || !mounted) return;

    await _write(
      () => row == null
          ? widget.api.post(_base, values)
          : widget.api.patch('$_base/${row['id']}', values),
    );
  }

  Future<void> _remove(Map<String, dynamic> row) async {
    final sure = await showSidePanel<bool>(
      context,
      builder: (context) => SidePanel(
        title: 'Remove this ${widget.kind.noun}?',
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
            child: const Text('Remove'),
          ),
        ],
        child: Text(widget.kind.title(row, widget.formats)),
      ),
    );

    if (sure != true || !mounted) return;

    await _write(() => widget.api.delete('$_base/${row['id']}'));
  }

  Future<void> _returned(Map<String, dynamic> row) => _write(
    () => widget.api.patch('$_base/${row['id']}', {
      'returned_on': _DetailForm.ymd(DateTime.now()),
    }),
  );

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final editable = widget.refused == null && !_busy;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (widget.refused != null) ...[
          ErrorNote(widget.refused!),
          const SizedBox(height: 12),
        ],
        if (widget.kind.intro != null) ...[
          Text(widget.kind.intro!, style: theme.textTheme.bodySmall),
          const SizedBox(height: 12),
        ],
        if (widget.rows.isEmpty && widget.kind.counted)
          Padding(
            padding: const EdgeInsets.only(bottom: 12),
            child: _Field(
              label:
                  widget.kind.noun[0].toUpperCase() +
                  widget.kind.noun.substring(1),
              empty: true,
              child: const SizedBox.shrink(),
            ),
          )
        else if (widget.rows.isEmpty)
          Padding(
            padding: const EdgeInsets.only(bottom: 12),
            child: Text(widget.kind.empty),
          ),
        for (final row in widget.rows)
          Card(
            child: ListTile(
              title: Text(widget.kind.title(row, widget.formats)),
              subtitle: Text(widget.kind.subtitle(row, widget.formats)),
              trailing: widget.refused != null
                  ? null
                  : Wrap(
                      spacing: 4,
                      crossAxisAlignment: WrapCrossAlignment.center,
                      children: [
                        if (widget.kind.returnable &&
                            row['returned_on'] == null)
                          TextButton(
                            onPressed: editable ? () => _returned(row) : null,
                            child: const Text('Mark returned'),
                          ),
                        IconButton(
                          tooltip: 'Change this ${widget.kind.noun}',
                          icon: const Icon(Icons.edit_outlined),
                          onPressed: editable ? () => _edit(row) : null,
                        ),
                        IconButton(
                          tooltip: 'Remove this ${widget.kind.noun}',
                          icon: const Icon(Icons.delete_outline),
                          onPressed: editable ? () => _remove(row) : null,
                        ),
                      ],
                    ),
            ),
          ),
        if (widget.refused == null)
          Align(
            alignment: Alignment.centerLeft,
            child: Padding(
              padding: const EdgeInsets.only(top: 8),
              child: FilledButton.icon(
                key: const ValueKey('tour-hr-record-add'),
                icon: const Icon(Icons.add),
                label: Text('Add ${widget.kind.noun}'),
                onPressed: editable ? () => _edit() : null,
              ),
            ),
          ),
      ],
    );
  }
}

/// The form for one row, in a side panel. Returns the values, or null.
class _DetailForm extends StatefulWidget {
  const _DetailForm({
    required this.kind,
    required this.initial,
    required this.formats,
  });

  final _DetailKind kind;
  final Map<String, dynamic>? initial;
  final DisplayFormats formats;

  static String ymd(DateTime d) =>
      '${d.year.toString().padLeft(4, '0')}-'
      '${d.month.toString().padLeft(2, '0')}-'
      '${d.day.toString().padLeft(2, '0')}';

  @override
  State<_DetailForm> createState() => _DetailFormState();
}

class _DetailFormState extends State<_DetailForm> {
  late final Map<String, TextEditingController> _text = {
    for (final f in widget.kind.fields)
      if (f.type == _FieldType.text ||
          f.type == _FieldType.multiline ||
          f.type == _FieldType.number)
        f.key: TextEditingController(
          text: widget.initial?[f.key]?.toString() ?? '',
        )..addListener(() => setState(() {})),
  };

  late final Map<String, String?> _picked = {
    for (final f in widget.kind.fields)
      if (f.type == _FieldType.date || f.type == _FieldType.choice)
        f.key:
            widget.initial?[f.key]?.toString() ??
            (f.type == _FieldType.choice ? f.options.keys.first : null),
  };

  /// Each date field's day, for the calendar field that shows it.
  late final Map<String, TextEditingController> _days = {
    for (final f in widget.kind.fields)
      if (f.type == _FieldType.date)
        f.key: TextEditingController(text: _picked[f.key] ?? ''),
  };

  @override
  void dispose() {
    for (final c in [..._text.values, ..._days.values]) {
      c.dispose();
    }
    super.dispose();
  }

  Object? _value(_FieldSpec f) {
    if (_text.containsKey(f.key)) {
      final text = _text[f.key]!.text.trim();
      if (text.isEmpty) return null;
      return f.key == 'quantity' ? int.tryParse(text) : text;
    }
    return _picked[f.key];
  }

  bool get _complete =>
      widget.kind.fields.every((f) => !f.required || _value(f) != null);

  Widget _field(_FieldSpec f) {
    switch (f.type) {
      case _FieldType.choice:
        return Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(f.label, style: Theme.of(context).textTheme.labelLarge),
            const SizedBox(height: 6),
            SegmentedButton<String>(
              segments: [
                for (final entry in f.options.entries)
                  ButtonSegment(value: entry.key, label: Text(entry.value)),
              ],
              selected: {_picked[f.key] ?? f.options.keys.first},
              onSelectionChanged: (v) =>
                  setState(() => _picked[f.key] = v.first),
            ),
          ],
        );
      case _FieldType.date:
        return CalendarDayField(
          key: ValueKey('hr-detail-${f.key}'),
          controller: _days[f.key]!,
          formats: widget.formats,
          label: f.label,
          firstDate: DateTime(1900),
          lastDate: DateTime(DateTime.now().year + 2),
          onChanged: (v) =>
              setState(() => _picked[f.key] = v.isEmpty ? null : v),
        );
      case _FieldType.text:
      case _FieldType.multiline:
      case _FieldType.number:
        return TextField(
          controller: _text[f.key],
          maxLines: f.type == _FieldType.multiline ? 4 : 1,
          keyboardType: f.type == _FieldType.number
              ? const TextInputType.numberWithOptions(decimal: true)
              : null,
          decoration: InputDecoration(
            labelText: f.label,
            border: const OutlineInputBorder(),
          ),
        );
    }
  }

  @override
  Widget build(BuildContext context) {
    return SidePanel(
      title: widget.initial == null
          ? 'Add ${widget.kind.noun}'
          : 'Change ${widget.kind.noun}',
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: const Text('Cancel'),
        ),
        FilledButton(
          onPressed: _complete
              ? () => Navigator.of(
                  context,
                ).pop({for (final f in widget.kind.fields) f.key: _value(f)})
              : null,
          child: const Text('Save'),
        ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: [
          for (final f in widget.kind.fields) ...[
            _field(f),
            const SizedBox(height: 14),
          ],
        ],
      ),
    );
  }
}

/// What deleting somebody removes, read from the server's own dry run, and
/// their name typed back before anything happens (Jose, 2026-10-20).
class _DeletePanel extends StatefulWidget {
  const _DeletePanel({
    required this.api,
    required this.personId,
    required this.name,
  });

  final ApiClient api;
  final String personId;
  final String name;

  @override
  State<_DeletePanel> createState() => _DeletePanelState();
}

class _DeletePanelState extends State<_DeletePanel> {
  Map<String, dynamic>? _data;
  String? _error;
  String _typed = '';
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final response = await widget.api.get(
      '/hr/people/${widget.personId}/deletion',
    );
    if (!mounted) return;
    setState(() {
      if (response.ok) {
        _data = response.body['data'] as Map<String, dynamic>?;
      } else {
        _error = response.reason;
      }
    });
  }

  bool get _matches =>
      _typed.trim().toLowerCase() == widget.name.trim().toLowerCase() &&
      widget.name.trim().isNotEmpty;

  Future<void> _delete() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    final response = await widget.api.deleteWithBody(
      '/hr/people/${widget.personId}',
      {'confirm': _typed.trim()},
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

  /// "emergency_contacts" reads as "Emergency contacts".
  static String _label(Object? table) {
    final words = '$table'.replaceAll('_', ' ').trim();
    return words.isEmpty ? words : words[0].toUpperCase() + words.substring(1);
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final blocked = _data?['blocked_because'] as String?;
    final tables = [
      for (final t in ((_data?['preview'] as Map?)?['tables'] as List? ?? []))
        if (t is Map && ((t['rows'] as num?) ?? 0) > 0) t,
    ];
    final removed = [
      for (final t in tables)
        if (((t['rows_dropped'] as num?) ?? 0) > 0)
          '${_label(t['table'])}: ${t['rows_dropped']}',
    ];
    final blanked = [
      for (final t in tables)
        // A map of column to stand-in when there are any, an empty list when
        // not (PHP's empty array): read both (0.0.0461 crashed on the map).
        if (switch (t['columns_erased']) {
          Map m => m.isNotEmpty,
          List l => l.isNotEmpty,
          _ => false,
        })
          _label(t['table']),
    ];

    return SidePanel(
      title: 'Delete ${widget.name} permanently?',
      actions: [
        TextButton(
          onPressed: _busy ? null : () => Navigator.of(context).pop(false),
          child: const Text('Cancel'),
        ),
        FilledButton(
          key: const Key('delete-permanently-confirm'),
          style: FilledButton.styleFrom(
            backgroundColor: theme.colorScheme.error,
          ),
          onPressed: _matches && blocked == null && !_busy ? _delete : null,
          child: Text(_busy ? 'Deleting…' : 'Delete permanently'),
        ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text(
            'This cannot be undone. They are signed out everywhere, their '
            'personal data is erased in every module, and their email and '
            'phone become free for a new account. Jobs, invoices, payroll '
            'totals and the audit log stay, with their name replaced by '
            '"Removed account".',
          ),
          const SizedBox(height: 16),
          if (_error != null) ...[
            ErrorNote(_error!),
            const SizedBox(height: 12),
          ],
          if (_data == null && _error == null)
            const LinearProgressIndicator()
          else if (blocked != null)
            ErrorNote(blocked)
          else if (_data != null) ...[
            if (removed.isNotEmpty) ...[
              Text('Removed', style: theme.textTheme.titleSmall),
              const SizedBox(height: 4),
              for (final line in removed) Text('• $line'),
              const SizedBox(height: 12),
            ],
            if (blanked.isNotEmpty) ...[
              Text('Erased, record kept', style: theme.textTheme.titleSmall),
              const SizedBox(height: 4),
              for (final line in blanked) Text('• $line'),
              const SizedBox(height: 16),
            ],
            Text(
              'Type their name, ${widget.name}, to confirm.',
              style: theme.textTheme.bodySmall,
            ),
            const SizedBox(height: 6),
            TextField(
              key: const Key('delete-permanently-name'),
              enabled: !_busy,
              decoration: const InputDecoration(
                border: OutlineInputBorder(),
                hintText: 'Their name',
              ),
              onChanged: (value) => setState(() => _typed = value),
            ),
          ],
        ],
      ),
    );
  }
}
