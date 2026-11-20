/// Work Hours › Payroll (UI queue 198, options 1 and 4; Merav via Jose,
/// 2026-11-19; server 47460410d). One week of the people in the top-bar
/// view: hours, rate, bonus, deductions and tech expenses make the weekly
/// pay. A manager edits bonus and deductions in the row (editing un-approves
/// it), approves each person or everyone, and downloads the .xlsx.
///
/// Daily approval (136) is the sibling: the same frame, chips and phone
/// cards. Every figure is the server's; nothing here adds pay up.
library;

import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/files.dart';
import '../../core/job_money.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../core/timestamps.dart';
import '../../widgets/empty_state.dart';
import '../../widgets/error_note.dart';
import '../../widgets/list_search.dart';
import '../../widgets/status_pill.dart';

class WorkHoursPayrollTab extends StatefulWidget {
  const WorkHoursPayrollTab({
    super.key,
    required this.api,
    required this.session,
  });

  final ApiClient api;
  final Session session;

  @override
  State<WorkHoursPayrollTab> createState() => _WorkHoursPayrollTabState();
}

class _WorkHoursPayrollTabState extends State<WorkHoursPayrollTab> {
  Map<String, dynamic>? _week;
  String? _error;
  bool _loading = true;
  bool _busy = false;
  bool _calendarOpen = false;
  String _query = '';
  String? _scopeKey;

  /// People whose tech expense lines are open.
  final _expensesOpen = <String>{};

  /// People whose note line is open.
  final _notesOpen = <String>{};

  @override
  void initState() {
    super.initState();
    _scopeKey = _currentScope();
    widget.session.addListener(_onSession);
    unawaited(_load());
  }

  @override
  void dispose() {
    widget.session.removeListener(_onSession);
    super.dispose();
  }

  String? _currentScope() {
    final scope = widget.session.me?.scope;
    return scope == null ? null : '${scope.type}:${scope.id}';
  }

  /// The top-bar view moved: the same week, for the new view.
  void _onSession() {
    final key = _currentScope();
    if (key == _scopeKey) return;
    _scopeKey = key;
    unawaited(_load(week: _week?['week_start'] as String?));
  }

  Future<void> _load({String? week}) async {
    setState(() => _loading = true);
    final response = await widget.api.get('/hr/time-clock/payroll', {
      if (week != null && week.isNotEmpty) 'week': week,
    });
    if (!mounted) return;
    _adopt(response);
  }

  void _adopt(ApiResponse response) {
    setState(() {
      _loading = false;
      _busy = false;
      if (response.ok && response.data is Map) {
        _week = Map<String, dynamic>.from(response.data as Map);
        _error = null;
      } else {
        _error = response.reason;
      }
    });
  }

  String? get _weekStart => _week?['week_start'] as String?;

  void _move(int days) {
    final day = parseCalendarDay(_weekStart);
    if (day == null) return;
    unawaited(
      _load(
        week: formatCalendarDay(DateTime(day.year, day.month, day.day + days)),
      ),
    );
  }

  /// Every write answers with the week again, so the screen takes it whole.
  Future<void> _send(Future<ApiResponse> request) async {
    setState(() => _busy = true);
    final response = await request;
    if (!mounted) return;
    if (response.ok) {
      _adopt(response);
    } else {
      setState(() => _busy = false);
      ScaffoldMessenger.maybeOf(
        context,
      )?.showSnackBar(SnackBar(content: Text(response.reason)));
    }
  }

  Future<void> _save(String userId, Map<String, dynamic> values) => _send(
    widget.api.put('/hr/time-clock/payroll/$userId', {
      'week': _weekStart,
      ...values,
    }),
  );

  Future<void> _approve(String userId) => _send(
    widget.api.post('/hr/time-clock/payroll/approve', {
      'user_id': userId,
      'week': _weekStart,
    }),
  );

