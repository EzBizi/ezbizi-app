import '../../core/phone_numbers.dart';
import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../widgets/error_note.dart';
import '../../widgets/list_table.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/status_pill.dart';
import 'phone_search.dart';
import 'settings_pane.dart';
import '../../widgets/refused.dart';
import 'buy_number_panel.dart';
import 'carrier_settings.dart';
import 'phone_refs.dart';

/// Phone › Settings › Numbers — the lines this business answers on.
///
/// ── Why importing comes before buying ────────────────────────────
///
/// Every path into this module assumed a number arrives by being bought. But a
/// company whose Twilio account EzBizi adopted has been answering its lines for
/// years, and none of them had a row here — so a correctly connected account
/// showed an empty Numbers screen, which reads as broken rather than as
/// unfinished. A-1 Chimney had five such lines.
///
/// So the top of this screen is what the account already holds and EzBizi does
/// not know about yet. Importing writes the row AND repoints the carrier at us;
/// a row without the repoint is a line we list and never hear from, which is
/// worse than not listing it.
///
/// ── This screen is where a line's ring group is set ──────────────
///
/// It used to be settable from both ends: here, and as a list of tick boxes
/// inside the Ring groups editor. One column, two editors, and the second one
/// asked the question backwards — "which numbers belong to this group" — when
/// a group is people and a number points at exactly one of them.
///
/// Jose named the shape on 2026-09-09 from RingCentral, where a queue holds
/// agents and the destination is set on the number. That is this screen. The
/// Ring groups editor now only SAYS which lines reach a group, so a group
/// nothing points at still admits it will never ring.
class NumbersPane extends StatefulWidget {
  const NumbersPane({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<NumbersPane> createState() => _NumbersPaneState();
}

class _NumbersPaneState extends State<NumbersPane> {
  bool _loading = true;
  String? _error;

  List<_Line> _lines = const [];
  List<_Conn> _connections = const [];
  List<RingGroupRef> _groups = const [];
  List<_Schedule> _schedules = const [];

  /// Lines ticked for a change made to all of them at once. Jose,
  /// 2026-10-05: "bulk edit capabilities".
  final Set<String> _bulkPicked = {};

  /// What the bulk bar sets; null leaves that field as each line has it.
  String? _bulkGroup;
  String? _bulkSchedule;
  bool? _bulkRecord;
  String? _bulkNote;

  /// One PATCH per line, the same request the line's own editor sends, so
  /// each is validated and authorized exactly as a single edit is.
  // ponytail: sequential requests; a bulk endpoint if accounts reach hundreds
  // of lines.
  Future<void> _saveBulk() async {
    final body = <String, dynamic>{
      if (_bulkGroup != null) 'ring_group_id': _bulkGroup,
      if (_bulkSchedule != null)
        'hours_schedule_id': _bulkSchedule == '' ? null : _bulkSchedule,
      if (_bulkRecord != null) 'record': _bulkRecord,
    };
    if (body.isEmpty) {
      setState(() => _bulkNote = 'Pick something to change first.');
      return;
    }

    setState(() {
      _saving = true;
      _bulkNote = null;
    });

    final failed = <String>[];
    for (final id in _bulkPicked.toList()) {
      final response = await widget.api.patch('/phone/numbers/$id', body);
      if (!response.ok) failed.add(response.reason);
    }
    if (!mounted) return;

    final done = _bulkPicked.length - failed.length;
    setState(() {
      _saving = false;
      _bulkNote = failed.isEmpty
          ? 'Changed $done ${done == 1 ? 'line' : 'lines'}.'
          : 'Changed $done; ${failed.length} refused: ${failed.first}';
      if (failed.isEmpty) {
        _bulkPicked.clear();
        _bulkGroup = null;
        _bulkSchedule = null;
        _bulkRecord = null;
      }
    });

    await _load();
  }

  Widget _bulkBar(ThemeData theme) {
    return Card(
      key: const ValueKey('bulk-bar'),
      margin: const EdgeInsets.only(bottom: 12),
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Wrap(
          spacing: 12,
          runSpacing: 12,
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            Text(
              '${_bulkPicked.length} selected',
              style: theme.textTheme.titleSmall,
            ),
            SizedBox(
              width: 220,
              child: SearchPickerField<String>(
                key: const ValueKey('bulk-group'),
                value: _bulkGroup,
                label: 'Rings',
                isDense: true,
                border: const OutlineInputBorder(),
                options: [
                  for (final g in _groups)
                    SearchOption(value: g.id, label: g.name),
                ],
                onChanged: (v) => setState(() => _bulkGroup = v),
              ),
            ),
            SizedBox(
              width: 220,
              child: SearchPickerField<String>(
                key: const ValueKey('bulk-hours'),
                value: _bulkSchedule,
                label: 'Business hours',
                isDense: true,
                border: const OutlineInputBorder(),
                options: [
                  const SearchOption(value: '', label: 'Always open'),
                  for (final s in _schedules)
                    SearchOption(value: s.id, label: s.name),
                ],
                onChanged: (v) => setState(() => _bulkSchedule = v),
              ),
            ),
            SizedBox(
              width: 180,
              child: SearchPickerField<String>(
                key: const ValueKey('bulk-record'),
                value: _bulkRecord == null
                    ? null
                    : (_bulkRecord! ? 'on' : 'off'),
                label: 'Recording',
                isDense: true,
                border: const OutlineInputBorder(),
                options: const [
                  SearchOption(value: 'on', label: 'Record calls'),
                  SearchOption(value: 'off', label: 'Do not record'),
                ],
                onChanged: (v) =>
                    setState(() => _bulkRecord = v == null ? null : v == 'on'),
              ),
            ),
            FilledButton(
              key: const ValueKey('bulk-save'),
              onPressed: _saving ? null : _saveBulk,
              child: Text(_saving ? 'Saving…' : 'Change these lines'),
            ),
            TextButton(
              onPressed: () => setState(() {
                _bulkPicked.clear();
                _bulkNote = null;
              }),
              child: const Text('Clear'),
            ),
          ],
        ),
      ),
    );
  }

