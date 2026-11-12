import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/api_client.dart';
import '../../core/theme.dart';
import '../../widgets/list_search.dart';
import '../../widgets/side_panel.dart';
import '../../widgets/social_network_mark.dart';
import '../../widgets/status_pill.dart';

/// Settings › Integrations, the health half (Jose 2026-11-12, options A and C
/// of docs/previews/integrations-patterns.html): a "Needs your attention"
/// block, then one rollup row per app that expands into its accounts. An
/// account opens [IntegrationAccountPanel].
///
/// Reads `GET /integrations/overview` once; the screen hands the answer in.
class IntegrationsOverview extends StatefulWidget {
  const IntegrationsOverview({
    super.key,
    required this.api,
    required this.data,
    required this.onOpenApp,
    required this.onChanged,
    this.phone = false,
  });

  final ApiClient api;

  /// The overview's `data`: summary, apps, branches.
  final Map<String, dynamic> data;

  /// Opens the provider's own pane (connect, disconnect, edit live there).
  final void Function(String provider) onOpenApp;

  /// Something was saved; reload.
  final VoidCallback onChanged;

  final bool phone;

  @override
  State<IntegrationsOverview> createState() => _IntegrationsOverviewState();
}

List<Map<String, dynamic>> _maps(Object? v) =>
    ((v as List<dynamic>?) ?? const []).whereType<Map<String, dynamic>>().toList();

bool _problem(Map<String, dynamic> c) =>
    c['status'] == 'broken' || c['status'] == 'setup';

/// The first check that wants something done: a failure, else a pending fix.
Map<String, dynamic>? _worst(Map<String, dynamic> c) {
  final checks = _maps(c['checks']);
  for (final k in checks) {
    if (k['state'] == 'fail') return k;
  }
  for (final k in checks) {
    if (k['fix'] != null && k['state'] != 'pass') return k;
  }
  return null;
}

String ago(String? iso) {
  final t = DateTime.tryParse(iso ?? '');
  if (t == null) return '';
  final d = DateTime.now().difference(t.toLocal());
  if (d.isNegative) {
    final m = -d.inMinutes;
    return m < 60 ? 'in $m min' : (m < 1440 ? 'in ${m ~/ 60} h' : 'in ${m ~/ 1440} d');
  }
  if (d.inMinutes < 1) return 'just now';
  if (d.inMinutes < 60) return '${d.inMinutes} min ago';
  if (d.inHours < 24) return '${d.inHours} h ago';
  return '${d.inDays} d ago';
}

class _IntegrationsOverviewState extends State<IntegrationsOverview> {
  final _open = <String>{};
  final _query = <String, String>{};
  final _all = <String>{};

  @override
  void initState() {
    super.initState();
    // Apps with a problem start expanded.
    for (final app in _maps(widget.data['apps'])) {
      if (_maps(app['connections']).any(_problem)) {
        _open.add(app['provider'] as String);
      }
    }
  }

  List<Map<String, dynamic>> get _branches => _maps(widget.data['branches']);

