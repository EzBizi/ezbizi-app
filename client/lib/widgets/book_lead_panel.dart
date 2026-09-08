import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/job_status.dart';
import '../core/phone_numbers.dart';
import '../core/session.dart';
import 'calendar_day_field.dart';
import 'franchise_field.dart';
import 'lead_thread.dart';

/// Book a lead: a customer and a job, from the thread.
///
/// Approved booking layout 1 / 4 (docs/previews/leads-booking-layouts.html,
/// d1c69cb5). Who this person is comes first and visible, because it decides
/// everything below it.
///
/// ── Nothing is preselected when the number already matches ─────
///
/// Attaching a job to the WRONG existing customer puts a stranger's work in
/// somebody's history and looks exactly like getting it right; nobody ever
/// finds it. Creating a duplicate splits a history and shows up as two rows.
/// A shared line, a family, a landlord and a tenant all produce a genuine
/// last-ten-digits match between different people, which is why the server
/// calls the match advisory. So with matches, nothing is chosen for you and
/// Book stays disabled until somebody says who this is, with "Create a new
/// customer" as a PEER of the matches rather than a fallback beneath them.
/// With no match, nothing is asked: the decision exists only where the
/// ambiguity does.
///
/// The customer fields are prefilled FROM the lead and edit the customer
/// being created; the lead stays as Google sent it, because it is evidence.
/// A time here is a field on the job, the same one the New Job panel takes:
/// nothing reserved, nobody notified, so the label never says "book".
class BookLeadPanel extends StatefulWidget {
  const BookLeadPanel({
    super.key,
    required this.api,
    required this.session,
    required this.lead,
    required this.matches,
    required this.onBooked,
  });

  final ApiClient api;
  final Session session;
  final Map<String, dynamic> lead;

  /// `customer_matches` from GET /leads/{id}: customers whose last ten
  /// digits match the lead's number. Advisory.
  final List<Map<String, dynamic>> matches;

  /// The server's answer to the booking: lead, customer, customer_created,
  /// job. The caller closes the panel.
  final void Function(Map<String, dynamic> result) onBooked;

  @override
  State<BookLeadPanel> createState() => _BookLeadPanelState();
}

class _BookLeadPanelState extends State<BookLeadPanel> {
  static const _newCustomer = 'new';

  /// Null means the question has not been answered. Answered at once when
  /// there is nobody to confuse this person with.
  late String? _who = widget.matches.isEmpty ? _newCustomer : null;

  late final _name = TextEditingController(
    text: (widget.lead['contact_name'] as String?) ?? '',
  );
  late final _phone = TextEditingController(
    text: (widget.lead['phone'] as String?) ?? '',
  );
  late final _email = TextEditingController(
    text: (widget.lead['email'] as String?) ?? '',
  );
  final _address = TextEditingController();

  late String? _franchiseId =
      (widget.lead['franchise'] as Map?)?['id'] as String?;
  late final _title = TextEditingController(
    text: humaniseService(widget.lead['service'] as String?) ?? 'Job from lead',
  );
  final _scheduled = TextEditingController();

