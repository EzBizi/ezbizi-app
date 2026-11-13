import 'dart:typed_data';

import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/files.dart';
import '../../core/session.dart';
import '../../widgets/error_note.dart';

/// Customers › Memberships › Promotions (Jose, 2026-11-06, 2026-11-09).
///
/// Pick the dates of Done jobs, narrow the list with filters, untick anyone,
/// see the text as the first customer's phone shows it, test it on your own
/// phone, then send. One text per customer phone, never to a number that
/// replied STOP, always with the opt-out line. Answers land in
/// Leads › Promotions. Sending takes `memberships.edit`.
class MembershipsPromotions extends StatefulWidget {
  const MembershipsPromotions({
    super.key,
    required this.api,
    required this.session,
  });

  final ApiClient api;
  final Session session;

  @override
  State<MembershipsPromotions> createState() => _MembershipsPromotionsState();
}

const _footer = 'Reply STOP to opt out.';
const _maxPictureBytes = 5 * 1024 * 1024;

enum _Preset {
  lastMonth('Last month'),
  last3('Last 3 months'),
  last6('Last 6 months'),
  last12('Last 12 months'),
  sameMonthLastYear('Same month last year'),
  custom('Custom');

  const _Preset(this.label);
  final String label;
}

class _MembershipsPromotionsState extends State<MembershipsPromotions> {
  _Preset? _preset;
  DateTime? _from;
  DateTime? _to;

  List<Map<String, dynamic>> _rows = const [];
  Map<String, dynamic> _serviceNames = const {};
  List<dynamic> _senders = const [];
  int _optedOut = 0;
  bool _loaded = false;

  // Filters. An empty set means "any".
  final _branches = <String>{};
  final _types = <String>{};
  final _services = <String>{};
  final _jobTags = <String>{};
  final _customerTags = <String>{};
  final _amountMin = TextEditingController();
  final _amountMax = TextEditingController();
  DateTime? _noJobSince;
  bool _excludeOpen = false;
  bool _excludeMembers = false;
  final _unticked = <String>{};
  final _search = TextEditingController();

  final _body = TextEditingController();
  final _testTo = TextEditingController();
  PickedBytes? _picture;
  String? _picturePath;

  List<dynamic> _sent = const [];
  String? _error;
  String? _note;
  bool _busy = false;

  /// Second press of Send confirms, in place (no dialogs in this app).
  bool _confirming = false;

  bool get _canSend => widget.session.me?.can('memberships.edit') ?? false;

  @override
  void initState() {
    super.initState();
    _loadSent();
    // Open on a range, so the audience count and the composer are there at
    // once; an empty page until a preset was tapped read as unbuilt (UI audit).
    _choosePreset(_Preset.lastMonth);
  }

  @override
  void dispose() {
    for (final c in [_amountMin, _amountMax, _search, _body, _testTo]) {
      c.dispose();
    }
    super.dispose();
  }

  static String _day(DateTime d) =>
      '${d.year}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

  Future<void> _loadSent() async {
    final response = await widget.api.get('/memberships/promotions');
    if (!mounted || !response.ok) return;
    setState(() => _sent = (response.data as List?) ?? const []);
  }

  // ── Dates ──────────────────────────────────────────────────────────────

  Future<void> _choosePreset(_Preset preset) async {
    final now = DateTime.now();
    final today = DateTime(now.year, now.month, now.day);
    DateTime monthsAgo(int n) =>
        DateTime(today.year, today.month - n, today.day);
    final (from, to) = switch (preset) {
      _Preset.lastMonth => (
        DateTime(today.year, today.month - 1, 1),
        DateTime(today.year, today.month, 0),
      ),
      _Preset.last3 => (monthsAgo(3), today),
      _Preset.last6 => (monthsAgo(6), today),
      _Preset.last12 => (monthsAgo(12), today),
      _Preset.sameMonthLastYear => (
        DateTime(today.year - 1, today.month, 1),
        DateTime(today.year - 1, today.month + 1, 0),
      ),
      _Preset.custom => (_from ?? monthsAgo(1), _to ?? today),
    };
    setState(() {
      _preset = preset;
      _from = from;
      _to = to;
    });
    await _loadPreview();
  }

