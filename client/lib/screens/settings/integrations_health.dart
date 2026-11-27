import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/branch_options.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../widgets/side_panel.dart';

/// Settings › Integrations, option A of docs/previews/integrations-cards.html
/// (Jose, 2026-11-12, after he hated the list in 0.0.0566): the old cards,
/// one status sentence per card, and a short note above them with one line
/// per CAUSE — never one row per account.
///
/// Everything here reads `GET /integrations/overview`. When that call fails
/// the screen simply has no note and no status lines; the grid is unchanged.

/// The overview names phone lines `phone`; the card is `phone_numbers`.
String cardProviderOf(String overviewProvider) =>
    overviewProvider == 'phone' ? 'phone_numbers' : overviewProvider;

/// One cause: the same check failing for the same reason on [count]
/// connections of one app.
class HealthCause {
  HealthCause({
    required this.provider,
    required this.appLabel,
    required this.checkKey,
    required this.reason,
    required this.waitingOn,
    required this.fix,
    required this.labels,
    required this.ids,
    this.summary,
  });

  final String provider;
  final String appLabel;
  final String checkKey;
  final String reason;

  /// The outside company the fix waits on (Twilio's A2P review), or null when
  /// somebody here can act.
  final String? waitingOn;
  final Map<String, dynamic>? fix;
  final List<String> labels;
  final List<String> ids;

  /// The server's plain words for the cause (no URLs, ids or API errors);
  /// null from an older server, which falls back to [reason].
  final String? summary;

  /// The raw reason per account, for "Show steps" / "Details" only.
  final List<String> details = [];

  int get count => ids.length;

  /// "14 phone lines", "3 Google Ads accounts", or the one account's name.
  String get subject {
    if (count == 1) return labels.first;
    if (provider == 'phone') return '$count phone lines';
    if (provider == 'workiz') return '$count Workiz branches';
    return '$count $appLabel accounts';
  }

  /// "needs …" / "has …" for one, "need …" / "have …" for several.
  String get _verb {
    final s = summary!;
    if (count == 1) return s;
    if (s.startsWith('needs ')) return 'need ${s.substring(6)}';
    if (s.startsWith('has ')) return 'have ${s.substring(4)}';
    return s;
  }

  String get sentence {
    if (waitingOn != null) {
      final what = checkKey == 'texting'
          ? "can't text yet"
          : summary != null
          ? _verb
          : 'wait${count == 1 ? 's' : ''}';
      return '$subject $what: waiting on $waitingOn.';
    }
    if (summary != null) return '$subject $_verb.';
    return '$subject: $reason';
  }

  /// Whether there is more to read than the sentence.
  bool get hasDetails => details.any((d) => d.isNotEmpty && d != summary);
}

List<Map<String, dynamic>> _apps(Map<String, dynamic>? overview) => [
  for (final a in (overview?['apps'] as List<dynamic>?) ?? const [])
    if (a is Map<String, dynamic>) a,
];

List<Map<String, dynamic>> _connections(Map<String, dynamic> app) => [
  for (final c in (app['connections'] as List<dynamic>?) ?? const [])
    if (c is Map<String, dynamic>) c,
];

List<Map<String, dynamic>> _checks(Map<String, dynamic> connection) => [
  for (final k in (connection['checks'] as List<dynamic>?) ?? const [])
    if (k is Map<String, dynamic>) k,
];

/// Failing checks grouped by (app, check, reason). Things somebody can fix
/// come first, then what waits on someone else.
List<HealthCause> groupCauses(Map<String, dynamic>? overview) {
  final by = <String, HealthCause>{};
  for (final app in _apps(overview)) {
    final provider = app['provider'] as String? ?? '';
    for (final c in _connections(app)) {
      if (c['status'] == 'off') continue;
      for (final k in _checks(c)) {
        if (k['state'] != 'fail') continue;
        final reason = (k['reason'] as String?) ?? '${k['label']} failed.';
        final summary = k['summary'] as String?;
        final id = '$provider|${k['key']}|${summary ?? reason}';
        final cause = by.putIfAbsent(
          id,
          () => HealthCause(
            provider: provider,
            appLabel: app['label'] as String? ?? provider,
            checkKey: k['key'] as String? ?? '',
            reason: reason,
            waitingOn: k['waiting_on'] as String?,
            fix: k['fix'] as Map<String, dynamic>?,
            labels: [],
            ids: [],
            summary: summary,
          ),
        );
        cause.details.add(reason);
        cause.labels.add(c['label'] as String? ?? '');
        cause.ids.add(c['id'] as String? ?? '');
      }
    }
  }
  final out = by.values.toList()
    ..sort((a, b) {
      final w = (a.waitingOn == null ? 0 : 1) - (b.waitingOn == null ? 0 : 1);
      return w != 0 ? w : b.count - a.count;
    });
  return out;
}

/// Connections whose only open item is the branch their leads go to.
List<Map<String, dynamic>> missingBranches(Map<String, dynamic>? overview) => [
  for (final app in _apps(overview))
    for (final c in _connections(app))
      if (c['status'] == 'setup' &&
          _checks(
            c,
          ).any((k) => (k['fix'] as Map?)?['action'] == 'choose_branch'))
        c,
];

