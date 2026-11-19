import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../widgets/async_body.dart';
import '../../widgets/dashboard_card.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/side_panel.dart';
import '../../widgets/status_pill.dart';
import 'builder_model.dart';
import 'builder_phone.dart';
import 'builder_question.dart';
import 'inspection_flow.dart';

/// Inspection › Builder (UI queue 56; Jose, 2026-10-06: "go ahead" on 1 and 4).
///
/// Jose's word for what it replaced: *"extremely hard to understand and use, it
/// makes no sense whatsoever."* Measured, it was three lists about a form —
/// a question's settings opened under all forty-two of its neighbours, screens
/// and questions sat in unrelated lists, and the words were the engine's.
///
/// Now the form itself: the screens run down the left in the order a
/// technician walks them, the chosen screen's questions fill the middle as
/// cards that say in words what they are, and a card opens WHERE IT IS
/// ([QuestionEditor]). A live phone sits beside them ([LivePhone]), or the Map
/// of which screens are reached. On a phone the same three steps are pages:
/// the screens, a screen, a question opened in place.
///
/// Changes save themselves. The published version is what technicians use
/// until Publish, so an edit here can never reach a technician half done.
class BuilderTab extends StatefulWidget {
  const BuilderTab({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  /// Below this the Builder is a phone: screens, then a screen, then a question.
  static const double phoneBelow = 720;

  /// From this width the live phone sits beside the questions; below it the
  /// phone opens from Preview.
  static const double phoneBesideFrom = 1100;

  @override
  State<BuilderTab> createState() => _BuilderTabState();
}

class _BuilderTabState extends State<BuilderTab> {
  bool _loading = true;
  String? _error;
  int _request = 0;

  List<Map<String, dynamic>> _definitions = [];
  List<Map<String, dynamic>> _types = const [];
  List<Map<String, dynamic>> _scopes = const [];
  List<Map<String, dynamic>> _codes = const [];
  List<Map<String, dynamic>> _bindable = const [];

  String? _selectedId;
  int _screenIndex = 0;

  /// The question open where it stands, by its stamp.
  String? _openUid;

  /// A question just made, whose words want the cursor.
  String? _focusUid;

  /// The right-hand pane shows the Map rather than the phone.
  bool _showMap = false;

  /// On a phone: inside a screen rather than on the list of screens, and
  /// looking at its preview rather than its questions.
  bool _onScreen = false;
  bool _phonePreview = false;

  bool _screenWhenOpen = false;
  bool _askDeleteScreen = false;

  /// What has been tapped on the phone. Samples, saved nowhere.
  final Map<String, dynamic> _sample = {};

  // ── Saving ──────────────────────────────────────────────────────────
  Timer? _saveTimer;
  Future<void> _saveChain = Future.value();
  bool _dirty = false;
  bool _saving = false;
  String? _saveError;
  String? _busy;
  bool _gone = false;

  Map<String, dynamic>? get _selected {
    for (final row in _definitions) {
      if (row['id'] == _selectedId) return row;
    }
    return null;
  }

  List<Map<String, dynamic>> get _screens => screensOf(_selected);

  Map<String, dynamic>? get _screen {
    final screens = _screens;
    if (screens.isEmpty) return null;
    return screens[_screenIndex.clamp(0, screens.length - 1)];
  }

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _saveTimer?.cancel();
    // Whatever was typed in the last moment still goes, even though nobody is
    // left on this screen to be told how it went. `mounted` is still true
    // inside dispose, so the save is told not to redraw.
    _gone = true;
    if (_dirty && _selectedId != null) unawaited(_saveNow(_selectedId!));
    super.dispose();
  }

  Future<void> _load() async {
    final request = ++_request;
    setState(() {
      _loading = _definitions.isEmpty;
      _error = null;
    });

    final answers = await Future.wait([
      widget.api.get('/inspections'),
      widget.api.get('/inspections/catalogue'),
    ]);
    if (!mounted || request != _request) return;

    final list = answers[0];
    final catalogue = answers[1];

    setState(() {
      _loading = false;
      if (!list.ok) {
        _error = list.reason;
        return;
      }
      _definitions = _maps(list.data);
      _definitions.forEach(assignUids);
      if (_selectedId == null && _definitions.isNotEmpty) {
        _selectedId = _definitions.first['id'] as String?;
      }
      if (catalogue.ok && catalogue.data is Map) {
        final data = Map<String, dynamic>.from(catalogue.data as Map);
        _types = _maps(data['field_types']);
        _scopes = _maps(data['scopes']);
        _codes = _maps(data['codes']);
        _bindable = _maps(data['bindable']);
      }
    });
  }

  static List<Map<String, dynamic>> _maps(dynamic raw) {
    if (raw is! List) return [];
    return [
      for (final row in raw)
        if (row is Map) Map<String, dynamic>.from(row),
    ];
  }

  // ── Saving as you go ────────────────────────────────────────────────

  /// Something was edited: redraw now, save in a moment.
  void _changed() {
    _dirty = true;
    _saveError = null;
    _saveTimer?.cancel();
    _saveTimer = Timer(const Duration(milliseconds: 700), _queueSave);
    if (mounted) setState(() {});
  }

  void _queueSave() {
    _saveTimer?.cancel();
    _saveTimer = null;
    final id = _selectedId;
    if (id == null) return;
    _saveChain = _saveChain.then((_) => _saveNow(id));
  }

  /// Everything typed is saved, or the reason it is not is on screen.
  Future<bool> _flush() async {
    if (_saveTimer != null || _dirty) _queueSave();
    await _saveChain;
    return !_dirty && _saveError == null;
  }

  Future<void> _saveNow(String id) async {
    Map<String, dynamic>? current;
    for (final row in _definitions) {
      if (row['id'] == id) current = row;
    }
    if (current == null || !_dirty) return;

    _dirty = false;
    if (mounted && !_gone) setState(() => _saving = true);

    final scope = current['scope'] as Map?;
    final response = await widget.api.patch('/inspections/$id', {
      'name': current['name'],
      'intro': current['intro'],
      'layout_preset': current['layout_preset'],
      'screens': withoutUids(current['screens'] as List? ?? const []),
      // Flat keys, not the nested `scope` object the server SENDS. It reads
      // `scope_type`/`scope_id` on the way in and answers `scope` on the way
      // out; posting the nested object silently changes nothing.
      'scope_type': scope?['type'],
      'scope_id': scope?['id'],
    });

    _saving = false;
    if (!response.ok) {
      _dirty = true;
      _saveError = response.reason;
    } else if (response.data is Map) {
      // Only what the server owns comes back in. The screens stay as they are
      // here: replacing them with the server's would drop a half-typed answer
      // out from under somebody still typing it.
      _adoptStatus(current, Map<String, dynamic>.from(response.data as Map));
    }
    if (mounted && !_gone) setState(() {});
  }

  void _adoptStatus(Map<String, dynamic> into, Map<String, dynamic> from) {
    for (final key in [
      'published',
      'published_at',
      'current_version',
      'has_cover',
    ]) {
      if (from.containsKey(key)) into[key] = from[key];
    }
  }

  // ── Inspections ─────────────────────────────────────────────────────

  Future<void> _select(String? id) async {
    if (id == null || id == _selectedId) return;
    await _flush();
    if (!mounted) return;
    setState(() {
      _selectedId = id;
      _resetPlace();
    });
  }

  void _resetPlace() {
    _screenIndex = 0;
    _openUid = null;
    _onScreen = false;
    _phonePreview = false;
    _screenWhenOpen = false;
    _askDeleteScreen = false;
    _sample.clear();
  }

  Future<void> _pickInspection() async {
    final picked = await showSearchPicker<String>(
      context,
      title: 'Inspections',
      value: _selectedId,
      // Searchable however few there are (Jose, 2026-10-22: every list gets a
      // search bar); the picker's own default waits for eight.
      searchFrom: 1,
      options: [
        for (final row in _definitions)
          SearchOption(
            value: '${row['id']}',
            label: '${row['name'] ?? ''}',
            detail: _status(row).$1,
          ),
      ],
    );
    await _select(picked);
  }

  Future<void> _create() async {
    await _flush();
    setState(() => _busy = 'create');
    final response = await widget.api.post('/inspections', {});
    if (!mounted) return;
    setState(() => _busy = null);
    if (!response.ok) {
      setState(() => _saveError = response.reason);
      return;
    }
    final row = Map<String, dynamic>.from(response.data as Map);
    assignUids(row);
    setState(() {
      _definitions = [..._definitions, row];
      _selectedId = row['id'] as String?;
      _resetPlace();
    });
  }

  Future<void> _publish() async {
    final current = _selected;
    if (current == null) return;
    setState(() => _busy = 'publish');
    final saved = await _flush();
    if (!mounted) return;
    if (!saved) {
      setState(() => _busy = null);
      return;
    }
    final response = await widget.api.post(
      '/inspections/${current['id']}/publish',
      {},
    );
    if (!mounted) return;
    setState(() {
      _busy = null;
      if (!response.ok) {
        _saveError = response.reason;
      } else if (response.data is Map) {
        _adoptStatus(current, Map<String, dynamic>.from(response.data as Map));
      }
    });
    if (response.ok) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            'Published. Technicians now get version ${current['current_version']}.',
          ),
        ),
      );
    }
  }

  Future<void> _unpublish() async {
    final current = _selected;
    if (current == null) return;
    await _flush();
    final response = await widget.api.post(
      '/inspections/${current['id']}/unpublish',
      {},
    );
    if (!mounted) return;
    setState(() {
      if (!response.ok) {
        _saveError = response.reason;
      } else if (response.data is Map) {
        _adoptStatus(current, Map<String, dynamic>.from(response.data as Map));
      }
    });
  }

  Future<void> _deleteDraft() async {
    final current = _selected;
    if (current == null || !_isDeletableDraft(current)) return;
    _saveTimer?.cancel();
    _saveTimer = null;
    _dirty = false;
    await _saveChain;
    final response = await widget.api.delete('/inspections/${current['id']}');
    if (!mounted) return;
    if (!response.ok) {
      setState(() => _saveError = response.reason);
      return;
    }
    setState(() {
      _definitions = [
        for (final row in _definitions)
          if (row['id'] != current['id']) row,
      ];
      _selectedId = _definitions.isEmpty
          ? null
          : _definitions.first['id'] as String?;
      _saveError = null;
      _resetPlace();
    });
  }

  static bool _isDeletableDraft(Map<String, dynamic> row) =>
      row['published'] != true && row['current_version'] == null;

  (String, Tone) _status(Map<String, dynamic> row) {
    final version = row['current_version'];
    if (row['published'] == true) {
      return ('Published · version $version', Tone.ok);
    }
    if (version != null) return ('Turned off · version $version', Tone.neutral);
    return ('Draft', Tone.neutral);
  }

  String _savedLine(Map<String, dynamic> row) {
    if (_saveError != null) return 'Not saved: $_saveError';
    if (_saving || _dirty) return 'Saving…';
    final version = row['current_version'];
    if (row['published'] == true) {
      return 'Saved. Technicians keep version $version until you publish.';
    }
    if (version != null) {
      return 'Saved. Technicians cannot start it while it is turned off.';
    }
    return 'Saved. Technicians cannot use it until you publish.';
  }

  /// The dropdown value for whatever this definition currently says.
  ///
  /// The organization is a real selectable value rather than a null, because
  /// "belongs to nobody" and "belongs to everybody" read identically on screen
  /// otherwise. Falls back to the first offered scope when the stored one is
  /// not among them — which happens when a definition scoped to a place this
  /// author cannot reach is opened by somebody who can still edit it.
  String? _scopeValue(Map<String, dynamic> current) {
    final scope = current['scope'] as Map?;
    final value = '${scope?['type'] ?? 'organization'}:${scope?['id'] ?? ''}';
    final offered = _scopes
        .map((s) => '${s['type'] ?? 'organization'}:${s['id'] ?? ''}')
        .toList();
    return offered.contains(value)
        ? value
        : (offered.isEmpty ? null : offered.first);
  }

  Future<void> _openSettings() async {
    final current = _selected;
    if (current == null) return;
    await showSidePanel<void>(
      context,
      builder: (context) => SidePanel(
        title: 'Settings',
        child: _Settings(
          definition: current,
          scopes: _scopes,
          scopeValue: _scopeValue(current),
          published: current['published'] == true,
          deletable: _isDeletableDraft(current),
          onChanged: _changed,
          onUnpublish: () async {
            Navigator.of(context).pop();
            await _unpublish();
          },
          onDelete: () async {
            Navigator.of(context).pop();
            await _deleteDraft();
          },
        ),
      ),
    );
  }

  Future<void> _openPreview() async {
    final current = _selected;
    if (current == null) return;
    await showSidePanel<void>(
      context,
      builder: (context) => SidePanel(
        title: 'Preview',
        scrollable: false,
        child: _PreviewPanel(
          definition: current,
          screenIndex: _screenIndex,
          sample: _sample,
          highlight: _openUid,
          onOpenScreen: (index) => setState(() {
            _screenIndex = index;
            _openUid = null;
          }),
        ),
      ),
    );
    if (mounted) setState(() {});
  }

  // ── Screens ─────────────────────────────────────────────────────────

  void _addScreen() {
    final current = _selected;
    if (current == null) return;
    if (current['screens'] is! List) current['screens'] = <dynamic>[];
    final screens = current['screens'] as List;
    screens.add(<String, dynamic>{
      uidKey: freshUid(),
      'name': 'Screen ${screens.length + 1}',
      'fields': <dynamic>[],
    });
    _screenIndex = screens.length - 1;
    _openUid = null;
    _onScreen = true;
    _changed();
  }

  void _openScreen(int index) {
    setState(() {
      _screenIndex = index;
      _openUid = null;
      _onScreen = true;
      _phonePreview = false;
      _screenWhenOpen = false;
      _askDeleteScreen = false;
    });
  }

  void _moveScreen(int from, int to) {
    final screens = _selected?['screens'];
    if (screens is! List || to < 0 || to >= screens.length || from == to) {
      return;
    }
    final chosen = _screen;
    screens.insert(to, screens.removeAt(from));
    _screenIndex = chosen == null ? to : screens.indexOf(chosen);
    _changed();
  }

  /// Every question on this screen that something elsewhere waits for.
  List<String> _screenDependents(Map<String, dynamic> screen) {
    final keys = <String>{};
    void add(Map<String, dynamic> field) {
      final key = '${field['key'] ?? ''}';
      if (key.isNotEmpty) keys.add(key);
      followUpsOf(field).forEach(add);
    }

    fieldsOf(screen).forEach(add);
    final out = <String>{};
    for (final key in keys) {
      for (final who in dependentsOf(_selected, key)) {
        out.add(who);
      }
    }
    // A question that waits for a neighbour on the same screen goes with it.
    final here = {for (final f in fieldsOf(screen)) '“${labelOf(f)}”'};
    return out.where((who) => !here.contains(who)).toList();
  }

  void _deleteScreen() {
    final screens = _selected?['screens'];
    final screen = _screen;
    if (screens is! List || screen == null) return;
    screens.remove(screen);
    _screenIndex = (_screenIndex - 1).clamp(
      0,
      screens.isEmpty ? 0 : screens.length - 1,
    );
    _askDeleteScreen = false;
    _openUid = null;
    _onScreen = false;
    _changed();
  }

  // ── Questions ───────────────────────────────────────────────────────

  List<dynamic>? get _fieldList {
    final screen = _screen;
    if (screen == null) return null;
    if (screen['fields'] is! List) screen['fields'] = <dynamic>[];
    return screen['fields'] as List;
  }

  void _addQuestion() {
    final fields = _fieldList;
    if (fields == null) return;
    final uid = freshUid();
    fields.add(<String, dynamic>{
      uidKey: uid,
      'key': nextKey(_selected),
      'label': '',
      'type': 'text',
      'required': false,
    });
    _openUid = uid;
    _focusUid = uid;
    _changed();
  }

  void _moveQuestion(int from, int to) {
    final fields = _fieldList;
    if (fields == null || to < 0 || to >= fields.length || from == to) return;
    fields.insert(to, fields.removeAt(from));
    _changed();
  }

  void _duplicate(Map<String, dynamic> field) {
    final fields = _fieldList;
    if (fields == null) return;
    final copy = duplicateOf(field, _selected);
    fields.insert(fields.indexOf(field) + 1, copy);
    _openUid = copy[uidKey] as String?;
    _changed();
  }

  Future<void> _moveToScreen(Map<String, dynamic> field) async {
    final screens = _screens;
    final picked = await showSearchPicker<int>(
      context,
      title: 'Move “${labelOf(field)}” to',
      options: [
        for (var i = 0; i < screens.length; i++)
          if (i != _screenIndex)
            SearchOption(
              value: i,
              label: '${i + 1} · ${screens[i]['name'] ?? 'Screen ${i + 1}'}',
            ),
      ],
    );
    if (picked == null || !mounted) return;
    _fieldList?.remove(field);
    final target = screens[picked];
    if (target['fields'] is! List) target['fields'] = <dynamic>[];
    (target['fields'] as List).add(field);
    _openUid = null;
    _changed();
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(
          'Moved to “${target['name'] ?? 'Screen ${picked + 1}'}”.',
        ),
      ),
    );
  }

  void _delete(Map<String, dynamic> field) {
    final fields = _fieldList;
    if (fields == null) return;
    final waiting = dependentsOf(_selected, '${field['key'] ?? ''}');
    if (waiting.isNotEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            '${waiting.join(', ')} ${waiting.length == 1 ? 'waits' : 'wait'} for '
            '“${labelOf(field)}”. Change ${waiting.length == 1 ? 'that' : 'those'} first.',
          ),
        ),
      );
      return;
    }
    final at = fields.indexOf(field);
    fields.removeAt(at);
    if (_openUid == field[uidKey]) _openUid = null;
    _changed();
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text('Deleted “${labelOf(field)}”.'),
        // An action makes a SnackBar persist by default (Flutter 3.35+); ours time out.
        persist: false,
        action: SnackBarAction(
          label: 'Undo',
          onPressed: () {
            if (!fields.contains(field)) {
              fields.insert(at.clamp(0, fields.length), field);
            }
            _changed();
          },
        ),
      ),
    );
  }

  // ── Building ────────────────────────────────────────────────────────

  @override
  Widget build(BuildContext context) {
    return AsyncBody(
      loading: _loading,
      error: _error,
      onRetry: _load,
      child: LayoutBuilder(
        builder: (context, box) {
          final current = _selected;
          if (current == null) return _none(context);

          if (box.maxWidth < BuilderTab.phoneBelow) {
            return Column(
              key: const Key('inspection-builder'),
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                _phoneBar(context, current),
                const Divider(height: 1),
                Expanded(
                  child: !_onScreen
                      ? _phoneScreens(context, current)
                      : Column(
                          crossAxisAlignment: CrossAxisAlignment.stretch,
                          children: [
                            _phoneBack(context),
                            const Divider(height: 1),
                            Expanded(
                              child: _phonePreview
                                  ? Padding(
                                      padding: const EdgeInsets.all(12),
                                      child: _livePhone(current),
                                    )
                                  : _canvas(context, current, narrow: true),
                            ),
                          ],
                        ),
                ),
              ],
            );
          }

          final beside = box.maxWidth >= BuilderTab.phoneBesideFrom;

          return Column(
            key: const Key('inspection-builder'),
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              _bar(context, current, previewButton: !beside),
              const Divider(height: 1),
              Expanded(
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    SizedBox(
                      key: const Key('inspection-builder-list'),
                      width: 232,
                      child: _rail(context, current),
                    ),
                    const VerticalDivider(width: 1),
                    Expanded(
                      child: KeyedSubtree(
                        key: const Key('inspection-builder-fields'),
                        child: _canvas(context, current, narrow: false),
                      ),
                    ),
                    if (beside) ...[
                      const VerticalDivider(width: 1),
                      SizedBox(
                        key: const Key('inspection-builder-phone'),
                        width: 340,
                        child: _side(context, current),
                      ),
                    ],
                  ],
                ),
              ),
            ],
          );
        },
      ),
    );
  }

  Widget _none(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.all(DashboardCard.padding),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const DashboardNote(
            'No inspections yet. Make one to decide the screens, the questions '
            'and what has to be photographed.',
          ),
          const SizedBox(height: 12),
          FilledButton.icon(
            key: const Key('inspection-new'),
            onPressed: _busy == 'create' ? null : _create,
            icon: const Icon(Icons.add),
            label: const Text('New inspection'),
          ),
        ],
      ),
    );
  }

  // ── The bar ─────────────────────────────────────────────────────────

  Widget _namePicker(BuildContext context, Map<String, dynamic> current) {
    final theme = Theme.of(context);
    final name = '${current['name'] ?? ''}'.trim();
    return InkWell(
      key: const Key('inspection-picker'),
      onTap: _pickInspection,
      borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 6),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Flexible(
              child: Text(
                name.isEmpty ? 'Untitled inspection' : name,
                overflow: TextOverflow.ellipsis,
                style: theme.textTheme.titleMedium?.copyWith(
                  fontWeight: FontWeight.w800,
                ),
              ),
            ),
            Icon(Icons.arrow_drop_down, color: theme.colorScheme.primary),
          ],
        ),
      ),
    );
  }

  Widget _savedText(BuildContext context, Map<String, dynamic> current) {
    final theme = Theme.of(context);
    final failed = _saveError != null;
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Flexible(
          child: Text(
            _savedLine(current),
            key: const Key('inspection-saved'),
            maxLines: 2,
            overflow: TextOverflow.ellipsis,
            style: theme.textTheme.bodySmall?.copyWith(
              color: failed ? ToneColours.of(context, Tone.bad).ink : null,
            ),
          ),
        ),
        if (failed)
          TextButton(
            onPressed: () {
              _saveError = null;
              _dirty = true;
              _queueSave();
              setState(() {});
            },
            child: const Text('Try again'),
          ),
      ],
    );
  }

  Widget _publishButton(Map<String, dynamic> current, {bool short = false}) {
    return FilledButton(
      key: const Key('inspection-publish'),
      onPressed: _busy == 'publish' ? null : _publish,
      child: Text(
        current['published'] == true && !short ? 'Publish changes' : 'Publish',
      ),
    );
  }

  Widget _bar(
    BuildContext context,
    Map<String, dynamic> current, {
    required bool previewButton,
  }) {
    final status = _status(current);
    return Padding(
      padding: const EdgeInsets.fromLTRB(12, 8, 16, 8),
      child: Row(
        children: [
          Expanded(
            child: Wrap(
              spacing: 10,
              runSpacing: 4,
              crossAxisAlignment: WrapCrossAlignment.center,
              children: [
                ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: 320),
                  child: _namePicker(context, current),
                ),
                StatusPill(status.$1, tone: status.$2),
                ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: 420),
                  child: _savedText(context, current),
                ),
              ],
            ),
          ),
          TextButton.icon(
            key: const Key('inspection-new'),
            onPressed: _busy == 'create' ? null : _create,
            icon: const Icon(Icons.add, size: 18),
            label: const Text('New inspection'),
          ),
          const SizedBox(width: 8),
          if (previewButton) ...[
            OutlinedButton(
              onPressed: _openPreview,
              child: const Text('Preview'),
            ),
            const SizedBox(width: 8),
          ],
          OutlinedButton(
            key: const Key('inspection-settings'),
            onPressed: _openSettings,
            child: const Text('Settings'),
          ),
          const SizedBox(width: 8),
          _publishButton(current),
        ],
      ),
    );
  }

  // ── The rail of screens ─────────────────────────────────────────────

  Widget _rail(BuildContext context, Map<String, dynamic> current) {
    final screens = _screens;
    final words = Vocabulary(current);
    final asked = askedOn(current);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(14, 12, 14, 4),
          child: Text(
            'SCREENS',
            style: Theme.of(context).textTheme.labelSmall?.copyWith(
              fontWeight: FontWeight.w800,
              letterSpacing: 0.6,
            ),
          ),
        ),
        Expanded(
          child: ReorderableListView.builder(
            buildDefaultDragHandles: false,
            padding: const EdgeInsets.symmetric(horizontal: 8),
            itemCount: screens.length,
            onReorder: (from, to) {
              // The index a drop reports is a GAP, so moving a row downwards
              // counts the gap it vacated.
              if (to > from) to -= 1;
              _moveScreen(from, to);
            },
            footer: Align(
              alignment: Alignment.centerLeft,
              child: TextButton.icon(
                key: const Key('screen-add'),
                onPressed: _addScreen,
                icon: const Icon(Icons.add, size: 18),
                label: const Text('Add a screen'),
              ),
            ),
            itemBuilder: (context, i) => _ScreenTile(
              key: ValueKey('screen:${screens[i][uidKey]}'),
              index: i,
              screen: screens[i],
              words: words,
              reach: conditionReach(screens[i]['visible_if'], asked, i),
              selected: i == _screenIndex.clamp(0, screens.length - 1),
              onTap: () => setState(() {
                _screenIndex = i;
                _openUid = null;
                _screenWhenOpen = false;
                _askDeleteScreen = false;
              }),
            ),
          ),
        ),
      ],
    );
  }

  // ── The chosen screen ───────────────────────────────────────────────

  Widget _canvas(
    BuildContext context,
    Map<String, dynamic> current, {
    required bool narrow,
  }) {
    final screen = _screen;
    if (screen == null) {
      return Padding(
        padding: const EdgeInsets.all(DashboardCard.padding),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const DashboardNote(
              'No screens yet. A screen is a page of questions the technician fills in.',
            ),
            const SizedBox(height: 12),
            FilledButton.icon(
              onPressed: _addScreen,
              icon: const Icon(Icons.add),
              label: const Text('Add a screen'),
            ),
          ],
        ),
      );
    }

    final fields = fieldsOf(screen);
    final words = Vocabulary(current);
    final asked = askedOn(current);
    final targets = targetsOf(current, upTo: _screenIndex);
    final kit = BuilderKit(
      definition: current,
      types: _types,
      codes: _codes,
      bindable: _bindable,
      narrow: narrow,
      onChanged: _changed,
    );

    return ReorderableListView.builder(
      buildDefaultDragHandles: false,
      padding: EdgeInsets.fromLTRB(narrow ? 12 : 18, 12, narrow ? 12 : 18, 24),
      header: _screenHeader(
        context,
        current,
        screen,
        words,
        asked,
        narrow: narrow,
      ),
      footer: Align(
        alignment: Alignment.centerLeft,
        child: Padding(
          padding: const EdgeInsets.only(top: 4),
          child: TextButton.icon(
            key: const Key('question-add'),
            onPressed: _addQuestion,
            icon: const Icon(Icons.add_circle_outline),
            label: const Text('Add a question'),
          ),
        ),
      ),
      itemCount: fields.length,
      onReorder: (from, to) {
        if (to > from) to -= 1;
        _moveQuestion(from, to);
      },
      itemBuilder: (context, i) {
        final field = fields[i];
        final uid = field[uidKey] as String?;
        final reach = questionReach(field['visible_if'], asked, _screenIndex);
        final open = uid != null && uid == _openUid;

        return Padding(
          key: ValueKey('row:$uid'),
          padding: const EdgeInsets.only(bottom: 8),
          child: open
              ? QuestionEditor(
                  key: ValueKey('open:$uid'),
                  field: field,
                  kit: kit,
                  words: words,
                  targets: targets,
                  reach: reach,
                  autofocus: _focusUid == uid,
                  dependents: dependentsOf(current, '${field['key'] ?? ''}'),
                  onClose: () => setState(() {
                    _openUid = null;
                    _focusUid = null;
                  }),
                  onDuplicate: () => _duplicate(field),
                  onMove: _screens.length > 1
                      ? () => _moveToScreen(field)
                      : null,
                  onDelete: () => _delete(field),
                )
              : QuestionCard(
                  field: field,
                  kit: kit,
                  words: words,
                  reach: reach,
                  index: i,
                  onOpen: () => setState(() {
                    _openUid = uid;
                    _focusUid = null;
                  }),
                  menu: _questionMenu(field, i, fields.length),
                ),
        );
      },
    );
  }

  Widget _questionMenu(Map<String, dynamic> field, int index, int count) {
    return PopupMenuButton<String>(
      key: ValueKey('question-menu:${field[uidKey]}'),
      tooltip: 'More for “${labelOf(field)}”',
      onSelected: (action) => switch (action) {
        'up' => _moveQuestion(index, index - 1),
        'down' => _moveQuestion(index, index + 1),
        'duplicate' => _duplicate(field),
        'move' => _moveToScreen(field),
        _ => _delete(field),
      },
      itemBuilder: (context) => [
        if (index > 0) const PopupMenuItem(value: 'up', child: Text('Move up')),
        if (index < count - 1)
          const PopupMenuItem(value: 'down', child: Text('Move down')),
        const PopupMenuItem(value: 'duplicate', child: Text('Duplicate')),
        if (_screens.length > 1)
          const PopupMenuItem(
            value: 'move',
            child: Text('Move to another screen'),
          ),
        const PopupMenuItem(value: 'delete', child: Text('Delete')),
      ],
    );
  }

  Widget _screenHeader(
    BuildContext context,
    Map<String, dynamic> current,
    Map<String, dynamic> screen,
    Vocabulary words,
    Map<String, int> asked, {
    required bool narrow,
  }) {
    final theme = Theme.of(context);
    final screens = _screens;
    final index = _screenIndex.clamp(0, screens.length - 1);
    final sentence = conditionSentence(screen['visible_if'], words);
    final reach = conditionReach(screen['visible_if'], asked, index);
    final bad = ToneColours.of(context, Tone.bad);

    return Padding(
      key: ValueKey('screen-header:${screen[uidKey]}'),
      padding: const EdgeInsets.only(bottom: 10),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              if (!narrow) ...[
                _Number(index + 1, on: true),
                const SizedBox(width: 10),
              ],
              Expanded(
                child: _ScreenName(
                  key: ValueKey('screen-name:${screen[uidKey]}'),
                  initial: '${screen['name'] ?? ''}',
                  onChanged: (text) {
                    screen['name'] = text;
                    _changed();
                  },
                ),
              ),
              PopupMenuButton<String>(
                key: const Key('screen-menu'),
                tooltip: 'More for this screen',
                onSelected: (action) => switch (action) {
                  'up' => _moveScreen(index, index - 1),
                  'down' => _moveScreen(index, index + 1),
                  _ => setState(() => _askDeleteScreen = true),
                },
                itemBuilder: (context) => [
                  if (index > 0)
                    const PopupMenuItem(
                      value: 'up',
                      child: Text('Move screen up'),
                    ),
                  if (index < screens.length - 1)
                    const PopupMenuItem(
                      value: 'down',
                      child: Text('Move screen down'),
                    ),
                  const PopupMenuItem(
                    value: 'delete',
                    child: Text('Delete screen'),
                  ),
                ],
              ),
            ],
          ),
          const SizedBox(height: 4),
          Wrap(
            spacing: 8,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              Text(
                reach == ConditionReach.never
                    ? 'Never shown: it waits for an answer given on a later screen.'
                    : sentence.isEmpty
                    ? 'Shown to every technician'
                    : 'Only when $sentence',
                key: const Key('screen-when'),
                style: theme.textTheme.bodySmall?.copyWith(
                  color: reach == ConditionReach.never ? bad.ink : null,
                ),
              ),
              TextButton(
                key: const Key('screen-when-change'),
                onPressed: () =>
                    setState(() => _screenWhenOpen = !_screenWhenOpen),
                child: Text(
                  _screenWhenOpen
                      ? 'Done'
                      : sentence.isEmpty
                      ? 'Show it only when…'
                      : 'Change',
                ),
              ),
            ],
          ),
          if (_screenWhenOpen)
            Container(
              padding: const EdgeInsets.all(10),
              decoration: BoxDecoration(
                border: Border.all(color: EzBiziTheme.edge(context)),
                borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
              ),
              child: ConditionSentence(
                condition: screen['visible_if'],
                // A screen can only wait for answers already given: the
                // screens before it.
                targets: index == 0
                    ? const []
                    : targetsOf(current, upTo: index - 1),
                words: words,
                always: 'Shown to every technician',
                add: 'Show it only when…',
                onChanged: (value) {
                  if (value == null) {
                    screen.remove('visible_if');
                  } else {
                    screen['visible_if'] = value;
                  }
                  _changed();
                },
              ),
            ),
          if (_askDeleteScreen) _deleteScreenAsk(context, screen),
          if (fieldsOf(screen).isEmpty)
            Padding(
              padding: const EdgeInsets.only(top: 12),
              child: Text(
                'Nothing is asked on this screen yet.',
                style: theme.textTheme.bodyMedium,
              ),
            ),
        ],
      ),
    );
  }

  Widget _deleteScreenAsk(BuildContext context, Map<String, dynamic> screen) {
    final bad = ToneColours.of(context, Tone.bad);
    final waiting = _screenDependents(screen);
    final count = questionCount(screen);
    final name = '${screen['name'] ?? ''}'.trim();

    return Container(
      key: const Key('screen-delete-ask'),
      margin: const EdgeInsets.only(top: 8),
      padding: const EdgeInsets.all(10),
      decoration: BoxDecoration(
        color: bad.fill,
        borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
      ),
      child: Wrap(
        spacing: 8,
        runSpacing: 6,
        crossAxisAlignment: WrapCrossAlignment.center,
        children: [
          Text(
            waiting.isNotEmpty
                ? '${waiting.join(', ')} ${waiting.length == 1 ? 'waits' : 'wait'} for '
                      'answers on this screen. Change ${waiting.length == 1 ? 'that' : 'those'} first.'
                : count == 0
                ? 'Delete “$name”?'
                : 'Delete “$name” and its ${count == 1 ? 'question' : '$count questions'}?',
            style: TextStyle(color: bad.ink),
          ),
          if (waiting.isEmpty)
            FilledButton(
              key: const Key('screen-delete-yes'),
              style: FilledButton.styleFrom(
                backgroundColor: Theme.of(context).colorScheme.error,
                foregroundColor: Theme.of(context).colorScheme.onError,
              ),
              onPressed: _deleteScreen,
              child: const Text('Delete'),
            ),
          TextButton(
            onPressed: () => setState(() => _askDeleteScreen = false),
            child: Text(waiting.isEmpty ? 'Keep' : 'OK'),
          ),
        ],
      ),
    );
  }

  // ── The phone or the Map ────────────────────────────────────────────

  Widget _livePhone(Map<String, dynamic> current) => LivePhone(
    definition: current,
    screenIndex: _screenIndex,
    answers: _sample,
    highlight: _openUid,
    onAnswer: (key, value) => setState(() {
      if (value == null) {
        _sample.remove(key);
      } else {
        _sample[key] = value;
      }
    }),
  );

  Widget _side(BuildContext context, Map<String, dynamic> current) {
    return Padding(
      padding: const EdgeInsets.all(12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          _MapSwitch(
            showMap: _showMap,
            onChanged: (map) => setState(() => _showMap = map),
          ),
          const SizedBox(height: 8),
          if (!_showMap)
            Text(
              'Live, with sample answers. Tap an answer to see what it asks next.',
              textAlign: TextAlign.center,
              style: Theme.of(context).textTheme.bodySmall,
            ),
          const SizedBox(height: 8),
          Expanded(
            child: _showMap
                ? InspectionFlowPane(
                    definition: current,
                    // The node id is a POSITION in the screen list, read at the
                    // moment of the tap and not held: reordering screens moves
                    // every id after the one that moved.
                    onOpenScreen: (index) => setState(() {
                      _screenIndex = index;
                      _openUid = null;
                    }),
                  )
                : _livePhone(current),
          ),
        ],
      ),
    );
  }

  // ── The phone itself (option 4) ─────────────────────────────────────

  Widget _phoneBar(BuildContext context, Map<String, dynamic> current) {
    final status = _status(current);
    return Padding(
      padding: const EdgeInsets.fromLTRB(8, 6, 8, 6),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Expanded(child: _namePicker(context, current)),
              IconButton(
                key: const Key('inspection-settings'),
                tooltip: 'Settings',
                onPressed: _openSettings,
                icon: const Icon(Icons.tune),
              ),
              _publishButton(current, short: true),
            ],
          ),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 6),
            child: Wrap(
              spacing: 8,
              runSpacing: 4,
              crossAxisAlignment: WrapCrossAlignment.center,
              children: [
                StatusPill(status.$1, tone: status.$2),
                _savedText(context, current),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _phoneScreens(BuildContext context, Map<String, dynamic> current) {
    final screens = _screens;
    final words = Vocabulary(current);
    final asked = askedOn(current);

    return ListView(
      padding: const EdgeInsets.all(12),
      children: [
        for (var i = 0; i < screens.length; i++)
          Padding(
            padding: const EdgeInsets.only(bottom: 8),
            child: Card(
              margin: EdgeInsets.zero,
              child: _ScreenTile(
                key: ValueKey('screen:${screens[i][uidKey]}'),
                index: i,
                screen: screens[i],
                words: words,
                reach: conditionReach(screens[i]['visible_if'], asked, i),
                selected: false,
                draggable: false,
                trailing: const Icon(Icons.chevron_right),
                onTap: () => _openScreen(i),
              ),
            ),
          ),
        Align(
          alignment: Alignment.centerLeft,
          child: TextButton.icon(
            key: const Key('screen-add'),
            onPressed: _addScreen,
            icon: const Icon(Icons.add, size: 18),
            label: const Text('Add a screen'),
          ),
        ),
      ],
    );
  }

  Widget _phoneBack(BuildContext context) {
    final theme = Theme.of(context);
    final screens = _screens;
    final index = _screenIndex.clamp(
      0,
      screens.isEmpty ? 0 : screens.length - 1,
    );

    // The screen's name is the title just below, where it is renamed, so this
    // row is only the way back, where it sits, and the preview.
    return Row(
      children: [
        Tooltip(
          message: 'Back to the screens',
          child: TextButton.icon(
            onPressed: () => setState(() {
              _onScreen = false;
              _openUid = null;
              _phonePreview = false;
            }),
            icon: const Icon(Icons.arrow_back),
            label: const Text('Screens'),
          ),
        ),
        const Spacer(),
        Text(
          '${index + 1} of ${screens.length}',
          style: theme.textTheme.bodySmall,
        ),
        TextButton(
          key: const Key('phone-preview'),
          onPressed: () => setState(() => _phonePreview = !_phonePreview),
          child: Text(_phonePreview ? 'Edit' : 'Preview'),
        ),
      ],
    );
  }
}

/// Phone or Map, for the right-hand pane.
///
/// Two chips rather than a SegmentedButton: the pane is laid out with
/// intrinsic heights in places, and a SegmentedButton cannot answer them.
class _MapSwitch extends StatelessWidget {
  const _MapSwitch({required this.showMap, required this.onChanged});

  final bool showMap;
  final ValueChanged<bool> onChanged;

  @override
  Widget build(BuildContext context) {
    return Row(
      key: const Key('inspection-builder-preview-switch'),
      mainAxisAlignment: MainAxisAlignment.center,
      children: [
        ChoiceChip(
          avatar: const Icon(Icons.phone_iphone, size: 16),
          label: const Text('Phone'),
          selected: !showMap,
          showCheckmark: false,
          onSelected: (_) => onChanged(false),
        ),
        const SizedBox(width: 8),
        ChoiceChip(
          avatar: const Icon(Icons.account_tree_outlined, size: 16),
          label: const Text('Map'),
          selected: showMap,
          showCheckmark: false,
          onSelected: (_) => onChanged(true),
        ),
      ],
    );
  }
}

class _Number extends StatelessWidget {
  const _Number(this.n, {this.on = false});

  final int n;
  final bool on;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final neutral = ToneColours.of(context, Tone.neutral);
    return Container(
      width: 26,
      height: 26,
      alignment: Alignment.center,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        color: on ? theme.colorScheme.primary : neutral.fill,
      ),
      child: Text(
        '$n',
        style: theme.textTheme.labelMedium?.copyWith(
          fontWeight: FontWeight.w800,
          color: on ? theme.colorScheme.onPrimary : neutral.ink,
        ),
      ),
    );
  }
}

