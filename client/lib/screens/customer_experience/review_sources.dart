import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/theme.dart';
import '../../widgets/status_pill.dart';

/// Where reviews were written (design 154 #13): Google, eKomi, Thumbtack,
/// each with its connection, count and average, from GET /reviews/sources.
/// The funnel below it is the Review links report this sits in.
class ReviewSources extends StatefulWidget {
  const ReviewSources({super.key, required this.api});

  final ApiClient api;

  @override
  State<ReviewSources> createState() => _ReviewSourcesState();
}

const _names = {'google': 'Google', 'ekomi': 'eKomi', 'thumbtack': 'Thumbtack'};

class _ReviewSourcesState extends State<ReviewSources> {
  List<Map<String, dynamic>>? _rows;
  String? _error;
  String _q = '';

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final r = await widget.api.get('/reviews/sources');
    if (!mounted) return;
    setState(() {
      _error = r.ok ? null : r.message;
      _rows = r.ok
          ? [
              for (final m in (r.data as List? ?? const []).whereType<Map>())
                Map<String, dynamic>.from(m),
            ]
          : null;
    });
  }

  static String _name(Map row) =>
      _names[row['source']] ?? '${row['source'] ?? ''}';

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final q = _q.trim().toLowerCase();
    final rows = [
      for (final r in _rows ?? const <Map<String, dynamic>>[])
        if (q.isEmpty || _name(r).toLowerCase().contains(q)) r,
    ];

    return Column(
      key: const ValueKey('cx-sources'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Wrap(
          spacing: 12,
          runSpacing: 8,
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            Text('Sources', style: theme.textTheme.titleSmall),
            SizedBox(
              width: 220,
              child: TextField(
                key: const ValueKey('cx-sources-search'),
                decoration: const InputDecoration(
                  prefixIcon: Icon(Icons.search, size: 18),
                  hintText: 'Search sources',
                  isDense: true,
                ),
                onChanged: (v) => setState(() => _q = v),
              ),
            ),
          ],
        ),
        const SizedBox(height: 8),
        if (_error != null)
          Text(_error!, style: theme.textTheme.bodySmall)
        else if (_rows == null)
          const LinearProgressIndicator()
        else
          for (final r in rows) _row(theme, r),
      ],
    );
  }

  Widget _row(ThemeData theme, Map<String, dynamic> r) {
    final connected = r['connected'] == true;
    final average = (r['average'] as num?)?.toDouble();
    return Container(
      key: ValueKey('cx-source-${r['source']}'),
      padding: const EdgeInsets.symmetric(vertical: 8),
      decoration: BoxDecoration(
        border: Border(bottom: BorderSide(color: EzBiziTheme.edge(context))),
      ),
      child: Row(
        children: [
          Expanded(
            flex: 3,
            child: Text(_name(r), style: theme.textTheme.bodyMedium),
          ),
          Expanded(
            flex: 3,
            child: Align(
              alignment: Alignment.centerLeft,
              child: StatusPill(
                connected ? 'Connected' : 'Not connected',
                tone: connected ? Tone.ok : Tone.neutral,
              ),
            ),
          ),
          Expanded(
            flex: 2,
            child: Text(
              connected ? '${r['count'] ?? 0} reviews' : '—',
              style: theme.textTheme.bodySmall,
            ),
          ),
          Expanded(
            flex: 2,
            child: Text(
              average == null ? '—' : '${average.toStringAsFixed(1)} ★',
              textAlign: TextAlign.right,
              style: theme.textTheme.bodySmall,
            ),
          ),
        ],
      ),
    );
  }
}
