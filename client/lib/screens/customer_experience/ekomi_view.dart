import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../core/timestamps.dart';
import '../../widgets/date_range_picker.dart';
import '../../widgets/side_panel.dart';
import '../../widgets/status_pill.dart';
import '../reports/report_dates.dart';

/// Customer Experience › eKomi (UI queue 165, option 1 + phone 4; Jose,
/// 2026-11-02: "as you recommend"). What A1 Tools did with eKomi: the account
/// and its request funnel, who was asked, the eKomi reviews, and asking
/// automatically when a job is Done.
///
/// Drawn in the Review links tab's place from its Sources row, with a back
/// arrow (the containment rule). Every read goes to eKomi live, so each tab
/// shows its own loading state.
class EkomiView extends StatefulWidget {
  const EkomiView({
    super.key,
    required this.api,
    required this.session,
    this.onBack,
  });

  final ApiClient api;
  final Session session;
  final VoidCallback? onBack;

  @override
  State<EkomiView> createState() => _EkomiViewState();
}

const _statuses = {
  'not_started': 'Not started',
  'started': 'Started',
  'completed': 'Completed',
  'interrupted': 'Interrupted',
};
const _channels = {'email': 'Email', 'sms': 'SMS', 'telephone': 'Phone'};

class _EkomiViewState extends State<EkomiView> {
  late final DateTime _today = DateSpan.single(DateTime.now()).from;
  late DateSpan _span = DateSpan(
    DateTime(_today.year, _today.month, _today.day - 29),
    _today,
  );
  int _tab = 0;

  List<Map<String, dynamic>>? _accounts;
  String? _id;
  String? _error;

  Map<String, dynamic>? _overview;
  String? _overviewError;
  List<Map<String, dynamic>>? _requests;
  String? _requestsError;
  List<Map<String, dynamic>>? _reviews;
  String? _reviewsError;

  String? _status;
  String? _channel;
  String _q = '';
  int _request = 0;

  Map<String, dynamic>? get _account => [
    for (final a in _accounts ?? const <Map<String, dynamic>>[])
      if ('${a['id']}' == _id) a,
  ].firstOrNull;

  @override
  void initState() {
    super.initState();
    _loadAccounts();
  }

  static List<Map<String, dynamic>> _list(Object? data) => [
    for (final m in (data as List? ?? const []).whereType<Map>())
      Map<String, dynamic>.from(m),
  ];

  Future<void> _loadAccounts() async {
    final r = await widget.api.get('/ekomi');
    if (!mounted) return;
    setState(() {
      _error = r.ok ? null : r.message;
      _accounts = r.ok ? _list(r.data) : const [];
      _id ??= _accounts!.isEmpty ? null : '${_accounts!.first['id']}';
    });
    _loadTab();
  }

  Map<String, String> get _range => {
    'from': formatCalendarDay(_span.from),
    'to': formatCalendarDay(_span.to),
  };

  Future<void> _loadTab() async {
    final id = _id;
    if (id == null) return;
    final request = ++_request;
    switch (_tab) {
      case 0:
        setState(() => _overview = _overviewError = null);
        final r = await widget.api.get('/ekomi/$id/overview', _range);
        if (!mounted || request != _request) return;
        setState(() {
          _overviewError = r.ok ? null : r.message;
          _overview = r.ok ? Map<String, dynamic>.from(r.data as Map) : null;
        });
      case 1:
        setState(() => _requests = _requestsError = null);
        final r = await widget.api.get('/ekomi/$id/requests', {
          ..._range,
          'status': ?_status,
          'channel': ?_channel,
          if (_q.trim().isNotEmpty) 'q': _q.trim(),
        });
        if (!mounted || request != _request) return;
        setState(() {
          _requestsError = r.ok ? null : r.message;
          _requests = r.ok ? _list(r.data) : null;
        });
      case 2:
        setState(() => _reviews = _reviewsError = null);
        final r = await widget.api.get('/reviews', {
          'source': 'ekomi',
          'from': formatCalendarDay(_span.from),
          'per_page': '100',
        });
        if (!mounted || request != _request) return;
        setState(() {
          _reviewsError = r.ok ? null : r.message;
          // ponytail: filtered here too until /reviews honours ?source=.
          _reviews = r.ok
              ? [
                  for (final m in _list(r.data))
                    if (m['source'] == 'ekomi') m,
                ]
              : null;
        });
    }
  }