  Future<void> _openAccount(Map<String, dynamic> c) async {
    await showSidePanel<void>(
      context,
      builder: (_) => IntegrationAccountPanel(
        api: widget.api,
        connection: c,
        branches: _branches,
        onOpenApp: widget.onOpenApp,
      ),
    );
    widget.onChanged();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final apps = _maps(widget.data['apps']);
    final attention = [
      for (final app in apps)
        for (final c in _maps(app['connections']))
          if (_problem(c)) c,
    ]..sort((a, b) => a['status'] == 'broken' ? -1 : (b['status'] == 'broken' ? 1 : 0));

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (attention.isNotEmpty)
          Card(
            key: const ValueKey('integrations-attention'),
            margin: const EdgeInsets.only(bottom: 16),
            shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(12),
              side: BorderSide(
                color: attention.any((c) => c['status'] == 'broken')
                    ? theme.colorScheme.error
                    : StateColour.of(context).caution,
              ),
            ),
            child: Padding(
              padding: const EdgeInsets.all(12),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Text(
                    'Needs your attention · ${attention.length}',
                    style: theme.textTheme.titleSmall,
                  ),
                  for (final c in attention) _attentionLine(context, c),
                ],
              ),
            ),
          ),
        for (final app in apps) _appRow(context, app),
      ],
    );
  }

  Widget _attentionLine(BuildContext context, Map<String, dynamic> c) {
    final theme = Theme.of(context);
    final k = _worst(c);
    final broken = c['status'] == 'broken';
    final fix = k?['fix'] as Map<String, dynamic>?;
    final reason = [
      if (k != null) '${k['label']}: ${k['reason'] ?? (broken ? 'failed' : 'needs a choice')}',
      ago(k?['checked_at'] as String?),
    ].where((s) => s.isNotEmpty).join(' · ');
    return InkWell(
      key: ValueKey('integrations-attention:${c['id']}'),
      onTap: () => _openAccount(c),
      child: Padding(
        padding: const EdgeInsets.only(top: 10),
        child: Wrap(
          spacing: 8,
          runSpacing: 4,
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            SocialNetworkMark(network: c['provider'] as String? ?? '', size: 20),
            Text('${c['label']}', style: theme.textTheme.bodyMedium?.copyWith(fontWeight: FontWeight.w600)),
            StatusPill(broken ? 'Broken' : 'Setup', tone: broken ? Tone.bad : Tone.caution, dense: true),
            Text(
              reason,
              style: theme.textTheme.bodySmall?.copyWith(
                color: broken ? theme.colorScheme.error : null,
              ),
            ),
            if (fix != null)
              TextButton(
                key: ValueKey('integrations-attention-fix:${c['id']}'),
                onPressed: () => _openAccount(c),
                child: Text(fix['label'] as String? ?? 'Fix'),
              ),
          ],
        ),
      ),
    );
  }

  Widget _appRow(BuildContext context, Map<String, dynamic> app) {
    final theme = Theme.of(context);
    final provider = app['provider'] as String;
    final counts = (app['counts'] as Map<String, dynamic>?) ?? const {};
    final rows = _maps(app['connections']);
    final open = _open.contains(provider);
    int n(String k) => (counts[k] as num?)?.toInt() ?? 0;

    final q = (_query[provider] ?? '').trim().toLowerCase();
    final matching = rows
        .where((c) => q.isEmpty || '${c['label']} ${c['branch_label'] ?? ''}'.toLowerCase().contains(q))
        .toList()
      ..sort((a, b) => (_problem(a) ? 0 : 1).compareTo(_problem(b) ? 0 : 1));
    // ponytail: more than 10 shows the problems first, then "+N more".
    final cap = _all.contains(provider) || matching.length <= 10
        ? matching.length
        : matching.where(_problem).length.clamp(1, 10);
    final shown = matching.take(cap).toList();

    return Card(
      key: ValueKey('integrations-app:$provider'),
      margin: const EdgeInsets.only(bottom: 8),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          InkWell(
            key: ValueKey('integrations-app-toggle:$provider'),
            onTap: () => setState(() => open ? _open.remove(provider) : _open.add(provider)),
            child: Padding(
              padding: const EdgeInsets.all(12),
              child: Wrap(
                spacing: 8,
                runSpacing: 4,
                crossAxisAlignment: WrapCrossAlignment.center,
                children: [
                  SocialNetworkMark(network: provider, size: 24),
                  Text('${app['label']}', style: theme.textTheme.titleSmall),
                  Text('${rows.length} ${rows.length == 1 ? 'account' : 'accounts'}', style: theme.textTheme.bodySmall),
                  if (n('ok') > 0) StatusPill('${n('ok')} ok', tone: Tone.ok, dense: true),
                  if (n('setup') > 0) StatusPill('${n('setup')} setup', tone: Tone.caution, dense: true),
                  if (n('broken') > 0) StatusPill('${n('broken')} broken', tone: Tone.bad, dense: true),
                  if (!widget.phone && app['last_checked_at'] != null)
                    Text('checked ${ago(app['last_checked_at'] as String?)}', style: theme.textTheme.bodySmall),
                  TextButton(
                    key: ValueKey('integrations-app-manage:$provider'),
                    onPressed: () => widget.onOpenApp(provider),
                    child: const Text('Manage'),
                  ),
                  Icon(open ? Icons.expand_less : Icons.expand_more, size: 20),
                ],
              ),
            ),
          ),
          if (open) ...[
            if (rows.length > 10)
              Padding(
                padding: const EdgeInsets.fromLTRB(12, 0, 12, 8),
                child: ListSearch(
                  key: ValueKey('integrations-app-search:$provider'),
                  hint: 'Find an account',
                  width: widget.phone ? double.infinity : 280,
                  onChanged: (v) => setState(() => _query[provider] = v),
                ),
              ),
            for (final c in shown) _accountRow(context, c),
            if (shown.length < matching.length)
              Align(
                alignment: AlignmentDirectional.centerStart,
                child: TextButton(
                  key: ValueKey('integrations-app-more:$provider'),
                  onPressed: () => setState(() => _all.add(provider)),
                  child: Text('+${matching.length - shown.length} more'),
                ),
              ),
          ],
        ],
      ),
    );
  }

  Widget _accountRow(BuildContext context, Map<String, dynamic> c) {
    final theme = Theme.of(context);
    final status = c['status'] as String? ?? 'ok';
    return InkWell(
      key: ValueKey('integrations-account:${c['id']}'),
      onTap: () => _openAccount(c),
      child: Container(
        decoration: BoxDecoration(border: Border(top: BorderSide(color: theme.dividerColor))),
        padding: EdgeInsets.fromLTRB(widget.phone ? 12 : 44, 8, 12, 8),
        child: Wrap(
          spacing: 8,
          runSpacing: 4,
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            Text('${c['label']}', style: theme.textTheme.bodyMedium),
            if (c['branch_label'] != null)
              Text('${c['branch_label']}', style: theme.textTheme.bodySmall),
            for (final k in _maps(c['checks'])) _CheckChip(k),
            if (status == 'off') const StatusPill('Off', dense: true),
            if (((c['used_by'] as num?) ?? 0) > 0)
              Text('used by ${c['used_by']} automations', style: theme.textTheme.bodySmall),
          ],
        ),
      ),
    );
  }
}

