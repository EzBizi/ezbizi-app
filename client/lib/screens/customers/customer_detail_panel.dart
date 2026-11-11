import '../../core/phone_numbers.dart';
import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/job_status.dart';
import '../../core/numbers.dart';
import '../../core/session.dart';
import '../../core/timestamps.dart';
import '../../widgets/address_fields.dart';
import '../../widgets/error_note.dart';
import '../../widgets/franchise_field.dart';
import '../../widgets/refused.dart';
import '../../widgets/book_job_panel.dart';
import '../../widgets/side_panel.dart';
import '../../core/theme.dart';
import '../../widgets/status_pill.dart';
import '../../widgets/tab_bar_like.dart';
import '../../widgets/tag.dart';
import '../jobs/job_detail_panel.dart';
import 'customer_tags.dart';
import 'customer_workiz.dart';

/// One customer, opened from the list.
///
/// The list is not a dead end. Detail, edit, deactivate, delete and moving the
/// owner all live here — the API already did them; this is the missing face.
/// Reassignment is a separate act (§5.2), not a field on the ordinary save.
class CustomerDetailPanel extends StatefulWidget {
  const CustomerDetailPanel({
    super.key,
    required this.api,
    required this.session,
    required this.id,
    this.onDone,
    this.dirty,
    this.knownTags = const [],
  });

  final ApiClient api;
  final Session session;
  final String id;

  /// Set when the panel is DOCKED beside the customers list: called where it
  /// would otherwise pop its route — true after a change, false on close.
  final ValueChanged<bool>? onDone;

  /// True while something typed here is unsaved, so the list beside a docked
  /// panel can refuse to switch customers underneath it. See JobDetailPanel.
  final ValueNotifier<bool>? dirty;

  /// Tags already used on the list beside this panel, offered as you type.
  final Iterable<String> knownTags;

  @override
  State<CustomerDetailPanel> createState() => _CustomerDetailPanelState();
}

class _CustomerDetailPanelState extends State<CustomerDetailPanel> {
  /// Customer, Money or Notes from Workiz (UI queue 59; Jose: "as u
  /// recommend").
  String _tab = 'customer';

  final _name = TextEditingController();
  final _phone = TextEditingController();
  final _email = TextEditingController();
  final _line1 = TextEditingController();
  final _line2 = TextEditingController();
  final _city = TextEditingController();
  final _state = TextEditingController();
  final _postal = TextEditingController();
  final _notes = TextEditingController();
  final _reason = TextEditingController();

  Map<String, dynamic>? _row;
  bool _loading = true;
  bool _busy = false;
  bool _reassigning = false;
  String? _error;
  String? _newOwnerId;

  Me get _me => widget.session.me!;

  String? get _editRefusal => _me.refusal('customers.edit', 'change a customer');
  String? get _deleteRefusal =>
      _me.refusal('customers.delete', 'delete a customer');

  /// The fields as loaded, to tell an edit from the record as it stands.
  String _loaded = '';

  List<TextEditingController> get _fields => [
    _name,
    _phone,
    _email,
    _line1,
    _line2,
    _city,
    _state,
    _postal,
    _notes,
  ];

  String get _typed => _fields.map((c) => c.text).join('\u0000');

  void _markDirty() => widget.dirty?.value = !_loading && _typed != _loaded;

  @override
  void initState() {
    super.initState();
    for (final c in _fields) {
      c.addListener(_markDirty);
    }
    _load();
  }

  /// A tag saved on its own, so a plain close still refreshes the list.
  bool _changed = false;

  void _done(bool? changed) {
    changed ??= _changed ? true : null;
    final onDone = widget.onDone;
    if (onDone != null) {
      onDone(changed ?? false);
      return;
    }

    Navigator.of(context).pop(changed);
  }

