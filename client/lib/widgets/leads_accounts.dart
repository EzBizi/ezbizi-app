// The connected Google Ads accounts, and where each one's leads go.
//
// Approved as `docs/previews/leads-accounts-layouts.html` — desktop layout 1
// (a summary band that opens a side panel) with mobile layout 4 (a row that
// opens a screen). Both are doors onto the SAME list, which is why the list
// lives here once rather than twice.
//
// ── What it replaces ─────────────────────────────────────────────
//
// One line showing one account. A-1 Chimney has twenty-two — a single connect
// created all of them in the same second — so twenty-one were invisible and
// the one shown was whichever the query returned first. Jose: *"I should be
// able to see all the 20 somethings accounts in there."*
//
// ── The reading the layout is built on ───────────────────────────
//
// Twenty-two accounts with per-account placement is a SETTINGS surface that
// has been living in the header of a list of leads. So the question is not
// "how do I show twenty-two rows" but "how little can this cost on the way to
// the leads?" — one row, at twenty-two accounts or two hundred.

import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/session.dart';
import 'social_network_mark.dart';

/// How a placement reads, and whether it can be changed.
///
/// The server names a franchise only when the reader can reach it, so an
/// account placed outside their reach arrives with a real id and a null name.
/// That is an answer rather than missing data: the row says a placement EXISTS
/// without naming it, because naming a franchise somebody cannot otherwise
/// reach leaks the shape of the organization one row at a time.
({String text, bool editable}) placementOf(Map<String, dynamic> connection) {
  final id = connection['leads_franchise_id'];
  final name = connection['leads_franchise_name'];

  if (id == null) return (text: 'Company level', editable: true);
  if (name == null) {
    // Change is disabled here on purpose. Offering to move something whose
    // current value you cannot see is offering to guess.
    return (text: 'Placed somewhere you cannot see', editable: false);
  }
  return (text: '$name', editable: true);
}

/// What the row calls itself: the name when it has one, the number when not.
///
/// Both stay on the row when named — the name is how you find it, the number is
/// how you check it against Google Ads. Jose, on twenty-two numbered rows:
/// *"no one will remember what the numbers mean."*
String accountName(Map<String, dynamic> connection) {
  final label = '${connection['label'] ?? ''}'.trim();
  return label.isEmpty ? '${connection['display_name'] ?? ''}' : label;
}

bool isNamed(Map<String, dynamic> connection) =>
    '${connection['label'] ?? ''}'.trim().isNotEmpty;

int leadsOf(Map<String, dynamic> connection) =>
    (connection['leads_count'] as num?)?.toInt() ?? 0;

/// How many of these sit on a franchise rather than at company level.
int placedCount(List<Map<String, dynamic>> connections) =>
    connections.where((c) => c['leads_franchise_id'] != null).length;

/// The list itself — grouped, searchable, and editable in place.
///
/// Grouped by lead count because that is what tells twenty-two rows apart, and
/// zero is what folds most of them away. Naming does not overturn that:
/// nineteen rows are nineteen rows whatever they are called.
///
/// But SEARCH REACHES THROUGH THE FOLD, and the fold says so. A fold is only
/// legitimate while the thing behind it stays findable — otherwise it hides
/// rows from exactly the person who went looking. Same rule as the sidebar,
/// where a failed search must never filter Settings out of reach.
class LeadsAccountsList extends StatefulWidget {
  const LeadsAccountsList({
    super.key,
    required this.api,
    required this.session,
    required this.connections,
    required this.onChanged,
  });

  final ApiClient api;
  final Session session;
  final List<Map<String, dynamic>> connections;

  /// Reload the screen behind this. Placement is retroactive, so the lead list
  /// changes the moment an account moves.
  final Future<void> Function() onChanged;

  @override
  State<LeadsAccountsList> createState() => _LeadsAccountsListState();
}

class _LeadsAccountsListState extends State<LeadsAccountsList> {
  final _search = TextEditingController();
  String _query = '';

  /// Open once somebody searches, because a fold that hides a match is the
  /// defect this exists to avoid.
  bool _showQuiet = false;

