import '../../widgets/list_search.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../core/api_client.dart';
import '../../core/theme.dart';
import '../../widgets/error_note.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/side_panel.dart';
import '../../widgets/status_pill.dart';

/// AI Assistance › WhatsApp groups (UI queue 61; Jose: "go ahead as u
/// recommend", desktop 1 and phone 4).
///
/// Where the reports that replace the Rodrigo bot's are sent (Jose,
/// 2026-10-06; docs/REPLACING-RODRIGO-BOT.md): one WhatsApp group per report,
/// with who is in it. A card per group says which report it gets, how it is
/// delivered today, and who is in it — a member with no phone on file receives
/// nothing, and the card says so by name. The reports with no group yet are
/// listed above the cards, so nothing the bot used to send goes missing.
///
/// A group is sent privately to each member until it is made a real WhatsApp
/// group, which Meta refuses until the Official Business Account is granted;
/// the refusal is the server's sentence, shown as it is.
class WhatsAppGroupsTab extends StatefulWidget {
  const WhatsAppGroupsTab({super.key, required this.api});

  final ApiClient api;

  /// WhatsApp's own limit on a group's members.
  static const int most = 8;

  @override
  State<WhatsAppGroupsTab> createState() => _WhatsAppGroupsTabState();
}

typedef _Person = ({String id, String name, bool hasPhone});

class _WhatsAppGroupsTabState extends State<WhatsAppGroupsTab> {
  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _groups = const [];
  List<({String key, String label})> _purposes = const [];
  List<_Person> _people = const [];

  /// What Meta said when a group could not be made real, by group.
  final Map<String, String> _refusals = {};
  final Set<String> _busy = {};
  String? _deleting;
  String? _renaming;

  /// What the search bar holds; matched against each card's name, report and
  /// members.
  String _query = '';

  List<Map<String, dynamic>> get _shown {
    final needle = _query.trim().toLowerCase();
    if (needle.isEmpty) return _groups;
    return [
      for (final g in _groups)
        if ([
          '${g['subject'] ?? ''}',
          _purposeLabel('${g['purpose'] ?? ''}'),
          for (final m in _membersOf(g)) m.name,
        ].any((text) => text.toLowerCase().contains(needle)))
          g,
    ];
  }