  @override
  void dispose() {
    _name.dispose();
    _phone.dispose();
    _email.dispose();
    _line1.dispose();
    _line2.dispose();
    _city.dispose();
    _state.dispose();
    _postal.dispose();
    _notes.dispose();
    _reason.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });

    // The reader's own date: past due is worked out against it, and the
    // server's is UTC, already tomorrow every US evening.
    final response = await widget.api.get('/customers/${widget.id}', {
      'today': formatCalendarDay(DateTime.now()),
    });

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _loading = false;
        _error = response.message;
      });
      return;
    }

    final row = Map<String, dynamic>.from(response.data as Map);
    _fill(row);

    setState(() {
      _row = row;
      _loading = false;
    });

    _loaded = _typed;
    widget.dirty?.value = false;
  }

  void _fill(Map<String, dynamic> row) {
    _name.text = row['name'] as String? ?? '';
    // Loaded formatted, saved back through toE164 — the field never shows
    // the stored form and never sends the pretty one.
    _phone.text = formatPhoneNumber(row['phone'] as String?);
    _email.text = row['email'] as String? ?? '';
    _notes.text = row['notes'] as String? ?? '';

    final address = row['address'] as Map<String, dynamic>? ?? const {};
    _line1.text = address['line1'] as String? ?? '';
    _line2.text = address['line2'] as String? ?? '';
    _city.text = address['city'] as String? ?? '';
    _state.text = address['state'] as String? ?? '';
    _postal.text = address['postal_code'] as String? ?? '';
  }

  Future<void> _save({bool? active}) async {
    setState(() {
      _busy = true;
      _error = null;
    });

    final response = await widget.api.patch('/customers/${widget.id}', {
      'name': _name.text.trim(),
      // Stored as E.164, never as the pretty form the field is showing.
      'phone': toE164(_phone.text),
      'email': _email.text.trim().isEmpty ? null : _email.text.trim(),
      'address_line1': _line1.text.trim().isEmpty ? null : _line1.text.trim(),
      'address_line2': _line2.text.trim().isEmpty ? null : _line2.text.trim(),
      'city': _city.text.trim().isEmpty ? null : _city.text.trim(),
      'state': _state.text.trim().isEmpty ? null : _state.text.trim().toUpperCase(),
      'postal_code': _postal.text.trim().isEmpty ? null : _postal.text.trim(),
      'notes': _notes.text.trim().isEmpty ? null : _notes.text.trim(),
      'is_active': ?active,
    });

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _busy = false;
        _error = response.reason;
      });
      return;
    }

    final row = Map<String, dynamic>.from(response.data as Map);
    // A save answers with the customer, not its money: keep what was shown.
    if (!row.containsKey('money')) row['money'] = _row?['money'];
    _fill(row);

    setState(() {
      _row = row;
      _busy = false;
    });

    _done(true);
  }

  /// "No marketing email" saves on its own, at once.
  Future<void> _saveOptOut(bool on) async {
    final response = await widget.api.patch('/customers/${widget.id}', {
      'email_opt_out': on,
    });
    if (!mounted) return;
    if (!response.ok) {
      setState(() => _error = response.reason);
      return;
    }
    final saved = (response.data as Map?)?['email_opt_out'] ?? on;
    setState(() => _row = {...?_row, 'email_opt_out': saved});
    _changed = true;
  }

  /// Tags save on their own, at once, with the whole list (UI queue 174).
  Future<bool> _saveTags(List<String> tags) async {
    final response =
        await widget.api.patch('/customers/${widget.id}', {'tags': tags});
    if (!mounted) return false;
    if (!response.ok) {
      setState(() => _error = response.reason);
      return false;
    }
    final saved = (response.data as Map?)?['tags'] ?? tags;
    setState(() => _row = {...?_row, 'tags': saved});
    _changed = true;
    return true;
  }

  Future<void> _reassign() async {
    setState(() {
      _busy = true;
      _error = null;
    });

    final response = await widget.api.post('/customers/${widget.id}/owner', {
      'owning_franchise_id': _newOwnerId,
      if (_reason.text.trim().isNotEmpty) 'reason': _reason.text.trim(),
    });

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _busy = false;
        _error = response.reason;
      });
      return;
    }

    _done(true);
  }

  Future<void> _delete() async {
    setState(() {
      _busy = true;
      _error = null;
    });

    final response = await widget.api.delete('/customers/${widget.id}');

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _busy = false;
        _error = response.reason;
      });
      return;
    }

    _done(true);
  }

  @override
  Widget build(BuildContext context) {
    final row = _row;
    final owner = row?['owning_franchise'] as Map<String, dynamic>?;
    final active = row?['is_active'] as bool? ?? true;
    final jobs = row?['jobs_count'] as int? ?? 0;
    final editable = _editRefusal == null && !_busy && !_loading;

    return SidePanel(
      title: row == null ? 'Customer' : row['name'] as String,
      subtitle: row == null
          ? null
          : [
              row['number'],
              if (workizClientNumbers(row) case final workiz?) 'Workiz $workiz',
              // The company as well as the franchise, as on the list row: two
              // companies may each run a "Texas", and this subtitle is what
              // somebody reads before editing or reassigning the customer.
              // The list said which Texas; the panel it opens must not stop.
              (owner?['company'] as Map<String, dynamic>?)?['name'],
              owner?['name'],
            ].where((s) => s != null && '$s'.isNotEmpty).join(' · '),
      width: 520,
      docked: widget.onDone != null,
      onClose: widget.onDone == null ? null : () => _done(null),
      actions: [
        if (row != null && widget.session.me!.can('jobs.create'))
          TextButton.icon(
            key: const ValueKey('customer-book-job'),
            onPressed: _busy
                ? null
                : () => showBookJobPanel(
                    context,
                    api: widget.api,
                    session: widget.session,
                    prefill: BookPrefill(
                      customerId: row['id'] as String,
                      franchiseId: owner?['id'] as String?,
                    ),
                  ),
            icon: const Icon(Icons.event_available_outlined, size: 18),
            label: const Text('Book a job'),
          ),
        // Save and Cancel belong to the Customer tab's fields; Money and the
        // Workiz notes are read only (deep dive 2026-11-02).
        if (_tab != 'money' && _tab != 'notes') ...[
          TextButton(
            onPressed: _busy ? null : () => _done(null),
            child: const Text('Cancel'),
          ),
          Refused(
            reason: _editRefusal,
            child: FilledButton(
              onPressed: editable ? () => _save() : null,
              child: const Text('Save'),
            ),
          ),
        ],
      ],
      child: _loading
          ? const Padding(
              padding: EdgeInsets.all(32),
              child: Center(child: CircularProgressIndicator()),
            )
          : _tabbed(context, row, owner, active, jobs, editable),
    );
  }

  /// The tabs, when there is more than the customer to show: Money for a
  /// reader with Financials whom Workiz has figures for, Notes from Workiz for
  /// a customer linked to a Workiz client. Otherwise today's single page.
  Widget _tabbed(
    BuildContext context,
    Map<String, dynamic>? row,
    Map<String, dynamic>? owner,
    bool active,
    int jobs,
    bool editable,
  ) {
    final money = CustomerMoney.of(row?['money']);
    final linked = row != null && workizClientNumbers(row) != null;
    final tabs = <(String, String, IconData, Tone?)>[
      ('customer', 'Customer', Icons.person_outline, null),
      if (money != null)
        money.late
            ? (
                'money',
                'Money · ${money.money(money.pastDue!)} past due',
                Icons.payments_outlined,
                Tone.bad,
              )
            : ('money', 'Money', Icons.payments_outlined, null),
      if (linked) ('notes', 'Notes from Workiz', Icons.sticky_note_2_outlined, null),
    ];
    final current = tabs.any((t) => t.$1 == _tab) ? _tab : 'customer';

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (tabs.length > 1)
          Padding(
            padding: const EdgeInsets.only(bottom: 14),
            child: TabBarLike(
              key: const Key('customer-tabs'),
              labels: [for (final t in tabs) (t.$2, t.$3)],
              tones: [for (final t in tabs) t.$4],
              selected: tabs.indexWhere((t) => t.$1 == current),
              onSelected: (i) => setState(() => _tab = tabs[i].$1),
            ),
          ),
        if (current == 'money' && money != null)
          CustomerMoneyCard(money: money)
        else if (current == 'notes')
          WorkizNotes(
            api: widget.api,
            customerId: widget.id,
            formats: _me.display,
          )
        else
          _customerPage(context, row, owner, active, jobs, editable),
      ],
    );
  }

  Widget _customerPage(
    BuildContext context,
    Map<String, dynamic>? row,
    Map<String, dynamic>? owner,
    bool active,
    int jobs,
    bool editable,
  ) {
    return Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                if (row != null)
                  Padding(
                    padding: const EdgeInsets.only(bottom: 14),
                    child: Wrap(
                      spacing: 8,
                      runSpacing: 8,
                      children: [
                        Tag(owner?['name'] as String? ?? 'Unassigned'),
                        if (!active) const Tag('Inactive', warn: true),
                      ],
                    ),
                  ),
                if (row != null)
                  Padding(
                    padding: const EdgeInsets.only(bottom: 14),
                    child: CustomerTagsEditor(
                      tags: customerTags(row),
                      suggestions: widget.knownTags,
                      enabled: _editRefusal == null && !_loading,
                      onChanged: _saveTags,
                    ),
                  ),
                TextField(
                  controller: _name,
                  decoration: const InputDecoration(labelText: 'Name'),
                  enabled: editable,
                ),
                const SizedBox(height: 12),
                TextField(
                  controller: _phone,
                  decoration: const InputDecoration(labelText: 'Phone'),
                  keyboardType: TextInputType.phone,
                  inputFormatters: const [PhoneNumberInputFormatter()],
                  enabled: editable,
                ),
                const SizedBox(height: 12),
                TextField(
                  controller: _email,
                  decoration: const InputDecoration(labelText: 'Email'),
                  keyboardType: TextInputType.emailAddress,
                  enabled: editable,
                ),
                // Newsletters skip this customer (customers.email_opt_out_at).
                // Saves at once, like the tags, behind customers.edit.
                if (row != null)
                  SwitchListTile(
                    key: const ValueKey('customer-email-opt-out'),
                    contentPadding: EdgeInsets.zero,
                    title: const Text('No marketing email'),
                    subtitle: const Text('Newsletters are not sent to them.'),
                    value: row['email_opt_out'] == true,
                    onChanged: _editRefusal == null && !_loading
                        ? _saveOptOut
                        : null,
                  ),
                const SizedBox(height: 12),
                // Found with the lookup, kept in parts (Jose, 2026-09-30).
                AddressFields(
                  api: widget.api,
                  line1: _line1,
                  line2: _line2,
                  city: _city,
                  state: _state,
                  postal: _postal,
                  enabled: editable,
                  dense: false,
                ),
                const SizedBox(height: 12),
                TextField(
                  controller: _notes,
                  decoration: const InputDecoration(labelText: 'Notes'),
                  minLines: 2,
                  maxLines: 5,
                  enabled: editable,
                ),
                const SizedBox(height: 20),
                if (row != null && _me.can('jobs.view')) ...[
                  _CustomerJobs(
                    api: widget.api,
                    session: widget.session,
                    customerId: widget.id,
                    total: jobs,
                  ),
                  const SizedBox(height: 20),
                ],
                Text(
                  'Accountability',
                  style: Theme.of(context).textTheme.titleSmall,
                ),
                const SizedBox(height: 8),
                Text(
                  'Moving who is accountable is a separate, audited step — not '
                  'something an ordinary save can do by accident.',
                  style: Theme.of(context).textTheme.bodySmall,
                ),
                const SizedBox(height: 12),
                if (!_reassigning)
                  Align(
                    alignment: Alignment.centerLeft,
                    child: Refused(
                      reason: _editRefusal,
                      child: OutlinedButton(
                        onPressed: editable
                            ? () => setState(() => _reassigning = true)
                            : null,
                        child: const Text('Reassign owner'),
                      ),
                    ),
                  )
                else ...[
                  FranchiseField(
                    me: _me,
                    value: _newOwnerId,
                    label: 'New owning ${_me.organization.franchiseLabel}',
                    enabled: !_busy,
                    onChanged: (v) => setState(() => _newOwnerId = v),
                  ),
                  const SizedBox(height: 12),
                  TextField(
                    controller: _reason,
                    decoration: const InputDecoration(
                      labelText: 'Reason',
                      helperText: 'Optional. Kept on the audit record.',
                    ),
                    enabled: !_busy,
                  ),
                  const SizedBox(height: 12),
                  Wrap(
                    spacing: 8,
                    runSpacing: 8,
                    children: [
                      OutlinedButton(
                        onPressed: _busy
                            ? null
                            : () => setState(() {
                                _reassigning = false;
                                _newOwnerId = null;
                              }),
                        child: const Text('Cancel'),
                      ),
                      FilledButton(
                        onPressed: (_busy || _newOwnerId == null)
                            ? null
                            : _reassign,
                        child: const Text('Hand over'),
                      ),
                    ],
                  ),
                ],
                const SizedBox(height: 20),
                Text(
                  'This record',
                  style: Theme.of(context).textTheme.titleSmall,
                ),
                const SizedBox(height: 8),
                Wrap(
                  spacing: 8,
                  runSpacing: 8,
                  children: [
                    Refused(
                      reason: _editRefusal,
                      child: OutlinedButton(
                        onPressed: editable
                            ? () => _save(active: !active)
                            : null,
                        child: Text(active ? 'Deactivate' : 'Reactivate'),
                      ),
                    ),
                    Refused(
                      reason: _deleteRefusal,
                      child: OutlinedButton(
                        // Red: it destroys the record (phone audit 2026-11-04).
                        style: OutlinedButton.styleFrom(
                          foregroundColor: Theme.of(context).colorScheme.error,
                        ),
                        onPressed:
                            (_deleteRefusal == null && !_busy && !_loading)
                            ? _delete
                            : null,
                        child: const Text('Delete'),
                      ),
                    ),
                  ],
                ),
                if (jobs > 0) ...[
                  const SizedBox(height: 8),
                  Text(
                    'This customer has $jobs ${jobs == 1 ? 'job' : 'jobs'}. '
                    'Deleting them would leave that work with no one attached '
                    'to it — deactivate instead.',
                    style: Theme.of(context).textTheme.bodySmall,
                  ),
                ],
                if (_error != null) ...[
                  const SizedBox(height: 14),
                  ErrorNote(_error!),
                ],
              ],
            );
  }
}

