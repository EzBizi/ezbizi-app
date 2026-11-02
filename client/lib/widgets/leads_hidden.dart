import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/session.dart';
import '../core/timestamps.dart';
import 'lead_thread.dart';
import 'side_panel.dart';

/// One grant sees, hides and unhides (Jose, 2026-10-29).
const leadsHiddenPermission = 'leads.hidden.view';

String _hiddenBy(Object? by) => by is Map
    ? '${by['name'] ?? ''}'.trim()
    : by is String
    ? by.trim()
    : '';

String _day(Object? iso) {
  final t = DateTime.tryParse('${iso ?? ''}')?.toLocal();
  return t == null ? '' : '${shortMonths[t.month - 1]} ${t.day}';
}

/// "Pat Lin · Oct 28", either half when only one is known.
String hiddenByLine(Object? by, Object? at) =>
    [_hiddenBy(by), _day(at)].where((s) => s.isNotEmpty).join(' · ');

/// Leads › Manage › Hidden (design 160, B1): hidden conversations and
/// always-hidden numbers, one list, a table on a desktop, cards on a phone.
class LeadsHidden extends StatefulWidget {
  const LeadsHidden({super.key, required this.api, this.session});

  final ApiClient api;
  final Session? session;

  @override
  State<LeadsHidden> createState() => _LeadsHiddenState();
}

