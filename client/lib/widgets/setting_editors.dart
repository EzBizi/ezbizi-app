import 'package:flutter/material.dart';

import '../core/job_status.dart';
import '../core/timestamps.dart';
import 'search_picker.dart';
import 'side_panel.dart';

/// Editors for the settings that have a shape (plan §6, §2.1 layer 5).
///
/// ── Why these exist ──────────────────────────────────────────────
///
/// Business hours, quiet hours and job statuses were all typed `json` on the
/// server, so the screen had one control for all three: a box of raw JSON. To
/// say the shop opens at eight you typed
///
///     {"monday": {"open": "08:00", "close": "17:00"}, ...}
///
/// and got every brace right, or the save was refused and you started again.
/// That is not a setting, it is a config file with a text area around it.
///
/// ── Driven by the type, not by the key ───────────────────────────
///
/// Each editor is chosen by the setting's declared type — `hours`,
/// `time_range`, `list` — never by its key. A second set of opening hours added
/// to the registry tomorrow gets this editor with no change here, which is the
/// same rule the rest of the app follows: the thing is configured, not coded.
///
/// ── No dialogs, and none needed ──────────────────────────────────
///
/// showTimePicker is a modal, so it is not used. A time is chosen from a
/// dropdown of quarter hours, which is a menu rather than a pop-up, and which
/// happens to be faster than a clock face for the "half past eight" that
/// opening times almost always are.

/// A time of day, in quarter-hour steps.
///
/// Values are 24-hour `HH:MM` because that is what the server stores and
/// compares. What is SHOWN follows the organization's `time_format` (§6), so
/// the same stored value reads as "5:00 PM" or "17:00" without either end
/// having to know which.
///
/// ── It used to follow the browser, and that was the same bug ─────
///
/// This formatted through `MaterialLocalizations.formatTimeOfDay` with
/// `alwaysUse24HourFormat: MediaQuery.alwaysUse24HourFormatOf(context)` — the
/// reader's MACHINE preference, and a locale for the rest. So an owner who set
/// Time format to 24h and then opened Business hours on the very next row
/// still saw "5:00 PM", from the screen where they had just changed it. A
/// setting that the screen presenting it ignores is worse than no setting at
/// all, which is the whole reason this work exists.
class TimeField extends StatelessWidget {
  const TimeField({
    super.key,
    required this.value,
    required this.formats,
    required this.onChanged,
  });

  final String value;

  /// The organization's, resolved for the scope being edited. Required, not
  /// defaulted: a default is how a control silently goes back to guessing.
  final DisplayFormats formats;

  final ValueChanged<String> onChanged;

  static const int _stepMinutes = 15;

  @override
  Widget build(BuildContext context) {
    final slots = <String>[
      for (var m = 0; m < 24 * 60; m += _stepMinutes)
        '${(m ~/ 60).toString().padLeft(2, '0')}:'
            '${(m % 60).toString().padLeft(2, '0')}',
    ];

    // Anything set through the API can sit off the quarter hour. The picker
    // draws whichever option matches its value and NOTHING when none does, so
    // dropping this one would leave the field blank on exactly the setting it
    // is meant to be showing. It joins the list in its right place instead.
    //
    // (It used to be worse and louder: the dropdown this replaced asserted on
    // a value with no item, so the same omission crashed rather than blanked.)
    if (!slots.contains(value)) {
      slots
        ..add(value)
        ..sort();
    }

    return SizedBox(
      width: 130,
      child: SearchPickerField<String>(
        value: value,
        isDense: true,
        border: InputBorder.none,
        onChanged: (v) => v == null ? null : onChanged(v),
        options: [
          for (final slot in slots)
            SearchOption(value: slot, label: formats.formatWallClock(slot)),
        ],
      ),
    );
  }
}

/// Opening times, one row per day (`hours`).
class HoursButton extends StatelessWidget {
  const HoursButton({
    super.key,
    required this.label,
    required this.value,
    required this.editable,
    required this.formats,
    required this.onChanged,
  });

  final String label;
  final dynamic value;
  final bool editable;

  /// Passed straight through to the TimeFields inside, and used by the summary
  /// on the button itself — which is the part somebody reads without opening
  /// anything, so it is the part that most needs to obey the setting.
  final DisplayFormats formats;

