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

          // Only the reporting sources, for now. The others are named where
          // they are connected, and this screen is not trying to become the
          // place every account is administered.
          canName: true,
        ),
      ],
    );
  }

  /// Give a connection a name a person recognises.
  ///
  /// The endpoint has existed since before this screen did — nothing listed
  /// the label, so naming a connection had no visible effect and nobody could
  /// tell it had worked. A write with no read.
  ///
  /// In a dialog rather than in place, and that is a deliberate exception to
  /// the house rule: this row sits in a list of rows, and an inline field would
  /// reflow every connection under it while somebody types.
  Future<void> _rename(_Connection row) async {
    final controller = TextEditingController(text: row.label ?? '');

    final name = await showDialog<String>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text('Name this ${row.providerLabel} connection'),
        content: TextField(
          controller: controller,
          autofocus: true,
          decoration: InputDecoration(
            labelText: 'Name',
            // The id, so somebody naming several in a row can tell them apart
            // while the dialog is covering the list.
            helperText: row.displayName,
          ),
          onSubmitted: (value) => Navigator.of(context).pop(value),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () => Navigator.of(context).pop(controller.text),
            child: const Text('Save'),
          ),
        ],
      ),
    );

    controller.dispose();

    // Null is Cancel. An EMPTY string is not — it is "take the name off",
    // which the endpoint accepts and stores as null.
    if (name == null || !mounted) return;

    final response = await widget.api.patch(
      '/social/connections/${row.id}/label',
      {'label': name.trim()},
    );

    if (!mounted) return;

    if (!response.ok) {
      setState(() => _error = response.message);
      return;
    }

    _load();
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
  /// Whether this person may connect the source behind one of these forms.
  ///
  /// The two are DIFFERENT permissions and were not always: connecting an
  /// Airtable base follows Profit & Loss, the screen that reads it, while a
  /// Workiz account follows the Experimental report. Showing both to everybody
  /// was survivable while they agreed; it stopped being so on 2026-09-17, when
  /// Airtable's moved to `profit_loss.view`.
  ///
  /// Absent, not disabled, and not shown-then-refused: a form that posts and
  /// comes back with a permission error has spent somebody's time and told
  /// them the app is for other people. Absent `/me` answers no.
  bool _mayConnect(String permission) =>
      widget.session.me?.permissions.contains(permission) ?? false;

  /// Whether this person can actually SEE the Experimental report.
  ///
  /// ── Two keys, because the pane needs two ────────────────────
  ///
  /// `reports.build` is what `SocialProvider` asks for to connect a Workiz
  /// account, and it was enough to see the report until Reports' tabs landed
  /// on 2026-09-17. It is not any more: the pane needs the tab AND the
  /// capability, so a builder without `reports.experimental.view` was being
  /// offered a form for an account they cannot look at — measured, connect
  /// reached the controller while the pane answered 403.
  ///
  /// The server permission is deliberately left alone. It is an authorisation
  /// floor and `reports.build` is the right one; what was wrong is the OFFER,
  /// and offering is this screen's job. A single connect permission cannot
  /// express "both of these", and widening the controller so one key would do
  /// it would hand the pane to everybody holding `reports.view`.
  bool get _maySeeExperimental =>
      _mayConnect('reports.build') && _mayConnect('reports.experimental.view');

  List<Widget> _reportingForms() => [
    if (_mayConnect('profit_loss.view')) ...[
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
    ],
    if (_maySeeExperimental) ...[
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
    ],
  ];

  Widget _family(
    BuildContext context, {
    required String title,
    required IconData icon,
    required List<_Connection> rows,
    required String empty,
    String? where,
    List<Widget>? connect,
    bool canName = false,
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
                  row.title,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
                // `appadTMQltMGmGrse` tells nobody anything, so a named
                // connection leads with its name - and still shows the id
                // underneath, because that is what identifies it to Airtable
                // when something is wrong.
                subtitle: Text(
                  [
                    if (row.beneath != null) row.beneath!,
                    row.providerLabel,
                    row.scopeWord,
                  ].join(' · '),
                ),
                onTap: canName ? () => _rename(row) : null,
                trailing: row.connected
                    ? (canName
                          ? IconButton(
                              icon: const Icon(Icons.edit_outlined, size: 18),
                              tooltip: 'Name this connection',
                              onPressed: () => _rename(row),
                            )
                          : null)
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
    required this.id,
    required this.provider,
    required this.providerLabel,
    required this.displayName,
    required this.scopeType,
    required this.connected,
    this.label,
    this.statusReason,
  });

  final String id;
  final String provider;
  final String providerLabel;
  final String displayName;

  /// What this business calls it. Null until somebody names it.
  ///
  /// Kept beside `displayName` rather than replacing it: an Airtable base's
  /// display name is its BASE ID, so a named connection still has to be able
  /// to show which base it is.
  final String? label;
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

  /// What to put in front of a person, and what to put under it.
  String get title => (label ?? '').isNotEmpty ? label! : displayName;
  String? get beneath => (label ?? '').isNotEmpty ? displayName : null;

  factory _Connection.fromJson(Map<String, dynamic> json) => _Connection(
    id: json['id'] as String? ?? '',
    label: json['label'] as String?,
    provider: json['provider'] as String? ?? '',
    providerLabel: json['provider_label'] as String? ?? '',
    displayName: json['display_name'] as String? ?? '',
    scopeType: json['scope_type'] as String? ?? '',
    connected: (json['status'] as String? ?? '') == 'connected',
    statusReason: json['status_reason'] as String?,
  );
}
