import '../../widgets/list_search.dart';
import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/live_changes.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../widgets/async_body.dart';
import '../../widgets/empty_state.dart';
import '../../widgets/side_panel.dart';
import '../../widgets/status_pill.dart';
import 'device_display.dart';

/// Monitoring › Lighting — the RGB controller (design 150, options 1 + 4).
///
/// A manager sets the lighting of every PC in view, the ticked ones, or one PC
/// from its side pane. The server stores what is WANTED
/// (LightingController); each PC's agent applies it through the bundled
/// OpenRGB and reports what it found in `specs.rgb_devices` and whether it
/// could in `specs.rgb_status`. Only a Ready PC (`rgb_status == 'ok'`) can be
/// ticked or set on its own: the others would store a wish nothing applies.
class LightingTab extends StatefulWidget {
  const LightingTab({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  /// Below this the table becomes cards and the PC pane opens full screen.
  static const double phoneBelow = 720;

  /// The eight quick colours. Data colours — what the lights will show — not
  /// theme paint, so they are hex strings rather than tokens.
  static const swatches = [
    '#FFFFFF',
    '#FF0000',
    '#FF8000',
    '#FFD000',
    '#00FF40',
    '#00AEEF',
    '#0040FF',
    '#A000FF',
  ];

  @override
  State<LightingTab> createState() => _LightingTabState();
}

/// The plain sentence for a PC's `specs.rgb_status`, and whether it is Ready.
/// [attention] is a problem someone can act on. A PC with no RGB hardware,
/// or one that has not reported yet, is not one (Jose, 2026-11-09: 31 of 31
/// "Needs attention", most of them PCs with no lights at all).
({String label, Tone tone, bool ready, bool attention}) lightingStatus(
  Map device,
) {
  final specs = device['specs'];
  final status = specs is Map ? specs['rgb_status'] as String? : null;

  if (status == 'ok') {
    return (label: 'Ready', tone: Tone.ok, ready: true, attention: false);
  }
  if (status != null && status.startsWith('brand_app_running:')) {
    final app = status.substring('brand_app_running:'.length);
    return (
      label: 'Close $app first',
      tone: Tone.caution,
      ready: false,
      attention: true,
    );
  }

  final label = switch (status) {
    'no_devices' => 'No RGB lights found',
    'engine_missing' => 'Lighting engine not installed',
    'engine_not_responding' => 'Lighting engine not responding',
    'needs_admin' => 'Needs admin rights',
    _ => 'Not reported yet',
  };
  final tone = switch (status) {
    'engine_not_responding' || 'needs_admin' => Tone.caution,
    'engine_missing' => Tone.bad,
    _ => Tone.neutral,
  };
  return (
    label: label,
    tone: tone,
    ready: false,
    attention: status != null && status != 'no_devices',
  );
}

List<Map> _rgbDevices(Map device) {
  final specs = device['specs'];
  final list = specs is Map ? specs['rgb_devices'] : null;
  return list is List ? list.whereType<Map>().toList() : const [];
}

int _ledCount(Map device) => _rgbDevices(
  device,
).fold(0, (sum, d) => sum + ((d['leds'] as num?)?.toInt() ?? 0));

Color? _parseHex(String? hex) {
  final m = RegExp(r'^#?([0-9A-Fa-f]{6})$').firstMatch((hex ?? '').trim());
  if (m == null) return null;
  return Color(int.parse('FF${m.group(1)}', radix: 16));
}

String? _normalHex(String text) {
  final m = RegExp(r'^#?([0-9A-Fa-f]{6})$').firstMatch(text.trim());
  return m == null ? null : '#${m.group(1)!.toUpperCase()}';
}

class _LightingTabState extends State<LightingTab> {
  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _devices = [];

  String _query = '';

  /// all · ready · attention
  String _filter = 'all';

  final Set<String> _ticked = {};

  /// 'all' = every PC in view (no device_ids), 'ticked' = the ticked ones.
  String _target = 'all';

  String? _openId;

  @override
  void initState() {
    super.initState();
    _load();
    // A machine's lights set elsewhere or coming online: re-read quietly;
    // the open editor is kept.
    _live = LiveChanges.of(widget.api).subscribe({'monitoring_devices'}, (_) {
      if (!_loading) return _load(quiet: true);
    }, active: LiveChanges.visible(this));
  }

  LiveSubscription? _live;

  @override
  void dispose() {
    _live?.cancel();
    super.dispose();
  }

  /// [quiet]: a live re-read; a failure keeps the devices on screen.
  Future<void> _load({bool quiet = false}) async {
    if (!quiet) {
      setState(() {
        _loading = _devices.isEmpty;
        _error = null;
      });
    }
    final response = await widget.api.get('/monitoring/devices');
    if (!mounted || (quiet && !response.ok)) return;
    setState(() {
      _loading = false;
      if (!response.ok) {
        _error = response.message;
        return;
      }
      _devices = (response.data as List<dynamic>)
          .cast<Map<String, dynamic>>()
          .where(isDesktopAgent)
          .toList();
      final ready = {
        for (final d in _devices)
          if (lightingStatus(d).ready) d['id'] as String,
      };
      _ticked.retainAll(ready);
    });
  }

  String _person(Map d) =>
      ((d['user'] as Map?)?['name'] as String?) ?? 'Nobody signed in';

  List<Map<String, dynamic>> get _shown {
    final q = _query.trim().toLowerCase();
    return [
      for (final d in _devices)
        if ((q.isEmpty ||
                friendlyDeviceName(d).toLowerCase().contains(q) ||
                _person(d).toLowerCase().contains(q)) &&
            switch (_filter) {
              'ready' => lightingStatus(d).ready,
              'attention' => lightingStatus(d).attention,
              _ => true,
            })
          d,
    ];
  }

  Future<String> _applyBulk(Map<String, dynamic> wanted) async {
    final body = {
      if (_target == 'ticked') 'device_ids': _ticked.toList(),
      ...wanted,
    };
    final response = await widget.api.post('/monitoring/lighting', body);
    if (!response.ok) return response.message;
    final n = ((response.data as Map?)?['updated'] as num?)?.toInt() ?? 0;
    await _load();
    return 'Set on $n ${n == 1 ? 'PC' : 'PCs'}';
  }

  Future<String> _applyOne(String id, Map<String, dynamic> wanted) async {
    final response = await widget.api.put(
      '/monitoring/devices/$id/lighting',
      wanted,
    );
    if (!response.ok) return response.message;
    await _load();
    return 'Set on this PC';
  }

  @override
  Widget build(BuildContext context) {
    return AsyncBody(
      loading: _loading,
      error: _error,
      onRetry: _load,
      child: LayoutBuilder(
        builder: (context, constraints) {
          final phone = constraints.maxWidth < LightingTab.phoneBelow;
          final open = _devices.where((d) => d['id'] == _openId).firstOrNull;

          if (phone && open != null) return _pane(open, phone: true);

          final list = ListView(
            padding: const EdgeInsets.all(16),
            children: [
              _bulkCard(),
              const SizedBox(height: 16),
              _searchBar(),
              const SizedBox(height: 12),
              if (_devices.isEmpty)
                const EmptyState(
                  key: ValueKey('tour-lighting-empty'),
                  icon: Icons.light_mode_outlined,
                  title: 'No PCs yet',
                  message:
                      'PCs running the EzBizi desktop app show here with '
                      'their RGB lights.',
                )
              else if (phone)
                ..._cards()
              else
                _table(),
            ],
          );

          if (phone || open == null) return list;
          return Row(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Expanded(child: list),
              const VerticalDivider(width: 1),
              SizedBox(width: 440, child: _pane(open, phone: false)),
            ],
          );
        },
      ),
    );
  }

  Widget _bulkCard() {
    final ready = _devices.where((d) => lightingStatus(d).ready).length;
    return Card(
      key: const ValueKey('tour-lighting-bulk'),
      margin: EdgeInsets.zero,
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: _LightingEditor(
          key: const ValueKey('lighting-bulk-editor'),
          keyPrefix: 'lighting-bulk',
          canApply:
              _devices.isNotEmpty &&
              (_target == 'all' ? ready > 0 : _ticked.isNotEmpty),
          onApply: _applyBulk,
          leading: Wrap(
            spacing: 12,
            runSpacing: 8,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              Text('Apply to', style: Theme.of(context).textTheme.titleSmall),
              SegmentedButton<String>(
                key: const ValueKey('lighting-target'),
                showSelectedIcon: false,
                segments: [
                  ButtonSegment(
                    value: 'all',
                    label: Text('All PCs in view · ${_devices.length}'),
                  ),
                  ButtonSegment(
                    value: 'ticked',
                    label: Text('Ticked · ${_ticked.length}'),
                  ),
                ],
                selected: {_target},
                onSelectionChanged: (s) => setState(() => _target = s.first),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _searchBar() {
    final ready = _devices.where((d) => lightingStatus(d).ready).length;
    final attention = _devices.where((d) => lightingStatus(d).attention).length;
    return Wrap(
      spacing: 8,
      runSpacing: 8,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        SizedBox(
          width: 280,
          child: ListSearch(
            key: const ValueKey('lighting-search'),
            hint: 'Search PCs or people',
            onChanged: (v) => setState(() => _query = v),
          ),
        ),
        for (final (key, label) in [
          ('all', 'All · ${_devices.length}'),
          ('ready', 'Ready · $ready'),
          ('attention', 'Needs attention · $attention'),
        ])
          ChoiceChip(
            key: ValueKey('lighting-filter-$key'),
            label: Text(label),
            selected: _filter == key,
            onSelected: (_) => setState(() => _filter = key),
          ),
      ],
    );
  }

  Widget _tick(Map d) {
    final id = d['id'] as String;
    final ready = lightingStatus(d).ready;
    return Checkbox(
      key: ValueKey('lighting-tick-$id'),
      value: _ticked.contains(id),
      onChanged: ready
          ? (v) =>
                setState(() => v == true ? _ticked.add(id) : _ticked.remove(id))
          : null,
    );
  }

  String _setBy(Map d) {
    final l = d['lighting'];
    if (l is! Map) return 'Not set';
    final by = l['set_by'] as String? ?? 'Someone';
    final v = (l['version'] as num?)?.toInt();
    if (v == null) return by;
    return '$by · ${relativeAge(DateTime.fromMillisecondsSinceEpoch(v))}';
  }

  Widget _table() {
    final theme = Theme.of(context);
    final head = theme.textTheme.labelMedium?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    final rows = _shown;
    return Card(
      key: const ValueKey('tour-lighting-list'),
      margin: EdgeInsets.zero,
      child: Column(
        children: [
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 10),
            child: Row(
              children: [
                const SizedBox(width: 48),
                Expanded(flex: 3, child: Text('PC', style: head)),
                Expanded(flex: 2, child: Text('Now', style: head)),
                Expanded(child: Text('Lights', style: head)),
                Expanded(flex: 2, child: Text('Set by', style: head)),
                Expanded(flex: 3, child: Text('Status', style: head)),
              ],
            ),
          ),
          const Divider(height: 1),
          if (rows.isEmpty)
            const Padding(
              padding: EdgeInsets.all(24),
              child: Text('No PCs match.'),
            ),
          for (final d in rows) ...[
            InkWell(
              key: ValueKey('lighting-row-${d['id']}'),
              onTap: () => setState(() => _openId = d['id'] as String),
              child: Container(
                color: d['id'] == _openId
                    ? theme.colorScheme.primaryContainer
                    : null,
                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 6),
                child: Row(
                  children: [
                    SizedBox(width: 48, child: _tick(d)),
                    Expanded(
                      flex: 3,
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            friendlyDeviceName(d),
                            style: theme.textTheme.titleSmall,
                            overflow: TextOverflow.ellipsis,
                          ),
                          Text(
                            _person(d),
                            style: theme.textTheme.bodySmall,
                            overflow: TextOverflow.ellipsis,
                          ),
                        ],
                      ),
                    ),
                    Expanded(flex: 2, child: LightingSwatch(d['lighting'])),
                    Expanded(child: Text('${_ledCount(d)}')),
                    Expanded(
                      flex: 2,
                      child: Text(
                        _setBy(d),
                        style: theme.textTheme.bodySmall,
                        overflow: TextOverflow.ellipsis,
                      ),
                    ),
                    Expanded(
                      flex: 3,
                      child: Align(
                        alignment: Alignment.centerLeft,
                        child: StatusPill(
                          lightingStatus(d).label,
                          tone: lightingStatus(d).tone,
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            ),
            const Divider(height: 1),
          ],
        ],
      ),
    );
  }

  List<Widget> _cards() {
    final theme = Theme.of(context);
    final rows = _shown;
    if (rows.isEmpty) return const [Text('No PCs match.')];
    return [
      for (final (i, d) in rows.indexed)
        Card(
          key: i == 0 ? const ValueKey('tour-lighting-list') : null,
          margin: const EdgeInsets.only(bottom: 8),
          child: InkWell(
            key: ValueKey('lighting-row-${d['id']}'),
            onTap: () => setState(() => _openId = d['id'] as String),
            child: Padding(
              padding: const EdgeInsets.fromLTRB(4, 8, 12, 8),
              child: Row(
                children: [
                  _tick(d),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          friendlyDeviceName(d),
                          style: theme.textTheme.titleSmall,
                          overflow: TextOverflow.ellipsis,
                        ),
                        Text(
                          '${_person(d)} · ${_ledCount(d)} lights',
                          style: theme.textTheme.bodySmall,
                          overflow: TextOverflow.ellipsis,
                        ),
                        const SizedBox(height: 4),
                        StatusPill(
                          lightingStatus(d).label,
                          tone: lightingStatus(d).tone,
                        ),
                      ],
                    ),
                  ),
                  LightingSwatch(d['lighting']),
                ],
              ),
            ),
          ),
        ),
    ];
  }

  Widget _pane(Map<String, dynamic> d, {required bool phone}) {
    final id = d['id'] as String;
    final status = lightingStatus(d);
    final found = _rgbDevices(d);
    final theme = Theme.of(context);
    final wanted = d['lighting'] is Map ? d['lighting'] as Map : null;

    return SidePanel(
      key: const ValueKey('tour-lighting-pane'),
      docked: true,
      title: friendlyDeviceName(d),
      subtitle: _person(d),
      onClose: phone ? null : () => setState(() => _openId = null),
      onBack: phone ? () => setState(() => _openId = null) : null,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              StatusPill(status.label, tone: status.tone),
              const SizedBox(width: 12),
              Expanded(
                child: Text(_setBy(d), style: theme.textTheme.bodySmall),
              ),
            ],
          ),
          const SizedBox(height: 16),
          _LightingEditor(
            key: ValueKey('lighting-pane-editor-$id'),
            keyPrefix: 'lighting-pane',
            initial: wanted,
            canApply: status.ready,
            onApply: (w) => _applyOne(id, w),
          ),
          const SizedBox(height: 24),
          Text('Lights found', style: theme.textTheme.titleSmall),
          const SizedBox(height: 8),
          if (found.isEmpty)
            Text(status.label, style: theme.textTheme.bodySmall)
          else
            for (final r in found)
              ListTile(
                dense: true,
                contentPadding: EdgeInsets.zero,
                leading: const Icon(Icons.light_mode_outlined),
                title: Text(r['name'] as String? ?? 'Device'),
                subtitle: Text(
                  [
                    if (r['vendor'] != null) r['vendor'],
                    if (r['type'] != null) r['type'],
                    '${r['zones'] ?? 0} zones · ${r['leds'] ?? 0} lights',
                  ].join(' · '),
                ),
              ),
        ],
      ),
    );
  }
}

