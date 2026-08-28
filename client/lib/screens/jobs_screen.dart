import 'package:flutter/material.dart';
import '../core/api_client.dart';
import '../core/job_status.dart';
import '../core/session.dart';
import '../core/timestamps.dart';
import '../widgets/async_body.dart';
import '../widgets/calendar_day_field.dart';
import '../widgets/data_transfer.dart';
import '../widgets/error_note.dart';
import '../widgets/franchise_field.dart';
import '../widgets/refused.dart';
import '../widgets/side_panel.dart';
import '../widgets/tag.dart';
import 'jobs/job_detail_panel.dart';

/// Jobs (plan §5.2).
///
/// The opposite shape to customers: the server scopes this list to the caller's
/// reach, because jobs are never shared. A company-level user sees every
/// franchise beneath them — that is the roll-up, and it comes from the server so
/// the client never has to work out who can see what.
///
/// Exporting jobs is HERE rather than on a tab of its own (§12), the same move
/// Customers made. Only exporting: jobs are export-only on the server, because a
/// CSV of customer names cannot say WHICH "J. Smith" a job belongs to — see
/// ResourceMap. [DataTransfer] reads that from the catalogue and offers no
/// Import control, so this screen does not repeat the reasoning.
class JobsScreen extends StatefulWidget {
  const JobsScreen({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<JobsScreen> createState() => _JobsScreenState();
}

class _JobsScreenState extends State<JobsScreen> {
  final _search = TextEditingController();

  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _jobs = [];
  List<JobStatus> _statusChoices = const [];
  List<String> _statuses = const [];
  String? _status;
  // Discovered from the rows themselves rather than fetched, because every
  // job already carries its role (Task 5) and a catalogue call would only
  // duplicate data already on the page. Accumulated across loads: narrowing
  // to one role must not make the others disappear from the filter that
  // offered them.
  final Map<String, String> _roles = {};
  String? _roleId;
  int _total = 0;
  // Page one only, until asked. The header used to claim a total the list did
  // not render, with no next-page control and nothing saying records were
  // hidden — which reads as data loss rather than as pagination.
  int _page = 1;
  int _lastPage = 1;

  // See customers_screen for both of these: a failed next page must not blank
  // the rows already being read, and only one request may be in flight at a
  // time whichever page it is for. The second matters more than it used to —
  // a page-one refresh no longer hides the "Load more" control behind a
  // spinner, so page one (which replaces the list) and page four (which appends
  // to it) can now be asked for together.
  String? _pageError;
  bool _fetching = false;

  /// Which request is the current one.
  ///
  /// A page-one load must be allowed to SUPERSEDE another page-one load rather
  /// than be dropped. Refusing it outright — which a plain in-flight guard does
  /// — loses the newer intent permanently: clearing the search box while the
  /// search is still in flight left the box empty and the list still filtered,
  /// with nothing scheduled to correct it.
  ///
  /// So every load takes a ticket, and a response that is no longer holding the
  /// current one is discarded. That also fixes the out-of-order case, where the
  /// slower of two answers lands last and wins.
  int _request = 0;

  @override
  void initState() {
    super.initState();
    _loadStatuses();
    _load();
  }

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  Future<void> _loadStatuses() async {
    final organizationId = widget.session.me!.organization.id;
    final settings = await widget.api.get('/settings', {
      'scope_type': 'organization',
      'scope_id': organizationId,
    });

    if (!mounted || !settings.ok) return;

    final all = settings.data;
    final rows = (all is List)
        ? all
              .cast<Map<String, dynamic>>()
              .where((s) => s['key'] == 'jobs.statuses')
              .toList()
        : const <Map<String, dynamic>>[];
    final resolved = rows.isEmpty ? null : rows.first['value'];
    final parsed = parseJobStatuses(resolved);

    if (parsed.isNotEmpty) {
      setState(() {
        _statusChoices = parsed;
        _statuses = parsed.map((s) => s.value).toList();
      });
    }
  }

  Future<void> _load({int page = 1}) async {
    // Appends only. Two appends at once duplicate rows, and an append landing
    // during a page-one refresh appends to a list that is being replaced. A
    // page-one load is never refused — it supersedes instead, via _request.
    if (page > 1 && _fetching) return;

    final request = ++_request;

    setState(() {
      // A spinner only when there is nothing to keep — see AsyncBody. The
      // refetch after creating a job comes through here with rows on screen,
      // and they stay up until the new ones land.
      _loading = page == 1 && _jobs.isEmpty;
      _fetching = true;
      _error = null;
      _pageError = null;
    });

    final response = await widget.api.get('/jobs', {
      'page': '$page',
      if (_search.text.trim().isNotEmpty) 'search': _search.text.trim(),
      'status': ?_status,
      'assigned_role_id': ?_roleId,
    });

    // Superseded while this was in flight — a newer load owns the screen now,
    // and applying this answer would undo it.
    if (!mounted || request != _request) return;

    setState(() {
      _loading = false;
      _fetching = false;

      if (!response.ok) {
        // A first-page failure takes over the screen even when rows are still
        // in hand: they predate whatever the write did, and a refusal to
        // re-read them has to be said out loud rather than whispered.
        if (page == 1) {
          _error = response.message;
        } else {
          _pageError = response.message;
        }
        return;
      }

      final rows = List<Map<String, dynamic>>.from(response.data as List);
      _jobs = page == 1 ? rows : [..._jobs, ...rows];

      for (final row in rows) {
        final role = row['assigned_role'] as Map<String, dynamic>?;
        if (role != null) _roles[role['id'] as String] = role['name'] as String;
      }

      final meta = response.body['meta'] as Map<String, dynamic>?;
      _total = meta?['total'] as int? ?? _jobs.length;
      _lastPage = meta?['last_page'] as int? ?? 1;
      _page = page;
    });
  }

  @override
  Widget build(BuildContext context) {
    final me = widget.session.me!;

    // The permission first, because it is the one the button is named after.
    // Reach second, and stated as the ASSIGNMENT it is: telling somebody who
    // holds `jobs.create` that they lack permission sends them to ask for a
    // grant they already have. `franchises` carries reach rather than the whole
    // list — the server computes it — so this is not §4 reimplemented in Dart.
    //
    // Both sentences come from Me rather than from here, so this screen and the
    // 403 it would have got cannot come to describe the same refusal in two
    // different ways. They already had.
    final refused =
        me.refusal('jobs.create', 'add a job') ??
        (me.franchises.isEmpty ? me.nowhereToAct('add a job') : null);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        PanelHeader(
          title: 'Jobs',
          note: (_loading || _error != null)
              ? 'Everything you can reach.'
              : _jobs.length < _total
              ? 'Showing ${_jobs.length} of $_total across the '
                    '${me.organization.franchiseLabel.toLowerCase()}s you can reach.'
              : '$_total across the ${me.organization.franchiseLabel.toLowerCase()}s you can reach.',
          // A Wrap rather than a Row, for the reason customers_screen gives:
          // two controls side by side overflow a narrow window.
          action: Wrap(
            spacing: 8,
            runSpacing: 8,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              // No `onImported`: jobs are export-only, so nothing this widget
              // does can change the list behind it.
              DataTransfer(
                api: widget.api,
                session: widget.session,
                resource: 'jobs',
              ),
              Refused(
                // §4 refuses in two places and they have different remedies. The
                // screen used to test only the second, so somebody whose role
                // lacks `jobs.create` was offered the button and got a 403; and
                // the sentence it did have named "franchise" rather than
                // whatever this organization calls one (§8).
                reason: refused,
                child: FilledButton.icon(
                  onPressed: refused == null ? _create : null,
                  icon: const Icon(Icons.add, size: 18),
                  label: const Text('Add job'),
                ),
              ),
            ],
          ),
        ),
        Padding(
          padding: const EdgeInsets.fromLTRB(24, 0, 24, 12),
          child: Wrap(
            spacing: 12,
            runSpacing: 12,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              SizedBox(
                width: 320,
                child: TextField(
                  controller: _search,
                  decoration: InputDecoration(
                    prefixIcon: const Icon(Icons.search),
                    hintText: 'Job number, title or customer',
                    suffixIcon: _search.text.isEmpty
                        ? null
                        : IconButton(
                            icon: const Icon(Icons.clear),
                            tooltip: 'Clear the search',
                            onPressed: () {
                              _search.clear();
                              _load();
                            },
                          ),
                  ),
                  onSubmitted: (_) => _load(),
                  onChanged: (_) => setState(() {}),
                ),
              ),
              if (_statuses.isNotEmpty)
                DropdownMenu<String?>(
                  initialSelection: _status,
                  label: const Text('Status'),
                  dropdownMenuEntries: [
                    const DropdownMenuEntry(value: null, label: 'Any status'),
                    ..._statusChoices.map(
                      (s) => DropdownMenuEntry(value: s.value, label: s.label),
                    ),
                  ],
                  onSelected: (value) {
                    setState(() => _status = value);
                    _load();
                  },
                ),
              // The same control as status, for the same reason: it sits in
              // this Wrap rather than opening over the form, and its choices
              // are discovered from the rows already on screen rather than
              // fetched, since every job already carries its role (Task 5).
              if (_roles.isNotEmpty)
                DropdownMenu<String?>(
                  initialSelection: _roleId,
                  label: const Text('Role'),
                  dropdownMenuEntries: [
                    const DropdownMenuEntry(value: null, label: 'Any role'),
                    ..._roles.entries.map(
                      (role) => DropdownMenuEntry(
                        value: role.key,
                        label: role.value,
                      ),
                    ),
                  ],
                  onSelected: (value) {
                    setState(() => _roleId = value);
                    _load();
                  },
                ),
            ],
          ),
        ),
        Expanded(
          child: AsyncBody(
            loading: _loading,
            error: _error,
            onRetry: _load,
            child: _jobs.isEmpty
                ? Center(child: Text(_emptyCopy()))
                : ListView.separated(
                    padding: const EdgeInsets.fromLTRB(24, 0, 24, 24),
                    itemCount: _jobs.length + (_page < _lastPage ? 1 : 0),
                    separatorBuilder: (_, _) => const SizedBox(height: 8),
                    itemBuilder: (context, i) => i == _jobs.length
                        ? Center(
                            child: Column(
                              mainAxisSize: MainAxisSize.min,
                              children: [
                                if (_pageError != null) ...[
                                  ErrorNote(_pageError!),
                                  const SizedBox(height: 8),
                                ],
                                TextButton(
                                  onPressed: _fetching
                                      ? null
                                      : () => _load(page: _page + 1),
                                  child: Text(
                                    _fetching
                                        ? 'Loading…'
                                        : _pageError != null
                                        ? 'Try again'
                                        : 'Load more (${_total - _jobs.length} left)',
                                  ),
                                ),
                              ],
                            ),
                          )
                        : _row(context, _jobs[i]),
                  ),
          ),
        ),
      ],
    );
  }

  Widget _row(BuildContext context, Map<String, dynamic> job) {
    final customer = job['customer'] as Map<String, dynamic>;
    final franchise = job['franchise'] as Map<String, dynamic>;
    final amount = job['amount'] as Map<String, dynamic>;
    final ownedHere = customer['owned_by_this_franchise'] as bool? ?? true;

    return Card(
      child: ListTile(
        contentPadding: const EdgeInsets.symmetric(horizontal: 20, vertical: 8),
        onTap: () => _open(job),
        title: Row(
          children: [
            Flexible(
              child: Text(
                job['title'] as String,
                overflow: TextOverflow.ellipsis,
                style: Theme.of(context).textTheme.titleMedium,
              ),
            ),
            // Flexible for the same reason the role tag below is: the label is
            // organization-chosen text of no trusted length, and a Row hands a
            // non-flex child unbounded width.
            Flexible(
              child: Tag(
                job['status_label'] as String? ?? job['status'] as String,
              ),
            ),
            // Null for the common case at booking time — a job has no
            // performer yet and often no role either — so this must not draw
            // an empty tag for the row that names nobody (§12.1).
            //
            // Flexible for the reason customers_screen's owner tag is: a Row
            // hands a non-flex child unbounded width, and a role name is
            // organization-chosen text with no length limit worth trusting.
            if ((job['assigned_role']?['name'] as String?)?.isNotEmpty ?? false)
              Flexible(
                child: Tag(
                  job['assigned_role']['name'] as String,
                  key: const Key('job-role-tag'),
                ),
              ),
            // So it is obvious before anyone edits anything that this work is
            // for a customer another franchise is accountable for (§5.2).
            if (!ownedHere) const Tag('Shared customer', warn: true),
          ],
        ),
        subtitle: Padding(
          padding: const EdgeInsets.only(top: 4),
          child: Text(
            [
              job['number'],
              customer['name'] ?? '',
              franchise['name'] ?? '',
              if (job['scheduled_for'] != null)
                formatStamp(
                  job['scheduled_for'] as String,
                  '',
                  formats: widget.session.me!.display,
                ),
              if (amount['display'] != null)
                '${amount['currency']} ${amount['display']}',
            ].join(' · '),
            style: Theme.of(context).textTheme.bodyMedium,
          ),
        ),
      ),
    );
  }

  Future<void> _create() async {
    final created = await showSidePanel<bool>(
      context,
      builder: (_) => _NewJobPanel(api: widget.api, session: widget.session),
    );

    if (created == true && mounted) _load();
  }

  Future<void> _open(Map<String, dynamic> job) async {
    final changed = await showSidePanel<bool>(
      context,
      builder: (_) => JobDetailPanel(
        api: widget.api,
        session: widget.session,
        id: job['id'] as String,
      ),
    );

    if (changed == true && mounted) _load();
  }

  String _emptyCopy() {
    final term = _search.text.trim();

    if (term.isEmpty && _status == null && _roleId == null) {
      return 'No jobs yet.';
    }

    if (RegExp(r'^[A-Za-z][A-Za-z0-9]*-\d+(?:-\d+)?$').hasMatch(term)) {
      return 'Job not found.';
    }

    return 'Nothing matches "$term".';
  }
}

