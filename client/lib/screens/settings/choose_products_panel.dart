import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/theme.dart';
import '../../widgets/side_panel.dart';
import '../email/consent_window.dart';

/// "Choose what to connect" (UI queue 183, option 1 / phone 4). Jose,
/// 2026-11-05: "when for example i click Meta - Facebook it asks me what
/// connections from facebook i want to make, before proceeding to the
/// connection".
///
/// Generic over the catalogue's `products[]`: a family the server lists two or
/// more products for gets this panel, whatever it is.
///
/// - Ticking products and Continue signs in once, asking only for those
///   (`POST start {products}`).
/// - A product with a sign-in of its own (WhatsApp's Embedded Signup) is run
///   after, on its own click: a window opened after an await is one the
///   browser blocks.
/// - Unticking a connected product is an inline red two-step, then
///   [onRemove], which asks for the authenticator code.
class ChooseProductsPanel extends StatefulWidget {
  const ChooseProductsPanel({
    super.key,
    required this.api,
    required this.label,
    required this.startPath,
    required this.products,
    required this.onRemove,
    required this.onChanged,
    this.preTick = const {},
    this.openConsent = openConsentWindow,
    this.onElsewhere,
  });

  final ApiClient api;
  final String label;

  /// The head's sign-in. Products with another start path sign in after.
  final String startPath;
  final List<Map<String, dynamic>> products;

  /// Removes one product; answers the server's response, or null when the
  /// person backed out of the code.
  final Future<ApiResponse?> Function(String key) onRemove;
  final Future<void> Function() onChanged;

  /// Ticked on opening besides what is connected: the product whose "Add
  /// access" opened the panel.
  final Set<String> preTick;
  final OpenConsentWindow openConsent;

  /// Opens where a product is set up when no sign-in asks for it (Gmail:
  /// Email's Workspace setup). Null leaves the line without a button.
  final void Function(Map<String, dynamic> product)? onElsewhere;

  @override
  State<ChooseProductsPanel> createState() => _ChooseProductsPanelState();
}

class _ChooseProductsPanelState extends State<ChooseProductsPanel> {
  late final Set<String> _connected = {
    for (final p in widget.products)
      if (p['connected'] == true) _key(p),
  };
  late final Set<String> _ticked = {
    ..._connected,
    ...widget.preTick,
    // A first sign-in starts with everything its own sign-in brings ticked.
    if (_connected.isEmpty && widget.preTick.isEmpty)
      for (final p in widget.products)
        if (!_separate(p)) _key(p),
  };

  /// Set up somewhere else, never at a sign-in: no scopes of its own and
  /// another start path (Gmail → `/email/workspace`, Leads + Google 6262b4928).
  bool _elsewhere(Map<String, dynamic> p) =>
      _separate(p) && ((p['scopes'] as List<dynamic>?) ?? const []).isEmpty;

  /// "Email" from `/email/workspace`: the module the setup lives in.
  static String _where(Map<String, dynamic> p) {
    final first = ((p['start_path'] as String?) ?? '')
        .split('/')
        .firstWhere((s) => s.isNotEmpty, orElse: () => '');
    return first.isEmpty
        ? 'its own screen'
        : '${first[0].toUpperCase()}${first.substring(1)}';
  }

  /// A connected product unticked, waiting on Confirm.
  String? _removing;
  bool _busy = false;
  String? _error;
  String? _waitingFor;
  List<String> _problems = const [];
  Timer? _poll;

  static String _key(Map<String, dynamic> p) => p['key'] as String? ?? '';
  static String _label(Map<String, dynamic> p) =>
      p['label'] as String? ?? _key(p);

  bool _separate(Map<String, dynamic> p) {
    final path = p['start_path'] as String?;
    return path != null && path.isNotEmpty && path != widget.startPath;
  }

  @override
  void dispose() {
    _poll?.cancel();
    super.dispose();
  }

  List<Map<String, dynamic>> get _toAdd => [
    for (final p in widget.products)
      if (_ticked.contains(_key(p)) &&
          !_connected.contains(_key(p)) &&
          !_separate(p))
        p,
  ];

