import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../widgets/async_body.dart';
import '../../widgets/error_note.dart';
import '../../widgets/tag.dart';
import 'test_builder.dart';

/// The guide builder — layout 8 of `docs/previews/training-layouts.html`
/// (Jose's approved pick, by number): a STORYBOARD, the one pattern in this
/// app that is not a list beside its editor.
///
/// ── Why a storyboard, and why it stays confined to this one screen ────
///
/// Every other module edits a list of things. A guide is a SEQUENCE, and a
/// sequence has a shape a list cannot show — screens as a horizontal strip
/// of thumbnails, so the author sees the guide's own shape (how much of it
/// is text, where the one video sits) rather than a column of titles. That
/// is genuinely different from the 25/75 list/pane split Inventory, Fleet
/// and this same module's own Dashboard tab use, and it is deliberate — see
/// the rejected layout 7 in the same mockup file, "list beside the editor",
/// which drew the screens as an ordinary vertical rail instead and lost the
/// shape entirely. It does not become a second house convention: nothing
/// outside this file and test_builder.dart draws a strip.
///
/// ── The strip scrolls; the page does not ───────────────────────────────
///
/// [_ScreenStrip] is the ONLY horizontally-scrolling thing on this screen —
/// a bounded-height `ListView(scrollDirection: Axis.horizontal)` — and
/// everything below it (the screen editor and, beside or under it, the
/// guide's own test) sits in the page's ordinary VERTICAL scroll instead.
/// Proven in `training_builder_test.dart`'s 375px test, and by the RED/GREEN
/// swap recorded in the task-15 report: replacing the strip's `ListView`
/// with a bare `Row` turns that same test red with an overflow, because a
/// `Row` never scrolls and never clips — it just keeps going off the edge of
/// a 375px screen.
///
/// ── Reusing the test editor rather than re-deriving it ──────────────────
///
/// [TestEditorPanel] (test_builder.dart) is a CONTROLLED widget: it takes
/// already-loaded test data and never fetches its own copy. That is what
/// lets [_load] here fetch the guide's own test ONCE, as part of the SAME
/// load that fetches the guide, and hand the result straight to the panel —
/// no second spinner nested inside this one's own `AsyncBody`, and no second
/// copy of the five options' editing logic to drift from `TestBuilder`'s.
///
/// ── Reorder without a mouse ──────────────────────────────────────────────
///
/// A guide is a SEQUENCE (this file's own first section) — without a way to
/// reorder its screens, the strip is a list wearing a sequence's clothes.
/// The mockup's own shelf label ("8 · drag to reorder") calls for a drag
/// gesture, but §4/the house rule this whole module already follows is that
/// nothing may assume a mouse, hover, or a wide viewport — a drag needs one
/// of the first two. [_ScreenThumbCard] instead draws a move-left and a
/// move-right button on every card ([_GuideBuilderState._moveScreen]):
/// simpler than drag-and-drop, works at 375px with a plain tap, and reaches
/// the exact same end (`GuidesController::normalizeScreens` rebuilds the
/// stored order from the `screens` array on save either way). The shelf
/// label is worded for what actually ships rather than left claiming drag.
class GuideBuilder extends StatefulWidget {
  const GuideBuilder({
    super.key,
    required this.api,
    required this.session,
    required this.guideId,
    this.onLeave,
  });

  final ApiClient api;
  final Session session;
  final String guideId;
  final VoidCallback? onLeave;

  /// Below this the two-column split (screen editor beside "Its test")
  /// collapses to a single stacked column — the brief's own "at 375px …
  /// the editors stack". Wide enough that each column still has room for a
  /// question's choices without wrapping every word.
  static const double splitAbove = 720;

  @override
  State<GuideBuilder> createState() => _GuideBuilderState();
}

class _GuideBuilderState extends State<GuideBuilder> {
  bool _loading = true;
  String? _error;

  late TextEditingController _title;
  /// Null until the guide has loaded: a published guide read "Draft — nobody
  /// sees it yet" with a Publish button for five seconds (phone audit
  /// 2026-11-04, Section 3 #14).
  String? _status;
  List<Map<String, dynamic>> _screens = [];
  int? _selected;