/// The lighting a PC was set to: a dot of its colour, a rainbow ring, a
/// breathing dot with its word, or a dash for off / not set.
class LightingSwatch extends StatelessWidget {
  const LightingSwatch(this.lighting, {super.key});

  final Object? lighting;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final l = lighting is Map ? lighting as Map : null;
    final mode = l?['mode'] as String?;
    final colour = _parseHex(l?['color'] as String?);

    if (l == null || mode == 'off' || mode == null) {
      return Text(
        '—',
        key: const ValueKey('lighting-swatch-off'),
        style: theme.textTheme.bodyMedium,
      );
    }

    final dot = Container(
      key: const ValueKey('lighting-swatch'),
      width: 20,
      height: 20,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        color: mode == 'rainbow' ? null : colour,
        gradient: mode == 'rainbow'
            ? SweepGradient(
                colors: [
                  for (var h = 0; h <= 360; h += 60)
                    HSVColor.fromAHSV(1, h % 360 * 1.0, 1, 1).toColor(),
                ],
              )
            : null,
        border: Border.all(color: theme.colorScheme.outline),
      ),
    );

    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        dot,
        if (mode == 'breathing') ...[
          const SizedBox(width: 6),
          Icon(Icons.air, size: 16, color: theme.colorScheme.onSurfaceVariant),
          Text(' Breathing', style: theme.textTheme.bodySmall),
        ],
        if (mode == 'rainbow')
          Text('  Rainbow', style: theme.textTheme.bodySmall),
      ],
    );
  }
}