  Future<void> _pickDay({required bool from}) async {
    final now = DateTime.now();
    final picked = await showDatePicker(
      context: context,
      firstDate: DateTime(now.year - 5),
      lastDate: now,
      initialDate: (from ? _from : _to) ?? now,
    );
    if (picked == null || !mounted) return;
    setState(() {
      _preset = _Preset.custom;
      if (from) {
        _from = picked;
        if (_to != null && _to!.isBefore(picked)) _to = picked;
      } else {
        _to = picked;
        if (_from != null && _from!.isAfter(picked)) _from = picked;
      }
      _from ??= picked;
      _to ??= picked;
    });
    await _loadPreview();
  }

  Future<void> _pickRange() async {
    final now = DateTime.now();
    final picked = await showDateRangePicker(
      context: context,
      firstDate: DateTime(now.year - 5),
      lastDate: now,
      initialDateRange: _from == null
          ? null
          : DateTimeRange(start: _from!, end: _to!),
    );
    if (picked == null || !mounted) return;
    setState(() {
      _preset = _Preset.custom;
      _from = picked.start;
      _to = picked.end;
    });
    await _loadPreview();
  }

  Future<void> _loadPreview() async {
    setState(() {
      _busy = true;
      _error = null;
      _confirming = false;
    });
    final response = await widget.api.get('/memberships/promotions/preview', {
      'from': _day(_from!),
      'to': _day(_to!),
    });
    if (!mounted) return;
    setState(() {
      _busy = false;
      if (!response.ok) {
        _error = response.reason;
        return;
      }
      final data = Map<String, dynamic>.from(response.data as Map);
      _rows = [
        for (final r in (data['rows'] as List? ?? const []))
          Map<String, dynamic>.from(r as Map),
      ];
      _serviceNames = Map<String, dynamic>.from(
        (data['services'] is Map ? data['services'] : const {}) as Map,
      );
      _senders = (data['senders'] as List?) ?? const [];
      _optedOut = (data['opted_out'] as num?)?.toInt() ?? 0;
      _loaded = true;
      _unticked.clear();
      if (!_canPicture) {
        _picture = null;
        _picturePath = null;
      }
      if (_body.text.trim().isEmpty) _body.text = '${data['message'] ?? ''}';
    });
  }

  // ── Filters ────────────────────────────────────────────────────────────

  static List<String> _strings(dynamic v) => [
    for (final x in (v as List? ?? const [])) '$x',
  ];

  bool _jobMatches(Map j) {
    if (_types.isNotEmpty && !_types.contains('${j['type'] ?? ''}')) {
      return false;
    }
    if (_jobTags.isNotEmpty && !_strings(j['tags']).any(_jobTags.contains)) {
      return false;
    }
    if (_services.isNotEmpty &&
        !_strings(j['services']).any(_services.contains)) {
      return false;
    }
    final min = double.tryParse(_amountMin.text.trim());
    final max = double.tryParse(_amountMax.text.trim());
    final amount = (j['amount'] as num?)?.toDouble();
    if (min != null && (amount == null || amount < min)) return false;
    if (max != null && (amount == null || amount > max)) return false;
    return true;
  }

  bool _matches(Map<String, dynamic> r) {
    if (_branches.isNotEmpty && !_branches.contains('${r['franchise']}')) {
      return false;
    }
    if (_customerTags.isNotEmpty &&
        !_strings(r['customer_tags']).any(_customerTags.contains)) {
      return false;
    }
    final since = _noJobSince;
    final last = r['last_job'] as String?;
    if (since != null && last != null && last.compareTo(_day(since)) >= 0) {
      return false;
    }
    if (_excludeOpen && r['open_job'] == true) return false;
    if (_excludeMembers && r['member'] == true) return false;
    return (r['jobs'] as List? ?? const []).any((j) => _jobMatches(j as Map));
  }

  List<Map<String, dynamic>> get _filtered => _rows.where(_matches).toList();

