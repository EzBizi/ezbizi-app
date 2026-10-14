import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/contracts.dart';
import '../../core/deep_links.dart';
import '../../core/hr.dart';
import '../../core/onboarding.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../core/timestamps.dart';
import '../../widgets/async_body.dart';
import '../../widgets/error_note.dart';
import '../../widgets/list_table.dart';
import '../../widgets/load_more.dart';
import '../../widgets/not_filled_in.dart';
import '../../widgets/refused.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/side_panel.dart';
import '../../widgets/tag.dart';
import 'onboarding_form.dart';

/// Who is joining, and how far they have got (EZBIZI-HR-PLAN.md, "Onboarding").
///
/// ── The employer's half of a two-sided thing ─────────────────────
///
/// An onboarding is one person joining under one pinned contract version. This
/// tab is where somebody starts one and watches it: who is in flight, how far
/// along, and what is waiting on whom. The other half — the same form, with the
/// other side's boxes live — is what the new starter meets after accepting
/// their invitation, and it is the same widget (onboarding_form.dart).
///
/// ── Two faces, one destination ───────────────────────────────────
///
/// The list, and the form. The form replaces the list rather than opening
/// beside it, the same way the Contracts tab swaps in its designer and for the
/// reason that screen sets out: a 520px panel is the wrong shape for a page of
/// questions, and the list behind is not context worth keeping in view while
/// somebody is halfway down a contract.
///
/// ── Why the catalogue is fetched here ────────────────────────────
///
/// The words for a kind of question, and for who fills one in, are the
/// server's. This tab needs them twice — to say "3 still with the new starter"
/// on a row, and to hand them to the form — so they arrive with the list rather
/// than being fetched at the moment somebody opens one and finding out then.
class OnboardingTab extends StatefulWidget {
  const OnboardingTab({
    super.key,
    required this.api,
    required this.session,
    this.link,
  });

  final ApiClient api;
  final Session session;

  /// A notification's "open this onboarding"; a new one opens over the list.
  final DeepLink? link;

  @override
  State<OnboardingTab> createState() => _OnboardingTabState();
}

class _OnboardingTabState extends State<OnboardingTab> {
  bool _loading = true;
  String? _error;

  /// Which load owns the screen — the argument customers_screen.dart sets out.
  /// Starting one ends in a refresh and the control that triggered it is not
  /// hidden behind a spinner, so two can be in flight at once.
  int _request = 0;

  bool _fetching = false;

  List<OnboardingSummary> _rows = const [];
  int _total = 0;
  int _page = 1;
  int _lastPage = 1;
  String? _pageError;

  ContractVocabulary _vocabulary = ContractVocabulary.none;

  /// Why nothing can be started, when nothing can. Kept apart from [_error]:
  /// not being allowed to add to the list does not make the list unreadable.
  String? _cannotStart;

  /// The onboarding being filled in, or null for the list.
  String? _open;

  /// Which tile is picked (layout A, Mia 2026-10-02). `open` - in progress
  /// and submitted together - is where the tab opens, so finished forms stay
  /// their own archive (a Suggestion Jose said to build, 2026-10-01: "a
  /// seperate archieved for all the forms that are already done"); the rest
  /// are one status each. Asked of the server: the list pages.
  String _filter = 'open';

  bool get _finished => _filter == 'completed' || _filter == 'cancelled';

  /// Each status counted across every page, by the server.
  Map<String, int>? _tiles;

  DisplayFormats get _formats =>
      widget.session.me?.display ?? DisplayFormats.fallback;

  /// §4: the tab is `hr.view` and starting an onboarding is `hr.create`. Read
  /// from the permission list `/me` sends for exactly this purpose — never
  /// inferred from a role name.
  bool get _mayCreate =>
      widget.session.me?.permissions.contains('hr.create') ?? false;

  @override
  void initState() {
    super.initState();
    _open = widget.link?.id;
    _load();
  }