enum StatusTone { ok, broken, waiting, none }

/// The one sentence a card carries, from every overview app it draws
/// ([providers] are card keys). Null when the overview has nothing for it.
(String, StatusTone)? cardStatus(
  Map<String, dynamic>? overview,
  Iterable<String> providers,
) {
  final want = providers.toSet();
  final apps = _apps(
    overview,
  ).where((a) => want.contains(cardProviderOf(a['provider'] as String? ?? '')));
  final rows = [for (final a in apps) ..._connections(a)];
  if (rows.isEmpty) return null;

  bool failing(Map<String, dynamic> c, {required bool external}) =>
      c['status'] != 'off' &&
      _checks(c).any(
        (k) => k['state'] == 'fail' && (k['waiting_on'] != null) == external,
      );

  final broken = rows.where((c) => failing(c, external: false)).length;
  if (broken > 0) {
    return (
      broken == 1 ? '1 needs fixing' : '$broken need fixing',
      StatusTone.broken,
    );
  }
  final waiting = rows.where((c) => failing(c, external: true)).toList();
  if (waiting.isNotEmpty) {
    final who = _checks(
      waiting.first,
    ).firstWhere((k) => k['waiting_on'] != null)['waiting_on'];
    return (
      '${rows.length} ${rows.length == 1 ? 'line' : 'lines'} · '
          'texting waits on $who',
      StatusTone.waiting,
    );
  }
  final live = rows.where((c) => c['status'] != 'off').toList();
  if (live.length == 1) {
    final choices = [
      for (final ch in (live.first['choices'] as List<dynamic>?) ?? const [])
        if (ch is Map) '${ch['value']}',
    ];
    return (
      choices.isEmpty ? 'Working' : 'Connected · ${choices.first}',
      StatusTone.ok,
    );
  }
  if (live.isEmpty) return ('Switched off', StatusTone.none);
  return ('${live.length} accounts working', StatusTone.ok);
}

/// The colour for a [StatusTone]: red only for what somebody here must fix.
Color statusColour(BuildContext context, StatusTone tone) {
  final scheme = Theme.of(context).colorScheme;
  return switch (tone) {
    StatusTone.ok => StateColour.of(context).on,
    StatusTone.broken => scheme.error,
    StatusTone.waiting => scheme.primary,
    StatusTone.none => scheme.onSurfaceVariant,
  };
}

/// "Needs attention", the LAST section of the screen (Jose, 2026-11-13):
/// one plain line per cause; raw detail only behind Show steps / Details.
/// Draws nothing when there is nothing to say.
class IntegrationsHealthNote extends StatelessWidget {
  const IntegrationsHealthNote({
    super.key,
    required this.api,
    required this.me,
    required this.overview,
    required this.onFix,
    required this.onChanged,
  });

  final ApiClient api;

  /// For "Company · Branch" on the branch chips.
  final Me? me;
  final Map<String, dynamic>? overview;

  /// Runs a cause's fix (reconnect, retry, open_url) — the screen owns the
  /// flows it leads to.
  final void Function(HealthCause cause) onFix;
  final VoidCallback onChanged;