  List<Map<String, dynamic>> get _chosen => [
    for (final r in _filtered)
      if (!_unticked.contains(r['phone'])) r,
  ];

  void _changed(VoidCallback f) => setState(() {
    f();
    _confirming = false;
  });

  Widget _chips(
    String label,
    Iterable<String> values,
    Set<String> picked, {
    String Function(String)? name,
  }) {
    final options = ({...values}..remove('')).toList()..sort();
    if (options.isEmpty) return const SizedBox.shrink();
    return Padding(
      padding: const EdgeInsets.only(top: 8),
      child: Wrap(
        spacing: 6,
        runSpacing: 6,
        crossAxisAlignment: WrapCrossAlignment.center,
        children: [
          Text(label, style: Theme.of(context).textTheme.labelLarge),
          for (final o in options)
            FilterChip(
              label: Text(name?.call(o) ?? o),
              selected: picked.contains(o),
              onSelected: (on) =>
                  _changed(() => on ? picked.add(o) : picked.remove(o)),
            ),
        ],
      ),
    );
  }

  Widget _filters(ThemeData theme) {
    final jobs = [
      for (final r in _rows)
        for (final j in (r['jobs'] as List? ?? const [])) j as Map,
    ];
    return Column(
      key: const ValueKey('promotions-filters'),
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text('Filters', style: theme.textTheme.titleSmall),
        _chips(
          'Branch',
          _rows.map((r) => '${r['franchise'] ?? ''}'),
          _branches,
        ),
        _chips('Job type', jobs.map((j) => '${j['type'] ?? ''}'), _types),
        _chips(
          'Service',
          jobs.expand((j) => _strings(j['services'])),
          _services,
          name: (id) => '${_serviceNames[id] ?? id}',
        ),
        _chips('Job tags', jobs.expand((j) => _strings(j['tags'])), _jobTags),
        _chips(
          'Customer tags',
          _rows.expand((r) => _strings(r['customer_tags'])),
          _customerTags,
        ),
        const SizedBox(height: 8),
        Wrap(
          spacing: 12,
          runSpacing: 8,
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            SizedBox(
              width: 130,
              child: TextField(
                key: const ValueKey('promotions-amount-min'),
                controller: _amountMin,
                keyboardType: TextInputType.number,
                decoration: const InputDecoration(labelText: 'Job \$ from'),
                onChanged: (_) => _changed(() {}),
              ),
            ),
            SizedBox(
              width: 130,
              child: TextField(
                key: const ValueKey('promotions-amount-max'),
                controller: _amountMax,
                keyboardType: TextInputType.number,
                decoration: const InputDecoration(labelText: 'Job \$ to'),
                onChanged: (_) => _changed(() {}),
              ),
            ),
            OutlinedButton.icon(
              key: const ValueKey('promotions-no-job-since'),
              icon: const Icon(Icons.event_busy),
              label: Text(
                _noJobSince == null
                    ? 'No job since…'
                    : 'No job since ${_day(_noJobSince!)}',
              ),
              onPressed: () async {
                final now = DateTime.now();
                final picked = await showDatePicker(
                  context: context,
                  firstDate: DateTime(now.year - 5),
                  lastDate: DateTime(now.year + 1),
                  initialDate: _noJobSince ?? _to ?? now,
                );
                if (picked != null && mounted) {
                  _changed(() => _noJobSince = picked);
                }
              },
            ),
            if (_noJobSince != null)
              IconButton(
                tooltip: 'Clear',
                icon: const Icon(Icons.close),
                onPressed: () => _changed(() => _noJobSince = null),
              ),
          ],
        ),
        CheckboxListTile(
          key: const ValueKey('promotions-exclude-open'),
          contentPadding: EdgeInsets.zero,
          controlAffinity: ListTileControlAffinity.leading,
          value: _excludeOpen,
          onChanged: (v) => _changed(() => _excludeOpen = v ?? false),
          title: const Text(
            'Leave out customers with an open or scheduled job',
          ),
        ),
        CheckboxListTile(
          key: const ValueKey('promotions-exclude-members'),
          contentPadding: EdgeInsets.zero,
          controlAffinity: ListTileControlAffinity.leading,
          value: _excludeMembers,
          onChanged: (v) => _changed(() => _excludeMembers = v ?? false),
          title: const Text('Leave out members'),
        ),
      ],
    );
  }

  Widget _list(ThemeData theme, List<Map<String, dynamic>> filtered) {
    final q = _search.text.trim().toLowerCase();
    final shown = q.isEmpty
        ? filtered
        : [
            for (final r in filtered)
              if ('${r['name'] ?? ''} ${r['phone']}'.toLowerCase().contains(q))
                r,
          ];
    return Column(
      key: const ValueKey('promotions-list'),
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        TextField(
          key: const ValueKey('promotions-search'),
          controller: _search,
          decoration: const InputDecoration(
            prefixIcon: Icon(Icons.search),
            hintText: 'Search names and phones',
          ),
          onChanged: (_) => setState(() {}),
        ),
        const SizedBox(height: 4),
        SizedBox(
          height: 320,
          child: ListView.builder(
            itemCount: shown.length,
            itemBuilder: (context, i) {
              final r = shown[i];
              final phone = '${r['phone']}';
              final job = (r['jobs'] as List? ?? const []).firstOrNull as Map?;
              return CheckboxListTile(
                key: ValueKey('promotions-row-$phone'),
                dense: true,
                controlAffinity: ListTileControlAffinity.leading,
                value: !_unticked.contains(phone),
                onChanged: (on) => _changed(
                  () => on == true
                      ? _unticked.remove(phone)
                      : _unticked.add(phone),
                ),
                title: Text('${r['name'] ?? 'No name'} · $phone'),
                subtitle: Text(
                  [
                    '${r['job_date'] ?? ''}',
                    if (job?['type'] != null) '${job!['type']}',
                    if (job?['amount'] != null) '\$${job!['amount']}',
                    if (r['franchise'] != null) '${r['franchise']}',
                  ].join(' · '),
                ),
              );
            },
          ),
        ),
      ],
    );
  }

  // ── Message, picture, test ─────────────────────────────────────────────

  bool get _canPicture =>
      _senders.isNotEmpty && _senders.every((s) => (s as Map)['mms'] == true);

  /// What the first customer on the list receives.
  String _rendered(Map<String, dynamic>? first) {
    final body = _body.text
        .trim()
        .replaceAll('{first_name}', '${first?['first_name'] ?? 'there'}')
        .replaceAll('{company}', '${first?['company'] ?? ''}');
    return body.toUpperCase().contains('STOP') ? body : '$body\n\n$_footer';
  }

  Future<void> _addPicture() async {
    final picked = await Files.pickImage();
    if (picked == null || !mounted) return;
    final ext = picked.name.split('.').last.toLowerCase();
    if (!{'jpg', 'jpeg', 'png', 'gif'}.contains(ext)) {
      setState(() => _error = 'Pictures must be JPG, PNG or GIF.');
      return;
    }
    if (picked.bytes.length > _maxPictureBytes) {
      setState(() => _error = 'Pictures must be 5 MB or smaller.');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    final response = await widget.api.postFile(
      '/memberships/promotions/media',
      'image',
      picked.name,
      picked.bytes,
    );
    if (!mounted) return;
    setState(() {
      _busy = false;
      if (!response.ok) {
        _error = response.reason;
        return;
      }
      _picture = picked;
      _picturePath = '${(response.data as Map)['media_path']}';
      _confirming = false;
    });
  }

  Future<void> _sendTest(Map<String, dynamic> first) async {
    setState(() {
      _busy = true;
      _error = null;
      _note = null;
    });
    final response = await widget.api.post('/memberships/promotions/test', {
      'to': _testTo.text.trim(),
      'body': _body.text.trim(),
      'franchise_id': first['franchise_id'],
      'first_name': first['first_name'],
      'company': first['company'],
      if (_picturePath != null) 'media_path': _picturePath,
    });
    if (!mounted) return;
    setState(() {
      _busy = false;
      if (!response.ok) {
        _error = response.reason;
        return;
      }
      _note = 'Test sent to ${(response.data as Map)['to']}.';
    });
  }

  Future<void> _send(List<Map<String, dynamic>> chosen) async {
    if (!_confirming) {
      setState(() => _confirming = true);
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    final response = await widget.api.post('/memberships/promotions', {
      'from': _day(_from!),
      'to': _day(_to!),
      'body': _body.text.trim(),
      'phones': [for (final r in chosen) r['phone']],
      if (_picturePath != null) 'media_path': _picturePath,
    });
    if (!mounted) return;
    setState(() {
      _busy = false;
      _confirming = false;
      if (!response.ok) {
        _error = response.reason;
        return;
      }
      _note = 'Sending to ${(response.data as Map)['recipients']} customers.';
      _rows = const [];
      _loaded = false;
      _preset = null;
      _from = null;
      _to = null;
      _picture = null;
      _picturePath = null;
    });
    await _loadSent();
  }

  // ── Build ──────────────────────────────────────────────────────────────

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final filtered = _loaded ? _filtered : const <Map<String, dynamic>>[];
    final chosen = _loaded ? _chosen : const <Map<String, dynamic>>[];
    final first = chosen.firstOrNull;
    final rendered = _rendered(first);
    final senders = [for (final s in _senders) '${(s as Map)['e164']}'];

    return ListView(
      key: const ValueKey('memberships-promotions'),
      padding: const EdgeInsets.all(16),
      children: [
        Text(
          'Text the customers of Done jobs',
          style: theme.textTheme.titleMedium,
        ),
        const SizedBox(height: 4),
        Text(
          'One text per phone. Numbers that replied STOP are skipped and every '
          'text ends with "$_footer" Answers arrive in Leads › Promotions.',
          style: theme.textTheme.bodySmall,
        ),
        const SizedBox(height: 16),
        Wrap(
          key: const ValueKey('promotions-dates'),
          spacing: 6,
          runSpacing: 6,
          children: [
            for (final p in _Preset.values)
              ChoiceChip(
                label: Text(p.label),
                selected: _preset == p,
                onSelected: _busy ? null : (_) => _choosePreset(p),
              ),
          ],
        ),
        if (_preset != null) ...[
          const SizedBox(height: 8),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              OutlinedButton(
                key: const ValueKey('promotions-from'),
                onPressed: _busy ? null : () => _pickDay(from: true),
                child: Text('From ${_from == null ? '—' : _day(_from!)}'),
              ),
              OutlinedButton(
                key: const ValueKey('promotions-to'),
                onPressed: _busy ? null : () => _pickDay(from: false),
                child: Text('To ${_to == null ? '—' : _day(_to!)}'),
              ),
              TextButton.icon(
                onPressed: _busy ? null : _pickRange,
                icon: const Icon(Icons.date_range),
                label: const Text('Pick on a calendar'),
              ),
            ],
          ),
        ],
        if (_error != null) ...[const SizedBox(height: 12), ErrorNote(_error!)],
        if (_note != null) ...[
          const SizedBox(height: 12),
          Text(_note!, key: const ValueKey('promotions-note')),
        ],
        if (_loaded) ...[
          const SizedBox(height: 16),
          _filters(theme),
          const SizedBox(height: 12),
          Text(
            '${chosen.length} customers will be texted'
            '${filtered.length == _rows.length ? '' : ' (${_rows.length} before filters)'}'
            '${_optedOut == 0 ? '' : '; $_optedOut replied STOP and are skipped'}.',
            key: const ValueKey('promotions-count'),
            style: theme.textTheme.bodyLarge,
          ),
          const SizedBox(height: 8),
          _list(theme, filtered),
          const SizedBox(height: 16),
          Text(
            senders.isEmpty
                ? 'No texting line is cleared to send for these branches.'
                : 'Sends from ${senders.join(', ')}',
            key: const ValueKey('promotions-sender'),
            style: theme.textTheme.titleSmall,
          ),
          const SizedBox(height: 8),
          TextField(
            key: const ValueKey('promotions-body'),
            controller: _body,
            minLines: 3,
            maxLines: 6,
            maxLength: 600,
            onChanged: (_) => _changed(() {}),
            decoration: const InputDecoration(
              labelText: 'Message',
              helperText:
                  '{first_name} and {company} are filled in for each customer.',
            ),
          ),
          Text(
            _segments(rendered, picture: _picture != null),
            key: const ValueKey('promotions-segments'),
            style: theme.textTheme.bodySmall,
          ),
          const SizedBox(height: 8),
          if (_canPicture)
            Wrap(
              spacing: 8,
              crossAxisAlignment: WrapCrossAlignment.center,
              children: [
                OutlinedButton.icon(
                  key: const ValueKey('promotions-picture'),
                  onPressed: _busy || !_canSend ? null : _addPicture,
                  icon: const Icon(Icons.image_outlined),
                  label: Text(
                    _picture == null ? 'Add a picture' : 'Change picture',
                  ),
                ),
                if (_picture != null)
                  TextButton(
                    onPressed: () => _changed(() {
                      _picture = null;
                      _picturePath = null;
                    }),
                    child: const Text('Remove picture'),
                  ),
                Text(
                  'JPG, PNG or GIF, up to 5 MB.',
                  style: theme.textTheme.bodySmall,
                ),
              ],
            )
          else if (senders.isNotEmpty)
            Text(
              '${senders.join(', ')} cannot send pictures, so the text goes without one.',
              key: const ValueKey('promotions-no-picture'),
              style: theme.textTheme.bodySmall,
            ),
          const SizedBox(height: 16),
          Text(
            first == null
                ? 'How it looks'
                : 'How it looks to ${first['name'] ?? first['phone']}',
            style: theme.textTheme.titleSmall,
          ),
          const SizedBox(height: 8),
          Wrap(
            key: const ValueKey('promotions-phones'),
            spacing: 16,
            runSpacing: 16,
            children: [
              _PhoneMock(
                ios: false,
                sender: senders.firstOrNull ?? '',
                text: rendered,
                picture: _picture,
              ),
              _PhoneMock(
                ios: true,
                sender: senders.firstOrNull ?? '',
                text: rendered,
                picture: _picture,
              ),
            ],
          ),
          if (_canSend) ...[
            const SizedBox(height: 16),
            Wrap(
              key: const ValueKey('promotions-test'),
              spacing: 8,
              runSpacing: 8,
              crossAxisAlignment: WrapCrossAlignment.center,
              children: [
                SizedBox(
                  width: 200,
                  child: TextField(
                    key: const ValueKey('promotions-test-to'),
                    controller: _testTo,
                    keyboardType: TextInputType.phone,
                    decoration: const InputDecoration(labelText: 'Your phone'),
                    onChanged: (_) => setState(() {}),
                  ),
                ),
                OutlinedButton.icon(
                  key: const ValueKey('promotions-test-send'),
                  onPressed:
                      _busy || first == null || _testTo.text.trim().isEmpty
                      ? null
                      : () => _sendTest(first),
                  icon: const Icon(Icons.science_outlined),
                  label: const Text('Send me a test'),
                ),
                Text(
                  'One text marked TEST, not part of the promotion.',
                  style: theme.textTheme.bodySmall,
                ),
              ],
            ),
            const SizedBox(height: 16),
            Align(
              alignment: Alignment.centerLeft,
              child: FilledButton.icon(
                key: const ValueKey('promotions-send'),
                onPressed:
                    _busy ||
                        chosen.isEmpty ||
                        _body.text.trim().isEmpty ||
                        senders.isEmpty
                    ? null
                    : () => _send(chosen),
                icon: const Icon(Icons.send),
                label: Text(
                  _confirming
                      ? 'Press again to text ${chosen.length} customers'
                      : 'Send',
                ),
              ),
            ),
          ] else
            Text(
              'Sending needs permission to edit memberships.',
              style: theme.textTheme.bodySmall,
            ),
        ],
        if (_sent.isNotEmpty) ...[
          const SizedBox(height: 24),
          Text('Sent', style: theme.textTheme.titleSmall),
          for (final p in _sent)
            ListTile(
              contentPadding: EdgeInsets.zero,
              title: Text(
                'Jobs ${p['jobs_from']} to ${p['jobs_to']}: '
                '${p['sent']} of ${p['recipients']} sent'
                '${(p['failed'] ?? 0) == 0 ? '' : ', ${p['failed']} failed'}'
                ', ${p['replies']} replies'
                '${p['picture'] == true ? ', with a picture' : ''}'
                '${p['finished_at'] == null ? ' (sending)' : ''}',
              ),
              subtitle: Text(
                '${p['body']}',
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
              ),
            ),
        ],
      ],
    );
  }
}

