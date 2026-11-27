import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../core/timestamps.dart';
import '../../widgets/error_note.dart';
import '../../widgets/list_table.dart';
import '../../widgets/share_bar.dart';

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

  /// The category tile picked, or null for every line. Kept across a change
  /// of days only while that category is still there to show.
  String? _picked;

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
    final response = await widget.api.get(
      '/phone/usage?scope_type=${widget.scopeType}&scope_id=${widget.scopeId}'
      '&from=${formatCalendarDay(from)}&to=${formatCalendarDay(to)}',
    );

    if (!mounted) return;

    // Parsed OUTSIDE setState, and guarded. The endless spinner this screen
    // shipped with was a cast throwing in here: the exception escaped before
    // `_loading` was cleared, so the pane hung with nothing to say. Whatever
    // arrives, this screen ends up in a state it can draw.
    List<_Row> rows = const [];
    Map<String, String> totals = const {};
    String? failed;

    if (response.ok) {
      try {
        rows = _Row.listFrom(response.body);
        totals = _Row.totalsFrom(response.body);
      } catch (_) {
        failed = 'EzBizi could not read the cost figures the server sent.';
      }
    }

    setState(() {
      _loading = false;
      _rows = rows;
      _totals = totals;
      _error = response.ok ? failed : response.reason;
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
      return ErrorRetry(_error!, onRetry: _load);
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

        const SizedBox(height: 16),

        if (_rows.isEmpty)
          Text(
            _note != null
                ? ''
                : 'Nothing collected yet. The nightly import fills this in from '
                      'tomorrow; there is nothing to press.',
            style: theme.textTheme.bodySmall,
          )
        else
          ..._summary(theme),
      ],
    );
  }

  /// Tiles for the total and the three biggest costs, then a table of where
  /// it went with each line's share (layout A, Mia, 2026-10-02). The response
  /// is every row in the window, so it is all counted here.
  List<Widget> _summary(ThemeData theme) {
    final lines = _byCategory();
    final whole = lines.fold<int>(0, (sum, line) => sum + line.micros);
    final picked = lines.any((l) => l.label == _picked) ? _picked : null;
    final shown = picked == null
        ? lines
        : lines.where((l) => l.label == picked).toList();
    final small = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );

    double share(int micros) => whole == 0 ? 0 : micros / whole;

    return [
      _MoneyTiles(
        picked: picked,
        onPick: (key) => setState(() => _picked = key),
        tiles: [
          _MoneyTile(
            key: null,
            label: 'Total',
            // The server's own bcmath sum, per currency, when it sent one.
            value: _totals.isEmpty
                ? _price(whole, _unit)
                : _totals.entries
                      .map((e) => _priceText(e.value, e.key))
                      .join(' + '),
            note: 'last $_days days',
          ),
          for (final line in lines.take(3))
            _MoneyTile(
              key: line.label,
              label: line.label,
              value: _price(line.micros, _unit),
              note: '${(share(line.micros) * 100).round()}% of the total',
            ),
        ],
      ),
      const SizedBox(height: 12),
      ListTable(
        columns: const [
          ListColumn('What', flex: 4),
          ListColumn('Detail', flex: 4),
          ListColumn('Cost', flex: 2, end: true),
          ListColumn('Share', flex: 3),
        ],
        rowKey: (i) => ValueKey('cost:${shown[i].label}'),
        rows: [
          for (final line in shown)
            [
              Text(
                line.label,
                overflow: TextOverflow.ellipsis,
                style: theme.textTheme.bodyMedium?.copyWith(
                  fontWeight: FontWeight.w600,
                ),
              ),
              Text(
                line.detail.isEmpty ? '—' : line.detail,
                overflow: TextOverflow.ellipsis,
                style: small,
              ),
              Text(
                _price(line.micros, _unit),
                style: theme.textTheme.bodyMedium?.copyWith(
                  fontFeatures: const [FontFeature.tabularFigures()],
                ),
              ),
              ShareBar(share: share(line.micros)),
            ],
        ],
      ),
    ];
  }

  /// The one currency the rows are in, when they agree. Twilio bills this
  /// account in one, so this is the ordinary case; mixed rows show no symbol
  /// rather than the wrong one.
  String get _unit {
    final units = _rows.map((r) => r.unit).whereType<String>().toSet();
    return units.length == 1 ? units.first : '';
  }

  static String _price(int micros, String unit) =>
      _priceText((micros / 1000000).toStringAsFixed(2), unit);

  static String _priceText(String raw, String unit) {
    final value = double.tryParse(raw);
    final amount = value == null ? raw : value.toStringAsFixed(2);

    return switch (unit.toUpperCase()) {
      'USD' => '\$$amount',
      '' => amount,
      _ => '$amount ${unit.toUpperCase()}',
    };
  }

  /// Summed as strings scaled to whole millionths, because these are the
  /// figures somebody may invoice from and a double would round them. The
  /// detail is what was counted for it: "412 calls · 1840 minutes".
  List<_Line> _byCategory() {
    final out = <String, _Line>{};

    for (final row in _rows) {
      final line = out[row.label] ??= _Line(row.label);
      line.micros += row.priceMicros;
      line.count += row.count;
      line.usage += row.usage;
      line.countUnit ??= row.countUnit;
      line.usageUnit ??= row.usageUnit;
    }

    return out.values.toList()..sort((a, b) => b.micros.compareTo(a.micros));
  }
}