  @override
  void didUpdateWidget(OnboardingTab old) {
    super.didUpdateWidget(old);
    if (widget.link != null && !identical(widget.link, old.link)) {
      _open = widget.link!.id;
    }
  }

  Future<void> _load({int page = 1}) async {
    if (page > 1 && _fetching) return;

    final request = ++_request;

    setState(() {
      // A spinner only when there is nothing to keep — see AsyncBody.
      _loading = page == 1 && _rows.isEmpty;
      _fetching = true;
      _error = null;
      _pageError = null;
    });

    final answers = await Future.wait([
      widget.api.get('/hr/onboardings', {
        'page': '$page',
        if (_filter == 'open') 'state': 'open' else 'status': _filter,
      }),
      widget.api.get('/hr/contracts/catalogue'),
    ]);

    if (!mounted || request != _request) return;

    final onboardings = answers[0];
    final catalogue = answers[1];

    setState(() {
      _loading = false;
      _fetching = false;

      if (!onboardings.ok) {
        if (page == 1) {
          _error = onboardings.reason;
        } else {
          _pageError = onboardings.reason;
        }
        return;
      }

      final rows = OnboardingSummary.listFrom(onboardings.data);
      _rows = page == 1 ? rows : [..._rows, ...rows];

      final meta = onboardings.body['meta'] as Map<String, dynamic>?;
      _total = meta?['total'] as int? ?? _rows.length;
      _lastPage = meta?['last_page'] as int? ?? 1;
      _page = page;
      final tiles = meta?['tiles'];
      _tiles = tiles is Map
          ? {
              for (final e in tiles.entries)
                '${e.key}': (e.value as num?)?.toInt() ?? 0,
            }
          : null;

      _vocabulary = catalogue.ok && catalogue.data is Map
          ? ContractVocabulary.fromJson(
              Map<String, dynamic>.from(catalogue.data as Map),
            )
          : ContractVocabulary.none;

      _cannotStart = _whyNot(catalogue);
    });
  }

  /// Why the button is dead, or null when it is not.
  String? _whyNot(ApiResponse catalogue) {
    // The ordinary answer for somebody whose role opens this tab and does not
    // add to it. Not a failure, and it must not be dressed as one.
    if (!_mayCreate) {
      return 'You can see who is being taken on, but not start one yourself.';
    }

    if (!catalogue.ok) {
      return 'The kinds of question a contract asks could not be loaded, so an '
          'onboarding started now would have nothing to draw: '
          '${catalogue.reason}';
    }

    return null;
  }

