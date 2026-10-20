import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../core/api_client.dart';
import '../core/session.dart';
import '../core/theme.dart';
import 'search_picker.dart';
import 'status_pill.dart';

/// The Workiz web login: a signed-in Workiz session, handed to EzBizi through
/// the EzBizi browser extension (UI queue 120, option 1 and phone 4).
///
/// One per organization, provider `workiz_web`. Three steps: get a link (good
/// for 30 minutes), paste it into the extension in the browser that is signed
/// in to Workiz, and wait — this polls the connections every 5 seconds while
/// the link is live and turns green on its own. Under it, every Workiz account
/// the login reaches, each filed on a branch.
///
/// Every call tolerates a server without the routes: a 404 says "Not available
/// on this server yet" rather than an error.
class WorkizSessionLogin extends StatefulWidget {
  const WorkizSessionLogin({
    super.key,
    required this.api,
    this.branches = const [],
    this.onConnected,
  });

  final ApiClient api;

  /// The branches an account can be filed on: the current view's.
  final List<FranchiseSummary> branches;

  /// Called once the session arrives, so the page's list can reload.
  final VoidCallback? onConnected;

  static const disclaimer =
      "EzBizi reads Workiz through your signed-in session because Workiz's "
      "API doesn't offer these reports. Workiz's terms may not allow automated "
      'use of its website; use at your own discretion.';

  @override
  State<WorkizSessionLogin> createState() => _WorkizSessionLoginState();
}

class _WorkizSessionLoginState extends State<WorkizSessionLogin> {
  static const _life = 30 * 60;

  /// The `workiz_web` row, or null when none is connected.
  Map<String, dynamic>? _row;
  bool _unavailable = false;
  String? _failure;

  String? _link;
  int _left = 0;
  bool _copied = false;
  bool _fetching = false;

  /// Send it again pressed on a signed-out session: the steps come back.
  bool _again = false;

  List<Map<String, dynamic>>? _accounts;
  Timer? _ticker;

  bool get _connected => _row?['status'] == 'connected';

  @override
  void initState() {
    super.initState();
    _refresh();
  }

  @override
  void dispose() {
    _ticker?.cancel();
    super.dispose();
  }

  static List<dynamic> _list(Object? body) => body is List
      ? body
      : (body is Map ? (body['data'] as List<dynamic>? ?? const []) : const []);

  /// Reads the `workiz_web` row; answers whether it is connected.
  Future<bool> _refresh() async {
    final response = await widget.api.get('/social/connections', {
      'include': 'data_sources',
    });
    if (!mounted || !response.ok) return false;
    final row = _list(response.body)
        .whereType<Map<String, dynamic>>()
        .where((c) => c['provider'] == 'workiz_web')
        .firstOrNull;
    final was = _connected;
    setState(() => _row = row);
    if (row != null) await _loadAccounts();
    if (_connected && !was) {
      _ticker?.cancel();
      if (mounted) {
        setState(() {
          _link = null;
          _again = false;
        });
      }
      widget.onConnected?.call();
    }
    return _connected;
  }

  Future<void> _loadAccounts() async {
    final id = _row?['id'];
    if (id == null) return;
    final response = await widget.api.get('/social/workiz-web/$id/accounts');
    if (!mounted) return;
    if (response.statusCode == 404) {
      setState(() => _unavailable = true);
      return;
    }
    if (!response.ok) return;
    setState(
      () => _accounts = _list(
        response.body,
      ).whereType<Map<String, dynamic>>().toList(),
    );
  }

  Future<void> _getLink() async {
    setState(() {
      _fetching = true;
      _failure = null;
      _copied = false;
    });
    final response = await widget.api.post('/social/workiz-web/link', {});
    if (!mounted) return;
    if (response.statusCode == 404) {
      setState(() {
        _fetching = false;
        _unavailable = true;
      });
      return;
    }
    if (!response.ok) {
      setState(() {
        _fetching = false;
        _failure = response.message;
      });
      return;
    }
    final body = response.body['data'] is Map
        ? response.body['data'] as Map
        : response.body;
    final expires = DateTime.tryParse('${body['expires_at'] ?? ''}');
    setState(() {
      _fetching = false;
      _link = '${body['link'] ?? ''}';
      _left = expires == null
          ? _life
          : expires.difference(DateTime.now()).inSeconds.clamp(0, _life);
    });
    _ticker?.cancel();
    _ticker = Timer.periodic(const Duration(seconds: 1), (timer) {
      if (!mounted) return timer.cancel();
      setState(() => _left--);
      if (_left <= 0) {
        timer.cancel();
        return;
      }
      if (_left % 5 == 0) _refresh();
    });
  }

