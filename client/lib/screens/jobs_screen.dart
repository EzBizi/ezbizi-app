import 'package:flutter/material.dart';
import '../core/api_client.dart';
import '../core/money.dart';
import '../core/session.dart';
import '../core/timestamps.dart';
import '../widgets/async_body.dart';
import '../widgets/error_note.dart';
import '../widgets/franchise_field.dart';
import '../widgets/refused.dart';
import '../widgets/side_panel.dart';
import '../widgets/tag.dart';

/// Jobs (plan §5.2).
///
/// The opposite shape to customers: the server scopes this list to the caller's
/// reach, because jobs are never shared. A company-level user sees every
/// franchise beneath them — that is the roll-up, and it comes from the server so
/// the client never has to work out who can see what.
class JobsScreen extends StatefulWidget {
  const JobsScreen({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<JobsScreen> createState() => _JobsScreenState();
}

class _JobsScreenState extends State<JobsScreen> {
  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _jobs = [];
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
    _load();
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

    final response = await widget.api.get('/jobs', {'page': '$page'});

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

      final meta = response.body['meta'] as Map<String, dynamic>?;
      _total = meta?['total'] as int? ?? _jobs.length;
      _lastPage = meta?['last_page'] as int? ?? 1;
      _page = page;
    });
  }

  @override
  Widget build(BuildContext context) {
    final me = widget.session.me!;
    final place = me.organization.franchiseLabel.toLowerCase();

    // The permission first, because it is the one the button is named after.
    // Reach second, and stated as the ASSIGNMENT it is: telling somebody who
    // holds `jobs.create` that they lack permission sends them to ask for a
    // grant they already have. `franchises` carries reach rather than the whole
    // list — the server computes it — so this is not §4 reimplemented in Dart.
    final refused =
        me.refusal('jobs.create', 'add a job') ??
        (me.franchises.isEmpty
            ? 'You have permission to add a job, but you are not assigned to any '
                  '$place, so there is nowhere to put one. An administrator can '
                  'assign you to a $place.'
            : null);

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
          action: Refused(
            // §4 refuses in two places and they have different remedies. The
            // screen used to test only the second, so somebody whose role
            // lacks `jobs.create` was offered the button and got a 403; and the
            // sentence it did have named "franchise" rather than whatever this
            // organization calls one (§8).
            reason: refused,
            child: FilledButton.icon(
              onPressed: refused == null ? _create : null,
              icon: const Icon(Icons.add, size: 18),
              label: const Text('Add job'),
            ),
          ),
        ),
        Expanded(
          child: AsyncBody(
            loading: _loading,
            error: _error,
            onRetry: _load,
            child: _jobs.isEmpty
                ? const Center(child: Text('No jobs yet.'))
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
        title: Row(
          children: [
            Flexible(
              child: Text(
                job['title'] as String,
                overflow: TextOverflow.ellipsis,
                style: Theme.of(context).textTheme.titleMedium,
              ),
            ),
            Tag(job['status'] as String),
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
  final _amount = TextEditingController();

  String? _franchiseId;
  String? _customerId;
  String _status = 'scheduled';

  List<Map<String, dynamic>> _customers = [];
  List<String> _statuses = const ['scheduled'];

  bool _loading = true;
  bool _busy = false;
  String? _error;

  @override
  void initState() {
    super.initState();

    final reachable = widget.session.me!.franchises;
    if (reachable.length == 1) _franchiseId = reachable.first.id;

    _loadOptions();
  }

  @override
  void dispose() {
    _title.dispose();
    _amount.dispose();
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

      if (resolved is List && resolved.isNotEmpty) {
        _statuses = resolved.map((s) => s.toString()).toList();
        _status = _statuses.first;
      }
    });
  }

  Future<void> _submit() async {
    setState(() {
      _busy = true;
      _error = null;
    });

    // The conversion lives in core/money.dart, where it is tested. What matters
    // here is that a price it cannot read is SAID rather than dropped: the
    // amount used to be posted with a null-aware element, so a typo created the
    // job with no price at all and no complaint, and the first anyone knew was
    // an invoice that came to nothing.
    final int? cents;
    try {
      cents = parseAmountMinor(_amount.text);
    } on FormatException catch (e) {
      setState(() {
        _busy = false;
        _error = e.message;
      });
      return;
    }

    final response = await widget.api.post('/jobs', {
      'franchise_id': _franchiseId,
      'customer_id': _customerId,
      'title': _title.text.trim(),
      'status': _status,
      // Minor units, always — money never travels as a float (§7). Null here
      // means the field was left empty, which is a job booked before anyone
      // quoted it; an amount that could not be read never reaches this line.
      'amount_minor': ?cents,
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
                    if (value.text.trim().isEmpty) return _customers.take(50);

                    final response = await widget.api.get('/customers', {
                      'search': value.text.trim(),
                      'per_page': '50',
                    });

                    if (!response.ok) return const <Map<String, dynamic>>[];

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
                TextField(
                  controller: _title,
                  decoration: const InputDecoration(
                    labelText: 'What is the job',
                  ),
                  enabled: !_busy,
                ),
                const SizedBox(height: 14),
                DropdownButtonFormField<String>(
                  initialValue: _status,
                  decoration: const InputDecoration(labelText: 'Status'),
                  items: _statuses
                      .map((s) => DropdownMenuItem(value: s, child: Text(s)))
                      .toList(),
                  onChanged: _busy
                      ? null
                      : (v) => setState(() => _status = v ?? _status),
                ),
                const SizedBox(height: 14),
                TextField(
                  controller: _amount,
                  decoration: const InputDecoration(
                    labelText: 'Price',
                    helperText:
                        'In dollars — 199.99 for \$199.99. Stored as '
                        'whole cents so it cannot drift.',
                    helperMaxLines: 2,
                  ),
                  // Dollars have a decimal point, so the on-screen keyboard has
                  // to offer one. Plain .number hides it on iOS, which would
                  // leave a phone user unable to type anything but whole dollars.
                  keyboardType: const TextInputType.numberWithOptions(
                    decimal: true,
                  ),
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
