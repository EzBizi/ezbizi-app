import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../widgets/error_note.dart';
import '../monitoring/device_display.dart';
import 'camera_bridge_source_stub.dart'
    if (dart.library.io) 'camera_bridge_source_io.dart'
    as real;

/// The five lines of the panel's checklist, in the order they are done.
enum BridgeLine { on, paired, recorder, cameras, talking }

/// What the panel shows, read from the bridge on this PC.
///
/// A null means "not known here", and the line stays pending rather than
/// claiming it. [fixes] holds the sentence for a line that is in trouble; a
/// line with a fix is drawn red with that sentence in place of its label.
class BridgePanelStatus {
  const BridgePanelStatus({
    this.on = false,
    this.startsWithWindows,
    this.paired = false,
    this.siteName,
    this.organization,
    this.recorderFoundAt,
    this.recorderHost,
    this.passwordSaved = false,
    this.camerasFound,
    this.lastOk,
    this.fixes = const {},
  });

  final bool on;
  final bool? startsWithWindows;
  final bool paired;
  final String? siteName;
  final String? organization;

  /// Where the last network scan found a recorder; null when none answered.
  final String? recorderFoundAt;

  /// The address typed and saved on this PC, with its login.
  final String? recorderHost;
  final bool passwordSaved;
  final int? camerasFound;
  final DateTime? lastOk;
  final Map<BridgeLine, String> fixes;
}

/// The panel's one dependency: the bridge on this PC, or a fake in tests.
///
/// The real one (camera_bridge_source_io.dart) wraps Monitoring's
/// `cameraBridge`, `CameraBridgeService.pair` and `BridgeStore`; on web the
/// stub says unsupported and the panel draws nothing.
abstract class BridgePanelSource extends ChangeNotifier {
  /// Only a Windows PC runs a bridge.
  bool get supported;

  BridgePanelStatus get status;

  /// Null when paired, or the sentence saying why not.
  Future<String?> pair(String code);

  /// Kept in this PC's keychain; never sent to EzBizi.
  Future<void> saveRecorder({
    required String host,
    required String username,
    required String password,
  });

  Future<void> setOn(bool on);
}

/// Settings › This computer › Camera bridge (UI queue 117, "The bridge on the
/// PC that stays on", option 1).
///
/// A checklist that ticks itself off, so whoever stands at the PC can see
/// where it stopped: on, paired, recorder, cameras, talking to EzBizi.
class CameraBridgePanel extends StatefulWidget {
  const CameraBridgePanel({
    super.key,
    required this.api,
    required this.session,
    this.source,
  });

  final ApiClient api;
  final Session session;

  /// Injected in tests; otherwise the real bridge on this PC.
  final BridgePanelSource? source;

  @override
  State<CameraBridgePanel> createState() => _CameraBridgePanelState();
}

class _CameraBridgePanelState extends State<CameraBridgePanel> {
  late final BridgePanelSource _source =
      widget.source ??
      real.realBridgeSource(widget.api, widget.session.me?.organization.name);

  final _code = TextEditingController();
  final _host = TextEditingController();
  final _user = TextEditingController();
  final _pass = TextEditingController();

  bool _pairing = false;
  String? _pairError;
  bool _saving = false;
  bool _editingRecorder = false;

  @override
  void initState() {
    super.initState();
    _source.addListener(_changed);
    _prefillHost();
  }

  @override
  void dispose() {
    _source.removeListener(_changed);
    if (widget.source == null) _source.dispose();
    for (final c in [_code, _host, _user, _pass]) {
      c.dispose();
    }
    super.dispose();
  }

  void _changed() {
    if (!mounted) return;
    _prefillHost();
    setState(() {});
  }

  /// The scan's recorder address, in the empty Address box.
  void _prefillHost() {
    final found = _source.status.recorderFoundAt;
    if (found != null && _host.text.isEmpty) _host.text = found;
  }

  Future<void> _pair() async {
    final code = _code.text.trim();
    if (code.isEmpty) return;
    setState(() {
      _pairing = true;
      _pairError = null;
    });
    final error = await _source.pair(code);
    if (!mounted) return;
    setState(() {
      _pairing = false;
      _pairError = error;
      if (error == null) _code.clear();
    });
  }