  Future<void> _copy() async {
    try {
      await Clipboard.setData(ClipboardData(text: _link ?? ''));
    } catch (_) {
      // No clipboard on this platform; the link is still selectable.
    }
    if (mounted) setState(() => _copied = true);
  }

  Future<void> _file(Map<String, dynamic> account, String? branch) async {
    final response = await widget.api.put(
      '/social/workiz-web/${_row!['id']}/accounts',
      {'account': account['account'], 'franchise_id': branch},
    );
    if (!mounted) return;
    if (response.statusCode == 404) {
      setState(() => _unavailable = true);
      return;
    }
    if (!response.ok) {
      setState(() => _failure = response.message);
      return;
    }
    setState(() {
      _failure = null;
      account['franchise_id'] = branch;
    });
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final small = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );

    return LayoutBuilder(
      builder: (context, box) {
        final phone = box.maxWidth < 600;
        final steps = _row == null || _again || (!_connected && _link != null);

        return Column(
          key: const ValueKey('workiz-session'),
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            if (_unavailable)
              Text(
                'Not available on this server yet.',
                key: const ValueKey('workiz-session-unavailable'),
                style: small,
              )
            else ...[
              if (_row != null) _status(theme, small),
              if (steps && phone)
                Padding(
                  padding: const EdgeInsets.only(top: 8),
                  child: Text(
                    'Sending the session needs the EzBizi extension on a '
                    'computer.',
                    key: const ValueKey('workiz-session-phone'),
                    style: small,
                  ),
                )
              else if (steps)
                _steps(theme, small),
              if (_failure != null)
                Padding(
                  padding: const EdgeInsets.only(top: 8),
                  child: Text(
                    _failure!,
                    style: small?.copyWith(color: theme.colorScheme.error),
                  ),
                ),
              if (_row != null && _accounts != null) _table(theme, small),
            ],
            const SizedBox(height: 12),
            Text(
              WorkizSessionLogin.disclaimer,
              key: const ValueKey('workiz-session-disclaimer'),
              style: small,
            ),
          ],
        );
      },
    );
  }

  Widget _status(ThemeData theme, TextStyle? small) {
    final reason = _row?['status_reason'] as String?;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            Text('Workiz web login', style: theme.textTheme.titleSmall),
            const SizedBox(width: 8),
            _connected
                ? const StatusPill('Connected', tone: Tone.ok)
                : const StatusPill('Signed out', tone: Tone.bad),
          ],
        ),
        if (!_connected) ...[
          if ((reason ?? '').isNotEmpty) ...[
            const SizedBox(height: 4),
            Text(
              reason!,
              key: const ValueKey('workiz-session-reason'),
              style: small?.copyWith(color: theme.colorScheme.error),
            ),
          ],
          if (!_again && _link == null) ...[
            const SizedBox(height: 8),
            FilledButton(
              key: const ValueKey('workiz-session-again'),
              onPressed: () => setState(() => _again = true),
              child: const Text('Send it again'),
            ),
          ],
        ],
      ],
    );
  }

  Widget _step(
    ThemeData theme,
    int n,
    bool done,
    String title,
    List<Widget> body,
  ) {
    final scheme = theme.colorScheme;
    final tone = ToneColours.of(context, Tone.ok);

    return Padding(
      padding: const EdgeInsets.only(top: 10),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          CircleAvatar(
            radius: 11,
            backgroundColor: done ? tone.fill : scheme.primary,
            foregroundColor: done ? tone.ink : scheme.onPrimary,
            child: done
                ? const Icon(Icons.check, size: 14)
                : Text(
                    '$n',
                    style: const TextStyle(
                      fontSize: 11,
                      fontWeight: FontWeight.w800,
                    ),
                  ),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(title, style: theme.textTheme.titleSmall),
                ...body,
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _steps(ThemeData theme, TextStyle? small) {
    final link = _link;
    final live = link != null && _left > 0;
    final clock =
        '${_left ~/ 60}:${(_left % 60).toString().padLeft(2, '0')} left';

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _step(theme, 1, live, 'Get a link', [
          const SizedBox(height: 4),
          if (link == null)
            Align(
              alignment: Alignment.centerLeft,
              child: FilledButton(
                key: const ValueKey('workiz-session-get'),
                onPressed: _fetching ? null : _getLink,
                child: Text(_fetching ? 'Getting a link…' : 'Get a link'),
              ),
            )
          else ...[
            Row(
              children: [
                Flexible(
                  child: Container(
                    padding: const EdgeInsets.symmetric(
                      horizontal: 8,
                      vertical: 5,
                    ),
                    decoration: BoxDecoration(
                      color: theme.colorScheme.surfaceContainerHighest,
                      border: Border.all(color: EzBiziTheme.edge(context)),
                      borderRadius: BorderRadius.circular(6),
                    ),
                    child: SelectableText(
                      link,
                      key: const ValueKey('workiz-session-link'),
                      maxLines: 1,
                      style: theme.textTheme.bodySmall?.copyWith(
                        fontFamily: 'monospace',
                      ),
                    ),
                  ),
                ),
                const SizedBox(width: 8),
                OutlinedButton(
                  key: const ValueKey('workiz-session-copy'),
                  onPressed: _copy,
                  child: Text(_copied ? 'Copied' : 'Copy'),
                ),
              ],
            ),
            const SizedBox(height: 4),
            Wrap(
              crossAxisAlignment: WrapCrossAlignment.center,
              children: [
                Text(
                  live
                      ? 'Good for 30 minutes · $clock'
                      : 'This link has run out.',
                  key: const ValueKey('workiz-session-clock'),
                  style: small,
                ),
                TextButton(
                  key: const ValueKey('workiz-session-new'),
                  onPressed: _fetching ? null : _getLink,
                  child: const Text('Get a new one'),
                ),
              ],
            ),
          ],
        ]),
        _step(theme, 2, false, 'Send your Workiz session', [
          Text(
            "In the browser where you're signed in to Workiz, click the "
            'EzBizi extension, paste the link, press Send.',
            style: small,
          ),
        ]),
        _step(theme, 3, false, 'Waiting for it…', [
          Text('This page turns green on its own.', style: small),
        ]),
      ],
    );
  }

  Widget _table(ThemeData theme, TextStyle? small) {
    final accounts = _accounts!;
    final options = [
      for (final b in widget.branches)
        SearchOption<String>(value: b.id, label: b.name),
    ];

    return Padding(
      padding: const EdgeInsets.only(top: 12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Expanded(child: Text('WORKIZ ACCOUNT', style: small)),
              const SizedBox(width: 12),
              Expanded(child: Text('BRANCH', style: small)),
            ],
          ),
          if (accounts.isEmpty)
            Padding(
              padding: const EdgeInsets.only(top: 6),
              child: Text(
                'No Workiz accounts reached by this login yet.',
                style: small,
              ),
            ),
          for (final account in accounts)
            Padding(
              padding: const EdgeInsets.only(top: 6),
              child: Row(
                children: [
                  Expanded(
                    child: Text(
                      '${account['name'] ?? account['account'] ?? ''}',
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: SearchPickerField<String>(
                      key: ValueKey(
                        'workiz-session-branch:${account['account']}',
                      ),
                      value: account['franchise_id'] as String?,
                      options: options,
                      hint: 'Choose a branch',
                      isDense: true,
                      errorText: account['franchise_id'] == null ? '' : null,
                      onChanged: (branch) => _file(account, branch),
                    ),
                  ),
                ],
              ),
            ),
          if (accounts.any((a) => a['franchise_id'] == null))
            Padding(
              padding: const EdgeInsets.only(top: 6),
              child: Text(
                "Payments from an account with no branch aren't filed "
                'anywhere.',
                style: small,
              ),
            ),
        ],
      ),
    );
  }
}
