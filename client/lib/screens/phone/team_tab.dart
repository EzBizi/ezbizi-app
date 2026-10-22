import '../../core/phone_numbers.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../widgets/list_table.dart';
import '../../widgets/search_picker.dart';
import 'settings_pane.dart';

/// Phone › Team — who your people are, as far as the phone system is concerned.
///
/// ── The two halves of one question ───────────────────────────────
///
/// A person has an inbound identity and an outbound one, and they are
/// independent. The EXTENSION is how a caller reaches them: dial 0101 and it
/// rings Analorena — every extension begins with a zero, which is what tells
/// one apart from a menu key as a caller types. The NUMBER is what a customer sees when they ring back.
/// Somebody can have either without the other, which is why neither field
/// requires the other and why the row survives with only one filled in.
///
/// ── Why the number matters more than it looks ────────────────────
///
/// Before this existed, every outbound call went out under whichever line the
/// Call tab happened to have selected — which for everybody was the first line
/// on the account, because nothing ever changed the default. So four people
/// calling four customers all appeared as the same number, and every callback
/// landed on whoever answered that line.
class TeamTab extends StatefulWidget {
  const TeamTab({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<TeamTab> createState() => _TeamTabState();
}

class _TeamTabState extends State<TeamTab> {
  bool _loading = true;
  bool _saving = false;
  String? _error;

  String? _connectionId;
  List<_Person> _people = const [];
  List<_Line> _lines = const [];

  final Map<String, TextEditingController> _extensionOf = {};
  final Map<String, String?> _numberOf = {};

  /// What the server last gave us, to work out what is unsaved.
  Map<String, String> _savedExtension = const {};
  Map<String, String?> _savedNumber = const {};

  /// Which tile is picked: `ext`, `noext`, `line`, or null for everybody.
  String? _filter;

  /// What is typed in the search box. /phone/extensions returns everybody, so
  /// the filter runs here.
  String _query = '';

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    for (final field in _extensionOf.values) {
      field.dispose();
    }
    super.dispose();
  }

  bool get _canEdit => widget.session.me?.can('phone.edit') ?? false;

  Future<void> _load() async {
    setState(() => _error = null);

    final response = await widget.api.get('/phone/extensions');
    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _loading = false;
        _error = response.reason;
      });
      return;
    }

    final people = [
      for (final row in (response.data as List? ?? const []))
        if (row is Map<String, dynamic>) _Person.fromJson(row),
    ];

    setState(() {
      _loading = false;
      _people = people;
      _connectionId = response.body['connection_id'] as String?;
      _lines = [
        for (final row in (response.body['numbers'] as List? ?? const []))
          if (row is Map<String, dynamic>) _Line.fromJson(row),
      ];

      _savedExtension = {for (final p in people) p.userId: p.extension};
      _savedNumber = {for (final p in people) p.userId: p.numberId};

      for (final person in people) {
        _extensionOf
            .putIfAbsent(person.userId, TextEditingController.new)
            .text = person.extension;
        _numberOf[person.userId] = person.numberId;
      }
    });
  }

  List<String> get _unsaved => [
    for (final person in _people)
      if ((_extensionOf[person.userId]?.text.trim() ?? '') !=
              (_savedExtension[person.userId] ?? '') ||
          _numberOf[person.userId] != _savedNumber[person.userId])
        person.name,
  ];

  Future<void> _save() async {
    final connectionId = _connectionId;
    if (connectionId == null) return;

    setState(() {
      _saving = true;
      _error = null;
    });

    // One request per person, because the endpoint takes one person, and
    // stopping at the first refusal — the refusal names who already holds an
    // extension, and burying that under three more attempts would lose the
    // only sentence worth reading.
    for (final person in _people) {
      final typed = _extensionOf[person.userId]?.text.trim() ?? '';
      final picked = _numberOf[person.userId];

      final unchanged =
          typed == (_savedExtension[person.userId] ?? '') &&
          picked == _savedNumber[person.userId];
      if (unchanged) continue;

      final response = await widget.api.put('/phone/extensions', {
        'connection_id': connectionId,
        'user_id': person.userId,
        'extension': typed.isEmpty ? null : typed,
        'number_id': picked,
      });

      if (!mounted) return;

      if (!response.ok) {
        // What already saved stays saved, so the footer names only the people
        // still outstanding.
        setState(() {
          _saving = false;
          _error = response.reason;
        });
        return;
      }

      _savedExtension = {..._savedExtension, person.userId: typed};
      _savedNumber = {..._savedNumber, person.userId: picked};
    }

    setState(() => _saving = false);
  }

  void _discard() {
    setState(() {
      for (final person in _people) {
        _extensionOf[person.userId]?.text = _savedExtension[person.userId] ?? '';
        _numberOf[person.userId] = _savedNumber[person.userId];
      }
      _error = null;
    });
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    if (_loading) return const Center(child: CircularProgressIndicator());

    final unsaved = _unsaved;

    return SettingsPane(
      title: 'Users',
      subtitle: 'Who each person is on the phone: the extension that reaches '
          'them, and the number they call out from.',
      error: _error,
      trailing: _connectionId == null
          ? null
          : Align(
              alignment: Alignment.centerRight,
              child: _searchBox(
                key: const ValueKey('comm-users-search'),
                hint: 'Search name, extension or number',
                onChanged: (value) => setState(() => _query = value),
              ),
            ),

      // Offered only when there is nothing typed to lose. A refused save is
      // reported here and retried with Save; reloading would discard the edit
      // in the course of offering to recover it.
      onRetry: unsaved.isEmpty ? _load : null,

      footer: _canEdit && _connectionId != null
          ? SettingsPaneFooter(
              key: const ValueKey('tour-phone-users-save'),
              unsaved: unsaved,
              saving: _saving,
              onSave: _save,
              onDiscard: _discard,
            )
          : null,
      children: [
        SettingsSection(
          key: const ValueKey('tour-phone-users-calling-out'),
          title: 'Calling out',
          children: [
            Text(
              'Whoever has no number here calls out from whichever line '
              'Phone › Dial has selected — which is the first line on the account '
              'unless somebody changes it. So without an assignment, everybody '
              'appears to customers as the same number, and every callback '
              'lands on whoever answers that line.',
              style: theme.textTheme.bodySmall,
            ),

            if (_lines.isEmpty) ...[
              const SizedBox(height: 12),
              Text(
                'No numbers on this phone account yet. Buy or import one on '
                'Settings › Numbers first.',
                // Muted, not red: a next step, not a failure (UI/UX).
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                ),
              ),
            ],
          ],
        ),

        if (_connectionId == null)
          SettingsSection(
            title: 'People',
            children: [
              Text(
                'No phone account is connected yet, so there is nothing to '
                'assign.',
                style: theme.textTheme.bodySmall,
              ),
            ],
          )
        else
          ..._peopleTable(),
      ],
    );
  }

  /// Tiles over the editable table (Mia, 2026-10-02, the `users` mockup).
  ///
  /// Counted, and filtered, on what is SAVED rather than what is typed: a row
  /// somebody is halfway through giving an extension would otherwise jump out
  /// of "No extension" under their cursor and take the field with it. The
  /// endpoint returns everybody at once, so the counts are taken here.
  List<Widget> _peopleTable() {
    bool hasExtension(_Person p) => (_savedExtension[p.userId] ?? '').isNotEmpty;
    bool ownLine(_Person p) => _savedNumber[p.userId] != null;

    final shown = [
      for (final person in _people)
        if (switch (_filter) {
          'ext' => hasExtension(person),
          'noext' => !hasExtension(person),
          'line' => ownLine(person),
          _ => true,
        })
          if (_personHit(person)) person,
    ];

    return [
      FilterTiles(
        key: const ValueKey('tour-phone-users-tiles'),
        prefix: 'users',
        picked: _filter,
        onPick: (key) => setState(() => _filter = key),
        tiles: [
          FilterTile(
            key: null,
            label: 'People',
            count: _people.length,
            note: 'in Communication',
          ),
          FilterTile(
            key: 'ext',
            label: 'With an extension',
            count: _people.where(hasExtension).length,
            note: 'can be dialled inside',
          ),
          FilterTile(
            key: 'noext',
            label: 'No extension',
            count: _people.where((p) => !hasExtension(p)).length,
            note: 'cannot be dialled inside',
            tone: Tone.caution,
          ),
          FilterTile(
            key: 'line',
            label: 'Own outgoing line',
            count: _people.where(ownLine).length,
            note: 'others use the selected line',
          ),
        ],
      ),
      const SizedBox(height: 12),
      if (shown.isEmpty)
        Padding(
          padding: const EdgeInsets.all(24),
          child: Text(
            _query.trim().isNotEmpty
                ? 'Nothing matches "${_query.trim()}".'
                : 'Nobody in this state.',
            textAlign: TextAlign.center,
          ),
        )
      else
        ListTable(
          key: const ValueKey('tour-phone-users-table'),
          columns: const [
            ListColumn('Person', flex: 3),
            ListColumn('Extension', flex: 2),
            ListColumn('Calls out from', flex: 4),
          ],
          rowKey: (i) => ValueKey('user-${shown[i].userId}'),
          rows: [for (final person in shown) _cells(person)],
        ),
    ];
  }

  bool _personHit(_Person person) {
    final lineId = _savedNumber[person.userId];
    final line = [
      for (final l in _lines)
        if (l.id == lineId) l,
    ];
    return _searchHit(_query, [
      person.name,
      _savedExtension[person.userId],
      for (final l in line) ...[l.label, l.e164],
    ]);
  }

  List<Widget> _cells(_Person person) {
    return [
      PersonCell(name: person.name),
      SizedBox(
        width: 120,
        child: TextField(
          key: Key('extension:${person.userId}'),
          controller: _extensionOf[person.userId],
          enabled: _canEdit,
          keyboardType: TextInputType.number,
          inputFormatters: [
            FilteringTextInputFormatter.digitsOnly,
            LengthLimitingTextInputFormatter(8),
          ],
          onChanged: (_) => setState(() {}),
          decoration: const InputDecoration(
            // The rule, said in the field rather than only in the 422.
            // Jose, 2026-09-22: every extension begins with a zero — it is
            // what tells an extension apart from a menu key at the moment
            // a caller presses it. Somebody typing 101 here would
            // otherwise learn that only when the save came back refused.
            hintText: '0101',
            helperText: 'Starts with 0',
            border: OutlineInputBorder(),
            isDense: true,
          ),
        ),
      ),
      // A width of its own, because a stacked row (below 720) lays its cells
      // in a Wrap, and a picker there would otherwise take the whole line or
      // none of it.
      ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 340),
        child: SearchPickerField<String?>(
          key: Key('number:${person.userId}'),
          value: _numberOf[person.userId],
          border: const OutlineInputBorder(),
          isDense: true,
          options: [
            // Named rather than blank, because an empty option reads as
            // "not loaded" and this is a real, common answer.
            const SearchOption<String?>(
              value: null,
              label: 'Whichever line is selected',
            ),
            for (final line in _lines)
              SearchOption<String?>(
                value: line.id,
                label: line.label.isEmpty
                    ? formatPhoneNumber(line.e164)
                    : '${line.label} · ${formatPhoneNumber(line.e164)}',
              ),
          ],
          onChanged: _canEdit
              ? (id) => setState(() => _numberOf[person.userId] = id)
              : null,
        ),
      ),
    ];
  }
}

class _Person {
  const _Person({
    required this.userId,
    required this.name,
    required this.extension,
    required this.numberId,
  });

  final String userId;
  final String name;
  final String extension;
  final String? numberId;

  factory _Person.fromJson(Map<String, dynamic> json) => _Person(
    userId: json['user_id'] as String? ?? '',
    name: json['name'] as String? ?? '',
    extension: (json['extension'] ?? '').toString(),
    numberId: json['number_id'] as String?,
  );
}

class _Line {
  const _Line({required this.id, required this.e164, required this.label});

  final String id;
  final String e164;
  final String label;

  factory _Line.fromJson(Map<String, dynamic> json) => _Line(
    id: json['id'] as String? ?? '',
    e164: json['e164'] as String? ?? '',
    label: (json['label'] ?? '').toString(),
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
  height: 40,
  child: TextField(
    key: key,
    onChanged: onChanged,
    textAlignVertical: TextAlignVertical.center,
    decoration: InputDecoration(
      isDense: true,
      hintText: hint,
      prefixIcon: const Icon(Icons.search, size: 18),
      prefixIconConstraints: const BoxConstraints(minWidth: 36),
      contentPadding: const EdgeInsets.symmetric(horizontal: 8),
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