class _CheckChip extends StatelessWidget {
  const _CheckChip(this.check);
  final Map<String, dynamic> check;

  @override
  Widget build(BuildContext context) {
    final (mark, tone) = switch (check['state']) {
      'pass' => ('✓', Tone.ok),
      'fail' => ('✗', Tone.bad),
      'off' => ('–', Tone.neutral),
      _ => ('?', Tone.neutral),
    };
    return StatusPill('$mark ${check['label']}', tone: tone, dense: true);
  }
}

/// Option C: one account, every check with its reason and time, the branch,
/// the choices made at connect, and Verify all. A SidePanel, never a dialog.
class IntegrationAccountPanel extends StatefulWidget {
  const IntegrationAccountPanel({
    super.key,
    required this.api,
    required this.connection,
    required this.branches,
    required this.onOpenApp,
  });

  final ApiClient api;
  final Map<String, dynamic> connection;
  final List<Map<String, dynamic>> branches;
  final void Function(String provider) onOpenApp;

  @override
  State<IntegrationAccountPanel> createState() => _IntegrationAccountPanelState();
}

class _IntegrationAccountPanelState extends State<IntegrationAccountPanel> {
  late Map<String, dynamic> _c = widget.connection;
  bool _busy = false;
  String? _said;
  bool _pickBranch = false;
  String? _purpose;

  static const _purposes = [
    ('company', 'Customer chat'),
    ('support', 'AI assistant'),
    ('reports', 'Reports'),
  ];

  @override
  void initState() {
    super.initState();
    final p = _maps(_c['choices']).where((x) => x['label'] == 'Purpose').firstOrNull;
    _purpose = (p?['value'] as String?)?.toLowerCase() ?? (_c['provider'] == 'whatsapp' ? 'company' : null);
    _pickBranch = _c['branch_id'] == null && _maps(_c['checks']).any((k) => k['key'] == 'branch');
  }

  String get _id => '${_c['id']}';

  Future<void> _recheck() async {
    setState(() {
      _busy = true;
      _said = null;
    });
    final r = await widget.api.post('/integrations/$_id/recheck', const {});
    if (!mounted) return;
    setState(() {
      _busy = false;
      if (r.ok && r.body['data'] is Map<String, dynamic>) {
        _c = r.body['data'] as Map<String, dynamic>;
      } else {
        _said = r.message;
      }
    });
  }

  Future<void> _saveBranch(Map<String, dynamic> b) async {
    setState(() => _busy = true);
    final r = await widget.api.patch('/social/connections/$_id/leads-franchise', {'leads_franchise_id': b['id']});
    if (!mounted) return;
    setState(() {
      _busy = false;
      if (r.ok) {
        _c = {..._c, 'branch_id': b['id'], 'branch_label': b['label']};
        _pickBranch = false;
      } else {
        _said = r.message;
      }
    });
  }

  Future<void> _savePurpose(String p) async {
    setState(() {
      _busy = true;
      _purpose = p;
    });
    final r = await widget.api.patch('/social/connections/$_id/whatsapp-purpose', {'purpose': p});
    if (!mounted) return;
    setState(() {
      _busy = false;
      if (!r.ok) _said = r.message;
    });
  }