/// One screen in the rail: its number, its name, and when it appears.
class _ScreenTile extends StatelessWidget {
  const _ScreenTile({
    super.key,
    required this.index,
    required this.screen,
    required this.words,
    required this.reach,
    required this.selected,
    required this.onTap,
    this.draggable = true,
    this.trailing,
  });

  final int index;
  final Map<String, dynamic> screen;
  final Vocabulary words;
  final ConditionReach reach;
  final bool selected;
  final VoidCallback onTap;
  final bool draggable;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final name = '${screen['name'] ?? ''}'.trim();
    final sentence = conditionSentence(screen['visible_if'], words);
    final count = questionCount(screen);
    final never = reach == ConditionReach.never;

    final detail = never
        ? 'Never shown: waits for a later answer'
        : sentence.isNotEmpty
        ? 'Only when $sentence'
        : count == 0
        ? 'No questions yet'
        : count == 1
        ? '1 question'
        : '$count questions';

    return Material(
      color: selected ? theme.colorScheme.primaryContainer : Colors.transparent,
      borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
        child: Padding(
          padding: const EdgeInsets.symmetric(vertical: 8, horizontal: 4),
          child: Row(
            children: [
              if (draggable)
                ReorderableDragStartListener(
                  index: index,
                  child: MouseRegion(
                    cursor: SystemMouseCursors.grab,
                    child: Padding(
                      padding: const EdgeInsets.symmetric(horizontal: 4),
                      child: Icon(
                        Icons.drag_indicator,
                        size: 16,
                        color: theme.colorScheme.onSurfaceVariant,
                      ),
                    ),
                  ),
                )
              else
                const SizedBox(width: 6),
              _Number(index + 1, on: selected),
              const SizedBox(width: 10),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      name.isEmpty ? 'Screen ${index + 1}' : name,
                      overflow: TextOverflow.ellipsis,
                      style: theme.textTheme.bodyMedium?.copyWith(
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                    Text(
                      detail,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: theme.textTheme.bodySmall?.copyWith(
                        color: never
                            ? ToneColours.of(context, Tone.bad).ink
                            : null,
                      ),
                    ),
                  ],
                ),
              ),
              ?trailing,
            ],
          ),
        ),
      ),
    );
  }
}