  Future<void> _approveAll() => _send(
    widget.api.post('/hr/time-clock/payroll/approve-all', {'week': _weekStart}),
  );

  Future<void> _download() async {
    final week = _weekStart;
    setState(() => _busy = true);
    final bytes = await widget.api.getBytes('/hr/time-clock/payroll/export', {
      'week': ?week,
    });
    if (!mounted) return;
    setState(() => _busy = false);
    if (bytes == null) {
      ScaffoldMessenger.maybeOf(context)?.showSnackBar(
        const SnackBar(content: Text('Could not download the payroll sheet.')),
      );
      return;
    }
    Files.download('payroll-${week ?? 'week'}.xlsx', bytes);
  }

  List<Map<String, dynamic>> get _rows => [
    for (final r in (_week?['rows'] as List?) ?? const [])
      if (r is Map) Map<String, dynamic>.from(r),
  ];

  Map<String, dynamic> get _totals =>
      Map<String, dynamic>.from((_week?['totals'] as Map?) ?? const {});

  Map<String, String> get _warningLabels => {
    for (final e in ((_week?['warning_labels'] as Map?) ?? const {}).entries)
      '${e.key}': '${e.value}',
  };

  @override
  Widget build(BuildContext context) {
    final week = _week;
    if (week == null) {
      if (_error != null) {
        return Padding(
          padding: const EdgeInsets.all(16),
          child: Align(alignment: Alignment.topLeft, child: ErrorNote(_error!)),
        );
      }
      return const Center(child: CircularProgressIndicator());
    }

    return LayoutBuilder(
      key: const ValueKey('tour-work_hours-payroll'),
      builder: (context, constraints) {
        final phone = constraints.maxWidth < 600;
        final all = _rows;
        final needle = _query.trim().toLowerCase();
        final rows = needle.isEmpty
            ? all
            : [
                for (final r in all)
                  if ([
                    (r['person'] as Map?)?['name'],
                    (r['person'] as Map?)?['role'],
                    (r['person'] as Map?)?['branch'],
                  ].any(
                    (v) => v != null && '$v'.toLowerCase().contains(needle),
                  ))
                    r,
              ];
        final waiting = (_totals['waiting'] as num?)?.toInt() ?? 0;

        Widget line(Map<String, dynamic> row) {
          final id = '${(row['person'] as Map?)?['id']}';
          final args = _RowArgs(
            row: row,
            warningLabels: _warningLabels,
            busy: _busy,
            expensesOpen: _expensesOpen.contains(id),
            noteOpen: _notesOpen.contains(id),
            onToggleExpenses: () => setState(
              () => _expensesOpen.contains(id)
                  ? _expensesOpen.remove(id)
                  : _expensesOpen.add(id),
            ),
            onToggleNote: () => setState(
              () => _notesOpen.contains(id)
                  ? _notesOpen.remove(id)
                  : _notesOpen.add(id),
            ),
            onSave: (values) => _save(id, values),
            onApprove: () => _approve(id),
          );
          return phone
              ? _PhoneCard(key: ValueKey('payroll-row-$id'), args: args)
              : _TableRow(key: ValueKey('payroll-row-$id'), args: args);
        }

        final list = all.isNotEmpty && rows.isEmpty
            ? Padding(
                padding: const EdgeInsets.fromLTRB(16, 16, 16, 0),
                child: Text(
                  'No people match "${_query.trim()}".',
                  key: const ValueKey('payroll-no-match'),
                  style: Theme.of(context).textTheme.bodyMedium,
                ),
              )
            : rows.isEmpty
            ? const EmptyState(
                icon: Icons.payments_outlined,
                title: 'Nobody to pay this week',
                message:
                    'Nobody in this view clocked in, has a pay rate or a tech '
                    'expense this week.',
              )
            : ListView(
                key: const ValueKey('tour-work_hours-payroll-rows'),
                padding: EdgeInsets.fromLTRB(
                  phone ? 12 : 16,
                  phone ? 8 : 0,
                  phone ? 12 : 16,
                  16,
                ),
                children: [
                  if (!phone) const _HeaderRow(),
                  for (final (i, row) in rows.indexed) ...[
                    if (i > 0)
                      phone
                          ? const SizedBox(height: 10)
                          : const Divider(height: 1),
                    line(row),
                  ],
                  if (!phone) ...[
                    const Divider(height: 1),
                    _TotalRow(totals: _totals),
                  ],
                ],
              );

        return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            _top(week, phone: phone, waiting: waiting),
            if (_loading || _busy) const LinearProgressIndicator(minHeight: 2),
            if (_error != null)
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 8, 16, 0),
                child: ErrorNote(_error!),
              ),
            Expanded(child: list),
            if (phone && waiting > 0)
              SafeArea(
                top: false,
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(12, 0, 12, 12),
                  // Outlined, as Daily approval's: a filled full-width button
                  // under the thumb approved everyone on a stray tap.
                  child: OutlinedButton.icon(
                    key: const ValueKey('payroll-approve-all'),
                    onPressed: _busy ? null : _approveAll,
                    icon: const Icon(Icons.done_all, size: 18),
                    label: Text('Approve all $waiting waiting'),
                  ),
                ),
              ),
          ],
        );
      },
    );
  }

  Widget _top(
    Map<String, dynamic> week, {
    required bool phone,
    required int waiting,
  }) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final totals = _totals;

    final stepper = Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        IconButton(
          key: const ValueKey('payroll-previous-week'),
          tooltip: 'The week before',
          onPressed: _loading ? null : () => _move(-7),
          icon: const Icon(Icons.chevron_left),
        ),
        Flexible(
          child: Text(
            '${week['label'] ?? week['week_start'] ?? ''}',
            key: const ValueKey('payroll-week-label'),
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: theme.textTheme.titleMedium,
          ),
        ),
        IconButton(
          key: const ValueKey('payroll-next-week'),
          tooltip: 'The week after',
          onPressed: _loading ? null : () => _move(7),
          icon: const Icon(Icons.chevron_right),
        ),
        IconButton(
          key: const ValueKey('payroll-calendar'),
          tooltip: _calendarOpen
              ? 'Hide the calendar'
              : 'Pick a week on the calendar',
          onPressed: () => setState(() => _calendarOpen = !_calendarOpen),
          icon: Icon(
            _calendarOpen
                ? Icons.calendar_month
                : Icons.calendar_today_outlined,
            size: 20,
          ),
        ),
      ],
    );

    final calendar = _calendarOpen
        ? Padding(
            padding: const EdgeInsets.only(top: 8),
            child: Align(
              alignment: Alignment.topLeft,
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 360),
                child: Material(
                  color: scheme.surface,
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(10),
                    side: BorderSide(color: scheme.outlineVariant),
                  ),
                  child: CalendarDatePicker(
                    key: const ValueKey('payroll-calendar-picker'),
                    initialDate: parseCalendarDay(_weekStart) ?? DateTime(2000),
                    firstDate: DateTime(2000),
                    lastDate: DateTime(2100, 12, 31),
                    // Any day: the server takes the week it falls in.
                    onDateChanged: (picked) {
                      setState(() => _calendarOpen = false);
                      unawaited(_load(week: formatCalendarDay(picked)));
                    },
                  ),
                ),
              ),
            ),
          )
        : const SizedBox.shrink();

    final viewName = Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Icon(Icons.place_outlined, size: 16, color: scheme.onSurfaceVariant),
        const SizedBox(width: 4),
        Flexible(
          child: Text(
            widget.session.me?.scope.shortPath ?? '',
            key: const ValueKey('payroll-view-name'),
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: theme.textTheme.bodySmall?.copyWith(
              color: scheme.onSurfaceVariant,
            ),
          ),
        ),
      ],
    );

    final pay = _money(totals['pay']);
    final summary = Wrap(
      key: const ValueKey('tour-work_hours-payroll-summary'),
      spacing: 8,
      runSpacing: 8,
      children: [
        if (!phone)
          _Count(
            'People',
            '${totals['people'] ?? 0}',
            Tone.neutral,
            keyName: 'people',
          ),
        if (!phone)
          _Count(
            'Hours',
            _hours(totals['hours']),
            Tone.neutral,
            keyName: 'hours',
          ),
        _Count('Pay', pay, Tone.info, keyName: 'pay'),
        _Count('Waiting', '$waiting', Tone.caution, keyName: 'waiting'),
        _Count(
          phone ? 'Warnings' : 'With warnings',
          '${totals['with_warnings'] ?? 0}',
          Tone.bad,
          keyName: 'with_warnings',
        ),
      ],
    );

    final search = SizedBox(
      width: phone ? double.infinity : 240,
      child: ListSearch(
        key: const Key('work-hours-payroll-search'),
        hint: 'Find a person',
        width: double.infinity,
        onChanged: (v) => setState(() => _query = v),
      ),
    );

    final download = phone
        ? IconButton(
            key: const ValueKey('payroll-download'),
            tooltip: 'Download .xlsx',
            onPressed: _busy ? null : _download,
            icon: const Icon(Icons.download_outlined),
          )
        : OutlinedButton.icon(
            key: const ValueKey('payroll-download'),
            onPressed: _busy ? null : _download,
            icon: const Icon(Icons.download_outlined, size: 18),
            label: const Text('Download .xlsx'),
          );

    if (phone) {
      return Padding(
        padding: const EdgeInsets.fromLTRB(4, 4, 8, 0),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Align(alignment: Alignment.centerLeft, child: stepper),
                ),
                download,
              ],
            ),
            Padding(padding: const EdgeInsets.only(left: 12), child: viewName),
            calendar,
            Padding(
              padding: const EdgeInsets.fromLTRB(12, 8, 4, 0),
              child: summary,
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(12, 8, 4, 0),
              child: search,
            ),
          ],
        ),
      );
    }

    return Padding(
      padding: const EdgeInsets.fromLTRB(8, 8, 16, 8),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Wrap(
            spacing: 16,
            runSpacing: 8,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [stepper, viewName],
          ),
          calendar,
          const SizedBox(height: 8),
          Padding(
            padding: const EdgeInsets.only(left: 8),
            child: Row(
              children: [
                Expanded(
                  child: Wrap(
                    spacing: 16,
                    runSpacing: 8,
                    crossAxisAlignment: WrapCrossAlignment.center,
                    children: [summary, search],
                  ),
                ),
                const SizedBox(width: 12),
                download,
                if (waiting > 0) ...[
                  const SizedBox(width: 8),
                  FilledButton.icon(
                    key: const ValueKey('payroll-approve-all'),
                    onPressed: _busy ? null : _approveAll,
                    icon: const Icon(Icons.done_all, size: 18),
                    label: Text('Approve all $waiting waiting'),
                  ),
                ],
              ],
            ),
          ),
        ],
      ),
    );
  }
}

