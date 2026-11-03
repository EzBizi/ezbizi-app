import '../../widgets/list_search.dart';
import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../core/timestamps.dart';
import '../../widgets/error_note.dart';
import '../../widgets/refused.dart';
import '../../widgets/side_panel.dart';
import '../../widgets/status_pill.dart';
import 'device_display.dart';

/// Settings › Monitoring › Camera sites (UI queue 117, desktop 1 + phone 4;
/// camera bridge plan, Task 3).
///
/// Each site is a place whose recorder reaches EzBizi through one PC there
/// that stays on. Offline sites sort first, loud, with the reason the bridge
/// last gave. A site opens in place to its cameras and its bridge.
///
/// Routes: GET/POST `/monitoring/camera-sites`, GET `/monitoring/camera-sites/
/// {id}`, POST `…/{id}/pairing-code` and `…/{id}/revoke`, and PATCH
/// `/monitoring/cameras/{id}` for a camera's name and on/off — the existing
/// camera route; bridge cameras are rows of the same table.
class CameraSitesSettings extends StatefulWidget {
  const CameraSitesSettings({
    super.key,
    required this.api,
    required this.session,
  });

  final ApiClient api;
  final Session session;

  /// Narrower than this (the tab's own width), sites and cameras are rows.
  static const double narrowBelow = 760;

  @override
  State<CameraSitesSettings> createState() => _CameraSitesSettingsState();
}

class _CameraSitesSettingsState extends State<CameraSitesSettings> {
  List<Map<String, dynamic>> _sites = const [];

  String _query = '';

  /// The sites the search keeps: case-insensitive over what a row shows.
  List<Map<String, dynamic>> get _shownSites {
    final q = _query.trim().toLowerCase();
    if (q.isEmpty) return _sites;
    return [
      for (final s in _sites)
        if ([
          s['name'],
          s['device'] is Map ? (s['device'] as Map)['pc_name'] : null,
          s['last_reason'],
          s['online'] == true ? 'Online' : 'Offline',
        ].any((v) => (v?.toString() ?? '').toLowerCase().contains(q)))
          s,
    ];
  }

  bool _loading = true;
  String? _error;

  String? _openId;
  Map<String, dynamic>? _site;
  bool _siteLoading = false;
  String? _siteError;

  Map<String, dynamic>? _code;
  bool _codeLoading = false;
  String? _actionError;
  Timer? _tick;