/// The screen's name, edited where it is read: a title until it is clicked.
class _ScreenName extends StatefulWidget {
  const _ScreenName({
    super.key,
    required this.initial,
    required this.onChanged,
  });

  final String initial;
  final ValueChanged<String> onChanged;

  @override
  State<_ScreenName> createState() => _ScreenNameState();
}

class _ScreenNameState extends State<_ScreenName> {
  late final _controller = TextEditingController(text: widget.initial);

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final quiet = OutlineInputBorder(
      borderSide: const BorderSide(color: Colors.transparent),
      borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
    );
    return Tooltip(
      message: 'Rename this screen',
      child: TextField(
        key: const Key('screen-name'),
        controller: _controller,
        style: theme.textTheme.titleLarge?.copyWith(
          fontWeight: FontWeight.w800,
        ),
        decoration: InputDecoration(
          isDense: true,
          filled: false,
          hintText: 'Name this screen',
          contentPadding: const EdgeInsets.symmetric(
            horizontal: 8,
            vertical: 6,
          ),
          enabledBorder: quiet,
          border: quiet,
          focusedBorder: OutlineInputBorder(
            borderSide: BorderSide(color: theme.colorScheme.primary),
            borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
          ),
        ),
        onChanged: widget.onChanged,
      ),
    );
  }
}