  void _fix(Map<String, dynamic> fix) {
    switch (fix['action']) {
      case 'retry':
        _recheck();
      case 'choose_branch':
        setState(() => _pickBranch = true);
      case 'open_url':
        final u = Uri.tryParse(fix['url'] as String? ?? '');
        if (u != null) launchUrl(u, mode: LaunchMode.externalApplication);
      default: // reconnect: the provider's own connect flow.
        Navigator.of(context).pop();
        widget.onOpenApp(_c['provider'] as String);
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final small = theme.textTheme.bodySmall;
    final checks = _maps(_c['checks']);
    final failing = checks.where((k) => k['state'] == 'fail').length;

    return SidePanel(
      title: '${_c['label']}',
      subtitle: failing > 0 ? '$failing of ${checks.length} checks failing' : 'Every check passes',
      headerActions: [
        TextButton(
          key: const ValueKey('integration-verify-all'),
          onPressed: _busy ? null : _recheck,
          child: const Text('Verify all'),
        ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (_said != null)
            Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: Text(_said!, key: const ValueKey('integration-said'), style: small),
            ),
          // Branch: its label, or inline chips to pick one.
          Text('Branch', style: theme.textTheme.labelLarge),
          const SizedBox(height: 4),
          if (_pickBranch)
            Wrap(
              spacing: 6,
              runSpacing: 6,
              children: [
                for (final b in widget.branches)
                  ActionChip(
                    key: ValueKey('integration-branch:${b['id']}'),
                    label: Text('${b['label']}'),
                    onPressed: _busy ? null : () => _saveBranch(b),
                  ),
              ],
            )
          else
            Text('${_c['branch_label'] ?? 'Everywhere in this view'}', style: small),
          const SizedBox(height: 12),
          if (_c['provider'] == 'whatsapp') ...[
            Text('What is this number for?', style: theme.textTheme.labelLarge),
            const SizedBox(height: 4),
            Wrap(
              spacing: 6,
              children: [
                for (final (v, l) in _purposes)
                  ChoiceChip(
                    key: ValueKey('integration-purpose:$v'),
                    label: Text(l),
                    selected: _purpose == v,
                    onSelected: _busy ? null : (_) => _savePurpose(v),
                  ),
              ],
            ),
            if (_purpose == 'reports')
              Padding(
                padding: const EdgeInsets.only(top: 4),
                child: Text('Moves Reports off the number that sends them now.', style: small),
              ),
            const SizedBox(height: 12),
          ],
          for (final ch in _maps(_c['choices']))
            if (!(ch['label'] == 'Purpose' && _c['provider'] == 'whatsapp'))
              Padding(
                padding: const EdgeInsets.only(bottom: 4),
                child: Text('${ch['label']}: ${ch['value']}', style: small),
              ),
          const SizedBox(height: 8),
          Text('Checks', style: theme.textTheme.labelLarge),
          for (final k in checks) _checkRow(context, k),
        ],
      ),
    );
  }

  Widget _checkRow(BuildContext context, Map<String, dynamic> k) {
    final theme = Theme.of(context);
    final state = k['state'] as String?;
    final colour = switch (state) {
      'pass' => StateColour.of(context).on,
      'fail' => theme.colorScheme.error,
      'off' => theme.colorScheme.outline,
      _ => theme.colorScheme.outlineVariant,
    };
    final fix = k['fix'] as Map<String, dynamic>?;
    final next = ago(k['next_retry_at'] as String?);
    return Container(
      key: ValueKey('integration-check:${k['key']}'),
      padding: const EdgeInsets.symmetric(vertical: 8),
      decoration: BoxDecoration(border: Border(bottom: BorderSide(color: theme.dividerColor))),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Padding(
            padding: const EdgeInsets.only(top: 3, right: 10),
            child: Icon(
              state == 'pass' ? Icons.check_circle : (state == 'fail' ? Icons.cancel : Icons.remove_circle_outline),
              size: 16,
              color: colour,
            ),
          ),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('${k['label']}', style: theme.textTheme.bodyMedium),
                if (k['reason'] != null)
                  Text(
                    '${k['reason']}',
                    style: theme.textTheme.bodySmall?.copyWith(color: state == 'fail' ? theme.colorScheme.error : null),
                  ),
                if (state == 'fail' && next.startsWith('in '))
                  Text('Next automatic check $next', style: theme.textTheme.bodySmall),
                if (fix != null && state != 'pass')
                  TextButton(
                    key: ValueKey('integration-fix:${k['key']}'),
                    onPressed: _busy ? null : () => _fix(fix),
                    child: Text(fix['label'] as String? ?? 'Fix'),
                  ),
              ],
            ),
          ),
          Text(ago(k['checked_at'] as String?), style: theme.textTheme.bodySmall),
        ],
      ),
    );
  }
}
