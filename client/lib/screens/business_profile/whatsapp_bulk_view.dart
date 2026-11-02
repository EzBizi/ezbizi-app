import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/theme.dart';
import '../../widgets/side_panel.dart';
import '../../widgets/status_pill.dart';

/// WhatsApp links in bulk (UI queue 163: design option 1 on a desktop, 4 on a
/// phone, `docs/previews/gbp-whatsapp-links-layouts.html`).
///
/// Pick, preview, apply, the Hours in bulk pattern. The list is read from
/// Google live (`GET /business-profile/whatsapp-links`), so it can take
/// seconds; nothing is written until the side panel's "Set on N listings"
/// posts the ticked ids (`/business-profile/whatsapp-links/apply`).
///
/// Drawn in the tab's place with a back control, like Phone check: the
/// containment rule, no pushed screen.
class WhatsAppBulkView extends StatefulWidget {
  const WhatsAppBulkView({
    super.key,
    required this.api,
    this.onBack,
    this.only,
  });

  final ApiClient api;
  final VoidCallback? onBack;

  /// One listing's id: shows and ticks just that listing (one-by-one).
  final String? only;

  @override
  State<WhatsAppBulkView> createState() => _WhatsAppBulkViewState();
}

/// The four states, in chip order, with their words and tones.
const _states = <String, (String, Tone)>{
  'missing': ('Missing', Tone.caution),
  'different': ('Different', Tone.caution),
  'same': ('Same', Tone.ok),
  'unreachable': ("Can't reach", Tone.neutral),
};

class _WhatsAppBulkViewState extends State<WhatsAppBulkView> {
  List<Map<String, dynamic>>? _rows;
  String? _proposed;

  /// The organization's WhatsApp numbers as {number, link}, oldest first.
  List<Map<String, dynamic>> _numbers = const [];

  /// The link being written; starts as the server's proposed one.
  String? _link;
  String? _error;
  String _q = '';
  bool _busy = false;
  final Set<String> _picked = {};