/// The inspection's own settings, out of the way of its questions: its name,
/// who it belongs to, the line it opens with, and turning it off.
///
/// The old Builder's "Look" is not here. Nothing reads `layout_preset`: the
/// run and the report draw every inspection the same way whichever of the
/// three was picked, so offering the choice was a control that did nothing.
/// The stored value is left as it is.
class _Settings extends StatefulWidget {
  const _Settings({
    required this.definition,
    required this.scopes,
    required this.scopeValue,
    required this.published,
    required this.deletable,
    required this.onChanged,
    required this.onUnpublish,
    required this.onDelete,
  });

  final Map<String, dynamic> definition;
  final List<Map<String, dynamic>> scopes;
  final String? scopeValue;
  final bool published;
  final bool deletable;
  final VoidCallback onChanged;
  final Future<void> Function() onUnpublish;
  final Future<void> Function() onDelete;

  @override
  State<_Settings> createState() => _SettingsState();
}

class _SettingsState extends State<_Settings> {
  late String? _scope = widget.scopeValue;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final d = widget.definition;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        BoundText(
          key: const Key('inspection-name'),
          initial: '${d['name'] ?? ''}',
          label: 'Name',
          helper: 'What technicians pick from in New Inspection.',
          onChanged: (text) {
            d['name'] = text;
            widget.onChanged();
          },
        ),
        const SizedBox(height: 16),
        if (widget.scopes.isNotEmpty) ...[
          SearchPickerField<String>(
            key: const Key('inspection-scope-picker'),
            value: _scope,
            label: 'Belongs to',
            helperText:
                'Who this inspection exists for. Roles still decide who may run it.',
            options: [
              for (final scope in widget.scopes)
                SearchOption(
                  value:
                      '${scope['type'] ?? 'organization'}:${scope['id'] ?? ''}',
                  label: '${scope['label'] ?? ''}',
                ),
            ],
            onChanged: (value) {
              if (value == null) return;
              final split = value.indexOf(':');
              final type = value.substring(0, split);
              final id = value.substring(split + 1);
              d['scope'] = {
                'type': type == 'organization' ? null : type,
                'id': id.isEmpty ? null : id,
              };
              setState(() => _scope = value);
              widget.onChanged();
            },
          ),
          const SizedBox(height: 16),
        ],
        BoundText(
          key: const Key('inspection-intro'),
          initial: '${d['intro'] ?? ''}',
          label: 'Intro',
          helper: 'Shown when a technician starts it. Optional.',
          minLines: 2,
          maxLines: 5,
          onChanged: (text) {
            d['intro'] = text;
            widget.onChanged();
          },
        ),
        const SizedBox(height: 24),
        if (widget.published) ...[
          Text(
            'Turning it off takes it out of New Inspection. Reports already filed are kept.',
            style: theme.textTheme.bodySmall,
          ),
          const SizedBox(height: 8),
          Align(
            alignment: Alignment.centerLeft,
            child: OutlinedButton(
              onPressed: widget.onUnpublish,
              child: const Text('Unpublish'),
            ),
          ),
        ],
        if (widget.deletable) ...[
          Text(
            'It has never been published, so nothing depends on it yet.',
            style: theme.textTheme.bodySmall,
          ),
          const SizedBox(height: 8),
          Align(
            alignment: Alignment.centerLeft,
            child: TextButton(
              style: TextButton.styleFrom(
                foregroundColor: ToneColours.of(context, Tone.bad).ink,
              ),
              onPressed: widget.onDelete,
              child: const Text('Delete draft'),
            ),
          ),
        ],
      ],
    );
  }
}

