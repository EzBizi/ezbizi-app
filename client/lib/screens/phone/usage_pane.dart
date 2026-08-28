import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../widgets/error_note.dart';

/// Phone › Settings › Cost — what this company's phone service costs.
///
/// ── Why this exists at all ───────────────────────────────────────
///
/// EzBizi pays Twilio for every tenant's traffic out of one bill. Nobody could
/// say what any one company spent, and Twilio prunes usage records — so the
/// answer was not merely unknown, it was being permanently lost every night.
///
/// Invoicing onward is somebody else's decision. This screen exists so that
/// decision can be made from data rather than from a guess.
///
/// ── Why it shows categories and not one number ───────────────────
///
/// "Forty dollars" prompts no action. "Thirty of it is recordings you keep for
/// a year" does. The nightly import keeps Twilio's own categories precisely so
/// this screen can say where the money went.
class UsagePane extends StatefulWidget {
  const UsagePane({
    super.key,
    required this.api,
    required this.session,
    required this.scopeType,
    required this.scopeId,
  });

  final ApiClient api;
  final Session session;
  final String scopeType;
  final String scopeId;

  @override
  State<UsagePane> createState() => _UsagePaneState();
}

class _UsagePaneState extends State<UsagePane> {
  bool _loading = true;
  String? _error;
  String? _note;

  List<_Row> _rows = const [];
  Map<String, String> _totals = const {};
  int _days = 30;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void didUpdateWidget(UsagePane old) {
    super.didUpdateWidget(old);
    if (old.scopeId != widget.scopeId) _load();
  }

  bool get _canSee => widget.session.me?.can('phone.edit') ?? false;

  Future<void> _load() async {
    if (!_canSee) {
      setState(() => _loading = false);
      return;
    }

    setState(() {
      _loading = _rows.isEmpty;
      _error = null;
    });

    final to = DateTime.now();
    final from = to.subtract(Duration(days: _days));
    String ymd(DateTime d) =>
        '${d.year.toString().padLeft(4, '0')}-'
        '${d.month.toString().padLeft(2, '0')}-'
        '${d.day.toString().padLeft(2, '0')}';

    final response = await widget.api.get(
      '/phone/usage?scope_type=${widget.scopeType}&scope_id=${widget.scopeId}'
      '&from=${ymd(from)}&to=${ymd(to)}',
    );

    if (!mounted) return;

    setState(() {
      _loading = false;
      _rows = response.ok ? _Row.listFrom(response.body) : const [];
      _totals = response.ok ? _Row.totalsFrom(response.body) : const {};
      _error = response.ok ? null : response.reason;
      _note = response.ok ? response.body['message'] as String? : null;
    });
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    // Said rather than drawn empty: an empty cost screen and a cost screen you
    // are not allowed to see look identical, and mean opposite things.
    if (!_canSee) {
      return Padding(
        padding: const EdgeInsets.all(24),
        child: Text(
          'You can see what the phone lines do, but not what they cost. '
          'That needs permission to change phone settings.',
          style: theme.textTheme.bodyMedium,
        ),
      );
    }

    if (_loading) return const Center(child: CircularProgressIndicator());

    if (_error != null) {
      return Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisSize: MainAxisSize.min,
          children: [
            ErrorNote(_error!),
            const SizedBox(height: 12),
            TextButton(onPressed: _load, child: const Text('Try again')),
          ],
        ),
      );
    }

    return ListView(
      padding: const EdgeInsets.all(24),
      children: [
        Text('Cost', style: theme.textTheme.titleMedium),
        const SizedBox(height: 4),
        Text(
          'What Twilio charged for this phone account, by day and by kind. '
          'Collected each night.',
          style: theme.textTheme.bodySmall,
        ),

        const SizedBox(height: 16),
        Wrap(
          spacing: 8,
          children: [
            for (final days in [7, 30, 90])
              ChoiceChip(
                label: Text('$days days'),
                selected: _days == days,
                onSelected: (_) {
                  setState(() => _days = days);
                  _load();
                },
              ),
          ],
        ),

        if (_note != null) ...[
          const SizedBox(height: 16),
          Text(_note!, style: theme.textTheme.bodySmall),
        ],

        if (_totals.isNotEmpty) ...[
          const SizedBox(height: 16),
          for (final entry in _totals.entries)
            Text(
              '${_money(entry.value)} ${entry.key}',
              style: theme.textTheme.headlineSmall,
            ),
          const SizedBox(height: 4),
          Text('over the last $_days days', style: theme.textTheme.bodySmall),
        ],

        const SizedBox(height: 20),

        if (_rows.isEmpty)
          Text(
            _note != null
                ? ''
                : 'Nothing collected yet. The nightly import fills this in from '
                      'tomorrow; there is nothing to press.',
            style: theme.textTheme.bodySmall,
          )
        else ...[
          Text('Where it went', style: theme.textTheme.labelLarge),
          const SizedBox(height: 8),
          for (final entry in _byCategory().entries)
            ListTile(
              key: ValueKey('cost:${entry.key}'),
              dense: true,
              contentPadding: EdgeInsets.zero,
              title: Text(entry.key),
              trailing: Text(
                _money(entry.value),
                style: theme.textTheme.bodyMedium,
              ),
            ),
        ],
      ],
    );
  }

  /// Summed as strings scaled to whole millionths, because these are the
  /// figures somebody may invoice from and a double would round them.
  Map<String, String> _byCategory() {
    final out = <String, int>{};

    for (final row in _rows) {
      out[row.label] = (out[row.label] ?? 0) + row.priceMicros;
    }

    final sorted = out.entries.toList()
      ..sort((a, b) => b.value.compareTo(a.value));

    return {
      for (final e in sorted) e.key: (e.value / 1000000).toStringAsFixed(2),
    };
  }

  String _money(String raw) {
    final value = double.tryParse(raw);
    return value == null ? raw : value.toStringAsFixed(2);
  }
}

class _Row {
  const _Row({required this.label, required this.priceMicros});

  final String label;

  /// Price in millionths, parsed from the decimal string the server sends.
  final int priceMicros;

  static int _micros(String raw) {
    final value = double.tryParse(raw) ?? 0;
    return (value * 1000000).round();
  }

  static List<_Row> listFrom(Map<String, dynamic> body) {
    final rows = (body['data'] as List?) ?? const [];

    return [
      for (final row in rows.cast<Map<String, dynamic>>())
        _Row(
          label: ((row['description'] as String?)?.trim().isNotEmpty ?? false)
              ? row['description'] as String
              : (row['category'] ?? 'Other') as String,
          priceMicros: _micros((row['price'] ?? '0').toString()),
        ),
    ];
  }

  static Map<String, String> totalsFrom(Map<String, dynamic> body) {
    final totals = (body['totals'] as Map?) ?? const {};

    return {
      for (final entry in totals.entries)
        entry.key.toString(): entry.value.toString(),
    };
  }
}