/// The customer's jobs, newest first — the history the list promised.
///
/// Jose chose the record beside the list (queue 24, option 7), and the drawing
/// he chose lists a customer's jobs under their details. Until then the only
/// trace was a count on the row: "3 jobs", and no way to see which.
///
/// Read through the JOBS list, not a new endpoint: `customer_id` is a filter it
/// already had, so reach applies exactly as it does on the Jobs screen. A
/// customer is shared across the organization and their jobs are not, so a
/// reader can see fewer jobs than the customer has — and says so, rather than
/// showing two and letting "3 jobs" elsewhere look like a miscount.
class _CustomerJobs extends StatefulWidget {
  const _CustomerJobs({
    required this.api,
    required this.session,
    required this.customerId,
    required this.total,
  });

  final ApiClient api;
  final Session session;
  final String customerId;

  /// `jobs_count` on the customer: every job, whoever may open it.
  final int total;

  @override
  State<_CustomerJobs> createState() => _CustomerJobsState();
}

class _CustomerJobsState extends State<_CustomerJobs> {
  List<Map<String, dynamic>>? _jobs;
  int _visible = 0;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final response = await widget.api.get('/jobs', {
      'customer_id': widget.customerId,
      'per_page': '50',
    });

    if (!mounted) return;

    setState(() {
      if (!response.ok) {
        _error = response.message;
        return;
      }

      _jobs = List<Map<String, dynamic>>.from(response.data as List);
      final meta = response.body['meta'] as Map<String, dynamic>?;
      _visible = meta?['total'] as int? ?? _jobs!.length;
    });
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    final jobs = _jobs;
    final hidden = widget.total - _visible;
    final place = widget.session.me!.organization.franchiseLabel.toLowerCase();

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text('Jobs', style: theme.textTheme.titleSmall),
        const SizedBox(height: 8),
        if (_error != null)
          ErrorNote(_error!)
        else if (jobs == null)
          const LinearProgressIndicator(minHeight: 2)
        else ...[
          if (jobs.isEmpty && hidden <= 0) Text('No jobs yet.', style: muted),
          for (final job in jobs) _row(context, job),
          if (_visible > jobs.length)
            Padding(
              padding: const EdgeInsets.only(top: 6),
              child: Text(
                'The latest ${jobs.length} of $_visible.',
                style: muted,
              ),
            ),
          if (hidden > 0)
            Padding(
              padding: const EdgeInsets.only(top: 6),
              child: Text(
                hidden == 1
                    ? '1 more, in a $place you cannot open.'
                    : '$hidden more, in ${place}s you cannot open.',
                style: muted,
              ),
            ),
        ],
      ],
    );
  }

  Widget _row(BuildContext context, Map<String, dynamic> job) {
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    final wall = parseWallDateTime(job['scheduled_for'] as String?);
    final when = wall == null
        ? 'Not scheduled'
        : widget.session.me!.display.formatDate(wall.day);
    final status = job['status'] as String? ?? '';

    return InkWell(
      onTap: () => showSidePanel<bool>(
        context,
        builder: (_) => JobDetailPanel(
          api: widget.api,
          session: widget.session,
          id: job['id'] as String,
        ),
      ),
      child: Container(
        padding: const EdgeInsets.symmetric(vertical: 8),
        decoration: BoxDecoration(
          border: Border(
            bottom: BorderSide(
              color: theme.colorScheme.outlineVariant,
              width: 0.5,
            ),
          ),
        ),
        child: Row(
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    job['title'] as String? ?? '',
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: theme.textTheme.bodyMedium?.copyWith(
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                  Text(
                    '${job['number']} · $when',
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: muted,
                  ),
                ],
              ),
            ),
            const SizedBox(width: 8),
            Flexible(
              child: StatusPill(
                job['status_label'] as String? ?? status,
                tone: toneForJobStatus(status),
              ),
            ),
            SizedBox(
              width: 84,
              child: Text(
                listAmount(job['amount']) ?? '—',
                textAlign: TextAlign.right,
                style: theme.textTheme.bodyMedium,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// A customer's Workiz client numbers, "#4512, #4600", or null when Workiz
/// never had them. Workiz's ids beside EzBizi's (Jose, 2026-09-29), shown on
/// the list and here.
String? workizClientNumbers(Map<String, dynamic> customer) {
  final ids = customer['workiz_client_ids'] as List? ?? const [];

  return ids.isEmpty ? null : ids.map((id) => '#$id').join(', ');
}