class _NewJobPanel extends StatefulWidget {
  const _NewJobPanel({required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<_NewJobPanel> createState() => _NewJobPanelState();
}

class _NewJobPanelState extends State<_NewJobPanel> {
  final _title = TextEditingController();
  final _description = TextEditingController();
  final _scheduled = TextEditingController();

  String? _franchiseId;
  String? _customerId;
  String _status = 'scheduled';

  List<Map<String, dynamic>> _customers = [];
  List<JobStatus> _statusChoices = const [
    JobStatus(value: 'scheduled', label: 'Scheduled'),
  ];
  List<String> _statuses = const ['scheduled'];

  bool _loading = true;
  bool _busy = false;
  String? _error;
  String? _customerSearchError;

  @override
  void initState() {
    super.initState();

    final reachable = widget.session.me!.franchisesInView;
    if (reachable.length == 1) _franchiseId = reachable.first.id;

    _loadOptions();
  }

  @override
  void dispose() {
    _title.dispose();
    _description.dispose();
    _scheduled.dispose();
    super.dispose();
  }

  /// Statuses come from the organization's settings, not from a constant here —
  /// an organization can rename them without a new build (§2.1).
  ///
  /// The endpoint needs a scope, and it answers with a LIST of setting objects
  /// rather than a map keyed by setting name. Getting either wrong used to fail
  /// silently: the dropdown quietly offered one status, and an organization that
  /// had removed 'scheduled' from its list could not create a job at all,
  /// because the client posted a status the server was right to reject.
  Future<void> _loadOptions() async {
    final organizationId = widget.session.me!.organization.id;

    final customers = await widget.api.get('/customers', {'per_page': '200'});
    final settings = await widget.api.get('/settings', {
      'scope_type': 'organization',
      'scope_id': organizationId,
    });

    if (!mounted) return;

    setState(() {
      _loading = false;

      if (!customers.ok) {
        _error = customers.message;
      } else {
        _customers = List<Map<String, dynamic>>.from(customers.data as List);
      }

      if (!settings.ok) {
        // Said out loud rather than degraded quietly. A create form offering the
        // wrong statuses is worse than one that admits it could not load them.
        _error ??=
            'Could not load this organization\'s job statuses: ${settings.message}';
        return;
      }

      final all = settings.data;
      final rows = (all is List)
          ? all
                .cast<Map<String, dynamic>>()
                .where((s) => s['key'] == 'jobs.statuses')
                .toList()
          : const <Map<String, dynamic>>[];
      final resolved = rows.isEmpty ? null : rows.first['value'];

      final parsed = parseJobStatuses(resolved);
      if (parsed.isNotEmpty) {
        _statusChoices = parsed;
        _statuses = parsed.map((s) => s.value).toList();
        _status = _statuses.first;
      }
    });
  }

  Future<void> _submit() async {
    setState(() {
      _busy = true;
      _error = null;
    });

    final scheduled = _scheduled.text.trim();

    final response = await widget.api.post('/jobs', {
      'franchise_id': _franchiseId,
      'customer_id': _customerId,
      'title': _title.text.trim(),
      'status': _status,
      if (_description.text.trim().isNotEmpty)
        'description': _description.text.trim(),
      if (scheduled.isNotEmpty) 'scheduled_for': scheduled,
    });

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _busy = false;
        _error = _firstError(response);
      });
      return;
    }

