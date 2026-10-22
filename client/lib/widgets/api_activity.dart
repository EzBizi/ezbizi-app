import 'dart:async';
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../core/api_client.dart';
import '../core/theme.dart';
import '../core/timestamps.dart';
import 'error_note.dart';
import 'search_picker.dart';
import 'status_pill.dart';

/// What an AI or a script did with an API key (UI queue 66).
///
/// Jose, 2026-10-07: "we should probably have an MCP-API log to records the
/// actions", "so changes can be reverted if needed". Every keyed request is
/// recorded by the server; this reads it as Read, Created, Changed or Deleted,
/// and a change opens to each field before and after, so it can be put back
/// by hand. Where the server can put one back safely (it says `revertable`),
/// Put it back does it in one click: Jose, asked whether he wanted that,
/// "Sure" (2026-10-07). Its answer is shown in place, never in a dialog.
///
/// Three places use it: the last few changes under a key in Your account
/// ([limit]), that key's whole history in a side panel, and Settings › API
/// activity for administrators ([everyone]). Wide, a change opens under its
/// row; narrow, it takes the list's place with a back arrow.
class ApiActivity extends StatefulWidget {
  const ApiActivity({
    super.key,
    required this.api,
    this.formats = DisplayFormats.fallback,
    this.keyId,
    this.everyone = false,
    this.limit,
    this.onShowAll,
  });

  final ApiClient api;

  /// Which way round a date is written: "Oct 2" or "2 Oct".
  final DisplayFormats formats;

  /// One key's history; null for every key the caller may see.
  final String? keyId;

  /// Settings: who did it is shown, and a Person filter.
  final bool everyone;

  /// Only the last few changes, then [onShowAll] with how many there are.
  final int? limit;
  final void Function(int changes)? onShowAll;

  @override
  State<ApiActivity> createState() => _ApiActivityState();
}

class _ApiActivityState extends State<ApiActivity> {
  static const _path = '/api-keys/activity';

  /// The server's page size: a full page means there may be older rows.
  static const _page = 200;

  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _rows = const [];
  bool _more = false;

  /// Reads are most of the traffic and none of the risk.
  bool _changesOnly = true;
  String? _person;
  String? _key;

  /// Only what the server refused (UI queue 111, from the summary strip).
  bool _refusedOnly = false;

  /// The last 24 hours, from the server; null when it does not say.
  Map<String, dynamic>? _summary;

  /// The row opened, by its place in [_rows].
  int? _open;

  /// The row being put back, and what the server said about each one tried.
  int? _reverting;
  final _said = <int, (String, Tone)>{};

