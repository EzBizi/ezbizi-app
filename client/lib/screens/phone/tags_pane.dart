import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../widgets/error_note.dart';
import '../../widgets/list_table.dart';
import '../../widgets/refused.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/share_bar.dart';

import 'phone_search.dart';

/// Phone › Settings › Tags — what calls get counted by.
///
/// ── Why a list and not free text ─────────────────────────────────
///
/// The point of tagging is counting. Free text cannot be counted: "no answer",
/// "No Answer" and "noanswer" are three answers to one question, and a report
/// built on them is wrong in a way nobody notices. A named list means renaming
/// a tag renames it everywhere and last month still adds up.
///
/// ── Why removing a used tag retires it ───────────────────────────
///
/// Deleting one would blank the label on every call it described and quietly
/// change totals that have already been read. So a tag that has been used is
/// retired: off the picker, still on its calls. The screen says which, and how
/// many, before anybody presses it.
class TagsPane extends StatefulWidget {
  const TagsPane({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<TagsPane> createState() => _TagsPaneState();
}

class _TagsPaneState extends State<TagsPane> {
  /// The company's connection, and whether it insists on a tag.
  ///
  /// One row per company, so this is where a company-wide rule belongs rather
  /// than on each line — a rule set per number is one somebody has to set
  /// seven times and can leave off the eighth.
  String? _connectionId;
  bool _requireTag = false;
  bool _savingRule = false;

  /// Seconds somebody is left alone after a call. Zero is off.
  ///
  /// Lives beside the tag rule because it is the time the tag is written in.
  /// A company that insists on a tag and gives nobody a moment to pick one has
  /// made a dialogue people dismiss.
  int _wrapUpSeconds = 0;

  /// Which tag is open for editing (its name and colour), if any. The editor
  /// opens in place above the table rather than over it: no modals, and a
  /// colour is chosen by comparing it with the tags listed under it.
  String? _colouring;
  final _rename = TextEditingController();

  /// The tile picked: `on` (offered on calls), `off` (retired), or null for
  /// every tag. The list comes back whole, so the tiles count it here (Mia,
  /// 2026-10-02).
  String? _shown;

  /// What is typed in the search box. /phone/tags returns every tag, so the
  /// filter runs here.
  String _query = '';

  bool _loading = true;
  String? _error;
  String? _note;

  List<_Tag> _tags = const [];

  final _name = TextEditingController();
  bool _saving = false;
  String? _formError;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _name.dispose();
    _rename.dispose();
    super.dispose();
  }

  Me get _me => widget.session.me!;

  bool get _canEdit => _me.can('phone.edit');

  String? get _editRefusal => _me.refusal('phone.edit', 'change the call tags');

  Future<void> _load() async {
    setState(() {
      _loading = _tags.isEmpty;
      _error = null;
    });

    final response = await widget.api.get('/phone/tags');

    if (!mounted) return;

    setState(() {
      _loading = false;
      if (!response.ok) {
        _error = response.reason;
        return;
      }
      _error = null;
      _tags = _Tag.listFrom(response.body);
    });

    await _loadRule();
  }

  /// The company's connection, which is where its rule lives.
  Future<void> _loadRule() async {
    final response = await widget.api.get('/phone/connections');
    if (!mounted || !response.ok) return;

    for (final row in (response.data as List? ?? const [])) {
      if (row is! Map<String, dynamic>) continue;

      setState(() {
        _connectionId = row['id'] as String?;
        _requireTag = row['require_call_tag'] == true;
        _wrapUpSeconds = (row['wrap_up_seconds'] as num?)?.toInt() ?? 0;
      });

      return;
    }
  }

  Future<void> _add() async {
    final name = _name.text.trim();

    if (name.isEmpty) {
      setState(() => _formError = 'Give the tag a name.');
      return;
    }

    setState(() {
      _saving = true;
      _formError = null;
    });

    final response = await widget.api.post('/phone/tags', {'name': name});

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _saving = false;
        _formError = response.reason;
      });
      return;
    }

    _name.clear();
    setState(() => _saving = false);
    await _load();
  }

  Future<void> _setActive(_Tag tag, bool active) async {
    final response = await widget.api.patch('/phone/tags/${tag.id}', {
      'is_active': active,
    });

    if (!mounted) return;

    if (!response.ok) {
      setState(() => _formError = response.reason);
      return;
    }

    await _load();
  }

  Future<void> _remove(_Tag tag) async {
    final response = await widget.api.delete('/phone/tags/${tag.id}');

    if (!mounted) return;

    if (!response.ok) {
      setState(() => _formError = response.reason);
      return;
    }

    setState(() => _note = response.body['message'] as String?);
    await _load();
  }

  /// Turn the company's rule on or off.
  ///
  /// Written straight through and re-read from the answer rather than assumed:
  /// a switch that flips on screen and not on the server is the shape this
  /// module keeps producing.
  Future<void> _setRequireTag(bool on) async {
    final id = _connectionId;
    if (id == null) return;

    setState(() => _savingRule = true);

    final response = await widget.api.patch('/phone/connections/$id', {
      'require_call_tag': on,
    });

    if (!mounted) return;

    setState(() {
      _savingRule = false;
      if (response.ok) {
        final data = response.data;
        _requireTag = data is Map && data['require_call_tag'] == true;
      } else {
        _formError = response.message;
      }
    });
  }

  /// How long the desk is left alone after a call.
  ///
  /// Read back from the answer rather than assumed, for the same reason as the
  /// rule above: a value that changes on screen and not on the server is the
  /// shape this module keeps producing.
  Future<void> _setWrapUp(int seconds) async {
    final id = _connectionId;
    if (id == null) return;

    setState(() => _savingRule = true);

    final response = await widget.api.patch('/phone/connections/$id', {
      'wrap_up_seconds': seconds,
    });

    if (!mounted) return;

    setState(() {
      _savingRule = false;
      if (response.ok) {
        final data = response.data;
        _wrapUpSeconds = data is Map
            ? ((data['wrap_up_seconds'] as num?)?.toInt() ?? 0)
            : 0;
      } else {
        _formError = response.message;
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    if (_loading) {
      return const Center(child: CircularProgressIndicator());
    }

    if (_error != null) {
      return ErrorRetry(_error!, onRetry: _load);
    }

    final live = _tags.where((t) => t.isActive).toList();
    final retired = _tags.where((t) => !t.isActive).toList();
    final shown = [
      for (final tag in switch (_shown) {
        'on' => live,
        'off' => retired,
        _ => _tags,
      })
        if (phoneSearchHit(_query, [tag.name])) tag,
    ];

    return ListView(
      key: const ValueKey('tags-pane'),
      padding: const EdgeInsets.all(24),
      children: [
        Row(
          children: [
            Expanded(child: Text('Tags', style: theme.textTheme.titleMedium)),
            if (_tags.isNotEmpty)
              Flexible(
                child: Align(
                  alignment: Alignment.centerRight,
                  child: phoneSearchBox(
                    key: const ValueKey('comm-tags-search'),
                    hint: 'Search tags',
                    onChanged: (value) => setState(() => _query = value),
                  ),
                ),
              ),
          ],
        ),
        const SizedBox(height: 4),
        Text(
          'What a call was about. Whoever answers picks one when the call ends, '
          'and reports count by them.',
          style: theme.textTheme.bodySmall,
        ),

        const SizedBox(height: 12),

        // The rule, beside the thing it is a rule about.
        //
        // Off unless somebody turns it on: a company with no tags set up would
        // otherwise find its dial pad locked by the first call it took, by a
        // requirement it never chose.
        Refused(
          reason: _canEdit ? null : _editRefusal,
          child: SwitchListTile(
            key: const ValueKey('require-call-tag'),
            value: _requireTag,
            onChanged: _canEdit && !_savingRule && _connectionId != null
                ? _setRequireTag
                : null,
            contentPadding: EdgeInsets.zero,
            title: const Text('Make a tag required after every call'),
            subtitle: Text(
              _tags.where((tag) => tag.isActive).isEmpty
                  ? 'Add at least one tag first — a requirement with nothing '
                        'to choose would stop the phone.'
                  : 'The person who took the call picks one before the desk '
                        'can start another. Applies to this company.',
              style: theme.textTheme.bodySmall,
            ),
          ),
        ),

        // The time the tag is written in.
        //
        // A dropdown of sensible values rather than a number field: what
        // matters is "a moment" or "long enough to write a note", and a box
        // that accepts 3600 is a box somebody eventually types 3600 into and
        // then wonders why the phone stopped ringing.
        Refused(
          reason: _canEdit ? null : _editRefusal,
          child: Padding(
            padding: const EdgeInsets.only(top: 8),
            child: Row(
              children: [
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Text('Time to write the call up'),
                      Text(
                        _wrapUpSeconds == 0
                            ? 'The next call can arrive the moment this one '
                                  'ends.'
                            : 'No call is sent to that person for '
                                  '$_wrapUpSeconds seconds after they hang up. '
                                  'They can say they are ready sooner.',
                        style: theme.textTheme.bodySmall,
                      ),
                    ],
                  ),
                ),
                const SizedBox(width: 12),
                SizedBox(
                  width: 150,
                  child: SearchPickerField<int>(
                    key: const ValueKey('wrap-up-seconds'),
                    value: _wrapUpSeconds,
                    border: InputBorder.none,
                    isDense: true,
                    onChanged:
                        _canEdit && !_savingRule && _connectionId != null
                        ? (value) => value == null ? null : _setWrapUp(value)
                        : null,
                    options: const [
                      SearchOption(value: 0, label: 'None'),
                      SearchOption(value: 15, label: '15 seconds'),
                      SearchOption(value: 30, label: '30 seconds'),
                      SearchOption(value: 60, label: '1 minute'),
                      SearchOption(value: 120, label: '2 minutes'),
                      SearchOption(value: 300, label: '5 minutes'),
                    ],
                  ),
                ),
              ],
            ),
          ),
        ),

        if (_note != null) ...[
          const SizedBox(height: 12),
          Text(_note!, style: theme.textTheme.bodySmall),
        ],

        if (_formError != null) ...[
          const SizedBox(height: 12),
          ErrorNote(_formError!),
        ],

        const SizedBox(height: 16),

        Refused(
          reason: _canEdit ? null : _editRefusal,
          child: Row(
            children: [
              Expanded(
                child: TextField(
                  key: const ValueKey('tag-name'),
                  controller: _name,
                  enabled: _canEdit,
                  decoration: const InputDecoration(
                    labelText: 'A new tag',
                    hintText: 'Warranty',
                    border: OutlineInputBorder(),
                    isDense: true,
                  ),
                  onSubmitted: (_) => _canEdit ? _add() : null,
                ),
              ),
              const SizedBox(width: 12),
              FilledButton(
                onPressed: (!_canEdit || _saving) ? null : _add,
                child: Text(_saving ? 'Adding…' : 'Add'),
              ),
            ],
          ),
        ),

        const SizedBox(height: 24),

        if (_tags.isEmpty)
          Text(
            'No tags yet. Until there is at least one, a call cannot be said to '
            'be about anything.',
            style: theme.textTheme.bodySmall,
          )
        else ...[
          FilterTiles(
            prefix: 'tags',
            picked: _shown,
            onPick: (key) => setState(() => _shown = key),
            tiles: [
              FilterTile(
                key: null,
                label: 'Tags',
                count: _tags.length,
                note: 'all',
              ),
              FilterTile(
                key: 'on',
                label: 'In use',
                count: live.length,
                note: 'offered on calls',
              ),
              FilterTile(
                key: 'off',
                label: 'Retired',
                count: retired.length,
                note: 'kept on old calls',
              ),
            ],
          ),
          const SizedBox(height: 12),
          if (_editing != null) _editor(_editing!, theme),
          if (shown.isEmpty)
            Padding(
              padding: const EdgeInsets.all(24),
              child: Text(
                _query.trim().isNotEmpty
                    ? 'Nothing matches "${_query.trim()}".'
                    : _shown == 'off'
                    ? 'Nothing retired.'
                    : 'No tag is offered on calls right now.',
                textAlign: TextAlign.center,
              ),
            )
          else
            _table(shown, theme),
        ],
      ],
    );
  }

  _Tag? get _editing {
    for (final tag in _tags) {
      if (tag.id == _colouring) return tag;
    }

    return null;
  }

  /// Tag, calls, share of calls, and what can be done to it (layout A, Mia,
  /// 2026-10-02). The share is of every tagged call, retired tags included,
  /// so the bars add up to the whole and last month still reads the same.
  Widget _table(List<_Tag> shown, ThemeData theme) {
    final total = _tags.fold<int>(0, (sum, tag) => sum + tag.callCount);
    final small = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );

    return ListTable(
      columns: const [
        ListColumn('Tag', flex: 4),
        ListColumn('Calls', flex: 2, end: true),
        ListColumn('Share of calls', flex: 4),
        ListColumn('', flex: 4, end: true),
      ],
      rowKey: (i) => ValueKey('tag:${shown[i].id}'),
      rows: [
        for (final tag in shown)
          [
            Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                // The swatch IS a control: a tag's colour is the thing you
                // are looking at when you decide to change it.
                _canEdit
                    ? InkWell(
                        borderRadius: BorderRadius.circular(20),
                        onTap: () => _pickColour(tag),
                        child: _swatch(tag.color, theme, ringed: true),
                      )
                    : _swatch(tag.color, theme, ringed: false),
                const SizedBox(width: 10),
                Flexible(
                  child: Text(
                    tag.name,
                    overflow: TextOverflow.ellipsis,
                    style: theme.textTheme.bodyMedium?.copyWith(
                      fontWeight: FontWeight.w600,
                      color: tag.isActive
                          ? null
                          : theme.colorScheme.onSurfaceVariant,
                    ),
                  ),
                ),
              ],
            ),
            Text(
              '${tag.callCount}',
              style: theme.textTheme.bodyMedium?.copyWith(
                fontFeatures: const [FontFeature.tabularFigures()],
              ),
            ),
            ShareBar(share: total == 0 ? 0 : tag.callCount / total),
            !_canEdit
                ? Text(tag.callCount == 0 ? 'Not used yet' : '', style: small)
                : Wrap(
                    alignment: WrapAlignment.end,
                    children: [
                      if (tag.isActive)
                        TextButton(
                          onPressed: () => _pickColour(tag),
                          child: const Text('Edit'),
                        ),
                      TextButton(
                        onPressed: () => _setActive(tag, !tag.isActive),
                        child: Text(tag.isActive ? 'Retire' : 'Bring back'),
                      ),
                      if (tag.callCount == 0)
                        TextButton(
                          onPressed: () => _remove(tag),
                          child: const Text('Remove'),
                        ),
                    ],
                  ),
          ],
      ],
    );
  }

  /// The open tag's name and colour, above the table.
  Widget _editor(_Tag tag, ThemeData theme) {
    return Card(
      key: const ValueKey('tag-editor'),
      margin: const EdgeInsets.only(bottom: 12),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 16, 16, 4),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: TextField(
                    key: const ValueKey('tag-rename'),
                    controller: _rename,
                    decoration: const InputDecoration(
                      labelText: 'Name',
                      border: OutlineInputBorder(),
                      isDense: true,
                    ),
                    onSubmitted: (_) => _saveName(tag),
                  ),
                ),
                const SizedBox(width: 12),
                FilledButton(
                  onPressed: () => _saveName(tag),
                  child: const Text('Save'),
                ),
                TextButton(
                  onPressed: () => setState(() => _colouring = null),
                  child: const Text('Close'),
                ),
              ],
            ),
            const SizedBox(height: 12),
            _palettePicker(tag, theme),
          ],
        ),
      ),
    );
  }

  /// Renaming renames it on every call it is on, which is the point of a list
  /// over free text (see the note at the top).
  Future<void> _saveName(_Tag tag) async {
    final name = _rename.text.trim();
    if (name.isEmpty || name == tag.name) {
      setState(() => _colouring = null);
      return;
    }

    final response = await widget.api.patch('/phone/tags/${tag.id}', {
      'name': name,
    });

    if (!mounted) return;

    if (!response.ok) {
      setState(() => _formError = response.reason);
      return;
    }

    setState(() => _colouring = null);
    await _load();
  }

  /// The eight the server accepts. Kept in step with PhoneCallTag::COLORS —
  /// a colour this list offers and the server refuses would save as nothing
  /// and read as "the tag did not keep my choice".
  static const _palette = <String>[
    '#E5484D',
    '#E8912A',
    '#F5C518',
    '#46A758',
    '#12A594',
    '#00AEEF',
    '#3E63DD',
    '#8E4EC6',
  ];

  static Color? _parse(String? hex) {
    if (hex == null) return null;

    final cleaned = hex.replaceFirst('#', '');
    final value = int.tryParse(cleaned, radix: 16);

    return value == null ? null : Color(0xFF000000 | value);
  }

  Widget _swatch(String? hex, ThemeData theme, {required bool ringed}) {
    final colour = _parse(hex);

    return Container(
      width: 24,
      height: 24,
      decoration: BoxDecoration(
        // No colour is drawn as an outline rather than as grey: grey is a
        // colour somebody might have chosen, and "none" should not look like
        // a choice they made.
        color: colour ?? Colors.transparent,
        shape: BoxShape.circle,
        border: Border.all(
          color: colour == null
              ? theme.colorScheme.outline
              : theme.colorScheme.outlineVariant,
          width: colour == null ? 1.5 : 1,
        ),
      ),
      child: colour == null && ringed
          ? Icon(Icons.add, size: 14, color: theme.colorScheme.outline)
          : null,
    );
  }

  /// Opens in place under the row rather than over it — the house rule is no
  /// modals, and a colour is chosen by comparing it with the tags around it.
  Future<void> _pickColour(_Tag tag) async {
    _rename.text = tag.name;
    setState(() => _colouring = _colouring == tag.id ? null : tag.id);
  }

  Future<void> _setColour(_Tag tag, String? hex) async {
    setState(() => _colouring = null);

    final response = await widget.api.patch('/phone/tags/${tag.id}', {
      'color': hex,
    });

    if (!mounted) return;

    if (!response.ok) {
      setState(() => _error = response.reason);
      return;
    }

    await _load();
  }

  Widget _palettePicker(_Tag tag, ThemeData theme) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: Wrap(
        spacing: 8,
        runSpacing: 8,
        children: [
          for (final hex in _palette)
            InkWell(
              borderRadius: BorderRadius.circular(20),
              onTap: () => _setColour(tag, hex),
              child: _swatch(hex, theme, ringed: false),
            ),
          InkWell(
            borderRadius: BorderRadius.circular(20),
            onTap: () => _setColour(tag, null),
            child: _swatch(null, theme, ringed: false),
          ),
        ],
      ),
    );
  }
}

@immutable
class _Tag {
  const _Tag({
    required this.id,
    required this.name,
    required this.isActive,
    required this.callCount,
    this.color,
  });

  final String id;
  final String name;
  final bool isActive;
  final int callCount;

  /// Hex the customer picked, from a fixed set the server validates. Null is
  /// a real choice — an untinted tag — not a missing value.
  final String? color;

  static List<_Tag> listFrom(dynamic payload) {
    final data = payload is Map ? payload['data'] : payload;
    if (data is! List) return const [];

    return data
        .whereType<Map<String, dynamic>>()
        .map(
          (r) => _Tag(
            id: r['id'].toString(),
            name: r['name']?.toString() ?? '',
            isActive: r['is_active'] != false,
            callCount: (r['call_count'] as num?)?.toInt() ?? 0,
            color: r['color'] as String?,
          ),
        )
        .toList(growable: false);
  }
}