// ── What a row says ─────────────────────────────────────────────────

/// Dollars from the server as money: 1096.4 → "$1,096.40".
String _money(Object? dollars) =>
    formatMoney((((dollars as num?) ?? 0) * 100).round());

/// "paid Nov 11" from the server's timestamp.
String? _paidOn(Object? at) {
  final d = DateTime.tryParse('${at ?? ''}')?.toLocal();
  if (d == null) return null;
  const months = [
    'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', //
    'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec',
  ];
  return 'paid ${months[d.month - 1]} ${d.day}';
}

String _hours(Object? hours) {
  final h = ((hours as num?) ?? 0).toDouble();
  return h == h.roundToDouble() ? h.toStringAsFixed(1) : '$h';
}

/// "$24.00/h", "$52,000/yr", or null without a rate.
String? _rateText(Map<String, dynamic> row) {
  final rate = row['rate'] as num?;
  if (rate == null) return null;
  final per = switch ('${row['basis'] == 'hourly' ? 'hour' : row['period']}') {
    'hour' => '/h',
    'week' => '/wk',
    'month' => '/mo',
    'year' => '/yr',
    'day' => '/day',
    final p => ' per $p',
  };
  final whole = rate == rate.roundToDouble() && rate >= 1000;
  return '${whole ? formatMoney((rate * 100).round(), cents: false) : _money(rate)}$per';
}

