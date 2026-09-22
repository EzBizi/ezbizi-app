import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../widgets/dashboard_card.dart';
import '../../widgets/social_network_mark.dart';

/// One provider's connections, grouped by who owns them (Jose, 2026-09-22).
///
/// ── Why this screen exists ──────────────────────────────────────
///
/// Integrations used to list every connection as a flat row under four family
/// headings. That was legible with six connections and unreadable with the
/// real thing: A1-Group holds fifty-three Google Ads accounts and a column of
/// identical Thumbtack rows, so the screen scrolled for pages and answered
/// nothing. His words: *"i'd like to have cards instead each with their own
/// screen for connections"*.
///
/// So the grid answers "what is this business connected to" and this screen
/// answers "which ones, and whose".
///
/// ── Grouped by SCOPE, which is the half the flat list destroyed ─
///
/// Every one of those fifty-three rows said "this company" — true, and useless,
/// because it never said WHICH. §3's cascade is the thing an administrator is
/// actually looking at when they open this: an account connected at the
/// organization serves everybody, one connected at a company serves that
/// company's branches, and one connected at a branch serves that branch alone.
///
/// The names come from `/me`'s `companiesInView` and `franchisesInView` rather
/// than from a new server field, and that is deliberate rather than
/// convenient: those two lists are the CURRENT VIEW (AGENTS.md — file on the
/// view, not on reach), so a connection belonging to a company this person is
/// not standing in cannot be named here. It falls back to the scope word rather
/// than inventing a name, which is the honest answer for a row that is in the
/// list because the server said it is in view.
class IntegrationProviderScreen extends StatefulWidget {
  const IntegrationProviderScreen({
    super.key,
    required this.api,
    required this.session,
    required this.provider,
    required this.providerLabel,
    required this.connections,
    this.connectWhere,
    this.connectForms,
    this.canName = false,
    this.onChanged,
  });

  final ApiClient api;
  final Session session;

  /// Catalogue `value` — `google_ads`, `thumbtack`, `airtable`…
  final String provider;
  final String providerLabel;

  /// This provider's connections, already filtered by the grid.
  final List<IntegrationConnection> connections;

  /// Where the account is connected, when it is connected somewhere else.
  ///
  /// The module that owns an account is where connecting it is understood, so
  /// this screen points rather than duplicating the panel. Null for the
  /// reporting sources, which connect here — see [connectForms].
  final String? connectWhere;

  /// The connect form, for the providers whose only home is Integrations.
  final List<Widget> Function()? connectForms;

  /// Whether a connection here can be given a name a person recognises.
  final bool canName;

  /// Reload the grid after a rename or a connect, so the card's count and this
  /// list cannot disagree about what exists.
  final Future<void> Function()? onChanged;

  @override
  State<IntegrationProviderScreen> createState() =>
      _IntegrationProviderScreenState();
}

class _IntegrationProviderScreenState extends State<IntegrationProviderScreen> {
  String? _error;

  /// The organization, then each company, then each branch — in that order and
  /// only where something is connected.
  ///
  /// Empty groups are dropped rather than drawn empty. A company with no
  /// connection of this provider is not a fact worth a heading; the grid
  /// already said how many there are in total, and a page of "nothing here"
  /// rows is the shape this redesign exists to remove.
  List<(String, List<IntegrationConnection>)> get _groups {
    final me = widget.session.me;
    final rows = widget.connections;

    final organization = rows.where((c) => c.scopeType == 'organization').toList();

    final groups = <(String, List<IntegrationConnection>)>[
      if (organization.isNotEmpty)
        (me?.organization.name ?? 'The organization', organization),
    ];

    for (final company in me?.companiesInView ?? const []) {
      final mine = rows
          .where((c) => c.scopeType == 'company' && c.scopeId == company.id)
          .toList();

      if (mine.isNotEmpty) groups.add((company.name, mine));
    }

    for (final franchise in me?.franchisesInView ?? const []) {
      final mine = rows
          .where((c) => c.scopeType == 'franchise' && c.scopeId == franchise.id)
          .toList();

      if (mine.isNotEmpty) groups.add((franchise.name, mine));
    }

    /*
     * ANYTHING THE TWO LISTS COULD NOT NAME, rather than dropped.
     *
     * The server decided this row is in view; `/me` decides what this person's
     * switcher can name. Those agree today and a row that fell between them
     * would VANISH from a screen whose whole purpose is inventory — the one
     * failure worse than an unnamed heading. So it is drawn under the scope
     * word instead, and the sum of the groups is always the count the card
     * showed.
     */
    final named = {for (final group in groups) ...group.$2.map((c) => c.id)};
    final rest = rows.where((c) => !named.contains(c.id)).toList();

    if (rest.isNotEmpty) {
      groups.add(('Elsewhere in this view', rest));
    }

    return groups;
  }

