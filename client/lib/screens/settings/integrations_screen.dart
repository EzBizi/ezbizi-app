import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../widgets/dashboard_card.dart';
import '../../widgets/api_key_connect_form.dart';
import '../../widgets/social_network_mark.dart';

/// Settings › Integrations — every outside account this view is connected to,
/// in one place.
///
/// ── Why this exists when each module already has a Connect ──────
///
/// Because "what is this company connected to" was not a question anybody could
/// answer. Social accounts connect under Marketing, the mailing account under
/// Newsletter, advertising under Leads — so finding out whether Mailchimp was
/// connected meant knowing which module owned it first. That is fine when you
/// built the app and useless when you did not.
///
/// This does not replace those screens and deliberately does not duplicate
/// their panels: Connect here opens the module that owns the account, because
/// the module is where the connecting is understood. What lives here is the
/// INVENTORY — what is connected, at what scope, and what is not.
///
/// ── Scope, and why the list can look short ──────────────────────
///
/// It answers for the CURRENT VIEW, the same as every other list in the app
/// (§3). Standing in A-1 Chimney you see A-1 Chimney's connections and the
/// organization's; you never see Premier Garage's. An administrator switching
/// views will see the list change, and that is correct rather than a bug.
class IntegrationsScreen extends StatefulWidget {
  const IntegrationsScreen({
    super.key,
    required this.api,
    required this.session,
  });

  final ApiClient api;
  final Session session;

  @override
  State<IntegrationsScreen> createState() => _IntegrationsScreenState();
}

class _IntegrationsScreenState extends State<IntegrationsScreen> {
  List<_Connection> _connections = const [];
  bool _loading = true;
  String? _error;

  String get _viewName =>
      widget.session.me?.scope.label ??
      widget.session.me?.organization.name ??
      'this view';

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    // Data sources are filtered out of this endpoint by default, because
    // listing them once put twenty-two Google Ads accounts in the Social Media
    // composer as if a post could go to one. Integrations is the screen that
    // wants them, so it is the screen that asks.
    final response = await widget.api.get('/social/connections', {
      'include': 'data_sources',
    });
    if (!mounted) return;

