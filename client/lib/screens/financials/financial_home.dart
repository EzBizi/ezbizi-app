import 'package:flutter/material.dart';

import '../../core/theme.dart';
import '../../widgets/status_pill.dart';

/// One card on Financials' home (UI queue 121, Jose picked layout 1 and
/// phone 4): an area of the books or one of the business's own bases.
///
/// Every value is what the server sent. A card with no figure shows "—", one
/// with no source shows no badge, and one with no refresh time says nothing
/// about freshness; nothing here is worked out on the client.
class FinancialCard {
  const FinancialCard({
    required this.id,
    required this.name,
    this.icon,
    this.colourHint,
    this.figure,
    this.source,
    this.refreshedAt,
    this.tables = const [],
  });

  final String id;
  final String name;
  final String? icon;

  /// A palette index (what /financials/home sends, 0–5) or a hue's name
  /// (a base's own colour).
  final Object? colourHint;
  final String? figure;

  /// native, airtable or both; null when the server did not say.
  final String? source;
  final String? refreshedAt;

  /// An area's tables: (id, name, record count or null).
  final List<(String, String, int?)> tables;

  static FinancialCard area(Map a) => FinancialCard(
    id: '${a['key']}',
    name: '${a['name'] ?? ''}',
    icon: a['icon'] as String?,
    colourHint: a['colour_hint'],
    figure: _text(a['figure']),
    source: a['source'] as String?,
    refreshedAt: a['refreshed_at'] as String?,
    tables: [
      for (final t in (a['tables'] as List? ?? const []).cast<Map>())
        ('${t['key']}', '${t['name'] ?? ''}', (t['count'] as num?)?.toInt()),
    ],
  );

  static FinancialCard base(Map b) => FinancialCard(
    id: '${b['id']}',
    name: '${b['name'] ?? ''}',
    colourHint: b['colour_hint'] ?? b['colour'],
    figure: _text(b['figure']),
    source: b['source'] as String?,
    refreshedAt: b['refreshed_at'] as String?,
  );

  static String? _text(Object? v) =>
      v == null || '$v'.trim().isEmpty ? null : '$v';
}

/// The badge's words. Only a source that is Airtable mentions Airtable, so a
/// business without it never reads the word on its home.
String? sourceLabel(String? source) => switch (source) {
  'native' => 'Native',
  'airtable' => 'Airtable mirror',
  'both' => 'Native + Airtable',
  _ => null,
};

Tone _sourceTone(String? source) => switch (source) {
  'native' => Tone.ok,
  'airtable' => Tone.caution,
  _ => Tone.neutral,
};

/// "refreshed 4 min ago", or null when there is no time to read.
String? refreshedLabel(String? iso, {DateTime? now}) {
  final at = iso == null ? null : DateTime.tryParse(iso)?.toLocal();
  if (at == null) return null;
  final minutes = (now ?? DateTime.now()).difference(at).inMinutes;
  if (minutes < 1) return 'refreshed just now';
  if (minutes < 60) return 'refreshed $minutes min ago';
  if (minutes < 60 * 24) return 'refreshed ${minutes ~/ 60} h ago';
  return 'refreshed ${minutes ~/ (60 * 24)} d ago';
}

const _hues = {
  'blue': 0,
  'teal': 1,
  'green': 2,
  'olive': 3,
  'gold': 4,
  'orange': 5,
  'coral': 6,
  'red': 6,
  'brown': 7,
  'rose': 8,
  'pink': 8,
  'slate': 9,
  'grey': 9,
  'gray': 9,
  'sky': 10,
};

/// A hint names a hue of the app's own palette; anything else takes the
/// card's place in its section, so neighbours differ.
ToneColours cardColours(BuildContext context, Object? hint, int index) =>
    PersonColours.hue(context, switch (hint) {
      final int i when i >= 0 => i % 8,
      final String name => _hues[name.toLowerCase()] ?? index % 8,
      _ => index % 8,
    });