// GSM-7: the alphabet a plain text is sent in. Anything else (an emoji, a
// curly quote) sends the whole text as UCS-2, which fits far fewer letters.
const _gsm =
    '@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !"#¤%&\'()*+,-./0123456789:;<=>?'
    '¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà';
const _gsmExtended = '^{}\\[~]|€';

/// "143 characters · 1 text": how many SMS segments a carrier bills for.
String _segments(String text, {required bool picture}) {
  if (picture) {
    return '${text.length} characters · sent as one picture message (MMS)';
  }
  final plain = text.runes.every(
    (r) =>
        _gsm.contains(String.fromCharCode(r)) ||
        _gsmExtended.contains(String.fromCharCode(r)),
  );
  final units = plain
      ? text.length + text.split('').where(_gsmExtended.contains).length
      : text.length;
  final (single, multi) = plain ? (160, 153) : (70, 67);
  final segments = units <= single ? 1 : (units / multi).ceil();
  return '$units characters · $segments text${segments == 1 ? '' : 's'}'
      '${plain ? '' : ' (has a character outside plain text, so fewer fit)'}';
}

/// An Android or iPhone screen showing the text as it arrives.
class _PhoneMock extends StatelessWidget {
  const _PhoneMock({
    required this.ios,
    required this.sender,
    required this.text,
    required this.picture,
  });