  Me get _me => widget.session.me!;
  bool get _manage => _me.can('monitoring.cameras.manage');
  String? _refusal(String act) => _me.refusal('monitoring.cameras.manage', act);

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _tick?.cancel();
    for (final c in _foundNames.values) {
      c.dispose();
    }
    super.dispose();
  }

  static Map<String, dynamic> _map(Object? v) =>
      v is Map ? v.cast<String, dynamic>() : <String, dynamic>{};

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    final res = await widget.api.get('/monitoring/camera-sites');
    if (!mounted) return;
    setState(() {
      _loading = false;
      if (!res.ok) {
        _error = res.statusCode == 404
            ? 'Camera sites are not available on this server yet.'
            : res.message;
        return;
      }
      final list = [for (final s in (res.data as List? ?? const [])) _map(s)];
      // Offline first; the server's order otherwise.
      _sites = [
        ...list.where((s) => s['online'] != true),
        ...list.where((s) => s['online'] == true),
      ];
    });
  }

  /// [pairNow] right after Add a site; opening a site only reads it, and its
  /// Get a code button asks for one.
  Future<void> _openSite(String id, {bool pairNow = false}) async {
    _tick?.cancel();
    setState(() {
      _openId = id;
      _site = null;
      _siteLoading = true;
      _siteError = null;
      _code = null;
      _actionError = null;
    });
    final res = await widget.api.get('/monitoring/camera-sites/$id');
    if (!mounted || _openId != id) return;
    setState(() {
      _siteLoading = false;
      if (!res.ok) {
        _siteError = res.message;
        return;
      }
      final site = _map(res.data);
      // Plain mutable maps, so a switch flips the row it is drawn from.
      site['cameras'] = [
        for (final c in (site['cameras'] as List? ?? const []))
          Map<String, dynamic>.of(_map(c)),
      ];
      _site = site;
    });
    if (pairNow && _site != null && _site!['device'] == null && _manage) {
      _newCode();
    }
  }

  void _back() {
    _tick?.cancel();
    setState(() {
      _openId = null;
      _site = null;
      _code = null;
    });
    _load();
  }

  Future<void> _add() async {
    final name = await showSidePanel<String>(
      context,
      builder: (context) =>
          const SidePanel(title: 'Add a site', child: _NewSite()),
    );
    if (name == null || name.trim().isEmpty || !mounted) return;
    final res = await widget.api.post('/monitoring/camera-sites', {
      'name': name.trim(),
    });
    if (!mounted) return;
    if (!res.ok) {
      setState(() => _error = res.reason);
      return;
    }
    final id = '${_map(res.data)['id']}';
    unawaited(_load());
    await _openSite(id, pairNow: true);
  }

  Future<void> _newCode() async {
    final id = _openId;
    if (id == null) return;
    setState(() {
      _codeLoading = true;
      _actionError = null;
    });
    final res = await widget.api.post(
      '/monitoring/camera-sites/$id/pairing-code',
      const {},
    );
    if (!mounted || _openId != id) return;
    setState(() {
      _codeLoading = false;
      if (!res.ok) {
        _actionError = res.reason;
        return;
      }
      // The plan writes `{code, expires_at}`; the list routes wrap in `data`.
      _code = res.body['data'] is Map ? _map(res.body['data']) : res.body;
    });
    _tick?.cancel();
    _tick = Timer.periodic(const Duration(seconds: 1), (_) {
      if (mounted) setState(() {});
    });
  }

  Future<void> _disconnect() async {
    final id = _openId;
    final pc = '${_map(_site?['device'])['pc_name'] ?? 'this PC'}';
    final sure = await showSidePanel<bool>(
      context,
      builder: (context) => SidePanel(
        title: 'Disconnect this PC',
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            key: const ValueKey('camera-site-disconnect-confirm'),
            onPressed: () => Navigator.of(context).pop(true),
            child: const Text('Disconnect'),
          ),
        ],
        child: Text(
          '$pc stops relaying this site\'s cameras. To bring them back, pair '
          'a PC with a new code and enter the recorder\'s password on it.',
        ),
      ),
    );
    if (sure != true || id == null || !mounted) return;
    final res = await widget.api.post(
      '/monitoring/camera-sites/$id/revoke',
      const {},
    );
    if (!mounted) return;
    if (!res.ok) {
      setState(() => _actionError = res.reason);
      return;
    }
    await _openSite(id);
  }

  Future<void> _patchCamera(
    Map<String, dynamic> camera,
    Map<String, dynamic> change,
  ) async {
    final before = Map<String, dynamic>.of(camera);
    setState(() {
      camera.addAll(change);
      _actionError = null;
    });
    final res = await widget.api.patch(
      '/monitoring/cameras/${camera['id']}',
      change,
    );
    if (!mounted || res.ok) return;
    setState(() {
      camera
        ..clear()
        ..addAll(before);
      _actionError = res.reason;
    });
  }

  /// Channels the bridge found on the recorder and nobody has added yet
  /// (`found`, Monitoring 2026-10-20). Matched on `channel` once cameras
  /// carry it; until then nothing found is hidden, rather than guessed.
  List<({String channel, String label, String device})> _found(
    Map<String, dynamic> site,
    List<Map<String, dynamic>> cameras,
  ) {
    final added = {
      for (final c in cameras)
        if (c['channel'] != null) '${c['channel']}',
    };
    return [
      for (final d in (site['found'] as List? ?? const []))
        for (final ch in (_map(d)['channels'] as List? ?? const []))
          if (!added.contains('${_map(ch)['channel']}'))
            (
              channel: '${_map(ch)['channel']}',
              label: '${_map(ch)['label'] ?? ''}',
              device: '${_map(d)['label'] ?? _map(d)['address'] ?? 'Recorder'}',
            ),
    ];
  }

  final Map<String, TextEditingController> _foundNames = {};
  String? _adding;

  Future<void> _addChannel(String channel, String label) async {
    final id = _openId;
    if (id == null) return;
    setState(() {
      _adding = channel;
      _actionError = null;
    });
    final res = await widget.api.post('/monitoring/camera-sites/$id/cameras', {
      // A string on both sides (Monitoring validates it as one).
      'channel': channel,
      'label': label.trim().isEmpty ? 'Channel $channel' : label.trim(),
    });
    if (!mounted) return;
    setState(() {
      _adding = null;
      if (!res.ok) _actionError = res.reason;
    });
    if (res.ok) await _openSite(id);
  }

  Widget _foundBox(
    BuildContext context,
    List<({String channel, String label, String device})> found,
  ) {
    final theme = Theme.of(context);
    return _section(
      context,
      key: const ValueKey('camera-site-found'),
      children: [
        Text('Found on the recorder', style: theme.textTheme.titleSmall),
        const SizedBox(height: 4),
        Text(
          'Name a channel and add it to show it here.',
          style: _muted(context),
        ),
        const SizedBox(height: 8),
        for (final f in found)
          Padding(
            padding: const EdgeInsets.only(bottom: 8),
            child: Row(
              children: [
                SizedBox(
                  width: 120,
                  child: Text(
                    '${f.device} · ${f.channel}',
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: _muted(context),
                  ),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: TextField(
                    key: ValueKey('camera-found-name-${f.channel}'),
                    controller: _foundNames.putIfAbsent(
                      f.channel,
                      () => TextEditingController(text: f.label),
                    ),
                    enabled: _manage,
                    decoration: const InputDecoration(
                      isDense: true,
                      labelText: 'Name',
                    ),
                  ),
                ),
                const SizedBox(width: 8),
                Refused(
                  reason: _refusal('add a camera'),
                  child: FilledButton.tonal(
                    key: ValueKey('camera-found-add-${f.channel}'),
                    onPressed: _manage && _adding == null
                        ? () => _addChannel(
                            f.channel,
                            _foundNames[f.channel]?.text ?? '',
                          )
                        : null,
                    child: Text(_adding == f.channel ? 'Adding…' : 'Add'),
                  ),
                ),
              ],
            ),
          ),
      ],
    );
  }

  // ── Building ─────────────────────────────────────────────────────

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, box) {
        final narrow = box.maxWidth < CameraSitesSettings.narrowBelow;
        return _openId == null
            ? _list(context, narrow)
            : _page(context, narrow);
      },
    );
  }

  TextStyle? _muted(BuildContext context) {
    final theme = Theme.of(context);
    return theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
  }

  TextStyle? _errorText(BuildContext context) {
    final theme = Theme.of(context);
    return theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.error,
      fontWeight: FontWeight.w600,
    );
  }

  String _since(Object? iso) => formatListStamp(iso, '', formats: _me.display);

  String _ago(Object? iso) {
    final at = iso is String ? DateTime.tryParse(iso) : null;
    return at == null ? 'never' : relativeAge(at);
  }

  Widget _tryAgain(String message, VoidCallback retry) => Padding(
    key: const ValueKey('camera-sites-error'),
    padding: const EdgeInsets.all(24),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      mainAxisSize: MainAxisSize.min,
      children: [
        ErrorNote(message),
        const SizedBox(height: 12),
        TextButton(
          key: const ValueKey('camera-sites-retry'),
          onPressed: retry,
          child: const Text('Try again'),
        ),
      ],
    ),
  );

  Widget _list(BuildContext context, bool narrow) {
    final theme = Theme.of(context);
    final muted = _muted(context);

    final header = Wrap(
      spacing: 8,
      runSpacing: 8,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        // Standard field height, as tall as Leads search (Jose, 2026-10-27).
        SizedBox(
          width: 240,
          child: ListSearch(
            key: const Key('camera-sites-search'),
            hint: 'Find a site',
            onChanged: (v) => setState(() => _query = v),
          ),
        ),
        Refused(
          reason: _refusal('add a camera site'),
          child: FilledButton.icon(
            key: const ValueKey('camera-sites-add'),
            onPressed: _manage ? _add : null,
            icon: const Icon(Icons.add, size: 18),
            label: const Text('Add a site'),
          ),
        ),
        const OutlinedButton(
          key: ValueKey('camera-sites-wiring'),
          onPressed: null,
          child: Text('Wiring guide · coming soon'),
        ),
      ],
    );

    return ListView(
      key: const ValueKey('camera-sites-list'),
      padding: EdgeInsets.all(narrow ? 12 : 20),
      children: [
        Text(
          'Camera sites',
          style: theme.textTheme.titleLarge?.copyWith(
            fontWeight: FontWeight.w700,
          ),
        ),
        const SizedBox(height: 4),
        Text(
          "Each site's recorder reaches EzBizi through one PC there that "
          'stays on.',
          style: muted,
        ),
        const SizedBox(height: 12),
        header,
        const SizedBox(height: 16),
        if (_loading)
          const Padding(
            padding: EdgeInsets.all(24),
            child: Center(child: CircularProgressIndicator()),
          )
        else if (_error != null)
          _tryAgain(_error!, _load)
        else if (_sites.isEmpty)
          Padding(
            key: const ValueKey('camera-sites-empty'),
            padding: const EdgeInsets.symmetric(vertical: 24),
            child: Text(
              'No sites yet. Add a site for each place with a recorder.',
              style: muted,
            ),
          )
        else if (_shownSites.isEmpty)
          Padding(
            padding: const EdgeInsets.symmetric(vertical: 24),
            child: Text(
              'No sites match "${_query.trim()}".',
              style: theme.textTheme.bodyMedium,
            ),
          )
        else
          for (final s in _shownSites) _siteRow(context, s, narrow),
      ],
    );
  }

  Widget _status(BuildContext context, Map<String, dynamic> s) {
    if (s['online'] == true) return const StatusPill('Online', tone: Tone.ok);
    final since = _since(s['offline_since']);
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        const StatusPill('OFFLINE', tone: Tone.bad),
        if (since.isNotEmpty) ...[
          const SizedBox(width: 6),
          Text('since $since', style: _errorText(context)),
        ],
      ],
    );
  }

  Widget _siteRow(BuildContext context, Map<String, dynamic> s, bool narrow) {
    final theme = Theme.of(context);
    final id = '${s['id']}';
    final online = s['online'] == true;
    final device = s['device'] == null ? null : _map(s['device']);
    final count = s['camera_count'] ?? 0;
    final cameras = '$count camera${count == 1 ? '' : 's'}';
    final reason = s['last_reason'] as String?;
    final line = narrow
        ? cameras
        : device == null
        ? 'No PC paired yet · $cameras'
        : 'Bridge: ${device['pc_name']} · $cameras · last seen '
              '${_ago(s['last_checkin_at'])}';

    return Card(
      key: ValueKey('camera-site-$id'),
      margin: const EdgeInsets.only(bottom: 8),
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: () => _openSite(id),
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Row(
            children: [
              Icon(
                online ? Icons.videocam_outlined : Icons.warning_amber_rounded,
                color: online
                    ? theme.colorScheme.onSurfaceVariant
                    : theme.colorScheme.error,
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      '${s['name']}',
                      style: theme.textTheme.titleSmall?.copyWith(
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                    const SizedBox(height: 2),
                    Text(line, style: _muted(context)),
                    if (!online && reason != null && reason.isNotEmpty)
                      Padding(
                        padding: const EdgeInsets.only(top: 2),
                        child: Text(reason, style: _errorText(context)),
                      ),
                  ],
                ),
              ),
              const SizedBox(width: 8),
              _status(context, s),
              if (!narrow) ...[
                const SizedBox(width: 8),
                const Icon(Icons.chevron_right),
              ],
            ],
          ),
        ),
      ),
    );
  }

  Widget _page(BuildContext context, bool narrow) {
    final theme = Theme.of(context);
    final site = _site;
    final known =
        site ?? _sites.where((s) => '${s['id']}' == _openId).firstOrNull;

    final head = Row(
      children: [
        IconButton(
          key: const ValueKey('camera-site-back'),
          tooltip: 'Back to camera sites',
          onPressed: _back,
          icon: const Icon(Icons.arrow_back),
        ),
        const SizedBox(width: 4),
        Expanded(
          child: Text(
            '${known?['name'] ?? ''}',
            style: theme.textTheme.titleLarge?.copyWith(
              fontWeight: FontWeight.w700,
            ),
          ),
        ),
        if (known != null) _status(context, known),
      ],
    );

    final children = <Widget>[head, const SizedBox(height: 12)];
    if (_siteLoading) {
      children.add(const Center(child: CircularProgressIndicator()));
    } else if (_siteError != null) {
      children.add(_tryAgain(_siteError!, () => _openSite(_openId!)));
    } else if (site != null) {
      final reason = site['last_reason'] as String?;
      if (site['online'] != true && reason != null && reason.isNotEmpty) {
        children.add(Text(reason, style: _errorText(context)));
        children.add(const SizedBox(height: 8));
      }
      if (_actionError != null) {
        children.add(ErrorNote(_actionError!));
        children.add(const SizedBox(height: 8));
      }
      final cameras = (site['cameras'] as List).cast<Map<String, dynamic>>();
      if (site['device'] == null || _code != null || _codeLoading) {
        children.add(_pairingPanel(context, site['device'] == null));
        children.add(const SizedBox(height: 16));
      }
      if (cameras.isEmpty) {
        children.add(
          Text(
            site['device'] == null
                ? 'Cameras appear here once a PC at this site is paired.'
                : 'The bridge has not reported any cameras yet.',
            style: _muted(context),
          ),
        );
      } else if (narrow) {
        for (final c in cameras) {
          children.add(_cameraRow(context, c));
        }
      } else {
        children.add(
          Wrap(
            spacing: 12,
            runSpacing: 12,
            children: [for (final c in cameras) _cameraTile(context, c)],
          ),
        );
      }
      final found = _found(site, cameras);
      if (found.isNotEmpty) {
        children.add(const SizedBox(height: 20));
        children.add(_foundBox(context, found));
      }
      if (site['device'] != null) {
        children.add(const SizedBox(height: 20));
        children.add(_bridgeBox(context, _map(site['device'])));
      }
    }

    return ListView(
      key: const ValueKey('camera-site-page'),
      padding: EdgeInsets.all(narrow ? 12 : 20),
      children: children,
    );
  }

  Widget _still(BuildContext context, Map<String, dynamic> c) {
    final url = c['still_url'] as String?;
    final theme = Theme.of(context);
    final empty = Container(
      key: ValueKey('camera-still-empty-${c['id']}'),
      color: theme.colorScheme.surfaceContainerHighest,
      alignment: Alignment.center,
      padding: const EdgeInsets.all(12),
      child: Text(
        'No picture yet — the bridge sends one every 10 minutes',
        textAlign: TextAlign.center,
        style: _muted(context),
      ),
    );
    return AspectRatio(
      aspectRatio: 16 / 9,
      child: ClipRRect(
        borderRadius: BorderRadius.circular(8),
        child: url == null || url.isEmpty
            ? empty
            : Image.network(
                url,
                key: ValueKey('camera-still-${c['id']}'),
                fit: BoxFit.cover,
                errorBuilder: (_, _, _) => empty,
              ),
      ),
    );
  }

  Widget _switch(Map<String, dynamic> c) => Refused(
    reason: _refusal('turn a camera on or off'),
    child: Switch(
      key: ValueKey('camera-enabled-${c['id']}'),
      value: c['enabled'] == true,
      onChanged: _manage ? (on) => _patchCamera(c, {'enabled': on}) : null,
    ),
  );

  Widget _name(Map<String, dynamic> c) => _InPlaceName(
    key: ValueKey('camera-name-${c['id']}'),
    id: '${c['id']}',
    label: '${c['label'] ?? ''}',
    refusal: _refusal('rename a camera'),
    onSave: (label) => _patchCamera(c, {'label': label}),
  );

  static const _supportWords = {
    'live': 'Live',
    'recordings': 'Recordings',
    'alerts': 'Alerts',
  };

  Widget _chips(Map<String, dynamic> c) => Wrap(
    spacing: 6,
    runSpacing: 6,
    children: [
      for (final s in (c['supports'] as List? ?? const []))
        StatusPill(_supportWords['$s'] ?? '$s', tone: Tone.info),
      if (c['enabled'] != true) const StatusPill('Off: not relayed'),
    ],
  );

  Widget? _cameraProblem(BuildContext context, Map<String, dynamic> c) {
    final status = c['bridge_status'] as String?;
    if (status == null || status == 'ok') return null;
    final reason = c['bridge_reason'] as String?;
    final text = reason != null && reason.isNotEmpty
        ? reason
        : status == 'auth_failed'
        ? 'The recorder refused the password saved on the PC.'
        : 'The bridge cannot reach this camera.';
    return Text(
      text,
      key: ValueKey('camera-problem-${c['id']}'),
      style: _errorText(context),
    );
  }

  Widget _cameraTile(BuildContext context, Map<String, dynamic> c) {
    final problem = _cameraProblem(context, c);
    return SizedBox(
      key: ValueKey('camera-tile-${c['id']}'),
      width: 260,
      child: Card(
        margin: EdgeInsets.zero,
        child: Padding(
          padding: const EdgeInsets.all(10),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              _still(context, c),
              const SizedBox(height: 8),
              Row(
                children: [
                  Expanded(child: _name(c)),
                  _switch(c),
                ],
              ),
              const SizedBox(height: 4),
              _chips(c),
              if (problem != null) ...[const SizedBox(height: 6), problem],
            ],
          ),
        ),
      ),
    );
  }

  Widget _cameraRow(BuildContext context, Map<String, dynamic> c) {
    final problem = _cameraProblem(context, c);
    return Card(
      key: ValueKey('camera-row-${c['id']}'),
      margin: const EdgeInsets.only(bottom: 8),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(12, 8, 8, 8),
        child: Row(
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  _name(c),
                  const SizedBox(height: 4),
                  _chips(c),
                  if (problem != null) ...[const SizedBox(height: 4), problem],
                ],
              ),
            ),
            _switch(c),
          ],
        ),
      ),
    );
  }

  Widget _section(
    BuildContext context, {
    required Key key,
    required List<Widget> children,
  }) {
    final theme = Theme.of(context);
    return Container(
      key: key,
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        border: Border.all(color: theme.colorScheme.outlineVariant),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: children,
      ),
    );
  }

  Widget _bridgeBox(BuildContext context, Map<String, dynamic> device) {
    final theme = Theme.of(context);
    final version = device['app_version'];
    return _section(
      context,
      key: const ValueKey('camera-site-bridge'),
      children: [
        Text(
          'The bridge',
          style: theme.textTheme.titleSmall?.copyWith(
            fontWeight: FontWeight.w700,
          ),
        ),
        const SizedBox(height: 4),
        Text(
          [
            '${device['pc_name'] ?? 'Unnamed PC'}',
            if (version != null) 'EzBizi $version',
            'last spoke ${_ago(_site?['last_checkin_at'])}',
          ].join(' · '),
        ),
        const SizedBox(height: 4),
        Text(
          "The recorder's password is kept on this PC only. A new PC needs it "
          'entered again.',
          style: _muted(context),
        ),
        const SizedBox(height: 12),
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            Refused(
              reason: _refusal('pair a new PC'),
              child: OutlinedButton(
                key: const ValueKey('camera-site-pair-new'),
                onPressed: _manage && !_codeLoading ? _newCode : null,
                child: const Text('Pair a new PC'),
              ),
            ),
            Refused(
              reason: _refusal('disconnect this PC'),
              child: OutlinedButton(
                key: const ValueKey('camera-site-disconnect'),
                onPressed: _manage ? _disconnect : null,
                child: const Text('Disconnect this PC'),
              ),
            ),
          ],
        ),
      ],
    );
  }

  Widget _pairingPanel(BuildContext context, bool unpaired) {
    final theme = Theme.of(context);
    final muted = _muted(context);
    final code = _code;
    final expires = DateTime.tryParse('${code?['expires_at'] ?? ''}');
    final left = expires?.difference(DateTime.now());
    final expired = left != null && left.isNegative;
    String two(int n) => n.toString().padLeft(2, '0');

    final children = <Widget>[
      Text(
        unpaired ? 'Pair the PC at this site' : 'Pair a new PC',
        style: theme.textTheme.titleSmall?.copyWith(
          fontWeight: FontWeight.w700,
        ),
      ),
      const SizedBox(height: 4),
    ];

    if (!_manage) {
      children.add(
        Text(
          'No PC is paired with this site yet. Somebody who can manage '
          'cameras can show a pairing code here.',
          style: muted,
        ),
      );
    } else {
      children.add(
        Text(
          'On the PC that stays on at this site: EzBizi › Settings › This '
          'computer › Camera bridge, then enter this code.',
        ),
      );
      children.add(const SizedBox(height: 12));
      if (_codeLoading && code == null) {
        children.add(const CircularProgressIndicator());
      } else if (code != null) {
        children.add(
          SelectableText(
            '${code['code']}',
            key: const ValueKey('camera-site-pairing-code'),
            style: theme.textTheme.headlineMedium?.copyWith(
              fontWeight: FontWeight.w800,
              letterSpacing: 4,
              color: expired ? theme.colorScheme.onSurfaceVariant : null,
            ),
          ),
        );
        if (left != null) {
          children.add(
            Text(
              expired
                  ? 'This code has expired.'
                  : 'Expires in ${left.inMinutes}:${two(left.inSeconds % 60)}',
              key: const ValueKey('camera-site-pairing-expiry'),
              style: expired ? _errorText(context) : muted,
            ),
          );
        }
      }
      children.add(const SizedBox(height: 12));
      children.add(
        OutlinedButton(
          key: const ValueKey('camera-site-new-code'),
          onPressed: _codeLoading ? null : _newCode,
          child: Text(code == null ? 'Get a code' : 'Get a new code'),
        ),
      );
    }

    return _section(
      context,
      key: const ValueKey('camera-site-pairing'),
      children: children,
    );
  }
}

