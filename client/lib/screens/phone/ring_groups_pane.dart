import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../widgets/error_note.dart';
import '../../widgets/list_table.dart';
import '../../widgets/refused.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/status_pill.dart';

/// Phone › Settings › Ring groups — who a line rings, and what happens when
/// nobody picks up.
///
/// ── Why the lines are edited here and not on a Numbers screen ────
///
/// A ring group that no line points at rings nobody, and until this pane
/// existed `phone_numbers.ring_group_id` could never be set at all: the
/// renderer read it, nothing wrote it, and every inbound call on a fully
/// configured account fell through to voicemail with nothing on screen to
/// explain why.
///
/// Making "which lines does this group answer" part of the group — rather than
/// a field buried on each number — means the two halves of "the phone rings"
/// are one screen and one save. A separate Numbers screen can still set it
/// later; this is where somebody setting up a phone system is already standing.
class RingGroupsPane extends StatefulWidget {
  const RingGroupsPane({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<RingGroupsPane> createState() => _RingGroupsPaneState();
}

class _RingGroupsPaneState extends State<RingGroupsPane> {
  bool _loading = true;
  String? _error;

  List<_Group> _groups = const [];
  List<_Person> _people = const [];
  List<_Line> _lines = const [];
  List<_Conn> _connections = const [];

  /// Null when the form is shut. Empty id means "new".
  _Draft? _draft;
  bool _saving = false;
  String? _formError;

  /// The tile picked (tiles over a table, Mia 2026-10-02): `free` or `none`;
  /// null is every group. Counted here - `/phone/ring-groups` is every group
  /// in one answer.
  String? _tile;

  /// What is typed in the search box. /phone/ring-groups returns every group,
  /// so the filter runs here.
  String _query = '';

  @override
  void initState() {
    super.initState();
    _load();
  }

  Me get _me => widget.session.me!;

  bool get _canEdit => _me.can('phone.edit');

  String? get _editRefusal => _me.refusal('phone.edit', 'edit ring groups');

  Future<void> _load() async {
    setState(() {
      _loading = _groups.isEmpty;
      _error = null;
    });

    final results = await Future.wait([
      widget.api.get('/phone/ring-groups'),
      widget.api.get('/phone/members'),
      widget.api.get('/phone/numbers'),
      widget.api.get('/phone/connections'),
    ]);

    if (!mounted) return;

    setState(() {
      _loading = false;

      final failed = results.firstWhere(
        (r) => !r.ok,
        orElse: () => results.first,
      );
      if (!failed.ok) {
        _error = failed.message;
        return;
      }

      _groups = _listOf(
        results[0],
      ).map(_Group.fromJson).toList(growable: false);

      final members = results[1].body['data'];
      _people = members is Map<String, dynamic> && members['people'] is List
          ? (members['people'] as List)
                .whereType<Map<String, dynamic>>()
                .map(_Person.fromJson)
                .toList(growable: false)
          : const [];

      _lines = _listOf(results[2]).map(_Line.fromJson).toList(growable: false);
      _connections = _listOf(
        results[3],
      ).map(_Conn.fromJson).toList(growable: false);
    });
  }

  List<Map<String, dynamic>> _listOf(ApiResponse response) {
    final data = response.body['data'];
    return data is List
        ? data.whereType<Map<String, dynamic>>().toList(growable: false)
        : const [];
  }

  void _newGroup() {
    final connectionId = _connections.isNotEmpty ? _connections.first.id : null;

    setState(() {
      _formError = null;
      _draft = _Draft(
        id: '',
        connectionId: connectionId,
        name: '',
        mode: 'simultaneous',
        timeoutSeconds: 30,
        memberIds: <String>{},
        overflow: 'voicemail',
        overflowNumber: '',
        overflowGroupId: null,
        queueMaxSeconds: 300,
        queueAnnouncePosition: true,
        queueAnnounceWait: false,
        queueHoldMusicUrl: '',
      );
    });
  }

  void _editGroup(_Group group) {
    setState(() {
      _formError = null;
      _draft = _Draft(
        id: group.id,
        connectionId: group.connectionId,
        name: group.name,
        mode: group.mode,
        timeoutSeconds: group.timeoutSeconds,
        memberIds: group.memberUserIds.toSet(),
        overflow: group.overflow,
        overflowNumber: group.overflowNumber ?? '',
        overflowGroupId: group.overflowGroupId,
        queueMaxSeconds: group.queueMaxSeconds,
        queueAnnouncePosition: group.queueAnnouncePosition,
        queueAnnounceWait: group.queueAnnounceWait,
        queueHoldMusicUrl: group.queueHoldMusicUrl ?? '',
      );
    });
  }

  Future<void> _save() async {
    final draft = _draft;
    if (draft == null || !_canEdit || _saving) return;

    if (draft.name.trim().isEmpty) {
      setState(() => _formError = 'Give the group a name.');
      return;
    }
    if (draft.connectionId == null) {
      setState(
        () => _formError = 'Connect a phone account for this place first.',
      );
      return;
    }

    setState(() {
      _saving = true;
      _formError = null;
    });

    final body = <String, dynamic>{
      'name': draft.name.trim(),
      'mode': draft.mode,
      'timeout_seconds': draft.timeoutSeconds,
      'member_user_ids': draft.memberIds.toList()..sort(),
      'member_role_ids': <String>[],
      'overflow': draft.overflow,
      'overflow_number': draft.overflow == 'forward'
          ? draft.overflowNumber.trim()
          : null,
      'overflow_group_id': draft.overflow == 'group'
          ? draft.overflowGroupId
          : null,

      // Sent only when they are the settings in play. A hold time left over
      // from the ten seconds somebody spent looking at the queue option is not
      // a decision, and writing it back on every save makes the column read as
      // one.
      if (draft.overflow == 'queue') ...{
        'queue_max_seconds': draft.queueMaxSeconds,
        'queue_announce_position': draft.queueAnnouncePosition,
        'queue_announce_wait': draft.queueAnnounceWait,
        'queue_hold_music_url': draft.queueHoldMusicUrl.trim().isEmpty
            ? null
            : draft.queueHoldMusicUrl.trim(),
      },
    };

    final response = draft.id.isEmpty
        ? await widget.api.post('/phone/ring-groups', {
            ...body,
            'connection_id': draft.connectionId,
          })
        : await widget.api.patch('/phone/ring-groups/${draft.id}', body);

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _saving = false;
        _formError = response.message;
      });
      return;
    }