  final ValueChanged<Map<String, dynamic>> onChanged;


  static String title(String day) => day[0].toUpperCase() + day.substring(1, 3);

  Map<String, dynamic> get _week {
    final raw = value is Map ? Map<String, dynamic>.from(value as Map) : {};

    // A copy of each day too (audit 2026-11-27): the editor writes into these
    // maps, and a shared one changed the screen's value on Cancel.
    return {
      for (final day in weekdayKeys)
        day: raw[day] is Map
            ? Map<String, dynamic>.from(raw[day] as Map)
            : raw[day],
    };
  }

  @override
  Widget build(BuildContext context) {
    return OutlinedButton(
      onPressed: editable ? () => _open(context) : null,
      child: Text(_summary()),
    );
  }

  /// The week in one line, when the week has one line's worth to say.
  ///
  /// Almost every business is open the same hours on the days it is open, so
  /// the useful summary is which days and what time. When they differ, saying
  /// so is more honest than picking one day's hours to stand for all of them.
  String _summary() {
    final open = _week.entries.where((e) => e.value != null).toList();

    if (open.isEmpty) return 'Closed all week';

    final days = open.map((e) => title(e.key)).join(' ');
    final ranges = open
        .map((e) => '${e.value['open']}-${e.value['close']}')
        .toSet();

    if (ranges.length > 1) return '$days · varies';

    final first = open.first.value as Map;

    return '$days · ${formats.formatWallClock(first['open'] as String)}'
        ' – ${formats.formatWallClock(first['close'] as String)}';
  }

  Future<void> _open(BuildContext context) async {
    var week = _week;

    final saved = await showSidePanel<Map<String, dynamic>>(
      context,
      builder: (context) => StatefulBuilder(
        builder: (context, repaint) => SidePanel(
          title: label,
          width: 480,
          actions: [
            TextButton(
              onPressed: () => Navigator.of(context).pop(),
              child: const Text('Cancel'),
            ),
            FilledButton(
              onPressed: () => Navigator.of(context).pop(week),
              child: const Text('Save'),
            ),
          ],
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              for (final day in weekdayKeys)
                Padding(
                  padding: const EdgeInsets.symmetric(vertical: 2),
                  child: Row(
                    children: [
                      SizedBox(
                        width: 46,
                        child: Text(
                          title(day),
                          style: Theme.of(context).textTheme.titleSmall,
                        ),
                      ),
                      Switch(
                        value: week[day] != null,
                        onChanged: (on) => repaint(() {
                          // Closing keeps nothing, so reopening starts from the
                          // ordinary working day rather than from midnight to
                          // midnight, which is what an empty pair would mean.
                          week[day] = on
                              ? {'open': '08:00', 'close': '17:00'}
                              : null;
                        }),
                      ),
                      const SizedBox(width: 8),
                      if (week[day] == null)
                        Text(
                          'Closed',
                          style: Theme.of(context).textTheme.bodyMedium,
                        )
                      else ...[
                        TimeField(
                          value: week[day]['open'] as String,
                          formats: formats,
                          onChanged: (v) =>
                              repaint(() => week[day]['open'] = v),
                        ),
                        const Text(' – '),
                        TimeField(
                          value: week[day]['close'] as String,
                          formats: formats,
                          onChanged: (v) =>
                              repaint(() => week[day]['close'] = v),
                        ),
                      ],
                    ],
                  ),
                ),
              const SizedBox(height: 14),
              Text(
                'A day that is switched off is closed. Closing later than it '
                'opens is allowed — an evening shift running past midnight is '
                'a real working day, not a mistake.',
                style: Theme.of(context).textTheme.bodySmall,
              ),
            ],
          ),
        ),
      ),
    );

    if (saved != null) onChanged(saved);
  }
}

/// One start-and-end pair that can also be off (`time_range`).
class TimeRangeButton extends StatelessWidget {
  const TimeRangeButton({
    super.key,
    required this.label,
    required this.value,
    required this.editable,
    required this.formats,
    required this.onChanged,
    this.help,
  });

  final String label;
  final dynamic value;
  final bool editable;

  /// The organization's clock (§6), for the same reason HoursButton takes one.
  final DisplayFormats formats;

  final String? help;
  final ValueChanged<Map<String, dynamic>> onChanged;