class _RowArgs {
  const _RowArgs({
    required this.row,
    required this.warningLabels,
    required this.busy,
    required this.expensesOpen,
    required this.noteOpen,
    required this.onToggleExpenses,
    required this.onToggleNote,
    required this.onSave,
    required this.onApprove,
  });

  final Map<String, dynamic> row;
  final Map<String, String> warningLabels;
  final bool busy;
  final bool expensesOpen;
  final bool noteOpen;
  final VoidCallback onToggleExpenses;
  final VoidCallback onToggleNote;
  final Future<void> Function(Map<String, dynamic>) onSave;
  final VoidCallback onApprove;

  String get id => '${(row['person'] as Map?)?['id']}';
  Map person() => (row['person'] as Map?) ?? const {};
  double get overtime => ((row['overtime_hours'] as num?) ?? 0).toDouble();
  List<Map<String, dynamic>> get expenses => [
    for (final e in (row['expense_lines'] as List?) ?? const [])
      if (e is Map) Map<String, dynamic>.from(e),
  ];

  List<Widget> warnings() => [
    for (final w in (row['warnings'] as List?) ?? const [])
      StatusPill(
        warningLabels['$w'] ?? '$w',
        key: ValueKey('payroll-warning-$id-$w'),
        tone: w == 'no_rate' ? Tone.bad : Tone.caution,
        dense: true,
      ),
  ];