  /// Lines Twilio holds that EzBizi has no row for, per connection scope.
  List<_Importable> _importable = const [];
  String? _importNote;
  final Set<String> _picked = <String>{};
  bool _importing = false;

  /// Which connection's account we are looking at. Null until loaded.
  _Conn? _scope;

  /// The line whose Move panel is open, if any.
  _Line? _moving;

  _Line? _editing;
  bool _saving = false;
  String? _formError;

  /// The tile picked over the Lines table (tiles over a table, Mia
  /// 2026-10-02): `answered`, `silent` or `recorded`; null is every line.
  /// Counted here - `/phone/numbers` is every line in one answer.
  String? _tile;

  /// What is typed in the Lines search box, filtered here for the same reason.
  String _query = '';

  bool _matches(_Line line) =>
      switch (_tile) {
        'answered' => line.answers,
        'silent' => !line.answers,
        'recorded' => line.record,
        'idle' => line.idle,
        'unassigned' => line.assignedTo == null,
        _ => true,
      } &&
      phoneSearchHit(_query, [
        line.label,
        line.e164,
        formatPhoneNumber(line.e164),
        line.answerTitle,
        _company(line),
      ]);

  @override
  void initState() {
    super.initState();
    _load();
  }

  Me get _me => widget.session.me!;

  bool get _canEdit => _me.can('phone.edit');

  /// Moving a line crosses a company boundary, and a company view cannot see
  /// the company it would be moving to. The server refuses anywhere else, so
  /// offering the control there would be a button that argues back.
  bool get _canMove => _canEdit && _me.scope.type == 'organization';

  String? get _editRefusal => _me.refusal('phone.edit', 'change a line');

  Future<void> _load() async {
    setState(() {
      _loading = _lines.isEmpty;
      _error = null;
    });

    final results = await Future.wait([
      widget.api.get('/phone/numbers'),
      widget.api.get('/phone/connections'),
      widget.api.get('/phone/ring-groups'),
      widget.api.get('/phone/hours'),
    ]);

    if (!mounted) return;

    final failed = results.firstWhere(
      (r) => !r.ok,
      orElse: () => results.first,
    );

    if (!failed.ok) {
      setState(() {
        _loading = false;
        _error = failed.reason;
      });
      return;
    }

    final connections = _Conn.listFrom(results[1].body);

    setState(() {
      _lines = _Line.listFrom(results[0].body);
      _connections = connections;
      _groups = RingGroupRef.listFrom(results[2].body);
      _schedules = _Schedule.listFrom(results[3].body);
      _scope ??= connections.isEmpty ? null : connections.first;
      _loading = false;
    });

    await _loadImportable();
  }

  /// What the carrier holds that we do not. Kept out of [_load] so switching
  /// place refetches only this, and a Twilio outage leaves the rest on screen.
  Future<void> _loadImportable() async {
    final scope = _scope;

    if (scope == null) {
      setState(() {
        _importable = const [];
        _importNote = null;
      });
      return;
    }

    final response = await widget.api.get(
      '/phone/numbers/importable'
      '?scope_type=${scope.scopeType}&scope_id=${scope.scopeId}',
    );

    if (!mounted) return;

    setState(() {
      _importable = response.ok
          ? _Importable.listFrom(response.body)
          : const [];
      _importNote = response.ok
          ? response.body['message'] as String?
          : response.reason;
      _picked.removeWhere((sid) => !_importable.any((i) => i.sid == sid));
    });
  }