  /// Ticked products with a sign-in of their own, still to run.
  List<Map<String, dynamic>> get _after => [
    for (final p in widget.products)
      if (_ticked.contains(_key(p)) &&
          !_connected.contains(_key(p)) &&
          _separate(p) &&
          !_elsewhere(p))
        p,
  ];

  void _toggle(Map<String, dynamic> p, bool on) {
    final key = _key(p);
    setState(() {
      if (on) {
        _ticked.add(key);
        if (_removing == key) _removing = null;
      } else {
        _ticked.remove(key);
        if (_connected.contains(key)) _removing = key;
      }
    });
  }

  Future<void> _remove(String key) async {
    setState(() => _busy = true);
    final response = await widget.onRemove(key);
    if (!mounted) return;
    setState(() {
      _busy = false;
      if (response == null) return;
      if (!response.ok) {
        _error = response.reason;
        return;
      }
      _connected.remove(key);
      _removing = null;
      final data = response.data;
      final problems = data is Map<String, dynamic> ? data['problems'] : null;
      _problems = [
        ..._problems,
        if (problems is List) ...problems.whereType<String>(),
      ];
    });
    await widget.onChanged();
  }

  Future<void> _signIn(String path, String label, List<String> keys) async {
    _poll?.cancel();
    _poll = await runConsentFlow(
      api: widget.api,
      openConsent: widget.openConsent,
      startPath: path,
      label: label,
      refusedFallback: '$label did not finish the sign-in.',
      body: path == widget.startPath ? {'products': keys} : const {},
      isMounted: () => mounted,
      onStart: () => setState(() {
        _busy = true;
        _error = null;
        _waitingFor = label;
      }),
      onEnd: (error) => setState(() {
        _busy = false;
        _waitingFor = null;
        _error = error;
      }),
      onConnected: () async {
        setState(() => _connected.addAll(keys));
        await widget.onChanged();
      },
    );
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final bad = theme.colorScheme.error;
    final caution = StateColour.of(context).caution;
    final add = _toAdd;
    final after = _after;

    final Widget? next = add.isNotEmpty
        ? FilledButton(
            key: const ValueKey('choose-continue'),
            onPressed: _busy
                ? null
                : () => _signIn(widget.startPath, widget.label, [
                    for (final p in add) _key(p),
                  ]),
            child: Text('Continue to ${widget.label}'),
          )
        : after.isNotEmpty
        ? FilledButton(
            key: ValueKey('choose-continue:${_key(after.first)}'),
            onPressed: _busy
                ? null
                : () => _signIn(
                    after.first['start_path'] as String,
                    _label(after.first),
                    [_key(after.first)],
                  ),
            child: Text('Continue to ${_label(after.first)}'),
          )
        : null;

    return SidePanel(
      title: 'Choose what to connect',
      subtitle: widget.label,
      actions: [?next],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            'Tick what EzBizi may use. ${widget.label} asks only for what is '
            'ticked, and you can come back to add or remove any of it.',
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
          const SizedBox(height: 12),
          for (final p in widget.products) ...[
            _product(theme, p),
            if (_removing == _key(p)) _confirmRemove(theme, bad, p),
          ],
          if (add.isNotEmpty && after.isNotEmpty)
            Padding(
              padding: const EdgeInsets.only(top: 8),
              child: Text(
                '${after.map(_label).join(', ')} '
                '${after.length == 1 ? 'has' : 'have'} a sign-in of '
                '${after.length == 1 ? 'its' : 'their'} own, next.',
                style: theme.textTheme.bodySmall,
              ),
            ),
          if (_waitingFor != null)
            Padding(
              padding: const EdgeInsets.only(top: 12),
              child: Text('Waiting for $_waitingFor…'),
            ),
          for (final line in _problems)
            Padding(
              padding: const EdgeInsets.only(top: 8),
              child: Text(
                line,
                key: const ValueKey('choose-problem'),
                style: theme.textTheme.bodySmall?.copyWith(color: caution),
              ),
            ),
          if (_error != null)
            Padding(
              padding: const EdgeInsets.only(top: 8),
              child: Text(_error!, style: TextStyle(color: bad)),
            ),
        ],
      ),
    );
  }

  Widget _product(ThemeData theme, Map<String, dynamic> p) {
    final key = _key(p);
    final scopes = ((p['scopes'] as List<dynamic>?) ?? const [])
        .whereType<String>()
        .toList();
    final description = p['description'] as String?;

    if (_elsewhere(p)) {
      return Padding(
        key: ValueKey('choose:$key'),
        padding: const EdgeInsets.fromLTRB(48, 6, 0, 6),
        child: Row(
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(_label(p), style: theme.textTheme.titleSmall),
                  Text(
                    _connected.contains(key)
                        ? 'Connected. Managed in ${_where(p)}, not at this '
                              'sign-in.'
                        : 'Set up in ${_where(p)}, not at this sign-in.',
                    style: theme.textTheme.bodySmall,
                  ),
                ],
              ),
            ),
            if (widget.onElsewhere != null)
              TextButton(
                key: ValueKey('choose-elsewhere:$key'),
                onPressed: () => widget.onElsewhere!(p),
                child: Text('Open ${_where(p)}'),
              ),
          ],
        ),
      );
    }

    return InkWell(
      key: ValueKey('choose:$key'),
      onTap: _busy ? null : () => _toggle(p, !_ticked.contains(key)),
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 6),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Checkbox(
              value: _ticked.contains(key),
              onChanged: _busy ? null : (v) => _toggle(p, v ?? false),
            ),
            const SizedBox(width: 4),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Padding(
                    padding: const EdgeInsets.only(top: 12),
                    child: Text(_label(p), style: theme.textTheme.titleSmall),
                  ),
                  if (description != null && description.isNotEmpty)
                    Text(description, style: theme.textTheme.bodySmall),
                  if (scopes.isNotEmpty)
                    Text(
                      'Asks for: ${scopes.join(', ')}',
                      style: theme.textTheme.labelSmall?.copyWith(
                        color: theme.colorScheme.onSurfaceVariant,
                      ),
                    ),
                ],
              ),
            ),
            if (_connected.contains(key))
              Padding(
                padding: const EdgeInsets.only(top: 12, left: 8),
                child: Text(
                  'Connected',
                  style: theme.textTheme.labelSmall?.copyWith(
                    color: StateColour.of(context).on,
                  ),
                ),
              ),
          ],
        ),
      ),
    );
  }

  Widget _confirmRemove(ThemeData theme, Color bad, Map<String, dynamic> p) {
    final key = _key(p);

    return Container(
      key: ValueKey('choose-remove:$key'),
      margin: const EdgeInsets.only(left: 44, bottom: 8),
      padding: const EdgeInsets.all(10),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(8),
        border: Border.all(color: bad.withValues(alpha: 0.5)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            'Remove ${_label(p)}? EzBizi stops using it.',
            style: theme.textTheme.labelLarge?.copyWith(color: bad),
          ),
          if (_separate(p))
            Text(
              key == 'whatsapp'
                  ? 'The number stops working in EzBizi, and bringing it '
                        'back means registering the number again.'
                  : 'It has a sign-in of its own: bringing it back means '
                        'setting it up again from the start.',
              style: theme.textTheme.bodySmall?.copyWith(color: bad),
            ),
          const SizedBox(height: 8),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              FilledButton(
                key: ValueKey('choose-remove-go:$key'),
                style: FilledButton.styleFrom(
                  backgroundColor: bad,
                  foregroundColor: theme.colorScheme.onError,
                ),
                onPressed: _busy ? null : () => _remove(key),
                child: Text('Remove ${_label(p)}'),
              ),
              OutlinedButton(
                key: ValueKey('choose-remove-cancel:$key'),
                onPressed: _busy ? null : () => _toggle(p, true),
                child: const Text('Keep it'),
              ),
            ],
          ),
        ],
      ),
    );
  }
}
