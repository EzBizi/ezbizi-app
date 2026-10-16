import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/session.dart';
import '../widgets/async_body.dart';
import '../widgets/connect_account.dart';
import '../widgets/connected_account.dart';

/// Business Profile — the listings the public sees on Maps.
///
/// ── What is NOT showing leads the screen ─────────────────────
///
/// A suspended or unverified listing looks perfectly healthy in a database and
/// is invisible on Google. Nobody discovers that from a list of names — they
/// discover it when a customer says they could not find the business. So the
/// count of listings Google is not showing sits at the top, and those rows are
/// marked rather than left for somebody to notice.
class BusinessProfileScreen extends StatefulWidget {
  const BusinessProfileScreen({
    super.key,
    required this.api,
    required this.session,
  });

  final ApiClient api;
  final Session session;

  @override
  State<BusinessProfileScreen> createState() => _BusinessProfileScreenState();
}

class _BusinessProfileScreenState extends State<BusinessProfileScreen> {
  bool _loading = true;
  bool _syncing = false;
  String? _error;
  String? _notice;

  List<Map<String, dynamic>> _locations = [];
  List<Map<String, dynamic>> _connections = [];
  int _total = 0;
  int _notLive = 0;
  bool _onlyNotLive = false;
  int _request = 0;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final request = ++_request;

    setState(() {
      _loading = _locations.isEmpty;
      _error = null;
    });

    final response = await widget.api.get('/business-profile/locations', {
      if (_onlyNotLive) 'live': 'no',
    });

    if (!mounted || request != _request) return;