  Widget status() {
    if (row['status'] == 'approved') {
      final by = (row['approved_by'] as Map?)?['name'];
      return StatusPill(
        by == null ? 'Approved' : 'Approved by $by',
        key: ValueKey('payroll-approved-$id'),
        tone: Tone.ok,
        dense: true,
      );
    }
    return FilledButton(
      key: ValueKey('payroll-approve-$id'),
      onPressed: busy ? null : onApprove,
      child: const Text('Approve'),
    );
  }

  Widget moneyField(String key) => _MoneyField(
    key: ValueKey('payroll-$key-$id'),
    value: ((row[key] as num?) ?? 0).toDouble(),
    label: key == 'bonus' ? 'Bonus' : 'Deductions',
    onSave: (v) => onSave({key: v}),
  );

  Widget noteLine() => _NoteField(
    key: ValueKey('payroll-note-$id'),
    value: row['note'] as String? ?? '',
    onSave: (v) => onSave({'note': v.isEmpty ? null : v}),
  );

  Widget expensesToggle(BuildContext context) {
    final total = ((row['tech_expenses'] as num?) ?? 0).toDouble();
    if (expenses.isEmpty) return const Text('—');
    return InkWell(
      key: ValueKey('payroll-expenses-$id'),
      onTap: onToggleExpenses,
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(
            expensesOpen ? Icons.expand_more : Icons.chevron_right,
            size: 18,
          ),
          Text(_money(total)),
        ],
      ),
    );
  }

  Widget expenseLines(BuildContext context) {
    final theme = Theme.of(context);
    final style = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    return Column(
      key: ValueKey('payroll-expense-lines-$id'),
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        for (final e in expenses)
          Text(
            [
              ?e['job_number'],
              ?e['item'],
              if (e['quantity'] != null) '× ${e['quantity']}',
              _money(e['amount']),
              ?_paidOn(e['paid_at']),
            ].join(' · '),
            style: style,
          ),
      ],
    );
  }
}

