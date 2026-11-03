import '../../widgets/list_search.dart';
import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/phone_numbers.dart';
import '../../core/theme.dart';
import '../../widgets/status_pill.dart';

/// Phone check (design 154 #10): one row per listing, its Business Profile
/// number beside the branch's EzBizi lines and the number the branch's
/// website shows ("policy"), and one verdict chip. Chips All · Mismatch ·
/// Match · Missing · Unknown, and a search.
class PhoneCheckView extends StatefulWidget {
  const PhoneCheckView({
    super.key,
    required this.api,
    required this.labelOf,
    this.onBack,
  });

  final ApiClient api;

  /// "Company · Branch" for a listing id, or null to use the server's name.
  final String? Function(String id) labelOf;
  final VoidCallback? onBack;

  @override
  State<PhoneCheckView> createState() => _PhoneCheckViewState();
}

/// "just now", "5 min ago", "3 h ago", "2 d ago".
String checkedAgo(DateTime at, {DateTime? now}) {
  final ago = (now ?? DateTime.now()).difference(at.toLocal());
  if (ago.isNegative || ago.inMinutes < 1) return 'just now';
  if (ago.inMinutes < 60) return '${ago.inMinutes} min ago';
  if (ago.inHours < 24) return '${ago.inHours} h ago';
  return '${ago.inDays} d ago';
}

const _statusWords = {
  'match': ('Match', Tone.ok),
  'mismatch': ('Mismatch', Tone.bad),
  'missing': ('No number on Google', Tone.caution),
  'unknown': ('Nothing to compare', Tone.neutral),
};

class _PhoneCheckViewState extends State<PhoneCheckView> {
  List<Map<String, dynamic>>? _rows;
  String? _error;
  String? _status;
  String _q = '';
  DateTime? _checkedAt;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final r = await widget.api.get('/business-profile/phone-check');
    if (!mounted) return;
    setState(() {
      _error = r.ok ? null : r.message;
      _checkedAt = DateTime.tryParse('${r.body['checked_at'] ?? ''}');
      _rows = r.ok
          ? [
              for (final m in (r.data as List? ?? const []).whereType<Map>())
                Map<String, dynamic>.from(m),
            ]
          : null;
    });
  }

  String _label(Map<String, dynamic> row) =>
      widget.labelOf('${row['id']}') ?? '${row['name'] ?? ''}';

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final rows = _rows;
    final q = _q.trim().toLowerCase();
    final shown = [
      for (final r in rows ?? const <Map<String, dynamic>>[])
        if ((_status == null || r['status'] == _status) &&
            (q.isEmpty ||
                _label(r).toLowerCase().contains(q) ||
                '${r['branch'] ?? ''}'.toLowerCase().contains(q) ||
                '${r['listing_phone'] ?? ''}'.contains(q)))
          r,
    ];
    int count(String? s) =>
        (rows ?? const []).where((r) => s == null || r['status'] == s).length;

    return Column(
      key: const ValueKey('cx-phone-check'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(8, 8, 16, 0),
          child: Row(
            children: [
              if (widget.onBack != null)
                IconButton(
                  key: const ValueKey('cx-phone-check-back'),
                  tooltip: 'Back to listings',
                  icon: const Icon(Icons.arrow_back),
                  onPressed: widget.onBack,
                ),
              Expanded(
                child: Text('Phone check', style: theme.textTheme.titleMedium),
              ),
              if (_checkedAt case final at?)
                Text(
                  'Checked ${checkedAgo(at)}',
                  key: const ValueKey('cx-phone-checked'),
                  style: theme.textTheme.bodySmall,
                ),
            ],
          ),
        ),
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 8, 16, 8),
          child: Wrap(
            spacing: 6,
            runSpacing: 6,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              ChoiceChip(
                key: const ValueKey('cx-phone-all'),
                label: Text('All · ${count(null)}'),
                selected: _status == null,
                onSelected: (_) => setState(() => _status = null),
              ),
              for (final s in _statusWords.keys)
                if (count(s) > 0 || s == 'mismatch' || s == 'match')
                  ChoiceChip(
                    key: ValueKey('cx-phone-$s'),
                    label: Text('${_statusWords[s]!.$1} · ${count(s)}'),
                    selected: _status == s,
                    onSelected: (_) =>
                        setState(() => _status = _status == s ? null : s),
                  ),
              SizedBox(
                width: 240,
                child: ListSearch(
                  key: const ValueKey('cx-phone-search'),
                  hint: 'Search listings',
                  onChanged: (v) => setState(() => _q = v),
                ),
              ),
            ],
          ),
        ),
        const Divider(height: 1),
        Expanded(
          child: _error != null
              ? Center(child: Text(_error!, style: theme.textTheme.bodyMedium))
              : rows == null
              ? const Center(child: CircularProgressIndicator())
              : shown.isEmpty
              ? Center(
                  child: Text(
                    rows.isEmpty ? 'No listings to check.' : 'Nothing matches.',
                    style: theme.textTheme.bodySmall,
                  ),
                )
              : ListView.separated(
                  padding: const EdgeInsets.symmetric(vertical: 4),
                  itemCount: shown.length,
                  separatorBuilder: (_, _) =>
                      Divider(height: 1, color: EzBiziTheme.edge(context)),
                  itemBuilder: (context, i) => _row(theme, shown[i]),
                ),
        ),
      ],
    );
  }

  Widget _row(ThemeData theme, Map<String, dynamic> r) {
    final (word, tone) =
        _statusWords[r['status']] ?? ('${r['status']}', Tone.neutral);
    final lines = [
      for (final l in (r['ezbizi_lines'] as List? ?? const []))
        formatPhoneNumber('$l'),
    ];
    String phone(Object? p) =>
        p == null || '$p'.isEmpty ? 'Not set' : formatPhoneNumber('$p');

    Widget number(String label, String value) => SizedBox(
      width: 170,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(label.toUpperCase(), style: theme.textTheme.labelSmall),
          Text(value, style: theme.textTheme.bodyMedium),
        ],
      ),
    );

    return Padding(
      key: ValueKey('cx-phone-row-${r['id']}'),
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  _label(r),
                  style: theme.textTheme.titleSmall,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                ),
              ),
              const SizedBox(width: 8),
              StatusPill(word, tone: tone),
            ],
          ),
          const SizedBox(height: 6),
          Wrap(
            spacing: 12,
            runSpacing: 6,
            children: [
              number('Business Profile', phone(r['listing_phone'])),
              number(
                'EzBizi lines',
                lines.isEmpty ? 'Not set' : lines.join(', '),
              ),
              number('Policy (website)', phone(r['website_phone'])),
            ],
          ),
        ],
      ),
    );
  }
}