/// The Material names /financials/home sends for its areas.
const _icons = <String, IconData>{
  'undo': Icons.undo,
  'savings': Icons.savings_outlined,
  'build': Icons.build_outlined,
  'trending_up': Icons.trending_up,
  'receipt_long': Icons.receipt_long_outlined,
  'payments': Icons.payments_outlined,
  'table_chart': Icons.table_chart_outlined,
};

IconData cardIcon(String? name, {required bool isBase}) =>
    _icons[name?.toLowerCase()] ??
    (isBase ? Icons.table_chart_outlined : Icons.folder_outlined);

/// The home: "Your areas", then "Your bases" ending in + New base. Cards are
/// one size, four across when wide, three in between and two on a phone.
class FinancialHome extends StatefulWidget {
  const FinancialHome({
    super.key,
    required this.areas,
    required this.bases,
    required this.onArea,
    required this.onBase,
    this.onNewBase,
  });

  final List<FinancialCard> areas;
  final List<FinancialCard> bases;
  final ValueChanged<FinancialCard> onArea;
  final ValueChanged<FinancialCard> onBase;

  /// Null when this person may not create a base: no + New base card.
  final VoidCallback? onNewBase;

  /// From this many cards up the home offers a search box.
  static const searchFrom = 8;

  @override
  State<FinancialHome> createState() => _FinancialHomeState();
}

class _FinancialHomeState extends State<FinancialHome> {
  String _query = '';

  bool _match(FinancialCard c) =>
      _query.isEmpty || c.name.toLowerCase().contains(_query.toLowerCase());

  @override
  Widget build(BuildContext context) {
    final searchable =
        widget.areas.length + widget.bases.length >= FinancialHome.searchFrom;
    final areas = widget.areas.where(_match).toList();
    final bases = widget.bases.where(_match).toList();

    return LayoutBuilder(
      builder: (context, box) {
        final phone = box.maxWidth < 700;
        final columns = box.maxWidth >= 1000 ? 4 : (phone ? 2 : 3);
        final height = phone ? 104.0 : 132.0;

        Widget grid(List<Widget> cards) => GridView(
          shrinkWrap: true,
          physics: const NeverScrollableScrollPhysics(),
          gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
            crossAxisCount: columns,
            mainAxisExtent: height,
            crossAxisSpacing: 12,
            mainAxisSpacing: 12,
          ),
          children: cards,
        );

        return ListView(
          padding: EdgeInsets.all(phone ? 12 : 16),
          children: [
            if (searchable) ...[
              Align(
                alignment: Alignment.centerLeft,
                child: SizedBox(
                  width: phone ? double.infinity : 320,
                  child: TextField(
                    key: const ValueKey('financials-home-search'),
                    decoration: const InputDecoration(
                      isDense: true,
                      prefixIcon: Icon(Icons.search),
                      hintText: 'Find an area or base',
                    ),
                    onChanged: (t) => setState(() => _query = t.trim()),
                  ),
                ),
              ),
              const SizedBox(height: 12),
            ],
            if (widget.areas.isNotEmpty) ...[
              const _Heading('Your areas'),
              if (areas.isEmpty)
                const _NoMatch()
              else
                grid([
                  for (final (i, a) in areas.indexed)
                    _Card(
                      key: ValueKey('financials-area-${a.id}'),
                      card: a,
                      index: i,
                      isBase: false,
                      compact: phone,
                      onTap: () => widget.onArea(a),
                    ),
                ]),
              const SizedBox(height: 20),
            ],
            const _Heading('Your bases'),
            if (bases.isEmpty && widget.onNewBase == null)
              Text(
                _query.isEmpty ? 'No bases yet.' : 'Nothing matches.',
                style: TextStyle(
                  color: Theme.of(context).colorScheme.onSurfaceVariant,
                ),
              )
            else
              grid([
                for (final (i, b) in bases.indexed)
                  _Card(
                    key: ValueKey('financials-base-${b.id}'),
                    card: b,
                    index: i + widget.areas.length,
                    isBase: true,
                    compact: phone,
                    onTap: () => widget.onBase(b),
                  ),
                if (widget.onNewBase case final onNew?)
                  _NewBaseCard(onTap: onNew),
              ]),
          ],
        );
      },
    );
  }
}