  /// The test whose own `guide_id` names this guide, if any — found by
  /// scanning the SAME authoring list `TestBuilder` itself reads
  /// (`GET /training/tests`), never a second hand-rolled join. A guide may
  /// have no test at all; that is an ordinary state, not an error.
  Map<String, dynamic>? _testEntry;

  /// True when [_testEntry] is null NOT because this guide truly has no
  /// test, but because the scan that looks for one stopped at 200 results
  /// (`GET /training/tests?per_page=200`) before covering every test in the
  /// organization. Past that count the scan is not exhaustive, so "no test
  /// is attached" would be a guess dressed as a fact — see [_NoTestNote]'s
  /// own two sentences for the honest version of each case.
  bool _testSearchIncomplete = false;

  /// This guide's own scope label — the franchise or company it is filed
  /// under, or "Organization" when it is filed at neither (the same
  /// resolution `learn_shelves.dart`'s own `_scopeLabel` does, duplicated
  /// here rather than shared: each screen in this module already keeps its
  /// own small private helpers, e.g. `_mapList` below and in
  /// test_builder.dart). Shown beside the screen strip's own count — the
  /// mockup's own scope tag (`docs/previews/training-layouts.html`, layout
  /// 8's shelf), dropped when this screen was first built.
  String _scope = 'Organization';

  bool _saving = false;
  String? _saveError;

  /// A per-screen CLIENT-SIDE id, present on every map in [_screens] under
  /// `_localId` and never sent to the server (`_save` builds a fresh map
  /// with only the four server-known fields per screen). See
  /// `_TestEditorPanelState._newLocalId`'s own docblock (test_builder.dart)
  /// for why [_ScreenEditor] below is keyed by this rather than [_selected]:
  /// the same `TextFormField`/`TextEditingController` reuse bug, here in the
  /// title/body/media-url fields of whichever screen currently sits at the
  /// selected list position.
  int _nextLocalId = 0;

  String _newLocalId() => 'local-${_nextLocalId++}';

  @override
  void initState() {
    super.initState();
    _title = TextEditingController();
    _load();
  }