  Future<void> _saveRecorder() async {
    final host = _host.text.trim();
    if (host.isEmpty || _pass.text.isEmpty) return;
    setState(() => _saving = true);
    await _source.saveRecorder(
      host: host,
      username: _user.text.trim(),
      password: _pass.text,
    );
    if (!mounted) return;
    _pass.clear();
    setState(() {
      _saving = false;
      _editingRecorder = false;
    });
  }

  @override
  Widget build(BuildContext context) {
    if (!_source.supported) return const SizedBox.shrink();
    final theme = Theme.of(context);
    final s = _source.status;
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );

    return Align(
      alignment: Alignment.topLeft,
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 640),
        child: ListView(
          key: const ValueKey('camera-bridge-panel'),
          padding: const EdgeInsets.all(16),
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(
                    'Camera bridge',
                    style: theme.textTheme.titleMedium?.copyWith(
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                ),
                Switch(
                  key: const ValueKey('camera-bridge-on'),
                  value: s.on,
                  onChanged: (v) => _source.setOn(v),
                ),
                const SizedBox(width: 6),
                const Text('On'),
              ],
            ),
            const SizedBox(height: 4),
            Text(
              "This PC relays the site's cameras to EzBizi. Leave the app "
              'open and the PC on; it starts with Windows.',
              style: muted,
            ),
            const SizedBox(height: 12),
            Container(
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                border: Border.all(color: theme.colorScheme.outlineVariant),
                borderRadius: BorderRadius.circular(8),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  for (final line in BridgeLine.values) ...[
                    if (line != BridgeLine.on) const SizedBox(height: 10),
                    _line(context, s, line),
                  ],
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _line(BuildContext context, BridgePanelStatus s, BridgeLine line) {
    final fix = s.fixes[line];
    final n = line.index + 1;
    if (fix != null) return _row(context, n, _Mark.trouble, Text(fix));

    switch (line) {
      case BridgeLine.on:
        if (!s.on) {
          return _row(
            context,
            n,
            _Mark.pending,
            const Text('Turn on Camera bridge'),
          );
        }
        return _row(
          context,
          n,
          _Mark.done,
          Text(
            s.startsWithWindows == true
                ? 'Camera bridge is on, and starts with the PC'
                : 'Camera bridge is on',
          ),
        );
      case BridgeLine.paired:
        if (s.paired) {
          return _row(
            context,
            n,
            _Mark.done,
            Text.rich(
              TextSpan(
                children: [
                  const TextSpan(text: 'Paired with '),
                  TextSpan(
                    text: s.siteName ?? 'a camera site',
                    style: const TextStyle(fontWeight: FontWeight.w700),
                  ),
                  if (s.organization != null)
                    TextSpan(text: ' · ${s.organization}'),
                ],
              ),
            ),
          );
        }
        return _row(context, n, _Mark.pending, _pairBox(context));
      case BridgeLine.recorder:
        final saved = s.recorderHost != null && s.passwordSaved;
        if (saved && !_editingRecorder) {
          return _row(
            context,
            n,
            _Mark.done,
            Row(
              children: [
                Expanded(
                  child: Text(
                    s.recorderFoundAt != null
                        ? 'Recorder found at ${s.recorderFoundAt} · password '
                              'saved on this PC'
                        // Saved, but no scan has found it (yet): say which.
                        : 'Recorder at ${s.recorderHost} · password saved on '
                              'this PC',
                  ),
                ),
                TextButton(
                  key: const ValueKey('camera-bridge-recorder-change'),
                  onPressed: () => setState(() {
                    _host.text = s.recorderHost ?? s.recorderFoundAt ?? '';
                    _editingRecorder = true;
                  }),
                  child: const Text('Change'),
                ),
              ],
            ),
          );
        }
        return _row(
          context,
          n,
          _Mark.pending,
          _recorderForm(context, s.recorderFoundAt),
        );
      case BridgeLine.cameras:
        final count = s.camerasFound ?? 0;
        if (count > 0) {
          return _row(
            context,
            n,
            _Mark.done,
            Text(count == 1 ? '1 camera found' : '$count cameras found'),
          );
        }
        return _row(context, n, _Mark.pending, const Text('Cameras found'));
      case BridgeLine.talking:
        final last = s.lastOk;
        if (last != null) {
          return _row(
            context,
            n,
            _Mark.done,
            Text('Talking to EzBizi · last ${relativeAge(last)}'),
          );
        }
        return _row(context, n, _Mark.pending, const Text('Talking to EzBizi'));
    }
  }

  Widget _pairBox(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const Text(
          'Enter the pairing code shown in Settings › Monitoring › Camera '
          'sites',
        ),
        const SizedBox(height: 8),
        Row(
          children: [
            SizedBox(
              width: 180,
              child: TextField(
                key: const ValueKey('camera-bridge-code'),
                controller: _code,
                textCapitalization: TextCapitalization.characters,
                decoration: const InputDecoration(
                  labelText: 'Pairing code',
                  hintText: 'ABCD-2345',
                  isDense: true,
                ),
                onSubmitted: (_) => _pair(),
              ),
            ),
            const SizedBox(width: 10),
            FilledButton(
              key: const ValueKey('camera-bridge-pair'),
              onPressed: _pairing ? null : _pair,
              child: const Text('Pair'),
            ),
          ],
        ),
        if (_pairError != null) ...[
          const SizedBox(height: 8),
          ErrorNote(
            _pairError!,
            key: const ValueKey('camera-bridge-pair-error'),
          ),
        ],
      ],
    );
  }

  Widget _recorderForm(BuildContext context, String? foundAt) {
    final theme = Theme.of(context);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text(
          foundAt != null
              ? 'Recorder found at $foundAt. Enter its login'
              : "The recorder's address and login",
        ),
        const SizedBox(height: 8),
        TextField(
          key: const ValueKey('camera-bridge-recorder-host'),
          controller: _host,
          decoration: const InputDecoration(
            labelText: 'Address',
            hintText: '192.168.1.64',
            isDense: true,
          ),
        ),
        const SizedBox(height: 8),
        TextField(
          key: const ValueKey('camera-bridge-recorder-user'),
          controller: _user,
          decoration: const InputDecoration(
            labelText: 'Username',
            isDense: true,
          ),
        ),
        const SizedBox(height: 8),
        TextField(
          key: const ValueKey('camera-bridge-recorder-pass'),
          controller: _pass,
          obscureText: true,
          enableSuggestions: false,
          autocorrect: false,
          decoration: const InputDecoration(
            labelText: 'Password',
            isDense: true,
          ),
          onSubmitted: (_) => _saveRecorder(),
        ),
        const SizedBox(height: 8),
        Row(
          children: [
            Expanded(
              child: Text(
                'Kept on this PC only.',
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                ),
              ),
            ),
            if (_editingRecorder)
              TextButton(
                onPressed: () => setState(() {
                  _pass.clear();
                  _editingRecorder = false;
                }),
                child: const Text('Cancel'),
              ),
            const SizedBox(width: 8),
            FilledButton(
              key: const ValueKey('camera-bridge-recorder-save'),
              onPressed: _saving ? null : _saveRecorder,
              child: const Text('Save'),
            ),
          ],
        ),
      ],
    );
  }

  Widget _row(BuildContext context, int n, _Mark mark, Widget child) {
    final theme = Theme.of(context);
    final Widget icon = switch (mark) {
      _Mark.done => Icon(
        Icons.check_circle,
        size: 22,
        color: StateColour.of(context).on,
      ),
      _Mark.trouble => Icon(
        Icons.error,
        size: 22,
        color: theme.colorScheme.error,
      ),
      _Mark.pending => CircleAvatar(
        radius: 11,
        backgroundColor: theme.colorScheme.surfaceContainerHighest,
        child: Text(
          '$n',
          style: theme.textTheme.labelSmall?.copyWith(
            color: theme.colorScheme.onSurfaceVariant,
            fontWeight: FontWeight.w700,
          ),
        ),
      ),
    };
    return Row(
      key: ValueKey('camera-bridge-line-$n'),
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        KeyedSubtree(
          key: ValueKey('camera-bridge-line-$n-${mark.name}'),
          child: icon,
        ),
        const SizedBox(width: 10),
        Expanded(
          child: Padding(padding: const EdgeInsets.only(top: 2), child: child),
        ),
      ],
    );
  }
}

enum _Mark { done, pending, trouble }
