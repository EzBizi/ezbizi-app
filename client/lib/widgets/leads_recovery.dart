// Leads › Manage › Recovery (Jose, 2026-11-16: "These Messages should be
// under a new tab in leads called Recovery"): the automatic texts that win
// back a missed, unbooked or after-hours enquiry, each with its switch, its
// words, a preview for a real brand and what it sent this week.
//
// A rule is edited through PATCH /automations/{id}, the builder's own call,
// with its conditions AND actions every time, and the saved version read back
// (11-12: an actions-only PATCH wiped a rule's conditions). The switch sends
// is_active alone, which writes no version.

import 'dart:async';
import 'dart:convert';

import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/session.dart';
import 'scope_choice_field.dart';
import 'search_picker.dart';
import 'side_panel.dart';

/// Shown with this grant; editing needs `automations.edit` as in Automations.
const leadsRecoveryPermission = 'automations.view';

const _triggerWords = {
  'lead.missed_call': 'Missed call',
  'lead.call_not_booked': 'After a call that did not book',
  'lead.follow_up_due': 'Went quiet without booking',
  'lead.received': 'New enquiry while the office is closed',
  'lead.customer_replied': 'Customer wrote while the office is closed',
};

class LeadsRecovery extends StatefulWidget {
  const LeadsRecovery({super.key, required this.api, this.session});

  final ApiClient api;
  final Session? session;

  @override
  State<LeadsRecovery> createState() => _LeadsRecoveryState();
}

class _LeadsRecoveryState extends State<LeadsRecovery> {
  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _rules = const [];
  List<Map<String, dynamic>> _texts = const [];
  List<String> _variables = const [];
  List<Map<String, dynamic>> _companies = const [];
  String? _companyId;

  bool get _canEdit => widget.session?.me?.can('automations.edit') ?? false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final response = await widget.api.get('/leads/recovery');
    if (!mounted) return;
    setState(() {
      _loading = false;
      if (!response.ok) {
        _error = response.message;
        return;
      }
      final data = response.data as Map<String, dynamic>;
      _rules = (data['rules'] as List).cast<Map<String, dynamic>>();
      _texts = (data['texts'] as List).cast<Map<String, dynamic>>();
      _variables = (data['variables'] as List).cast<String>();
      _companies = (data['companies'] as List).cast<Map<String, dynamic>>();
      _companyId ??= _companies.isEmpty ? null : '${_companies.first['id']}';
    });
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) return const Center(child: CircularProgressIndicator());
    if (_error != null) return Center(child: Text(_error!));

    return ListView(
      key: const ValueKey('recovery-list'),
      padding: const EdgeInsets.all(16),
      children: [
        Row(
          children: [
            Expanded(
              child: Text(
                'Automatic texts that win back missed and unbooked leads.',
                style: Theme.of(context).textTheme.bodyMedium,
              ),
            ),
            if (_companies.length > 1)
              SizedBox(
                width: 260,
                child: SearchPickerField<String>(
                  key: const ValueKey('recovery-brand'),
                  label: 'Preview as',
                  isDense: true,
                  value: _companyId,
                  options: [
                    for (final c in _companies)
                      SearchOption(value: '${c['id']}', label: '${c['name']}'),
                  ],
                  onChanged: (id) => setState(() => _companyId = id),
                ),
              ),
          ],
        ),
        const SizedBox(height: 12),
        if (_rules.isEmpty)
          const Padding(
            padding: EdgeInsets.all(24),
            child: Text('No recovery texts yet. Build one in Automations.'),
          ),
        for (final rule in _rules)
          _MessageCard(
            key: ValueKey('recovery-${rule['id']}'),
            api: widget.api,
            canEdit: _canEdit,
            companyId: _companyId,
            variables: _variables,
            title: '${rule['name']}',
            subtitle: [
              _triggerWords[rule['trigger_key']] ?? '${rule['trigger_key']}',
              if ((rule['delay_minutes'] as int? ?? 0) > 0)
                'after ${rule['delay_minutes']} min',
            ].join(' · '),
            message: '${rule['message'] ?? ''}',
            active: rule['is_active'] == true,
            sent: rule['sent_7d'] as int? ?? 0,
            ruleId: '${rule['id']}',
            place: '${rule['place'] ?? 'organization'}',
            me: widget.session?.me,
            onPlace: (place) => _place(rule, place),
            onSave: (text) => _saveRule(rule, text),
            onToggle: (on) => _toggle(rule, on),
          ),
        for (final t in _texts)
          _MessageCard(
            key: ValueKey('recovery-${t['key']}'),
            api: widget.api,
            canEdit: _canEdit,
            companyId: _companyId,
            variables: const ['{name}', '{company}', '{booked}', '{date}', '{window}'],
            title: '${t['label']}',
            subtitle: 'Sent from Re-Scheduling',
            message: '${t['message'] ?? ''}',
            onSave: (text) => _saveText(t, text),
          ),
      ],
    );
  }

  /// The whole version, conditions included, then read back. Null on success.
  Future<String?> _saveRule(Map<String, dynamic> rule, String text) async {
    final actions = [
      for (final a in (rule['actions'] as List).cast<Map<String, dynamic>>())
        a['type'] == 'reply' ? {...a, 'message': text} : a,
    ];
    final response = await widget.api.patch('/automations/${rule['id']}', {
      'conditions': rule['conditions'],
      'actions': actions,
      'delay_minutes': rule['delay_minutes'],
    });
    if (!response.ok) return response.message;
    final saved = response.data as Map<String, dynamic>;
    final kept =
        jsonEncode(saved['conditions']) == jsonEncode(rule['conditions']) &&
        (saved['actions'] as List).any(
          (a) => a['type'] == 'reply' && a['message'] == text,
        );
    if (!kept) {
      return 'Saved, but the rule reads back differently. Open it in Automations.';
    }
    setState(() {
      rule['actions'] = saved['actions'];
      rule['message'] = text;
      rule['version'] = saved['version'];
    });
    return null;
  }

  /// Company · Branch: scope alone, which writes no version.
  Future<String?> _place(Map<String, dynamic> rule, String place) async {
    final parts = place.split(':');
    final response = await widget.api.patch('/automations/${rule['id']}', {
      'scope_type': parts.length == 2 ? parts[0] : null,
      'scope_id': parts.length == 2 ? parts[1] : null,
    });
    if (!response.ok) return response.message;
    setState(() => rule['place'] = place);
    return null;
  }

  Future<String?> _toggle(Map<String, dynamic> rule, bool on) async {
    final response = await widget.api.patch('/automations/${rule['id']}', {
      'is_active': on,
    });
    if (!response.ok) return response.message;
    setState(() => rule['is_active'] = (response.data as Map)['is_active']);
    return null;
  }

  Future<String?> _saveText(Map<String, dynamic> t, String text) async {
    final response = await widget.api.patch('/settings/${t['key']}', {
      'value': text,
    });
    if (!response.ok) return response.message;
    setState(() => t['message'] = text);
    return null;
  }
}