  Future<void> _import() async {
    final scope = _scope;
    if (scope == null || _picked.isEmpty) return;

    setState(() {
      _importing = true;
      _formError = null;
    });

    final response = await widget.api.post('/phone/numbers/import', {
      'scope_type': scope.scopeType,
      'scope_id': scope.scopeId,
      'sids': _picked.toList()..sort(),
    });

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _importing = false;
        _formError = response.reason;
      });
      return;
    }

    _picked.clear();
    setState(() => _importing = false);
    await _load();
  }

  Future<void> _move(_Line line, String companyId) async {
    setState(() {
      _saving = true;
      _formError = null;
    });

    final response = await widget.api.post('/phone/numbers/${line.id}/move', {
      'scope_type': 'company',
      'scope_id': companyId,
    });

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _saving = false;
        _formError = response.reason;
      });
      return;
    }

    setState(() {
      _saving = false;
      _moving = null;
    });

    await _load();
  }

  Future<void> _save(_Line line, Map<String, dynamic> body) async {
    setState(() {
      _saving = true;
      _formError = null;
    });

    final response = await widget.api.patch('/phone/numbers/${line.id}', body);

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _saving = false;
        _formError = response.reason;
      });
      return;
    }

    setState(() {
      _saving = false;
      _editing = null;
    });

    await _load();
  }

  /// Which of the three jobs this pane does is on screen.
  ///
  /// They used to be stacked: the import list and the buy panel sat ABOVE the
  /// lines, so the thing you open Numbers to look at was below two things you
  /// use once. Segments also make the split possible at all — a buy panel
  /// cannot share a 320px column with a list of lines.
  int _segment = 0;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    if (_loading) {
      return const Center(child: CircularProgressIndicator());
    }

    if (_connections.isEmpty && _error == null) {
      return Padding(
        padding: const EdgeInsets.all(24),
        child: Text(
          'No phone account is connected yet. Ask for phone service on the '
          'Connection screen, and lines will be set up here once it is on.',
          style: theme.textTheme.bodyMedium,
        ),
      );
    }

    final silent = _lines.where((line) => !line.answers).length;

    return SettingsPane(
      title: 'Numbers',
      subtitle: 'The lines this business answers on, and what each one does.',
      error: _error,
      onRetry: _load,
      trailing: _connections.length > 1 ? _placePicker(theme) : null,

      // No footer: each line saves from its own form, and the import and buy
      // segments are actions rather than settings. A footer here would claim
      // the pane as a whole is something you save.
      body: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (silent > 0)
            Padding(
              padding: const EdgeInsets.fromLTRB(24, 0, 24, 12),
              child: _silentLinesNote(theme),
            ),

          if (_formError != null)
            Padding(
              padding: const EdgeInsets.fromLTRB(24, 0, 24, 12),
              child: ErrorNote(_formError!),
            ),

          Padding(
            padding: const EdgeInsets.fromLTRB(24, 0, 24, 12),
            child: _segments(theme),
          ),

          Expanded(child: _segmentBody(theme)),
        ],
      ),
    );
  }

  Widget _segments(ThemeData theme) {
    final waiting = _importable.length;

    return Wrap(
      spacing: 12,
      runSpacing: 8,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        _segmentButtons(waiting),
        if (_segment == 0 && _lines.isNotEmpty)
          phoneSearchBox(
            key: const ValueKey('comm-numbers-search'),
            hint: 'Search label or number',
            onChanged: (value) => setState(() => _query = value),
          ),
      ],
    );
  }

  Widget _segmentButtons(int waiting) {
    return SegmentedButton<int>(
      showSelectedIcon: false,
      segments: [
        ButtonSegment(value: 0, label: Text('Lines · ${_lines.length}')),
        ButtonSegment(
          value: 1,
          label: Text(waiting == 0 ? 'Import' : 'Import · $waiting waiting'),
        ),
        if (_canEdit && _scope != null)
          const ButtonSegment(value: 2, label: Text('Buy a number')),
      ],
      selected: {_segment},
      onSelectionChanged: (picked) => setState(() => _segment = picked.first),
    );
  }

  Widget _segmentBody(ThemeData theme) {
    return switch (_segment) {
      1 => ListView(
        padding: const EdgeInsets.fromLTRB(24, 0, 24, 24),
        children: _importSection(theme),
      ),
      2 => ListView(
        padding: const EdgeInsets.fromLTRB(24, 0, 24, 24),
        children: [
          if (_canEdit && _scope != null)
            BuyNumberPanel(
              key: ValueKey('buy:${_scope!.id}'),
              api: widget.api,
              scopeType: _scope!.scopeType,
              scopeId: _scope!.scopeId,
              onBought: _load,
            ),
        ],
      ),
      _ => _linesAndDetail(theme),
    };
  }

  /// The list beside the line being edited.
  ///
  /// This used to return `_LineForm` outright, so opening a line REPLACED the
  /// whole pane: you lost the list while editing, could not see the line above
  /// the one you were changing, and came back to the top of the list rather
  /// than to where you had been. On eleven lines that is the difference
  /// between checking a neighbour and starting again.
  Widget _linesAndDetail(ThemeData theme) {
    final editing = _editing;

    return LayoutBuilder(
      builder: (context, box) {
        // Below this there is not room for two columns, so the form pushes
        // over the list and carries a Back — which is what it did everywhere
        // before, and is right on a phone.
        final split = box.maxWidth >= 600;

        if (!split) {
          return editing == null
              ? _linesList(theme)
              : _lineForm(editing, showBack: true);
        }

        final listWidth = (box.maxWidth * 0.36).clamp(260.0, 420.0);

        return Row(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            SizedBox(width: listWidth, child: _linesList(theme)),
            VerticalDivider(width: 1, color: theme.colorScheme.outlineVariant),
            Expanded(
              child: editing == null
                  ? _noLineChosen(theme)
                  : _lineForm(editing, showBack: false),
            ),
          ],
        );
      },
    );
  }

  Widget _noLineChosen(ThemeData theme) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Text(
          _lines.isEmpty
              ? 'No lines yet. Import the ones this account already holds, or '
                    'buy a new one.'
              : 'Choose a line to set up what it does.',
          textAlign: TextAlign.center,
          style: theme.textTheme.bodySmall,
        ),
      ),
    );
  }

  Widget _lineForm(_Line editing, {required bool showBack}) {
    return _LineForm(
      key: ValueKey(editing.id),
      api: widget.api,
      scopeType: _scope?.scopeType ?? '',
      scopeId: _scope?.scopeId ?? '',
      line: editing,
      groups: _groups,
      schedules: _schedules,
      saving: _saving,
      error: _formError,
      showBack: showBack,
      onCancel: () => setState(() {
        _editing = null;
        _formError = null;
      }),
      onSave: (body) => _save(editing, body),
    );
  }

  Widget _linesList(ThemeData theme) {
    final moving = _moving;

    return ListView(
      padding: const EdgeInsets.fromLTRB(24, 0, 24, 24),
      children: [
        if (_lines.isEmpty)
          Text(
            'No lines yet. Import the ones this account already holds, or buy '
            'a new one.',
            style: theme.textTheme.bodySmall,
          )
        else ...[
          _tiles(),
          const SizedBox(height: 12),
          // Above the table rather than inside a row: a table row is its
          // cells, and the panel is a paragraph and a row of buttons.
          if (moving != null) ...[
            Card(
              margin: EdgeInsets.zero,
              child: Padding(
                padding: const EdgeInsets.only(top: 12),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Padding(
                      padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
                      child: Text(
                        moving.heading,
                        style: theme.textTheme.titleSmall,
                      ),
                    ),
                    _movePanel(moving, theme),
                  ],
                ),
              ),
            ),
            const SizedBox(height: 12),
          ],
          if (_bulkNote != null)
            Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: Text(_bulkNote!, style: theme.textTheme.bodySmall),
            ),
          if (_bulkPicked.isNotEmpty) _bulkBar(theme),
          _linesTable(theme),
        ],
      ],
    );
  }

  Widget _tiles() {
    int count(bool Function(_Line) test) => _lines.where(test).length;

    return FilterTiles(
      prefix: 'lines',
      picked: _tile,
      onPick: (key) => setState(() => _tile = key),
      tiles: [
        FilterTile(
          key: null,
          label: 'Lines',
          count: _lines.length,
          note: 'on this account',
        ),
        FilterTile(
          key: 'answered',
          label: 'Answered',
          count: count((l) => l.answers),
          note: 'something picks up',
          tone: Tone.ok,
        ),
        // The same rule as the red note above: the server's `answers`.
        FilterTile(
          key: 'silent',
          label: 'Nobody answers',
          count: count((l) => !l.answers),
          note: 'calls go to voicemail',
          tone: Tone.bad,
        ),
        FilterTile(
          key: 'recorded',
          label: 'Recorded',
          count: count((l) => l.record),
          note: 'calls kept',
        ),
        // Jose, 2026-10-05: "list of phones not in use, deactivated or not
        // assigned". From the server's last call and extension holder.
        FilterTile(
          key: 'idle',
          label: 'Not in use',
          count: count((l) => l.idle),
          note: 'no calls in 30 days',
          tone: Tone.caution,
        ),
        FilterTile(
          key: 'unassigned',
          label: 'Not assigned',
          count: count((l) => l.assignedTo == null),
          note: 'nobody holds it',
        ),
      ],
    );
  }

  /// The company a line belongs to, by name, when this view spans several.
  /// The row carries `scope_id`; the name is the one `/me` already has.
  String _company(_Line line) {
    final company = _me.companies
        .where((c) => c.id == line.scopeId)
        .firstOrNull;

    return company?.name ?? '—';
  }

  /// Row buttons at their text's height, not a 48px target row each.
  static final _compact = TextButton.styleFrom(
    visualDensity: VisualDensity.compact,
    tapTargetSize: MaterialTapTargetSize.shrinkWrap,
    minimumSize: const Size(40, 32),
    padding: const EdgeInsets.symmetric(horizontal: 8),
  );

  Widget _linesTable(ThemeData theme) {
    final shown = _lines.where(_matches).toList();
    final small = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );

    // A company view holds one company; the column would say it every row.
    // So does any list whose lines all belong to one company - A-1's 13 did,
    // and on a narrow pane it was the word that pushed every row to a third
    // line (Jose, 2026-10-05: "these are too fat").
    final showCompany =
        _me.scope.type != 'company' && shown.map(_company).toSet().length > 1;

    if (shown.isEmpty) {
      return Padding(
        padding: const EdgeInsets.all(24),
        child: Text(
          _query.trim().isNotEmpty
              ? 'Nothing matches "${_query.trim()}".'
              : 'No lines like that.',
          textAlign: TextAlign.center,
        ),
      );
    }

    return ListTable(
      columns: [
        // The number rides in the Line cell rather than a column of its own,
        // so a stacked row is two lines, not three.
        const ListColumn('Line', flex: 6),
        // Unnamed when stacked: the value ("Ring group · Office") says what
        // it is, and the words made the row three lines (Jose, 2026-10-05).
        const ListColumn('What answers it', flex: 4, namedWhenStacked: false),
        if (showCompany) const ListColumn('Company', flex: 3),
        // No Recorded or Written down columns: stacked on a narrow pane they
        // were a bare "Yes Yes" with nothing saying what (Jose, 2026-10-05:
        // "a random yes yes floating there without explanation ... get rid of
        // it"). Both are on the line's own editor, and Recorded is a tile.
        const ListColumn('', flex: 3, end: true),
      ],
      rowKey: (i) => ValueKey('line:${shown[i].id}'),
      rows: [
        for (final line in shown)
          [
            // The tick sits IN the line's cell, not a column of its own:
            // stacked on a narrow pane a column of its own was a whole line
            // per row holding one box (Jose, 2026-10-05: "these are too fat").
            Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                if (_canEdit)
                  Checkbox(
                    key: ValueKey('pick:${line.id}'),
                    visualDensity: VisualDensity.compact,
                    materialTapTargetSize: MaterialTapTargetSize.shrinkWrap,
                    value: _bulkPicked.contains(line.id),
                    onChanged: (on) => setState(() {
                      on == true
                          ? _bulkPicked.add(line.id)
                          : _bulkPicked.remove(line.id);
                      _bulkNote = null;
                    }),
                  ),
                Flexible(
                  child: Text(
                    line.label.trim().isEmpty || line.label == line.e164
                        ? formatPhoneNumber(line.e164)
                        : line.label,
                    overflow: TextOverflow.ellipsis,
                    style: theme.textTheme.bodyMedium?.copyWith(
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                ),
                if (line.label.trim().isNotEmpty &&
                    line.label != line.e164) ...[
                  const SizedBox(width: 8),
                  Text(formatPhoneNumber(line.e164), style: small),
                ],
              ],
            ),
            // A line nobody answers is a fault, not a footnote - the reason
            // it is a red pill and not grey text, which is how it went unread
            // on all five production lines for three hours.
            line.answers
                ? Text(_answeredBy(line))
                : const StatusPill('Nobody answers', tone: Tone.bad),
            if (showCompany) Text(_company(line), style: small),
            if (_canEdit)
              Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  if (_canMove)
                    TextButton(
                      style: _compact,
                      onPressed: () => setState(() {
                        _moving = _moving?.id == line.id ? null : line;
                        _formError = null;
                      }),
                      child: Text(_moving?.id == line.id ? 'Close' : 'Move'),
                    ),
                  TextButton(
                    style: _compact,
                    onPressed: () => setState(() {
                      _editing = line;
                      _formError = null;
                    }),
                    child: const Text('Edit'),
                  ),
                ],
              )
            else
              const SizedBox.shrink(),
          ],
      ],
    );
  }

  /// The answer step's own title from the server's trace ("Rings Dispatch"),
  /// so this cannot disagree with Call flow; worked out from the group only
  /// for a server that sends no trace.
  String _answeredBy(_Line line) {
    if (line.answerTitle != null) return line.answerTitle!;

    final group = _groups.where((g) => g.id == line.ringGroupId).firstOrNull;

    return group == null ? 'Answered by a call menu' : 'Rings ${group.name}';
  }

  Widget _placePicker(ThemeData theme) {
    return Row(
      // Both of these, and they are the bug.
      //
      // This is handed to SettingsPane as `trailing`, which is a NON-FLEX
      // child of the header's Row — so it arrives with an unbounded width
      // constraint. A Row that is `mainAxisSize.max` under an unbounded width
      // tries to be infinitely wide, and an `Expanded` inside it is the exact
      // combination Flutter refuses: "children have non-zero flex but incoming
      // width constraints are unbounded".
      //
      // In debug that throws. In a RELEASE build the assertion is skipped and
      // the layout simply degrades — which is how it shipped: the title's own
      // Expanded was squeezed to a few pixels and "Numbers" wrapped one letter
      // per line down the page.
      //
      // It only appeared for an organization with more than one phone account,
      // because that is the only time this picker is drawn at all.
      mainAxisSize: MainAxisSize.min,
      children: [
        SizedBox(
          // Narrower on a phone, where 260 ran off the screen's right edge
          // (phone audit 2026-11-04).
          width: MediaQuery.sizeOf(context).width < 600 ? 170 : 260,
          child: SearchPickerField<String>(
            value: _scope?.id,
            // The label goes INSIDE the field rather than beside it. Beside it
            // this was a Row of two parts that together wanted more width than
            // the header can spare, and a trailing that does not fit is how
            // this broke in the first place. One self-sizing control cannot.
            label: 'Phone account',
            border: const OutlineInputBorder(),
            isDense: true,
            options: [
              for (final c in _connections)
                SearchOption(value: c.id, label: c.label),
            ],
            onChanged: (id) {
              final next = _connections.where((c) => c.id == id).firstOrNull;
              if (next == null) return;
              setState(() => _scope = next);
              _loadImportable();
            },
          ),
        ),
      ],
    );
  }

  List<Widget> _importSection(ThemeData theme) {
    if (_importNote != null && _importable.isEmpty) {
      return [
        Text(
          'Already on this phone account',
          style: theme.textTheme.labelLarge,
        ),
        const SizedBox(height: 6),
        Text(_importNote!, style: theme.textTheme.bodySmall),
      ];
    }

    if (_importable.isEmpty) {
      return [
        Text(
          'Already on this phone account',
          style: theme.textTheme.labelLarge,
        ),
        const SizedBox(height: 6),
        Text(
          'Every line on this phone account is already set up in EzBizi.',
          style: theme.textTheme.bodySmall,
        ),
      ];
    }

    return [
      Text('Already on this phone account', style: theme.textTheme.labelLarge),
      const SizedBox(height: 6),
      Text(
        'These lines belong to this account but EzBizi does not handle them '
        'yet. Importing points the line at EzBizi, so calls to it start '
        'arriving here.',
        style: theme.textTheme.bodySmall,
      ),
      const SizedBox(height: 8),

      for (final line in _importable)
        CheckboxListTile(
          key: ValueKey('import:${line.sid}'),
          dense: true,
          contentPadding: EdgeInsets.zero,
          controlAffinity: ListTileControlAffinity.leading,
          value: _picked.contains(line.sid),
          onChanged: _canEdit
              ? (on) => setState(() {
                  if (on == true) {
                    _picked.add(line.sid);
                  } else {
                    _picked.remove(line.sid);
                  }
                })
              : null,
          title: Text(line.heading),
          subtitle: line.answersElsewhere
              ? Text(
                  'This line currently answers somewhere else. Importing it '
                  'sends its calls to EzBizi instead.',
                  style: theme.textTheme.bodySmall,
                )
              : null,
        ),

      const SizedBox(height: 8),

      Align(
        alignment: Alignment.centerLeft,
        child: Refused(
          reason: _canEdit ? null : _editRefusal,
          child: FilledButton(
            onPressed: (!_canEdit || _picked.isEmpty || _importing)
                ? null
                : _import,
            child: Text(
              _importing
                  ? 'Importing…'
                  : _picked.length == 1
                  ? 'Import 1 line'
                  : 'Import ${_picked.length} lines',
            ),
          ),
        ),
      ),
    ];
  }

  /// Why the whole business is missing calls, and what to do about it.
  Widget _silentLinesNote(ThemeData theme) {
    final silent = _lines.where((line) => !line.answers).length;
    final noGroups = _groups.isEmpty;

    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: theme.colorScheme.errorContainer,
        borderRadius: BorderRadius.circular(8),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(
            Icons.phone_missed_outlined,
            color: theme.colorScheme.onErrorContainer,
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  silent == 1
                      ? 'One line is not reaching anybody'
                      : '$silent lines are not reaching anybody',
                  style: theme.textTheme.titleSmall?.copyWith(
                    color: theme.colorScheme.onErrorContainer,
                  ),
                ),
                const SizedBox(height: 4),
                Text(
                  noGroups
                      ? 'No ring groups exist yet, so there is nobody for a '
                            'call to ring. Make one on the Ring groups screen, '
                            'put the people who answer the phone in it, then '
                            'set it on each line here.'
                      : 'Open each line below and give it a ring group. Until '
                            'then every caller hears voicemail.',
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: theme.colorScheme.onErrorContainer,
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  /// Where a line goes, and what it costs to send it there.
  ///
  /// Opens in the row rather than over it — no modals — and says the two
  /// things somebody would otherwise find out afterwards: the line's routing
  /// is let go when the companies keep separate Twilio accounts, and the call
  /// history goes with the number.
  Widget _movePanel(_Line line, ThemeData theme) {
    final elsewhere = _me.companiesInView
        .where((c) => c.id != line.scopeId)
        .toList();

    return Container(
      width: double.infinity,
      padding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('Move this line', style: theme.textTheme.labelLarge),
          const SizedBox(height: 4),
          Text(
            'The number keeps working and keeps its history — past calls and '
            'recordings go with it, and become readable by whoever can read '
            'the new company’s lines. If the two companies keep separate '
            'phone accounts, its ring group, hours, menu and voicemail stay '
            'behind and it will need setting up again.',
            style: theme.textTheme.bodySmall,
          ),

          const SizedBox(height: 12),

          if (elsewhere.isEmpty)
            Text(
              'There is no other company to move it to.',
              style: theme.textTheme.bodySmall,
            )
          else
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                for (final company in elsewhere)
                  OutlinedButton(
                    onPressed: _saving ? null : () => _move(line, company.id),
                    child: Text('Move to ${company.name}'),
                  ),
              ],
            ),
        ],
      ),
    );
  }
}