    setState(() {
      _loading = false;

      if (!response.ok) {
        _error = response.message;
        return;
      }

      _locations = List<Map<String, dynamic>>.from(
        (response.data as List? ?? const []).cast<Map<String, dynamic>>(),
      );
      final meta = response.body['meta'] as Map<String, dynamic>? ?? const {};
      _total = (meta['total'] as num?)?.toInt() ?? _locations.length;
      _notLive = (meta['not_live'] as num?)?.toInt() ?? 0;
      _connections = List<Map<String, dynamic>>.from(
        (meta['connections'] as List? ?? const []).cast<Map<String, dynamic>>(),
      );
    });
  }

  Future<void> _sync() async {
    setState(() {
      _syncing = true;
      _notice = null;
    });

    final response = await widget.api.post('/business-profile/sync', const {});

    if (!mounted) return;
    setState(() => _syncing = false);

    if (!response.ok) {
      // Carries the server's own sentence — including "Google has not granted
      // permission yet", which is a wait rather than a fault and must not read
      // like one.
      setState(() => _notice = response.message);
      return;
    }

    final data = response.data as Map<String, dynamic>? ?? const {};
    final fetched = (data['fetched'] as num?)?.toInt() ?? 0;

    setState(() => _notice =
        fetched > 0 ? 'Checked $fetched listing${fetched == 1 ? '' : 's'}.' : 'Nothing to check.');

    await _load();
  }

  bool get _connected => _connections.any((c) => c['status'] == 'connected');

  /// The connected profile itself, so the tab can name it and remove it.
  Map<String, dynamic>? get _profile =>
      _connections.isEmpty ? null : _connections.first;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 8),
          child: Row(
            children: [
              FilterChip(
                key: const ValueKey('tour-business-profile-not-showing'),
                label: Text(
                  _notLive > 0 ? 'Not showing ($_notLive)' : 'Not showing',
                ),
                selected: _onlyNotLive,
                onSelected: (v) {
                  setState(() => _onlyNotLive = v);
                  _load();
                },
              ),
              const Spacer(),
              FilledButton.tonalIcon(
                key: const ValueKey('tour-customer_experience-check-google'),
                onPressed: _syncing ? null : _sync,
                icon: _syncing
                    ? const SizedBox(
                        width: 16, height: 16,
                        child: CircularProgressIndicator(strokeWidth: 2))
                    : const Icon(Icons.sync, size: 18),
                label: Text(_syncing ? 'Checking…' : 'Check Google'),
              ),
            ],
          ),
        ),
        if (_profile != null)
          ConnectedAccount(
            key: const ValueKey('tour-business-profile-account'),
            connection: _profile!,
          ),
        if (_notice != null)
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
            child: Row(
              children: [
                Expanded(child: Text(_notice!, style: theme.textTheme.bodySmall)),
                IconButton(
                  icon: const Icon(Icons.close, size: 16),
                  onPressed: () => setState(() => _notice = null),
                ),
              ],
            ),
          ),
        const Divider(height: 1),
        Expanded(
          child: AsyncBody(
            loading: _loading,
            error: _error,
            onRetry: _load,
            child: _locations.isEmpty ? _empty(theme) : _list(theme),
          ),
        ),
      ],
    );
  }

  Widget _empty(ThemeData theme) {
    return Center(
      key: const ValueKey('tour-business-profile-empty'),
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 420),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(
              _connected ? Icons.storefront_outlined : Icons.link_off,
              size: 40,
              color: theme.colorScheme.outline,
            ),
            const SizedBox(height: 12),
            Text(
              _connected
                  ? (_onlyNotLive ? 'Every listing is showing' : 'No listings yet')
                  : 'No Business Profile connected',
              style: theme.textTheme.titleMedium,
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 8),
            Text(
              _connected
                  ? (_onlyNotLive
                      ? 'Google is showing all of them. Nothing needs attention here.'
                      : 'The profile is connected but no listings have come back yet. '
                          'Check Google fetches them.')
                  : 'This is where the listings the public sees on Google Maps appear — '
                      'name, address, hours, and whether Google is showing them at all. '
                      'Connect the profile this organization already owns.',
              style: theme.textTheme.bodySmall
                  ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
              textAlign: TextAlign.center,
            ),
            if (!_connected) ...[
              const SizedBox(height: 18),
              ConnectAccount(
                api: widget.api,
                label: 'Business Profile',
                startPath: '/social/business-profile/start',
                onConnected: _load,
              ),
            ],
          ],
        ),
      ),
    );
  }

  Widget _list(ThemeData theme) {
    return ListView.separated(
      key: const ValueKey('tour-business-profile-listings'),
      itemCount: _locations.length + 1,
      separatorBuilder: (_, _) => const Divider(height: 1),
      itemBuilder: (context, index) {
        if (index == _locations.length) {
          return Padding(
            padding: const EdgeInsets.all(16),
            child: Text(
              '$_total listing${_total == 1 ? '' : 's'}',
              style: theme.textTheme.bodySmall
                  ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
            ),
          );
        }

        final location = _locations[index];
        final live = location['is_live'] == true;

        return ListTile(
          leading: Icon(
            live ? Icons.check_circle_outline : Icons.error_outline,
            color: live ? theme.colorScheme.outline : theme.colorScheme.error,
          ),
          title: Text(location['title'] as String? ?? 'Untitled listing'),
          subtitle: Text(
            [
              if ((location['address'] as String?)?.isNotEmpty == true)
                location['address'],
              if ((location['primary_category'] as String?)?.isNotEmpty == true)
                location['primary_category'],
              // Google's own word, shown as-is when it is not simply live —
              // SUSPENDED and UNVERIFIED mean different things and the fix
              // differs, so collapsing them to "problem" would help nobody.
              if (!live) location['verification_state'] ?? 'not showing',
            ].join('  ·  '),
            style: theme.textTheme.bodySmall?.copyWith(
              color: live ? null : theme.colorScheme.error,
            ),
          ),
          trailing: location['franchise'] is Map
              ? Chip(
                  label: Text(
                    (location['franchise'] as Map)['name'] as String? ?? '',
                    style: theme.textTheme.labelSmall,
                  ),
                  visualDensity: VisualDensity.compact,
                )
              : null,
        );
      },
    );
  }
}