  List<JobStatus> _statuses = const [];
  String? _status;
  bool _busy = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _loadStatuses();
  }

  @override
  void dispose() {
    for (final c in [_name, _phone, _email, _address, _title, _scheduled]) {
      c.dispose();
    }
    super.dispose();
  }

  /// The tenant's own job statuses, never a hardcoded list. Same read the
  /// New Job panel makes.
  Future<void> _loadStatuses() async {
    final response = await widget.api.get('/settings', {
      'scope_type': 'organization',
      'scope_id': widget.session.me!.organization.id,
    });
    if (!mounted || !response.ok) return;

    final all = response.data;
    final rows = all is List
        ? all
              .cast<Map<String, dynamic>>()
              .where((s) => s['key'] == 'jobs.statuses')
              .toList()
        : const <Map<String, dynamic>>[];
    final parsed = parseJobStatuses(rows.isEmpty ? null : rows.first['value']);

    if (parsed.isNotEmpty) {
      setState(() {
        _statuses = parsed;
        _status ??= parsed.first.value;
      });
    }
  }

  bool get _ready =>
      !_busy &&
      _who != null &&
      _franchiseId != null &&
      _status != null &&
      _title.text.trim().isNotEmpty;

  Future<void> _book() async {
    setState(() {
      _busy = true;
      _error = null;
    });

    final scheduled = _scheduled.text.trim();
    final response = await widget.api.post('/leads/${widget.lead['id']}/book', {
      'franchise_id': _franchiseId,
      if (_who != _newCustomer) 'customer_id': _who,
      if (_who == _newCustomer)
        'customer': {
          'name': _name.text.trim(),
          'phone': _phone.text.trim(),
          'email': _email.text.trim(),
          'address_line1': _address.text.trim(),
        },
      'title': _title.text.trim(),
      'status': _status,
      if (scheduled.isNotEmpty) 'scheduled_for': scheduled,
    });
    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _busy = false;
        _error = response.message;
      });
      return;
    }

    widget.onBooked(response.data as Map<String, dynamic>? ?? const {});
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final me = widget.session.me!;
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: [
        if (widget.matches.isNotEmpty) ...[
          _whoBlock(theme),
          const SizedBox(height: 16),
        ],
        if (_who == null || _who == _newCustomer) ...[
          Text('The customer', style: theme.textTheme.titleSmall),
          const SizedBox(height: 8),
          TextField(
            key: const ValueKey('book-name'),
            controller: _name,
            enabled: !_busy,
            decoration: const InputDecoration(labelText: 'Name'),
            onChanged: (_) => setState(() {}),
          ),
          const SizedBox(height: 10),
          TextField(
            controller: _phone,
            enabled: !_busy,
            decoration: const InputDecoration(labelText: 'Phone'),
          ),
          const SizedBox(height: 10),
          TextField(
            controller: _email,
            enabled: !_busy,
            decoration: const InputDecoration(labelText: 'Email'),
          ),
          const SizedBox(height: 10),
          TextField(
            controller: _address,
            enabled: !_busy,
            decoration: const InputDecoration(labelText: 'Address'),
          ),
          const SizedBox(height: 6),
          Text(
            'Prefilled from the lead. Editing here changes the customer being '
            'created; the lead itself is left exactly as Google sent it.',
            style: muted,
          ),
          const SizedBox(height: 16),
        ],
        Text('The job', style: theme.textTheme.titleSmall),
        const SizedBox(height: 8),
        FranchiseField(
          me: me,
          value: _franchiseId,
          enabled: !_busy,
          onChanged: (v) => setState(() => _franchiseId = v),
        ),
        const SizedBox(height: 10),
        TextField(
          key: const ValueKey('book-title'),
          controller: _title,
          enabled: !_busy,
          decoration: const InputDecoration(labelText: 'Job title'),
          onChanged: (_) => setState(() {}),
        ),
        const SizedBox(height: 10),
        DropdownButtonFormField<String>(
          key: const ValueKey('book-status'),
          initialValue: _status,
          decoration: const InputDecoration(labelText: 'Status'),
          items: [
            for (final s in _statuses)
              DropdownMenuItem(value: s.value, child: Text(s.label)),
          ],
          onChanged: _busy ? null : (v) => setState(() => _status = v),
        ),
        const SizedBox(height: 10),
        CalendarDayField(
          controller: _scheduled,
          label: 'When the job is scheduled for (optional)',
          helper:
              'A time on the job, not an appointment: nothing is reserved and '
              'nobody is notified.',
          formats: me.display,
          withTime: true,
          enabled: !_busy,
        ),
        if (_error != null) ...[
          const SizedBox(height: 10),
          Text(
            _error!,
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.error,
            ),
          ),
        ],
        const SizedBox(height: 18),
        FilledButton.icon(
          key: const ValueKey('book-submit'),
          onPressed: _ready ? _book : null,
          icon: _busy
              ? const SizedBox(
                  width: 16,
                  height: 16,
                  child: CircularProgressIndicator(strokeWidth: 2),
                )
              : const Icon(Icons.event_available_outlined, size: 18),
          label: Text(_busy ? 'Booking…' : 'Book'),
        ),
      ],
    );
  }

  Widget _whoBlock(ThemeData theme) {
    final n = widget.matches.length;

    return Container(
      padding: const EdgeInsets.fromLTRB(12, 10, 12, 6),
      decoration: BoxDecoration(
        color: theme.colorScheme.secondaryContainer,
        borderRadius: BorderRadius.circular(10),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            n == 1
                ? 'A customer already has this number'
                : '$n customers already have this number',
            style: theme.textTheme.titleSmall?.copyWith(
              color: theme.colorScheme.onSecondaryContainer,
            ),
          ),
          const SizedBox(height: 2),
          Text(
            'Pick one, or make a new one. Nothing is chosen for you: attaching '
            'a job to the wrong person looks exactly like getting it right.',
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.onSecondaryContainer,
            ),
          ),
          const SizedBox(height: 4),
          RadioGroup<String>(
            groupValue: _who,
            onChanged: _busy ? (_) {} : (v) => setState(() => _who = v),
            child: Column(
              children: [
                for (final m in widget.matches)
                  RadioListTile<String>(
                    key: ValueKey('book-match-${m['id']}'),
                    value: m['id'] as String,
                    dense: true,
                    contentPadding: EdgeInsets.zero,
                    title: Text(m['name'] as String? ?? 'Customer'),
                    subtitle: Text(
                      [
                        ?(m['number'] as String?),
                        if ((m['phone'] as String?)?.isNotEmpty == true)
                          formatPhoneNumber(m['phone'] as String?),
                      ].join(' · '),
                    ),
                  ),
                RadioListTile<String>(
                  key: const ValueKey('book-match-new'),
                  value: _newCustomer,
                  dense: true,
                  contentPadding: EdgeInsets.zero,
                  title: const Text('Create a new customer'),
                  subtitle: const Text('From this lead'),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

/// "Thu 17 Sep, 2 PM" from an ISO stamp, in the reader's clock. Empty when
/// there is no time.
String formatJobWhen(String? iso) {
  final at = iso == null ? null : DateTime.tryParse(iso)?.toLocal();
  if (at == null) return '';
  const days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
  const months = [
    'Jan',
    'Feb',
    'Mar',
    'Apr',
    'May',
    'Jun',
    'Jul',
    'Aug',
    'Sep',
    'Oct',
    'Nov',
    'Dec',
  ];
  final h12 = at.hour % 12 == 0 ? 12 : at.hour % 12;
  final minutes = at.minute == 0
      ? ''
      : ':${at.minute.toString().padLeft(2, '0')}';
  final ampm = at.hour < 12 ? 'AM' : 'PM';
  return '${days[at.weekday - 1]} ${at.day} ${months[at.month - 1]}, '
      '$h12$minutes $ampm';
}
