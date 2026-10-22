// Leads › Manage › Office hours (UI queue 138, docs/previews/
// leads-office-hours-layouts.html, Jose's 1 + 4 on 2026-10-22: "better, go
// ahead, and places that do not have time set up should be highlighted").
//
// Nobody had found the hours in Settings, so every place ran on the default
// Mon–Fri 8–5 Eastern and customers writing at 6 PM were told the office was
// closed. This lists every place (the organization, each company, its branches
// and sub-companies, indented), each place's hours and time zone, whether they
// are its own or inherited and from whom, and the auto-replies that read them.
//
// Edit opens the week right under its row, with no side pane (Jose: "not sure
// i like the sidepart"). On a phone the places are nested cards, and a tapped
// one takes the page with ← back.
//
// A place that has not set up its time is one whose hours come from nobody:
// `hours_own` false and `hours_from` null. Those rows wear the caution tone and
// a "Not set up" chip, and the banner counts them.

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../core/api_client.dart';
import '../core/deep_links.dart';
import '../core/theme.dart';
import 'open_destination.dart';
import 'search_picker.dart';
import 'status_pill.dart';

/// Monday first, as the server keys them.
const officeDays = <(String, String)>[
  ('monday', 'Mon'),
  ('tuesday', 'Tue'),
  ('wednesday', 'Wed'),
  ('thursday', 'Thu'),
  ('friday', 'Fri'),
  ('saturday', 'Sat'),
  ('sunday', 'Sun'),
];

const _dayNames = {
  'monday': 'Monday',
  'tuesday': 'Tuesday',
  'wednesday': 'Wednesday',
  'thursday': 'Thursday',
  'friday': 'Friday',
  'saturday': 'Saturday',
  'sunday': 'Sunday',
};

/// `08:00` as people write it: `8:00`.
String _clock(String hhmm) =>
    hhmm.length == 5 && hhmm.startsWith('0') ? hhmm.substring(1) : hhmm;

/// A week as one line: consecutive days with the same times share a range,
/// closed days are left out, and a week with no open day says "Closed".
///
/// "Mon–Fri 8:00–17:00 · Sat 9:00–13:00".
String officeHoursSummary(Object? hours) {
  final week = hours is Map ? hours : const {};
  final parts = <String>[];
  var i = 0;

  while (i < officeDays.length) {
    final day = week[officeDays[i].$1];
    if (day is! Map) {
      i++;
      continue;
    }
    final times = '${day['open']}–${day['close']}';
    var j = i;
    while (j + 1 < officeDays.length) {
      final next = week[officeDays[j + 1].$1];
      if (next is! Map || '${next['open']}–${next['close']}' != times) break;
      j++;
    }
    final days = i == j
        ? officeDays[i].$2
        : '${officeDays[i].$2}–${officeDays[j].$2}';
    parts.add('$days ${_clock('${day['open']}')}–${_clock('${day['close']}')}');
    i = j + 1;
  }

  return parts.isEmpty ? 'Closed' : parts.join(' · ');
}

/// A place that nobody gave hours to: they come from the default.
bool officeHoursNotSetUp(Map<String, dynamic> place) =>
    place['hours_own'] != true && place['hours_from'] == null;

/// `HH:MM`, padded, or null when it is not a time of day.
String? _wireTime(String typed) {
  final match = RegExp(r'^\s*(\d{1,2}):(\d{2})\s*$').firstMatch(typed);
  if (match == null) return null;
  final hour = int.parse(match.group(1)!);
  final minute = int.parse(match.group(2)!);
  if (hour > 23 || minute > 59) return null;
  return '${'$hour'.padLeft(2, '0')}:${'$minute'.padLeft(2, '0')}';
}

/// The week as the server keeps it, every day present, so two weeks compare.
Map<String, dynamic> _normalWeek(Object? hours) {
  final week = hours is Map ? hours : const {};
  return {
    for (final (key, _) in officeDays)
      key: week[key] is Map
          ? {
              'open':
                  _wireTime('${week[key]['open']}') ?? '${week[key]['open']}',
              'close':
                  _wireTime('${week[key]['close']}') ?? '${week[key]['close']}',
            }
          : null,
  };
}