class _Heading extends StatelessWidget {
  const _Heading(this.text);

  final String text;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.only(bottom: 8),
    child: Text(text, style: Theme.of(context).textTheme.titleMedium),
  );
}

class _NoMatch extends StatelessWidget {
  const _NoMatch();

  @override
  Widget build(BuildContext context) => Text(
    'Nothing matches.',
    style: TextStyle(color: Theme.of(context).colorScheme.onSurfaceVariant),
  );
}

class _Card extends StatelessWidget {
  const _Card({
    super.key,
    required this.card,
    required this.index,
    required this.isBase,
    required this.compact,
    required this.onTap,
  });

  final FinancialCard card;
  final int index;
  final bool isBase;
  final bool compact;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final colours = cardColours(context, card.colourHint, index);
    final badge = sourceLabel(card.source);
    final refreshed = refreshedLabel(card.refreshedAt);

    return Card(
      margin: EdgeInsets.zero,
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: onTap,
        child: Padding(
          padding: EdgeInsets.all(compact ? 10 : 14),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Container(
                    width: compact ? 28 : 32,
                    height: compact ? 28 : 32,
                    decoration: BoxDecoration(
                      color: colours.fill,
                      borderRadius: BorderRadius.circular(
                        EzBiziTheme.controlRadius,
                      ),
                    ),
                    child: Icon(
                      cardIcon(card.icon, isBase: isBase),
                      size: compact ? 16 : 18,
                      color: colours.ink,
                    ),
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Text(
                      card.name,
                      maxLines: compact ? 1 : 2,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(fontWeight: FontWeight.w700),
                    ),
                  ),
                ],
              ),
              const Spacer(),
              Text(
                card.figure ?? '—',
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: const TextStyle(
                  fontWeight: FontWeight.w800,
                  fontSize: 14,
                ),
              ),
              if (!compact && (badge != null || refreshed != null)) ...[
                const SizedBox(height: 6),
                Row(
                  children: [
                    if (badge != null)
                      Flexible(
                        child: StatusPill(
                          badge,
                          tone: _sourceTone(card.source),
                        ),
                      ),
                    if (badge != null && refreshed != null)
                      const SizedBox(width: 8),
                    if (refreshed != null)
                      Flexible(
                        child: Text(
                          refreshed,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: TextStyle(
                            fontSize: 11.5,
                            color: scheme.onSurfaceVariant,
                          ),
                        ),
                      ),
                  ],
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

class _NewBaseCard extends StatelessWidget {
  const _NewBaseCard({required this.onTap});

  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return CustomPaint(
      key: const ValueKey('financials-new-base-card'),
      painter: _DashedBorder(scheme.outline),
      child: Material(
        type: MaterialType.transparency,
        child: InkWell(
          borderRadius: BorderRadius.circular(14),
          onTap: onTap,
          child: Center(
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                Icon(Icons.add, color: scheme.primary),
                const SizedBox(width: 6),
                Text(
                  'New base',
                  style: TextStyle(
                    color: scheme.primary,
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _DashedBorder extends CustomPainter {
  const _DashedBorder(this.colour);

  final Color colour;

  @override
  void paint(Canvas canvas, Size size) {
    final paint = Paint()
      ..color = colour
      ..style = PaintingStyle.stroke
      ..strokeWidth = 1.2;
    final outline = Path()
      ..addRRect(
        RRect.fromRectAndRadius(
          Offset.zero & size,
          const Radius.circular(14),
        ).deflate(0.6),
      );
    for (final metric in outline.computeMetrics()) {
      for (var d = 0.0; d < metric.length; d += 10) {
        canvas.drawPath(metric.extractPath(d, d + 6), paint);
      }
    }
  }

  @override
  bool shouldRepaint(_DashedBorder old) => old.colour != colour;
}