  @override
  Widget build(BuildContext context) {
    if (_open case final id?) {
      return OnboardingForm(
        key: ValueKey(id),
        api: widget.api,
        onboardingId: id,
        vocabulary: _vocabulary,
        formats: _formats,
        backLabel: 'All onboardings',
        onBack: () => setState(() => _open = null),
        // The counts on the list behind are now out of date. Refreshed rather
        // than patched in place: the answer to "how far along" is the server's
        // and it counts required questions this end never sees.
        onChanged: _load,
      );
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        PanelHeader(
          // Never "$_total in flight". The Records tab reads the same endpoint
          // to show what was collected about somebody, so finished ones are on
          // it too, and a count that called them all in flight would be wrong
          // by exactly the number of people who have ever completed one.
          note: _loading || _error != null
              ? 'One person joining, under one contract.'
              : '$_total ${_total == 1 ? 'onboarding' : 'onboardings'}. Each '
                    'one pins the contract version it started with, so '
                    'publishing a new version does not change a form somebody '
                    'is halfway through.',
          action: FilledButton.icon(
            onPressed: _cannotStart == null ? _start : null,
            icon: const Icon(Icons.how_to_reg_outlined, size: 18),
            label: const Text('Start an onboarding'),
          ),
        ),
        if (_cannotStart != null)
          Padding(
            padding: const EdgeInsets.fromLTRB(24, 0, 24, 12),
            child: ErrorNote(_cannotStart!),
          ),
        Expanded(
          child: AsyncBody(
            loading: _loading,
            error: _error,
            onRetry: _load,
            child:
                _rows.isEmpty &&
                    _filter == 'open' &&
                    (_tiles?.values.every((n) => n == 0) ?? true)
                ? Center(
                    child: Padding(
                      padding: const EdgeInsets.all(32),
                      child: SizedBox(
                        width: 460,
                        child: _finished || _filter != 'open'
                            ? const Text(
                                'Nothing finished yet. Completed and cancelled '
                                'onboardings are kept here.',
                                textAlign: TextAlign.center,
                              )
                            : const Text(
                                'Nobody is being taken on right now.\n\n'
                                'Starting one picks the person and the contract. The '
                                'contract decides what the form asks and which side '
                                'answers each question — you fill in your half, they '
                                'fill in theirs, and either of you can correct the '
                                'questions marked as shared.',
                                textAlign: TextAlign.center,
                              ),
                      ),
                    ),
                  )
                : ListView(
                    padding: const EdgeInsets.fromLTRB(24, 0, 24, 24),
                    children: [
                      _tilesRow(),
                      const SizedBox(height: 12),
                      _table(context),
                    ],
                  ),
          ),
        ),
      ],
    );
  }

  Widget _tilesRow() {
    int? n(String status) => _tiles?[status];

    return FilterTiles(
      prefix: 'onboardings',
      picked: _filter,
      // A picked tile tapped again goes back to the ones still under way.
      onPick: (key) {
        setState(() {
          _filter = key ?? 'open';
          _rows = const [];
        });
        _load();
      },
      tiles: [
        FilterTile(
          key: 'open',
          label: 'Ongoing',
          count: _tiles == null
              ? null
              : (n('in_progress') ?? 0) +
                    (n('submitted') ?? 0) +
                    (n('awaiting_approval') ?? 0),
          note: 'not finished',
        ),
        FilterTile(
          key: 'in_progress',
          label: 'Being filled in',
          count: n('in_progress'),
          note: 'answers still owed',
        ),
        FilterTile(
          key: 'submitted',
          label: 'Submitted',
          count: n('submitted'),
          note: 'waiting on a signature',
          tone: Tone.caution,
        ),
        FilterTile(
          key: 'awaiting_approval',
          label: 'To approve',
          count: n('awaiting_approval'),
          note: 'signed, waiting for approval',
          tone: Tone.caution,
        ),
        FilterTile(
          key: 'completed',
          label: 'Completed',
          count: n('completed'),
          note: 'signed by everyone',
        ),
        FilterTile(
          key: 'cancelled',
          label: 'Cancelled',
          count: n('cancelled'),
          note: 'stopped before signing',
        ),
      ],
    );
  }

  Widget _table(BuildContext context) {
    if (_rows.isEmpty) {
      return const Padding(
        padding: EdgeInsets.all(24),
        child: Text(
          'No onboardings in this state.',
          textAlign: TextAlign.center,
        ),
      );
    }

    final theme = Theme.of(context);
    final small = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );

    return ListTable(
      columns: const [
        ListColumn('Person', flex: 4),
        ListColumn('Contract', flex: 3),
        ListColumn('Status', flex: 2),
        ListColumn('Where it is', flex: 4),
        ListColumn('', flex: 2, end: true),
      ],
      rowKey: (i) => ValueKey('onboarding-${_rows[i].id}'),
      onTap: (i) => setState(() => _open = _rows[i].id),
      rows: [
        for (final row in _rows)
          [
            Row(
              children: [
                Expanded(
                  child: PersonCell(
                    name: row.personName.isEmpty
                        ? row.personEmail
                        : row.personName,
                    sub: row.personName.isEmpty ? null : row.personEmail,
                  ),
                ),
                // Required questions still empty, both sides, as Records
                // counts a record's empty boxes (Jose, 2026-10-14).
                MissingCount(
                  _owed(row),
                  tooltip:
                      '${_owed(row)} required '
                      '${_owed(row) == 1 ? 'question' : 'questions'} '
                      'not answered',
                ),
              ],
            ),
            Text(
              [
                if (row.contractName.isNotEmpty) row.contractName,
                if (row.version != null) 'v${row.version}',
              ].join(' · '),
              overflow: TextOverflow.ellipsis,
            ),
            row.status.isEmpty
                ? const SizedBox.shrink()
                : Tag(
                    onboardingStatusLabel(row.status),
                    good: row.status == 'completed',
                  ),
            _whereItIs(row, small),
            Wrap(
              alignment: WrapAlignment.end,
              children: [
                if (row.downloadable &&
                    (row.status == 'completed' ||
                        row.status == 'awaiting_approval'))
                  IconButton(
                    key: Key('onboarding-download-${row.id}'),
                    tooltip: 'Download the signed PDF',
                    icon: const Icon(Icons.download_outlined, size: 20),
                    onPressed: () => _download(row),
                  ),
                if (_canCancel(row))
                  Refused(
                    reason: widget.session.me?.refusal(
                      'hr.create',
                      'cancel onboardings',
                    ),
                    child: TextButton(
                      onPressed: _mayCreate ? () => _cancel(row) : null,
                      child: const Text('Cancel'),
                    ),
                  ),
                TextButton(
                  onPressed: () => setState(() => _open = row.id),
                  child: const Text('Open'),
                ),
              ],
            ),
          ],
      ],
      footer: _page < _lastPage
          ? LoadMore(
              shown: _rows.length,
              total: _total,
              busy: _fetching,
              error: _pageError,
              onMore: () => _load(page: _page + 1),
            )
          : null,
    );
  }

  int _owed(OnboardingSummary row) =>
      row.progress.values.fold(0, (sum, side) => sum + side.outstanding);

  /// What is still owed, in amber and by side, so whose move it is shows
  /// without opening it (Jose, 2026-10-14); otherwise the sentence below.
  Widget _whereItIs(OnboardingSummary row, TextStyle? small) {
    if (_owed(row) == 0) return Text(_waitingOn(row), style: small);

    return Wrap(
      runSpacing: 4,
      children: [
        if (row.answered == 0) const Tag('Not started', warn: true),
        for (final side in [filledByEmployer, filledByEmployee])
          if ((row.progress[side]?.outstanding ?? 0) > 0)
            Tag(
              '${_vocabulary.filledByLabel(side)}: '
              '${row.progress[side]!.outstanding} missing',
              warn: true,
            ),
      ],
    );
  }

  /// Where it got to, and what is still owed.
  ///
  /// ── Only ever what the server said ───────────────────────────────
  ///
  /// The counts are the server's: which questions are required is a property of
  /// the tenant's field definitions, and a list row never sees the pinned
  /// version. So when no `progress` block arrives, this says WHEN rather than
  /// inventing a "waiting on them" — a row that guessed would be wrong exactly
  /// when it mattered, on the onboarding somebody was about to chase.
  ///
  /// Where the counts DO arrive, both sides are printed rather than one verdict
  /// word: an onboarding stuck because nobody set the wage and one stuck
  /// because the new starter has not opened it are different problems with
  /// different owners. The words for the sides are the catalogue's, so an
  /// organization that calls the employer "Head office" reads that here too.
  String _waitingOn(OnboardingSummary row) {
    String at(String? iso) => formatStamp(iso, '', formats: _formats);

    if (row.status == 'cancelled' || row.cancelledAt != null) {
      return 'Cancelled ${at(row.cancelledAt)}';
    }
    if (row.completedAt != null) return 'Completed ${at(row.completedAt)}';
    if (row.submittedAt != null) return 'Submitted ${at(row.submittedAt)}';

    final owed = [
      for (final side in [filledByEmployer, filledByEmployee])
        if ((row.progress[side]?.outstanding ?? 0) > 0)
          '${row.progress[side]!.outstanding} still with '
              '${_vocabulary.filledByLabel(side)}',
    ];

    if (row.progress.isEmpty) {
      return row.startedAt == null ? '' : 'Started ${at(row.startedAt)}';
    }

    if (owed.isEmpty) {
      // Everything required is answered and it has not been submitted. That is
      // a real state and a useful one — it is the row somebody should chase.
      return 'Everything asked has been answered. Not submitted yet.';
    }

    // How far along, then who it is waiting on. Two different questions, and a
    // row that answered only the second would make a form somebody has nearly
    // finished look the same as one nobody has touched.
    return ['${row.answered} answered', ...owed].join(' · ');
  }

  Future<void> _start() async {
    final started = await showSidePanel<String>(
      context,
      builder: (_) => _StartPanel(api: widget.api, inFlight: _rows),
    );

    if (started == null || !mounted) return;

    // Straight into the form. Somebody who has just chosen a person and a
    // contract is there to fill in their half, and making them find the new row
    // on a list they were already looking at is a step that buys nothing.
    setState(() => _open = started);

    await _load();
  }

  Future<void> _download(OnboardingSummary row) async {
    final refusal = await downloadSignedPdf(widget.api, row);
    if (refusal == null || !mounted) return;
    ScaffoldMessenger.of(
      context,
    ).showSnackBar(SnackBar(content: Text(refusal)));
  }

  // Whatever its state (Jose, 2026-10-05: "regardless of anything").
  bool _canCancel(OnboardingSummary row) =>
      row.status != 'cancelled' && row.cancelledAt == null;

  Future<void> _cancel(OnboardingSummary row) async {
    final who = row.personName.isEmpty ? row.personEmail : row.personName;
    final confirmed = await showSidePanel<bool>(
      context,
      builder: (context) => SidePanel(
        title: 'Cancel this onboarding?',
        subtitle: who,
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(false),
            child: const Text('Keep it'),
          ),
          FilledButton(
            style: FilledButton.styleFrom(
              backgroundColor: Theme.of(context).colorScheme.error,
            ),
            onPressed: () => Navigator.of(context).pop(true),
            child: const Text('Cancel onboarding'),
          ),
        ],
        child: const Text(
          'The form stops. Answers, signatures and documents stay on the '
          'record, and you can start a new onboarding for them if that is '
          'still needed.',
        ),
      ),
    );

    if (confirmed != true || !mounted) return;

    final response = await widget.api.delete('/hr/onboardings/${row.id}');

    if (!mounted) return;

    if (!response.ok) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(response.reason)));
      return;
    }

    await _load();
  }
}