class _LeadsHiddenState extends State<LeadsHidden> {
  final _search = TextEditingController();
  String _filter = 'all';
  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _leads = const [];
  List<Map<String, dynamic>> _numbers = const [];
  int _generation = 0;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    final generation = ++_generation;
    final q = _search.text.trim();
    final response = await widget.api.get('/leads/hidden', {
      if (q.isNotEmpty) 'q': q,
    });
    if (!mounted || generation != _generation) return;
    setState(() {
      _loading = false;
      if (!response.ok) {
        _error = response.message;
        return;
      }
      _error = null;
      final data = response.data as Map<String, dynamic>? ?? const {};
      _leads = (data['leads'] as List? ?? const [])
          .cast<Map<String, dynamic>>();
      _numbers = (data['numbers'] as List? ?? const [])
          .cast<Map<String, dynamic>>();
    });
  }

  Future<void> _act(Future<ApiResponse> call) async {
    final response = await call;
    if (!mounted) return;
    if (!response.ok) {
      ScaffoldMessenger.maybeOf(
        context,
      )?.showSnackBar(SnackBar(content: Text(response.message)));
      return;
    }
    await _load();
  }

  Future<void> _hideNumber() async {
    final saved = await showSidePanel<bool>(
      context,
      builder: (_) => _HideNumberPanel(api: widget.api),
    );
    if (saved == true) await _load();
  }

  Future<void> _open(Map<String, dynamic> lead) => showSidePanel<void>(
    context,
    builder: (_) => SidePanel(
      title: '${lead['contact_name'] ?? lead['phone'] ?? 'Hidden lead'}',
      subtitle: 'Hidden · read only',
      width: 560,
      scrollable: false,
      child: LeadThread(
        key: ValueKey('hidden-thread-${lead['id']}'),
        api: widget.api,
        lead: lead,
        session: widget.session,
      ),
    ),
  );

  List<_Row> get _rows => [
    if (_filter != 'numbers')
      for (final l in _leads)
        _Row(
          id: '${l['id']}',
          who:
              '${(l['contact_name'] as String?)?.isNotEmpty == true ? l['contact_name'] : l['phone'] ?? ''}',
          kind: l['always'] == true ? 'Conversation · number' : 'Conversation',
          by: hiddenByLine(l['hidden_by'], l['hidden_at']),
          reason: l['reason'] as String?,
          lead: l,
        ),
    if (_filter != 'conversations')
      for (final n in _numbers)
        _Row(
          id: '${n['id']}',
          who: '${n['digits'] ?? ''}',
          kind: 'Always hide number',
          by: hiddenByLine(n['hidden_by'], n['hidden_at']),
          reason: n['reason'] as String?,
        ),
  ];

  Widget _action(_Row r) => r.lead == null
      ? OutlinedButton(
          key: ValueKey('stop-hiding-${r.id}'),
          onPressed: () =>
              _act(widget.api.delete('/leads/hidden-numbers/${r.id}')),
          child: const Text('Stop hiding'),
        )
      : Wrap(
          spacing: 6,
          children: [
            TextButton(
              key: ValueKey('hidden-open-${r.id}'),
              onPressed: () => _open(r.lead!),
              child: const Text('Open'),
            ),
            OutlinedButton(
              key: ValueKey('unhide-${r.id}'),
              onPressed: () =>
                  _act(widget.api.post('/leads/${r.id}/unhide', const {})),
              child: const Text('Unhide'),
            ),
          ],
        );

  Widget _kindChip(ThemeData theme, String kind) => Container(
    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
    decoration: BoxDecoration(
      color: theme.colorScheme.surfaceContainerHighest,
      borderRadius: BorderRadius.circular(999),
    ),
    child: Text(kind, style: theme.textTheme.labelSmall),
  );

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );

    Widget chip(String value, String label) => ChoiceChip(
      key: ValueKey('hidden-chip-$value'),
      showCheckmark: false,
      selected: _filter == value,
      label: Text(label),
      onSelected: (_) => setState(() => _filter = value),
    );

    final rows = _rows;

    return LayoutBuilder(
      builder: (context, box) {
        final narrow = box.maxWidth < 700;
        return Padding(
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Wrap(
                spacing: 8,
                runSpacing: 8,
                crossAxisAlignment: WrapCrossAlignment.center,
                children: [
                  SizedBox(
                    width: narrow ? box.maxWidth - 32 : 260,
                    child: TextField(
                      key: const ValueKey('hidden-search'),
                      controller: _search,
                      onChanged: (_) => _load(),
                      decoration: const InputDecoration(
                        isDense: true,
                        hintText: 'Name or number',
                        prefixIcon: Icon(Icons.search, size: 18),
                      ),
                    ),
                  ),
                  chip('all', 'All'),
                  chip('conversations', 'Conversations'),
                  chip('numbers', 'Always-hidden numbers'),
                  FilledButton.icon(
                    key: const ValueKey('hide-number-open'),
                    onPressed: _hideNumber,
                    icon: const Icon(Icons.visibility_off_outlined, size: 18),
                    label: const Text('Hide a number'),
                  ),
                ],
              ),
              const SizedBox(height: 12),
              Expanded(
                child: _loading
                    ? const Center(child: CircularProgressIndicator())
                    : _error != null
                    ? Center(child: Text(_error!, style: muted))
                    : rows.isEmpty
                    ? Center(
                        child: Text(
                          'Nothing is hidden.',
                          key: const ValueKey('hidden-empty'),
                          style: muted,
                        ),
                      )
                    : ListView(
                        key: const ValueKey('hidden-list'),
                        children: [
                          if (!narrow)
                            Padding(
                              padding: const EdgeInsets.symmetric(
                                horizontal: 8,
                                vertical: 6,
                              ),
                              child: Row(
                                children: [
                                  Expanded(
                                    flex: 3,
                                    child: Text('WHO', style: muted),
                                  ),
                                  Expanded(
                                    flex: 2,
                                    child: Text('KIND', style: muted),
                                  ),
                                  Expanded(
                                    flex: 3,
                                    child: Text('HIDDEN BY', style: muted),
                                  ),
                                  Expanded(
                                    flex: 3,
                                    child: Text('REASON', style: muted),
                                  ),
                                  const SizedBox(width: 190),
                                ],
                              ),
                            ),
                          for (final r in rows)
                            narrow
                                ? Card(
                                    key: ValueKey('hidden-row-${r.id}'),
                                    child: Padding(
                                      padding: const EdgeInsets.all(12),
                                      child: Column(
                                        crossAxisAlignment:
                                            CrossAxisAlignment.start,
                                        children: [
                                          Row(
                                            children: [
                                              Expanded(
                                                child: Text(
                                                  r.who,
                                                  style: theme
                                                      .textTheme
                                                      .titleSmall,
                                                ),
                                              ),
                                              _kindChip(theme, r.kind),
                                            ],
                                          ),
                                          const SizedBox(height: 4),
                                          Text(r.by, style: muted),
                                          if (r.reason?.isNotEmpty == true)
                                            Text(r.reason!),
                                          Align(
                                            alignment:
                                                AlignmentDirectional.centerEnd,
                                            child: _action(r),
                                          ),
                                        ],
                                      ),
                                    ),
                                  )
                                : Container(
                                    key: ValueKey('hidden-row-${r.id}'),
                                    padding: const EdgeInsets.symmetric(
                                      horizontal: 8,
                                      vertical: 6,
                                    ),
                                    decoration: BoxDecoration(
                                      border: Border(
                                        top: BorderSide(
                                          color:
                                              theme.colorScheme.outlineVariant,
                                        ),
                                      ),
                                    ),
                                    child: Row(
                                      children: [
                                        Expanded(
                                          flex: 3,
                                          child: Text(
                                            r.who,
                                            style: theme.textTheme.titleSmall,
                                          ),
                                        ),
                                        Expanded(
                                          flex: 2,
                                          child: Align(
                                            alignment: AlignmentDirectional
                                                .centerStart,
                                            child: _kindChip(theme, r.kind),
                                          ),
                                        ),
                                        Expanded(flex: 3, child: Text(r.by)),
                                        Expanded(
                                          flex: 3,
                                          child: Text(
                                            r.reason?.isNotEmpty == true
                                                ? r.reason!
                                                : '—',
                                            style: r.reason?.isNotEmpty == true
                                                ? null
                                                : muted,
                                          ),
                                        ),
                                        SizedBox(
                                          width: 190,
                                          child: Align(
                                            alignment:
                                                AlignmentDirectional.centerEnd,
                                            child: _action(r),
                                          ),
                                        ),
                                      ],
                                    ),
                                  ),
                        ],
                      ),
              ),
            ],
          ),
        );
      },
    );
  }
}