  DisplayFormats get _formats =>
      widget.session.me?.display ?? DisplayFormats.fallback;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final account = _account;
    final certificate =
        (_overview?['snapshot'] as Map?)?['certificate_url'] as String?;

    return Column(
      key: const ValueKey('ekomi'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(8, 8, 16, 0),
          child: Wrap(
            spacing: 8,
            runSpacing: 6,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              if (widget.onBack != null)
                IconButton(
                  key: const ValueKey('ekomi-back'),
                  tooltip: 'Back to Review links',
                  icon: const Icon(Icons.arrow_back),
                  onPressed: widget.onBack,
                ),
              Text(
                account == null ? 'eKomi' : 'eKomi · ${account['name'] ?? ''}',
                style: theme.textTheme.titleMedium,
              ),
              if (account != null)
                StatusPill(
                  account['status'] == 'connected'
                      ? 'Connected'
                      : '${account['status'] ?? ''}',
                  tone: account['status'] == 'connected'
                      ? Tone.ok
                      : Tone.caution,
                ),
              if ((_accounts?.length ?? 0) > 1)
                for (final a in _accounts!)
                  ChoiceChip(
                    key: ValueKey('ekomi-account-${a['id']}'),
                    label: Text('${a['name'] ?? ''}'),
                    selected: '${a['id']}' == _id,
                    onSelected: (_) {
                      setState(() => _id = '${a['id']}');
                      _loadTab();
                    },
                  ),
              if (certificate != null && certificate.isNotEmpty)
                SelectableText(
                  'Certificate: $certificate',
                  key: const ValueKey('ekomi-certificate'),
                  style: theme.textTheme.bodySmall,
                ),
            ],
          ),
        ),
        if (_error != null)
          Padding(
            padding: const EdgeInsets.all(16),
            child: Text(
              _error!,
              style: TextStyle(color: theme.colorScheme.error),
            ),
          )
        else if (_accounts == null)
          const LinearProgressIndicator()
        else if (_id == null)
          const Padding(
            padding: EdgeInsets.all(16),
            child: Text('No eKomi account is connected.'),
          )
        else ...[
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16),
            child: SegmentedButton<int>(
              key: const ValueKey('ekomi-tabs'),
              showSelectedIcon: false,
              segments: const [
                ButtonSegment(value: 0, label: Text('Overview')),
                ButtonSegment(value: 1, label: Text('Requests')),
                ButtonSegment(value: 2, label: Text('Reviews')),
                ButtonSegment(value: 3, label: Text('Settings')),
              ],
              selected: {_tab},
              onSelectionChanged: (s) {
                setState(() => _tab = s.first);
                _loadTab();
              },
            ),
          ),
          if (_tab != 3)
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 8, 16, 0),
              child: ReportDates(
                initial: _span,
                today: _today,
                formats: _formats,
                onChanged: (span) {
                  _span = span;
                  _loadTab();
                },
              ),
            ),
          const SizedBox(height: 8),
          Expanded(
            child: switch (_tab) {
              0 => _overviewTab(theme),
              1 => _requestsTab(theme),
              2 => _reviewsTab(theme),
              _ => _EkomiSettings(
                key: ValueKey('ekomi-settings-$_id'),
                api: widget.api,
                id: _id!,
                account: account ?? const {},
                onSaved: _loadAccounts,
              ),
            },
          ),
        ],
      ],
    );
  }

  Widget _loading(String what) => Center(
    child: Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        const CircularProgressIndicator(),
        const SizedBox(height: 8),
        Text(what),
      ],
    ),
  );

  Widget _failed(ThemeData theme, String message) => Padding(
    padding: const EdgeInsets.all(16),
    child: Text(message, style: TextStyle(color: theme.colorScheme.error)),
  );

  Widget _overviewTab(ThemeData theme) {
    if (_overviewError != null) return _failed(theme, _overviewError!);
    final o = _overview;
    if (o == null) return _loading('Reading eKomi…');
    final snap = Map<String, dynamic>.from(o['snapshot'] as Map? ?? const {});
    final f = Map<String, dynamic>.from(o['funnel'] as Map? ?? const {});
    final rates = Map<String, dynamic>.from(f['rates'] as Map? ?? const {});
    int n(String k) => (f[k] as num?)?.toInt() ?? 0;
    String rate(String k) {
      final v = rates[k] as num?;
      return v == null ? '' : ' · ${v.round()}%';
    }

    final average = (snap['average'] as num?)?.toDouble();
    final tiles = [
      ('Reviews (all time)', '${snap['review_count'] ?? 0}'),
      ('Average', average == null ? '—' : '${average.toStringAsFixed(1)} ★'),
      ('Requests sent', '${n('requested')}'),
      ('Review rate', rate('review').replaceFirst(' · ', '').ifEmpty('—')),
    ];
    final steps = [
      ('Requested', n('requested'), ''),
      ('Delivered', n('delivered'), rate('delivered')),
      ('Opened', n('opened'), rate('open')),
      ('Clicked', n('clicked'), rate('click')),
      ('Reviewed', n('reviewed'), rate('review')),
    ];
    final top = n('requested');

    return ListView(
      key: const ValueKey('ekomi-overview'),
      padding: const EdgeInsets.fromLTRB(16, 0, 16, 24),
      children: [
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            for (final (label, value) in tiles)
              SizedBox(
                width: 170,
                child: Card(
                  margin: EdgeInsets.zero,
                  child: Padding(
                    padding: const EdgeInsets.all(10),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(label, style: theme.textTheme.bodySmall),
                        Text(value, style: theme.textTheme.titleLarge),
                      ],
                    ),
                  ),
                ),
              ),
          ],
        ),
        const SizedBox(height: 12),
        for (final (label, count, r) in steps)
          _bar(theme, label, count, top, '$count$r'),
        _bar(
          theme,
          'Bounced',
          n('bounced'),
          top,
          '${n('bounced')}${rate('bounce')}',
          bad: true,
        ),
      ],
    );
  }

  Widget _bar(
    ThemeData theme,
    String label,
    int count,
    int top,
    String trailing, {
    bool bad = false,
  }) {
    final colour = bad ? theme.colorScheme.error : theme.colorScheme.primary;
    return Padding(
      key: ValueKey('ekomi-step-${label.toLowerCase()}'),
      padding: const EdgeInsets.symmetric(vertical: 3),
      child: Row(
        children: [
          SizedBox(
            width: 90,
            child: Text(
              label,
              style: bad ? TextStyle(color: theme.colorScheme.error) : null,
            ),
          ),
          Expanded(
            child: ClipRRect(
              borderRadius: BorderRadius.circular(5),
              child: LinearProgressIndicator(
                value: top == 0 ? 0 : count / top,
                minHeight: 10,
                color: colour,
                backgroundColor: EzBiziTheme.edge(context),
              ),
            ),
          ),
          SizedBox(
            width: 90,
            child: Text(trailing, textAlign: TextAlign.right),
          ),
        ],
      ),
    );
  }

  Widget _requestsTab(ThemeData theme) {
    final rows = _requests;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16),
          child: Wrap(
            spacing: 6,
            runSpacing: 6,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              SizedBox(
                width: 240,
                child: TextField(
                  key: const ValueKey('ekomi-requests-search'),
                  decoration: const InputDecoration(
                    prefixIcon: Icon(Icons.search, size: 18),
                    hintText: 'Name, email or job',
                    isDense: true,
                  ),
                  onSubmitted: (v) {
                    _q = v;
                    _loadTab();
                  },
                ),
              ),
              for (final e in _statuses.entries)
                FilterChip(
                  key: ValueKey('ekomi-status-${e.key}'),
                  label: Text(e.value),
                  selected: _status == e.key,
                  onSelected: (on) {
                    setState(() => _status = on ? e.key : null);
                    _loadTab();
                  },
                ),
              for (final e in _channels.entries)
                FilterChip(
                  key: ValueKey('ekomi-channel-${e.key}'),
                  label: Text(e.value),
                  selected: _channel == e.key,
                  onSelected: (on) {
                    setState(() => _channel = on ? e.key : null);
                    _loadTab();
                  },
                ),
              FilledButton.icon(
                key: const ValueKey('ekomi-ask'),
                icon: const Icon(Icons.send, size: 16),
                label: const Text('Send review request'),
                onPressed: _ask,
              ),
            ],
          ),
        ),
        const SizedBox(height: 8),
        Expanded(
          child: _requestsError != null
              ? _failed(theme, _requestsError!)
              : rows == null
              ? _loading('Reading eKomi…')
              : rows.isEmpty
              ? const Center(child: Text('Nobody was asked in these dates.'))
              : ListView.separated(
                  padding: const EdgeInsets.symmetric(horizontal: 16),
                  itemCount: rows.length,
                  separatorBuilder: (_, _) => const Divider(height: 1),
                  itemBuilder: (context, i) => _requestRow(theme, rows[i]),
                ),
        ),
      ],
    );
  }

  Widget _requestRow(ThemeData theme, Map<String, dynamic> r) {
    final status = '${r['status'] ?? ''}';
    final bounce = r['bounce_reason'] as String?;
    final at = DateTime.tryParse('${r['created_at'] ?? ''}');
    final pill = StatusPill(
      [
        _statuses[status] ?? status,
        if (bounce != null && bounce.isNotEmpty) bounce,
      ].join(' · '),
      tone: switch (status) {
        'completed' => Tone.ok,
        'interrupted' => Tone.bad,
        _ => Tone.neutral,
      },
    );
    return Padding(
      key: ValueKey('ekomi-request-${r['order_id']}-${r['email']}'),
      padding: const EdgeInsets.symmetric(vertical: 8),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            '${r['name'] ?? r['email'] ?? ''}',
            style: theme.textTheme.titleSmall,
          ),
          Text(
            [
              ?r['email'] as String?,
              if (r['order_id'] != null) 'Job ${r['order_id']}',
              _channels[r['channel']] ?? '${r['channel'] ?? ''}',
              if (at != null) _formats.formatDate(at.toLocal()),
            ].where((p) => p.isNotEmpty).join(' · '),
            style: theme.textTheme.bodySmall,
          ),
          const SizedBox(height: 4),
          pill,
        ],
      ),
    );
  }

  /// Ask one job's customer: pick the job, then one POST.
  Future<void> _ask() async {
    final id = _id;
    if (id == null) return;
    final job = await showSidePanel<Map<String, dynamic>>(
      context,
      builder: (context) => _JobChooser(api: widget.api),
    );
    if (job == null || !mounted) return;
    final r = await widget.api.post('/ekomi/$id/requests', {
      'job_id': job['id'],
    });
    if (!mounted) return;
    final data = r.ok && r.data is Map ? r.data as Map : const {};
    final message = !r.ok
        ? r.message
        : data['ok'] == true
        ? 'Review request sent.'
        : data['never'] == true
        ? 'eKomi will never ask this person (${data['message'] ?? 'skipped'}).'
        : '${data['message'] ?? 'Not sent.'}';
    ScaffoldMessenger.maybeOf(
      context,
    )?.showSnackBar(SnackBar(content: Text(message)));
    if (r.ok && data['ok'] == true) _loadTab();
  }

  Widget _reviewsTab(ThemeData theme) {
    if (_reviewsError != null) return _failed(theme, _reviewsError!);
    final rows = _reviews;
    if (rows == null) return _loading('Loading reviews…');
    if (rows.isEmpty) {
      return const Center(child: Text('No eKomi reviews in these dates.'));
    }
    return ListView.separated(
      key: const ValueKey('ekomi-reviews'),
      padding: const EdgeInsets.symmetric(horizontal: 16),
      itemCount: rows.length,
      separatorBuilder: (_, _) => const Divider(height: 1),
      itemBuilder: (context, i) {
        final r = rows[i];
        final rating = (r['rating'] as num?)?.toInt() ?? 0;
        final at = DateTime.tryParse('${r['reviewed_at'] ?? ''}');
        return ListTile(
          contentPadding: EdgeInsets.zero,
          title: Text(
            '${'★' * rating}${'☆' * (5 - rating).clamp(0, 5)}  '
            '${r['reviewer_name'] ?? ''}',
          ),
          subtitle: Text('${r['comment'] ?? ''}'),
          trailing: at == null
              ? null
              : Text(
                  _formats.formatDate(at.toLocal()),
                  style: theme.textTheme.bodySmall,
                ),
        );
      },
    );
  }
}