  String? get _start =>
      value is Map ? (value as Map)['start'] as String? : null;
  String? get _end => value is Map ? (value as Map)['end'] as String? : null;

  @override
  Widget build(BuildContext context) {
    return OutlinedButton(
      onPressed: editable ? () => _open(context) : null,
      child: Text(
        _start == null || _end == null
            ? 'Off'
            : '${formats.formatWallClock(_start!)}'
                  ' – ${formats.formatWallClock(_end!)}',
      ),
    );
  }

  Future<void> _open(BuildContext context) async {
    var start = _start;
    var end = _end;

    final saved = await showSidePanel<Map<String, dynamic>>(
      context,
      builder: (context) => StatefulBuilder(
        builder: (context, repaint) => SidePanel(
          title: label,
          subtitle: help,
          actions: [
            TextButton(
              onPressed: () => Navigator.of(context).pop(),
              child: const Text('Cancel'),
            ),
            FilledButton(
              // Both or neither — half a range is a rule nothing downstream
              // can evaluate, and the server refuses it. The switch below is
              // what makes that impossible to express rather than a message
              // arriving after the save.
              onPressed: () =>
                  Navigator.of(context).pop({'start': start, 'end': end}),
              child: const Text('Save'),
            ),
          ],
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              SwitchListTile(
                contentPadding: EdgeInsets.zero,
                title: const Text('On'),
                value: start != null,
                onChanged: (on) => repaint(() {
                  start = on ? '22:00' : null;
                  end = on ? '07:00' : null;
                }),
              ),
              if (start != null && end != null) ...[
                const SizedBox(height: 8),
                Row(
                  children: [
                    const Text('From '),
                    TimeField(
                      value: start!,
                      formats: formats,
                      onChanged: (v) => repaint(() => start = v),
                    ),
                    const Text(' until '),
                    TimeField(
                      value: end!,
                      formats: formats,
                      onChanged: (v) => repaint(() => end = v),
                    ),
                  ],
                ),
              ],
            ],
          ),
        ),
      ),
    );

    if (saved != null) onChanged(saved);
  }
}

/// An ordered list of names — job statuses and anything like them (`list`).
///
/// Order is content here, not presentation: a status list is a pipeline, and
/// the sequence is what a board reads to lay its columns out left to right. So
/// the editor lets you drag, and saves what you see.
class ListButton extends StatefulWidget {
  const ListButton({
    super.key,
    required this.label,
    required this.value,
    required this.editable,
    required this.onChanged,
    this.help,
  });

  final String label;
  final dynamic value;
  final bool editable;
  final String? help;
  final ValueChanged<List<dynamic>> onChanged;

  @override
  State<ListButton> createState() => _ListButtonState();
}

class _ListButtonState extends State<ListButton> {
  List<JobStatus> get _items => parseJobStatuses(widget.value);

  @override
  Widget build(BuildContext context) {
    return ConstrainedBox(
      // The row it sits in has a heading beside it, so the button takes what
      // is left and ellipsizes rather than pushing the label off the screen.
      constraints: const BoxConstraints(maxWidth: 260),
      child: OutlinedButton(
        onPressed: widget.editable ? _open : null,
        child: Text(
          _items.isEmpty
              ? 'None'
              : _items.map((item) => item.label).join(', '),
          overflow: TextOverflow.ellipsis,
        ),
      ),
    );
  }

  Future<void> _open() async {
    final saved = await showSidePanel<List<dynamic>>(
      context,
      builder: (_) =>
          _ListPanel(label: widget.label, help: widget.help, items: _items),
    );

    if (saved != null) widget.onChanged(saved);
  }
}

class _ListPanel extends StatefulWidget {
  const _ListPanel({required this.label, required this.items, this.help});

  final String label;
  final String? help;
  final List<JobStatus> items;

  @override
  State<_ListPanel> createState() => _ListPanelState();
}

/// A row of the list, with an identity of its own.
///
/// Keyed by the text it holds, a row would get a new key on every keystroke and
/// lose focus mid-word; keyed by its position, dragging would carry the text of
/// whatever used to be there. So each row keeps an id that outlives both.
class _Row {
  _Row(this.id, {required String label, required String value, this.was})
    : labelController = TextEditingController(text: label),
      valueController = TextEditingController(text: value);