  @override
  void dispose() {
    _title.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = _screens.isEmpty && _testEntry == null;
      _error = null;
    });

    final response = await widget.api.get('/training/guides/${widget.guideId}');
    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _loading = false;
        _error = response.message;
      });
      return;
    }

    final guide = Map<String, dynamic>.from(response.body['data'] as Map);

    final testsResponse = await widget.api.get('/training/tests', {'per_page': '200'});
    if (!mounted) return;

    Map<String, dynamic>? test;
    var searchIncomplete = false;
    if (testsResponse.ok) {
      final tests = _mapList(testsResponse.body['data']);
      test = tests.where((t) => t['guide_id'] == widget.guideId).firstOrNull;
      // A page cap of 200 is exhaustive for almost every organization, but
      // not a promise — `meta.total` says whether this page was really
      // every test. See [_testSearchIncomplete]'s own docblock.
      final total = (testsResponse.body['meta'] as Map?)?['total'];
      if (test == null && total is num && total > tests.length) {
        searchIncomplete = true;
      }
    }

    setState(() {
      _loading = false;
      _title.text = guide['title'] as String? ?? '';
      _status = guide['status'] as String? ?? 'draft';
      _scope = _scopeLabel(widget.session.me, guide);
      _screens = (guide['screens'] as List? ?? const [])
          .whereType<Map>()
          .map((s) => {'_localId': _newLocalId(), ...Map<String, dynamic>.from(s), 'blocks': _blocksOf(s)})
          .toList();
      _selected = _screens.isEmpty ? null : 0;
      _testEntry = test;
      _testSearchIncomplete = searchIncomplete;
    });
  }

  void _addScreen() {
    setState(() {
      _screens = [
        ..._screens,
        {
          '_localId': _newLocalId(),
          'title': 'New topic',
          'chapter': null,
          'section': null,
          'blocks': [
            {'_key': _newBlockKey(), 'type': 'text', 'text': ''},
          ],
        },
      ];
      // Focuses the new screen's editor immediately — no dialog opens; the
      // strip gains a card and the editor below swaps to it in the same
      // frame (§4/house rule: no modals, proven in
      // training_builder_test.dart's "never opens a dialog").
      _selected = _screens.length - 1;
    });
  }

  /// Swaps the screen at [index] with the one [direction] away (±1 — the
  /// only distances the move-left/move-right controls ever call this with).
  /// A plain list splice and a later [_save], exactly like every other edit
  /// in this file: `GuidesController::normalizeScreens` rebuilds the stored
  /// `screens` column from array order on every save, so there is no
  /// separate reorder endpoint to call.
  ///
  /// Selection follows the ITEM, not the slot: if the screen that moved was
  /// selected, [_selected] moves with it, and if it swapped places with the
  /// selected screen, [_selected] swaps too — so the editor below never
  /// jumps to a different screen than the one the author was just looking
  /// at.
  void _moveScreen(int index, int direction) {
    final target = index + direction;
    if (target < 0 || target >= _screens.length) return;

    setState(() {
      final reordered = [..._screens];
      final moved = reordered.removeAt(index);
      reordered.insert(target, moved);
      _screens = reordered;

      if (_selected == index) {
        _selected = target;
      } else if (_selected == target) {
        _selected = index;
      }
    });
  }

  void _removeScreen(int index) {
    setState(() {
      _screens = [..._screens]..removeAt(index);
      if (_screens.isEmpty) {
        _selected = null;
      } else if (_selected != null && _selected! >= _screens.length) {
        _selected = _screens.length - 1;
      }
    });
  }

  void _updateScreen(int index, Map<String, dynamic> patch) {
    setState(() {
      _screens = [
        for (var i = 0; i < _screens.length; i++)
          if (i == index) {..._screens[i], ...patch} else _screens[i],
      ];
    });
  }

  Future<void> _save() async {
    setState(() {
      _saving = true;
      _saveError = null;
    });

    final response = await widget.api.patch('/training/guides/${widget.guideId}', {
      'title': _title.text,
      'screens': [
        for (final s in _screens)
          {
            'title': s['title'],
            'chapter': s['chapter'],
            'section': s['section'],
            'blocks': [for (final b in (s['blocks'] as List? ?? const [])) _blockForSave(b as Map<String, dynamic>)],
          },
      ],
    });
    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _saving = false;
        // The server's own sentence — a bad `media_url` scheme, in
        // particular (`GuidesController::rules`) — never this client's own
        // wording. See test_builder.dart's docblock for the same rule.
        _saveError = response.reason;
      });
      return;
    }

    setState(() => _saving = false);
  }

  Future<void> _togglePublish() async {
    final next = _status == 'published' ? 'draft' : 'published';
    final response = await widget.api.patch('/training/guides/${widget.guideId}', {'status': next});
    if (!mounted || !response.ok) return;
    setState(() => _status = next);
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Material(
          color: theme.colorScheme.surface,
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
            // A `Row` of Leave + title + pill + two buttons overflowed at
            // 375px — the exact class of bug this task's own 375px test
            // (`training_builder_test.dart`) exists to catch, just in the
            // HEADER rather than the strip. A `Wrap`, every control its own
            // child (never grouped inside a nested `Row`, which Wrap cannot
            // itself shrink), moves controls to a second line instead of
            // overflowing, which is what a phone gets here.
            child: Wrap(
              crossAxisAlignment: WrapCrossAlignment.center,
              spacing: 8,
              runSpacing: 8,
              children: [
                TextButton.icon(
                  onPressed: widget.onLeave ?? () => Navigator.maybePop(context),
                  icon: const Icon(Icons.arrow_back, size: 18),
                  label: const Text('All training'),
                ),
                ConstrainedBox(
                  constraints: const BoxConstraints(minWidth: 80, maxWidth: 200),
                  child: TextField(
                    controller: _title,
                    style: theme.textTheme.titleMedium,
                    decoration: const InputDecoration(isDense: true, border: InputBorder.none),
                  ),
                ),
                if (_status != null) ...[
                  Tag(_status == 'published' ? 'Published' : 'Draft — nobody sees it yet'),
                  OutlinedButton(
                    onPressed: _togglePublish,
                    child: Text(_status == 'published' ? 'Unpublish' : 'Publish'),
                  ),
                ],
                ElevatedButton(
                  key: const Key('training.save-guide'),
                  onPressed: _saving ? null : _save,
                  child: Text(_saving ? 'Saving…' : 'Save'),
                ),
              ],
            ),
          ),
        ),
        Divider(height: 1, color: EzBiziTheme.edge(context)),
        Expanded(
          child: AsyncBody(
            loading: _loading,
            error: _error,
            onRetry: _load,
            child: SingleChildScrollView(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  if (_saveError != null) ...[ErrorNote(_saveError!), const SizedBox(height: 12)],
                  Row(
                    children: [
                      Text('Screens', style: theme.textTheme.titleSmall),
                      const SizedBox(width: 8),
                      Expanded(
                        // "N · what the arrows on each card do" — the
                        // mockup's own "8 · drag to reorder"
                        // (training-layouts.html, layout 8's shelf), worded
                        // for what actually ships: move-left/move-right
                        // controls, not a drag gesture (this file's own
                        // docblock, "Reorder without a mouse").
                        child: Text(
                          '${_screens.length} · move a screen with the arrows on its card',
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: theme.textTheme.bodySmall?.copyWith(
                            color: theme.colorScheme.onSurfaceVariant,
                          ),
                        ),
                      ),
                      Tag(_scope),
                    ],
                  ),
                  const SizedBox(height: 8),
                  _ScreenStrip(
                    screens: _screens,
                    selected: _selected,
                    onSelect: (i) => setState(() => _selected = i),
                    onAdd: _addScreen,
                    onMove: _moveScreen,
                  ),
                  const SizedBox(height: 16),
                  LayoutBuilder(
                    builder: (context, constraints) {
                      final wide = constraints.maxWidth >= GuideBuilder.splitAbove;

                      final screenEditor = KeyedSubtree(
                        key: const Key('training.screen-editor'),
                        child: _selected == null
                            ? const _NoScreenNote()
                            : _ScreenEditor(
                                key: ValueKey(
                                  'training.screen-editor-${_screens[_selected!]['_localId']}',
                                ),
                                screen: _screens[_selected!],
                                onChanged: (patch) => _updateScreen(_selected!, patch),
                                onDelete: () => _removeScreen(_selected!),
                              ),
                      );

                      final testColumn = Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Row(
                            children: [
                              Text('Its test', style: theme.textTheme.titleSmall),
                              if (_testEntry != null) ...[
                                const SizedBox(width: 8),
                                Text(
                                  '${(_testEntry!['questions'] as List? ?? const []).length} questions',
                                  style: theme.textTheme.bodySmall?.copyWith(
                                    color: theme.colorScheme.onSurfaceVariant,
                                  ),
                                ),
                              ],
                            ],
                          ),
                          const SizedBox(height: 8),
                          if (_testEntry == null)
                            _NoTestNote(searchIncomplete: _testSearchIncomplete)
                          else
                            TestEditorPanel(
                              api: widget.api,
                              testId: _testEntry!['id'] as String,
                              test: _testEntry!,
                              guideIdFixed: widget.guideId,
                              onSaved: (updated) => setState(() => _testEntry = updated),
                            ),
                        ],
                      );

                      if (!wide) {
                        return Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [screenEditor, const SizedBox(height: 24), testColumn],
                        );
                      }

                      return Row(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Expanded(child: screenEditor),
                          const SizedBox(width: 16),
                          VerticalDivider(width: 1, color: EzBiziTheme.edge(context)),
                          const SizedBox(width: 16),
                          Expanded(child: testColumn),
                        ],
                      );
                    },
                  ),
                ],
              ),
            ),
          ),
        ),
      ],
    );
  }
}