/// Bonus or deductions, saved when the field is left (or Enter).
class _MoneyField extends StatefulWidget {
  const _MoneyField({
    super.key,
    required this.value,
    required this.label,
    required this.onSave,
  });

  final double value;
  final String label;
  final void Function(double) onSave;

  @override
  State<_MoneyField> createState() => _MoneyFieldState();
}

class _MoneyFieldState extends State<_MoneyField> {
  late final _text = TextEditingController(text: _show(widget.value));
  final _focus = FocusNode();

  static String _show(double v) => v.toStringAsFixed(2);

  @override
  void initState() {
    super.initState();
    _focus.addListener(() {
      if (!_focus.hasFocus) _commit();
    });
  }

  @override
  void didUpdateWidget(_MoneyField old) {
    super.didUpdateWidget(old);
    if (!_focus.hasFocus && old.value != widget.value) {
      _text.text = _show(widget.value);
    }
  }

  @override
  void dispose() {
    _text.dispose();
    _focus.dispose();
    super.dispose();
  }

  void _commit() {
    final raw = _text.text.replaceAll(RegExp(r'[\$,\s]'), '');
    final v = raw.isEmpty ? 0.0 : double.tryParse(raw);
    if (v == null || v < 0) {
      _text.text = _show(widget.value);
      return;
    }
    if ((v - widget.value).abs() < 0.005) return;
    widget.onSave(v);
  }

  @override
  Widget build(BuildContext context) => SizedBox(
    width: 96,
    child: Semantics(
      label: widget.label,
      child: TextField(
        controller: _text,
        focusNode: _focus,
        textAlign: TextAlign.end,
        keyboardType: const TextInputType.numberWithOptions(decimal: true),
        decoration: InputDecoration(
          isDense: true,
          prefixText: r'$',
          hintText: '0.00',
          contentPadding: const EdgeInsets.symmetric(
            horizontal: 8,
            vertical: 8,
          ),
          border: const OutlineInputBorder(),
        ),
        onSubmitted: (_) => _focus.unfocus(),
      ),
    ),
  );
}

/// The week's note for one person, saved when left.
class _NoteField extends StatefulWidget {
  const _NoteField({super.key, required this.value, required this.onSave});

  final String value;
  final void Function(String) onSave;

  @override
  State<_NoteField> createState() => _NoteFieldState();
}

class _NoteFieldState extends State<_NoteField> {
  late final _text = TextEditingController(text: widget.value);
  final _focus = FocusNode();

  @override
  void initState() {
    super.initState();
    _focus.addListener(() {
      if (!_focus.hasFocus && _text.text.trim() != widget.value) {
        widget.onSave(_text.text.trim());
      }
    });
  }

  @override
  void dispose() {
    _text.dispose();
    _focus.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => TextField(
    controller: _text,
    focusNode: _focus,
    maxLength: 500,
    decoration: const InputDecoration(
      isDense: true,
      labelText: 'Note for this week',
      counterText: '',
      border: OutlineInputBorder(),
    ),
    onSubmitted: (_) => _focus.unfocus(),
  );
}

/// One of the summary chips.
class _Count extends StatelessWidget {
  const _Count(this.label, this.value, this.tone, {required this.keyName});

  final String label;
  final String value;
  final Tone tone;
  final String keyName;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final colours = ToneColours.of(context, tone);

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
      decoration: BoxDecoration(
        color: colours.fill,
        borderRadius: BorderRadius.circular(10),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Text(
            label,
            style: theme.textTheme.bodySmall?.copyWith(color: colours.ink),
          ),
          const SizedBox(width: 8),
          Text(
            value,
            key: ValueKey('payroll-count-$keyName'),
            style: theme.textTheme.titleMedium?.copyWith(color: colours.ink),
          ),
        ],
      ),
    );
  }
}