    // Saving a group no longer touches any line.
    //
    // It used to PATCH /phone/numbers for every line whose tick had changed —
    // a second save, of a different resource, from a screen about people. That
    // is gone with the control it served: a line's ring group is set on
    // Numbers, and the company's default on General.
    setState(() {
      _saving = false;
      _draft = null;
    });

    await _load();
  }

  /// The lines that route here, in words. Empty when nothing does.
  String _reachedBy(String? groupId) {
    if (groupId == null) return '';

    final names = [
      for (final line in _lines)
        if (line.ringGroupId == groupId) line.label,
    ];

    if (names.isEmpty) return '';
    if (names.length == 1) return names.first;

    return '${names.take(names.length - 1).join(', ')} and ${names.last}';
  }

  Future<void> _delete(_Group group) async {
    if (!_canEdit || _saving) return;

    setState(() {
      _saving = true;
      _formError = null;
    });

    final response = await widget.api.delete('/phone/ring-groups/${group.id}');

    if (!mounted) return;

    setState(() {
      _saving = false;
      if (!response.ok) _formError = response.message;
    });

    if (response.ok) {
      setState(() => _draft = null);
      await _load();
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    if (_loading) {
      return const Center(child: CircularProgressIndicator());
    }

    if (_error != null) {
      // A failed load replaced the pane with a sentence and nothing else, so
      // the only way to retry was to navigate to another section and back.
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

    return ListView(
      padding: const EdgeInsets.all(24),
      children: [
        Row(
          children: [
            Expanded(
              child: Text('Ring groups', style: theme.textTheme.titleMedium),
            ),
            if (_groups.isNotEmpty)
              Flexible(
                child: Align(
                  alignment: Alignment.centerRight,
                  child: _searchBox(
                    key: const ValueKey('comm-ring-groups-search'),
                    hint: 'Search group or member',
                    onChanged: (value) => setState(() => _query = value),
                  ),
                ),
              ),
          ],
        ),
        const SizedBox(height: 8),
        Text(
          'A group is who a line rings. Someone only rings if they are signed '
          'in with Phone open and not on Do not disturb.',
          style: theme.textTheme.bodyMedium,
        ),

        // The counts below are a MEASUREMENT, not a property, and there is no
        // live channel to keep them true. Somebody's phone can register a
        // second after this was read, so the age is stated rather than left
        // for the reader to assume it is current.
        if (_groups.isNotEmpty)
          Padding(
            padding: const EdgeInsets.only(top: 4),
            child: Text(
              'Who can be rung was checked ${_measuredAgo()}. It is not live.',
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
          ),
        const SizedBox(height: 16),

        if (_connections.isEmpty)
          Text(
            'Connect a phone account first. A group belongs to one.',
            style: theme.textTheme.bodyMedium,
          )
        else ...[
          if (_groups.isNotEmpty) ...[
            _tiles(),
            const SizedBox(height: 12),
            _table(theme),
          ],
          if (_groups.isEmpty && _draft == null)
            Text(
              'No groups yet. Without one, a call goes straight to voicemail.',
              style: theme.textTheme.bodyMedium,
            ),
          const SizedBox(height: 12),
          if (_draft != null) _form(theme) else _addButton(),
        ],
      ],
    );
  }

  /// How old the reachability reading is, in words rather than a timestamp.
  ///
  /// "as of 14:32" makes somebody work out whether that was a minute ago; the
  /// only thing they want to know is whether to trust the number.
  String _measuredAgo() {
    final stamps = _groups
        .map((g) => DateTime.tryParse(g.measuredAt))
        .whereType<DateTime>()
        .toList();

    if (stamps.isEmpty) return 'at some point';

    final age = DateTime.now().difference(
      stamps.reduce((a, b) => a.isAfter(b) ? a : b).toLocal(),
    );

    if (age.inSeconds < 60) return 'a few seconds ago';
    if (age.inMinutes < 60) {
      return '${age.inMinutes} minute${age.inMinutes == 1 ? '' : 's'} ago';
    }

    return 'over an hour ago';
  }

  Widget _addButton() => Refused(
    reason: _canEdit ? null : _editRefusal,
    child: FilledButton.icon(
      onPressed: _canEdit && !_saving ? _newGroup : null,
      icon: const Icon(Icons.add),
      label: const Text('Add a ring group'),
    ),
  );

  // Nobody free is the group the old card painted red: everybody in it is on
  // Do not disturb, on a call, or has never opened Phone - or there is nobody
  // in it at all - so a call goes straight to what happens after.
  static bool _someoneFree(_Group group) => group.reachableCount > 0;

  Widget _tiles() {
    final free = _groups.where(_someoneFree).length;

    return FilterTiles(
      prefix: 'ring-groups',
      picked: _tile,
      onPick: (key) => setState(() => _tile = key),
      tiles: [
        FilterTile(
          key: null,
          label: 'Ring groups',
          count: _groups.length,
          note: 'all',
        ),
        FilterTile(
          key: 'free',
          label: 'Someone free',
          count: free,
          note: 'can answer now',
          tone: Tone.ok,
        ),
        FilterTile(
          key: 'none',
          label: 'Nobody free',
          count: _groups.length - free,
          note: 'calls go to what happens after',
          tone: Tone.bad,
        ),
      ],
    );
  }

  Widget _table(ThemeData theme) {
    final shown = [
      for (final group in switch (_tile) {
        'free' => _groups.where(_someoneFree),
        'none' => _groups.where((g) => !_someoneFree(g)),
        _ => _groups,
      })
        if (_searchHit(_query, [
          group.name,
          for (final member in group.members) member.name,
        ]))
          group,
    ];
    final small = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );

    if (shown.isEmpty) {
      return Padding(
        padding: const EdgeInsets.all(24),
        child: Text(
          _query.trim().isNotEmpty
              ? 'Nothing matches "${_query.trim()}".'
              : 'No groups like that.',
          textAlign: TextAlign.center,
        ),
      );
    }

    return ListTable(
      columns: const [
        ListColumn('Group', flex: 4),
        ListColumn('Rings', flex: 2),
        ListColumn('Members', flex: 4),
        ListColumn('Free now', flex: 2),
        ListColumn('Waits', flex: 1),
        ListColumn('Then', flex: 3),
        ListColumn('', flex: 2, end: true),
      ],
      rowKey: (i) => ValueKey('ring-group-${shown[i].id}'),
      rows: [
        for (final group in shown)
          [
            Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(
                  group.name,
                  overflow: TextOverflow.ellipsis,
                  style: theme.textTheme.bodyMedium?.copyWith(
                    fontWeight: FontWeight.w600,
                  ),
                ),
                // LABELS, not E.164, and kept from the old card: a group no
                // line points at rings nobody, and this is where that shows.
                Text(
                  _reachedBy(group.id).isEmpty
                      ? 'no number rings it yet'
                      : 'rings for ${_reachedBy(group.id)}',
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: small,
                ),
              ],
            ),
            Text(_modeLabel(group.mode)),
            Text(
              group.members.isEmpty
                  ? 'nobody in it'
                  : group.members.map((m) => m.name).join(', '),
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
              style: small,
            ),
            // The number that matters is not how many are IN the group but how
            // many a call reaches now - four people all on Do not disturb ring
            // nobody.
            StatusPill(
              '${group.reachableCount} of ${group.members.length}',
              tone: _someoneFree(group) ? Tone.ok : Tone.bad,
            ),
            Text('${group.timeoutSeconds}s'),
            Text(_overflowLabel(group), style: small),
            _draft?.id == group.id
                ? const SizedBox.shrink()
                : Refused(
                    reason: _canEdit ? null : _editRefusal,
                    child: TextButton(
                      onPressed: _canEdit && !_saving
                          ? () => _editGroup(group)
                          : null,
                      child: const Text('Edit'),
                    ),
                  ),
          ],
      ],
    );
  }

  static String _modeLabel(String mode) => switch (mode) {
    'sequential' => 'In order',
    'longest_idle' => 'Least busy first',
    _ => 'Everyone at once',
  };

  String _overflowLabel(_Group group) => switch (group.overflow) {
    'queue' => 'Hold in a queue',
    'forward' =>
      group.overflowNumber == null || group.overflowNumber!.isEmpty
          ? 'Forward'
          : 'Forward to ${group.overflowNumber}',
    'group' => () {
      final other = _groups.where((g) => g.id == group.overflowGroupId);
      return other.isEmpty
          ? 'Ring another group'
          : 'Ring ${other.first.name}';
    }(),
    _ => 'Voicemail',
  };

  Widget _form(ThemeData theme) {
    final draft = _draft!;

    // Reachability for the group being edited, keyed by person. Empty for a
    // group that does not exist yet — nothing has been measured about a draft.
    final roster = {
      for (final member
          in _groups.where((g) => g.id == draft.id).expand((g) => g.members))
        member.userId: member,
    };
    final otherGroups = _groups
        .where((g) => g.id != draft.id && g.connectionId == draft.connectionId)
        .toList();

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              draft.id.isEmpty ? 'New ring group' : 'Edit ring group',
              style: theme.textTheme.titleSmall,
            ),
            const SizedBox(height: 12),

            // KEYED ON THE DRAFT, and this is not a nicety.
            //
            // `initialValue` is read once, when the field's State is created.
            // Pressing Edit on a second group while this editor is open swaps
            // the draft underneath a widget in the same position, so Flutter
            // reuses that State and its controller — and the box goes on
            // showing the PREVIOUS group's name over the new group's members.
            //
            // Jose hit it on 2026-09-09: the editor showed Dispatch's seven
            // people and Dispatch's "no number rings this group", with
            // "Marketing" in the name box. Save would have renamed Dispatch.
            // He reported it as leftover text, which is exactly what it looks
            // like from outside.
            //
            // A key that changes with the draft forces a fresh State, so the
            // field can never be one group behind the rest of the form.
            TextFormField(
              key: ValueKey('ring-group-name-${draft.id}'),
              initialValue: draft.name,
              decoration: const InputDecoration(labelText: 'Name'),
              enabled: !_saving,
              onChanged: (value) => draft.name = value,
            ),
            const SizedBox(height: 12),

            if (_connections.length > 1) ...[
              SearchPickerField<String>(
                value: draft.connectionId,
                label: 'Phone account',
                options: [
                  for (final conn in _connections)
                    SearchOption(value: conn.id, label: conn.label),
                ],
                onChanged: _saving
                    ? null
                    : (value) => setState(() {
                        draft.connectionId = value;
                        // An overflow group belongs to one account.
                        draft.overflowGroupId = null;
                      }),
              ),
              const SizedBox(height: 12),
            ],

            Text('Rings', style: theme.textTheme.labelLarge),
            RadioListTile<String>(
              value: 'simultaneous',
              // ignore: deprecated_member_use
              groupValue: draft.mode,
              // ignore: deprecated_member_use
              onChanged: _saving
                  ? null
                  : (v) => setState(() => draft.mode = v!),
              title: const Text('Everyone at once'),
              contentPadding: EdgeInsets.zero,
              dense: true,
            ),
            RadioListTile<String>(
              value: 'sequential',
              // ignore: deprecated_member_use
              groupValue: draft.mode,
              // ignore: deprecated_member_use
              onChanged: _saving
                  ? null
                  : (v) => setState(() => draft.mode = v!),
              title: const Text('One at a time, in order'),
              contentPadding: EdgeInsets.zero,
              dense: true,
            ),

            // ── FAIRNESS, which a fixed order does not give you ──
            //
            // RingCentral calls this "longest idle" and A1 Chimney's own
            // queues are set to it. A fixed list means the first person
            // answers most of the calls and the last answers almost none —
            // a rota nobody agreed to, and one that only shows up months
            // later in somebody's numbers.
            //
            // Read from the call log rather than a counter, so it is the same
            // fact the reports are built from.
            RadioListTile<String>(
              value: 'longest_idle',
              // ignore: deprecated_member_use
              groupValue: draft.mode,
              // ignore: deprecated_member_use
              onChanged: _saving
                  ? null
                  : (v) => setState(() => draft.mode = v!),
              title: const Text('One at a time, whoever is least busy'),
              subtitle: const Text(
                'Starts with whoever has gone longest without answering.',
              ),
              contentPadding: EdgeInsets.zero,
              dense: true,
            ),

            const SizedBox(height: 8),
            Text(
              // Said differently when the wait is per person, because the
              // same number means a very different hold: thirty seconds each
              // across five people is a caller holding for two and a half
              // minutes, and nothing on this screen used to say so.
              draft.mode == 'simultaneous'
                  ? 'Ring for ${draft.timeoutSeconds} seconds'
                  : 'Ring each person for ${draft.timeoutSeconds} seconds',
              style: theme.textTheme.labelLarge,
            ),
            Slider(
              value: draft.timeoutSeconds.toDouble(),
              min: 5,
              max: 60,
              divisions: 11,
              label: '${draft.timeoutSeconds}s',
              onChanged: _saving
                  ? null
                  : (v) => setState(() => draft.timeoutSeconds = v.round()),
            ),
            Text(
              'Twilio hangs up a browser call at 60 seconds, so it cannot ring longer.',
              style: theme.textTheme.bodySmall,
            ),

            const SizedBox(height: 16),
            Text('Who it rings', style: theme.textTheme.labelLarge),
            Text(
              'The people whose phones ring when a call reaches this group.',
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
            const SizedBox(height: 4),
            if (_people.isEmpty)
              Text('Nobody to add yet.', style: theme.textTheme.bodySmall)
            else
              for (final person in _people)
                CheckboxListTile(
                  value: draft.memberIds.contains(person.id),
                  onChanged: _saving
                      ? null
                      : (on) => setState(() {
                          if (on == true) {
                            draft.memberIds.add(person.id);
                          } else {
                            draft.memberIds.remove(person.id);
                          }
                        }),
                  title: Text(person.name),

                  // Why this person would or would not ring, against their
                  // name rather than as prose at the top of the pane. Only
                  // for people already in the group: the server measures
                  // reachability for members, and inventing a state for
                  // somebody who is not one would be a guess.
                  subtitle: roster[person.id] == null
                      ? null
                      : Text(
                          roster[person.id]!.why,
                          style: theme.textTheme.bodySmall?.copyWith(
                            color: roster[person.id]!.reachable
                                ? null
                                : theme.colorScheme.error,
                          ),
                        ),
                  contentPadding: EdgeInsets.zero,
                  dense: true,
                ),

            const SizedBox(height: 16),
            Text('Where calls come from', style: theme.textTheme.labelLarge),

            // READ ONLY, deliberately.
            //
            // This used to be a list of numbers with tick boxes, and it was the
            // wrong shape twice over. A line points at ONE group, so a control
            // here is really editing the line's setting from the far end — and
            // the same setting is edited on Numbers, where somebody looking at
            // a phone number already stands. Two editors for one column is how
            // the two disagree.
            //
            // It also read as "add a number to this group", which is not what
            // a group is. A group is people. Every other phone system puts the
            // destination on the number for exactly that reason.
            //
            // Still SHOWN, because a group nothing points at never rings, and
            // that is the failure this module keeps producing: a screen full
            // of correct settings that no call ever reaches.
            Text(
              _reachedBy(draft.id).isEmpty
                  ? 'No number rings this group yet. Choose it as the ring '
                        'group on a line under Numbers, or set it as the '
                        "company's default under General."
                  : 'Rings when a call comes to ${_reachedBy(draft.id)}.',
              style: theme.textTheme.bodySmall?.copyWith(
                color: _reachedBy(draft.id).isEmpty
                    ? theme.colorScheme.error
                    : theme.colorScheme.onSurfaceVariant,
              ),
            ),

            const SizedBox(height: 16),
            Text('If nobody answers', style: theme.textTheme.labelLarge),
            for (final option in const [
              ('voicemail', 'Take a voicemail'),
              ('queue', 'Hold the caller in a queue'),
              ('forward', 'Forward to another number'),
              ('group', 'Ring another group'),
            ])
              RadioListTile<String>(
                value: option.$1,
                // ignore: deprecated_member_use
                groupValue: draft.overflow,
                // ignore: deprecated_member_use
                onChanged: _saving
                    ? null
                    : (v) => setState(() => draft.overflow = v!),
                title: Text(option.$2),
                contentPadding: EdgeInsets.zero,
                dense: true,
              ),

            // ── THE QUEUE, and what a caller hears while they stand in it ──
            //
            // Asked for by name: "RingCentral has Positioning available... so
            // if everyone is on calls, it leaves callers waiting on a quee, so
            // they don't go straight to voicemail".
            //
            // Four settings and no more. RingCentral's own queue screen has
            // six collapsible sections and roughly thirty controls, and most
            // of them describe things this app answers elsewhere — hours,
            // blocked callers, who is in the group, where voicemail goes. What
            // is genuinely new is the waiting: how long, what is said, and what
            // plays in between.
            if (draft.overflow == 'queue') ...[
              const SizedBox(height: 8),
              Text(
                'Hold for ${draft.queueMaxSeconds ~/ 60} '
                '${draft.queueMaxSeconds ~/ 60 == 1 ? 'minute' : 'minutes'} '
                'before taking a voicemail',
                style: theme.textTheme.bodySmall,
              ),
              Slider(
                value: draft.queueMaxSeconds.toDouble(),
                min: 60,
                max: 1800,
                divisions: 29,
                label: '${draft.queueMaxSeconds ~/ 60} min',
                onChanged: _saving
                    ? null
                    : (v) => setState(
                        () => draft.queueMaxSeconds = (v ~/ 60) * 60,
                      ),
              ),
              SwitchListTile(
                value: draft.queueAnnouncePosition,
                onChanged: _saving
                    ? null
                    : (v) => setState(() => draft.queueAnnouncePosition = v),
                title: const Text('Say their place in the line'),
                subtitle: const Text(
                  'Between each round of music: "you are third in line".',
                ),
                contentPadding: EdgeInsets.zero,
                dense: true,
              ),
              SwitchListTile(
                value: draft.queueAnnounceWait,
                onChanged: _saving
                    ? null
                    : (v) => setState(() => draft.queueAnnounceWait = v),
                title: const Text('Say how long the wait is likely to be'),
                // Off by default, and the reason is worth the sentence: the
                // estimate comes from how long the people currently waiting
                // have waited, which on a short queue is a guess made from two
                // callers. Somebody told two minutes who waits six hangs up
                // angrier than somebody told nothing.
                subtitle: const Text(
                  'An estimate from how long callers ahead of them have '
                  'waited. On a short queue that is a guess.',
                ),
                contentPadding: EdgeInsets.zero,
                dense: true,
              ),
              TextFormField(
                key: ValueKey('ring-group-hold-music-${draft.id}'),
                initialValue: draft.queueHoldMusicUrl,
                decoration: const InputDecoration(
                  labelText: 'Hold music',
                  hintText: 'Leave empty for the default',
                  helperText:
                      'A direct link to an MP3 or WAV file. Its '
                      'length is the gap between announcements.',
                  helperMaxLines: 2,
                ),
                enabled: !_saving,
                onChanged: (value) => draft.queueHoldMusicUrl = value,
              ),
              const SizedBox(height: 8),
            ],

            if (draft.overflow == 'forward')
              TextFormField(
                // Same trap as the name field above: a forwarding number left
                // over from the group you were looking at a moment ago is one
                // Save away from sending this group's calls to it.
                key: ValueKey('ring-group-overflow-${draft.id}'),
                initialValue: draft.overflowNumber,
                decoration: const InputDecoration(
                  labelText: 'Forward to',
                  hintText: '+12035550123',
                ),
                enabled: !_saving,
                onChanged: (value) => draft.overflowNumber = value,
              ),

            if (draft.overflow == 'group')
              otherGroups.isEmpty
                  ? Text(
                      'There is no other group on this phone account to send '
                      'calls to.',
                      style: theme.textTheme.bodySmall,
                    )
                  : SearchPickerField<String>(
                      value: draft.overflowGroupId,
                      label: 'Then ring',
                      options: [
                        for (final g in otherGroups)
                          SearchOption(value: g.id, label: g.name),
                      ],
                      onChanged: _saving
                          ? null
                          : (v) => setState(() => draft.overflowGroupId = v),
                    ),

            if (_formError != null) ...[
              const SizedBox(height: 12),
              ErrorNote(_formError!),
            ],

            const SizedBox(height: 16),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                Refused(
                  reason: _canEdit ? null : _editRefusal,
                  child: FilledButton(
                    onPressed: _canEdit && !_saving ? _save : null,
                    child: const Text('Save'),
                  ),
                ),
                TextButton(
                  onPressed: _saving
                      ? null
                      : () => setState(() {
                          _draft = null;
                          _formError = null;
                        }),
                  child: const Text('Cancel'),
                ),
                if (draft.id.isNotEmpty)
                  Refused(
                    reason: _canEdit ? null : _editRefusal,
                    child: TextButton(
                      onPressed: _canEdit && !_saving
                          ? () => _delete(
                              _groups.firstWhere((g) => g.id == draft.id),
                            )
                          : null,
                      child: const Text('Delete this group'),
                    ),
                  ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

/// The form's working copy. Mutable on purpose — it is scratch until Save.
class _Draft {
  _Draft({
    required this.id,
    required this.connectionId,
    required this.name,
    required this.mode,
    required this.timeoutSeconds,
    required this.memberIds,
    required this.overflow,
    required this.overflowNumber,
    required this.overflowGroupId,
    required this.queueMaxSeconds,
    required this.queueAnnouncePosition,
    required this.queueAnnounceWait,
    required this.queueHoldMusicUrl,
  });

  final String id;
  String? connectionId;
  String name;
  String mode;
  int timeoutSeconds;
  Set<String> memberIds;
  String overflow;
  String overflowNumber;
  String? overflowGroupId;
  int queueMaxSeconds;
  bool queueAnnouncePosition;
  bool queueAnnounceWait;
  String queueHoldMusicUrl;
}

/// One person in a ring group, and whether a call would reach them.
class _Member {
  const _Member({
    required this.userId,
    required this.name,
    required this.reachable,
    required this.state,
  });

  final String userId;
  final String name;
  final bool reachable;

  /// The presence row's own word, or null where there is NO row — which is a
  /// different fact and gets a different sentence. `dnd` is somebody who chose
  /// not to be rung; null is somebody who has never opened the softphone, and
  /// that is usually a person who does not know they are in a ring group.
  final String? state;

  String get why => switch (state) {
    'registered' => 'Rings now',
    'dnd' => 'Do not disturb',
    'on_call' => 'On another call',
    _ => 'Never signed in to Phone',
  };

  factory _Member.fromJson(Map<String, dynamic> json) => _Member(
    userId: json['user_id'] as String? ?? '',
    name: json['name'] as String? ?? '',
    reachable: json['reachable'] == true,
    state: json['state'] as String?,
  );
}

class _Group {
  const _Group({
    required this.id,
    required this.connectionId,
    required this.name,
    required this.mode,
    required this.timeoutSeconds,
    required this.memberUserIds,
    required this.overflow,
    required this.members,
    required this.measuredAt,
    this.overflowGroupId,
    this.overflowNumber,
    this.queueMaxSeconds = 300,
    this.queueAnnouncePosition = true,
    this.queueAnnounceWait = false,
    this.queueHoldMusicUrl,
  });

  final String id;
  final String connectionId;
  final String name;
  final String mode;
  final int timeoutSeconds;
  final List<String> memberUserIds;
  final String overflow;

  /// Every member, with whether a call reaches them RIGHT NOW — answered by
  /// the server from the same code that does the dialling.
  final List<_Member> members;

  /// When that was measured. Not decoration: there is no live channel, so a
  /// reachability count with no time against it reads as current forever.
  final String measuredAt;

  final String? overflowGroupId;
  final String? overflowNumber;

  final int queueMaxSeconds;
  final bool queueAnnouncePosition;
  final bool queueAnnounceWait;
  final String? queueHoldMusicUrl;

  int get reachableCount => members.where((m) => m.reachable).length;

  factory _Group.fromJson(Map<String, dynamic> json) => _Group(
    id: json['id'] as String? ?? '',
    connectionId: json['connection_id'] as String? ?? '',
    name: json['name'] as String? ?? '',
    mode: json['mode'] as String? ?? 'simultaneous',
    timeoutSeconds: (json['timeout_seconds'] as num?)?.toInt() ?? 30,
    memberUserIds: (json['member_user_ids'] as List? ?? const [])
        .map((id) => id.toString())
        .toList(growable: false),
    overflow: json['overflow'] as String? ?? 'voicemail',
    members: [
      for (final row in (json['members'] as List? ?? const []))
        if (row is Map<String, dynamic>) _Member.fromJson(row),
    ],
    measuredAt: json['members_measured_at'] as String? ?? '',
    overflowGroupId: json['overflow_group_id'] as String?,
    overflowNumber: json['overflow_number'] as String?,
    queueMaxSeconds: (json['queue_max_seconds'] as num?)?.toInt() ?? 300,
    queueAnnouncePosition: json['queue_announce_position'] != false,
    queueAnnounceWait: json['queue_announce_wait'] == true,
    queueHoldMusicUrl: json['queue_hold_music_url'] as String?,
  );
}

class _Person {
  const _Person({required this.id, required this.name});

  final String id;
  final String name;

  factory _Person.fromJson(Map<String, dynamic> json) => _Person(
    id: json['id'] as String? ?? '',
    name: json['name'] as String? ?? '',
  );
}

class _Line {
  const _Line({
    required this.id,
    required this.connectionId,
    required this.e164,
    required this.label,
    this.ringGroupId,
  });

  final String id;
  final String connectionId;
  final String e164;
  final String label;
  final String? ringGroupId;

  factory _Line.fromJson(Map<String, dynamic> json) => _Line(
    id: json['id'] as String? ?? '',
    connectionId: json['connection_id'] as String? ?? '',
    e164: json['e164'] as String? ?? '',
    label: json['label'] as String? ?? '',
    ringGroupId: json['ring_group_id'] as String?,
  );
}

class _Conn {
  const _Conn({required this.id, required this.label});

  final String id;
  final String label;

  factory _Conn.fromJson(Map<String, dynamic> json) => _Conn(
    id: json['id'] as String? ?? '',
    label: switch (json['scope_type'] as String?) {
      'organization' => 'The group',
      _ => 'Company account',
    },
  );
}

/// The list's search box, sized to sit in the pane's header beside its
/// buttons: 40 high, the height of the controls around it.
Widget _searchBox({
  required Key key,
  required String hint,
  required ValueChanged<String> onChanged,
}) => SizedBox(
  width: 260,
  child: TextField(
    key: key,
    onChanged: onChanged,
    textAlignVertical: TextAlignVertical.center,
    decoration: InputDecoration(
      isDense: true,
      hintText: hint,
      prefixIcon: const Icon(Icons.search, size: 18),
      prefixIconConstraints: const BoxConstraints(minWidth: 36),
      border: const OutlineInputBorder(),
    ),
  ),
);


/// Whether any of [fields] holds [query], ignoring case. A query of digits
/// and phone punctuation also matches a number however it is stored, so
/// "(203) 555" finds +12035550199.
bool _searchHit(String query, Iterable<String?> fields) {
  final q = query.trim().toLowerCase();
  if (q.isEmpty) return true;
  final digits = q.replaceAll(RegExp(r'\D'), '');
  final phoneish = digits.length >= 3 && RegExp(r'^[\d\s()+.-]+$').hasMatch(q);
  for (final field in fields) {
    if (field == null || field.isEmpty) continue;
    final value = field.toLowerCase();
    if (value.contains(q)) return true;
    if (phoneish && value.replaceAll(RegExp(r'\D'), '').contains(digits)) {
      return true;
    }
  }
  return false;
}