/// Screens as thumbnails, scrolling horizontally INSIDE this fixed-height
/// box — the only horizontally-scrolling thing on the page (this file's own
/// docblock, "The strip scrolls; the page does not").
class _ScreenStrip extends StatelessWidget {
  const _ScreenStrip({
    required this.screens,
    required this.selected,
    required this.onSelect,
    required this.onAdd,
    required this.onMove,
  });

  final List<Map<String, dynamic>> screens;
  final int? selected;
  final ValueChanged<int> onSelect;
  final VoidCallback onAdd;

  /// `(index, direction)` — direction is always ±1, one card's own
  /// move-left/move-right button (`_GuideBuilderState._moveScreen`).
  final void Function(int index, int direction) onMove;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      key: const Key('training.screen-strip'),
      // 118 plus room for the move-left/move-right row each card now draws
      // under its title (this file's own "Reorder without a mouse").
      height: 134,
      child: ListView(
        scrollDirection: Axis.horizontal,
        children: [
          for (var i = 0; i < screens.length; i++)
            Padding(
              padding: const EdgeInsets.only(right: 10),
              child: _ScreenThumbCard(
                index: i,
                screen: screens[i],
                selected: i == selected,
                onTap: () => onSelect(i),
                onMoveLeft: i > 0 ? () => onMove(i, -1) : null,
                onMoveRight: i < screens.length - 1 ? () => onMove(i, 1) : null,
              ),
            ),
          _AddScreenCard(onTap: onAdd),
        ],
      ),
    );
  }
}

