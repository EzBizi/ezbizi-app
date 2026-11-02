import 'package:flutter/material.dart';

import '../../core/session.dart';
import '../../core/theme.dart';
import '../../core/timestamps.dart';
import '../../widgets/side_panel.dart';
import '../../widgets/status_pill.dart';

/// Human Resources at a glance, option 1 (desktop) and 4 (phone) of
/// `docs/previews/hr-dashboard-layouts.html`, UI queue 42 (Jose, 2026-10-01:
/// "the dashboard at human resources is also lacking, now we have a lot more
/// information that could be displayed here").
///
/// Six figures, joined and left by month, where people work, who is on the
/// clock right now, then three lists with names. Every figure and row opens
/// its whole list: in a side panel on a desktop, in place with ← back on a
/// phone. Drawn from `GET /hr/dashboard/overview` (HR); nothing is counted
/// here that the server did not count.
class HrOverview extends StatefulWidget {
  const HrOverview({
    super.key,
    required this.data,
    required this.period,
    required this.session,
  });

  final Map<String, dynamic> data;
  final String period;
  final Session session;

  @override
  State<HrOverview> createState() => _HrOverviewState();
}

/// One list a figure opens.
class _List {
  const _List(this.title, this.note, this.rows);

  final String title;
  final String note;
  final List<_Row> rows;
}

class _Row {
  const _Row(this.name, this.detail, {this.pill, this.tone, this.when});

  final String name;
  final String detail;
  final String? pill;
  final Tone? tone;
  final String? when;
}

class _HrOverviewState extends State<HrOverview> {
  /// On a phone, the list a figure opened, in place of the figures.
  _List? _opened;

  Map<String, dynamic> _map(String key) {
    final v = widget.data[key];
    return v is Map ? Map<String, dynamic>.from(v) : const {};
  }

  List<Map<String, dynamic>> _rows(Object? raw) => [
    for (final r in raw is List ? raw : const [])
      if (r is Map) Map<String, dynamic>.from(r),
  ];

  int _int(Object? v) => v is num ? v.toInt() : 0;

  String get _periodWord => switch (widget.period) {
    'day' => 'today',
    'week' => 'this week',
    'month' => 'this month',
    'quarter' => 'this quarter',
    _ => 'this year',
  };

  String _date(Object? iso) {
    final at = DateTime.tryParse('${iso ?? ''}');
    return at == null
        ? ''
        : widget.session.me!.display.formatDate(at.toLocal());
  }

  /// "13 days" since a date; empty when there is none.
  String _age(Object? iso) {
    final at = DateTime.tryParse('${iso ?? ''}');
    if (at == null) return '';
    final days = DateTime.now().difference(at).inDays;
    return days <= 0 ? 'today' : days == 1 ? '1 day' : '$days days';
  }

  // ── the lists behind the figures ─────────────────────────────────

  List<_Row> get _waiting {
    final rows = <(String?, _Row)>[
      for (final o in _rows(_map('onboarding')['items']))
        (
          '${o['since'] ?? ''}',
          _Row(
            '${o['name'] ?? ''}',
            '${o['contract'] ?? 'Paperwork'}',
            pill: switch (o['state']) {
              'to_sign' => 'To sign',
              'to_approve' => 'To approve',
              _ => 'Filling in',
            },
            tone: o['state'] == 'filling_in' ? Tone.info : Tone.caution,
            when: _age(o['since']),
          ),
        ),
      for (final i in _rows(_map('invitations_stalled')['items']))
        (
          '${i['sent_at'] ?? ''}',
          _Row(
            '${i['name'] ?? ''}',
            'Invitation, sent ${_date(i['sent_at'])}',
            pill: i['state'] == 'expired' ? 'Expired' : 'Not opened',
            tone: i['state'] == 'expired' ? Tone.bad : Tone.caution,
            when: _age(i['sent_at']),
          ),
        ),
    ];
    // Oldest first: the longest wait is the first thing to chase.
    rows.sort((a, b) => (a.$1 ?? '').compareTo(b.$1 ?? ''));
    return [for (final r in rows) r.$2];
  }

  List<_Row> _people(String key, String dateKey) => [
    for (final p in _rows(_map(key)['people']))
      _Row(
        '${p['name'] ?? ''}',
        [p['role'], p['place']]
            .where((v) => v != null && '$v'.isNotEmpty)
            .join(' · '),
        when: _date(p[dateKey]),
      ),
  ];