/// The form for one line. A separate widget so its controllers live and die
/// with the line being edited rather than with the pane.
/// Said about a control that also exists on the General pane.
///
/// Two editors for one fact is safe only if they cannot disagree, and here they
/// cannot: both write the same fields through the same endpoint, and this pane
/// reloads when it is opened, so whichever you open second shows what the other
/// last saved.
///
/// What this fixes is the half that arrangement does NOT. Somebody changing a
/// value here has no way to know the same control exists elsewhere, so the
/// second editor reads as a trap — you change it in one place, find the old
/// value in the other, and conclude the save failed.
///
/// A sentence rather than the chip the design drew: a Widget in `label:` reports
/// its full intrinsic width to InputDecorator, which overflows the field on a
/// narrow pane. Help text wraps, and is the same words in the same place.
const _alsoInGeneral = 'Also on General.';

class _LineForm extends StatefulWidget {
  const _LineForm({
    super.key,
    required this.api,
    required this.scopeType,
    required this.scopeId,
    required this.line,
    required this.groups,
    required this.schedules,
    required this.saving,
    required this.error,
    required this.showBack,
    required this.onCancel,
    required this.onSave,
  });

  final ApiClient api;
  final String scopeType;
  final String scopeId;
  final _Line line;
  final List<RingGroupRef> groups;
  final List<_Schedule> schedules;
  final bool saving;
  final String? error;