  static const _path = '/ai-assistance/whatsapp-groups';

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = _groups.isEmpty;
      _error = null;
    });
    final response = await widget.api.get(_path);
    if (!mounted) return;
    setState(() {
      _loading = false;
      if (!response.ok) {
        _error = response.reason;
        return;
      }
      _groups = [
        for (final g in (response.body['data'] as List? ?? const []))
          if (g is Map) Map<String, dynamic>.from(g),
      ];
      _purposes = [
        for (final p in (response.body['purposes'] as List? ?? const []))
          if (p is Map)
            (key: '${p['key']}', label: '${p['label'] ?? p['key']}'),
      ];
      _people = [
        for (final p in (response.body['people'] as List? ?? const []))
          if (p is Map)
            (
              id: '${p['id']}',
              name: '${p['name'] ?? ''}',
              hasPhone: p['has_phone'] == true,
            ),
      ];
    });
  }

  String _purposeLabel(String key) {
    for (final p in _purposes) {
      if (p.key == key) return p.label;
    }
    return key;
  }

  List<({String key, String label})> get _unset => [
    for (final p in _purposes)
      if (!_groups.any((g) => g['purpose'] == p.key)) p,
  ];

  List<_Person> _membersOf(Map<String, dynamic> group) => [
    for (final m in (group['members'] as List? ?? const []))
      if (m is Map)
        (
          id: '${m['id']}',
          name: '${m['name'] ?? ''}',
          hasPhone: m['has_phone'] == true,
        ),
  ];

  void _replace(Map<String, dynamic> fresh) {
    setState(() {
      _groups = [
        for (final g in _groups)
          if (g['id'] == fresh['id']) fresh else g,
      ];
    });
  }

  Future<void> _saveMembers(
    Map<String, dynamic> group,
    List<String> ids,
  ) async {
    final id = '${group['id']}';
    setState(() => _busy.add(id));
    final response = await widget.api.patch('$_path/$id', {'member_ids': ids});
    if (!mounted) return;
    setState(() => _busy.remove(id));
    if (!response.ok) {
      _say(response.reason);
      return;
    }
    _replace(Map<String, dynamic>.from(response.data as Map));
  }

  Future<void> _add(Map<String, dynamic> group) async {
    final members = _membersOf(group);
    if (members.length >= WhatsAppGroupsTab.most) return;
    final taken = {for (final m in members) m.id};
    final picked = await showSearchPicker<String>(
      context,
      title: 'Add to “${group['subject']}”',
      options: [
        for (final p in _people)
          if (!taken.contains(p.id))
            SearchOption(
              value: p.id,
              label: p.name,
              detail: p.hasPhone
                  ? null
                  : 'No phone on file: would receive nothing',
            ),
      ],
    );
    if (picked == null || !mounted) return;
    await _saveMembers(group, [...taken, picked]);
  }

  Future<void> _publish(Map<String, dynamic> group) async {
    final id = '${group['id']}';
    setState(() {
      _busy.add(id);
      _refusals.remove(id);
    });
    final response = await widget.api.post('$_path/$id/publish', {});
    if (!mounted) return;
    setState(() => _busy.remove(id));
    if (!response.ok) {
      // Meta's refusal, as the server worded it: it says what happens meanwhile.
      setState(() => _refusals[id] = response.reason);
      return;
    }
    _replace(Map<String, dynamic>.from(response.data as Map));
  }

  Future<void> _rename(Map<String, dynamic> group, String subject) async {
    final id = '${group['id']}';
    final text = subject.trim();
    setState(() => _renaming = null);
    if (text.isEmpty || text == group['subject']) return;
    final response = await widget.api.patch('$_path/$id', {'subject': text});
    if (!mounted) return;
    if (!response.ok) {
      _say(response.reason);
      return;
    }
    _replace(Map<String, dynamic>.from(response.data as Map));
  }

  Future<void> _delete(Map<String, dynamic> group) async {
    final id = '${group['id']}';
    setState(() => _busy.add(id));
    final response = await widget.api.delete('$_path/$id');
    if (!mounted) return;
    setState(() {
      _busy.remove(id);
      _deleting = null;
    });
    if (!response.ok) {
      _say(response.reason);
      return;
    }
    setState(
      () => _groups = [
        for (final g in _groups)
          if (g['id'] != id) g,
      ],
    );
  }

  Future<void> _copy(String link) async {
    await Clipboard.setData(ClipboardData(text: link));
    if (!mounted) return;
    ScaffoldMessenger.maybeOf(
      context,
    )?.showSnackBar(const SnackBar(content: Text('Invite link copied.')));
  }

  void _say(String message) => ScaffoldMessenger.maybeOf(
    context,
  )?.showSnackBar(SnackBar(content: Text(message)));

  Future<void> _create([String? purpose]) async {
    final made = await showSidePanel<Map<String, dynamic>>(
      context,
      builder: (context) => SidePanel(
        title: 'New WhatsApp group',
        child: _NewGroup(
          api: widget.api,
          purposes: _unset,
          people: _people,
          purpose: purpose,
        ),
      ),
    );
    if (made != null && mounted) setState(() => _groups = [..._groups, made]);
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    if (_loading) return const Center(child: CircularProgressIndicator());
    if (_error != null) {
      return Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisSize: MainAxisSize.min,
          children: [
            ErrorNote(_error!),
            const SizedBox(height: 12),
            TextButton(onPressed: _load, child: const Text('Try again')),
          ],
        ),
      );
    }

    final narrow = MediaQuery.sizeOf(context).width < 600;
    final unset = _unset;
    final shown = _shown;

    return ListView(
      key: const ValueKey('tour-ai_assistance-whatsapp'),
      padding: EdgeInsets.all(narrow ? 12 : 20),
      children: [
        Text(
          'Where each report is sent, and who gets it. Until a group is made a real '
          'WhatsApp group, its report goes privately to each member.',
          style: theme.textTheme.bodySmall,
        ),
        const SizedBox(height: 12),
        if (unset.isNotEmpty) ...[
          _unsetStrip(context, unset, narrow),
          const SizedBox(height: 12),
        ],
        Align(
          alignment: Alignment.centerLeft,
          // Standard field height, as tall as Leads search (Jose, 2026-10-27).
          child: SizedBox(
            width: 240,
            child: ListSearch(
              key: const Key('whatsapp-groups-search'),
              hint: 'Find a group',
              onChanged: (v) => setState(() => _query = v),
            ),
          ),
        ),
        const SizedBox(height: 12),
        if (_groups.isEmpty)
          Text('No groups yet.', style: theme.textTheme.bodyMedium)
        else if (shown.isEmpty)
          Text(
            'No groups match "${_query.trim()}".',
            style: theme.textTheme.bodyMedium,
          )
        else
          LayoutBuilder(
            key: const ValueKey('tour-ai_assistance-whatsapp-groups'),
            builder: (context, box) {
              const gap = 12.0;
              final across = box.maxWidth >= 900 ? 2 : 1;
              // Cards side by side are one height, whatever each holds.
              return Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  for (var i = 0; i < shown.length; i += across) ...[
                    if (i > 0) const SizedBox(height: gap),
                    IntrinsicHeight(
                      child: Row(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          for (var j = i; j < i + across; j++) ...[
                            if (j > i) const SizedBox(width: gap),
                            Expanded(
                              child: j < shown.length
                                  ? _card(context, shown[j])
                                  : const SizedBox(),
                            ),
                          ],
                        ],
                      ),
                    ),
                  ],
                ],
              );
            },
          ),
      ],
    );
  }

  Widget _unsetStrip(
    BuildContext context,
    List<({String key, String label})> unset,
    bool narrow,
  ) {
    final theme = Theme.of(context);
    return Container(
      key: const Key('whatsapp-unset'),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: theme.colorScheme.surfaceContainerLowest,
        border: Border.all(color: EzBiziTheme.edge(context)),
        borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
      ),
      child: Wrap(
        spacing: 8,
        runSpacing: 8,
        crossAxisAlignment: WrapCrossAlignment.center,
        children: [
          Text(
            'Not set up yet:',
            style: theme.textTheme.bodyMedium?.copyWith(
              fontWeight: FontWeight.w800,
            ),
          ),
          // One searchable picker, not a chip per report: about 60 of them
          // wrapped eight rows (deep dive 2026-11-02).
          SizedBox(
            width: narrow ? double.infinity : 360,
            child: SearchPickerField<String>(
              key: const Key('whatsapp-unset-picker'),
              value: null,
              isDense: true,
              hint: 'Choose a report · ${unset.length}',
              options: [
                for (final p in unset) SearchOption(value: p.key, label: p.label),
              ],
              onChanged: (key) {
                if (key != null) _create(key);
              },
            ),
          ),
          Text(
            'Each needs a group before it can be sent.',
            style: theme.textTheme.bodySmall,
          ),
          FilledButton.icon(
            key: const Key('whatsapp-new'),
            onPressed: () => _create(),
            icon: const Icon(Icons.add),
            label: const Text('Make a group'),
          ),
        ],
      ),
    );
  }

  Widget _card(BuildContext context, Map<String, dynamic> group) {
    final theme = Theme.of(context);
    final id = '${group['id']}';
    final members = _membersOf(group);
    final real = group['on_whatsapp'] == true;
    final link = '${group['invite_link'] ?? ''}';
    final busy = _busy.contains(id);
    final full = members.length >= WhatsAppGroupsTab.most;
    final bad = ToneColours.of(context, Tone.bad);
    final caution = ToneColours.of(context, Tone.caution);

    return Card(
      key: ValueKey('whatsapp-group-$id'),
      margin: EdgeInsets.zero,
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        _purposeLabel('${group['purpose']}').toUpperCase(),
                        style: theme.textTheme.labelSmall?.copyWith(
                          fontWeight: FontWeight.w800,
                          letterSpacing: 0.5,
                          color: theme.colorScheme.primary,
                        ),
                      ),
                      if (_renaming == id)
                        TextFormField(
                          key: ValueKey('whatsapp-subject-$id'),
                          initialValue: '${group['subject'] ?? ''}',
                          autofocus: true,
                          decoration: const InputDecoration(
                            isDense: true,
                            labelText: 'Group name',
                          ),
                          onFieldSubmitted: (text) => _rename(group, text),
                        )
                      else
                        Text(
                          '${group['subject'] ?? ''}',
                          style: theme.textTheme.titleSmall?.copyWith(
                            fontWeight: FontWeight.w800,
                          ),
                        ),
                    ],
                  ),
                ),
                const SizedBox(width: 8),
                StatusPill(
                  real ? 'WhatsApp group' : 'Sent privately',
                  tone: real ? Tone.ok : Tone.caution,
                ),
                PopupMenuButton<String>(
                  key: ValueKey('whatsapp-more-$id'),
                  tooltip: 'More for “${group['subject']}”',
                  onSelected: (action) => setState(() {
                    if (action == 'rename') {
                      _renaming = id;
                    } else {
                      _deleting = id;
                    }
                  }),
                  itemBuilder: (context) => const [
                    PopupMenuItem(value: 'rename', child: Text('Rename')),
                    PopupMenuItem(value: 'delete', child: Text('Delete')),
                  ],
                ),
              ],
            ),
            const SizedBox(height: 10),
            Wrap(
              spacing: 6,
              runSpacing: 6,
              children: [
                for (final m in members)
                  InputChip(
                    key: ValueKey('whatsapp-member-$id-${m.id}'),
                    avatar: CircleAvatar(
                      backgroundColor: PersonColours.of(context, m.name).fill,
                      child: Text(
                        initials(m.name),
                        style: TextStyle(
                          fontSize: 9,
                          fontWeight: FontWeight.w800,
                          color: PersonColours.of(context, m.name).ink,
                        ),
                      ),
                    ),
                    // Short enough for a phone's width, with an ellipsis
                    // past it rather than a cut mid-word (UI audit,
                    // 2026-10-08). The red already says it won't arrive;
                    // the tooltip says why.
                    label: Tooltip(
                      message: m.hasPhone
                          ? m.name
                          : "${m.name} has no phone number, so won't receive "
                                'what is sent to this group.',
                      child: Text(
                        m.hasPhone ? m.name : '${m.name} · no phone',
                        overflow: TextOverflow.ellipsis,
                      ),
                    ),
                    labelStyle: m.hasPhone ? null : TextStyle(color: bad.ink),
                    backgroundColor: m.hasPhone ? null : bad.fill,
                    deleteButtonTooltipMessage: 'Take ${m.name} out',
                    onDeleted: busy
                        ? null
                        : () => _saveMembers(group, [
                            for (final other in members)
                              if (other.id != m.id) other.id,
                          ]),
                  ),
                Tooltip(
                  message: full
                      ? "8 is WhatsApp's limit for a group"
                      : 'Add somebody',
                  child: ActionChip(
                    key: ValueKey('whatsapp-add-$id'),
                    avatar: const Icon(Icons.add, size: 16),
                    label: Text(full ? "Add · 8 is WhatsApp's limit" : 'Add'),
                    onPressed: full || busy ? null : () => _add(group),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 8),
            Text(
              [
                '${members.length} of ${WhatsAppGroupsTab.most}',
                if (!real)
                  'each gets the report privately until this is a WhatsApp group',
              ].join(' · '),
              key: ValueKey('whatsapp-count-$id'),
              style: theme.textTheme.bodySmall,
            ),
            const SizedBox(height: 10),
            if (_deleting == id)
              Container(
                padding: const EdgeInsets.all(8),
                decoration: BoxDecoration(
                  color: bad.fill,
                  borderRadius: BorderRadius.circular(
                    EzBiziTheme.controlRadius,
                  ),
                ),
                child: Wrap(
                  spacing: 8,
                  crossAxisAlignment: WrapCrossAlignment.center,
                  children: [
                    Text(
                      'Delete it? Its report stops being sent until it has a group again.',
                      style: theme.textTheme.bodySmall?.copyWith(
                        color: bad.ink,
                      ),
                    ),
                    FilledButton(
                      key: ValueKey('whatsapp-delete-yes-$id'),
                      style: FilledButton.styleFrom(
                        backgroundColor: theme.colorScheme.error,
                        foregroundColor: theme.colorScheme.onError,
                      ),
                      onPressed: busy ? null : () => _delete(group),
                      child: const Text('Delete'),
                    ),
                    TextButton(
                      onPressed: () => setState(() => _deleting = null),
                      child: const Text('Keep'),
                    ),
                  ],
                ),
              )
            else if (real && link.isNotEmpty)
              Row(
                children: [
                  Text('Invite link', style: theme.textTheme.bodySmall),
                  const SizedBox(width: 8),
                  Flexible(
                    child: SelectableText(
                      link,
                      maxLines: 1,
                      style: theme.textTheme.bodySmall?.copyWith(
                        fontFamily: 'monospace',
                      ),
                    ),
                  ),
                  const SizedBox(width: 8),
                  OutlinedButton(
                    key: ValueKey('whatsapp-copy-$id'),
                    onPressed: () => _copy(link),
                    child: const Text('Copy'),
                  ),
                ],
              )
            else if (!real) ...[
              Align(
                alignment: Alignment.centerLeft,
                child: OutlinedButton(
                  key: ValueKey('whatsapp-publish-$id'),
                  onPressed: busy ? null : () => _publish(group),
                  child: const Text('Make it a WhatsApp group'),
                ),
              ),
              if (_refusals[id] case final said?) ...[
                const SizedBox(height: 8),
                Container(
                  key: ValueKey('whatsapp-refused-$id'),
                  padding: const EdgeInsets.all(8),
                  decoration: BoxDecoration(
                    color: caution.fill,
                    borderRadius: BorderRadius.circular(
                      EzBiziTheme.controlRadius,
                    ),
                  ),
                  child: Text(
                    said,
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: caution.ink,
                    ),
                  ),
                ),
              ],
            ],
          ],
        ),
      ),
    );
  }
}