class _ScreenThumbCard extends StatelessWidget {
  const _ScreenThumbCard({
    required this.index,
    required this.screen,
    required this.selected,
    required this.onTap,
    required this.onMoveLeft,
    required this.onMoveRight,
  });

  final int index;
  final Map<String, dynamic> screen;
  final bool selected;
  final VoidCallback onTap;

  /// Null at the strip's first card — there is nothing earlier to swap
  /// with — so the button disables itself rather than the caller needing
  /// to know the strip's own length.
  final VoidCallback? onMoveLeft;

  /// Null at the strip's last card, symmetric with [onMoveLeft].
  final VoidCallback? onMoveRight;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final types = [for (final b in (screen['blocks'] as List? ?? const [])) (b as Map)['type']];
    final kind = types.contains('video') ? 'video' : (types.contains('image') ? 'image' : 'none');
    final title = screen['title'] as String? ?? '';

    return InkWell(
      key: ValueKey('training.screen-card-$index'),
      onTap: onTap,
      borderRadius: BorderRadius.circular(10),
      child: Container(
        width: 150,
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(10),
          border: Border.all(color: selected ? scheme.primary : EzBiziTheme.edge(context), width: selected ? 2 : 1),
          color: scheme.surface,
        ),
        clipBehavior: Clip.antiAlias,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              height: 60,
              width: double.infinity,
              color: scheme.surfaceContainerHighest,
              alignment: Alignment.center,
              child: Icon(
                switch (kind) {
                  'image' => Icons.image_outlined,
                  'video' => Icons.play_circle_outline,
                  _ => Icons.notes_outlined,
                },
                color: scheme.onSurfaceVariant,
              ),
            ),
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 6),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      _MoveButton(
                        key: ValueKey('training.screen-move-left-$index'),
                        icon: Icons.chevron_left,
                        tooltip: 'Move earlier',
                        onPressed: onMoveLeft,
                      ),
                      Expanded(
                        child: Text(
                          '${index + 1} · ${title.isEmpty ? 'Untitled' : title}',
                          textAlign: TextAlign.center,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: theme.textTheme.bodySmall?.copyWith(fontWeight: FontWeight.w700),
                        ),
                      ),
                      _MoveButton(
                        key: ValueKey('training.screen-move-right-$index'),
                        icon: Icons.chevron_right,
                        tooltip: 'Move later',
                        onPressed: onMoveRight,
                      ),
                    ],
                  ),
                  Padding(
                    padding: const EdgeInsets.only(left: 4),
                    child: Text(
                      '${types.length} ${types.length == 1 ? 'block' : 'blocks'}',
                      style: theme.textTheme.bodySmall?.copyWith(color: scheme.onSurfaceVariant),
                    ),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// One arrow on a screen card — `Icons.chevron_left`/`chevron_right`, sized
/// to sit beside the card's own title without pushing it off the card's
/// fixed 150px width (see this file's own "Reorder without a mouse").
/// Disables itself (greys out, `onPressed: null`) rather than disappearing
/// at the strip's own ends — the card at position 1 keeps the SAME two-arrow
/// shape as every other card, just with the left one inert, so nothing
/// shifts around as the author moves a screen toward an edge.
class _MoveButton extends StatelessWidget {
  const _MoveButton({super.key, required this.icon, required this.tooltip, required this.onPressed});

  final IconData icon;
  final String tooltip;
  final VoidCallback? onPressed;

  @override
  Widget build(BuildContext context) {
    return IconButton(
      onPressed: onPressed,
      icon: Icon(icon, size: 15),
      tooltip: tooltip,
      padding: EdgeInsets.zero,
      constraints: const BoxConstraints.tightFor(width: 20, height: 20),
      visualDensity: VisualDensity.compact,
      splashRadius: 12,
    );
  }
}

class _AddScreenCard extends StatelessWidget {
  const _AddScreenCard({required this.onTap});

  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(10),
      child: Container(
        width: 150,
        height: 134,
        alignment: Alignment.center,
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(10),
          border: Border.all(color: scheme.outlineVariant),
        ),
        // A Row here overflowed 150px at the default text scale — a Column
        // (icon above label, both centred) never needs more width than the
        // longer of the two, which "Add screen" alone comfortably clears.
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(Icons.add, size: 20, color: scheme.onSurfaceVariant),
            const SizedBox(height: 6),
            Text('Add topic', style: TextStyle(fontSize: 12, color: scheme.onSurfaceVariant)),
          ],
        ),
      ),
    );
  }
}

