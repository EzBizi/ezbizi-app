import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../widgets/disconnect_panel.dart';
import '../../widgets/dashboard_card.dart';
import '../../widgets/refused.dart';
import '../../widgets/social_network_mark.dart';

/// Newsletter › Settings — the mailing account this view sends through.
///
/// ── Why this exists when Settings › Integrations also lists it ──
///
/// They answer different questions. Integrations answers "what is this company
/// connected to", across every module, for somebody auditing or tidying. This
/// answers "can I send, and if not what do I do about it" — for somebody who
/// came here to write a newsletter and found Send greyed out.
///
/// The overlap is deliberate and it is one direction only: this screen connects
/// and disconnects because Newsletter is where a mailing account is understood;
/// Integrations lists and points here. Two Connect flows for one account would
/// be two things to keep correct.
class NewsletterSettings extends StatefulWidget {
  const NewsletterSettings({
    super.key,
    required this.api,
    required this.session,
    required this.viewName,
    required this.onChanged,
  });

  final ApiClient api;
  final Session session;
  final String viewName;
  final Future<void> Function() onChanged;

  @override
  State<NewsletterSettings> createState() => _NewsletterSettingsState();
}

class _NewsletterSettingsState extends State<NewsletterSettings> {
  final TextEditingController _key = TextEditingController();
  List<Map<String, dynamic>> _connections = const [];
  bool _loading = true;
  bool _busy = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _key.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    final response = await widget.api.get('/social/connections');
    if (!mounted) return;

    setState(() {
      _loading = false;
      if (!response.ok) {
        _error = response.reason;
        return;
      }
      _connections = ((response.body['data'] as List<dynamic>?) ?? const [])
          .whereType<Map<String, dynamic>>()
          .where((c) => c['provider'] == 'mailchimp')
          .toList();
    });
  }

  Future<void> _connect() async {
    setState(() {
      _busy = true;
      _error = null;
    });

    final response = await widget.api.post('/newsletter/mailchimp/connect', {
      'api_key': _key.text.trim(),
    });

    if (!mounted) return;

    setState(() {
      _busy = false;
      _error = response.ok ? null : response.reason;
      if (response.ok) _key.clear();
    });

    if (response.ok) {
      await _load();
      await widget.onChanged();
    }
  }

  Future<void> _disconnect(String id, String name) async {
    final done = await confirmDisconnect(
      context,
      api: widget.api,
      title: 'Disconnect $name?',
      explanation:
          'EzBizi stops sending newsletters through this account. To use it '
          'again, it has to be connected again.',
      dependentsPath: '/integrations/social/$id/dependents',
      perform: (code) =>
          widget.api.deleteWithBody('/social/connections/$id', {'code': code}),
    );
    if (done == null || !mounted) return;

    setState(() => _error = null);

    await _load();
    await widget.onChanged();
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) return const Center(child: CircularProgressIndicator());

    final refusal = widget.session.me?.refusal(
      'newsletter.send',
      'connect a mailing account',
    );

    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        DashboardCard(
          key: const ValueKey('tour-newsletter-mailing-account'),
          title: 'Mailing account',
          icon: Icons.drafts_outlined,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              const DashboardNote(
                'A newsletter goes out through your own mailing account, on '
                'your sending reputation and to your list. EzBizi writes it; '
                'the account sends it.',
              ),
              const SizedBox(height: 12),
              if (_connections.isEmpty)
                _connectForm(context, refusal)
              else
                for (final c in _connections) _connected(context, c, refusal),
              if (_error != null) ...[
                const SizedBox(height: 10),
                DashboardNote(_error!),
              ],
            ],
          ),
        ),
      ],
    );
  }

  Widget _connectForm(BuildContext context, String? refusal) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text('Nothing connected in ${widget.viewName} yet.'),
        const SizedBox(height: 12),
        TextField(
          key: const ValueKey('tour-newsletter-mailchimp-key'),
          controller: _key,
          enabled: !_busy && refusal == null,
          decoration: const InputDecoration(
            labelText: 'Mailchimp API key',
            // The datacentre suffix is what names the API host, so a key
            // without one cannot be used and the field says so up front
            // rather than after a failed round trip.
            helperText: 'Ends in a dash and a datacentre, like …-us14.',
          ),
          obscureText: true,
          onChanged: (_) => setState(() {}),
          onSubmitted: (_) => _connect(),
        ),
        const SizedBox(height: 12),
        Align(
          alignment: Alignment.centerLeft,
          child: Refused(
            reason:
                refusal ??
                (_key.text.trim().isEmpty
                    ? 'Paste the API key from your Mailchimp account.'
                    : null),
            child: FilledButton(
              onPressed:
                  refusal == null && _key.text.trim().isNotEmpty && !_busy
                  ? _connect
                  : null,
              child: Text(_busy ? 'Connecting…' : 'Connect'),
            ),
          ),
        ),
      ],
    );
  }

  Widget _connected(
    BuildContext context,
    Map<String, dynamic> c,
    String? refusal,
  ) {
    final name = c['display_name'] as String? ?? 'Mailchimp';
    final healthy = (c['status'] as String? ?? '') == 'connected';

    return ListTile(
      contentPadding: EdgeInsets.zero,
      leading: const SocialNetworkMark(network: 'mailchimp', size: 24),
      title: Text(name),
      subtitle: Text(
        healthy
            ? 'Connected · newsletters send through this account'
            : (c['status_reason'] as String? ??
                  'This connection needs attention.'),
      ),
      trailing: Refused(
        reason: refusal,
        child: TextButton(
          // Muted: destructive, not the main action (phone audit 2026-11-04).
          style: TextButton.styleFrom(
            foregroundColor: Theme.of(context).colorScheme.onSurfaceVariant,
          ),
          onPressed: refusal == null && !_busy
              ? () => _disconnect(c['id'] as String? ?? '', name)
              : null,
          child: const Text('Disconnect'),
        ),
      ),
    );
  }
}