// ── Desktop: the table ──────────────────────────────────────────────

const _flexName = 5;
const _wRate = 120.0;
const _wHours = 124.0;
const _wField = 120.0;
const _wExpenses = 132.0;
const _wPay = 120.0;
const _flexWarnings = 4;
const _wStatus = 200.0;

/// One table line: the name grows, the figures keep their widths and sit
/// right, with a gap so two headings never touch.
Widget _cells(List<Widget> c) {
  Widget fixed(double width, Widget child) => SizedBox(
    width: width,
    child: Padding(
      padding: const EdgeInsets.only(left: 12),
      child: Align(alignment: Alignment.topRight, child: child),
    ),
  );
  return Row(
    crossAxisAlignment: CrossAxisAlignment.start,
    children: [
      Expanded(flex: _flexName, child: c[0]),
      fixed(_wRate, c[1]),
      fixed(_wHours, c[2]),
      fixed(_wField, c[3]),
      fixed(_wField, c[4]),
      fixed(_wExpenses, c[5]),
      fixed(_wPay, c[6]),
      const SizedBox(width: 16),
      Expanded(flex: _flexWarnings, child: c[7]),
      fixed(_wStatus, c[8]),
    ],
  );
}

class _HeaderRow extends StatelessWidget {
  const _HeaderRow();

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final style = theme.textTheme.labelSmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    Widget cell(String text) => Text(text.toUpperCase(), style: style);

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 8),
      child: _cells([
        cell('Name'),
        cell('Rate'),
        cell('Hours'),
        cell('Bonus'),
        cell('Deductions'),
        cell('Tech expenses'),
        cell('Weekly pay'),
        cell('Warnings'),
        cell('Status'),
      ]),
    );
  }
}

class _TableRow extends StatelessWidget {
  const _TableRow({super.key, required this.args});

  final _RowArgs args;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    final person = args.person();
    final row = args.row;
    final rate = _rateText(row);

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 10),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          _cells([
            Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Flexible(
                      child: Text(
                        '${person['name'] ?? ''}',
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: theme.textTheme.titleSmall,
                      ),
                    ),
                    IconButton(
                      key: ValueKey('payroll-note-toggle-${args.id}'),
                      tooltip: (row['note'] as String?)?.isNotEmpty == true
                          ? 'Note: ${row['note']}'
                          : 'Add a note',
                      visualDensity: VisualDensity.compact,
                      iconSize: 16,
                      onPressed: args.onToggleNote,
                      icon: Icon(
                        (row['note'] as String?)?.isNotEmpty == true
                            ? Icons.sticky_note_2_outlined
                            : Icons.edit_outlined,
                      ),
                    ),
                  ],
                ),
                Text(
                  [?person['role'], ?person['branch']].join(' · '),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: muted,
                ),
              ],
            ),
            Text(rate ?? 'No rate', style: rate == null ? muted : null),
            Column(
              crossAxisAlignment: CrossAxisAlignment.end,
              children: [
                Text(
                  _hours(row['hours']),
                  key: ValueKey('payroll-hours-${args.id}'),
                ),
                if (args.overtime > 0)
                  Text(
                    '+${_hours(args.overtime)} OT, not paid',
                    key: ValueKey('payroll-overtime-${args.id}'),
                    style: muted,
                  ),
              ],
            ),
            args.moneyField('bonus'),
            args.moneyField('deductions'),
            args.expensesToggle(context),
            Text(
              row['pay'] == null ? '—' : _money(row['pay']),
              key: ValueKey('payroll-pay-${args.id}'),
              style: theme.textTheme.titleSmall,
            ),
            Wrap(spacing: 4, runSpacing: 4, children: args.warnings()),
            args.status(),
          ]),
          if (args.expensesOpen && args.expenses.isNotEmpty)
            Padding(
              padding: const EdgeInsets.only(top: 6, left: 12),
              child: args.expenseLines(context),
            ),
          if (args.noteOpen)
            Padding(
              padding: const EdgeInsets.only(top: 8, right: _wStatus),
              child: args.noteLine(),
            ),
        ],
      ),
    );
  }
}