  final int id;
  final String? was;
  final TextEditingController labelController;
  final TextEditingController valueController;
}

class _ListPanelState extends State<_ListPanel> {
  late final List<_Row> _rows = [
    for (final (i, item) in widget.items.indexed)
      _Row(i, label: item.label, value: item.value, was: item.was ?? item.value),
  ];

  late int _nextId = _rows.length;
  String? _error;

  @override
  void dispose() {
    for (final row in _rows) {
      row.labelController.dispose();
      row.valueController.dispose();
    }
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return SidePanel(
      title: widget.label,
      subtitle: widget.help,
      width: 480,
      // The list scrolls itself — see side_panel.dart.
      scrollable: false,
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: const Text('Cancel'),
        ),
        FilledButton(onPressed: _save, child: const Text('Save')),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Expanded(
            child: ReorderableListView(
              buildDefaultDragHandles: false,
              onReorder: (from, to) => setState(() {
                if (to > from) to -= 1;
                _rows.insert(to, _rows.removeAt(from));
              }),
              children: [
                for (final (i, row) in _rows.indexed)
                  Padding(
                    key: ValueKey(row.id),
                    padding: const EdgeInsets.symmetric(vertical: 4),
                    child: Row(
                      children: [
                        ReorderableDragStartListener(
                          index: i,
                          child: const Padding(
                            padding: EdgeInsets.only(right: 8),
                            child: Icon(Icons.drag_indicator, size: 20),
                          ),
                        ),
                        Expanded(
                          flex: 3,
                          child: TextField(
                            controller: row.labelController,
                            decoration: const InputDecoration(
                              isDense: true,
                              border: OutlineInputBorder(),
                              labelText: 'Name',
                            ),
                          ),
                        ),
                        const SizedBox(width: 8),
                        Expanded(
                          flex: 2,
                          child: TextField(
                            controller: row.valueController,
                            decoration: const InputDecoration(
                              isDense: true,
                              border: OutlineInputBorder(),
                              labelText: 'Stored as',
                            ),
                          ),
                        ),
                        IconButton(
                          icon: const Icon(Icons.close, size: 18),
                          tooltip: 'Remove',
                          onPressed: () => setState(() {
                            final removed = _rows.removeAt(i);
                            removed.labelController.dispose();
                            removed.valueController.dispose();
                          }),
                        ),
                      ],
                    ),
                  ),
              ],
            ),
          ),
          if (_error != null) ...[
            const SizedBox(height: 10),
            Text(
              _error!,
              style: TextStyle(color: Theme.of(context).colorScheme.error),
            ),
          ],
          const SizedBox(height: 10),
          Align(
            alignment: Alignment.centerLeft,
            child: TextButton.icon(
              onPressed: () => setState(
                () => _rows.add(_Row(_nextId++, label: '', value: '')),
              ),
              icon: const Icon(Icons.add, size: 18),
              label: const Text('Add'),
            ),
          ),
        ],
      ),
    );
  }

  /// The same three rules the server enforces, checked while the cursor is
  /// still in the field rather than as a 422 after the panel has closed.
  void _save() {
    final entries = <JobStatus>[];

    for (final row in _rows) {
      final label = row.labelController.text.trim();
      var value = row.valueController.text.trim();
      if (value.isEmpty && label.isNotEmpty) {
        value = _slug(label);
      }
      entries.add(JobStatus(value: value, label: label, was: row.was));
    }

    if (entries.any((entry) => entry.label.isEmpty || entry.value.isEmpty)) {
      setState(
        () => _error = 'One of these is blank. Fill it in or remove it.',
      );
      return;
    }

    final values = [for (final entry in entries) entry.value];
    if (values.toSet().length != values.length) {
      setState(
        () => _error = 'Two entries are the same — each has to be distinct.',
      );
      return;
    }

    if (entries.isEmpty) {
      setState(() => _error = 'There has to be at least one.');
      return;
    }

    Navigator.of(context).pop([for (final entry in entries) entry.toJson()]);
  }

  String _slug(String label) {
    final slug = label
        .toLowerCase()
        .replaceAll(RegExp(r'[^a-z0-9]+'), '_')
        .replaceAll(RegExp(r'^_+|_+$'), '');
    return slug.isEmpty ? label : slug;
  }
}