  @override
  Widget build(BuildContext context) {
    final causes = groupCauses(overview);
    final branchless = missingBranches(overview);
    if (causes.isEmpty && branchless.isEmpty) return const SizedBox.shrink();

    final theme = Theme.of(context);
    return Container(
      key: const ValueKey('integrations-note'),
      margin: const EdgeInsets.only(top: 4, bottom: 16),
      padding: const EdgeInsets.fromLTRB(12, 8, 12, 8),
      decoration: BoxDecoration(
        color: theme.colorScheme.surface,
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: theme.colorScheme.outlineVariant),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Padding(
            padding: const EdgeInsets.only(top: 4, bottom: 4),
            child: Text(
              'Needs attention · ${causes.length + (branchless.isEmpty ? 0 : 1)}',
              key: const ValueKey('integrations-section:attention'),
              style: theme.textTheme.titleSmall,
            ),
          ),
          for (final c in causes)
            Padding(
              key: ValueKey('integrations-cause:${c.provider}:${c.checkKey}'),
              padding: const EdgeInsets.symmetric(vertical: 3),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Padding(
                    padding: const EdgeInsets.only(top: 5, right: 8),
                    child: _Dot(
                      statusColour(
                        context,
                        c.waitingOn == null
                            ? StatusTone.broken
                            : StatusTone.waiting,
                      ),
                    ),
                  ),
                  Expanded(
                    child: Text(c.sentence, style: theme.textTheme.bodyMedium),
                  ),
                  if (c.hasDetails)
                    TextButton(
                      key: ValueKey(
                        'integrations-details:${c.provider}:${c.checkKey}',
                      ),
                      onPressed: () => showSidePanel<void>(
                        context,
                        builder: (_) => SidePanel(
                          title: c.subject,
                          subtitle: c.sentence,
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              for (var i = 0; i < c.count; i++)
                                Padding(
                                  padding: const EdgeInsets.only(bottom: 12),
                                  child: Column(
                                    crossAxisAlignment:
                                        CrossAxisAlignment.start,
                                    children: [
                                      Text(
                                        c.labels[i],
                                        style: theme.textTheme.titleSmall,
                                      ),
                                      SelectableText(
                                        c.details[i],
                                        style: theme.textTheme.bodySmall,
                                      ),
                                    ],
                                  ),
                                ),
                            ],
                          ),
                        ),
                      ),
                      child: Text(
                        c.checkKey.contains('webhook')
                            ? 'Show steps'
                            : 'Details',
                      ),
                    ),
                  // "Try again" on a step only a person in Workiz can do was
                  // nine dead buttons: retry stays in the account's own page.
                  if (c.waitingOn == null &&
                      c.fix != null &&
                      c.fix!['action'] != 'choose_branch' &&
                      c.fix!['action'] != 'retry')
                    TextButton(
                      key: ValueKey(
                        'integrations-fix:${c.provider}:${c.checkKey}',
                      ),
                      onPressed: () => onFix(c),
                      child: Text(c.fix!['label'] as String? ?? 'Open'),
                    ),
                ],
              ),
            ),
          if (branchless.isNotEmpty)
            Padding(
              padding: const EdgeInsets.only(top: 2),
              child: Row(
                children: [
                  Expanded(
                    child: Text(
                      '${branchless.length} '
                      '${branchless.length == 1 ? 'account has' : 'accounts have'}'
                      ' no branch for their leads.',
                      key: const ValueKey('integrations-branchless'),
                      style: theme.textTheme.bodySmall?.copyWith(
                        color: theme.colorScheme.onSurfaceVariant,
                      ),
                    ),
                  ),
                  TextButton(
                    key: const ValueKey('integrations-choose-later'),
                    onPressed: () async {
                      await showSidePanel<void>(
                        context,
                        builder: (_) => _BranchPanel(
                          api: api,
                          me: me,
                          rows: branchless,
                          branches: [
                            for (final b
                                in (overview?['branches'] as List<dynamic>?) ??
                                    const [])
                              if (b is Map<String, dynamic>) b,
                          ],
                        ),
                      );
                      onChanged();
                    },
                    child: const Text('Choose later ›'),
                  ),
                ],
              ),
            ),
        ],
      ),
    );
  }
}

class _Dot extends StatelessWidget {
  const _Dot(this.colour);
  final Color colour;

  @override
  Widget build(BuildContext context) => Container(
    width: 8,
    height: 8,
    decoration: BoxDecoration(color: colour, shape: BoxShape.circle),
  );
}

/// A card's status sentence, as a dot and a line.
class IntegrationStatusLine extends StatelessWidget {
  const IntegrationStatusLine(this.status, {super.key});

  final (String, StatusTone) status;

  @override
  Widget build(BuildContext context) {
    final colour = statusColour(context, status.$2);
    return Row(
      children: [
        _Dot(colour),
        const SizedBox(width: 6),
        Flexible(
          child: Text(
            status.$1,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: Theme.of(
              context,
            ).textTheme.labelSmall?.copyWith(color: colour),
          ),
        ),
      ],
    );
  }
}

/// Every account without a branch, each with the branches as chips.
class _BranchPanel extends StatefulWidget {
  const _BranchPanel({
    required this.api,
    required this.me,
    required this.rows,
    required this.branches,
  });

  final ApiClient api;
  final Me? me;
  final List<Map<String, dynamic>> rows;
  final List<Map<String, dynamic>> branches;

  @override
  State<_BranchPanel> createState() => _BranchPanelState();
}

class _BranchPanelState extends State<_BranchPanel> {
  final Map<String, String> _set = {};
  String? _error;

  Future<void> _choose(String id, String branch) async {
    final r = await widget.api.patch(
      '/social/connections/$id/leads-franchise',
      {'leads_franchise_id': branch},
    );
    if (!mounted) return;
    setState(() {
      if (r.ok) {
        _set[id] = branch;
        _error = null;
      } else {
        _error = r.message;
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return SidePanel(
      title: 'Branches for leads',
      subtitle: 'Which branch each account files its leads on.',
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (_error != null)
            Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: Text(
                _error!,
                style: TextStyle(color: theme.colorScheme.error),
              ),
            ),
          for (final r in widget.rows)
            Padding(
              padding: const EdgeInsets.only(bottom: 14),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    r['label'] as String? ?? '',
                    style: theme.textTheme.titleSmall,
                  ),
                  const SizedBox(height: 6),
                  Wrap(
                    spacing: 6,
                    runSpacing: 6,
                    children: [
                      for (final b in widget.branches)
                        ChoiceChip(
                          key: ValueKey('branch:${r['id']}:${b['id']}'),
                          label: Text(
                            branchLabelOf(
                              widget.me,
                              b['id'] as String?,
                              b['label'] as String? ?? '',
                            ),
                          ),
                          selected: _set[r['id']] == b['id'],
                          onSelected: (_) =>
                              _choose(r['id'] as String, b['id'] as String),
                        ),
                    ],
                  ),
                ],
              ),
            ),
        ],
      ),
    );
  }
}
