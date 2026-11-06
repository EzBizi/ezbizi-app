import 'dart:async';

import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/phone_numbers.dart';
import '../core/session.dart';
import '../core/theme.dart';
import '../core/timestamps.dart';
import '../screens/scheduling/schedule_pane.dart';
import 'address_fields.dart';
import 'calendar_day_field.dart';
import 'franchise_field.dart';
import 'lead_thread.dart' show humaniseService;
import 'search_picker.dart';
import 'side_panel.dart';
import 'status_pill.dart';
import 'suggest_search.dart';

/// The fields Workiz's API does not take, tagged "EzBizi only" before the
/// first booking answers. Jobs owns this list (JobBookingController::
/// EZBIZI_ONLY); the booking's own `ezbizi_only` replaces it once it answers.
const kEzbiziOnlyBookFields = [
  'company',
  'phone_ext',
  'more_phones',
  'country',
  'description',
  'external_company',
  'custom_fields',
  'all_day',
  'recurrence',
];

/// What the screen that opened the Book panel already knows.
class BookPrefill {
  const BookPrefill({
    this.leadId,
    this.franchiseId,
    this.customerId,
    this.name = '',
    this.phone = '',
    this.email = '',
    this.line1 = '',
    this.line2 = '',
    this.city = '',
    this.state = '',
    this.postal = '',
    this.title = '',
    this.description = '',
    this.jobSource,
    this.matches = const [],
  });

  /// Leads: the lead's contact, address and source, with `lead_id`. The
  /// source is a hint: it is used only when one of the branch's job sources
  /// reads the same.
  /// [matches] are `customer_matches`: with any, nobody is chosen for you.
  ///
  /// Everything the lead holds carries over (Jose, 2026-11-05): the address
  /// Google checked ([details]' `checked_address`) before one parsed from a
  /// form, the branch whose areas cover its ZIP ([bookFranchiseId]) when
  /// the lead names none, and its summary as the description.
  factory BookPrefill.fromLead(
    Map<String, dynamic> lead, {
    List<String> said = const [],
    List<Map<String, dynamic>> matches = const [],
    Map<String, dynamic>? details,
    String? bookFranchiseId,
  }) {
    final form = addressFromForm(said);
    final checked = details?['checked_address'] as Map?;
    final exact = checked?['exact'] == true;
    String pick(Object? google, String fromForm, [Object? stored]) =>
        exact && google is String && google.isNotEmpty
        ? google
        : fromForm.isNotEmpty
        ? fromForm
        : (stored is String ? stored : '');
    return BookPrefill(
      leadId: lead['id'] as String?,
      franchiseId:
          ((lead['franchise'] as Map?)?['id'] as String?) ?? bookFranchiseId,
      name: (lead['contact_name'] as String?) ?? '',
      phone: (lead['phone'] as String?) ?? '',
      email: (lead['email'] as String?) ?? '',
      line1: pick(checked?['line1'], form.line1, lead['address']),
      line2: form.line2,
      city: pick(checked?['city'], form.city, lead['city']),
      state: pick(checked?['state'], form.state),
      postal: pick(
        checked?['zip'],
        form.postal,
        lead['postal_code'] ?? details?['zip'],
      ),
      title:
          humaniseService(lead['service'] as String?) ??
          ((lead['job_type'] as String?) ?? ''),
      description: (lead['summary'] as String?) ?? '',
      jobSource: humaniseService(lead['source'] as String?),
      matches: matches,
    );
  }

  final String? leadId;
  final String? franchiseId;
  final String? customerId;
  final String name, phone, email;
  final String line1, line2, city, state, postal;
  final String title;

  /// The job's description: a lead's summary.
  final String description;
  final String? jobSource;
  final List<Map<String, dynamic>> matches;
}

/// Opens the shared Book panel over the current screen. Pops with the
/// booking's answer once somebody presses Done, or null.
Future<Map<String, dynamic>?> showBookJobPanel(
  BuildContext context, {
  required ApiClient api,
  required Session session,
  BookPrefill prefill = const BookPrefill(),
  ValueChanged<Map<String, dynamic>>? onBooked,
}) => showSidePanel<Map<String, dynamic>>(
  context,
  builder: (_) => BookJobPanel(
    api: api,
    session: session,
    prefill: prefill,
    onBooked: onBooked,
  ),
);

/// Book a job: design 153, options 1 and 4 (docs/previews/
/// book-job-panel-layouts.html). One side panel, one scroll, four sections
/// (Client, Service location, Job details, Scheduled), each ticked when it is
/// complete, Book pinned at the bottom naming what is missing. On a phone the
/// sections are cards that open in place.
///
/// It saves through POST /jobs/book (EzBizi, then the branch's Workiz) and
/// shows Workiz's answer; a refusal stays here with Retry Workiz.
class BookJobPanel extends StatefulWidget {
  const BookJobPanel({
    super.key,
    required this.api,
    required this.session,
    this.prefill = const BookPrefill(),
    this.onBooked,
  });

  final ApiClient api;
  final Session session;
  final BookPrefill prefill;

  /// Called with the booking's answer as soon as EzBizi has the job
  /// (`job`, `workiz`, `ezbizi_only`), before anybody presses Done.
  final ValueChanged<Map<String, dynamic>>? onBooked;

  @override
  State<BookJobPanel> createState() => _BookJobPanelState();
}

enum _Who { undecided, existing, newClient }

class _BookJobPanelState extends State<BookJobPanel> {
  BookPrefill get _p => widget.prefill;