  /// False when the list is beside this form and Back would go nowhere.
  final bool showBack;
  final VoidCallback onCancel;
  final void Function(Map<String, dynamic>) onSave;

  @override
  State<_LineForm> createState() => _LineFormState();
}

class _LineFormState extends State<_LineForm> {
  late final TextEditingController _label;
  late final TextEditingController _forwardTo;
  late final TextEditingController _fallback;
  late final TextEditingController _notice;
  late String? _groupId;
  late String? _scheduleId;
  late bool _record;
  late bool _leadLine;
  late bool _transcribe;
  late String _afterHours;

  /// Carrier fields, populated only once the panel reports a change.
  Map<String, dynamic> _carrier = const {};

  @override
  void initState() {
    super.initState();
    _label = TextEditingController(text: widget.line.label);
    _forwardTo = TextEditingController(
      text: widget.line.afterHoursNumber ?? '',
    );
    _fallback = TextEditingController(text: widget.line.fallbackNumber ?? '');
    _notice = TextEditingController(text: widget.line.recordingNotice ?? '');
    _groupId = widget.line.ringGroupId;
    _scheduleId = widget.line.hoursScheduleId;
    _record = widget.line.record;
    _leadLine = widget.line.leadLine;
    _transcribe = widget.line.transcribe;
    _afterHours = widget.line.afterHours;
  }