  /// Disconnect all: asked once, then done in one call. One Thumbtack
  /// consent is twelve rows sharing one grant, and twelve confirmations for
  /// one account is the wrong number (Jose, 2026-09-09).
  bool _confirmAll = false;
  bool _lettingGo = false;
  String? _allError;

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  Future<void> _disconnectAll() async {
    final provider = '${widget.connections.first['provider']}';

    setState(() {
      _lettingGo = true;
      _allError = null;
    });

    final response = await widget.api.delete(
      '/social/providers/$provider/connections',
    );

    if (!mounted) return;

    setState(() {
      _lettingGo = false;
      _confirmAll = false;
      _allError = response.ok ? null : response.reason;
    });

    if (response.ok) await widget.onChanged();
  }

  Widget _disconnectAllRow(ThemeData theme) {
    final n = widget.connections.length;

    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Row(
        children: [
          Expanded(
            child: Text(
              _confirmAll
                  ? 'Disconnect all $n accounts? Nothing already in EzBizi is '
                        'deleted, and you can connect again afterwards.'
                  : (_allError ?? ''),
              style: theme.textTheme.bodySmall?.copyWith(
                color: _allError != null && !_confirmAll
                    ? theme.colorScheme.error
                    : theme.colorScheme.onSurfaceVariant,
              ),
            ),
          ),
          if (_confirmAll) ...[
            TextButton(
              key: const ValueKey('accounts-keep-all'),
              onPressed: _lettingGo
                  ? null
                  : () => setState(() => _confirmAll = false),
              child: const Text('Keep'),
            ),
            TextButton(
              key: const ValueKey('accounts-disconnect-all-confirm'),
              onPressed: _lettingGo ? null : _disconnectAll,
              child: Text(_lettingGo ? 'Disconnecting…' : 'Disconnect all'),
            ),
          ] else
            TextButton(
              key: const ValueKey('accounts-disconnect-all'),
              onPressed: () => setState(() => _confirmAll = true),
              child: const Text('Disconnect all'),
            ),
        ],
      ),
    );
  }

  bool _matches(Map<String, dynamic> c) {
    if (_query.isEmpty) return true;
    final haystack = [
      '${c['label'] ?? ''}',
      '${c['display_name'] ?? ''}',
      placementOf(c).text,
    ].join(' ').toLowerCase();
    return haystack.contains(_query);
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    final producing = <Map<String, dynamic>>[];
    final quiet = <Map<String, dynamic>>[];
    for (final c in widget.connections) {
      (leadsOf(c) > 0 ? producing : quiet).add(c);
    }
    producing.sort((a, b) => leadsOf(b).compareTo(leadsOf(a)));
    quiet.sort((a, b) => accountName(a).compareTo(accountName(b)));

    final shownProducing = producing.where(_matches).toList();
    final shownQuiet = quiet.where(_matches).toList();

    // Searching opens the fold, rather than leaving matches behind it.
    final quietOpen = _showQuiet || _query.isNotEmpty;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: [
        if (widget.connections.length > 1) _disconnectAllRow(theme),
        if (widget.connections.length > 6)
          Padding(
            padding: const EdgeInsets.only(bottom: 12),
            child: TextField(
              controller: _search,
              onChanged: (v) => setState(() => _query = v.trim().toLowerCase()),
              decoration: InputDecoration(
                isDense: true,
                hintText: 'Find an account',
                prefixIcon: const Icon(Icons.search, size: 18),
                prefixIconConstraints: const BoxConstraints(
                  minWidth: 34,
                  minHeight: 34,
                ),
                border: const OutlineInputBorder(),
              ),
            ),
          ),

        if (shownProducing.isNotEmpty) ...[
          _groupHead(theme, 'Producing leads'),
          for (final c in shownProducing) _row(c),
        ],

        if (quiet.isNotEmpty) ...[
          InkWell(
            onTap: _query.isEmpty
                ? () => setState(() => _showQuiet = !_showQuiet)
                : null,
            child: Padding(
              padding: const EdgeInsets.symmetric(vertical: 10, horizontal: 4),
              child: Row(
                children: [
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          _query.isEmpty
                              ? '${quiet.length} that have never sent a lead'
                              : '${shownQuiet.length} of ${quiet.length} that '
                                    'have never sent a lead',
                          style: theme.textTheme.titleSmall,
                        ),
                        if (_query.isEmpty && !_showQuiet)
                          Text(
                            'Search reaches in here',
                            style: theme.textTheme.bodySmall?.copyWith(
                              color: theme.colorScheme.onSurfaceVariant,
                            ),
                          ),
                      ],
                    ),
                  ),
                  if (_query.isEmpty)
                    Icon(
                      quietOpen ? Icons.expand_less : Icons.expand_more,
                      size: 20,
                    ),
                ],
              ),
            ),
          ),
          if (quietOpen)
            for (final c in shownQuiet) _row(c),
        ],

        if (shownProducing.isEmpty && shownQuiet.isEmpty && _query.isNotEmpty)
          Padding(
            padding: const EdgeInsets.symmetric(vertical: 20),
            child: Text(
              'No account matches “${_search.text.trim()}”. The list is '
              'filtered, not empty — clear the box to see all '
              '${widget.connections.length} again.',
              style: theme.textTheme.bodyMedium,
            ),
          ),
      ],
    );
  }

  Widget _groupHead(ThemeData theme, String text) => Padding(
    padding: const EdgeInsets.fromLTRB(4, 4, 4, 6),
    child: Text(text, style: theme.textTheme.titleSmall),
  );

  /// display_names that more than one connection in this list wears.
  Set<String> get _twins {
    final seen = <String, int>{};
    for (final c in widget.connections) {
      final name = '${c['display_name'] ?? ''}';
      seen[name] = (seen[name] ?? 0) + 1;
    }
    return {
      for (final e in seen.entries)
        if (e.value > 1) e.key,
    };
  }

  Widget _row(Map<String, dynamic> c) => LeadsAccountRow(
    ambiguous: _twins.contains('${c['display_name'] ?? ''}'),
    key: ValueKey('leads-account-${c['id']}'),
    api: widget.api,
    session: widget.session,
    connection: c,
    onChanged: widget.onChanged,
  );
}