class _MessageCard extends StatefulWidget {
  const _MessageCard({
    super.key,
    required this.api,
    required this.canEdit,
    required this.companyId,
    required this.variables,
    required this.title,
    required this.subtitle,
    required this.message,
    required this.onSave,
    this.active,
    this.sent,
    this.ruleId,
    this.onToggle,
    this.place,
    this.me,
    this.onPlace,
  });

  final ApiClient api;
  final bool canEdit;
  final String? companyId;
  final List<String> variables;
  final String title;
  final String subtitle;
  final String message;
  final Future<String?> Function(String text) onSave;

  /// Rules only: settings texts have no switch and no runs.
  final bool? active;
  final int? sent;
  final String? ruleId;
  final Future<String?> Function(bool on)? onToggle;
  final String? place;
  final Me? me;
  final Future<String?> Function(String place)? onPlace;

  @override
  State<_MessageCard> createState() => _MessageCardState();
}

class _MessageCardState extends State<_MessageCard> {
  late final _text = TextEditingController(text: widget.message);
  String _preview = '';
  String? _from;
  String? _note;
  bool _busy = false;
  Timer? _debounce;

  @override
  void initState() {
    super.initState();
    _text.addListener(() {
      _debounce?.cancel();
      _debounce = Timer(const Duration(milliseconds: 400), _render);
      setState(() {});
    });
    _render();
  }

  @override
  void didUpdateWidget(_MessageCard old) {
    super.didUpdateWidget(old);
    if (old.companyId != widget.companyId) _render();
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _text.dispose();
    super.dispose();
  }

  Future<void> _render() async {
    if (_text.text.trim().isEmpty) return;
    final response = await widget.api.post('/leads/recovery/preview', {
      'text': _text.text,
      'company_id': ?widget.companyId,
    });
    if (!mounted || !response.ok) return;
    final data = response.data as Map<String, dynamic>;
    setState(() {
      _preview = '${data['text']}';
      _from = data['sms_from'] as String?;
    });
  }

  Future<void> _run(Future<String?> Function() call, String done) async {
    setState(() => _busy = true);
    final error = await call();
    if (!mounted) return;
    setState(() {
      _busy = false;
      _note = error ?? done;
    });
  }