/// Starting one: who, and under which contract.
///
/// ── An invitation, not a person ──────────────────────────────────
///
/// `POST /hr/onboardings` takes an invitation and a contract, and that is the
/// whole of it. It reads as a limitation and is a decision: an invitation is
/// how somebody arrives, it already carries the role and the place they are
/// arriving into, and one arrival is one contract. Starting a form against a
/// bare account would leave the two sides of it unconnected to how that person
/// came to be here.
///
/// One nobody has accepted yet is a valid subject on purpose:
/// `onboardings.user_id` is nullable for exactly that, so the employer can fill
/// in the wage and the start date before the person has an account. An accepted
/// one is equally valid — the onboarding simply starts knowing who they are.
///
/// ── Why it reads two lists that already exist ────────────────────
///
/// The invitations and the contracts. There is no purpose-built "who could be
/// onboarded" endpoint, and inventing one would be inventing a rule: which
/// pairings are allowed is the server's judgement, it enforces it on the POST,
/// and a second opinion here would be a list that quietly disagrees. What this
/// panel does is narrower and safe — it drops the two statuses that can never
/// work and marks the invitations ALREADY on the list behind it, because
/// offering one that will be refused is a mistake with no symptom until the
/// button is pressed.
class _StartPanel extends StatefulWidget {
  const _StartPanel({required this.api, required this.inFlight});