class _NoScreenNote extends StatelessWidget {
  const _NoScreenNote();

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Text(
      'This guide has no topics yet — tap "Add topic" above.',
      style: theme.textTheme.bodyMedium?.copyWith(color: theme.colorScheme.onSurfaceVariant),
    );
  }
}

class _NoTestNote extends StatelessWidget {
  const _NoTestNote({required this.searchIncomplete});

  /// See `_GuideBuilderState._testSearchIncomplete`'s own docblock — true
  /// only when the 200-test scan for this guide's test stopped before
  /// covering every test in the organization, in which case "no test is
  /// attached" would be a guess, not a finding.
  final bool searchIncomplete;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Text(
      searchIncomplete
          ? 'Could not confirm whether this guide has a test — there are '
                'more than 200 tests in this organization, and this guide\'s '
                'own test may be among the ones not checked.'
          : 'No test is attached to this guide yet. Attach one from the Tests '
                'list by setting its guide there.',
      style: theme.textTheme.bodyMedium?.copyWith(color: theme.colorScheme.onSurfaceVariant),
    );
  }
}

/// One topic: its title, where it sits (chapter, section), and its content
/// as ordered blocks — text, pictures, videos, tables — as A1 Tools' topic
/// editor had it.
///
/// Every block carries a client-only `_key` so its editor travels WITH it
/// through a move or a delete. Without it Flutter reuses a text field by
/// position, and after a move the next keystroke lands in a different block
/// (A1 Tools shipped exactly that bug and fixed it the same way).
class _ScreenEditor extends StatefulWidget {
  const _ScreenEditor({
    super.key,
    required this.screen,
    required this.onChanged,
    required this.onDelete,
  });

  final Map<String, dynamic> screen;
  final ValueChanged<Map<String, dynamic>> onChanged;
  final VoidCallback onDelete;

  @override
  State<_ScreenEditor> createState() => _ScreenEditorState();
}

class _ScreenEditorState extends State<_ScreenEditor> {
  late final _title = TextEditingController(text: widget.screen['title'] as String? ?? '');
  late final _chapter = TextEditingController(text: widget.screen['chapter'] as String? ?? '');
  late final _section = TextEditingController(text: widget.screen['section'] as String? ?? '');

  List<Map<String, dynamic>> get _blocks => [
    for (final b in (widget.screen['blocks'] as List? ?? const [])) b as Map<String, dynamic>,
  ];

  @override
  void dispose() {
    _title.dispose();
    _chapter.dispose();
    _section.dispose();
    super.dispose();
  }

  void _setBlocks(List<Map<String, dynamic>> blocks) => widget.onChanged({'blocks': blocks});

  void _add(String type) => _setBlocks([
    ..._blocks,
    {
      '_key': _newBlockKey(),
      'type': type,
      if (type == 'table')
        'rows': [
          ['Column 1', 'Column 2'],
        ],
    },
  ]);

  void _patch(int i, Map<String, dynamic> patch) => _setBlocks([
    for (var j = 0; j < _blocks.length; j++) j == i ? {..._blocks[j], ...patch} : _blocks[j],
  ]);