/// One account: what it is called, where its leads go, and what it has sent.
///
/// Naming happens HERE rather than behind a control that opens something.
/// The steady state of this screen is reading, but the FIRST visit after
/// naming shipped is an editing visit twenty-two rows long, and a row you must
/// open and close once per account is twenty-two cycles of a workflow. In the
/// row, naming twenty-two accounts is one scroll.
class LeadsAccountRow extends StatefulWidget {
  const LeadsAccountRow({
    super.key,
    required this.api,
    required this.session,
    required this.connection,
    required this.onChanged,
    this.ambiguous = false,
  });

  final ApiClient api;
  final Session session;
  final Map<String, dynamic> connection;
  final Future<void> Function() onChanged;

  /// Another connection in the same list wears the same display_name. Google
  /// never produced this - its digits and its names are both unique - but one
  /// Thumbtack connect made twelve businesses all called "A1 Chimney", and a
  /// person cannot tell which one they are naming until they have named it.
  /// When true and the row has no label yet, the provider's own id is shown
  /// beside the name so the twins can be told apart.
  final bool ambiguous;

  @override
  State<LeadsAccountRow> createState() => _LeadsAccountRowState();
}

enum _Editing { none, name, placement, disconnect }

class _LeadsAccountRowState extends State<LeadsAccountRow> {
  _Editing _editing = _Editing.none;
  bool _busy = false;
  String? _error;

  /// What the last change actually did, in the row that did it.
  String? _said;

  final _name = TextEditingController();

  @override
  void dispose() {
    _name.dispose();
    super.dispose();
  }

  String get _id => '${widget.connection['id']}';