  @override
  void dispose() {
    _label.dispose();
    _forwardTo.dispose();
    _fallback.dispose();
    _notice.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return ListView(
      padding: const EdgeInsets.all(24),
      children: [
        Row(
          children: [
            if (widget.showBack) ...[
              IconButton(
                tooltip: 'Back to the lines',
                icon: const Icon(Icons.arrow_back),
                onPressed: widget.onCancel,
              ),
              const SizedBox(width: 4),
            ],
            Expanded(
              child: Text(
                formatPhoneNumber(widget.line.e164),
                style: theme.textTheme.titleMedium,
              ),
            ),
          ],
        ),

        if (widget.error != null) ...[
          const SizedBox(height: 12),
          ErrorNote(widget.error!),
        ],

        const SizedBox(height: 16),
        TextField(
          controller: _label,
          decoration: const InputDecoration(
            labelText: 'What this line is called',
            border: OutlineInputBorder(),
          ),
        ),

        const SizedBox(height: 16),
        SearchPickerField<String?>(
          value: _groupId,
          label: 'Who it rings',
          border: const OutlineInputBorder(),
          options: [
            const SearchOption<String?>(
              value: null,
              label: 'Nobody — go straight to voicemail',
            ),
            for (final g in widget.groups)
              SearchOption<String?>(value: g.id, label: g.name),
          ],
          onChanged: (v) => setState(() => _groupId = v),
        ),

        const SizedBox(height: 16),
        SearchPickerField<String?>(
          value: _scheduleId,
          label: 'Opening hours',
          border: const OutlineInputBorder(),
          options: [
            const SearchOption<String?>(value: null, label: 'Always open'),
            for (final s in widget.schedules)
              SearchOption<String?>(value: s.id, label: s.name),
          ],
          onChanged: (v) => setState(() => _scheduleId = v),
        ),

        const SizedBox(height: 16),
        SearchPickerField<String>(
          value: _afterHours,
          label: 'When it is closed',
          border: const OutlineInputBorder(),
          options: const [
            SearchOption(value: 'voicemail', label: 'Take a voicemail'),
            SearchOption(value: 'forward', label: 'Forward the call'),
            SearchOption(
              value: 'closed',
              label: 'Say it is closed and hang up',
            ),
          ],
          onChanged: (v) => setState(() => _afterHours = v ?? 'voicemail'),
        ),

        if (_afterHours == 'forward') ...[
          const SizedBox(height: 16),
          TextField(
            controller: _forwardTo,
            keyboardType: TextInputType.phone,
            decoration: const InputDecoration(
              labelText: 'Forward to',
              hintText: '+12035550123',
              helperText: _alsoInGeneral,
              border: OutlineInputBorder(),
            ),
          ),
        ],

        const SizedBox(height: 16),
        TextField(
          controller: _fallback,
          keyboardType: TextInputType.phone,
          decoration: const InputDecoration(
            labelText: 'Fallback number',
            hintText: '+17865550123',
            helperText:
                '$_alsoInGeneral Dialled when the steps above reach nobody. '
                'Tried once, then voicemail. Leave empty to go straight to '
                'voicemail.',
            helperMaxLines: 3,
            border: OutlineInputBorder(),
          ),
        ),

        const SizedBox(height: 8),
        SwitchListTile(
          contentPadding: EdgeInsets.zero,
          value: _record,
          onChanged: (v) => setState(() => _record = v),
          title: const Text('Record calls on this line'),
          subtitle: Text(
            '$_alsoInGeneral Callers are told out loud before the call '
            'connects.',
            style: theme.textTheme.bodySmall,
          ),
        ),
        // Only when recording is on: this sentence is what makes the recording
        // lawful, so it is shown next to the switch that starts it rather than
        // filed away somewhere the person turning recording on will not look.
        if (_record) ...[
          const SizedBox(height: 8),
          TextField(
            controller: _notice,
            maxLines: 2,
            decoration: InputDecoration(
              labelText: 'What callers are told',
              hintText: 'This call may be recorded.',
              helperText:
                  '$_alsoInGeneral '
                  'Spoken before the call connects. This wording is what gets '
                  'the caller’s consent, so it has to be true where you '
                  'operate. Left empty, callers hear the sentence above.',
              helperMaxLines: 4,
              border: OutlineInputBorder(),
            ),
          ),
        ],

        // Jose, 2026-10-16: EZ Calls and EZ Texts in Leads.
        SwitchListTile(
          key: const Key('number-lead-line'),
          contentPadding: EdgeInsets.zero,
          value: _leadLine,
          onChanged: (v) => setState(() => _leadLine = v),
          title: const Text('Calls and texts to this line are leads'),
          subtitle: Text(
            'They appear in Leads under EZ Calls and EZ Texts.',
            style: theme.textTheme.bodySmall,
          ),
        ),

        SwitchListTile(
          contentPadding: EdgeInsets.zero,
          value: _transcribe,
          onChanged: (v) => setState(() => _transcribe = v),
          title: const Text('Write down what is said'),
          subtitle: Text(
            '$_alsoInGeneral Costs more per call. Off unless you need it.',
            style: theme.textTheme.bodySmall,
          ),
        ),

        const Divider(height: 32),
        CarrierSettings(
          api: widget.api,
          numberId: widget.line.id,
          scopeType: widget.scopeType,
          scopeId: widget.scopeId,
          callerIdLookup: widget.line.voiceCallerIdLookup,
          voicemailTranscribe: widget.line.voicemailTranscribe,
          emergencyAddressSid: widget.line.emergencyAddressSid,
          onChanged: (body) => _carrier = body,
        ),

        const SizedBox(height: 20),
        Row(
          children: [
            FilledButton(
              onPressed: widget.saving
                  ? null
                  : () => widget.onSave({
                      'label': _label.text.trim(),
                      'ring_group_id': _groupId,
                      'hours_schedule_id': _scheduleId,
                      'record': _record,
                      'lead_line': _leadLine,
                      'transcribe': _transcribe,
                      'after_hours': _afterHours,
                      'after_hours_number': _afterHours == 'forward'
                          ? _forwardTo.text.trim()
                          : null,
                      'fallback_number': _fallback.text.trim(),

                      // Sent even when recording is off, so the wording a
                      // business already chose survives switching it off and
                      // on again.
                      'recording_notice': _notice.text.trim(),

                      // Only what the carrier panel actually reports having
                      // changed — sending an untouched emergency address back
                      // would be a pointless Twilio write on every save.
                      ..._carrier,
                    }),
              child: Text(widget.saving ? 'Saving…' : 'Save'),
            ),
            const SizedBox(width: 12),
            TextButton(onPressed: widget.onCancel, child: const Text('Cancel')),
          ],
        ),
      ],
    );
  }
}