  void _move(int i, int by) {
    final blocks = _blocks;
    final moved = blocks.removeAt(i);
    blocks.insert(i + by, moved);
    _setBlocks(blocks);
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final blocks = _blocks;

    InputDecoration field([String? hint]) =>
        InputDecoration(isDense: true, border: const OutlineInputBorder(), hintText: hint);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            Expanded(child: Text('Topic', style: theme.textTheme.titleSmall)),
            IconButton(
              onPressed: widget.onDelete,
              icon: const Icon(Icons.delete_outline, size: 18),
              tooltip: 'Delete topic',
            ),
          ],
        ),
        const SizedBox(height: 8),
        Text('Title', style: theme.textTheme.labelMedium),
        const SizedBox(height: 4),
        TextField(controller: _title, onChanged: (t) => widget.onChanged({'title': t}), decoration: field()),
        const SizedBox(height: 12),
        Row(
          children: [
            Expanded(
              child: TextField(
                key: const Key('training.topic-chapter'),
                controller: _chapter,
                onChanged: (t) => widget.onChanged({'chapter': t.trim().isEmpty ? null : t}),
                decoration: field('Chapter (optional)'),
              ),
            ),
            const SizedBox(width: 8),
            Expanded(
              child: TextField(
                key: const Key('training.topic-section'),
                controller: _section,
                onChanged: (t) => widget.onChanged({'section': t.trim().isEmpty ? null : t}),
                decoration: field('Section (optional)'),
              ),
            ),
          ],
        ),
        const SizedBox(height: 4),
        Text(
          'Topics next to each other with the same chapter and section are grouped together.',
          style: theme.textTheme.bodySmall?.copyWith(color: theme.colorScheme.onSurfaceVariant),
        ),
        const SizedBox(height: 16),
        Row(
          children: [
            Expanded(child: Text('Content', style: theme.textTheme.titleSmall)),
            PopupMenuButton<String>(
              key: const Key('training.add-block'),
              onSelected: _add,
              itemBuilder: (_) => const [
                PopupMenuItem(value: 'text', child: Text('Text')),
                PopupMenuItem(value: 'image', child: Text('Image link')),
                PopupMenuItem(value: 'video', child: Text('Video link')),
                PopupMenuItem(value: 'table', child: Text('Table')),
              ],
              child: const Chip(avatar: Icon(Icons.add, size: 18), label: Text('Add block')),
            ),
          ],
        ),
        const SizedBox(height: 8),
        if (blocks.isEmpty) Text('No content yet — add a block.', style: theme.textTheme.bodyMedium),
        for (final (i, block) in blocks.indexed)
          _BlockEditor(
            key: ValueKey('training.block-${block['_key']}'),
            block: block,
            onChanged: (patch) => _patch(i, patch),
            onUp: i > 0 ? () => _move(i, -1) : null,
            onDown: i < blocks.length - 1 ? () => _move(i, 1) : null,
            onDelete: () => _setBlocks([...blocks]..removeAt(i)),
          ),
      ],
    );
  }
}

class _BlockEditor extends StatefulWidget {
  const _BlockEditor({
    super.key,
    required this.block,
    required this.onChanged,
    required this.onUp,
    required this.onDown,
    required this.onDelete,
  });

  final Map<String, dynamic> block;
  final ValueChanged<Map<String, dynamic>> onChanged;
  final VoidCallback? onUp;
  final VoidCallback? onDown;
  final VoidCallback onDelete;

  @override
  State<_BlockEditor> createState() => _BlockEditorState();
}

class _BlockEditorState extends State<_BlockEditor> {
  late final _text = TextEditingController(text: _initial());

  String get _type => widget.block['type'] as String? ?? 'text';

  bool get _held => _type == 'image' && widget.block['media_id'] != null;

  String _initial() => switch (widget.block['type']) {
    'text' => widget.block['text'] as String? ?? '',
    // A held image's `url` is a signed link that expires: never put in a
    // field an author could copy it from.
    'image' => widget.block['media_id'] != null ? '' : widget.block['url'] as String? ?? '',
    'video' => widget.block['url'] as String? ?? '',
    'table' => [for (final r in (widget.block['rows'] as List? ?? const [])) (r as List).join(' | ')].join('\n'),
    _ => '',
  };