/// Mode, colour (swatches + hex), brightness and Apply. Shared by the bulk
/// bar and the PC pane; [onApply] gets the body and returns the result line.
class _LightingEditor extends StatefulWidget {
  const _LightingEditor({
    super.key,
    required this.keyPrefix,
    required this.onApply,
    required this.canApply,
    this.leading,
    this.initial,
  });

  final String keyPrefix;
  final Future<String> Function(Map<String, dynamic> wanted) onApply;
  final bool canApply;
  final Widget? leading;
  final Map? initial;

  @override
  State<_LightingEditor> createState() => _LightingEditorState();
}

class _LightingEditorState extends State<_LightingEditor> {
  late String _mode = widget.initial?['mode'] as String? ?? 'static';
  late final _hex = TextEditingController(
    text: widget.initial?['color'] as String? ?? '#00AEEF',
  );
  late double _brightness =
      (widget.initial?['brightness'] as num?)?.toDouble() ?? 100;
  bool _busy = false;
  String? _result;

  bool get _needsColour => _mode == 'static' || _mode == 'breathing';

  @override
  void dispose() {
    _hex.dispose();
    super.dispose();
  }

  Future<void> _apply() async {
    setState(() {
      _busy = true;
      _result = null;
    });
    final result = await widget.onApply({
      'mode': _mode,
      if (_needsColour) 'color': _normalHex(_hex.text),
      'brightness': _brightness.round(),
    });
    if (!mounted) return;
    setState(() {
      _busy = false;
      _result = result;
    });
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final p = widget.keyPrefix;
    final hexOk = !_needsColour || _normalHex(_hex.text) != null;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        if (widget.leading != null) ...[
          widget.leading!,
          const SizedBox(height: 12),
        ],
        SingleChildScrollView(
          scrollDirection: Axis.horizontal,
          child: SegmentedButton<String>(
            key: ValueKey('$p-mode'),
            showSelectedIcon: false,
            segments: const [
              ButtonSegment(value: 'off', label: Text('Off')),
              ButtonSegment(value: 'static', label: Text('Static')),
              ButtonSegment(value: 'breathing', label: Text('Breathing')),
              ButtonSegment(value: 'rainbow', label: Text('Rainbow')),
            ],
            selected: {_mode},
            onSelectionChanged: (s) => setState(() => _mode = s.first),
          ),
        ),
        if (_needsColour) ...[
          const SizedBox(height: 12),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              for (final hex in LightingTab.swatches)
                InkWell(
                  key: ValueKey('$p-swatch-$hex'),
                  customBorder: const CircleBorder(),
                  onTap: () => setState(() => _hex.text = hex),
                  child: Container(
                    width: 28,
                    height: 28,
                    decoration: BoxDecoration(
                      shape: BoxShape.circle,
                      color: _parseHex(hex),
                      border: Border.all(
                        width: _normalHex(_hex.text) == hex ? 3 : 1,
                        color: _normalHex(_hex.text) == hex
                            ? theme.colorScheme.primary
                            : theme.colorScheme.outline,
                      ),
                    ),
                  ),
                ),
              SizedBox(
                width: 130,
                child: TextField(
                  key: ValueKey('$p-hex'),
                  controller: _hex,
                  decoration: InputDecoration(
                    isDense: true,
                    labelText: 'Hex',
                    errorText: hexOk ? null : 'Like #00AEEF',
                  ),
                  onChanged: (_) => setState(() {}),
                ),
              ),
            ],
          ),
        ],
        if (_mode != 'off') ...[
          const SizedBox(height: 8),
          Row(
            children: [
              Text(
                'Brightness ${_brightness.round()}%',
                style: theme.textTheme.bodyMedium,
              ),
              Expanded(
                child: Slider(
                  key: ValueKey('$p-brightness'),
                  value: _brightness,
                  max: 100,
                  divisions: 20,
                  onChanged: (v) => setState(() => _brightness = v),
                ),
              ),
            ],
          ),
        ],
        const SizedBox(height: 8),
        Wrap(
          spacing: 12,
          runSpacing: 8,
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            FilledButton(
              key: ValueKey('$p-apply'),
              onPressed: widget.canApply && hexOk && !_busy ? _apply : null,
              child: const Text('Apply'),
            ),
            if (_result != null)
              Text(
                _result!,
                key: ValueKey('$p-result'),
                style: theme.textTheme.bodyMedium,
              ),
          ],
        ),
      ],
    );
  }
}