bool _sameWeek(Map<String, dynamic> a, Map<String, dynamic> b) {
  for (final (key, _) in officeDays) {
    final x = a[key] as Map?;
    final y = b[key] as Map?;
    if ((x == null) != (y == null)) return false;
    if (x != null && (x['open'] != y!['open'] || x['close'] != y['close'])) {
      return false;
    }
  }
  return true;
}

/// The digits and the colon, five at most. Not the phone hours' mask: that
/// one forces two-digit hours, so `8:00` typed as written became `80:0`.
final _timeInput = <TextInputFormatter>[
  FilteringTextInputFormatter.allow(RegExp(r'[0-9:]')),
  LengthLimitingTextInputFormatter(5),
];

/// One place's week being edited.
class _Draft {
  _Draft(Map<String, dynamic> place)
    : zone = '${place['timezone'] ?? ''}',
      original = _normalWeek(place['hours']) {
    for (final (key, _) in officeDays) {
      final day = original[key] as Map?;
      closed[key] = day == null;
      open[key] = TextEditingController(text: '${day?['open'] ?? '08:00'}');
      close[key] = TextEditingController(text: '${day?['close'] ?? '17:00'}');
    }
  }

  final Map<String, dynamic> original;
  String zone;
  final closed = <String, bool>{};
  final open = <String, TextEditingController>{};
  final close = <String, TextEditingController>{};

  /// Shown once Save has been pressed, so a half-typed time is not red.
  bool checked = false;

  bool validOpen(String day) => _wireTime(open[day]!.text) != null;
  bool validClose(String day) => _wireTime(close[day]!.text) != null;

  /// The first day with a time that is not one, or null.
  String? get firstInvalid {
    for (final (key, _) in officeDays) {
      if (closed[key]!) continue;
      if (!validOpen(key) || !validClose(key)) return key;
    }
    return null;
  }

  Map<String, dynamic> get week => {
    for (final (key, _) in officeDays)
      key: closed[key]!
          ? null
          : {
              'open': _wireTime(open[key]!.text),
              'close': _wireTime(close[key]!.text),
            },
  };

  void copyMonday() {
    for (final key in const ['tuesday', 'wednesday', 'thursday', 'friday']) {
      closed[key] = closed['monday']!;
      open[key]!.text = open['monday']!.text;
      close[key]!.text = close['monday']!.text;
    }
  }

  void dispose() {
    for (final c in [...open.values, ...close.values]) {
      c.dispose();
    }
  }
}

class LeadsOfficeHours extends StatefulWidget {
  const LeadsOfficeHours({super.key, required this.api, this.onDeeper});

  final ApiClient api;

  /// True while a phone's editor takes the page, so Manage hides its header
  /// and tabs; false when it gives the page back.
  final ValueChanged<bool>? onDeeper;

  @override
  State<LeadsOfficeHours> createState() => _LeadsOfficeHoursState();
}

class _LeadsOfficeHoursState extends State<LeadsOfficeHours> {
  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _places = const [];
  List<Map<String, dynamic>> _usedBy = const [];

  /// IANA name → the Settings label ("Central — Chicago").
  Map<String, String> _zoneLabels = const {};
  bool _zonesAsked = false;