class _Line {
  _Line(this.label);

  final String label;
  int micros = 0;
  int count = 0;
  double usage = 0;
  String? countUnit;
  String? usageUnit;

  String get detail {
    String n(num v) =>
        v == v.roundToDouble() ? v.round().toString() : v.toStringAsFixed(1);

    return [
      if (count > 0 && (countUnit ?? '').isNotEmpty) '${n(count)} $countUnit',
      // Said once when the two are the same thing ("412 calls · 412 calls").
      if (usage > 0 &&
          (usageUnit ?? '').isNotEmpty &&
          (usageUnit != countUnit || usage != count))
        '${n(usage)} $usageUnit',
    ].join(' · ');
  }
}

class _Row {
  const _Row({
    required this.label,
    required this.priceMicros,
    this.count = 0,
    this.usage = 0,
    this.countUnit,
    this.usageUnit,
    this.unit,
  });

  final String label;
  final int count;
  final double usage;
  final String? countUnit;
  final String? usageUnit;

  /// The currency, as Twilio sends it ("USD").
  final String? unit;

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
          count: (row['count'] as num?)?.toInt() ?? 0,
          usage: double.tryParse('${row['usage'] ?? ''}') ?? 0,
          countUnit: row['count_unit'] as String?,
          usageUnit: row['usage_unit'] as String?,
          unit: row['price_unit'] as String?,
        ),
    ];
  }

  static Map<String, String> totalsFrom(Map<String, dynamic> body) {
    // Read without casting. `as Map?` threw here when the server sent `[]` for
    // an empty keyed collection, and because that happened mid-load the pane
    // spun forever instead of reporting anything. The server sends an object
    // now; this stays tolerant because a screen should degrade to "no totals"
    // on a shape it did not expect, never hang.
    final totals = body['totals'];

    if (totals is! Map) return const {};

    return {
      for (final entry in totals.entries)
        entry.key.toString(): entry.value.toString(),
    };
  }
}

/// A money tile. FilterTiles counts whole numbers and draws a dash for none,
/// and a cost is neither, so this is the same tile with an amount in it.
class _MoneyTile {
  const _MoneyTile({
    required this.key,
    required this.label,
    required this.value,
    required this.note,
  });

  final String? key;
  final String label;
  final String value;
  final String note;
}

/// The tiles in a wrapping row, keyed `cost-tile-<key>` the way FilterTiles
/// keys its own. Tapping a picked tile clears it.
class _MoneyTiles extends StatelessWidget {
  const _MoneyTiles({
    required this.tiles,
    required this.picked,
    required this.onPick,
  });

  final List<_MoneyTile> tiles;
  final String? picked;
  final ValueChanged<String?> onPick;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final radius = BorderRadius.circular(EzBiziTheme.controlRadius);

    return LayoutBuilder(
      builder: (context, box) {
        const gap = 10.0;
        final across = (box.maxWidth / 170).floor().clamp(2, tiles.length);
        final width = (box.maxWidth - gap * (across - 1)) / across;

        return Wrap(
          spacing: gap,
          runSpacing: gap,
          children: [
            for (final tile in tiles)
              SizedBox(
                width: width,
                child: Material(
                  key: Key('cost-tile-${tile.key ?? 'all'}'),
                  color: picked == tile.key
                      ? theme.colorScheme.primaryContainer
                      : theme.colorScheme.surface,
                  shape: RoundedRectangleBorder(
                    borderRadius: radius,
                    side: BorderSide(
                      color: picked == tile.key
                          ? theme.colorScheme.primary
                          : EzBiziTheme.edge(context),
                    ),
                  ),
                  child: InkWell(
                    borderRadius: radius,
                    onTap: () => onPick(picked == tile.key ? null : tile.key),
                    child: Padding(
                      padding: const EdgeInsets.all(12),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            tile.label.toUpperCase(),
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: theme.textTheme.labelSmall?.copyWith(
                              letterSpacing: 0.8,
                              fontWeight: FontWeight.w700,
                            ),
                          ),
                          const SizedBox(height: 4),
                          Text(
                            tile.value,
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: theme.textTheme.headlineSmall?.copyWith(
                              fontWeight: FontWeight.w800,
                            ),
                          ),
                          Text(
                            tile.note,
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: theme.textTheme.bodySmall?.copyWith(
                              color: theme.colorScheme.onSurfaceVariant,
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                ),
              ),
          ],
        );
      },
    );
  }
}