  Future<void> _runs() async {
    final response = await widget.api.get('/automations/${widget.ruleId}/runs');
    if (!mounted) return;
    final runs = response.ok
        ? (response.data as List).cast<Map<String, dynamic>>()
        : const <Map<String, dynamic>>[];
    await showSidePanel<void>(
      context,
      builder: (context) => SidePanel(
        title: widget.title,
        subtitle: 'Recent runs',
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            if (runs.isEmpty) const Text('No runs yet.'),
            for (final r in runs)
              ListTile(
                dense: true,
                title: Text(
                  '${DateTime.tryParse('${r['started_at']}')?.toLocal() ?? ''}'
                      .split('.')
                      .first,
                ),
                subtitle: Text(
                  [
                    for (final a in (r['action_results'] as List? ?? const []))
                      '${(a as Map)['detail'] ?? ''}',
                  ].join(' '),
                ),
                trailing: Text('${r['status']}'),
              ),
          ],
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final changed = _text.text != widget.message;
    final id = widget.ruleId ?? widget.title;

    return Card(
      margin: const EdgeInsets.only(bottom: 12),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(widget.title, style: theme.textTheme.titleMedium),
                      Text(
                        widget.subtitle,
                        style: theme.textTheme.bodySmall?.copyWith(
                          color: theme.colorScheme.onSurfaceVariant,
                        ),
                      ),
                    ],
                  ),
                ),
                if (widget.active != null)
                  Switch(
                    key: ValueKey('recovery-switch-$id'),
                    value: widget.active!,
                    onChanged: !widget.canEdit || _busy
                        ? null
                        : (on) => _run(
                            () => widget.onToggle!(on),
                            on ? 'Switched on.' : 'Switched off.',
                          ),
                  ),
              ],
            ),
            if (widget.place != null && widget.me != null) ...[
              const SizedBox(height: 12),
              ScopeChoiceField(
                key: ValueKey('recovery-place-$id'),
                label: 'Company · Branch',
                isDense: true,
                value: widget.place,
                helperText: 'A company covers its branches and sub companies.',
                places: {
                  'organization': 'Everywhere in this business',
                  for (final c in widget.me!.companies)
                    'company:${c.id}': '${c.name} and its branches',
                  for (final f in widget.me!.franchises) 'franchise:${f.id}': f.name,
                },
                companies: widget.me!.companies,
                branches: widget.me!.franchises,
                onChanged: !widget.canEdit || _busy
                    ? null
                    : (place) => _run(() => widget.onPlace!(place), 'Saved.'),
              ),
            ],
            const SizedBox(height: 12),
            TextField(
              key: ValueKey('recovery-text-$id'),
              controller: _text,
              readOnly: !widget.canEdit,
              minLines: 2,
              maxLines: 6,
              decoration: const InputDecoration(
                labelText: 'Message',
                border: OutlineInputBorder(),
              ),
            ),
            const SizedBox(height: 8),
            Wrap(
              spacing: 6,
              runSpacing: 6,
              children: [
                for (final v in widget.variables)
                  ActionChip(
                    label: Text(v.startsWith('{') ? v : '{{$v}}'),
                    onPressed: widget.canEdit
                        ? () => _text.text +=
                              v.startsWith('{') ? v : '{{$v}}'
                        : null,
                  ),
              ],
            ),
            const SizedBox(height: 12),
            Container(
              key: ValueKey('recovery-preview-$id'),
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: theme.colorScheme.surfaceContainerHighest,
                borderRadius: BorderRadius.circular(8),
              ),
              child: Text(_preview.isEmpty ? ' ' : _preview),
            ),
            const SizedBox(height: 8),
            Row(
              children: [
                Expanded(
                  child: Text(
                    widget.active == null
                        ? 'Texted from the line Re-Scheduling sends on.'
                        : 'Replies where the enquiry came in (Google text, '
                              'Thumbtack, chat). A Google call is texted from '
                              '${_from ?? 'the company\'s cleared line'}.',
                    style: theme.textTheme.bodySmall,
                  ),
                ),
                if (widget.sent != null)
                  TextButton(
                    key: ValueKey('recovery-runs-$id'),
                    onPressed: _runs,
                    child: Text('${widget.sent} sent in the last 7 days'),
                  ),
                if (widget.canEdit && changed)
                  FilledButton(
                    key: ValueKey('recovery-save-$id'),
                    onPressed: _busy
                        ? null
                        : () => _run(() => widget.onSave(_text.text), 'Saved.'),
                    child: const Text('Save'),
                  ),
              ],
            ),
            if (_note != null)
              Text(_note!, key: ValueKey('recovery-note-$id')),
          ],
        ),
      ),
    );
  }
}