/// The Add a site panel's one field.
class _NewSite extends StatefulWidget {
  const _NewSite();

  @override
  State<_NewSite> createState() => _NewSiteState();
}

class _NewSiteState extends State<_NewSite> {
  final _name = TextEditingController();

  @override
  void dispose() {
    _name.dispose();
    super.dispose();
  }

  void _submit() {
    if (_name.text.trim().isEmpty) return;
    Navigator.of(context).pop(_name.text.trim());
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        TextField(
          key: const ValueKey('camera-site-new-name'),
          controller: _name,
          autofocus: true,
          maxLength: 120,
          decoration: const InputDecoration(
            labelText: 'Site name',
            hintText: 'Main yard',
          ),
          onSubmitted: (_) => _submit(),
        ),
        const SizedBox(height: 8),
        Text(
          'Next, a pairing code for the PC that stays on at this site.',
          style: Theme.of(context).textTheme.bodySmall,
        ),
        const SizedBox(height: 16),
        Align(
          alignment: Alignment.centerRight,
          child: FilledButton(
            key: const ValueKey('camera-site-new-save'),
            onPressed: _submit,
            child: const Text('Add a site'),
          ),
        ),
      ],
    );
  }
}

/// A camera's name, renamed where it is drawn.
class _InPlaceName extends StatefulWidget {
  const _InPlaceName({
    super.key,
    required this.id,
    required this.label,
    required this.refusal,
    required this.onSave,
  });