  /// The one place open for editing, by scope and id.
  String? _openKey;
  _Draft? _draft;
  bool _saving = false;
  String? _formError;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _draft?.dispose();
    super.dispose();
  }

  static String _keyOf(Map<String, dynamic> place) =>
      '${place['scope']}:${place['id']}';

  Future<void> _load() async {
    setState(() {
      _loading = _places.isEmpty;
      _error = null;
    });

    final response = await widget.api.get('/leads/office-hours');
    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _loading = false;
        _error = response.reason;
      });
      return;
    }

    final data = response.data as Map<String, dynamic>? ?? const {};
    setState(() {
      _loading = false;
      _places = [
        for (final p in (data['places'] as List? ?? const []))
          (p as Map).cast<String, dynamic>(),
      ];
      _usedBy = [
        for (final r in (data['used_by'] as List? ?? const []))
          (r as Map).cast<String, dynamic>(),
      ];
    });

    if (!_zonesAsked) await _loadZones();
  }

  /// The time zones Settings offers, read the way Settings reads them: the
  /// `timezone` row of GET /settings at the organization, and its options.
  Future<void> _loadZones() async {
    _zonesAsked = true;
    final org = _organization;
    if (org == null) return;

    final response = await widget.api.get('/settings', {
      'scope_type': 'organization',
      'scope_id': '${org['id']}',
    });
    if (!mounted || !response.ok || response.data is! List) return;

    for (final row in response.data as List) {
      if (row is! Map || row['key'] != 'timezone') continue;
      final labels = <String, String>{};
      for (final option in (row['options'] as List? ?? const [])) {
        if (option is String) {
          labels[option] = option;
        } else if (option is Map && option['value'] != null) {
          labels['${option['value']}'] =
              '${option['label'] ?? option['value']}';
        }
      }
      setState(() => _zoneLabels = labels);
    }
  }

  Map<String, dynamic>? get _organization {
    for (final p in _places) {
      if (p['scope'] == 'organization') return p;
    }
    return _places.isEmpty ? null : _places.first;
  }

  /// "America/Chicago" → "Chicago".
  static String _city(String zone) => zone.split('/').last.replaceAll('_', ' ');

  /// "Central (Chicago)", from the Settings label "Central — Chicago".
  String zoneName(String zone) {
    if (zone.isEmpty) return 'No time zone';
    final label = _zoneLabels[zone];
    if (label == null) return _city(zone);
    final parts = label.split(' — ');
    return parts.length == 2 ? '${parts[0]} (${parts[1]})' : label;
  }

  /// "Eastern", for the banner's sentence.
  String _zoneShort(String zone) {
    final label = _zoneLabels[zone];
    if (label != null && label.contains(' — ')) return label.split(' — ').first;
    return _city(zone);
  }

  Map<String, dynamic>? _placeById(Object? id) {
    if (id == null) return null;
    for (final p in _places) {
      if ('${p['id']}' == '$id') return p;
    }
    return null;
  }

  int _depthOf(Map<String, dynamic> place) {
    var depth = 0;
    var parent = _placeById(place['parent_id']);
    while (parent != null && depth < 12) {
      depth++;
      parent = _placeById(parent['parent_id']);
    }
    return depth;
  }

  /// What a value says beside where it came from: nothing when it is the
  /// place's own, "from X" when inherited, "default" when nobody set it.
  ({String text, bool inherited}) _cell(String value, bool own, Object? from) {
    if (own) return (text: value, inherited: false);
    return (
      text: from == null ? '$value · default' : '$value · from $from',
      inherited: true,
    );
  }

  ({String text, bool inherited}) _hoursCell(Map<String, dynamic> place) =>
      _cell(
        officeHoursSummary(place['hours']),
        place['hours_own'] == true,
        place['hours_from'],
      );

  ({String text, bool inherited}) _zoneCell(Map<String, dynamic> place) =>
      _cell(
        zoneName('${place['timezone'] ?? ''}'),
        place['timezone_own'] == true,
        place['timezone_from'],
      );

  List<(String, Tone)> _chips(Map<String, dynamic> place) {
    final ownHours = place['hours_own'] == true;
    final ownZone = place['timezone_own'] == true;
    return [
      if (officeHoursNotSetUp(place)) ('Not set up', Tone.caution),
      if (ownHours && ownZone)
        ('own hours + zone', Tone.info)
      else if (ownHours)
        ('own hours', Tone.info)
      else if (ownZone)
        ('own zone', Tone.info),
    ];
  }

  // ── editing ──────────────────────────────────────────────────────

  void _edit(Map<String, dynamic> place, {required bool phone}) {
    setState(() {
      _draft?.dispose();
      final key = _keyOf(place);
      if (_openKey == key && !phone) {
        _openKey = null;
        _draft = null;
      } else {
        _openKey = key;
        _draft = _Draft(place);
      }
      _formError = null;
    });
    if (phone) widget.onDeeper?.call(true);
  }

  void _close() {
    final wasOpen = _openKey != null;
    setState(() {
      _draft?.dispose();
      _draft = null;
      _openKey = null;
      _formError = null;
    });
    if (wasOpen) widget.onDeeper?.call(false);
  }

  Map<String, dynamic>? get _openPlace {
    for (final p in _places) {
      if (_keyOf(p) == _openKey) return p;
    }
    return null;
  }

  Future<void> _put(
    Map<String, dynamic> place,
    Map<String, dynamic> body,
  ) async {
    setState(() {
      _saving = true;
      _formError = null;
    });

    final response = await widget.api.put(
      '/leads/office-hours/${place['scope']}/${place['id']}',
      body,
    );
    if (!mounted) return;

    setState(() => _saving = false);
    if (!response.ok) {
      setState(() => _formError = response.reason);
      return;
    }

    _close();
    await _load();
  }

  /// Sends only what changed: the week, the zone, or both.
  Future<void> _save(Map<String, dynamic> place) async {
    final draft = _draft!;
    final bad = draft.firstInvalid;
    if (bad != null) {
      setState(() {
        draft.checked = true;
        _formError =
            '${_dayNames[bad]}\'s times need to be written as hours and '
            'minutes, like 08:00 or 17:30.';
      });
      return;
    }

    final body = <String, dynamic>{};
    final week = draft.week;
    if (!_sameWeek(week, draft.original)) body['hours'] = week;
    if (draft.zone != '${place['timezone'] ?? ''}') {
      body['timezone'] = draft.zone;
    }

    if (body.isEmpty) {
      _close();
      return;
    }
    await _put(place, body);
  }

  // ── building ─────────────────────────────────────────────────────

  @override
  Widget build(BuildContext context) {
    if (_loading) return const Center(child: CircularProgressIndicator());

    if (_error != null) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(_error!, textAlign: TextAlign.center),
              const SizedBox(height: 8),
              TextButton(onPressed: _load, child: const Text('Try again')),
            ],
          ),
        ),
      );
    }

    return LayoutBuilder(
      builder: (context, box) {
        final phone = box.maxWidth < 600;
        final open = _openPlace;
        if (phone && open != null) return _phoneEditor(context, open);
        return phone ? _phoneList(context) : _desk(context);
      },
    );
  }

  Widget _banner(BuildContext context) {
    final count = _places.where(officeHoursNotSetUp).length;
    if (count == 0) return const SizedBox.shrink();

    final org = _organization!;
    final colours = ToneColours.of(context, Tone.caution);
    final places = count == 1
        ? '1 place still uses'
        : '$count places still use';

    return Container(
      key: const ValueKey('office-hours-banner'),
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      decoration: BoxDecoration(
        color: colours.fill,
        borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(Icons.schedule_outlined, size: 18, color: colours.ink),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              '$places the default ${officeHoursSummary(org['hours'])} '
              '${_zoneShort('${org['timezone'] ?? ''}')}. Customers writing '
              'after hours there are told you\'re closed.',
              style: TextStyle(color: colours.ink, fontSize: 13),
            ),
          ),
        ],
      ),
    );
  }

  Widget _usedByLine(BuildContext context) {
    final theme = Theme.of(context);
    final n = _usedBy.length;

    if (n == 0) {
      return Text(
        'No auto-replies read these hours yet.',
        key: const ValueKey('office-hours-used-by'),
        style: theme.textTheme.bodySmall,
      );
    }

    return Wrap(
      key: const ValueKey('office-hours-used-by'),
      crossAxisAlignment: WrapCrossAlignment.center,
      spacing: 4,
      children: [
        Text(
          n == 1 ? 'Used by 1 auto-reply:' : 'Used by $n auto-replies:',
          style: theme.textTheme.bodyMedium?.copyWith(
            fontWeight: FontWeight.w700,
          ),
        ),
        for (final rule in _usedBy)
          TextButton(
            key: ValueKey('used-by-${rule['id']}'),
            onPressed: () => _openRule('${rule['id']}'),
            child: Text(
              rule['active'] == false
                  ? '${rule['name']} (off)'
                  : '${rule['name']}',
            ),
          ),
      ],
    );
  }

  /// Opens the rule in Automations, the way a notification about it does.
  void _openRule(String id) {
    DeepLinks.open(DeepLink(module: 'automations', type: 'automation', id: id));
    OpenDestination.of(context)?.call('automations');
  }

  Widget _cellText(
    BuildContext context,
    ({String text, bool inherited}) cell, {
    Key? key,
    int maxLines = 2,
  }) {
    final theme = Theme.of(context);
    return Text(
      cell.text,
      key: key,
      maxLines: maxLines,
      overflow: TextOverflow.ellipsis,
      style: cell.inherited
          ? theme.textTheme.bodyMedium?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
              fontStyle: FontStyle.italic,
            )
          : theme.textTheme.bodyMedium,
    );
  }

  Widget _chipRow(Map<String, dynamic> place) => Wrap(
    spacing: 6,
    runSpacing: 4,
    children: [
      for (final (label, tone) in _chips(place)) StatusPill(label, tone: tone),
    ],
  );

  // ── the desk ─────────────────────────────────────────────────────

  Widget _desk(BuildContext context) {
    final theme = Theme.of(context);
    final heading = theme.textTheme.labelSmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
      fontWeight: FontWeight.w700,
    );

    return ListView(
      key: const ValueKey('office-hours'),
      padding: const EdgeInsets.all(16),
      children: [
        _banner(context),
        const SizedBox(height: 12),
        Container(
          key: const ValueKey('office-hours-table'),
          decoration: BoxDecoration(
            color: theme.colorScheme.surface,
            border: Border.all(color: theme.colorScheme.outlineVariant),
            borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
          ),
          clipBehavior: Clip.antiAlias,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Padding(
                padding: const EdgeInsets.fromLTRB(12, 10, 12, 8),
                child: Row(
                  children: [
                    Expanded(flex: 3, child: Text('Place', style: heading)),
                    Expanded(flex: 4, child: Text('Hours', style: heading)),
                    Expanded(flex: 3, child: Text('Time zone', style: heading)),
                    const Expanded(flex: 2, child: SizedBox.shrink()),
                    const SizedBox(width: 76),
                  ],
                ),
              ),
              for (final place in _places) ...[
                const Divider(height: 1),
                _deskRow(context, place),
                if (_keyOf(place) == _openKey && _draft != null)
                  _editor(context, place, _draft!, phone: false),
              ],
            ],
          ),
        ),
        const SizedBox(height: 12),
        _usedByLine(context),
      ],
    );
  }

  Widget _deskRow(BuildContext context, Map<String, dynamic> place) {
    final theme = Theme.of(context);
    final open = _keyOf(place) == _openKey;
    final notSetUp = officeHoursNotSetUp(place);
    final depth = _depthOf(place);

    return Container(
      key: ValueKey('office-row-${place['id']}'),
      color: open
          ? theme.colorScheme.primaryContainer
          : notSetUp
          ? ToneColours.of(context, Tone.caution).fill
          : null,
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      child: Row(
        children: [
          Expanded(
            flex: 3,
            child: Padding(
              padding: EdgeInsets.only(
                left: 18.0 * (depth > 0 ? depth - 1 : 0),
              ),
              child: Text(
                '${place['name']}',
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
                style: theme.textTheme.bodyMedium?.copyWith(
                  fontWeight: depth <= 1 ? FontWeight.w700 : FontWeight.w400,
                ),
              ),
            ),
          ),
          Expanded(
            flex: 4,
            child: _cellText(
              context,
              _hoursCell(place),
              key: ValueKey('office-hours-${place['id']}'),
            ),
          ),
          const SizedBox(width: 8),
          Expanded(
            flex: 3,
            child: _cellText(
              context,
              _zoneCell(place),
              key: ValueKey('office-zone-${place['id']}'),
            ),
          ),
          const SizedBox(width: 8),
          Expanded(flex: 2, child: _chipRow(place)),
          SizedBox(
            width: 76,
            child: Align(
              alignment: Alignment.centerRight,
              child: OutlinedButton(
                key: ValueKey('office-edit-${place['id']}'),
                onPressed: () => _edit(place, phone: false),
                child: Text(open ? 'Close' : 'Edit'),
              ),
            ),
          ),
        ],
      ),
    );
  }

  // ── the editor, under its row or full screen ─────────────────────

  Widget _dayBox(BuildContext context, _Draft draft, String key, String short) {
    final theme = Theme.of(context);
    final closed = draft.closed[key]!;

    Widget field(
      TextEditingController c,
      String which,
      bool valid,
      String hint,
    ) => TextField(
      key: ValueKey('$which-$key'),
      controller: c,
      keyboardType: TextInputType.datetime,
      inputFormatters: _timeInput,
      textAlign: TextAlign.center,
      decoration: InputDecoration(
        isDense: true,
        hintText: hint,
        border: const OutlineInputBorder(),
        contentPadding: const EdgeInsets.symmetric(horizontal: 4, vertical: 8),
        errorText: draft.checked && !valid ? 'HH:MM' : null,
      ),
    );

    return Container(
      padding: const EdgeInsets.all(6),
      decoration: BoxDecoration(
        color: closed
            ? theme.colorScheme.surfaceContainerHighest
            : theme.colorScheme.surface,
        border: Border.all(color: theme.colorScheme.outlineVariant),
        borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Tooltip(
            message: closed
                ? 'Open on ${_dayNames[key]}'
                : 'Closed on ${_dayNames[key]}',
            child: TextButton(
              key: ValueKey('day-$key'),
              onPressed: () =>
                  setState(() => draft.closed[key] = !draft.closed[key]!),
              child: Text(
                short,
                style: const TextStyle(fontWeight: FontWeight.w700),
              ),
            ),
          ),
          if (closed)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 8),
              child: Text(
                'Closed',
                key: ValueKey('closed-$key'),
                textAlign: TextAlign.center,
                style: theme.textTheme.bodySmall,
              ),
            )
          else ...[
            field(draft.open[key]!, 'open', draft.validOpen(key), '08:00'),
            const SizedBox(height: 6),
            field(draft.close[key]!, 'close', draft.validClose(key), '17:00'),
          ],
        ],
      ),
    );
  }

  Widget _week(BuildContext context, _Draft draft, {required bool phone}) {
    Widget row(List<(String, String)> days, int slots) => IntrinsicHeight(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          for (var i = 0; i < slots; i++) ...[
            if (i > 0) const SizedBox(width: 6),
            Expanded(
              child: i < days.length
                  ? _dayBox(context, draft, days[i].$1, days[i].$2)
                  : const SizedBox.shrink(),
            ),
          ],
        ],
      ),
    );

    if (!phone) return row(officeDays, 7);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        for (var i = 0; i < officeDays.length; i += 2) ...[
          if (i > 0) const SizedBox(height: 6),
          row(officeDays.sublist(i, (i + 2).clamp(0, officeDays.length)), 2),
        ],
      ],
    );
  }

  List<SearchOption<String>> _zoneOptions(String current) {
    final zones = _zoneLabels.isNotEmpty
        ? _zoneLabels.keys.toList()
        : {
            for (final p in _places) '${p['timezone'] ?? ''}',
          }.where((z) => z.isNotEmpty).toList();
    if (current.isNotEmpty && !zones.contains(current)) {
      zones.insert(0, current);
    }
    return [
      for (final z in zones)
        SearchOption(value: z, label: zoneName(z), detail: z),
    ];
  }

  Widget _editor(
    BuildContext context,
    Map<String, dynamic> place,
    _Draft draft, {
    required bool phone,
  }) {
    final theme = Theme.of(context);
    final parent = _placeById(place['parent_id']);
    final parentName = parent == null ? 'the default' : '${parent['name']}\'s';

    final zonePicker = SearchPickerField<String>(
      key: const ValueKey('office-zone'),
      value: draft.zone.isEmpty ? null : draft.zone,
      label: 'Time zone',
      isDense: true,
      options: _zoneOptions(draft.zone),
      onChanged: _saving
          ? null
          : (v) => setState(() => draft.zone = v ?? draft.zone),
    );

    final inherit = [
      if (place['hours_own'] == true)
        TextButton(
          key: const ValueKey('inherit-hours'),
          onPressed: _saving ? null : () => _put(place, {'hours': null}),
          child: Text('Use $parentName hours again'),
        ),
      if (place['timezone_own'] == true)
        TextButton(
          key: const ValueKey('inherit-zone'),
          onPressed: _saving ? null : () => _put(place, {'timezone': null}),
          child: Text('Use $parentName time zone again'),
        ),
    ];

    final copy = TextButton.icon(
      key: const ValueKey('copy-monday'),
      onPressed: () => setState(draft.copyMonday),
      icon: const Icon(Icons.content_copy, size: 16),
      label: const Text('Copy Monday to weekdays'),
    );

    final actions = Row(
      mainAxisAlignment: MainAxisAlignment.end,
      children: [
        TextButton(
          key: const ValueKey('office-cancel'),
          onPressed: _saving ? null : _close,
          child: const Text('Cancel'),
        ),
        const SizedBox(width: 8),
        FilledButton(
          key: const ValueKey('office-save'),
          onPressed: _saving ? null : () => _save(place),
          child: Text(_saving ? 'Saving…' : 'Save'),
        ),
      ],
    );

    final error = _formError == null
        ? null
        : Text(
            _formError!,
            key: const ValueKey('office-error'),
            style: theme.textTheme.bodyMedium?.copyWith(
              color: theme.colorScheme.error,
            ),
          );

    if (phone) {
      return Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          _week(context, draft, phone: true),
          Align(alignment: Alignment.centerLeft, child: copy),
          const SizedBox(height: 8),
          zonePicker,
          const SizedBox(height: 8),
          for (final b in inherit)
            Align(alignment: Alignment.centerLeft, child: b),
          if (error != null) ...[const SizedBox(height: 8), error],
          const SizedBox(height: 12),
          actions,
        ],
      );
    }

    return Container(
      key: const ValueKey('office-editor'),
      color: theme.colorScheme.surfaceContainerLow,
      padding: const EdgeInsets.all(12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          _week(context, draft, phone: false),
          const SizedBox(height: 10),
          Wrap(
            spacing: 12,
            runSpacing: 8,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              copy,
              SizedBox(width: 280, child: zonePicker),
              ...inherit,
            ],
          ),
          if (error != null) ...[const SizedBox(height: 8), error],
          const SizedBox(height: 8),
          actions,
        ],
      ),
    );
  }

  // ── the phone ────────────────────────────────────────────────────

  Widget _phoneList(BuildContext context) {
    final theme = Theme.of(context);

    return ListView(
      key: const ValueKey('office-hours'),
      padding: const EdgeInsets.all(12),
      children: [
        _banner(context),
        const SizedBox(height: 10),
        for (final place in _places)
          Padding(
            padding: EdgeInsets.only(
              left: 12.0 * (_depthOf(place) - 1).clamp(0, 4),
              bottom: 8,
            ),
            child: _phoneCard(context, theme, place),
          ),
        const SizedBox(height: 4),
        _usedByLine(context),
      ],
    );
  }

  Widget _phoneCard(
    BuildContext context,
    ThemeData theme,
    Map<String, dynamic> place,
  ) {
    final notSetUp = officeHoursNotSetUp(place);
    final caution = ToneColours.of(context, Tone.caution);

    return Material(
      key: ValueKey('office-card-${place['id']}'),
      color: notSetUp ? caution.fill : theme.colorScheme.surface,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
        side: BorderSide(
          color: notSetUp ? caution.ink : theme.colorScheme.outlineVariant,
        ),
      ),
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: () => _edit(place, phone: true),
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                '${place['name']}',
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
                style: theme.textTheme.bodyMedium?.copyWith(
                  fontWeight: FontWeight.w700,
                ),
              ),
              const SizedBox(height: 4),
              _cellText(
                context,
                _hoursCell(place),
                key: ValueKey('office-hours-${place['id']}'),
                maxLines: 3,
              ),
              _cellText(
                context,
                _zoneCell(place),
                key: ValueKey('office-zone-${place['id']}'),
              ),
              if (_chips(place).isNotEmpty) ...[
                const SizedBox(height: 6),
                _chipRow(place),
              ],
            ],
          ),
        ),
      ),
    );
  }

  Widget _phoneEditor(BuildContext context, Map<String, dynamic> place) {
    final theme = Theme.of(context);

    return Column(
      key: const ValueKey('office-editor'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(8, 8, 16, 8),
          child: Row(
            children: [
              IconButton(
                tooltip: 'Back to office hours',
                icon: const Icon(Icons.arrow_back),
                onPressed: _saving ? null : _close,
              ),
              const SizedBox(width: 4),
              Expanded(
                child: Text(
                  '${place['name']}',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: theme.textTheme.titleLarge,
                ),
              ),
            ],
          ),
        ),
        const Divider(height: 1),
        Expanded(
          child: ListView(
            padding: const EdgeInsets.all(12),
            children: [_editor(context, place, _draft!, phone: true)],
          ),
        ),
      ],
    );
  }
}