  final bool ios;
  final String sender;
  final String text;
  final PickedBytes? picture;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final bubble = ios
        ? scheme.surfaceContainerHighest
        : scheme.secondaryContainer;
    final ink = ios ? scheme.onSurface : scheme.onSecondaryContainer;
    final radius = ios ? 18.0 : 20.0;
    return Container(
      key: ValueKey(ios ? 'promotions-ios' : 'promotions-android'),
      width: 260,
      height: 460,
      padding: const EdgeInsets.all(10),
      decoration: BoxDecoration(
        color: scheme.surface,
        border: Border.all(color: scheme.outline, width: 6),
        borderRadius: BorderRadius.circular(ios ? 36 : 24),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            ios ? 'iPhone' : 'Android',
            textAlign: TextAlign.center,
            style: theme.textTheme.labelSmall,
          ),
          const SizedBox(height: 4),
          Row(
            mainAxisAlignment: ios
                ? MainAxisAlignment.center
                : MainAxisAlignment.start,
            children: [
              Icon(ios ? Icons.chevron_left : Icons.arrow_back, size: 18),
              const SizedBox(width: 4),
              Flexible(
                child: Text(
                  sender,
                  overflow: TextOverflow.ellipsis,
                  style: theme.textTheme.titleSmall,
                ),
              ),
            ],
          ),
          Divider(color: scheme.outlineVariant),
          Expanded(
            child: SingleChildScrollView(
              child: Align(
                alignment: Alignment.topLeft,
                child: ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: 200),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      if (picture != null)
                        Padding(
                          padding: const EdgeInsets.only(bottom: 4),
                          child: ClipRRect(
                            borderRadius: BorderRadius.circular(radius),
                            child: Image.memory(
                              Uint8List.fromList(picture!.bytes),
                              fit: BoxFit.cover,
                            ),
                          ),
                        ),
                      Container(
                        padding: const EdgeInsets.symmetric(
                          horizontal: 12,
                          vertical: 8,
                        ),
                        decoration: BoxDecoration(
                          color: bubble,
                          borderRadius: BorderRadius.circular(radius),
                        ),
                        child: Text(
                          text,
                          style: theme.textTheme.bodyMedium?.copyWith(
                            color: ink,
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}