  final String id;
  final String label;
  final String? refusal;
  final ValueChanged<String> onSave;

  @override
  State<_InPlaceName> createState() => _InPlaceNameState();
}

class _InPlaceNameState extends State<_InPlaceName> {
  TextEditingController? _editing;

  @override
  void dispose() {
    _editing?.dispose();
    super.dispose();
  }

  void _save() {
    final text = _editing!.text.trim();
    _editing!.dispose();
    setState(() => _editing = null);
    if (text.isNotEmpty && text != widget.label) widget.onSave(text);
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final editing = _editing;
    if (editing != null) {
      return Row(
        children: [
          Expanded(
            child: TextField(
              key: ValueKey('camera-name-field-${widget.id}'),
              controller: editing,
              autofocus: true,
              maxLength: 255,
              decoration: const InputDecoration(isDense: true, counterText: ''),
              onSubmitted: (_) => _save(),
            ),
          ),
          IconButton(
            key: ValueKey('camera-name-save-${widget.id}'),
            tooltip: 'Save name',
            onPressed: _save,
            icon: const Icon(Icons.check, size: 18),
          ),
        ],
      );
    }
    final can = widget.refusal == null;
    return Row(
      children: [
        Flexible(
          child: Text(
            widget.label,
            overflow: TextOverflow.ellipsis,
            style: theme.textTheme.titleSmall?.copyWith(
              fontWeight: FontWeight.w700,
            ),
          ),
        ),
        Refused(
          reason: widget.refusal,
          child: IconButton(
            key: ValueKey('camera-name-edit-${widget.id}'),
            tooltip: can ? 'Rename' : null,
            visualDensity: VisualDensity.compact,
            onPressed: can
                ? () => setState(
                    () => _editing = TextEditingController(text: widget.label),
                  )
                : null,
            icon: const Icon(Icons.edit_outlined, size: 16),
          ),
        ),
      ],
    );
  }
}