extension on String {
  String ifEmpty(String other) => isEmpty ? other : this;
}

/// Pick a job to ask about: searches /jobs as you type.
class _JobChooser extends StatefulWidget {
  const _JobChooser({required this.api});

  final ApiClient api;

  @override
  State<_JobChooser> createState() => _JobChooserState();
}

class _JobChooserState extends State<_JobChooser> {
  List<Map<String, dynamic>> _rows = const [];
  int _request = 0;

  Future<void> _search(String q) async {
    final request = ++_request;
    final r = await widget.api.get('/jobs', {
      'search': q.trim(),
      'per_page': '30',
    });
    if (!mounted || request != _request) return;
    setState(() => _rows = _EkomiViewState._list(r.ok ? r.data : null));
  }

  @override
  Widget build(BuildContext context) => SidePanel(
    key: const ValueKey('ekomi-ask-panel'),
    title: 'Send review request',
    subtitle: "eKomi emails the job's customer once.",
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        TextField(
          key: const ValueKey('ekomi-ask-search'),
          autofocus: true,
          decoration: const InputDecoration(
            prefixIcon: Icon(Icons.search, size: 18),
            hintText: 'Job number, customer or address',
            isDense: true,
          ),
          onChanged: _search,
        ),
        const SizedBox(height: 8),
        for (final j in _rows)
          ListTile(
            key: ValueKey('ekomi-ask-job-${j['id']}'),
            dense: true,
            contentPadding: EdgeInsets.zero,
            title: Text('${j['number'] ?? ''} · ${j['title'] ?? ''}'),
            onTap: () => Navigator.of(context).pop(j),
          ),
      ],
    ),
  );
}