  Future<void> _saveName() async {
    final typed = _name.text.trim();
    setState(() {
      _busy = true;
      _error = null;
    });

    final response = await widget.api.patch(
      '/social/connections/$_id/label',
      // Blank clears rather than storing an empty string, so "unnamed" has one
      // representation and nothing downstream has to decide what '' means.
      {'label': typed.isEmpty ? null : typed},
    );

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _busy = false;
        _error = response.message;
      });
      return;
    }

    setState(() {
      _busy = false;
      _editing = _Editing.none;
      _said = null;
    });
    await widget.onChanged();
  }

  Future<void> _place(String? franchiseId) async {
    setState(() {
      _busy = true;
      _error = null;
      _said = null;
    });

    final response = await widget.api.patch(
      '/social/connections/$_id/leads-franchise',
      {'leads_franchise_id': franchiseId},
    );

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _busy = false;
        _error = response.message;
      });
      return;
    }

    // Three answers, and they mean different things.
    //
    //   a number — the leads that moved. Placement is retroactive, so this is
    //              the whole confirmation somebody looks for.
    //   0        — it took, and there was nothing to carry. Saying nothing
    //              here would read as a failure.
    //   null     — the same franchise re-submitted; nothing happened. Saying
    //              "0 moved" would report a non-event as an outcome.
    final moved = (response.body['data']?['leads_moved'] as num?)?.toInt();
    final where = franchiseId == null
        ? 'company level'
        : _franchiseName(franchiseId);

    setState(() {
      _busy = false;
      _editing = _Editing.none;
      _said = moved == null
          ? null
          : moved == 0
          ? 'Moved to $where. No leads to move yet.'
          : '$moved ${moved == 1 ? 'lead' : 'leads'} moved to $where.';
    });

    await widget.onChanged();
  }

  Future<void> _disconnect() async {
    setState(() {
      _busy = true;
      _error = null;
    });

    final response = await widget.api.delete('/social/connections/$_id');

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _busy = false;
        _editing = _Editing.none;
        _error = response.reason;
      });
      return;
    }

    setState(() {
      _busy = false;
      _editing = _Editing.none;
    });
    await widget.onChanged();
  }

  String _franchiseName(String id) {
    for (final f
        in widget.session.me?.franchises ?? const <FranchiseSummary>[]) {
      if (f.id == id) return f.name;
    }
    return 'that place';
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final c = widget.connection;
    final placement = placementOf(c);
    final leads = leadsOf(c);
    final named = isNamed(c);

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Wrap(
            spacing: 12,
            runSpacing: 6,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              SizedBox(
                width: 260,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Flexible(
                          child: Text(
                            accountName(c),
                            style: theme.textTheme.titleSmall,
                            overflow: TextOverflow.ellipsis,
                          ),
                        ),
                        if (named) ...[
                          const SizedBox(width: 8),
                          // The number stays even when named: the name is how
                          // you find it, the number is how you check it against
                          // the provider.
                          Text(
                            '${c['display_name'] ?? ''}',
                            style: theme.textTheme.bodySmall?.copyWith(
                              color: theme.colorScheme.onSurfaceVariant,
                            ),
                          ),
                        ] else if (widget.ambiguous &&
                            '${c['remote_id'] ?? ''}'.isNotEmpty) ...[
                          const SizedBox(width: 8),
                          // Twins: the provider's id is the only thing that
                          // tells them apart until somebody names them.
                          Text(
                            '${c['remote_id']}',
                            key: ValueKey('twin-${c['id']}'),
                            style: theme.textTheme.bodySmall?.copyWith(
                              color: theme.colorScheme.onSurfaceVariant,
                            ),
                          ),
                        ],
                      ],
                    ),
                    Text(
                      placement.text,
                      style: theme.textTheme.bodySmall?.copyWith(
                        color: theme.colorScheme.onSurfaceVariant,
                        fontStyle: placement.editable
                            ? FontStyle.normal
                            : FontStyle.italic,
                      ),
                    ),
                  ],
                ),
              ),
              SizedBox(
                width: 84,
                child: Text(
                  leads == 0
                      ? 'no leads'
                      : '$leads ${leads == 1 ? 'lead' : 'leads'}',
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: theme.colorScheme.onSurfaceVariant,
                  ),
                ),
              ),
              if (_busy)
                const SizedBox(
                  width: 16,
                  height: 16,
                  child: CircularProgressIndicator(strokeWidth: 2),
                )
              else ...[
                TextButton(
                  onPressed: () => setState(() {
                    _name.text = '${c['label'] ?? ''}';
                    _editing = _editing == _Editing.name
                        ? _Editing.none
                        : _Editing.name;
                  }),
                  child: Text(named ? 'Rename' : 'Name it'),
                ),
                TextButton(
                  onPressed: placement.editable
                      ? () => setState(
                          () => _editing = _editing == _Editing.placement
                              ? _Editing.none
                              : _Editing.placement,
                        )
                      : null,
                  child: const Text('Change'),
                ),
                if (_editing == _Editing.disconnect) ...[
                  TextButton(
                    onPressed: () => setState(() => _editing = _Editing.none),
                    child: const Text('Keep'),
                  ),
                  TextButton(
                    onPressed: _disconnect,
                    child: const Text('Disconnect'),
                  ),
                ] else
                  TextButton(
                    onPressed: () =>
                        setState(() => _editing = _Editing.disconnect),
                    child: const Text('Disconnect'),
                  ),
              ],
            ],
          ),

          if (_editing == _Editing.name)
            Padding(
              padding: const EdgeInsets.only(top: 8, bottom: 4),
              child: Row(
                children: [
                  Expanded(
                    child: TextField(
                      controller: _name,
                      autofocus: true,
                      onSubmitted: (_) => _saveName(),
                      decoration: const InputDecoration(
                        isDense: true,
                        border: OutlineInputBorder(),
                        hintText: 'What this account is, in your words',
                        helperText: 'Leave it empty to go back to the number.',
                      ),
                    ),
                  ),
                  const SizedBox(width: 8),
                  FilledButton(onPressed: _saveName, child: const Text('Save')),
                ],
              ),
            ),

          if (_editing == _Editing.placement)
            Padding(
              padding: const EdgeInsets.only(top: 8, bottom: 4),
              child: Wrap(
                spacing: 8,
                runSpacing: 6,
                children: [
                  ChoiceChip(
                    label: const Text('Company level'),
                    selected: c['leads_franchise_id'] == null,
                    showCheckmark: false,
                    onSelected: (_) => _place(null),
                  ),
                  for (final f
                      in widget.session.me?.franchises ??
                          const <FranchiseSummary>[])
                    ChoiceChip(
                      label: Text(f.name),
                      selected: c['leads_franchise_id'] == f.id,
                      showCheckmark: false,
                      onSelected: (_) => _place(f.id),
                    ),
                ],
              ),
            ),

          if (_editing == _Editing.disconnect && !_busy)
            Padding(
              padding: const EdgeInsets.only(top: 4),
              child: Text(
                'This removes the stored permission. Nothing already in EzBizi '
                'is deleted, and you can connect an account again afterwards.',
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                ),
              ),
            ),

          if (_said != null)
            Padding(
              padding: const EdgeInsets.only(top: 4),
              child: Text(
                _said!,
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.primary,
                ),
              ),
            ),

          if (_error != null)
            Padding(
              padding: const EdgeInsets.only(top: 4),
              child: Text(
                _error!,
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.error,
                ),
              ),
            ),
        ],
      ),
    );
  }
}