  List<_Row> get _certifications => [
    for (final c in _rows(_map('certifications')['items']))
      _Row(
        '${c['name'] ?? ''}',
        '${c['certification'] ?? ''}',
        pill: c['state'] == 'lapsed'
            ? 'Lapsed'
            : _untilDays(c['date']).isEmpty
            ? 'Expiring'
            : 'in ${_untilDays(c['date'])}',
        tone: c['state'] == 'lapsed' ? Tone.bad : Tone.caution,
        when: _date(c['date']),
      ),
  ];

  String _untilDays(Object? iso) {
    final at = DateTime.tryParse('${iso ?? ''}');
    if (at == null) return '';
    final days = at.difference(DateTime.now()).inDays;
    return days <= 1 ? '1 day' : '$days days';
  }

  void _open(BuildContext context, _List list, {required bool phone}) {
    if (phone) {
      setState(() => _opened = list);
      return;
    }
    showSidePanel<void>(
      context,
      builder: (context) => SidePanel(
        title: list.title,
        subtitle: list.note,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            if (list.rows.isEmpty) const Text('Nobody.'),
            for (final row in list.rows) _personRow(context, row),
          ],
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, box) {
        final phone = box.maxWidth < 760;
        final opened = _opened;
        if (phone && opened != null) return _openedList(context, opened);
        return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            _figures(context, phone: phone),
            const SizedBox(height: 16),
            if (phone) ...[
              _rightNow(context, phone: true),
              const SizedBox(height: 12),
              _byMonth(context),
              const SizedBox(height: 12),
              _places(context),
              const SizedBox(height: 12),
              ..._lists(context, phone: true),
            ] else ...[
              _equalRow([
                _byMonth(context),
                _places(context),
                _rightNow(context, phone: false),
              ]),
              const SizedBox(height: 16),
              _equalRow(_lists(context, phone: false)),
            ],
          ],
        );
      },
    );
  }

  /// Sibling cards are one size.
  Widget _equalRow(List<Widget> cards) => IntrinsicHeight(
    child: Row(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        for (final (i, card) in cards.indexed) ...[
          if (i > 0) const SizedBox(width: 16),
          Expanded(child: card),
        ],
      ],
    ),
  );

  // ── six figures ──────────────────────────────────────────────────

  Widget _figures(BuildContext context, {required bool phone}) {
    final books = _map('on_the_books');
    final joined = _map('joined');
    final left = _map('left');
    final onboarding = _map('onboarding');
    final stalled = _map('invitations_stalled');
    final certs = _map('certifications');
    final since = _date(left['since']);

    final tiles = [
      _figure(
        context,
        key: 'books',
        label: 'On the books',
        value: _int(books['total']),
        unit: 'now',
        note:
            '${_int(books['active'])} active · ${_int(books['inactive'])} inactive',
      ),
      _figure(
        context,
        key: 'joined',
        label: 'Joined',
        value: _int(joined['count']),
        unit: _periodWord,
        note: 'counted by start date',
        open: () => _open(
          context,
          _List('Joined $_periodWord', 'By start date, newest first.', _people(
            'joined',
            'started_on',
          )),
          phone: phone,
        ),
      ),
      _figure(
        context,
        key: 'left',
        label: 'Left',
        value: _int(left['count']),
        unit: _periodWord,
        note: since.isEmpty ? 'by leaving date' : 'leaving dates since $since',
        open: () => _open(
          context,
          _List('Left $_periodWord', 'By leaving date.', _people(
            'left',
            'left_on',
          )),
          phone: phone,
        ),
      ),
      _figure(
        context,
        key: 'onboarding',
        label: 'Onboarding',
        value: _int(onboarding['count']),
        unit: 'now',
        note:
            '${_int(onboarding['filling_in'])} filling in · '
            '${_int(onboarding['to_sign'])} to sign'
            '${_int(onboarding['to_approve']) > 0 ? ' · ${_int(onboarding['to_approve'])} to approve' : ''}',
        open: () => _open(
          context,
          _List(
            'Onboarding in flight',
            'Started and not finished, oldest first.',
            [
              for (final r in _waiting)
                if (r.pill == 'To sign' || r.pill == 'Filling in') r,
            ],
          ),
          phone: phone,
        ),
      ),
      _figure(
        context,
        key: 'stalled',
        label: 'Invitations stalled',
        value: _int(stalled['count']),
        unit: 'now',
        note:
            '${_int(stalled['expired'])} expired · '
            '${_int(stalled['not_opened'])} not opened',
        open: () => _open(
          context,
          _List(
            'Invitations stalled',
            'Expired, or never opened.',
            [
              for (final r in _waiting)
                if (r.pill == 'Expired' || r.pill == 'Not opened') r,
            ],
          ),
          phone: phone,
        ),
      ),
      _figure(
        context,
        key: 'certifications',
        label: 'Certifications',
        value: _int(certs['count']),
        unit: 'next 60 days',
        note:
            '${_int(certs['lapsed'])} lapsed · ${_int(certs['expiring'])} '
            'expiring',
        open: () => _open(
          context,
          _List(
            'Certifications',
            'Lapsed, or expiring within 60 days.',
            _certifications,
          ),
          phone: phone,
        ),
      ),
    ];

    final perRow = phone ? 2 : 6;
    return Column(
      children: [
        for (var i = 0; i < tiles.length; i += perRow) ...[
          if (i > 0) const SizedBox(height: 10),
          IntrinsicHeight(
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                for (var j = i; j < i + perRow && j < tiles.length; j++) ...[
                  if (j > i) const SizedBox(width: 10),
                  Expanded(child: tiles[j]),
                ],
              ],
            ),
          ),
        ],
      ],
    );
  }

  Widget _figure(
    BuildContext context, {
    required String key,
    required String label,
    required int value,
    required String unit,
    required String note,
    VoidCallback? open,
  }) {
    final theme = Theme.of(context);
    final radius = BorderRadius.circular(EzBiziTheme.controlRadius);
    return Material(
      key: Key('hr-figure-$key'),
      color: theme.colorScheme.surface,
      shape: RoundedRectangleBorder(
        borderRadius: radius,
        side: BorderSide(color: EzBiziTheme.edge(context)),
      ),
      child: InkWell(
        borderRadius: radius,
        onTap: open,
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Expanded(
                    child: Text(
                      label.toUpperCase(),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: theme.textTheme.labelSmall?.copyWith(
                        letterSpacing: 0.8,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                  ),
                  if (open != null)
                    Icon(
                      Icons.chevron_right,
                      size: 16,
                      color: theme.colorScheme.onSurfaceVariant,
                    ),
                ],
              ),
              const SizedBox(height: 4),
              Text.rich(
                TextSpan(
                  children: [
                    TextSpan(
                      text: '$value',
                      style: theme.textTheme.headlineSmall?.copyWith(
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                    TextSpan(text: '  $unit', style: theme.textTheme.bodySmall),
                  ],
                ),
              ),
              Text(
                note,
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
                style: theme.textTheme.bodySmall,
              ),
            ],
          ),
        ),
      ),
    );
  }

  // ── the cards ────────────────────────────────────────────────────

  Widget _card(
    BuildContext context, {
    required String title,
    String? aside,
    required Widget child,
    Key? key,
  }) {
    final theme = Theme.of(context);
    return Container(
      key: key,
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: theme.colorScheme.surface,
        border: Border.all(color: EzBiziTheme.edge(context)),
        borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Expanded(child: Text(title, style: theme.textTheme.titleSmall)),
              if (aside != null)
                // Gives way to the title on a phone rather than overflowing.
                Flexible(
                  child: Text(
                    aside,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    textAlign: TextAlign.right,
                    style: theme.textTheme.bodySmall,
                  ),
                ),
            ],
          ),
          const SizedBox(height: 10),
          child,
        ],
      ),
    );
  }

  Widget _byMonth(BuildContext context) {
    final theme = Theme.of(context);
    final months = _rows(widget.data['by_month']);
    final joinedInk = theme.colorScheme.primary;
    final leftInk = ToneColours.of(context, Tone.caution).ink;
    final top = months.fold<int>(
      1,
      (m, r) => [m, _int(r['joined']), _int(r['left'])].reduce(
        (a, b) => a > b ? a : b,
      ),
    );
    final joined = months.fold<int>(0, (s, r) => s + _int(r['joined']));
    final left = months.fold<int>(0, (s, r) => s + _int(r['left']));

    // A share of the room above the month's name, so a taller font takes
    // it from the bars rather than overflowing the card.
    Widget bar(int n, Color ink, double width) => SizedBox(
      width: width,
      child: FractionallySizedBox(
        heightFactor: n == 0 ? 0.02 : n / top,
        alignment: Alignment.bottomCenter,
        child: DecoratedBox(
          decoration: BoxDecoration(
            color: ink,
            borderRadius: const BorderRadius.vertical(top: Radius.circular(2)),
          ),
        ),
      ),
    );

    return _card(
      context,
      key: const Key('hr-by-month'),
      title: 'Joined and left, last 12 months',
      aside: 'Joined $joined · Left $left',
      child: SizedBox(
        height: 116,
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.end,
          children: [
            for (final m in months)
              Expanded(
                // Sized from the column it gets: on a narrow card (an iPad's
                // split view, Apple 2026-10-07) the bars thin out, the month
                // goes to one letter, and the label has a fixed slot, so no
                // column can overflow by a fraction of a pixel.
                child: LayoutBuilder(
                  builder: (context, column) {
                    final width = column.maxWidth;
                    final barWidth = ((width - 2) / 2).clamp(1.0, 8.0);
                    final month = int.tryParse(
                      '${m['month'] ?? ''}'.split('-').last,
                    );
                    final name = month == null || month < 1 || month > 12
                        ? ''
                        : shortMonths[month - 1];
                    return Column(
                      children: [
                        Expanded(
                          child: Row(
                            mainAxisAlignment: MainAxisAlignment.center,
                            crossAxisAlignment: CrossAxisAlignment.end,
                            children: [
                              bar(_int(m['joined']), joinedInk, barWidth),
                              const SizedBox(width: 2),
                              bar(_int(m['left']), leftInk, barWidth),
                            ],
                          ),
                        ),
                        const SizedBox(height: 4),
                        SizedBox(
                          height: 16,
                          child: FittedBox(
                            fit: BoxFit.scaleDown,
                            child: Text(
                              width < 26 && name.isNotEmpty
                                  ? name.substring(0, 1)
                                  : name,
                              maxLines: 1,
                              softWrap: false,
                              style: theme.textTheme.labelSmall,
                            ),
                          ),
                        ),
                      ],
                    );
                  },
                ),
              ),
          ],
        ),
      ),
    );
  }

  Widget _places(BuildContext context) {
    final theme = Theme.of(context);
    final places = _rows(widget.data['places']);
    final top = places.fold<int>(
      1,
      (m, p) => _int(p['count']) > m ? _int(p['count']) : m,
    );
    final overlap = _int(widget.data['overlap']);

    return _card(
      context,
      key: const Key('hr-places'),
      title: 'Where people work',
      aside: 'active, now',
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          for (final p in places)
            Padding(
              padding: const EdgeInsets.only(bottom: 6),
              child: Row(
                children: [
                  Expanded(
                    flex: 5,
                    child: Text(
                      '${p['name'] ?? 'Not assigned to a place'}',
                      overflow: TextOverflow.ellipsis,
                      style: theme.textTheme.bodySmall,
                    ),
                  ),
                  Expanded(
                    flex: 4,
                    child: ClipRRect(
                      borderRadius: BorderRadius.circular(3),
                      child: LinearProgressIndicator(
                        value: _int(p['count']) / top,
                        minHeight: 6,
                        backgroundColor:
                            theme.colorScheme.surfaceContainerHighest,
                      ),
                    ),
                  ),
                  SizedBox(
                    width: 32,
                    child: Text(
                      '${_int(p['count'])}',
                      textAlign: TextAlign.right,
                      style: theme.textTheme.titleSmall,
                    ),
                  ),
                ],
              ),
            ),
          if (places.isEmpty)
            Text('Nobody is placed yet.', style: theme.textTheme.bodySmall),
          if (overlap > 0)
            Text(
              overlap == 1
                  ? '1 person works in two places and is counted in both.'
                  : '$overlap people work in two places and are counted in '
                        'both.',
              style: theme.textTheme.bodySmall,
            ),
        ],
      ),
    );
  }

  Widget _rightNow(BuildContext context, {required bool phone}) {
    final theme = Theme.of(context);
    final now = _map('right_now');
    final clock = now['on_the_clock'] is Map
        ? Map<String, dynamic>.from(now['on_the_clock'] as Map)
        : null;
    final away = now['away_today'] is Map
        ? Map<String, dynamic>.from(now['away_today'] as Map)
        : null;
    final retake = now['due_for_retaking'] is Map
        ? Map<String, dynamic>.from(now['due_for_retaking'] as Map)
        : null;

    Widget line(String label, String note, int value) => Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(label, style: theme.textTheme.bodyMedium),
                Text(note, style: theme.textTheme.bodySmall),
              ],
            ),
          ),
          Text(
            '$value',
            style: theme.textTheme.titleLarge?.copyWith(
              fontWeight: FontWeight.w800,
            ),
          ),
        ],
      ),
    );

    return _card(
      context,
      key: const Key('hr-right-now'),
      title: 'Right now',
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (clock != null)
            line(
              'On the clock',
              'Work Hours · of ${_int(clock['of_active'])} active',
              _int(clock['count']),
            ),
          if (away != null)
            line(
              'Away today',
              'Work Hours · ${_int(away['vacation'])} vacation, '
                  '${_int(away['sick'])} sick',
              _int(away['count']),
            ),
          if (retake != null)
            line(
              'Due for retaking',
              'Training · a pass has lapsed',
              _int(retake['count']),
            ),
          if (clock == null && away == null && retake == null)
            Text(
              'Work Hours and Training are not open to you, so there is '
              'nothing to show here.',
              style: theme.textTheme.bodySmall,
            ),
        ],
      ),
    );
  }

  List<Widget> _lists(BuildContext context, {required bool phone}) {
    final waiting = _waiting;
    final joined = _people('joined', 'started_on');
    final certs = _certifications;

    Widget list(
      String key,
      String title,
      String aside,
      List<_Row> rows,
      String empty,
      _List whole,
    ) => _card(
      context,
      key: Key('hr-list-$key'),
      title: '$title  ${rows.length}',
      aside: aside,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (rows.isEmpty)
            Text(empty, style: Theme.of(context).textTheme.bodySmall),
          for (final row in rows.take(5)) _personRow(context, row),
          if (rows.length > 5)
            Align(
              alignment: Alignment.centerLeft,
              child: TextButton(
                onPressed: () => _open(context, whole, phone: phone),
                child: Text('${rows.length - 5} more'),
              ),
            ),
        ],
      ),
    );

    return [
      list(
        'waiting',
        'Waiting on someone',
        'oldest first',
        waiting,
        'Nothing is waiting.',
        _List('Waiting on someone', 'Oldest first.', waiting),
      ),
      if (phone) const SizedBox(height: 12),
      list(
        'joined',
        'Joined $_periodWord',
        'start date',
        joined,
        'Nobody joined $_periodWord.',
        _List('Joined $_periodWord', 'By start date, newest first.', joined),
      ),
      if (phone) const SizedBox(height: 12),
      list(
        'certifications',
        'Certifications',
        'lapsed or within 60 days',
        certs,
        'Nothing lapses in the next 60 days.',
        _List('Certifications', 'Lapsed, or expiring within 60 days.', certs),
      ),
    ];
  }

  Widget _personRow(BuildContext context, _Row row) {
    final theme = Theme.of(context);
    final initials = row.name
        .split(RegExp(r'\s+'))
        .where((w) => w.isNotEmpty)
        .take(2)
        .map((w) => w[0].toUpperCase())
        .join();
    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Row(
        children: [
          CircleAvatar(
            radius: 14,
            backgroundColor: theme.colorScheme.primaryContainer,
            child: Text(
              initials,
              style: theme.textTheme.labelSmall?.copyWith(
                color: theme.colorScheme.primary,
                fontWeight: FontWeight.w800,
              ),
            ),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  row.name,
                  overflow: TextOverflow.ellipsis,
                  style: theme.textTheme.bodyMedium,
                ),
                if (row.detail.isNotEmpty)
                  Text(
                    row.detail,
                    overflow: TextOverflow.ellipsis,
                    style: theme.textTheme.bodySmall,
                  ),
              ],
            ),
          ),
          if (row.pill != null) ...[
            const SizedBox(width: 6),
            StatusPill(row.pill!, tone: row.tone ?? Tone.neutral),
          ],
          if (row.when != null && row.when!.isNotEmpty) ...[
            const SizedBox(width: 8),
            Text(row.when!, style: theme.textTheme.bodySmall),
          ],
        ],
      ),
    );
  }

  /// A phone: the list a figure opened, in place, with ← back.
  Widget _openedList(BuildContext context, _List list) {
    final theme = Theme.of(context);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Align(
          alignment: Alignment.centerLeft,
          child: TextButton.icon(
            key: const Key('hr-list-back'),
            onPressed: () => setState(() => _opened = null),
            icon: const Icon(Icons.arrow_back, size: 18),
            label: Text('${list.title}  ${list.rows.length}'),
          ),
        ),
        Text(list.note, style: theme.textTheme.bodySmall),
        const SizedBox(height: 10),
        if (list.rows.isEmpty) const Text('Nobody.'),
        for (final row in list.rows) _personRow(context, row),
      ],
    );
  }
}