/// Credentials and automatic requests: PUT /ekomi/{id}/settings, and Test.
class _EkomiSettings extends StatefulWidget {
  const _EkomiSettings({
    super.key,
    required this.api,
    required this.id,
    required this.account,
    required this.onSaved,
  });

  final ApiClient api;
  final String id;
  final Map<String, dynamic> account;
  final VoidCallback onSaved;

  @override
  State<_EkomiSettings> createState() => _EkomiSettingsState();
}

class _EkomiSettingsState extends State<_EkomiSettings> {
  late final _shop = TextEditingController(
    text: '${widget.account['shop_id'] ?? ''}',
  );
  final _password = TextEditingController();
  late final Map<String, dynamic> _s = Map<String, dynamic>.from(
    widget.account['settings'] as Map? ?? const {},
  );
  late final _delay = TextEditingController(text: '${_s['delay_hours'] ?? 24}');
  String? _note;
  bool _busy = false;

  @override
  void dispose() {
    _shop.dispose();
    _password.dispose();
    _delay.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    setState(() {
      _busy = true;
      _note = null;
    });
    final r = await widget.api.put('/ekomi/${widget.id}/settings', {
      'shop_id': _shop.text.trim(),
      // Blank keeps the old one.
      if (_password.text.isNotEmpty) 'password': _password.text,
      'auto_request': _s['auto_request'] == true,
      'delay_hours': int.tryParse(_delay.text.trim()) ?? 24,
      'start_date': ?_s['start_date'] as String?,
    });
    if (!mounted) return;
    setState(() {
      _busy = false;
      _note = r.ok ? 'Saved.' : r.message;
      if (r.ok && r.data is Map) {
        _s.addAll(Map<String, dynamic>.from(r.data as Map));
      }
    });
    if (r.ok) {
      _password.clear();
      widget.onSaved();
    }
  }