  void _changed(String t) => widget.onChanged(switch (_type) {
    'text' => {'text': t},
    'table' => {
      'rows': [
        for (final line in t.split('\n'))
          if (line.trim().isNotEmpty) [for (final c in line.split('|')) c.trim()],
      ],
    },
    _ => {'url': t},
  });

  @override
  void dispose() {
    _text.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final long = _type == 'text' || _type == 'table';

    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.fromLTRB(10, 4, 4, 10),
      decoration: BoxDecoration(
        border: Border.all(color: EzBiziTheme.edge(context)),
        borderRadius: BorderRadius.circular(10),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(switch (_type) {
                'image' => Icons.image_outlined,
                'video' => Icons.play_circle_outline,
                'table' => Icons.table_chart_outlined,
                _ => Icons.notes_outlined,
              }, size: 18, color: scheme.onSurfaceVariant),
              const SizedBox(width: 6),
              Expanded(
                child: Text(switch (_type) {
                  'image' => 'Image',
                  'video' => 'Video',
                  'table' => 'Table',
                  _ => 'Text',
                }, style: theme.textTheme.labelMedium),
              ),
              IconButton(onPressed: widget.onUp, icon: const Icon(Icons.arrow_upward, size: 16), tooltip: 'Move up'),
              IconButton(onPressed: widget.onDown, icon: const Icon(Icons.arrow_downward, size: 16), tooltip: 'Move down'),
              IconButton(onPressed: widget.onDelete, icon: const Icon(Icons.close, size: 16), tooltip: 'Remove block'),
            ],
          ),
          if (_held)
            Row(
              children: [
                Icon(Icons.image_outlined, size: 18, color: scheme.onSurfaceVariant),
                const SizedBox(width: 8),
                Text(
                  'Stored with this guide',
                  style: theme.textTheme.bodySmall?.copyWith(color: scheme.onSurfaceVariant),
                ),
              ],
            )
          else
            TextField(
              controller: _text,
              onChanged: _changed,
              minLines: long ? 3 : 1,
              maxLines: long ? 12 : 1,
              decoration: InputDecoration(
                isDense: true,
                border: const OutlineInputBorder(),
                hintText: switch (_type) {
                  'image' || 'video' => 'https://…',
                  'table' => 'One row per line, cells separated by |. The first row is the header.',
                  _ => null,
                },
              ),
            ),
        ],
      ),
    );
  }
}

var _blockSeq = 0;

String _newBlockKey() => 'b${_blockSeq++}';

/// A topic's blocks as the editor holds them, each with its `_key`. The
/// server sends every topic as blocks, whatever shape it was stored in.
List<Map<String, dynamic>> _blocksOf(Map s) => [
  for (final b in (s['blocks'] as List? ?? const []))
    if (b is Map) {'_key': _newBlockKey(), ...Map<String, dynamic>.from(b)},
];

/// What is sent: never the editor's `_key`, never `embed_url`, and for an
/// image the guide holds, its id and not the signed link it was read with.
Map<String, dynamic> _blockForSave(Map<String, dynamic> b) => {
  for (final e in b.entries)
    if (e.key != '_key' && e.key != 'embed_url' && !(e.key == 'url' && b['media_id'] != null)) e.key: e.value,
};

List<Map<String, dynamic>> _mapList(dynamic raw) {
  if (raw is! List) return const [];
  return [
    for (final entry in raw)
      if (entry is Map) Map<String, dynamic>.from(entry),
  ];
}

/// This guide's own franchise or company name, or "Organization" when it is
/// filed at neither — the same resolution `learn_shelves.dart`'s own
/// `_scopeLabel` does over an entry's `franchise_id`/`company_id`; not
/// shared, per this file's own docblock on [_GuideBuilderState._scope].
String _scopeLabel(Me? me, Map<String, dynamic> entry) {
  if (me == null) return 'Organization';

  final franchiseId = entry['franchise_id'] as String?;
  if (franchiseId != null) {
    final franchise = me.franchises.where((f) => f.id == franchiseId).firstOrNull;
    if (franchise != null) return franchise.name;
  }

  final companyId = entry['company_id'] as String?;
  if (companyId != null) {
    final company = me.companies.where((c) => c.id == companyId).firstOrNull;
    if (company != null) return company.name;
  }

  return 'Organization';
}
