import 'package:flutter/material.dart';
import '../core/api_client.dart';
import '../core/session.dart';
import '../widgets/appearance_card.dart';
import '../widgets/async_body.dart';
import '../widgets/identity_card.dart';
import '../widgets/tag.dart';
import '../widgets/password_card.dart';
import '../widgets/side_panel.dart';
import '../widgets/two_factor_card.dart';

/// Your own account (plan §2.1 category 6).
///
/// Everything here belongs to the PERSON. Settings, next door, belongs to the
/// organization and is set by an administrator. They were one screen, which
/// put somebody's dark-mode choice directly above the organization's job
/// statuses and implied that whoever could change one could change the other.
/// §2.1 says the categories must not be merged; the shape of the app should say
/// so before anybody reads a word.
class ProfileScreen extends StatefulWidget {
  const ProfileScreen({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<ProfileScreen> createState() => _ProfileScreenState();
}

class _ProfileScreenState extends State<ProfileScreen> {
  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _sessions = [];

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });

    final response = await widget.api.get('/me/sessions');

    if (!mounted) return;

    setState(() {
      _loading = false;
      _error = response.ok ? null : response.message;
      _sessions = response.ok
          ? (response.body['sessions'] as List<dynamic>).cast<Map<String, dynamic>>()
          : [];
    });
  }

  Future<void> _revokeOthers() async {
    final confirmed = await showSidePanel<bool>(
      context,
      builder: (c) => SidePanel(
        title: 'Sign out everywhere else?',
        actions: [
          TextButton(
              onPressed: () => Navigator.of(c).pop(false),
              child: const Text('Cancel')),
          FilledButton(
              onPressed: () => Navigator.of(c).pop(true),
              child: const Text('Sign them out')),
        ],
        child: const Text(
          'Every other browser and device is signed out immediately. This one '
          'stays signed in.',
        ),
      ),
    );

    if (confirmed != true) return;

    final response = await widget.api.delete('/me/sessions');

    if (!mounted) return;

    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      content: Text(response.ok
          ? 'Signed out of ${response.body['revoked']} other session(s).'
          : response.message),
    ));

    if (response.ok) _load();
  }

  @override
  Widget build(BuildContext context) {
    return AsyncBody(
      loading: _loading,
      error: _error,
      onRetry: _load,
      child: ListView(
        padding: const EdgeInsets.all(24),
        children: [
          Text('Your account', style: Theme.of(context).textTheme.headlineSmall),
          const SizedBox(height: 4),
          Text(
            'Yours, not the organization\'s. What an administrator controls is '
            'in Settings.',
            style: Theme.of(context).textTheme.bodyMedium,
          ),
          const SizedBox(height: 20),

          IdentityCard(api: widget.api, session: widget.session),
          const SizedBox(height: 12),

          AppearanceCard(session: widget.session),
          const SizedBox(height: 12),
          TwoFactorCard(api: widget.api, session: widget.session),
          const SizedBox(height: 12),
          PasswordCard(api: widget.api, session: widget.session),
          const SizedBox(height: 12),
          _sessionsCard(),
        ],
      ),
    );
  }

  Widget _sessionsCard() {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('Where you are signed in',
                style: Theme.of(context).textTheme.titleMedium),
            const SizedBox(height: 4),
            Text(
              // The honest description. A token list is not a device list, and
              // calling it one would have people looking for a phone model.
              'One entry per sign-in. A browser you closed without signing out '
              'is still here until it expires.',
              style: Theme.of(context).textTheme.bodySmall,
            ),
            const SizedBox(height: 12),
            for (final s in _sessions)
              Padding(
                padding: const EdgeInsets.only(bottom: 10),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    // The platform, drawn. Five rows reading "web" told nobody
                    // which one to sign out, which left "everywhere else" as
                    // the only usable control.
                    Icon(_deviceIcon(s['kind'] as String?), size: 20),
                    const SizedBox(width: 10),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Row(children: [
                            Flexible(
                              child: Text(
                                s['label'] as String? ?? 'Unknown device',
                                overflow: TextOverflow.ellipsis,
                              ),
                            ),
                            if (s['current'] == true) ...[
                              const SizedBox(width: 6),
                              const Tag('This one'),
                            ],
                          ]),
                          Text(
                            [
                              if (s['ip'] != null) s['ip'] as String,
                              _lastUsed(s['last_used_at'] as String?),
                            ].join(' · '),
                            style: Theme.of(context).textTheme.bodySmall,
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
            const SizedBox(height: 8),
            OutlinedButton.icon(
              onPressed: _sessions.length < 2 ? null : _revokeOthers,
              icon: const Icon(Icons.logout),
              label: const Text('Sign out everywhere else'),
            ),
          ],
        ),
      ),
    );
  }

  /// Apple, Windows, phone, or an honest shrug.
  ///
  /// A confidently wrong icon is worse than a generic one: somebody scanning
  /// for "the Mac I left at the office" and seeing a Windows logo will not
  /// revoke it.
  IconData _deviceIcon(String? kind) => switch (kind) {
        'mobile' => Icons.smartphone,
        'apple' => Icons.laptop_mac,
        'windows' => Icons.laptop_windows,
        'desktop' => Icons.desktop_windows_outlined,
        _ => Icons.device_unknown_outlined,
      };

  String _lastUsed(String? iso) {
    if (iso == null) return 'Never used';

    final at = DateTime.tryParse(iso)?.toLocal();

    if (at == null) return '';

    final ago = DateTime.now().difference(at);

    if (ago.inMinutes < 1) return 'Just now';
    if (ago.inHours < 1) return '${ago.inMinutes} min ago';
    if (ago.inDays < 1) return '${ago.inHours} h ago';

    return '${ago.inDays} d ago';
  }
}