class _TotalRow extends StatelessWidget {
  const _TotalRow({required this.totals});

  final Map<String, dynamic> totals;

  @override
  Widget build(BuildContext context) {
    final style = Theme.of(context).textTheme.titleSmall;
    return Padding(
      key: const ValueKey('payroll-total'),
      padding: const EdgeInsets.symmetric(vertical: 10),
      child: _cells([
        Text('Total · ${totals['people'] ?? 0} people', style: style),
        const SizedBox.shrink(),
        Text(_hours(totals['hours']), style: style),
        const SizedBox.shrink(),
        const SizedBox.shrink(),
        const SizedBox.shrink(),
        Text(_money(totals['pay']), style: style),
        const SizedBox.shrink(),
        const SizedBox.shrink(),
      ]),
    );
  }
}

// ── Phone: a card per person ────────────────────────────────────────

class _PhoneCard extends StatelessWidget {
  const _PhoneCard({super.key, required this.args});

  final _RowArgs args;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    final person = args.person();
    final row = args.row;
    final warnings = args.warnings();

    return Card(
      margin: EdgeInsets.zero,
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        '${person['name'] ?? ''}',
                        style: theme.textTheme.titleSmall,
                      ),
                      Text(
                        [
                          ?person['role'],
                          ?person['branch'],
                          _rateText(row) ?? 'No rate',
                        ].join(' · '),
                        style: muted,
                      ),
                    ],
                  ),
                ),
                Column(
                  crossAxisAlignment: CrossAxisAlignment.end,
                  children: [
                    Text(
                      row['pay'] == null ? '—' : _money(row['pay']),
                      key: ValueKey('payroll-pay-${args.id}'),
                      style: theme.textTheme.titleMedium,
                    ),
                    Text(
                      '${_hours(row['hours'])} h'
                      '${args.overtime > 0 ? ' · ${_hours(args.overtime)} OT' : ''}',
                      key: ValueKey('payroll-hours-${args.id}'),
                      style: muted,
                    ),
                  ],
                ),
              ],
            ),
            if (warnings.isNotEmpty) ...[
              const SizedBox(height: 6),
              Wrap(spacing: 4, runSpacing: 4, children: warnings),
            ],
            const SizedBox(height: 8),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              crossAxisAlignment: WrapCrossAlignment.center,
              children: [
                Text('Bonus', style: muted),
                args.moneyField('bonus'),
                Text('Deductions', style: muted),
                args.moneyField('deductions'),
              ],
            ),
            if (args.expenses.isNotEmpty) ...[
              const SizedBox(height: 6),
              Row(
                children: [
                  Text('Tech expenses', style: muted),
                  const SizedBox(width: 6),
                  args.expensesToggle(context),
                ],
              ),
              if (args.expensesOpen)
                Padding(
                  padding: const EdgeInsets.only(left: 12, top: 2),
                  child: args.expenseLines(context),
                ),
            ],
            if (args.noteOpen) ...[const SizedBox(height: 8), args.noteLine()],
            const SizedBox(height: 6),
            // Wraps rather than overflows: "Approved by <a long name>".
            Wrap(
              alignment: WrapAlignment.spaceBetween,
              crossAxisAlignment: WrapCrossAlignment.center,
              runSpacing: 4,
              children: [
                TextButton.icon(
                  key: ValueKey('payroll-note-toggle-${args.id}'),
                  onPressed: args.onToggleNote,
                  icon: const Icon(Icons.edit_outlined, size: 16),
                  label: Text(
                    (row['note'] as String?)?.isNotEmpty == true
                        ? 'Note'
                        : 'Add a note',
                  ),
                ),
                args.status(),
              ],
            ),
          ],
        ),
      ),
    );
  }
}