    Navigator.of(context).pop(true);
  }

  String _firstError(ApiResponse response) {
    final errors = response.body['errors'];

    if (errors is Map && errors.isNotEmpty) {
      final first = errors.values.first;
      if (first is List && first.isNotEmpty) return first.first.toString();
    }

    return response.message;
  }

  @override
  Widget build(BuildContext context) {
    final me = widget.session.me!;

    return SidePanel(
      title: 'Add job',
      // Wider than the default. The customer picker's suggestion list is 420
      // across and is drawn over everything, so in a 440 panel it would hang off
      // the right edge of the window.
      width: 520,
      actions: [
        TextButton(
          onPressed: _busy ? null : () => Navigator.of(context).pop(false),
          child: const Text('Cancel'),
        ),
        FilledButton(
          onPressed:
              (_busy || _loading || _franchiseId == null || _customerId == null)
              ? null
              : _submit,
          child: const Text('Create'),
        ),
      ],
      child: _loading
          ? const Padding(
              padding: EdgeInsets.all(32),
              child: Center(child: CircularProgressIndicator()),
            )
          : Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                FranchiseField(
                  me: me,
                  value: _franchiseId,
                  enabled: !_busy,
                  onChanged: (v) => setState(() => _franchiseId = v),
                ),
                const SizedBox(height: 14),
                // Searched, not listed. A dropdown of the first 200 by name
                // means that in a 900-customer organization everyone from
                // roughly 'D' onward simply cannot be picked, and no job can
                // be created for them at all — with nothing on screen saying
                // why. The endpoint already searches name, email, phone and
                // number, so this asks it.
                Autocomplete<Map<String, dynamic>>(
                  displayStringForOption: (c) =>
                      '${c['name']} · ${c['number']}',
                  optionsBuilder: (value) async {
                    if (value.text.trim().isEmpty) {
                      if (_customerSearchError != null && mounted) {
                        setState(() => _customerSearchError = null);
                      }
                      return _customers.take(50);
                    }

                    final response = await widget.api.get('/customers', {
                      'search': value.text.trim(),
                      'per_page': '50',
                    });

                    if (!response.ok) {
                      if (mounted) {
                        setState(() {
                          _customerSearchError =
                              'Could not search customers: ${response.message}';
                        });
                      }
                      return const <Map<String, dynamic>>[];
                    }

                    if (_customerSearchError != null && mounted) {
                      setState(() => _customerSearchError = null);
                    }

                    return List<Map<String, dynamic>>.from(
                      response.data as List,
                    );
                  },
                  onSelected: (c) =>
                      setState(() => _customerId = c['id'] as String),
                  fieldViewBuilder:
                      (context, controller, focusNode, onSubmit) => TextField(
                        controller: controller,
                        focusNode: focusNode,
                        enabled: !_busy,
                        decoration: InputDecoration(
                          labelText: 'Customer',
                          helperText: _customerId == null
                              ? 'Search by name, phone, email or number.'
                              : 'Any customer in the organization — doing work for '
                                    'another franchise\'s customer does not move them.',
                          helperMaxLines: 3,
                          suffixIcon: _customerId == null
                              ? const Icon(Icons.search)
                              : const Icon(Icons.check, size: 20),
                        ),
                        // Typing again after choosing means the choice is stale.
                        onChanged: (_) {
                          if (_customerId != null) {
                            setState(() => _customerId = null);
                          }
                        },
                      ),
                  optionsViewBuilder: (context, onSelected, options) => Align(
                    alignment: Alignment.topLeft,
                    child: Material(
                      elevation: 4,
                      child: ConstrainedBox(
                        constraints: const BoxConstraints(
                          maxHeight: 260,
                          maxWidth: 420,
                        ),
                        child: ListView(
                          padding: EdgeInsets.zero,
                          shrinkWrap: true,
                          children: options
                              .map(
                                (c) => ListTile(
                                  dense: true,
                                  title: Text(c['name'] as String),
                                  subtitle: Text(
                                    [
                                      c['number'],
                                      (c['owning_franchise'] as Map)['name'] ??
                                          '',
                                    ].join(' · '),
                                  ),
                                  onTap: () => onSelected(c),
                                ),
                              )
                              .toList(),
                        ),
                      ),
                    ),
                  ),
                ),
                const SizedBox(height: 14),
                if (_customerSearchError != null) ...[
                  const SizedBox(height: 8),
                  ErrorNote(_customerSearchError!),
                ],
                const SizedBox(height: 14),
                TextField(
                  controller: _title,
                  decoration: const InputDecoration(
                    labelText: 'What is the job',
                  ),
                  enabled: !_busy,
                ),
                const SizedBox(height: 14),
                CalendarDayField(
                  controller: _scheduled,
                  label: 'Scheduled for',
                  helper: 'Empty means not dated yet.',
                  formats: widget.session.me!.display,
                  withTime: true,
                  enabled: !_busy,
                ),
                const SizedBox(height: 14),
                DropdownButtonFormField<String>(
                  initialValue: _status,
                  decoration: const InputDecoration(labelText: 'Status'),
                  items: _statusChoices
                      .map(
                        (s) => DropdownMenuItem(
                          value: s.value,
                          child: Text(s.label),
                        ),
                      )
                      .toList(),
                  onChanged: _busy
                      ? null
                      : (v) => setState(() => _status = v ?? _status),
                ),
                const SizedBox(height: 14),
                TextField(
                  controller: _description,
                  decoration: const InputDecoration(labelText: 'Notes'),
                  minLines: 2,
                  maxLines: 5,
                  enabled: !_busy,
                ),
                if (_error != null) ...[
                  const SizedBox(height: 14),
                  ErrorNote(_error!),
                ],
              ],
            ),
    );
  }
}