  late String? _franchiseId = _p.franchiseId ?? _onlyFranchise();

  // Client.
  late _Who _who = _p.customerId != null
      ? _Who.existing
      : _p.matches.isEmpty
      ? (_p.name.isNotEmpty || _p.phone.isNotEmpty || _p.leadId != null
            ? _Who.newClient
            : _Who.undecided)
      : _Who.undecided;
  String? _customerId;
  Map<String, dynamic>? _customer;
  final _search = TextEditingController();
  List<Map<String, dynamic>> _hits = const [];
  String? _searchError;
  late final _name = TextEditingController(text: _p.name);
  final _company = TextEditingController();
  late final _phone = TextEditingController(text: _p.phone);
  final _ext = TextEditingController();
  final List<TextEditingController> _morePhones = [];
  late final _email = TextEditingController(text: _p.email);

  // Location.
  late final _line1 = TextEditingController(text: _p.line1);
  late final _line2 = TextEditingController(text: _p.line2);
  late final _city = TextEditingController(text: _p.city);
  late final _state = TextEditingController(text: _p.state);
  late final _postal = TextEditingController(text: _p.postal);
  final _country = TextEditingController(text: 'US');
  String? _areaId;
  bool _areaPicked = false;

  // Details.
  late final _title = TextEditingController(text: _p.title);
  String? _status;
  String? _jobType;
  String? _jobSource;
  late final _description = TextEditingController(text: _p.description);
  String? _externalCompany;
  final Map<String, String> _custom = {};

  // Scheduled.
  bool _scheduled = true;
  final _start = TextEditingController();
  final _end = TextEditingController();
  bool _allDay = false;
  String _repeat = 'none';
  final _until = TextEditingController();
  final Set<String> _team = {};
  Map<String, dynamic>? _suggestion;

  Map<String, dynamic>? _options;
  String _optionsKey = '';
  bool _busy = false;
  String? _error;
  Map<String, dynamic>? _result;
  List<String> _ezbiziOnly = kEzbiziOnlyBookFields;
  final Set<String> _open = {'client'};

  static const _repeats = {
    'none': ('Does not repeat', 0, ''),
    '1-week': ('Every week', 1, 'week'),
    '2-week': ('Every 2 weeks', 2, 'week'),
    '1-month': ('Every month', 1, 'month'),
    '3-month': ('Every 3 months', 3, 'month'),
    '6-month': ('Every 6 months', 6, 'month'),
    '1-year': ('Every year', 1, 'year'),
  };

  String? _onlyFranchise() {
    final all = widget.session.me?.franchisesInView ?? const [];
    return all.length == 1 ? all.first.id : null;
  }

  @override
  void initState() {
    super.initState();
    _postal.addListener(_zipChanged);
    if (_p.customerId != null) _pickCustomer(_p.customerId!);
    _loadOptions();
  }

  @override
  void dispose() {
    for (final c in [
      _search,
      _name,
      _company,
      _phone,
      _ext,
      _email,
      _line1,
      _line2,
      _city,
      _state,
      _postal,
      _country,
      _title,
      _description,
      _start,
      _end,
      _until,
      ..._morePhones,
    ]) {
      c.dispose();
    }
    super.dispose();
  }

  String get _zip {
    final saved = (_customer?['address'] as Map?)?['postal_code'] as String?;
    final raw = _who == _Who.existing ? (saved ?? '') : _postal.text;
    final digits = raw.replaceAll(RegExp(r'\D'), '');
    return digits.length >= 5 ? digits.substring(0, 5) : '';
  }

  void _zipChanged() {
    final key = '$_franchiseId|$_zip|$_areaId';
    if (key != _optionsKey) _loadOptions();
  }

  /// GET /jobs/book/options for the branch, ZIP and area. With exactly one
  /// area matching the ZIP and none picked by hand, that one is chosen.
  Future<void> _loadOptions() async {
    final franchise = _franchiseId;
    if (franchise == null) return;
    final zip = _zip;
    final key = '$franchise|$zip|$_areaId';
    _optionsKey = key;
    final response = await widget.api.get('/jobs/book/options', {
      'franchise_id': franchise,
      if (zip.isNotEmpty) 'zip': zip,
      'service_area_id': ?_areaId,
    });
    if (!mounted || key != _optionsKey) return;
    if (!response.ok) {
      setState(() => _error = response.message);
      return;
    }
    final data =
        (response.data is Map
            ? (response.data as Map).cast<String, dynamic>()
            : null) ??
        {};
    final areas = _maps(data['service_areas']);
    final matching = areas.where((a) => a['matches_zip'] == true).toList();
    final statuses = [for (final s in (data['statuses'] as List? ?? [])) '$s'];
    var reload = false;
    setState(() {
      _options = data;
      _status ??= statuses.isEmpty ? null : statuses.first;
      final hint = _p.jobSource?.toLowerCase();
      if (_jobSource == null && hint != null) {
        _jobSource = [
          for (final w in (data['job_sources'] as List? ?? [])) '$w',
        ].where((w) => w.toLowerCase() == hint).firstOrNull;
      }
      if (!_areaPicked &&
          matching.length == 1 &&
          _areaId != matching.first['id']) {
        _areaId = matching.first['id'] as String;
        reload = true;
      }
      final covering = {
        for (final t in _maps(data['technicians']))
          if (t['covers_area'] == true) '${t['id']}',
      };
      _team.retainAll(covering);
    });
    if (reload) _loadOptions();
    if (zip.isNotEmpty) _loadSuggestion(zip);
  }