  final ApiClient api;

  /// The rows the tab is holding, used only to mark people who already have one.
  final List<OnboardingSummary> inFlight;

  @override
  State<_StartPanel> createState() => _StartPanelState();
}

class _StartPanelState extends State<_StartPanel> {
  bool _loading = true;
  String? _loadError;

  List<Map<String, dynamic>> _invitations = const [];
  List<Map<String, dynamic>> _contracts = const [];

  String? _invitationId;
  String? _contractId;

  /// Under the organization or a company: the employer the contract names,
  /// where it is filed, where the person is assigned (Ana's Suggestion; Jose,
  /// 2026-10-01). Empty leaves it where the invitation places them.
  String _under = '';

  /// The organization and the companies this person may place somebody in.
  List<ScopeOption> _places = const [];

  bool _busy = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final answers = await Future.wait([
      widget.api.get('/hr/invitations'),
      widget.api.get('/hr/contracts'),
      widget.api.get('/hr/people/scope-options'),
    ]);

    if (!mounted) return;

    setState(() {
      _loading = false;

      // The contracts are the one list without which this panel cannot do
      // anything at all: an onboarding without a contract has no questions.
      if (!answers[1].ok) {
        _loadError = 'The contracts could not be loaded: ${answers[1].reason}';
        return;
      }

      _invitations = _rows(answers[0]);
      _contracts = _rows(answers[1]);
      _places = answers[2].ok && answers[2].data is Map
          ? [
              for (final place in AssignmentOptions.fromJson(
                Map<String, dynamic>.from(answers[2].data as Map),
              ).scopes)
                if (place.isActive &&
                    (place.type == 'organization' || place.type == 'company'))
                  place,
            ]
          : const [];

      // One choice is not a choice.
      if (_contracts.length == 1) {
        _contractId = _contracts.first['id'] as String?;
      }
    });
  }

  static List<Map<String, dynamic>> _rows(ApiResponse response) =>
      response.ok && response.data is List
      ? [
          for (final row in (response.data as List).whereType<Map>())
            Map<String, dynamic>.from(row),
        ]
      : const [];

  /// Whether this person or invitation has one STILL RUNNING, as far as the
  /// list behind this panel knows.
  ///
  /// A finished one is deliberately not a reason to refuse: somebody rehired,
  /// or moving onto a different contract, gets a second onboarding and both are
  /// true. Only an unfinished one is a clash.
  ///
  /// "As far as it knows" is the honest limit, and the reason this only MARKS
  /// rather than refuses: the list is paginated, so a match is proof and a miss
  /// is not. The server is what actually refuses, and its refusal is what gets
  /// shown.
  bool _already(String? invitationId) =>
      invitationId != null &&
      widget.inFlight.any(
        (row) => row.invitationId == invitationId && row.status != 'cancelled',
      );

  bool get _complete => _invitationId != null && _contractId != null;

  Future<void> _submit() async {
    setState(() {
      _busy = true;
      _error = null;
    });

    final response = await widget.api.post('/hr/onboardings', {
      'invitation_id': _invitationId,
      'contract_id': _contractId,
      if (_under.isNotEmpty)
        'company_id': _under.startsWith('company:')
            ? _under.substring('company:'.length)
            : null,
    });

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _busy = false;
        _error = response.reason;
      });
      return;
    }

    final created = response.data is Map
        ? (response.data as Map)['id']?.toString()
        : null;

    Navigator.of(context).pop(created);
  }

  @override
  Widget build(BuildContext context) {
    return SidePanel(
      title: 'Start an onboarding',
      subtitle:
          'The contract decides what the form asks. The version it is on right '
          'now is pinned to this onboarding, so publishing a new one later '
          'will not change the questions this person is answering.',
      width: 520,
      actions: [
        TextButton(
          onPressed: _busy ? null : () => Navigator.of(context).pop(),
          child: const Text('Cancel'),
        ),
        FilledButton(
          onPressed: (_busy || !_complete) ? null : _submit,
          child: Text(_busy ? 'Starting…' : 'Start'),
        ),
      ],
      child: AsyncBody(
        loading: _loading,
        error: _loadError,
        onRetry: () {
          setState(() {
            _loading = true;
            _loadError = null;
          });
          _load();
        },
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            SearchPickerField<String>(
              value: _invitationId,
              label: 'Who is this for',
              border: const OutlineInputBorder(),
              helperMaxLines: 3,
              helperText:
                  'One of the invitations you have sent. They do not have to '
                  'have accepted it yet — you can fill in your half before '
                  'they arrive.',
              options: _subjects(),
              onChanged: _busy
                  ? null
                  : (v) => setState(() => _invitationId = v),
            ),
            if (_subjects().isEmpty) ...[
              const SizedBox(height: 12),
              const ErrorNote(
                'There is no invitation to start one against. An onboarding '
                'begins from the invitation somebody arrives on, so invite them '
                'first — that is the Invitations tab.',
              ),
            ],
            const SizedBox(height: 18),
            SearchPickerField<String>(
              value: _contractId,
              label: 'Contract',
              border: const OutlineInputBorder(),
              helperText: 'What they will be asked, and what they will sign.',
              options: [
                for (final contract in _contracts)
                  if (contract['id'] is String)
                    SearchOption(
                      value: contract['id'] as String,
                      label: [
                        contract['name'] as String? ?? '',
                        if (contract['version'] != null)
                          'version ${contract['version']}',
                      ].join(' · '),
                    ),
              ],
              onChanged: _busy ? null : (v) => setState(() => _contractId = v),
            ),
            if (_contracts.isEmpty) ...[
              const SizedBox(height: 12),
              const ErrorNote(
                'There are no contracts yet. A contract is what an onboarding '
                'asks, so one has to exist before anybody can be taken on — '
                'the Contracts tab is where they are made.',
              ),
            ],
            if (_places.isNotEmpty) ...[
              const SizedBox(height: 18),
              SearchPickerField<String>(
                key: const ValueKey('onboarding-under'),
                value: _under,
                label: 'Under',
                border: const OutlineInputBorder(),
                helperText:
                    'The employer the contract names. The paperwork is filed '
                    'there, and they are assigned there.',
                helperMaxLines: 3,
                options: [
                  const SearchOption(
                    value: '',
                    label: 'Where their invitation places them',
                  ),
                  for (final place in _places)
                    SearchOption(value: place.value, label: place.pathLabel),
                ],
                onChanged: _busy
                    ? null
                    : (v) => setState(() => _under = v ?? ''),
              ),
            ],
            if (_error != null) ...[
              const SizedBox(height: 18),
              ErrorNote(_error!),
            ],
          ],
        ),
      ),
    );
  }

  List<SearchOption<String>> _subjects() {
    String nameOf(Map<String, dynamic> row) => [
      row['first_name'],
      row['last_name'],
    ].whereType<String>().where((part) => part.trim().isNotEmpty).join(' ');

    return [
      for (final invitation in _invitations)
        // Revoked and abandoned are the two the server can never accept — one
        // was withdrawn and the other lapsed, so nobody is coming either way.
        // Dropped rather than shown and refused, unlike a role somebody may not
        // hand out: there the absence would hide a fact worth knowing, and here
        // the Invitations tab is already the place those rows are read.
        if (invitation['status'] != 'revoked' &&
            invitation['status'] != 'abandoned' &&
            invitation['id'] is String)
          SearchOption(
            value: invitation['id'] as String,
            enabled: !_already(invitation['id'] as String?),
            // One string rather than a name with the address as a second
            // line: two people can share a name, so the address is part of
            // WHICH invitation this is, and search has to match it.
            label: [
              nameOf(invitation).isEmpty
                  ? invitation['email'] as String? ?? ''
                  : '${nameOf(invitation)} (${invitation['email']})',
              if (_already(invitation['id'] as String?)) 'already onboarding',
            ].join(' — '),
          ),
    ];
  }
}