  /// After an apply: id → null for set, or the failure message.
  final Map<String, String?> _results = {};

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _rows = null;
      _error = null;
    });
    final response = await widget.api.get('/business-profile/whatsapp-links');
    if (!mounted) return;
    setState(() {
      if (!response.ok) {
        _error = response.message;
        _rows = const [];
        return;
      }
      _proposed = response.body['proposed'] as String?;
      _link ??= _proposed;
      _numbers = (response.body['numbers'] as List? ?? const [])
          .whereType<Map<String, dynamic>>()
          .toList();
      _rows = (response.body['data'] as List? ?? const [])
          .whereType<Map<String, dynamic>>()
          .where((r) => widget.only == null || _id(r) == widget.only)
          .toList();
      if (widget.only != null) {
        _picked
          ..clear()
          ..addAll([
            for (final r in _rows!)
              if (_tickable(r)) _id(r),
          ]);
      }
    });
  }

  String _id(Map<String, dynamic> r) => '${r['id']}';

  /// The server's state compares against proposed; recomputed here against
  /// the chosen link. Only "unreachable" is taken as sent.
  String _state(Map<String, dynamic> r) {
    if (r['state'] == 'unreachable') return 'unreachable';
    final current = r['current'] as String?;
    if (current == null || current.isEmpty) return 'missing';
    return current == _link ? 'same' : 'different';
  }

  bool _tickable(Map<String, dynamic> r) =>
      _link != null && (_state(r) == 'missing' || _state(r) == 'different');

  List<Map<String, dynamic>> get _shown {
    final q = _q.trim().toLowerCase();
    return [
      for (final r in _rows ?? const <Map<String, dynamic>>[])
        if (q.isEmpty ||
            '${r['name'] ?? ''}'.toLowerCase().contains(q) ||
            '${r['branch'] ?? ''}'.toLowerCase().contains(q))
          r,
    ];
  }

  List<Map<String, dynamic>> get _selected => [
    for (final r in _rows ?? const <Map<String, dynamic>>[])
      if (_picked.contains(_id(r))) r,
  ];

  int _count(String state) =>
      (_rows ?? const []).where((r) => _state(r) == state).length;

  /// A chip selects exactly the rows in that state (that can be ticked).
  void _selectState(String state) => setState(() {
    _picked
      ..clear()
      ..addAll([
        for (final r in _rows ?? const <Map<String, dynamic>>[])
          if (_state(r) == state && _tickable(r)) _id(r),
      ]);
  });

  void _toggle(Map<String, dynamic> r) => setState(() {
    final id = _id(r);
    if (!_picked.remove(id)) _picked.add(id);
  });

  Future<void> _apply(List<String> ids) async {
    setState(() {
      _busy = true;
      _error = null;
    });
    final response = await widget.api.post(
      '/business-profile/whatsapp-links/apply',
      {'targets': ids, 'link': ?_link},
    );
    if (!mounted) return;
    setState(() {
      _busy = false;
      if (!response.ok) {
        _error = response.message;
        return;
      }
      for (final r in (response.body['data'] as List? ?? const [])) {
        if (r is! Map) continue;
        _results['${r['id']}'] = r['ok'] == true
            ? null
            : (r['message'] as String? ?? 'Not set');
      }
      _picked.clear();
    });
  }

  List<String> get _failed => [
    for (final e in _results.entries)
      if (e.value != null) e.key,
  ];

  Future<void> _preview() async {
    final picked = _selected;
    if (picked.isEmpty) return;
    final replacing = picked.where((r) => _state(r) == 'different').length;
    final go = await showSidePanel<bool>(
      context,
      builder: (context) {
        final theme = Theme.of(context);
        return SidePanel(
          key: const ValueKey('wa-bulk-preview'),
          title: 'Set WhatsApp links',
          subtitle: '${picked.length} listing${picked.length == 1 ? '' : 's'}',
          actions: [
            FilledButton(
              key: const ValueKey('wa-bulk-apply'),
              onPressed: _link == null
                  ? null
                  : () => Navigator.of(context).pop(true),
              child: Text(
                'Set on ${picked.length} listing${picked.length == 1 ? '' : 's'}',
              ),
            ),
          ],
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              if (replacing > 0)
                Padding(
                  padding: const EdgeInsets.only(bottom: 12),
                  child: Text(
                    '$replacing listing${replacing == 1 ? ' already has' : 's already have'} '
                    'another WhatsApp link; it will be replaced.',
                    key: const ValueKey('wa-bulk-replace-warning'),
                    style: TextStyle(
                      color: ToneColours.of(context, Tone.caution).ink,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                ),
              for (final r in picked)
                Padding(
                  padding: const EdgeInsets.only(bottom: 10),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(_label(r), style: theme.textTheme.titleSmall),
                      Text(
                        '${r['current'] ?? '—'} → ${_link ?? '—'}',
                        style: theme.textTheme.bodySmall,
                      ),
                    ],
                  ),
                ),
              const SizedBox(height: 8),
              Text(
                'Google can take a few minutes to show the button.',
                style: theme.textTheme.bodySmall,
              ),
            ],
          ),
        );
      },
    );
    if (go == true) await _apply([for (final r in picked) _id(r)]);
  }

  String _label(Map<String, dynamic> r) {
    final name = '${r['name'] ?? ''}';
    final branch = r['branch'] as String?;
    return branch == null || branch.isEmpty ? name : '$name · $branch';
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final rows = _rows;
    final shown = _shown;

    return LayoutBuilder(
      builder: (context, box) {
        final cards = box.maxWidth < 760;
        return Column(
          key: const ValueKey('wa-bulk'),
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(8, 8, 16, 0),
              child: Row(
                children: [
                  if (widget.onBack != null)
                    IconButton(
                      key: const ValueKey('wa-bulk-back'),
                      tooltip: 'Back to listings',
                      icon: const Icon(Icons.arrow_back),
                      onPressed: widget.onBack,
                    ),
                  Expanded(
                    child: Text(
                      widget.only == null
                          ? 'WhatsApp links in bulk'
                          : 'WhatsApp link',
                      style: theme.textTheme.titleMedium,
                    ),
                  ),
                  if (_failed.isNotEmpty)
                    TextButton.icon(
                      key: const ValueKey('wa-bulk-retry'),
                      icon: const Icon(Icons.refresh, size: 16),
                      label: const Text('Retry failed'),
                      onPressed: _busy ? null : () => _apply(_failed),
                    ),
                  if (!cards) ...[
                    const SizedBox(width: 8),
                    FilledButton(
                      key: const ValueKey('wa-bulk-review'),
                      onPressed: _picked.isEmpty || _busy ? null : _preview,
                      child: Text('Review ${_picked.length} listings'),
                    ),
                  ],
                ],
              ),
            ),
            if (rows != null && _link == null && _error == null)
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 8, 16, 0),
                child: Text(
                  'Connect a WhatsApp number first.',
                  key: const ValueKey('wa-bulk-no-number'),
                  style: TextStyle(color: theme.colorScheme.error),
                ),
              ),
            if (_error != null)
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 8, 16, 0),
                child: Text(
                  _error!,
                  key: const ValueKey('wa-bulk-error'),
                  style: TextStyle(color: theme.colorScheme.error),
                ),
              ),
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 8, 16, 8),
              child: Wrap(
                spacing: 6,
                runSpacing: 6,
                crossAxisAlignment: WrapCrossAlignment.center,
                children: [
                  if (_numbers.length > 1)
                    DropdownButton<String>(
                      key: const ValueKey('wa-bulk-number'),
                      value: _link,
                      isDense: true,
                      onChanged: _busy
                          ? null
                          : (v) => setState(() {
                              _link = v;
                              _picked.removeWhere(
                                (id) => !_tickable(
                                  _rows!.firstWhere((r) => _id(r) == id),
                                ),
                              );
                            }),
                      items: [
                        for (final n in _numbers)
                          DropdownMenuItem(
                            value: '${n['link']}',
                            child: Text('Number ${n['number']}'),
                          ),
                      ],
                    ),
                  SizedBox(
                    width: cards ? double.infinity : 260,
                    child: TextField(
                      key: const ValueKey('wa-bulk-search'),
                      decoration: const InputDecoration(
                        prefixIcon: Icon(Icons.search, size: 18),
                        hintText: 'Listing, company or branch',
                        isDense: true,
                      ),
                      onChanged: (v) => setState(() => _q = v),
                    ),
                  ),
                  for (final s in _states.keys)
                    ActionChip(
                      key: ValueKey('wa-bulk-chip-$s'),
                      label: Text('${_states[s]!.$1} · ${_count(s)}'),
                      onPressed: () => _selectState(s),
                    ),
                ],
              ),
            ),
            const Divider(height: 1),
            Expanded(
              child: rows == null
                  ? const Center(
                      key: ValueKey('wa-bulk-loading'),
                      child: Column(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          CircularProgressIndicator(),
                          SizedBox(height: 8),
                          Text('Reading your listings from Google…'),
                        ],
                      ),
                    )
                  : shown.isEmpty
                  ? Center(
                      child: Text(
                        rows.isEmpty ? 'No listings.' : 'Nothing matches.',
                        style: theme.textTheme.bodySmall,
                      ),
                    )
                  : ListView.separated(
                      padding: const EdgeInsets.symmetric(vertical: 4),
                      itemCount: shown.length,
                      separatorBuilder: (_, _) =>
                          Divider(height: 1, color: EzBiziTheme.edge(context)),
                      itemBuilder: (context, i) => cards
                          ? _card(theme, shown[i])
                          : _tableRow(theme, shown[i]),
                    ),
            ),
            if (cards)
              Padding(
                padding: const EdgeInsets.all(12),
                child: FilledButton(
                  key: const ValueKey('wa-bulk-review'),
                  onPressed: _picked.isEmpty || _busy ? null : _preview,
                  child: Text('Review ${_picked.length} listings'),
                ),
              ),
          ],
        );
      },
    );
  }

  Widget _tick(Map<String, dynamic> r) {
    final tickable = _tickable(r);
    final box = Checkbox(
      key: ValueKey('wa-bulk-tick-${_id(r)}'),
      value: _picked.contains(_id(r)),
      onChanged: tickable ? (_) => _toggle(r) : null,
    );
    if (r['state'] == 'unreachable') {
      return Tooltip(message: "Google account isn't connected", child: box);
    }
    return box;
  }

  Widget _stateChip(Map<String, dynamic> r) {
    final state = _state(r);
    final (word, tone) = _states[state] ?? (state, Tone.neutral);
    return StatusPill(
      state == 'different' ? 'Different · Replace' : word,
      tone: state == 'different' ? Tone.caution : tone,
    );
  }

  String _willBe(Map<String, dynamic> r) => switch (_state(r)) {
    'same' => 'no change',
    'unreachable' => '—',
    _ => _link ?? '—',
  };

  Widget? _result(ThemeData theme, Map<String, dynamic> r) {
    final id = _id(r);
    if (!_results.containsKey(id)) return null;
    final message = _results[id];
    return Text(
      message == null ? '✓ Set' : '✗ $message',
      key: ValueKey('wa-bulk-result-$id'),
      style: TextStyle(
        color: message == null
            ? ToneColours.of(context, Tone.ok).ink
            : theme.colorScheme.error,
        fontWeight: FontWeight.w600,
      ),
    );
  }

  Widget _tableRow(ThemeData theme, Map<String, dynamic> r) {
    Widget cell(String text, {int flex = 2}) => Expanded(
      flex: flex,
      child: Text(
        text,
        style: theme.textTheme.bodyMedium,
        maxLines: 2,
        overflow: TextOverflow.ellipsis,
      ),
    );
    return Padding(
      key: ValueKey('wa-bulk-row-${_id(r)}'),
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 6),
      child: Row(
        children: [
          _tick(r),
          cell('${r['name'] ?? ''}', flex: 3),
          cell('${r['branch'] ?? '—'}'),
          cell('${r['current'] ?? '—'}'),
          cell(_willBe(r)),
          Expanded(
            flex: 2,
            child: Align(
              alignment: Alignment.centerLeft,
              child: _result(theme, r) ?? _stateChip(r),
            ),
          ),
        ],
      ),
    );
  }

  Widget _card(ThemeData theme, Map<String, dynamic> r) {
    return Padding(
      key: ValueKey('wa-bulk-row-${_id(r)}'),
      padding: const EdgeInsets.fromLTRB(4, 8, 16, 8),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _tick(r),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(_label(r), style: theme.textTheme.titleSmall),
                const SizedBox(height: 2),
                Text(
                  'Now: ${r['current'] ?? '—'}',
                  style: theme.textTheme.bodySmall,
                ),
                Text(
                  'Will be: ${_willBe(r)}',
                  style: theme.textTheme.bodySmall,
                ),
                const SizedBox(height: 4),
                _result(theme, r) ?? _stateChip(r),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