@immutable
class _Line {
  const _Line({
    required this.id,
    required this.e164,
    required this.label,
    required this.record,
    this.leadLine = false,
    required this.transcribe,
    required this.afterHours,
    required this.answers,
    required this.scopeId,
    required this.voiceCallerIdLookup,
    required this.voicemailTranscribe,
    this.afterHoursNumber,
    this.ringGroupId,
    this.hoursScheduleId,
    this.emergencyAddressSid,
    this.fallbackNumber,
    this.recordingNotice,
    this.answerTitle,
    this.lastCallAt,
    this.assignedTo,
  });

  /// The last call on this line, either way; null when it has had none.
  final DateTime? lastCallAt;

  /// The person whose extension holds this line, or null when nobody does.
  final String? assignedTo;

  /// No call in thirty days, or never.
  bool get idle =>
      lastCallAt == null ||
      DateTime.now().difference(lastCallAt!) > const Duration(days: 30);

  final String id;
  final String e164;
  final String label;

  /// Which company owns this line today — used to leave it out of its own
  /// "move to" list.
  final String scopeId;
  final bool record;

  /// Inbound calls and texts become leads (EZ Calls / EZ Texts).
  final bool leadLine;
  final bool transcribe;
  final String afterHours;
  final String? afterHoursNumber;
  final String? ringGroupId;
  final String? hoursScheduleId;