  /// Scheduling's nearby suggestion for the ZIP (GET
  /// /scheduling/availability/suggestion), as one tap-to-fill line.
  Future<void> _loadSuggestion(String zip) async {
    final response = await widget.api.get(
      '/scheduling/availability/suggestion',
      {'zip': zip},
    );
    if (!mounted || !response.ok || zip != _zip) return;
    final data = (response.data is Map
        ? (response.data as Map).cast<String, dynamic>()
        : null);
    final pick = (data?['suggested'] ?? data?['earliest']) as Map?;
    setState(() => _suggestion = pick?.cast<String, dynamic>());
  }

  static List<Map<String, dynamic>> _maps(Object? raw) => [
    for (final m in (raw as List? ?? const []))
      if (m is Map) m.cast<String, dynamic>(),
  ];

  bool get _workiz => _options?['workiz'] == true;

  Future<void> _searchCustomers(String term) async {
    final q = term.trim();
    if (q.length < 2) {
      setState(() => _hits = const []);
      return;
    }
    final response = await widget.api.get('/customers', {
      'search': q,
      'per_page': '10',
    });
    if (!mounted || _search.text.trim() != q) return;
    setState(() {
      _searchError = response.ok
          ? null
          : 'Could not search customers: ${response.message}';
      _hits = response.ok ? _maps(response.data) : const [];
    });
  }

  Future<void> _pickCustomer(String id, [Map<String, dynamic>? row]) async {
    setState(() {
      _who = _Who.existing;
      _customerId = id;
      _customer = row;
      _hits = const [];
      _search.clear();
    });
    final response = await widget.api.get('/customers/$id');
    if (!mounted || _customerId != id || !response.ok) return;
    setState(() {
      _customer =
          (response.data is Map
              ? (response.data as Map).cast<String, dynamic>()
              : null) ??
          row;
    });
    _areaPicked = false;
    _loadOptions();
  }

  // ── Completeness ──────────────────────────────────────────────

  bool get _clientDone => switch (_who) {
    _Who.existing => _customerId != null,
    _Who.newClient => _name.text.trim().isNotEmpty,
    _Who.undecided => false,
  };
  bool get _locationDone => _franchiseId != null && _areaId != null;
  bool get _detailsDone =>
      _title.text.trim().isNotEmpty && (!_workiz || _jobType != null);
  bool get _scheduleDone => _start.text.trim().isNotEmpty;

  List<String> _needs({required bool scheduled}) => [
    if (!_clientDone) 'Client',
    if (_franchiseId == null) 'Branch',
    if (_areaId == null) 'Service area',
    if (_title.text.trim().isEmpty) 'Job name',
    if (_workiz && _jobType == null) 'Job type',
    if (scheduled && !_scheduleDone) 'Start',
  ];

  Map<String, dynamic> _body({required bool scheduled}) {
    final repeat = _repeats[_repeat]!;
    return {
      'franchise_id': _franchiseId,
      'lead_id': ?_p.leadId,
      if (_who == _Who.existing) 'customer_id': _customerId,
      if (_who == _Who.newClient) ...{
        'customer': {
          'name': _name.text.trim(),
          'company': _company.text.trim(),
          'phone': _phone.text.trim(),
          'phone_ext': _ext.text.trim(),
          'more_phones': [
            for (final c in _morePhones)
              if (c.text.trim().isNotEmpty) c.text.trim(),
          ],
          'email': _email.text.trim(),
        },
        'location': {
          'address_line1': _line1.text.trim(),
          'address_line2': _line2.text.trim(),
          'city': _city.text.trim(),
          'state': _state.text.trim(),
          'postal_code': _postal.text.trim(),
          'country': _country.text.trim(),
        },
      },
      'service_area_id': _areaId,
      'title': _title.text.trim(),
      'status': ?_status,
      'job_type': ?_jobType,
      'job_source': ?_jobSource,
      if (_description.text.trim().isNotEmpty)
        'description': _description.text.trim(),
      'external_company': ?_externalCompany,
      if (_custom.values.any((v) => v.trim().isNotEmpty))
        'custom_fields': {
          for (final e in _custom.entries)
            if (e.value.trim().isNotEmpty) e.key: e.value.trim(),
        },
      'scheduled': scheduled,
      if (scheduled) ...{
        'start': _start.text.trim(),
        if (_end.text.trim().isNotEmpty) 'end': _end.text.trim(),
        'all_day': _allDay,
        if (repeat.$2 > 0)
          'recurrence': {
            'every': repeat.$2,
            'unit': repeat.$3,
            if (_until.text.trim().isNotEmpty) 'until': _until.text.trim(),
          },
      },
      'technician_ids': _team.toList(),
    };
  }

  Future<void> _book({required bool scheduled}) async {
    setState(() {
      _busy = true;
      _error = null;
    });
    final response = await widget.api.post(
      '/jobs/book',
      _body(scheduled: scheduled),
    );
    if (!mounted) return;
    if (!response.ok) {
      setState(() {
        _busy = false;
        _error = response.message;
      });
      return;
    }
    final data =
        (response.data is Map
            ? (response.data as Map).cast<String, dynamic>()
            : null) ??
        {};
    setState(() {
      _busy = false;
      _result = data;
      final only = data['ezbizi_only'];
      if (only is List) _ezbiziOnly = [for (final f in only) '$f'];
    });
    widget.onBooked?.call(data);
  }

