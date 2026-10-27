import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../core/api_client.dart';
import 'side_panel.dart';

/// The one confirmation every integration Disconnect goes through.
///
/// Jose, 2026-10-21: "disconnecting an integration should require an
/// authenticator code, and also if possible list the amount of services -
/// automations - etc that will stop working". So the panel reads
/// `GET /integrations/{kind}/{id}/dependents` (when [dependentsPath] is given),
/// lists what stops, and asks for the 6-digit code the server insists on.
/// Disconnect is enabled only with six digits; a refusal is shown inline and
/// the panel stays open. It pops with the successful [ApiResponse], or null.
Future<ApiResponse?> confirmDisconnect(
  BuildContext context, {
  required ApiClient api,
  required String title,
  String? subtitle,
  required String explanation,
  String? dependentsPath,
  required Future<ApiResponse> Function(String code) perform,
}) {
  return showSidePanel<ApiResponse>(
    context,
    builder: (_) => DisconnectPanel(
      api: api,
      title: title,
      subtitle: subtitle,
      explanation: explanation,
      dependentsPath: dependentsPath,
      perform: perform,
    ),
  );
}

class DisconnectPanel extends StatefulWidget {
  const DisconnectPanel({
    super.key,
    required this.api,
    required this.title,
    this.subtitle,
    required this.explanation,
    this.dependentsPath,
    required this.perform,
  });

  final ApiClient api;
  final String title;
  final String? subtitle;
  final String explanation;
  final String? dependentsPath;
  final Future<ApiResponse> Function(String code) perform;

  @override
  State<DisconnectPanel> createState() => _DisconnectPanelState();
}

class _DisconnectPanelState extends State<DisconnectPanel> {
  final _code = TextEditingController();
  bool _loading = false;
  bool _busy = false;
  String? _error;
  String? _dependentsError;
  List<Map<String, dynamic>> _dependents = const [];
  List<Map<String, dynamic>> _features = const [];

  @override
  void initState() {
    super.initState();
    _code.addListener(() => setState(() {}));
    _loadDependents();
  }

  @override
  void dispose() {
    _code.dispose();
    super.dispose();
  }

  Future<void> _loadDependents() async {
    final path = widget.dependentsPath;
    if (path == null) return;
    setState(() => _loading = true);
    final response = await widget.api.get(path);
    if (!mounted) return;
    setState(() {
      _loading = false;
      if (response.ok) {
        final data = response.data as Map?;
        List<Map<String, dynamic>> rows(String key) =>
            ((data?[key] as List?) ?? const [])
                .whereType<Map>()
                .map((r) => Map<String, dynamic>.from(r))
                .toList();
        _dependents = rows('dependents');
        // What stops (breaks) or gets worse (degrades), whether or not
        // anything is counted against this connection today.
        _features = rows('features');
      } else {
        _dependentsError = response.reason;
      }
    });
  }

  bool get _ready => RegExp(r'^\d{6}$').hasMatch(_code.text) && !_busy;

  Future<void> _go() async {
    if (!_ready) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    final response = await widget.perform(_code.text);
    if (!mounted) return;
    if (response.ok) {
      Navigator.of(context).pop(response);
      return;
    }
    setState(() {
      _busy = false;
      _error = response.reason;
      _code.clear();
    });
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );

    return SidePanel(
      title: widget.title,
      subtitle: widget.subtitle,
      actions: [
        TextButton(
          key: const Key('disconnect-keep'),
          onPressed: _busy ? null : () => Navigator.of(context).pop(),
          child: const Text('Keep it'),
        ),
        const SizedBox(width: 8),
        FilledButton(
          key: const Key('disconnect-confirm'),
          onPressed: _ready ? _go : null,
          child: Text(_busy ? 'Disconnecting…' : 'Disconnect'),
        ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(widget.explanation),
          if (widget.dependentsPath != null) ...[
            const SizedBox(height: 16),
            Text('What stops working', style: theme.textTheme.titleSmall),
            const SizedBox(height: 6),
            if (_loading)
              Text('Checking…', style: muted)
            else if (_dependentsError != null)
              Text(_dependentsError!, style: muted)
            else if (_dependents.isEmpty && _features.isEmpty)
              Text(
                'Nothing else in EzBizi depends on this connection.',
                key: const Key('disconnect-no-dependents'),
                style: muted,
              )
            else ...[
              for (final (i, row) in _features.indexed)
                _FeatureRow(key: ValueKey('disconnect-feature-$i'), row: row),
              if (_features.isNotEmpty && _dependents.isNotEmpty)
                const SizedBox(height: 8),
              for (final row in _dependents) _DependentRow(row: row),
            ],
          ],
          const SizedBox(height: 20),
          Text(
            'Enter the 6-digit code from your authenticator app to disconnect.',
            style: muted,
          ),
          const SizedBox(height: 8),
          TextField(
            key: const Key('disconnect-code'),
            controller: _code,
            enabled: !_busy,
            autofocus: true,
            keyboardType: TextInputType.number,
            textAlign: TextAlign.center,
            maxLength: 6,
            inputFormatters: [FilteringTextInputFormatter.digitsOnly],
            style: theme.textTheme.headlineSmall?.copyWith(letterSpacing: 8),
            decoration: const InputDecoration(
              counterText: '',
              hintText: '······',
              border: OutlineInputBorder(),
            ),
            onSubmitted: (_) => _go(),
          ),
          if (_error != null) ...[
            const SizedBox(height: 8),
            Text(
              _error!,
              key: const Key('disconnect-error'),
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.error,
              ),
            ),
          ],
        ],
      ),
    );
  }
}

/// One feature line, e.g. "Marketing › Posts: can't publish to X", marked as
/// stopping outright (breaks) or working with less (degrades).
class _FeatureRow extends StatelessWidget {
  const _FeatureRow({super.key, required this.row});

  final Map<String, dynamic> row;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final breaks = row['severity'] == 'breaks';
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            breaks ? 'Stops' : 'Limited',
            style: theme.textTheme.labelSmall?.copyWith(
              color: breaks
                  ? theme.colorScheme.error
                  : theme.colorScheme.onSurfaceVariant,
              fontWeight: FontWeight.w600,
            ),
          ),
          const SizedBox(width: 8),
          Expanded(child: Text('${row['feature'] ?? ''}')),
        ],
      ),
    );
  }
}

class _DependentRow extends StatelessWidget {
  const _DependentRow({required this.row});

  final Map<String, dynamic> row;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final names =
        (row['names'] as List?)?.map((n) => '$n').toList() ?? const [];
    return Padding(
      key: ValueKey('disconnect-dependent-${row['key']}'),
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('• ${row['label'] ?? ''}'),
          if (names.isNotEmpty)
            Padding(
              padding: const EdgeInsets.only(left: 12),
              child: Text(
                names.join(', '),
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                ),
              ),
            ),
        ],
      ),
    );
  }
}