class _Row {
  const _Row({
    required this.id,
    required this.who,
    required this.kind,
    required this.by,
    this.reason,
    this.lead,
  });

  final String id;
  final String who;
  final String kind;
  final String by;
  final String? reason;

  /// Null for an always-hidden number.
  final Map<String, dynamic>? lead;
}

class _HideNumberPanel extends StatefulWidget {
  const _HideNumberPanel({required this.api});

  final ApiClient api;

  @override
  State<_HideNumberPanel> createState() => _HideNumberPanelState();
}

class _HideNumberPanelState extends State<_HideNumberPanel> {
  final _phone = TextEditingController();
  final _reason = TextEditingController();
  bool _saving = false;
  String? _error;

  @override
  void dispose() {
    _phone.dispose();
    _reason.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    if (_phone.text.trim().isEmpty) {
      setState(() => _error = 'Type the number to hide.');
      return;
    }
    setState(() {
      _saving = true;
      _error = null;
    });
    final reason = _reason.text.trim();
    final response = await widget.api.post('/leads/hidden-numbers', {
      'phone': _phone.text.trim(),
      'reason': reason.isEmpty ? null : reason,
    });
    if (!mounted) return;
    if (!response.ok) {
      setState(() {
        _saving = false;
        _error = response.message;
      });
      return;
    }
    Navigator.of(context).pop(true);
  }