  Future<void> _retryWorkiz() async {
    final job = (_result?['job'] as Map?)?['id'];
    setState(() {
      _busy = true;
      _error = null;
    });
    final response = await widget.api.post('/jobs/$job/book/workiz', {
      'technician_ids': _team.toList(),
    });
    if (!mounted) return;
    setState(() {
      _busy = false;
      if (!response.ok) {
        _error = response.message;
        return;
      }
      final data =
          (response.data is Map
              ? (response.data as Map).cast<String, dynamic>()
              : null) ??
          {};
      _result = {...?_result, 'workiz': data['workiz']};
    });
  }

  void _useSuggestion() {
    final s = _suggestion;
    if (s == null) return;
    final date = s['date'] as String;
    final w = (s['window'] as Map?) ?? const {};
    setState(() {
      _scheduled = true;
      _start.text = '$date ${_hm(w['start_time'])}';
      if (w['end_time'] != null) _end.text = '$date ${_hm(w['end_time'])}';
    });
  }

  static String _hm(Object? t) {
    final s = '${t ?? '09:00'}';
    return s.length >= 5 ? s.substring(0, 5) : s;
  }

  // ── Building ──────────────────────────────────────────────────

  @override
  Widget build(BuildContext context) {
    final result = _result;
    final needsBook = _needs(scheduled: true);
    final needsSave = _needs(scheduled: false);
    final needs = _scheduled ? needsBook : needsSave;
    final theme = Theme.of(context);

    return SidePanel(
      title: 'Book a job',
      subtitle: _p.leadId != null ? 'From this lead' : null,
      width: 560,
      actions: result != null
          ? [
              FilledButton(
                key: const ValueKey('book-done'),
                onPressed: _busy
                    ? null
                    : () => Navigator.of(context).pop(result),
                child: const Text('Done'),
              ),
            ]
          : [
              if (needs.isNotEmpty)
                Text(
                  'Needs: ${needs.join(', ')}',
                  key: const ValueKey('book-needs'),
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: theme.colorScheme.error,
                  ),
                ),
              OutlinedButton(
                key: const ValueKey('book-unscheduled'),
                onPressed: !_busy && needsSave.isEmpty
                    ? () => _book(scheduled: false)
                    : null,
                child: const Text('Save unscheduled'),
              ),
              if (_scheduled)
                FilledButton(
                  key: const ValueKey('book-submit'),
                  onPressed: !_busy && needsBook.isEmpty
                      ? () => _book(scheduled: true)
                      : null,
                  child: Text(_busy ? 'Booking…' : 'Book job'),
                ),
            ],
      child: Builder(
        builder: (context) {
          // A phone (option 4): the panel fills the window, sections are cards.
          final phone = MediaQuery.sizeOf(context).width < 600;
          return Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              if (result != null) ...[
                _resultBox(theme, result),
                const SizedBox(height: 12),
              ],
              if (_error != null) ...[
                Text(
                  _error!,
                  key: const ValueKey('book-error'),
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: theme.colorScheme.error,
                  ),
                ),
                const SizedBox(height: 12),
              ],
              _section(
                theme,
                phone: phone,
                id: 'client',
                title: 'Client',
                done: _clientDone,
                summary: _clientSummary(),
                child: _client(theme),
              ),
              _section(
                theme,
                phone: phone,
                id: 'location',
                title: 'Service location',
                done: _locationDone,
                summary: _locationSummary(),
                child: _location(theme),
              ),
              _section(
                theme,
                phone: phone,
                id: 'details',
                title: 'Job details',
                done: _detailsDone,
                summary: _title.text.trim(),
                child: _details(theme),
              ),
              _section(
                theme,
                phone: phone,
                id: 'schedule',
                title: 'Scheduled',
                done: _scheduled ? _scheduleDone : true,
                summary: _scheduled ? _start.text : 'Unscheduled',
                child: _schedule(theme),
              ),
            ],
          );
        },
      ),
    );
  }

  Widget _section(
    ThemeData theme, {
    required bool phone,
    required String id,
    required String title,
    required bool done,
    required String summary,
    required Widget child,
  }) {
    final open = !phone || _open.contains(id);
    final header = Row(
      children: [
        Text(title, style: theme.textTheme.titleSmall),
        if (done) ...[
          const SizedBox(width: 6),
          Icon(
            Icons.check,
            key: ValueKey('book-done-$id'),
            size: 16,
            color: ToneColours.of(context, Tone.ok).ink,
          ),
        ],
        if (phone && !open && summary.isNotEmpty) ...[
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              summary,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
          ),
        ] else
          const Spacer(),
        if (phone) Icon(open ? Icons.expand_less : Icons.expand_more, size: 18),
      ],
    );

    return Container(
      key: ValueKey('book-section-$id'),
      margin: const EdgeInsets.only(bottom: 12),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        border: Border.all(color: EzBiziTheme.edge(context)),
        borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          phone
              ? InkWell(
                  onTap: () => setState(
                    () => _open.contains(id) ? _open.remove(id) : _open.add(id),
                  ),
                  child: header,
                )
              : header,
          if (open) ...[const SizedBox(height: 10), child],
        ],
      ),
    );
  }

  Widget _only(String field) => !_workiz || !_ezbiziOnly.contains(field)
      ? const SizedBox.shrink()
      : Padding(
          padding: const EdgeInsets.only(top: 2),
          child: Align(
            alignment: Alignment.centerLeft,
            child: StatusPill(
              'EzBizi only',
              key: ValueKey('ezbizi-only-$field'),
            ),
          ),
        );

  String _clientSummary() => switch (_who) {
    _Who.existing => [
      _customer?['name'] ?? '',
      formatPhoneNumber(_customer?['phone'] as String?),
    ].where((s) => '$s'.isNotEmpty).join(' · '),
    _Who.newClient => [
      _name.text.trim(),
      formatPhoneNumber(_phone.text.trim()),
    ].where((s) => s.isNotEmpty).join(' · '),
    _Who.undecided => '',
  };

  String _locationSummary() {
    final area = _maps(
      _options?['service_areas'],
    ).where((a) => a['id'] == _areaId).firstOrNull?['name'];
    final line = _who == _Who.existing
        ? ((_customer?['address'] as Map?)?['line1'])
        : _line1.text.trim();
    return [line, area].where((s) => s != null && '$s'.isNotEmpty).join(' · ');
  }

  Widget _client(ThemeData theme) {
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    if (_who == _Who.existing) {
      final c = _customer;
      return Container(
        key: const ValueKey('book-client-card'),
        padding: const EdgeInsets.all(10),
        decoration: BoxDecoration(
          color: theme.colorScheme.secondaryContainer,
          borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
        ),
        child: Row(
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    [c?['name'] ?? 'Customer', ?c?['number']].join(' · '),
                    style: theme.textTheme.titleSmall,
                  ),
                  Text(
                    [
                      formatPhoneNumber(c?['phone'] as String?),
                      c?['email'] ?? '',
                    ].where((s) => '$s'.isNotEmpty).join(' · '),
                    style: muted,
                  ),
                ],
              ),
            ),
            TextButton(
              key: const ValueKey('book-client-change'),
              onPressed: _busy || _result != null
                  ? null
                  : () => setState(() {
                      _who = _p.matches.isEmpty
                          ? _Who.newClient
                          : _Who.undecided;
                      _customerId = null;
                      _customer = null;
                    }),
              child: const Text('Change'),
            ),
          ],
        ),
      );
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (_p.matches.isNotEmpty && _who == _Who.undecided) ...[
          Text(
            _p.matches.length == 1
                ? 'A customer already has this number'
                : '${_p.matches.length} customers already have this number',
            style: theme.textTheme.titleSmall,
          ),
          Text(
            'Pick one, or make a new one. Nothing is chosen for you: attaching '
            'a job to the wrong person looks exactly like getting it right.',
            style: muted,
          ),
          for (final m in _p.matches)
            ListTile(
              key: ValueKey('book-match-${m['id']}'),
              dense: true,
              contentPadding: EdgeInsets.zero,
              leading: const Icon(Icons.person_outline, size: 18),
              title: Text(m['name'] as String? ?? 'Customer'),
              subtitle: Text(
                [
                  ?(m['number'] as String?),
                  if ((m['phone'] as String?)?.isNotEmpty == true)
                    formatPhoneNumber(m['phone'] as String?),
                ].join(' · '),
              ),
              onTap: () => _pickCustomer(m['id'] as String, m),
            ),
          const SizedBox(height: 8),
        ],
        SuggestSearchField(
          key: const ValueKey('book-client-search'),
          controller: _search,
          hintText: 'Find a client: name, phone or email',
          prefixIcon: const Icon(Icons.search),
          debounce: const Duration(milliseconds: 250),
          suggestions: [
            for (final h in _hits)
              SuggestItem(
                key: ValueKey('book-hit-${h['id']}'),
                title: '${h['name'] ?? 'Customer'}',
                subtitle: [
                  ?(h['number'] as String?),
                  if ((h['phone'] as String?)?.isNotEmpty == true)
                    formatPhoneNumber(h['phone'] as String?),
                ].join(' · '),
              ),
          ],
          onChanged: _searchCustomers,
          onPick: (i) => _pickCustomer(_hits[i]['id'] as String, _hits[i]),
          onDismiss: () => setState(() => _hits = const []),
        ),
        if (_searchError != null)
          Text(
            _searchError!,
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.error,
            ),
          ),
        const SizedBox(height: 8),
        if (_who != _Who.newClient)
          Align(
            alignment: Alignment.centerLeft,
            child: TextButton.icon(
              key: const ValueKey('book-new-client'),
              onPressed: () => setState(() => _who = _Who.newClient),
              icon: const Icon(Icons.add, size: 18),
              label: const Text('New client'),
            ),
          )
        else ...[
          TextField(
            key: const ValueKey('book-name'),
            controller: _name,
            decoration: const InputDecoration(labelText: 'Name'),
            onChanged: (_) => setState(() {}),
          ),
          const SizedBox(height: 8),
          TextField(
            key: const ValueKey('book-company'),
            controller: _company,
            decoration: const InputDecoration(labelText: 'Company'),
          ),
          _only('company'),
          const SizedBox(height: 8),
          Row(
            children: [
              Expanded(
                flex: 3,
                child: TextField(
                  key: const ValueKey('book-phone'),
                  controller: _phone,
                  decoration: const InputDecoration(labelText: 'Phone'),
                  onChanged: (_) => setState(() {}),
                ),
              ),
              const SizedBox(width: 8),
              Expanded(
                child: TextField(
                  key: const ValueKey('book-ext'),
                  controller: _ext,
                  decoration: const InputDecoration(labelText: 'Ext'),
                ),
              ),
            ],
          ),
          _only('phone_ext'),
          for (final (i, c) in _morePhones.indexed) ...[
            const SizedBox(height: 8),
            TextField(
              key: ValueKey('book-more-phone-$i'),
              controller: c,
              decoration: const InputDecoration(labelText: 'Another phone'),
            ),
          ],
          if (_morePhones.isNotEmpty) _only('more_phones'),
          if (_morePhones.length < 5)
            Align(
              alignment: Alignment.centerLeft,
              child: TextButton.icon(
                key: const ValueKey('book-add-phone'),
                onPressed: () =>
                    setState(() => _morePhones.add(TextEditingController())),
                icon: const Icon(Icons.add, size: 18),
                label: const Text('Add phone'),
              ),
            ),
          TextField(
            key: const ValueKey('book-email'),
            controller: _email,
            decoration: const InputDecoration(labelText: 'Email'),
          ),
        ],
      ],
    );
  }

  Widget _location(ThemeData theme) {
    final me = widget.session.me!;
    final areas = _maps(_options?['service_areas'])
      ..sort(
        (a, b) => (b['matches_zip'] == true ? 1 : 0).compareTo(
          a['matches_zip'] == true ? 1 : 0,
        ),
      );
    final matching = areas.where((a) => a['matches_zip'] == true).length;
    final saved = (_customer?['address'] as Map?)?.cast<String, dynamic>();

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        FranchiseField(
          me: me,
          value: _franchiseId,
          enabled: !_busy && _result == null,
          onChanged: (v) {
            setState(() {
              _franchiseId = v;
              _areaId = null;
              _areaPicked = false;
              _options = null;
            });
            _loadOptions();
          },
        ),
        const SizedBox(height: 8),
        if (_who == _Who.existing)
          Text(
            saved == null
                ? 'No saved address'
                : [
                    saved['line1'],
                    saved['line2'],
                    saved['city'],
                    [
                      saved['state'],
                      saved['postal_code'],
                    ].where((s) => s != null && '$s'.isNotEmpty).join(' '),
                  ].where((s) => s != null && '$s'.isNotEmpty).join(', '),
            key: const ValueKey('book-saved-address'),
          )
        else ...[
          AddressFields(
            api: widget.api,
            line1: _line1,
            line2: _line2,
            city: _city,
            state: _state,
            postal: _postal,
            country: _country,
            enabled: !_busy,
            dense: false,
          ),
          _only('country'),
        ],
        const SizedBox(height: 8),
        SearchPickerField<String>(
          key: const ValueKey('book-area'),
          value: _areaId,
          label: 'Service area',
          // No branch yet means no areas were asked for, not that none covers it.
          helperText: _zip.isEmpty || _franchiseId == null
              ? null
              : matching == 0
              ? 'No area covers $_zip'
              : '$matching ${matching == 1 ? 'area matches' : 'areas match'} $_zip',
          options: [
            for (final a in areas)
              SearchOption(
                value: a['id'] as String,
                label: '${a['name']}',
                detail: a['matches_zip'] == true ? 'Covers $_zip' : null,
              ),
          ],
          onChanged: _busy
              ? null
              : (v) {
                  setState(() {
                    _areaId = v;
                    _areaPicked = true;
                  });
                  _loadOptions();
                },
        ),
      ],
    );
  }

  Widget _details(ThemeData theme) {
    final o = _options ?? const {};
    List<String> words(String key) => [
      for (final w in (o[key] as List? ?? [])) '$w',
    ];
    final fields = _maps(o['custom_fields']);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        TextField(
          key: const ValueKey('book-title'),
          controller: _title,
          decoration: const InputDecoration(labelText: 'Job name'),
          onChanged: (_) => setState(() {}),
        ),
        const SizedBox(height: 8),
        SearchPickerField<String>(
          key: const ValueKey('book-status'),
          value: _status,
          label: 'Status',
          options: [
            for (final s in words('statuses')) SearchOption(value: s, label: s),
          ],
          onChanged: _busy ? null : (v) => setState(() => _status = v),
        ),
        const SizedBox(height: 8),
        SearchPickerField<String>(
          key: const ValueKey('book-job-type'),
          value: _jobType,
          label: _workiz ? 'Job type (Workiz needs one)' : 'Job type',
          allowClear: !_workiz,
          create: _workiz ? null : (typed) => typed,
          options: [
            for (final s in words('job_types'))
              SearchOption(value: s, label: s),
          ],
          onChanged: _busy ? null : (v) => setState(() => _jobType = v),
        ),
        const SizedBox(height: 8),
        SearchPickerField<String>(
          key: const ValueKey('book-job-source'),
          value: _jobSource,
          label: 'Job source',
          allowClear: true,
          create: _workiz ? null : (typed) => typed,
          options: [
            for (final s in {...words('job_sources'), ?_jobSource})
              SearchOption(value: s, label: s),
          ],
          onChanged: _busy ? null : (v) => setState(() => _jobSource = v),
        ),
        const SizedBox(height: 8),
        // ponytail: no rich-text editor in the app; plain text into the HTML field.
        TextField(
          key: const ValueKey('book-description'),
          controller: _description,
          minLines: 3,
          maxLines: 8,
          decoration: const InputDecoration(labelText: 'Description'),
        ),
        _only('description'),
        const SizedBox(height: 8),
        SearchPickerField<String>(
          key: const ValueKey('book-external'),
          value: _externalCompany,
          label: 'External company',
          allowClear: true,
          create: (typed) => typed,
          options: [
            for (final s in words('external_companies'))
              SearchOption(value: s, label: s),
          ],
          onChanged: _busy ? null : (v) => setState(() => _externalCompany = v),
        ),
        _only('external_company'),
        for (final f in fields) ...[const SizedBox(height: 8), _customField(f)],
        if (fields.isNotEmpty) _only('custom_fields'),
      ],
    );
  }

  Widget _customField(Map<String, dynamic> f) {
    final name = '${f['name']}';
    final options = [for (final o in (f['options'] as List? ?? [])) '$o'];
    if (options.isNotEmpty) {
      return SearchPickerField<String>(
        key: ValueKey('book-custom-$name'),
        value: _custom[name],
        label: name,
        allowClear: true,
        options: [for (final o in options) SearchOption(value: o, label: o)],
        onChanged: (v) => setState(() => _custom[name] = v ?? ''),
      );
    }
    return TextFormField(
      key: ValueKey('book-custom-$name'),
      initialValue: _custom[name],
      decoration: InputDecoration(labelText: name),
      onChanged: (v) => _custom[name] = v,
    );
  }

  Widget _schedule(ThemeData theme) {
    final me = widget.session.me!;
    final techs = _maps(_options?['technicians']);
    final covering = techs.where((t) => t['covers_area'] == true).toList();
    final s = _suggestion;
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        SwitchListTile(
          key: const ValueKey('book-scheduled'),
          contentPadding: EdgeInsets.zero,
          title: const Text('Scheduled'),
          subtitle: _scheduled ? null : const Text('Saved as unscheduled'),
          value: _scheduled,
          onChanged: _busy ? null : (v) => setState(() => _scheduled = v),
        ),
        if (_scheduled) ...[
          if (s != null)
            Row(
              key: const ValueKey('book-suggestion'),
              children: [
                Expanded(
                  child: Text(
                    [
                      'Suggested: ${s['date']}',
                      '${_hm((s['window'] as Map?)?['start_time'])}–'
                          '${_hm((s['window'] as Map?)?['end_time'])}',
                      for (final t in (s['technicians'] as List? ?? []).take(2))
                        '$t',
                    ].join(' · '),
                    style: muted,
                  ),
                ),
                TextButton(
                  key: const ValueKey('book-suggestion-use'),
                  onPressed: _useSuggestion,
                  child: const Text('Use'),
                ),
              ],
            ),
          CalendarDayField(
            key: const ValueKey('book-start'),
            controller: _start,
            label: 'Start',
            formats: me.display,
            withTime: true,
            enabled: !_busy,
            onChanged: (_) => setState(() {}),
          ),
          const SizedBox(height: 8),
          CalendarDayField(
            key: const ValueKey('book-end'),
            controller: _end,
            label: 'End',
            formats: me.display,
            withTime: true,
            enabled: !_busy,
          ),
          CheckboxListTile(
            key: const ValueKey('book-all-day'),
            contentPadding: EdgeInsets.zero,
            controlAffinity: ListTileControlAffinity.leading,
            title: const Text('All day'),
            value: _allDay,
            onChanged: (v) => setState(() => _allDay = v ?? false),
          ),
          _only('all_day'),
          SearchPickerField<String>(
            key: const ValueKey('book-repeat'),
            value: _repeat,
            label: 'Repeat',
            options: [
              for (final e in _repeats.entries)
                SearchOption(value: e.key, label: e.value.$1),
            ],
            onChanged: (v) => setState(() => _repeat = v ?? 'none'),
          ),
          if (_repeat != 'none') ...[
            const SizedBox(height: 8),
            CalendarDayField(
              key: const ValueKey('book-until'),
              controller: _until,
              label: 'Repeat until (optional)',
              formats: me.display,
            ),
          ],
          _only('recurrence'),
          const SizedBox(height: 10),
          Text('Team', style: theme.textTheme.labelLarge),
          const SizedBox(height: 4),
          if (_areaId == null)
            Text('Pick a service area to see who covers it.', style: muted)
          else if (covering.isEmpty)
            Text('Nobody on this branch covers this area.', style: muted)
          else
            Wrap(
              spacing: 6,
              runSpacing: 6,
              children: [
                for (final t in covering)
                  FilterChip(
                    key: ValueKey('book-tech-${t['id']}'),
                    label: Text('${t['name']}'),
                    selected: _team.contains('${t['id']}'),
                    onSelected: (on) => setState(
                      () => on
                          ? _team.add('${t['id']}')
                          : _team.remove('${t['id']}'),
                    ),
                  ),
              ],
            ),
          const SizedBox(height: 6),
          Align(
            alignment: Alignment.centerLeft,
            child: TextButton(
              key: const ValueKey('book-view-schedule'),
              onPressed: () => showSidePanel<void>(
                context,
                builder: (_) => SidePanel(
                  title: 'Schedule',
                  width: 900,
                  scrollable: false,
                  child: SchedulePane(
                    api: widget.api,
                    session: widget.session,
                    visible: true,
                  ),
                ),
              ),
              child: const Text('View schedule ›'),
            ),
          ),
        ],
      ],
    );
  }

  Widget _resultBox(ThemeData theme, Map<String, dynamic> result) {
    final job = (result['job'] as Map?) ?? const {};
    final w = (result['workiz'] as Map?) ?? const {};
    final status = w['status'];
    final refused = status == 'refused';
    final assigned = [for (final a in (w['assigned'] as List? ?? [])) '$a'];
    final line = switch (status) {
      'created' =>
        'Booked in EzBizi · Sent to Workiz ✓'
            '${w['serial_id'] != null ? ' (#${w['serial_id']})' : ''}',
      'refused' => 'Booked in EzBizi · Workiz refused it',
      _ => 'Booked',
    };
    final colours = ToneColours.of(context, refused ? Tone.bad : Tone.ok);

    return Container(
      key: const ValueKey('book-result'),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: colours.fill,
        borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            line,
            style: theme.textTheme.titleSmall?.copyWith(color: colours.ink),
          ),
          Text(
            [
              'Job ${job['number'] ?? ''}',
              if (assigned.isNotEmpty) 'Assigned ${assigned.join(', ')}',
            ].join(' · '),
            style: theme.textTheme.bodySmall?.copyWith(color: colours.ink),
          ),
          if (refused) ...[
            const SizedBox(height: 4),
            Text(
              '${w['refused'] ?? ''}',
              key: const ValueKey('book-refused'),
              style: theme.textTheme.bodySmall?.copyWith(color: colours.ink),
            ),
            const SizedBox(height: 6),
            OutlinedButton(
              key: const ValueKey('book-retry-workiz'),
              onPressed: _busy ? null : _retryWorkiz,
              child: const Text('Retry Workiz'),
            ),
          ],
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
  final h12 = at.hour % 12 == 0 ? 12 : at.hour % 12;
  final minutes = at.minute == 0
      ? ''
      : ':${at.minute.toString().padLeft(2, '0')}';
  final ampm = at.hour < 12 ? 'AM' : 'PM';
  return '${shortWeekdays[at.weekday - 1]} ${at.day} ${shortMonths[at.month - 1]}, '
      '$h12$minutes $ampm';
}

/// The address a website form gave, from its "Label: value" lines. A later
/// message wins over an earlier one; a label it cannot place is ignored.
({String line1, String line2, String city, String state, String postal})
addressFromForm(Iterable<String> said) {
  var line1 = '', line2 = '', city = '', state = '', postal = '';
  for (final text in said) {
    for (final raw in text.split('\n')) {
      // Since 2026-10-12 a form says the address on one line, values only:
      // "📍 123 Elm St Apt 4, Tempe, Arizona 85281".
      if (raw.startsWith('📍 ')) {
        final parts = raw.substring('📍 '.length).split(', ');
        if (parts.length >= 3) {
          final last = RegExp(
            r'^(.*?)\s*(\d{5}(?:-\d{4})?)?$',
          ).firstMatch(parts.last)!;
          line1 = parts.sublist(0, parts.length - 2).join(', ');
          city = parts[parts.length - 2];
          final st = last.group(1)!.trim();
          state = _stateCodes[st.toLowerCase()] ?? st;
          postal = last.group(2) ?? postal;
        }
        continue;
      }
      final at = raw.indexOf(':');
      if (at < 0) continue;
      // Each field starts with an emoji mark; the label is the letters after it.
      final label = raw
          .substring(0, at)
          .replaceFirst(RegExp(r'^[^A-Za-z]+'), '')
          .toLowerCase();
      final value = raw.substring(at + 1).trim();
      if (value.isEmpty) continue;
      if (RegExp(r'zip|postal').hasMatch(label)) {
        postal = value;
      } else if (label.contains('city')) {
        city = value;
      } else if (label == 'state' || label.startsWith('state ')) {
        state = _stateCodes[value.toLowerCase()] ?? value;
      } else if (RegExp(r'line ?2|suite|apt|unit').hasMatch(label)) {
        line2 = value;
      } else if (RegExp(r'^(address|street)').hasMatch(label) &&
          !label.contains('email')) {
        line1 = value;
      }
    }
  }
  return (line1: line1, line2: line2, city: city, state: state, postal: postal);
}

const _stateCodes = {
  'alabama': 'AL',
  'alaska': 'AK',
  'arizona': 'AZ',
  'arkansas': 'AR',
  'california': 'CA',
  'colorado': 'CO',
  'connecticut': 'CT',
  'delaware': 'DE',
  'district of columbia': 'DC',
  'florida': 'FL',
  'georgia': 'GA',
  'hawaii': 'HI',
  'idaho': 'ID',
  'illinois': 'IL',
  'indiana': 'IN',
  'iowa': 'IA',
  'kansas': 'KS',
  'kentucky': 'KY',
  'louisiana': 'LA',
  'maine': 'ME',
  'maryland': 'MD',
  'massachusetts': 'MA',
  'michigan': 'MI',
  'minnesota': 'MN',
  'mississippi': 'MS',
  'missouri': 'MO',
  'montana': 'MT',
  'nebraska': 'NE',
  'nevada': 'NV',
  'new hampshire': 'NH',
  'new jersey': 'NJ',
  'new mexico': 'NM',
  'new york': 'NY',
  'north carolina': 'NC',
  'north dakota': 'ND',
  'ohio': 'OH',
  'oklahoma': 'OK',
  'oregon': 'OR',
  'pennsylvania': 'PA',
  'rhode island': 'RI',
  'south carolina': 'SC',
  'south dakota': 'SD',
  'tennessee': 'TN',
  'texas': 'TX',
  'utah': 'UT',
  'vermont': 'VT',
  'virginia': 'VA',
  'washington': 'WA',
  'west virginia': 'WV',
  'wisconsin': 'WI',
  'wyoming': 'WY',
};