    setState(() {
      _loading = false;

      if (!response.ok) {
        _error = response.reason;
        return;
      }

      _error = null;
      _connections = ((response.body['data'] as List<dynamic>?) ?? const [])
          .whereType<Map<String, dynamic>>()
          .map(_Connection.fromJson)
          .toList();
    });
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) return const Center(child: CircularProgressIndicator());

    final byFamily = <String, List<_Connection>>{};
    for (final c in _connections) {
      byFamily.putIfAbsent(c.family, () => []).add(c);
    }

    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        if (_error != null) ...[
          DashboardNote(_error!),
          const SizedBox(height: 12),
        ],
        _family(
          context,
          title: 'Mailing',
          icon: Icons.drafts_outlined,
          rows: byFamily['mailing'] ?? const [],
          empty:
              'No mailing account connected in $_viewName. A newsletter is '
              'sent through the company\'s own account, so one has to be '
              'connected before anything can go out.',
          where: 'Marketing › Newsletter',
        ),
        const SizedBox(height: 12),
        _family(
          context,
          title: 'Social',
          icon: Icons.share_outlined,
          rows: byFamily['social'] ?? const [],
          empty: 'No social account connected in $_viewName.',
          where: 'Marketing › Social Media Management › Accounts',
        ),
        const SizedBox(height: 12),
        _family(
          context,
          title: 'Advertising and listings',
          icon: Icons.campaign_outlined,
          rows: byFamily['leads'] ?? const [],
          empty: 'No advertising account connected in $_viewName.',
          where: 'Leads',
        ),
        const SizedBox(height: 12),
        _family(
          context,
          title: 'Reporting',
          icon: Icons.assessment_outlined,
          rows: byFamily['reporting'] ?? const [],
          empty:
              'No reporting source connected in $_viewName. Reports read '
              'these directly, so a report covering a business with none '
              'connected has nothing to show.',
          // The one family that connects HERE rather than elsewhere, which is
          // why it passes forms instead of a `where` sentence.
          connect: _reportingForms(),
        ),
      ],
    );
  }

  /// The connect forms for the Reporting family.
  ///
  /// Jose, 2026-09-16: "make the connection in Integrations", and for both
  /// Airtable and Workiz — "make it as well so any company can make workiz
  /// connections via api as well, same set it up on integrations".
  ///
  /// Airtable has no other connect screen, so this is its only home. Workiz
  /// also has one on Reports › Experimental; that one stays for now because a
  /// branch connecting its own book from the screen that reads it is a real
  /// path, and both go through the same endpoint and the same shared form
  /// rather than being two implementations.
  List<Widget> _reportingForms() => [
    Text('Airtable', style: Theme.of(context).textTheme.titleSmall),
    const SizedBox(height: 4),
    Text(
      'One connection is one base. Connect as many bases as this business '
      'keeps its figures in.',
      style: Theme.of(context).textTheme.bodySmall,
    ),
    const SizedBox(height: 8),
    ApiKeyConnectForm(
      api: widget.api,
      path: '/social/airtable',
      submitLabel: 'Connect base',
      fields: const [
        ('api_token', 'Personal access token', 'pat…'),
        ('base_id', 'Base id', 'app… — from the base\'s URL'),
      ],
      onConnected: _load,
    ),
    const SizedBox(height: 16),
    Text('Workiz', style: Theme.of(context).textTheme.titleSmall),
    const SizedBox(height: 4),
    Text(
      'One connection is one Workiz account. Switch to the branch it belongs '
      'to before connecting it — the accounts are separate books and a report '
      'attributes each one to its branch.',
      style: Theme.of(context).textTheme.bodySmall,
    ),
    const SizedBox(height: 8),
    ApiKeyConnectForm(
      api: widget.api,
      path: '/social/workiz',
      submitLabel: 'Connect account',
      fields: const [
        ('api_token', 'API token', 'api_… — from Workiz › Developer › v1'),
      ],
      onConnected: _load,
    ),
  ];

  Widget _family(
    BuildContext context, {
    required String title,
    required IconData icon,
    required List<_Connection> rows,
    required String empty,
    String? where,
    List<Widget>? connect,
  }) {
    final theme = Theme.of(context);

    return DashboardCard(
      title: title,
      icon: icon,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (rows.isEmpty)
            DashboardNote(empty)
          else
            for (final row in rows)
              ListTile(
                contentPadding: EdgeInsets.zero,
                dense: true,
                leading: SocialNetworkMark(network: row.provider, size: 24),
                title: Text(
                  row.displayName,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
                subtitle: Text('${row.providerLabel} · ${row.scopeWord}'),
                trailing: row.connected
                    ? null
                    // A broken connection is the whole reason somebody comes
                    // looking at this screen, so it says so here rather than
                    // only on the module that owns it.
                    : Tooltip(
                        message:
                            row.statusReason ??
                            'This connection needs attention.',
                        child: Icon(
                          Icons.error_outline,
                          size: 18,
                          color: theme.colorScheme.error,
                        ),
                      ),
              ),
          const SizedBox(height: 8),

          /*
           * A SENTENCE FOR MOST FAMILIES, A FORM FOR ONE.
           *
           * This used to be a sentence for all of them, and the comment here
           * said why: "connecting is done where the account is understood, and
           * a second Connect flow here would be a second thing to keep
           * correct." Jose overrode the first half on 2026-09-16 for the
           * reporting sources — "make the connection in Integrations".
           *
           * The second half is still true, which is why the form is a shared
           * widget rather than a copy: Workiz can be connected from Reports ›
           * Experimental as well, and two forms posting to one endpoint would
           * be two sets of field names to keep in step.
           */
          if (connect != null)
            ...connect
          else
            Text(
              'Connect and disconnect under $where.',
              style: theme.textTheme.bodySmall,
            ),
        ],
      ),
    );
  }
}

class _Connection {
  const _Connection({
    required this.provider,
    required this.providerLabel,
    required this.displayName,
    required this.scopeType,
    required this.connected,
    this.statusReason,
  });

  final String provider;
  final String providerLabel;
  final String displayName;
  final String scopeType;
  final bool connected;
  final String? statusReason;

  /// Which screen owns this account. Grouped rather than listed flat, because
  /// "where do I go to fix this" is the question the screen is answering.
  String get family => switch (provider) {
    'mailchimp' => 'mailing',
    'google_ads' || 'google_business_profile' || 'thumbtack' => 'leads',
    'airtable' || 'workiz' => 'reporting',
    _ => 'social',
  };

  String get scopeWord => switch (scopeType) {
    'organization' => 'the whole organization',
    'company' => 'this company',
    'franchise' => 'this branch',
    _ => scopeType,
  };

  factory _Connection.fromJson(Map<String, dynamic> json) => _Connection(
    provider: json['provider'] as String? ?? '',
    providerLabel: json['provider_label'] as String? ?? '',
    displayName: json['display_name'] as String? ?? '',
    scopeType: json['scope_type'] as String? ?? '',
    connected: (json['status'] as String? ?? '') == 'connected',
    statusReason: json['status_reason'] as String?,
  );
}