  @override
  Widget build(BuildContext context) => SidePanel(
    title: 'Hide a number',
    subtitle: 'Its conversations leave every list for everyone else.',
    actions: [
      TextButton(
        onPressed: () => Navigator.of(context).pop(false),
        child: const Text('Cancel'),
      ),
      FilledButton(
        key: const ValueKey('hide-number-save'),
        onPressed: _saving ? null : _save,
        child: const Text('Hide'),
      ),
    ],
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        TextField(
          key: const ValueKey('hide-number-phone'),
          controller: _phone,
          keyboardType: TextInputType.phone,
          decoration: const InputDecoration(labelText: 'Phone'),
        ),
        const SizedBox(height: 12),
        TextField(
          key: const ValueKey('hide-number-reason'),
          controller: _reason,
          maxLength: 255,
          decoration: const InputDecoration(labelText: 'Reason (optional)'),
        ),
        if (_error != null)
          Text(
            _error!,
            style: TextStyle(color: Theme.of(context).colorScheme.error),
          ),
      ],
    ),
  );
}

/// The ⋯ menu on a lead's header, with the tick only.
class LeadHideMenu extends StatelessWidget {
  const LeadHideMenu({
    super.key,
    required this.api,
    required this.lead,
    this.onHidden,
  });

  final ApiClient api;
  final Map<String, dynamic> lead;
  final Future<void> Function()? onHidden;

  Future<void> _hide(
    BuildContext context, {
    String? reason,
    bool always = false,
  }) async {
    final response = await api.post('/leads/${lead['id']}/hide', {
      if (always) 'always': true,
      if (reason != null && reason.isNotEmpty) 'reason': reason,
    });
    if (!context.mounted) return;
    if (!response.ok) {
      ScaffoldMessenger.maybeOf(
        context,
      )?.showSnackBar(SnackBar(content: Text(response.message)));
      return;
    }
    ScaffoldMessenger.maybeOf(
      context,
    )?.showSnackBar(const SnackBar(content: Text('Hidden')));
    await onHidden?.call();
  }

  @override
  Widget build(BuildContext context) => PopupMenuButton<String>(
    key: const ValueKey('lead-more'),
    tooltip: 'More',
    icon: const Icon(Icons.more_horiz),
    onSelected: (v) async {
      if (v == 'hide') {
        await _hide(context);
      } else {
        final reason = await showSidePanel<String>(
          context,
          builder: (_) => const _AlwaysHidePanel(),
        );
        if (reason != null && context.mounted) {
          await _hide(context, reason: reason, always: true);
        }
      }
    },
    itemBuilder: (_) => const [
      PopupMenuItem(
        key: ValueKey('lead-hide'),
        value: 'hide',
        child: Text('Hide this conversation'),
      ),
      PopupMenuItem(
        key: ValueKey('lead-always-hide'),
        value: 'always',
        child: Text('Always hide this number…'),
      ),
    ],
  );
}

/// Returns the reason ('' for none), or null when cancelled.
class _AlwaysHidePanel extends StatefulWidget {
  const _AlwaysHidePanel();

  @override
  State<_AlwaysHidePanel> createState() => _AlwaysHidePanelState();
}

class _AlwaysHidePanelState extends State<_AlwaysHidePanel> {
  final _reason = TextEditingController();

  @override
  void dispose() {
    _reason.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => SidePanel(
    title: 'Always hide this number',
    subtitle:
        'Every conversation from it, now and later, leaves the lists for '
        'everyone without this permission.',
    actions: [
      TextButton(
        onPressed: () => Navigator.of(context).pop(),
        child: const Text('Cancel'),
      ),
      FilledButton(
        key: const ValueKey('always-hide-save'),
        onPressed: () => Navigator.of(context).pop(_reason.text.trim()),
        child: const Text('Hide'),
      ),
    ],
    child: TextField(
      key: const ValueKey('always-hide-reason'),
      controller: _reason,
      maxLength: 255,
      decoration: const InputDecoration(labelText: 'Reason (optional)'),
    ),
  );
}