/// The side panel that makes a group: which report, its name, who is in it.
class _NewGroup extends StatefulWidget {
  const _NewGroup({
    required this.api,
    required this.purposes,
    required this.people,
    this.purpose,
  });

  final ApiClient api;
  final List<({String key, String label})> purposes;
  final List<_Person> people;
  final String? purpose;

  @override
  State<_NewGroup> createState() => _NewGroupState();
}

class _NewGroupState extends State<_NewGroup> {
  late String? _purpose =
      widget.purpose ??
      (widget.purposes.isEmpty ? null : widget.purposes.first.key);
  late final _subject = TextEditingController(text: _defaultSubject(_purpose));
  final List<String> _members = [];
  bool _saving = false;
  String? _error;
  bool _subjectTouched = false;

  String _defaultSubject(String? key) {
    for (final p in widget.purposes) {
      if (p.key == key) return p.label;
    }
    return '';
  }

  @override
  void dispose() {
    _subject.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    final purpose = _purpose;
    if (purpose == null) return;
    setState(() {
      _saving = true;
      _error = null;
    });
    final response = await widget.api.post('/ai-assistance/whatsapp-groups', {
      'purpose': purpose,
      'subject': _subject.text.trim(),
      'member_ids': _members,
    });
    if (!mounted) return;
    if (!response.ok) {
      setState(() {
        _saving = false;
        _error = response.reason;
      });
      return;
    }
    Navigator.of(context).pop(Map<String, dynamic>.from(response.data as Map));
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final full = _members.length >= WhatsAppGroupsTab.most;

    if (widget.purposes.isEmpty) {
      return Text(
        'Every report already has a group.',
        style: theme.textTheme.bodyMedium,
      );
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        SearchPickerField<String>(
          key: const Key('whatsapp-new-purpose'),
          value: _purpose,
          label: 'Which report it gets',
          options: [
            for (final p in widget.purposes)
              SearchOption(value: p.key, label: p.label),
          ],
          onChanged: (value) => setState(() {
            _purpose = value;
            if (!_subjectTouched) _subject.text = _defaultSubject(value);
          }),
        ),
        const SizedBox(height: 16),
        TextField(
          key: const Key('whatsapp-new-subject'),
          controller: _subject,
          decoration: const InputDecoration(
            labelText: 'Group name on WhatsApp',
            border: OutlineInputBorder(),
          ),
          onChanged: (_) => setState(() => _subjectTouched = true),
        ),
        const SizedBox(height: 16),
        Text(
          'Who is in it · ${_members.length} of ${WhatsAppGroupsTab.most}',
          style: theme.textTheme.labelLarge,
        ),
        const SizedBox(height: 6),
        for (final p in widget.people)
          CheckboxListTile(
            key: ValueKey('whatsapp-new-person-${p.id}'),
            dense: true,
            contentPadding: EdgeInsets.zero,
            controlAffinity: ListTileControlAffinity.leading,
            value: _members.contains(p.id),
            title: Text(p.name),
            subtitle: p.hasPhone
                ? null
                : const Text('No phone on file: would receive nothing'),
            // The 9th is refused here, before saving: WhatsApp's own limit.
            onChanged: !_members.contains(p.id) && full
                ? null
                : (on) => setState(() {
                    on == true ? _members.add(p.id) : _members.remove(p.id);
                  }),
          ),
        if (full)
          Text(
            "8 is WhatsApp's limit for a group.",
            style: theme.textTheme.bodySmall,
          ),
        if (_error != null) ...[const SizedBox(height: 12), ErrorNote(_error!)],
        const SizedBox(height: 16),
        FilledButton(
          key: const Key('whatsapp-new-save'),
          onPressed: _saving || _subject.text.trim().isEmpty ? null : _save,
          child: Text(_saving ? 'Making it…' : 'Make the group'),
        ),
      ],
    );
  }
}