  Future<void> _rename(IntegrationConnection row) async {
    final controller = TextEditingController(text: row.label ?? '');

    final name = await showDialog<String>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text('Name this ${widget.providerLabel} connection'),
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

    setState(() => _error = null);
    await widget.onChanged?.call();

    if (mounted) Navigator.of(context).pop();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final groups = _groups;

    return Scaffold(
      appBar: AppBar(
        title: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            SocialNetworkMark(network: widget.provider, size: 22),
            const SizedBox(width: 10),
            Flexible(child: Text(widget.providerLabel)),
          ],
        ),
      ),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          if (_error != null) ...[
            DashboardNote(_error!),
            const SizedBox(height: 12),
          ],

          if (groups.isEmpty)
            DashboardCard(
              title: widget.providerLabel,
              icon: Icons.link_off_outlined,
              child: DashboardNote(
                widget.connectWhere == null
                    ? 'Nothing connected here yet.'
                    : 'Nothing connected here yet. Connect it in '
                          '${widget.connectWhere}.',
              ),
            ),

          for (final (heading, rows) in groups) ...[
            DashboardCard(
              title: heading,
              icon: Icons.account_tree_outlined,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  for (final row in rows)
                    ListTile(
                      key: ValueKey('connection:${row.id}'),
                      contentPadding: EdgeInsets.zero,
                      dense: true,
                      leading: SocialNetworkMark(
                        network: row.provider,
                        size: 24,
                      ),
                      title: Text(
                        row.title,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                      ),
                      // `appadTMQltMGmGrse` tells nobody anything, so a named
                      // connection leads with its name and still shows the id
                      // underneath — that is what identifies it to the provider
                      // when something is wrong.
                      subtitle: Text(
                        [
                          if (row.beneath != null) row.beneath!,
                          if (!row.connected)
                            row.statusReason ?? 'Not working',
                        ].join(' · '),
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: theme.textTheme.bodySmall?.copyWith(
                          color: row.connected
                              ? theme.colorScheme.onSurfaceVariant
                              : theme.colorScheme.error,
                        ),
                      ),
                      trailing: widget.canName
                          ? IconButton(
                              tooltip: 'Name this connection',
                              icon: const Icon(Icons.edit_outlined, size: 18),
                              onPressed: () => _rename(row),
                            )
                          : null,
                    ),
                ],
              ),
            ),
            const SizedBox(height: 12),
          ],

          if (widget.connectForms != null) ...[
            DashboardCard(
              title: 'Connect another',
              icon: Icons.add_link,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: widget.connectForms!(),
              ),
            ),
          ] else if (widget.connectWhere != null && groups.isNotEmpty)
            DashboardNote(
              'Accounts are connected in ${widget.connectWhere}, which is where '
              'connecting one is explained.',
            ),
        ],
      ),
    );
  }
}

/// One outside account this view is connected to.
///
/// Public, and shared by the grid and this screen deliberately: the card's
/// count and the list behind it are two readings of one set, and two models
/// would be two chances for them to disagree about what "connected" means.
class IntegrationConnection {
  const IntegrationConnection({
    required this.id,
    required this.provider,
    required this.providerLabel,
    required this.displayName,
    required this.scopeType,
    required this.scopeId,
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

  /// Which organization, company or franchise owns it.
  ///
  /// The server has always sent this; nothing read it until the flat list was
  /// replaced, because a row saying "this company" had nowhere to put a name.
  final String scopeId;

  final bool connected;
  final String? statusReason;

  /// Which screen owns this account. The grid draws a heading per family,
  /// because "where do I go to fix this" is the question underneath.
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

  factory IntegrationConnection.fromJson(Map<String, dynamic> json) =>
      IntegrationConnection(
        id: json['id'] as String? ?? '',
        label: json['label'] as String?,
        provider: json['provider'] as String? ?? '',
        providerLabel: json['provider_label'] as String? ?? '',
        displayName: json['display_name'] as String? ?? '',
        scopeType: json['scope_type'] as String? ?? '',
        scopeId: json['scope_id'] as String? ?? '',
        connected: (json['status'] as String? ?? '') == 'connected',
        statusReason: json['status_reason'] as String?,
      );
}