  Future<void> _test() async {
    setState(() {
      _busy = true;
      _note = null;
    });
    final r = await widget.api.post('/ekomi/${widget.id}/test', const {});
    if (!mounted) return;
    final ok = r.ok && r.data is Map && (r.data as Map)['ok'] == true;
    setState(() {
      _busy = false;
      _note = ok ? 'eKomi answered.' : r.message;
    });
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final start = _s['start_date'] as String?;
    return ListView(
      key: const ValueKey('ekomi-settings'),
      padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
      children: [
        Text('Connection', style: theme.textTheme.titleSmall),
        const SizedBox(height: 8),
        TextField(
          key: const ValueKey('ekomi-shop'),
          controller: _shop,
          decoration: const InputDecoration(labelText: 'Shop id'),
        ),
        const SizedBox(height: 8),
        TextField(
          key: const ValueKey('ekomi-password'),
          controller: _password,
          obscureText: true,
          decoration: const InputDecoration(
            labelText: 'Password',
            helperText: 'Leave blank to keep the saved one.',
          ),
        ),
        const SizedBox(height: 8),
        Align(
          alignment: Alignment.centerLeft,
          child: OutlinedButton(
            key: const ValueKey('ekomi-test'),
            onPressed: _busy ? null : _test,
            child: const Text('Test connection'),
          ),
        ),
        const SizedBox(height: 20),
        Text('Automatic requests', style: theme.textTheme.titleSmall),
        SwitchListTile(
          key: const ValueKey('ekomi-auto'),
          contentPadding: EdgeInsets.zero,
          title: const Text('Ask when a job is Done'),
          subtitle: const Text('Once per job, only customers with an email.'),
          value: _s['auto_request'] == true,
          onChanged: (v) => setState(() => _s['auto_request'] = v),
        ),
        TextField(
          key: const ValueKey('ekomi-delay'),
          controller: _delay,
          keyboardType: TextInputType.number,
          decoration: const InputDecoration(
            labelText: 'Wait (hours) after the job is Done',
            helperText: '0 to 720',
          ),
        ),
        const SizedBox(height: 8),
        Text(
          start == null
              ? 'Only jobs Done from the day this is switched on.'
              : 'Only jobs Done on or after $start.',
          key: const ValueKey('ekomi-start'),
          style: theme.textTheme.bodySmall,
        ),
        const SizedBox(height: 16),
        Row(
          children: [
            FilledButton(
              key: const ValueKey('ekomi-save'),
              onPressed: _busy ? null : _save,
              child: const Text('Save'),
            ),
            const SizedBox(width: 12),
            if (_note != null)
              Expanded(child: Text(_note!, key: const ValueKey('ekomi-note'))),
          ],
        ),
      ],
    );
  }
}