  /// Whether a call to this line can reach anybody at all.
  ///
  /// The server computes it from the same condition TwimlRenderer branches on
  /// — no ring group and no call flow means the renderer returns voicemail
  /// before it can emit a Dial. Judging it again here would be a second rule
  /// that could drift from the one that actually answers the phone.
  final bool answers;

  final bool voiceCallerIdLookup;
  final bool voicemailTranscribe;
  final String? emergencyAddressSid;
  final String? fallbackNumber;

  /// Null means the built-in consent sentence, never silence.
  final String? recordingNotice;

  /// The `answer` step's title from the server's `steps` trace.
  final String? answerTitle;

  /// Formatted for reading, compared raw.
  ///
  /// `label == e164` deliberately tests the stored form: a line whose label
  /// IS its number should show the number once, and comparing a formatted
  /// string to a stored one never matches, so it would show `+1 (770) 270 -
  /// 7152 · +17707... ` — the duplication this getter exists to avoid.
  String get heading => label.trim().isEmpty || label == e164
      ? formatPhoneNumber(e164)
      : '$label · ${formatPhoneNumber(e164)}';

  static List<_Line> listFrom(dynamic payload) {
    final data = payload is Map ? payload['data'] : payload;
    if (data is! List) return const [];

    return data
        .whereType<Map<String, dynamic>>()
        .map((row) {
          return _Line(
            id: row['id'].toString(),
            e164: row['e164']?.toString() ?? '',
            label: row['label']?.toString() ?? '',
            record: row['record'] == true,
            leadLine: row['lead_line'] == true,
            transcribe: row['transcribe'] == true,
            afterHours: row['after_hours']?.toString() ?? 'voicemail',
            afterHoursNumber: row['after_hours_number'] as String?,
            ringGroupId: row['ring_group_id'] as String?,
            hoursScheduleId: row['hours_schedule_id'] as String?,

            // The server decides this, from the same condition the call renderer
            // branches on. Only when it does not say — an older server — is it
            // worked out here, and then by that same rule rather than by assuming
            // the line is fine: assuming fine is how this went unnoticed before.
            scopeId: (row['scope_id'] ?? '').toString(),
            answers:
                row['answers'] as bool? ??
                (row['ring_group_id'] != null || row['flow_id'] != null),
            voiceCallerIdLookup: row['voice_caller_id_lookup'] == true,
            voicemailTranscribe: row['voicemail_transcribe'] == true,
            emergencyAddressSid: row['emergency_address_sid'] as String?,
            fallbackNumber: row['fallback_number'] as String?,
            recordingNotice: row['recording_notice'] as String?,
            answerTitle: _answerTitle(row['steps']),
            lastCallAt: DateTime.tryParse(
              row['last_call_at']?.toString() ?? '',
            ),
            assignedTo: row['assigned_to'] as String?,
          );
        })
        .toList(growable: false);
  }

  static String? _answerTitle(Object? steps) {
    if (steps is! List) return null;

    for (final step in steps.whereType<Map>()) {
      if (step['step'] == 'answer' && step['title'] is String) {
        return step['title'] as String;
      }
    }

    return null;
  }
}

@immutable
class _Importable {
  const _Importable({
    required this.sid,
    required this.e164,
    required this.label,
    required this.answersElsewhere,
  });

  final String sid;
  final String e164;
  final String label;
  final bool answersElsewhere;

  String get heading => label.trim().isEmpty || label == e164
      ? formatPhoneNumber(e164)
      : '$label · ${formatPhoneNumber(e164)}';

  static List<_Importable> listFrom(dynamic payload) {
    final data = payload is Map ? payload['data'] : payload;
    if (data is! List) return const [];

    return data
        .whereType<Map<String, dynamic>>()
        .map((row) {
          return _Importable(
            sid: row['sid'].toString(),
            e164: row['e164']?.toString() ?? '',
            label: row['label']?.toString() ?? '',
            answersElsewhere: row['answers_elsewhere'] == true,
          );
        })
        .toList(growable: false);
  }
}

@immutable
class _Conn {
  const _Conn({
    required this.id,
    required this.label,
    required this.scopeType,
    required this.scopeId,
  });

  final String id;
  final String label;
  final String scopeType;
  final String scopeId;

  static List<_Conn> listFrom(dynamic payload) {
    final data = payload is Map ? payload['data'] : payload;
    if (data is! List) return const [];

    return data
        .whereType<Map<String, dynamic>>()
        .map((row) {
          return _Conn(
            id: row['id'].toString(),
            label:
                row['scope_name']?.toString() ??
                row['scope_type']?.toString() ??
                'This place',
            scopeType: row['scope_type']?.toString() ?? 'company',
            scopeId: row['scope_id']?.toString() ?? '',
          );
        })
        .toList(growable: false);
  }
}

@immutable
class _Schedule {
  const _Schedule({required this.id, required this.name});

  final String id;
  final String name;

  static List<_Schedule> listFrom(dynamic payload) {
    final data = payload is Map ? payload['data'] : payload;
    if (data is! List) return const [];

    return data
        .whereType<Map<String, dynamic>>()
        .map(
          (r) => _Schedule(
            id: r['id'].toString(),
            name: r['name']?.toString() ?? 'Hours',
          ),
        )
        .toList(growable: false);
  }
}
