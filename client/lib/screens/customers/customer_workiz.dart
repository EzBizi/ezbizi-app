import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/job_money.dart';
import '../../core/theme.dart';
import '../../core/timestamps.dart';
import '../../widgets/error_note.dart';
import '../../widgets/tag.dart';

/// A customer's money and notes from Workiz, on the customer panel (UI queue
/// 59; Jose: "as u recommend", desktop 1 and phone 4, relayed by Customers).
///
/// The panel takes the job panel's shape (queue 58): Customer, Money, Notes
/// from Workiz. Money is past due, due and paid, summed by the server across the jobs in the branches the
/// reader works in. Notes are fetched from Workiz live, one list per branch's
/// Workiz client, and only when somebody opens them.

/// One currency's figures from `data.money`.
class CustomerMoney {
  const CustomerMoney._(this._map);

  final Map<String, dynamic> _map;

  /// The first entry of `data.money`, or null when there is nothing to draw:
  /// null (no Jobs Financials) and an empty list (nothing in Workiz) alike.
  /// A-1 bills in one currency; more than one would need its own design.
  static CustomerMoney? of(Object? raw) {
    if (raw is! List || raw.isEmpty || raw.first is! Map) return null;
    return CustomerMoney._(Map<String, dynamic>.from(raw.first as Map));
  }

  int _minor(String key) => (_map[key] as num?)?.toInt() ?? 0;

  String get currency => '${_map['currency'] ?? 'USD'}';

  /// Null when the server was not given today's date: unknown, never zero.
  int? get pastDue => (_map['past_due_minor'] as num?)?.toInt();
  int get due => _minor('due_minor');

  /// Money collected: successful payments net of refunds, pending left out.
  int get paid => _minor('paid_minor');
  int get invoiced => _minor('invoiced_minor');
  int get invoices => _minor('invoices');
  int get openEstimates => _minor('open_estimates');
  int get openEstimatesMinor => _minor('open_estimates_minor');

  bool get late => (pastDue ?? 0) > 0;

  String money(int minor) => formatMoney(minor, currency: currency);
}

/// Past due, due and paid, with past due in red when there is any.
class CustomerMoneyCard extends StatelessWidget {
  const CustomerMoneyCard({super.key, required this.money});

  final CustomerMoney money;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurfaceVariant;
    final bad = ToneColours.of(context, Tone.bad);

    Widget figure(String label, String value, {bool alarm = false, Key? key}) =>
        Expanded(
          child: Container(
            key: key,
            padding: const EdgeInsets.fromLTRB(10, 8, 10, 8),
            decoration: BoxDecoration(
              color: alarm ? bad.fill : theme.colorScheme.surface,
              border: Border.all(
                color: alarm
                    ? bad.ink.withValues(alpha: 0.3)
                    : theme.colorScheme.outlineVariant,
              ),
              borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  label.toUpperCase(),
                  style: theme.textTheme.labelSmall?.copyWith(
                    color: alarm ? bad.ink : muted,
                    fontWeight: FontWeight.w700,
                  ),
                ),
                const SizedBox(height: 2),
                FittedBox(
                  fit: BoxFit.scaleDown,
                  alignment: AlignmentDirectional.centerStart,
                  child: Text(
                    value,
                    style: theme.textTheme.titleMedium?.copyWith(
                      fontWeight: FontWeight.w800,
                      color: alarm ? bad.ink : null,
                    ),
                  ),
                ),
              ],
            ),
          ),
        );

    final pastDue = money.pastDue;
    final parts = [
      money.invoices == 1 ? '1 invoice' : '${money.invoices} invoices',
      '${money.money(money.invoiced)} invoiced',
      if (money.openEstimates == 0)
        'no open estimates'
      else
        '${money.openEstimates == 1 ? '1 open estimate' : '${money.openEstimates} open estimates'} '
            'worth ${money.money(money.openEstimatesMinor)}',
    ];

    return Container(
      key: const Key('customer-money'),
      padding: const EdgeInsets.fromLTRB(14, 12, 14, 12),
      decoration: BoxDecoration(
        color: theme.colorScheme.surfaceContainerLowest,
        border: Border.all(color: theme.colorScheme.outlineVariant),
        borderRadius: BorderRadius.circular(14),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Text(
                'Money',
                style: theme.textTheme.titleSmall?.copyWith(
                  fontWeight: FontWeight.w800,
                ),
              ),
              const Spacer(),
              const Flexible(child: Tag('From Workiz · read only')),
            ],
          ),
          const SizedBox(height: 10),
          Row(
            children: [
              figure(
                'Past due',
                pastDue == null ? '—' : money.money(pastDue),
                alarm: money.late,
                key: const ValueKey('customer-money-past-due'),
              ),
              const SizedBox(width: 8),
              figure(
                'Due',
                money.money(money.due),
                key: const ValueKey('customer-money-due'),
              ),
              const SizedBox(width: 8),
              figure(
                'Paid',
                money.money(money.paid),
                key: const ValueKey('customer-money-paid'),
              ),
            ],
          ),
          const SizedBox(height: 10),
          Text(
            parts.join(' · '),
            key: const Key('customer-money-line'),
            style: theme.textTheme.bodyMedium,
          ),
          const SizedBox(height: 4),
          Text(
            pastDue == null
                ? 'Past due could not be worked out without today\'s date.'
                : 'Counted from the jobs in the branches you can see.',
            style: theme.textTheme.bodySmall,
          ),
        ],
      ),
    );
  }
}