/// Desktop layout 1: one band, whatever the number of accounts.
///
/// It costs ONE row on the way to the leads at twenty-two accounts or at two
/// hundred, and the second line carries the only fact worth a permanent row —
/// how they are placed, which is the number that changes.
class LeadsAccountsBand extends StatelessWidget {
  const LeadsAccountsBand({
    super.key,
    required this.connections,
    required this.onManage,
    this.network = 'google_ads',
    this.noun = 'Google Ads',
  });

  final List<Map<String, dynamic>> connections;
  final VoidCallback onManage;

  /// The SocialNetworkMark key and the words on the band. Google Ads by
  /// default; Thumbtack passes its own. Every endpoint the list calls is
  /// provider-neutral, so this is the whole difference.
  final String network;
  final String noun;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final placed = placedCount(connections);
    final atCompany = connections.length - placed;

    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
      child: Row(
        children: [
          SocialNetworkMark(network: network, size: 20),
          const SizedBox(width: 8),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(
                  connections.length == 1
                      ? '1 $noun account'
                      : '${connections.length} $noun accounts',
                  style: theme.textTheme.bodyMedium,
                ),
                Text(
                  placed == 0
                      ? 'All at company level'
                      : '$atCompany at company level · $placed on '
                            '${placed == 1 ? 'a franchise' : 'franchises'}',
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: theme.colorScheme.onSurfaceVariant,
                  ),
                ),
              ],
            ),
          ),
          TextButton(onPressed: onManage, child: const Text('Manage')),
        ],
      ),
    );
  }
}

/// Mobile layout 4: a row that states its value.
///
/// The same shape as Your account on a phone. It carries the placed count
/// rather than only the total because that is the number that changes —
/// somebody checking whether their assignment took sees it without opening
/// anything.
class LeadsAccountsTile extends StatelessWidget {
  const LeadsAccountsTile({
    super.key,
    required this.connections,
    required this.onOpen,
    this.network = 'google_ads',
    this.noun = 'Google Ads',
  });

  final List<Map<String, dynamic>> connections;
  final VoidCallback onOpen;
  final String network;
  final String noun;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final placed = placedCount(connections);

    return ListTile(
      leading: SocialNetworkMark(network: network, size: 20),
      title: Text(noun, style: theme.textTheme.bodyMedium),
      subtitle: Text(
        '${connections.length} '
        '${connections.length == 1 ? 'account' : 'accounts'} · '
        '$placed placed',
        style: theme.textTheme.bodySmall,
      ),
      trailing: const Icon(Icons.chevron_right, size: 18),
      onTap: onOpen,
    );
  }
}