  /// The search box, sent as `q`, debounced. A new search starts again from
  /// the newest row (no `before` cursor).
  final _search = TextEditingController();
  Timer? _debounce;
  String _q = '';

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _search.dispose();
    _debounce?.cancel();
    super.dispose();
  }

  void _searched(String text) {
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 400), () {
      if (!mounted) return;
      _q = text.trim();
      _load(quiet: true);
    });
  }

  /// ApiKeysController@activity does not read `q` yet, so the loaded rows are
  /// also narrowed here. Once the server honours it this is a no-op.
  bool _matches(Map<String, dynamic> row) {
    final q = _q.toLowerCase();
    if (q.isEmpty) return true;
    final text = [
      verbOf(row),
      row['path'],
      row['who'],
      row['key_name'],
      row['action'],
      row['status'],
    ].whereType<Object>().join(' ').toLowerCase();
    return text.contains(q);
  }

  /// [quiet] keeps the list (and the search box) on screen while a search
  /// reloads, so typing is not interrupted by the spinner.
  Future<void> _load({bool older = false, bool quiet = false}) async {
    setState(() {
      _loading = !older && !quiet;
      _error = null;
      if (!older) _open = null;
    });
    final response = await widget.api.get(_path, {
      'key_id': ?(widget.keyId ?? _key),
      'user_id': ?_person,
      if (_q.isNotEmpty) 'q': _q,
      if (older && _rows.isNotEmpty) 'before': '${_rows.last['at']}',
    });
    if (!mounted) return;
    setState(() {
      _loading = false;
      if (!response.ok) {
        _error = response.reason;
        return;
      }
      final page = [
        for (final row in (response.body['activity'] as List? ?? const []))
          if (row is Map) Map<String, dynamic>.from(row),
      ];
      _rows = older ? [..._rows, ...page] : page;
      _more = page.length >= _page;
      if (!older) _summary = response.body['summary'] as Map<String, dynamic>?;
    });
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    if (_loading) {
      return const Padding(
        padding: EdgeInsets.all(16),
        child: Center(child: CircularProgressIndicator()),
      );
    }
    if (_error != null) return ErrorNote(_error!);

    final shown = [
      for (final (i, row) in _rows.indexed)
        if ((!_changesOnly || verbOf(row) != 'Read') &&
            (!_refusedOnly || (int.tryParse('${row['status']}') ?? 0) >= 400) &&
            _matches(row))
          (i, row),
    ];

    if (widget.limit case final limit?) return _compact(context, shown, limit);

    return LayoutBuilder(
      builder: (context, box) {
        final wide = box.maxWidth >= 700;
        if (!wide && _open != null) return _page1(context, _open!);

        return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            if (widget.everyone && _summary != null) ...[
              _strip(context, _summary!),
              const SizedBox(height: 12),
            ],
            _filters(context),
            const SizedBox(height: 10),
            if (shown.isEmpty)
              Padding(
                padding: const EdgeInsets.all(8),
                child: Text(
                  _q.isNotEmpty
                      ? 'Nothing done with an API key matches "$_q".'
                      : _changesOnly
                      ? 'No changes made with an API key yet.'
                      : 'Nothing done with an API key yet.',
                  style: theme.textTheme.bodyMedium,
                ),
              )
            else if (wide)
              _table(context, shown)
            else
              ..._byDay(context, shown),
            if (_more)
              Align(
                alignment: Alignment.centerLeft,
                child: TextButton(
                  key: const Key('api-activity-older'),
                  onPressed: () => _load(older: true),
                  child: const Text('Show older'),
                ),
              ),
          ],
        );
      },
    );
  }

  Widget _filters(BuildContext context) {
    final people = <String, String>{
      for (final row in _rows)
        if (row['user_id'] != null) '${row['user_id']}': '${row['who']}',
      ?_person: _personName ?? _person ?? '',
    };
    final keys = <String, String>{
      for (final row in _rows)
        if (row['key_id'] != null) '${row['key_id']}': '${row['key_name']}',
      ?_key: _keyName ?? _key ?? '',
    };

    return Wrap(
      spacing: 8,
      runSpacing: 8,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        SizedBox(
          width: 240,
          child: TextField(
            key: const Key('api-activity-search'),
            controller: _search,
            onChanged: _searched,
            // bodyMedium for the text and the hint, as the dense pickers draw:
            // 53 points tall, as they are; the theme's 16-point hint makes 56.
            style: Theme.of(context).textTheme.bodyMedium,
            decoration: InputDecoration(
              isDense: true,
              prefixIcon: const Icon(Icons.search, size: 18),
              hintText: 'Path, person or key',
              hintStyle: Theme.of(context).textTheme.bodyMedium?.copyWith(
                color: Theme.of(context).inputDecorationTheme.hintStyle?.color,
              ),
            ),
          ),
        ),
        if (widget.everyone)
          SizedBox(
            width: 200,
            child: SearchPickerField<String?>(
              key: const Key('api-activity-person'),
              label: 'Person',
              isDense: true,
              value: _person,
              options: [
                const SearchOption(value: null, label: 'Everyone'),
                for (final MapEntry(:key, :value) in people.entries)
                  SearchOption(value: key, label: value),
              ],
              onChanged: (value) {
                _person = value;
                _personName = people[value];
                _load();
              },
            ),
          ),
        if (widget.keyId == null)
          SizedBox(
            width: 220,
            child: SearchPickerField<String?>(
              key: const Key('api-activity-key'),
              label: 'Key',
              isDense: true,
              value: _key,
              options: [
                const SearchOption(value: null, label: 'All keys'),
                for (final MapEntry(:key, :value) in keys.entries)
                  SearchOption(value: key, label: value),
              ],
              onChanged: (value) {
                _key = value;
                _keyName = keys[value];
                _load();
              },
            ),
          ),
        FilterChip(
          key: const Key('api-activity-changes'),
          label: const Text('Changes only'),
          selected: _changesOnly,
          onSelected: (on) => setState(() {
            _changesOnly = on;
            _open = null;
          }),
        ),
      ],
    );
  }

  String? _personName;
  String? _keyName;

  /// The last 24 hours in four numbers; Calls, Changes and Refused filter
  /// the table to themselves (UI queue 111).
  Widget _strip(BuildContext context, Map<String, dynamic> summary) {
    final theme = Theme.of(context);
    Widget tile(
      String key,
      String label,
      Object? value, {
      VoidCallback? onTap,
      bool on = false,
      bool warn = false,
    }) {
      final colour = warn && (value is num && value > 0)
          ? theme.colorScheme.error
          : theme.colorScheme.onSurface;
      return Expanded(
        child: Card(
          margin: const EdgeInsets.only(right: 8),
          color: on ? theme.colorScheme.primaryContainer : null,
          child: InkWell(
            key: ValueKey('api-summary-$key'),
            onTap: onTap,
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    '${value ?? 0}',
                    style: theme.textTheme.titleLarge?.copyWith(color: colour),
                  ),
                  Text(label, style: theme.textTheme.bodySmall),
                ],
              ),
            ),
          ),
        ),
      );
    }

    void show({required bool changes, required bool refused}) => setState(() {
      _changesOnly = changes;
      _refusedOnly = refused;
      _open = null;
    });

    return Row(
      children: [
        tile('keys', 'keys used · 24 h', summary['keys']),
        tile(
          'calls',
          'calls',
          summary['calls'],
          on: !_changesOnly && !_refusedOnly,
          onTap: () => show(changes: false, refused: false),
        ),
        tile(
          'changes',
          'changes',
          summary['changes'],
          on: _changesOnly && !_refusedOnly,
          onTap: () => show(changes: true, refused: false),
        ),
        tile(
          'refused',
          'refused',
          summary['refused'],
          warn: true,
          on: _refusedOnly,
          onTap: () => show(changes: false, refused: true),
        ),
      ],
    );
  }

  /// Under a key in Your account: the last few changes, then Show all.
  Widget _compact(
    BuildContext context,
    List<(int, Map<String, dynamic>)> shown,
    int limit,
  ) {
    final theme = Theme.of(context);
    if (shown.isEmpty) {
      return Text(
        'No changes made with this key yet.',
        style: theme.textTheme.bodySmall,
      );
    }
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        for (final (_, row) in shown.take(limit))
          Padding(
            padding: const EdgeInsets.symmetric(vertical: 3),
            child: Row(
              children: [
                SizedBox(
                  width: 72,
                  child: Text(_when(row), style: theme.textTheme.bodySmall),
                ),
                _verbPill(row),
                const SizedBox(width: 8),
                Expanded(child: _where(context, row)),
              ],
            ),
          ),
        if (shown.length > limit || _more)
          TextButton(
            key: const Key('api-activity-all'),
            style: TextButton.styleFrom(padding: EdgeInsets.zero),
            onPressed: () => widget.onShowAll?.call(shown.length),
            child: Text(
              _more
                  ? 'Show all changes →'
                  : 'Show all ${shown.length} changes →',
            ),
          ),
      ],
    );
  }

  Widget _table(BuildContext context, List<(int, Map<String, dynamic>)> shown) {
    final theme = Theme.of(context);
    final head = theme.textTheme.labelSmall?.copyWith(
      fontWeight: FontWeight.w800,
      color: theme.colorScheme.onSurfaceVariant,
    );

    Widget line(List<Widget> cells) => Row(
      children: [
        SizedBox(width: 120, child: cells[0]),
        if (widget.everyone) SizedBox(width: 180, child: cells[1]),
        SizedBox(width: 96, child: cells[2]),
        Expanded(child: cells[3]),
        SizedBox(width: 56, child: cells[4]),
        SizedBox(width: 72, child: cells[5]),
      ],
    );

    return Card(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 8, 16, 8),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            line([
              Text('WHEN', style: head),
              Text('WHO · KEY', style: head),
              Text('WHAT', style: head),
              Text('WHERE', style: head),
              Text('VIA', style: head),
              Text('RESULT', style: head),
            ]),
            for (final (n, (i, row)) in shown.indexed) ...[
              if (n == 0 || _dayOf(shown[n - 1].$2) != _dayOf(row)) ...[
                const Divider(height: 1),
                Container(
                  color: theme.colorScheme.surfaceContainerHighest,
                  padding: const EdgeInsets.symmetric(
                    horizontal: 8,
                    vertical: 5,
                  ),
                  child: Text(_dayOf(row).toUpperCase(), style: head),
                ),
              ],
              const Divider(height: 1),
              InkWell(
                key: ValueKey('api-activity-row-$i'),
                onTap: () => setState(() => _open = _open == i ? null : i),
                child: Container(
                  color: _open == i ? theme.colorScheme.primaryContainer : null,
                  padding: const EdgeInsets.symmetric(vertical: 8),
                  child: line([
                    Text(_when(row), style: theme.textTheme.bodySmall),
                    Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          '${row['who'] ?? ''}',
                          style: const TextStyle(fontWeight: FontWeight.w700),
                          overflow: TextOverflow.ellipsis,
                        ),
                        Text(
                          '${row['key_name'] ?? ''}',
                          style: theme.textTheme.bodySmall,
                          overflow: TextOverflow.ellipsis,
                        ),
                      ],
                    ),
                    Align(
                      alignment: Alignment.centerLeft,
                      child: _verbPill(row),
                    ),
                    _where(context, row),
                    Text(_via(row), style: theme.textTheme.bodySmall),
                    Align(
                      alignment: Alignment.centerLeft,
                      child: _resultPill(row),
                    ),
                  ]),
                ),
              ),
              if (_open == i) _detail(context, i, row),
            ],
          ],
        ),
      ),
    );
  }

  String _dayOf(Map<String, dynamic> row) {
    final at = DateTime.tryParse('${row['at']}')?.toLocal();
    return at == null ? '' : widget.formats.formatDate(at);
  }

  /// Narrow: grouped by day, a row opens in the list's place.
  List<Widget> _byDay(
    BuildContext context,
    List<(int, Map<String, dynamic>)> shown,
  ) {
    final theme = Theme.of(context);
    final days = <String, List<(int, Map<String, dynamic>)>>{};
    for (final entry in shown) {
      final at = DateTime.tryParse('${entry.$2['at']}')?.toLocal();
      final day = at == null ? '' : widget.formats.formatDate(at);
      (days[day] ??= []).add(entry);
    }
    return [
      for (final MapEntry(key: day, value: rows) in days.entries) ...[
        Padding(
          padding: const EdgeInsets.fromLTRB(4, 8, 4, 4),
          child: Text(
            day.toUpperCase(),
            style: theme.textTheme.labelSmall?.copyWith(
              fontWeight: FontWeight.w800,
            ),
          ),
        ),
        Card(
          child: Column(
            children: [
              for (final (i, row) in rows)
                ListTile(
                  key: ValueKey('api-activity-row-$i'),
                  dense: true,
                  title: Text(
                    '${verbOf(row)} · ${_pathOf(row)}',
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(fontWeight: FontWeight.w600),
                  ),
                  subtitle: Text(
                    [
                      if (widget.everyone) '${row['who'] ?? ''}',
                      if (widget.keyId == null) '${row['key_name'] ?? ''}',
                      _when(row),
                    ].where((part) => part.isNotEmpty).join(' · '),
                  ),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () => setState(() => _open = i),
                ),
            ],
          ),
        ),
      ],
    ];
  }

  /// Narrow: one change, in the list's place.
  Widget _page1(BuildContext context, int i) {
    final theme = Theme.of(context);
    final row = _rows[i];
    return Column(
      key: const Key('api-activity-detail'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(
          children: [
            IconButton(
              tooltip: 'Back',
              icon: const Icon(Icons.arrow_back),
              onPressed: () => setState(() => _open = null),
            ),
            Expanded(
              child: Text(verbOf(row), style: theme.textTheme.titleMedium),
            ),
            _resultPill(row),
          ],
        ),
        _where(context, row),
        const SizedBox(height: 4),
        Text(
          [
            _when(row),
            '${row['who'] ?? ''}',
            '${row['key_name'] ?? ''}',
            _via(row) == '—' ? '' : 'through the ${_via(row)}',
          ].where((part) => part.isNotEmpty).join(' · '),
          style: theme.textTheme.bodySmall,
        ),
        const SizedBox(height: 8),
        _detail(context, i, row),
      ],
    );
  }

  /// Each field the way it was and the way it was set.
  Widget _detail(BuildContext context, int i, Map<String, dynamic> row) {
    final theme = Theme.of(context);
    final sent = row['sent'];
    // The server keeps the GET's whole answer, and most routes answer
    // {"data": {...}}: the fields are inside it.
    final kept = row['before'];
    final before = kept is Map && kept.length == 1 && kept['data'] is Map
        ? kept['data'] as Map
        : kept;
    final verb = verbOf(row);
    final good = ToneColours.of(context, Tone.ok);
    final bad = ToneColours.of(context, Tone.bad);

    Widget cell(String text, {Color? fill, Color? ink, bool struck = false}) =>
        Container(
          color: fill,
          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 5),
          child: Text(
            text,
            style: theme.textTheme.bodySmall?.copyWith(
              color: ink,
              decoration: struck ? TextDecoration.lineThrough : null,
            ),
          ),
        );

    final Widget body;
    if (sent is Map && sent.isNotEmpty) {
      body = Table(
        key: const Key('api-activity-diff'),
        border: TableBorder.all(color: EzBiziTheme.edge(context)),
        columnWidths: const {0: IntrinsicColumnWidth()},
        children: [
          TableRow(
            children: [
              cell('Field'),
              cell(verb == 'Created' ? '' : 'Before'),
              cell(verb == 'Created' ? 'Sent' : 'Set to'),
            ],
          ),
          for (final MapEntry(:key, :value) in sent.entries)
            TableRow(
              children: [
                cell('$key'),
                before is Map
                    ? cell(
                        valueText(before[key]),
                        fill: bad.fill,
                        ink: bad.ink,
                        struck: true,
                      )
                    : cell(verb == 'Created' ? '' : '—'),
                cell(valueText(value), fill: good.fill, ink: good.ink),
              ],
            ),
        ],
      );
    } else if (before is Map && before.isNotEmpty) {
      body = Table(
        key: const Key('api-activity-held'),
        border: TableBorder.all(color: EzBiziTheme.edge(context)),
        columnWidths: const {0: IntrinsicColumnWidth()},
        children: [
          TableRow(children: [cell('Field'), cell('What it held')]),
          for (final MapEntry(:key, :value) in before.entries)
            TableRow(children: [cell('$key'), cell(valueText(value))]),
        ],
      );
    } else if (sent is String && sent.isNotEmpty) {
      body = SelectableText(
        sent,
        maxLines: 8,
        style: theme.textTheme.bodySmall?.copyWith(fontFamily: 'monospace'),
      );
    } else {
      body = Text(
        verb == 'Read' ? 'A read changes nothing.' : 'Nothing was sent.',
        style: theme.textTheme.bodySmall,
      );
    }

    return Container(
      margin: const EdgeInsets.only(bottom: 8),
      padding: const EdgeInsets.all(10),
      decoration: BoxDecoration(
        color: theme.colorScheme.surfaceContainerLowest,
        border: Border.all(color: EzBiziTheme.edge(context)),
        borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          body,
          if (_said[i] case (final text, final tone)) ...[
            const SizedBox(height: 8),
            Text(
              text,
              key: const Key('api-activity-said'),
              style: theme.textTheme.bodySmall?.copyWith(
                color: ToneColours.of(context, tone).ink,
                fontWeight: FontWeight.w700,
              ),
            ),
          ],
          if (sent != null || row['revertable'] == true) ...[
            const SizedBox(height: 8),
            Wrap(
              alignment: WrapAlignment.end,
              spacing: 8,
              runSpacing: 8,
              children: [
                if (sent != null)
                  OutlinedButton(
                    key: const Key('api-activity-copy'),
                    onPressed: () => _copy(
                      sent is String
                          ? sent
                          : const JsonEncoder.withIndent('  ').convert(sent),
                    ),
                    child: const Text('Copy what was sent'),
                  ),
                if (row['reverted_at'] != null)
                  StatusPill(
                    'Put back ${formatListStamp(row['reverted_at'], '', formats: widget.formats)}',
                    tone: Tone.ok,
                  )
                else if (row['revertable'] == true)
                  FilledButton.icon(
                    key: const Key('api-activity-revert'),
                    onPressed: _reverting == null ? () => _revert(i) : null,
                    icon: const Icon(Icons.undo, size: 18),
                    label: Text(
                      _reverting == i ? 'Putting it back…' : 'Put it back',
                    ),
                  ),
              ],
            ),
          ],
        ],
      ),
    );
  }

  /// One click, the server's answer in place. Done, or refused for good (put
  /// back already, changed since, not revertable), the button goes; any other
  /// refusal leaves it to try again.
  Future<void> _revert(int i) async {
    setState(() {
      _reverting = i;
      _said.remove(i);
    });
    final response = await widget.api.post(
      '$_path/${_rows[i]['id']}/revert',
      {},
    );
    if (!mounted) return;
    setState(() {
      _reverting = null;
      final message = '${response.body['message'] ?? ''}';
      if (response.ok) {
        final exact = response.body['reverted'] == true;
        _said[i] = (
          message.isEmpty ? 'Put back.' : message,
          exact ? Tone.ok : Tone.caution,
        );
        _rows[i] = {
          ..._rows[i],
          'reverted_at': DateTime.now().toUtc().toIso8601String(),
        };
      } else {
        _said[i] = (response.reason, Tone.bad);
        if (response.statusCode == 409 || response.statusCode == 422) {
          _rows[i] = {..._rows[i], 'revertable': false};
        }
      }
    });
  }

  Future<void> _copy(String text) async {
    await Clipboard.setData(ClipboardData(text: text));
    if (!mounted) return;
    ScaffoldMessenger.maybeOf(
      context,
    )?.showSnackBar(const SnackBar(content: Text('Copied.')));
  }

  String _when(Map<String, dynamic> row) =>
      formatListStamp(row['at'], '', formats: widget.formats);

  String _via(Map<String, dynamic> row) => switch (row['via']) {
    'mcp' => 'MCP',
    'api' => 'API',
    _ => '—',
  };

  Widget _where(BuildContext context, Map<String, dynamic> row) => Text(
    _pathOf(row),
    maxLines: 1,
    overflow: TextOverflow.ellipsis,
    style: Theme.of(
      context,
    ).textTheme.bodySmall?.copyWith(fontFamily: 'monospace'),
  );

  Widget _verbPill(Map<String, dynamic> row) {
    final verb = verbOf(row);
    return StatusPill(
      verb,
      tone: switch (verb) {
        'Created' => Tone.ok,
        'Changed' => Tone.caution,
        'Deleted' => Tone.bad,
        _ => Tone.neutral,
      },
    );
  }

  Widget _resultPill(Map<String, dynamic> row) {
    final status = int.tryParse('${row['status']}') ?? 0;
    return StatusPill(
      status == 0
          ? '—'
          : status < 400
          ? 'OK'
          : status == 401 || status == 403
          ? 'Refused'
          : 'Failed',
      tone: status == 0
          ? Tone.neutral
          : status < 400
          ? Tone.ok
          : Tone.bad,
    );
  }

  static String _pathOf(Map<String, dynamic> row) => switch (row['action']) {
    'api_key.created' || 'api_key.revoked' => '${row['key_name'] ?? ''}',
    _ => '${row['path'] ?? ''}'.replaceFirst(RegExp(r'^/api/v1'), ''),
  };
}

/// A request in the words a person uses, not the method's.
String verbOf(Map<String, dynamic> row) => switch (row['action']) {
  'api_key.created' => 'Key made',
  'api_key.revoked' => 'Key revoked',
  'api_key.reverted' => 'Put back',
  _ => switch ('${row['method'] ?? ''}'.toUpperCase()) {
    'GET' || 'HEAD' => 'Read',
    'POST' => 'Created',
    'PUT' || 'PATCH' => 'Changed',
    'DELETE' => 'Deleted',
    final method => method,
  },
};

/// A field's value as text: nested ones as JSON, nothing as a dash.
String valueText(Object? value) => switch (value) {
  null => '—',
  String text => text.isEmpty ? '""' : text,
  Map() || List() => jsonEncode(value),
  _ => '$value',
};