/// The notes staff leave on this customer's Workiz jobs, one group per
/// branch, each note under the job it was left on (A-1 keeps its notes on
/// jobs, not clients: Customers, 2026-10-07). They are read from EzBizi's copy
/// of each job, so Workiz is not asked; fetched when the tab is opened.
class WorkizNotes extends StatefulWidget {
  const WorkizNotes({
    super.key,
    required this.api,
    required this.customerId,
    required this.formats,
  });

  final ApiClient api;
  final String customerId;
  final DisplayFormats formats;

  @override
  State<WorkizNotes> createState() => _WorkizNotesState();
}

class _WorkizNotesState extends State<WorkizNotes> {
  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _clients = const [];

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    final response = await widget.api.get(
      '/customers/${widget.customerId}/workiz-notes',
    );
    if (!mounted) return;
    setState(() {
      _loading = false;
      if (!response.ok) {
        _error = response.reason;
        return;
      }
      final data = response.data;
      _clients = [
        for (final row in (data is List ? data : const []))
          if (row is Map) Map<String, dynamic>.from(row),
      ];
    });
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    if (_loading) {
      return Padding(
        key: const Key('workiz-notes-loading'),
        padding: const EdgeInsets.symmetric(vertical: 24),
        child: Row(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            const SizedBox.square(
              dimension: 18,
              child: CircularProgressIndicator(strokeWidth: 2),
            ),
            const SizedBox(width: 12),
            Text('Loading the notes…', style: theme.textTheme.bodyMedium),
          ],
        ),
      );
    }

    if (_error != null) {
      return Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          ErrorNote(_error!),
          const SizedBox(height: 8),
          TextButton(onPressed: _load, child: const Text('Try again')),
        ],
      );
    }

    final total = _clients.fold<int>(
      0,
      (n, c) => n + ((c['notes'] as List?)?.length ?? 0),
    );
    final failed = _clients.any((c) => '${c['error'] ?? ''}'.isNotEmpty);

    if (_clients.isEmpty || (total == 0 && !failed)) {
      return Text(
        'Workiz has no notes on this customer.',
        key: const Key('workiz-notes-none'),
        style: theme.textTheme.bodyMedium,
      );
    }

    return Column(
      key: const Key('workiz-notes'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        for (var i = 0; i < _clients.length; i++) ...[
          if (i > 0) const SizedBox(height: 14),
          _branch(context, _clients[i], first: i == 0),
          if ('${_clients[i]['error'] ?? ''}'.isNotEmpty)
            _notesError(context, _clients[i])
          else if (((_clients[i]['notes'] as List?) ?? const []).isEmpty)
            Padding(
              padding: const EdgeInsets.only(top: 4),
              child: Text('No notes here.', style: theme.textTheme.bodySmall),
            )
          else
            for (final (job, notes) in _byJob(
              _clients[i]['notes'] as List,
            )) ...[
              _jobHeading(context, job),
              for (final note in notes) _note(context, note),
            ],
        ],
      ],
    );
  }

  /// Why a branch shows no notes, under its name: the server's sentence (no
  /// Workiz connection, or not on its v2 key), plain, since nothing here can
  /// change it.
  Widget _notesError(BuildContext context, Map<String, dynamic> client) =>
      Padding(
        key: ValueKey('workiz-notes-error-${client['client_id']}'),
        padding: const EdgeInsets.only(top: 4),
        child: Text(
          '${client['error']}',
          style: Theme.of(context).textTheme.bodySmall,
        ),
      );

  /// A branch's notes under the job each was left on, in the order the
  /// server sends them (newest first), so the job with the latest note leads.
  static List<(Map<String, dynamic>, List<Map<String, dynamic>>)> _byJob(
    List raw,
  ) {
    final groups =
        <String, (Map<String, dynamic>, List<Map<String, dynamic>>)>{};
    for (final item in raw) {
      if (item is! Map) continue;
      final note = Map<String, dynamic>.from(item);
      final job = note['job'] is Map
          ? Map<String, dynamic>.from(note['job'] as Map)
          : <String, dynamic>{};
      final key = '${job['id'] ?? job['workiz_number'] ?? ''}';
      groups
          .putIfAbsent(key, () => (job, <Map<String, dynamic>>[]))
          .$2
          .add(note);
    }
    return groups.values.toList();
  }

  /// The job a group of notes was left on: EzBizi's number and Workiz's.
  Widget _jobHeading(BuildContext context, Map<String, dynamic> job) {
    final theme = Theme.of(context);
    final words = [
      if (job['number'] != null) '${job['number']}',
      if (job['workiz_number'] != null) 'Workiz #${job['workiz_number']}',
    ];

    return Padding(
      key: ValueKey('workiz-notes-job-${job['id'] ?? job['workiz_number']}'),
      padding: const EdgeInsets.only(top: 10),
      child: Text(
        words.isEmpty ? 'A Workiz job' : words.join(' · '),
        style: theme.textTheme.labelMedium?.copyWith(
          fontWeight: FontWeight.w700,
        ),
      ),
    );
  }

  String _branchName(Map<String, dynamic> client) {
    final franchise = client['franchise'];
    final name = franchise is Map ? '${franchise['name'] ?? ''}'.trim() : '';
    return name.isEmpty ? 'Workiz client ${client['client_id'] ?? ''}' : name;
  }

  Widget _branch(
    BuildContext context,
    Map<String, dynamic> client, {
    required bool first,
  }) {
    final theme = Theme.of(context);
    return Row(
      children: [
        Expanded(
          child: Text(
            _branchName(client).toUpperCase(),
            style: theme.textTheme.labelSmall?.copyWith(
              fontWeight: FontWeight.w800,
              letterSpacing: 0.6,
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
        ),
        if (first) const Tag('From Workiz · read only'),
      ],
    );
  }

  Widget _note(BuildContext context, Map<String, dynamic> note) {
    final theme = Theme.of(context);
    final who = '${note['author'] ?? ''}'.trim();
    final when = formatStamp(note['created_at'], '', formats: widget.formats);
    return Container(
      margin: const EdgeInsets.only(top: 8),
      padding: const EdgeInsets.only(left: 10),
      decoration: BoxDecoration(
        border: Border(
          left: BorderSide(color: EzBiziTheme.edge(context), width: 3),
        ),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SelectableText(
            '${note['text'] ?? ''}',
            style: theme.textTheme.bodyMedium,
          ),
          if (who.isNotEmpty || when.isNotEmpty)
            Text(
              [if (who.isNotEmpty) who, if (when.isNotEmpty) when].join(' · '),
              style: theme.textTheme.bodySmall,
            ),
        ],
      ),
    );
  }
}