/// The phone and the Map in a side panel, for a window too narrow to keep
/// them beside the questions. Its own state, because a panel is its own route
/// and does not redraw when the Builder does.
class _PreviewPanel extends StatefulWidget {
  const _PreviewPanel({
    required this.definition,
    required this.screenIndex,
    required this.sample,
    required this.highlight,
    required this.onOpenScreen,
  });

  final Map<String, dynamic> definition;
  final int screenIndex;
  final Map<String, dynamic> sample;
  final String? highlight;
  final ValueChanged<int> onOpenScreen;

  @override
  State<_PreviewPanel> createState() => _PreviewPanelState();
}

class _PreviewPanelState extends State<_PreviewPanel> {
  bool _map = false;
  late int _screen = widget.screenIndex;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _MapSwitch(
          showMap: _map,
          onChanged: (map) => setState(() => _map = map),
        ),
        const SizedBox(height: 12),
        Expanded(
          child: _map
              ? InspectionFlowPane(
                  definition: widget.definition,
                  onOpenScreen: (index) {
                    widget.onOpenScreen(index);
                    setState(() {
                      _screen = index;
                      _map = false;
                    });
                  },
                )
              : LivePhone(
                  definition: widget.definition,
                  screenIndex: _screen,
                  answers: widget.sample,
                  highlight: widget.highlight,
                  onAnswer: (key, value) => setState(() {
                    if (value == null) {
                      widget.sample.remove(key);
                    } else {
                      widget.sample[key] = value;
                    }
                  }),
                ),
        ),
      ],
    );
  }
}
