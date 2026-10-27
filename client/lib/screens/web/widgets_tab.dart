import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../widgets/colour_field.dart';
import '../../widgets/async_body.dart';
import '../../widgets/error_note.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/shortcode_button.dart';
import 'chat_hours.dart';
import 'widget_previews.dart';
import 'web_lists.dart';

/// Web Management → Widgets: how a site's chat bubble and contact form look,
/// and what the form asks.
///
/// ── Why this is a screen and not shortcode arguments ──
///
/// All of it used to be typed into the WordPress shortcode. That meant the
/// person who could change the wording was whoever could edit the page, the
/// change reached one site, and a company with six subdomains set its colours
/// six times — differently, eventually. Everything here ships down with the
/// values, so a change made once reaches every site that follows the primary,
/// with nobody touching WordPress.
class WidgetsTab extends StatefulWidget {
  const WidgetsTab({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<WidgetsTab> createState() => _WidgetsTabState();
}

class _WidgetsTabState extends State<WidgetsTab> {
  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _sites = const [];
  String? _selected;

  /// What the search bar over the site list holds; matched against what each
  /// row shows.
  String _query = '';

  List<Map<String, dynamic>> get _shownSites {
    final needle = _query.trim().toLowerCase();
    if (needle.isEmpty) return _sites;
    return [
      for (final site in _sites)
        if ([
          site['host'],
          if (site['is_primary'] == true) 'Primary',
          (site['franchise'] as Map?)?['name'],
        ].any((v) => v != null && '$v'.toLowerCase().contains(needle)))
          site,
    ];
  }

  /// Pane widths, held HERE rather than in the editor.
  ///
  /// The editor is keyed by site id, so its state is thrown away every time
  /// somebody clicks a different website — a width kept there would snap back
  /// on every click, which is worse than not being draggable.
  ///
  /// Seeded from the person's saved preference and written back when a drag
  /// settles, the way the app's own sidebar does it. During the drag the value
  /// lives here so the pane follows the pointer at frame rate; a PATCH per
  /// pixel would be a request per frame for a value nobody has finished
  /// choosing.
  late double _listWidth = widget.session.me?.webWidgetsListWidth ?? 260;
  late double _previewWidth = widget.session.me?.webWidgetsPreviewWidth ?? 360;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _loading = true);

    final response = await widget.api.get('/web/sites');

    if (!mounted) return;

    setState(() {
      _loading = false;

      if (!response.ok) {
        _error = response.message;
        return;
      }

      _sites = ((response.body['data'] as List<dynamic>?) ?? const [])
          .cast<Map<String, dynamic>>();
      _selected ??= _sites.isEmpty ? null : _sites.first['id'] as String?;
    });
  }

  bool get _canEdit => widget.session.me!.can('web_management.edit');

  /// How many websites belong to the same company as the one open.
  ///
  /// Per COMPANY, not "everything in the rail": somebody who can reach two
  /// companies would otherwise be told a company-wide switch covers sites it
  /// cannot touch. The server scopes by company either way; this is the label
  /// agreeing with what the button will do.
  int get _companySiteCount {
    final open = _sites.firstWhere(
      (site) => site['id'] == _selected,
      orElse: () => const <String, dynamic>{},
    );

    final company = (open['company'] as Map?)?['id'];

    if (company == null) return _sites.length;

    return _sites
        .where((site) => (site['company'] as Map?)?['id'] == company)
        .length;
  }

  @override
  Widget build(BuildContext context) {
    return AsyncBody(
      loading: _loading,
      error: _error,
      onRetry: _load,
      child: _sites.isEmpty ? _noSites(context) : _split(context),
    );
  }

  Widget _noSites(BuildContext context) {
    final theme = Theme.of(context);

    return Center(
      key: const ValueKey('tour-widgets-no-sites'),
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text('No websites yet', style: theme.textTheme.titleMedium),
            const SizedBox(height: 8),
            Text(
              'Register a website under SEO first. The widgets are dressed per '
              'site, so there is nothing to dress until there is one.',
              textAlign: TextAlign.center,
              style: theme.textTheme.bodySmall,
            ),
          ],
        ),
      ),
    );
  }

  Widget _split(BuildContext context) {
    final wide = MediaQuery.sizeOf(context).width >= 900;

    final shown = _shownSites;
    final list = ListView(
      key: const ValueKey('tour-widgets-sites'),
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(8, 8, 8, 4),
          child: LayoutBuilder(
            // As tall as the buttons beside it (Jose, 2026-10-20); no wider
            // than the list, which can be dragged down to 180.
            builder: (context, box) => Align(
              alignment: Alignment.centerLeft,
              child: SizedBox(
                width: math.min(240, box.maxWidth),
                child: TextField(
                  key: const Key('web-widgets-sites-search'),
                  textAlignVertical: TextAlignVertical.center,
                  decoration: const InputDecoration(
                    isDense: true,
                    prefixIconConstraints: BoxConstraints(minWidth: 36),
                    prefixIcon: Icon(Icons.search, size: 18),
                    hintText: 'Find a website',
                    border: OutlineInputBorder(),
                  ),
                  onChanged: (v) => setState(() => _query = v),
                ),
              ),
            ),
          ),
        ),
        if (shown.isEmpty)
          Padding(
            padding: const EdgeInsets.all(16),
            child: Text(
              'No websites match "${_query.trim()}".',
              style: Theme.of(context).textTheme.bodyMedium,
            ),
          ),
        for (final site in shown)
          ListTile(
            selected: site['id'] == _selected,
            title: Text('${site['host']}'),
            subtitle: site['is_primary'] == true
                ? const Text('Primary')
                : (site['franchise'] is Map
                    ? Text('${(site['franchise'] as Map)['name']}')
                    : null),
            onTap: () => setState(() => _selected = site['id'] as String?),
          ),
      ],
    );

    final detail = _selected == null
        ? const SizedBox.shrink()
        : _WidgetEditor(
            key: ValueKey(_selected),
            api: widget.api,
            siteId: _selected!,
            siteCount: _companySiteCount,
            canEdit: _canEdit,
            previewWidth: _previewWidth,
            onPreviewSettled: () => widget.session.setPreference(
              'web.widgets.preview_width',
              _previewWidth.round(),
            ),
            onPreviewDelta: (delta) => setState(
              () => _previewWidth = (_previewWidth - delta).clamp(280.0, 640.0),
            ),
          );

    if (!wide) {
      return _selected == null ? list : detail;
    }

    return LayoutBuilder(
      builder: (context, constraints) {
        return Row(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            SizedBox(width: _listWidth, child: list),
            _DragHandle(
              tooltip: 'Drag to resize the list',
              onSettled: () => widget.session.setPreference(
                'web.widgets.list_width',
                _listWidth.round(),
              ),
              onDelta: (delta) => setState(() {
                // 180 still shows a subdomain without truncating it; past 460
                // the list is wider than the longest host anybody registers and
                // the space is simply taken from the editor.
                _listWidth = (_listWidth + delta).clamp(180.0, 460.0);
              }),
            ),
            Expanded(
              child: _selected == null
                  ? detail
                  : _WidgetEditor(
                      key: ValueKey(_selected),
                      api: widget.api,
                      siteId: _selected!,
                      siteCount: _companySiteCount,
                      canEdit: _canEdit,
                      previewWidth: _previewWidth,
                      onPreviewSettled: () => widget.session.setPreference(
                        'web.widgets.preview_width',
                        _previewWidth.round(),
                      ),
                      onPreviewDelta: (delta) => setState(() {
                        // Dragging LEFT widens the preview, so the sign flips.
                        // The upper bound leaves the form its 560: below that
                        // the question rows start wrapping, and a form that
                        // wraps to make room for a preview of itself is the
                        // wrong trade.
                        final room = constraints.maxWidth - _listWidth - 560;
                        final most = room < 300.0 ? 300.0 : room;
                        _previewWidth =
                            (_previewWidth - delta).clamp(280.0, most);
                      }),
                    ),
            ),
          ],
        );
      },
    );
  }
}

/// The line between two panes, which you can also pull.
///
/// ── Why this is not a plain [VerticalDivider] ──
///
/// The widths on this screen were mine: 260 for the site list, 360 for the
/// preview. Both are guesses about somebody else's monitor, their site count
/// and whether they are reading the form or the preview at that moment — and a
/// guess about all three at once is a guess that is wrong for most people.
/// Pulling the line is how they say which.
///
/// ── What it refuses to do ──
///
/// It clamps rather than letting a pane be dragged to nothing. A pane collapsed
/// to two pixels is not a smaller pane, it is a lost one, and the way back is
/// a hairline somebody has to find. The caller supplies the bounds because only
/// it knows what its own content stops working at.
///
/// ── The invisible part that matters most ──
///
/// The hit area is wider than the line. A one-pixel divider is a one-pixel
/// target, which is a fight with the mouse rather than a control; the divider
/// stays a hairline and the grab area around it is twelve.
class _DragHandle extends StatefulWidget {
  const _DragHandle({
    required this.onDelta,
    required this.onSettled,
    this.tooltip = 'Drag to resize',
  });

  /// Pixels the pointer moved since the last frame, positive to the right.
  final void Function(double delta) onDelta;

  /// The drag has stopped. Separate from [onDelta] because the value is worth
  /// saving once, not sixty times a second while somebody is still choosing.
  final VoidCallback onSettled;

  final String tooltip;

  @override
  State<_DragHandle> createState() => _DragHandleState();
}

class _DragHandleState extends State<_DragHandle> {
  bool _hovering = false;
  bool _dragging = false;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final lit = _hovering || _dragging;

    return MouseRegion(
      cursor: SystemMouseCursors.resizeLeftRight,
      onEnter: (_) => setState(() => _hovering = true),
      onExit: (_) => setState(() => _hovering = false),
      child: GestureDetector(
        // Opaque, so the twelve pixels of padding are draggable rather than
        // only the hairline drawn inside them.
        behavior: HitTestBehavior.opaque,
        onHorizontalDragStart: (_) => setState(() => _dragging = true),
        onHorizontalDragEnd: (_) {
          setState(() => _dragging = false);
          widget.onSettled();
        },
        // A cancelled drag still settled somewhere: the pane is at whatever
        // width the last frame left it, so that width is the one to keep.
        onHorizontalDragCancel: () {
          setState(() => _dragging = false);
          widget.onSettled();
        },
        onHorizontalDragUpdate: (details) => widget.onDelta(details.delta.dx),
        child: Tooltip(
          message: widget.tooltip,
          waitDuration: const Duration(milliseconds: 700),
          child: SizedBox(
            width: 12,
            child: Center(
              child: Container(
                width: lit ? 3 : 1,
                color: lit
                    ? theme.colorScheme.primary
                    : theme.colorScheme.outlineVariant,
              ),
            ),
          ),
        ),
      ),
    );
  }
}

/// One site's widget settings.
class _WidgetEditor extends StatefulWidget {
  const _WidgetEditor({
    super.key,
    required this.api,
    required this.siteId,
    required this.siteCount,
    required this.canEdit,
    required this.previewWidth,
    required this.onPreviewDelta,
    required this.onPreviewSettled,
  });

  final ApiClient api;
  final String siteId;

  /// How many websites this site's COMPANY has.
  ///
  /// Counted from the rail rather than asked for separately, and counted per
  /// company rather than as "every site in the list" — the rail can hold two
  /// companies, and "all 47 websites" is a promise about one of them.
  final int siteCount;
  final bool canEdit;

  /// Owned by the parent, because this widget is rebuilt from scratch whenever
  /// a different website is selected.
  final double previewWidth;
  final void Function(double delta) onPreviewDelta;

  /// Called once the drag stops, so the width is saved rather than the
  /// hundred values it passed through on the way.
  final VoidCallback onPreviewSettled;

  @override
  State<_WidgetEditor> createState() => _WidgetEditorState();
}

class _WidgetEditorState extends State<_WidgetEditor> {
  /// True while a company-wide action is in flight.
  ///
  /// Its own flag rather than `_saving`: that one belongs to the Save button
  /// and these buttons change sites this pane is not showing, so sharing it
  /// would grey out Save for a reason the person cannot see.
  bool _working = false;

  int get _siteCount => widget.siteCount;

  bool _loading = true;
  bool _saving = false;
  String? _error;
  String? _saved;

  /// True when this site dresses itself; false when it follows the primary.
  bool _own = false;

  Map<String, dynamic> _config = {};
  Map<String, dynamic> _inherited = {};

  /// What each preset option is, from the server: how the generated style
  /// options are drawn without this screen keeping its own list of them.
  Map<String, dynamic> _schema = {};

  /// The colour this business publishes under, or null if it has told us none.
  String? _brand;

  /// Bumped only when a value is set from OUTSIDE the text box — picking a
  /// preset — so the boxes rebuild and show it.
  ///
  /// The fields are keyed on this rather than on their own value, which would
  /// rebuild them on every keystroke and take the cursor with it. Without it
  /// the config changed and the box kept the old text, so a customer could
  /// pick a colour and then type over their own pick without seeing it.
  int _external = 0;
  List<String> _fieldTypes = const ['text'];

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _loading = true);

    final response = await widget.api.get('/web/sites/${widget.siteId}/widgets');

    if (!mounted) return;

    setState(() {
      _loading = false;

      if (!response.ok) {
        _error = response.message;
        return;
      }

      _config = Map<String, dynamic>.from(
          response.body['config'] as Map? ?? const {});
      _inherited = Map<String, dynamic>.from(
          response.body['inherited'] as Map? ?? const {});
      _schema = Map<String, dynamic>.from(
          response.body['schema'] as Map? ?? const {});
      _own = response.body['own'] == true;
      _brand = response.body['brand'] as String?;
      _fieldTypes = ((response.body['field_types'] as List?) ?? const ['text'])
          .map((type) => '$type')
          .toList();
    });
  }

  Map<String, dynamic> _section(String key) =>
      Map<String, dynamic>.from(_config[key] as Map? ?? {});

  void _set(String section, String key, Object? value) {
    setState(() {
      final part = _section(section);
      part[key] = value;
      _config[section] = part;
      _saved = null;
    });
  }

  Future<void> _save() async {
    setState(() {
      _saving = true;
      _error = null;
    });

    final response = await widget.api
        .put('/web/sites/${widget.siteId}/widgets', {'config': _config});

    if (!mounted) return;

    setState(() {
      _saving = false;

      if (!response.ok) {
        _error = response.message;
        return;
      }

      _config = Map<String, dynamic>.from(
          response.body['config'] as Map? ?? const {});
      _own = response.body['own'] == true;

      _saved = 'Saved. Every site that follows this one changes with it.';
    });
  }

  Future<void> _followPrimary() async {
    setState(() => _saving = true);

    final response =
        await widget.api.delete('/web/sites/${widget.siteId}/widgets');

    if (!mounted) return;

    setState(() {
      _saving = false;

      if (!response.ok) {
        _error = response.message;
        return;
      }

      _config = Map<String, dynamic>.from(
          response.body['config'] as Map? ?? const {});
      _own = response.body['own'] == true;

      _saved = 'Following the primary website again.';
    });
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    if (_loading) {
      return const Center(child: CircularProgressIndicator());
    }

    final chat = _section('chat');
    final form = _section('form');
    final style = _section('style');

    final following = !_own
        ? Padding(
            padding: const EdgeInsets.only(bottom: 12),
            child: Text(
              'Following the primary website. Change anything here and this '
              'site starts answering for itself.',
              style: theme.textTheme.bodySmall,
            ),
          )
        : const SizedBox.shrink();

    // Nothing chosen: the cards, full width. No preview pane, because a
    // preview of six widgets at once is the thing being replaced.
    if (_focus == null) {
      return ListView(
        key: const ValueKey('widgets-cards'),
        padding: const EdgeInsets.all(20),
        children: [following, ..._cards(theme, chat, style)],
      );
    }

    final formPane = ListView(
      // Named so a test can drive THIS scrollable. There are three on the
      // screen and the pane's own headings are ListView children, so a finder
      // anchored on one of those disappears part-way through the scroll it is
      // driving.
      key: const ValueKey('widgets-editor'),
      padding: const EdgeInsets.all(20),
      children: [
        following,
        _focusHeader(theme),
        ..._focusedFields(theme, chat, form, style),

        const SizedBox(height: 16),
        if (_error != null) ErrorNote(_error!),
        if (_saved != null)
          Text(_saved!, style: theme.textTheme.bodySmall),
        const SizedBox(height: 8),
        if (widget.canEdit)
          Wrap(
            spacing: 8,
            runSpacing: 8,
            alignment: WrapAlignment.end,
            children: [
              if (_own && _inherited.isNotEmpty)
                TextButton(
                  onPressed: _saving ? null : _followPrimary,
                  child: const Text('Follow the primary again'),
                ),
              /*
               * Jose, 2026-09-23: "can we add a button to clear the cache as
               * well, or make it so when any change it done and saved it
               * clears it".
               *
               * The second half is already true — since plugin 1.13.0 a site
               * clears its own page cache whenever EzBizi's answer changes, so
               * saving IS clearing. This button is for the case that does not
               * cover: a page gone stale for a reason EzBizi never saw.
               */
              TextButton.icon(
                key: const ValueKey('tour-widgets-rebuild'),
                onPressed: _working ? null : _refreshEverywhere,
                icon: const Icon(Icons.refresh, size: 18),
                label: Text(
                  _siteCount > 1
                      ? 'Rebuild all $_siteCount websites'
                      : 'Rebuild this website',
                ),
              ),
              FilledButton(
                key: const ValueKey('tour-widgets-save'),
                onPressed: _saving ? null : _save,
                child: Text(_saving ? 'Saving…' : 'Save'),
              ),
            ],
          ),
      ],
    );

    /*
     * The preview sits BESIDE the form, not above it.
     *
     * Everything on this screen is a value that only means something once it
     * is rendered — a hex, a radius, a line of wording — and the page it
     * renders on is one nobody at EzBizi will ever load. Above the form it
     * would scroll away exactly when somebody started changing things.
     *
     * Below 920 there is no room for both: the form needs about 560 before the
     * question rows start wrapping, and a preview narrower than 320 stops
     * resembling the thing it previews. So the form keeps the width and the
     * preview steps aside rather than both being cramped.
     */
    return LayoutBuilder(
      builder: (context, constraints) {
      if (constraints.maxWidth < 920) return formPane;

      return Row(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Expanded(child: formPane),
          _DragHandle(
            tooltip: 'Drag to resize the preview',
            onDelta: widget.onPreviewDelta,
            onSettled: widget.onPreviewSettled,
          ),
          SizedBox(width: widget.previewWidth, child: _preview(theme)),
        ],
      );
      },
    );
  }

  /// ── One widget at a time ──────────────────────────────────────
  ///
  /// Jose, 2026-09-23: *"i'd like to see each widget as a card, so i can click
  /// on them and focus on it, and so i dont see all the previews at once on
  /// the right side"*.
  ///
  /// The screen used to be one column holding every widget's settings at once,
  /// with a preview pane showing the chat and the form together. Six widgets
  /// in, that is a page somebody scrolls past four things to reach the fifth,
  /// beside a preview of two of them. So the fields below are grouped per
  /// widget and only the chosen one is drawn.
  ///
  /// The SAVE is still one save. A site has one widget config and the API
  /// takes it whole, so focusing is about what you look at rather than about
  /// what you are editing — somebody who opens three cards and presses Save
  /// once saves all three, which is the behaviour that cannot surprise.

  /// Which widget is open, by key, or null for the cards.
  ///
  /// A KEY rather than an index: the catalogue below grows, and an index would
  /// point at a different widget the day somebody adds one.
  String? _focus;

  /// Every widget this screen dresses, in the order the cards draw them.
  ///
  /// `chat`, `form` and `look` are hand-written because they are not rendered
  /// from a shortcode the way the other four are — the chat and the form are
  /// placed as widgets or drawn in the footer, and Look is shared by all of
  /// them rather than being a widget at all.
  List<_WidgetCardSpec> get _catalogue => [
    const _WidgetCardSpec(
      'chat',
      'Live chat',
      'The bubble a visitor opens to start a conversation.',
      Icons.chat_bubble_outline,
    ),
    const _WidgetCardSpec(
      'form',
      'Contact form',
      'The questions a visitor answers to send a message.',
      Icons.assignment_outlined,
    ),
    for (final rendered in _renderedCatalogue)
      _WidgetCardSpec(
        rendered.key,
        rendered.label,
        rendered.blurb,
        _renderedIcons[rendered.key] ?? Icons.widgets_outlined,
      ),
    const _WidgetCardSpec(
      'look',
      'Look',
      'Colours, corners and typeface, shared by every widget above.',
      Icons.palette_outlined,
    ),
  ];

  /// One line under each card saying what it is set to NOW.
  ///
  /// The point of a card grid is to answer "what is switched on here" without
  /// opening anything; six cards that all read the same would put that answer
  /// one click further away than the old long column did.
  String _cardStatus(String key, Map<String, dynamic> chat, Map<String, dynamic> style) {
    switch (key) {
      case 'chat':
        if (chat['enabled'] == false) return 'Not on this website';

        final hours = (chat['hours'] as List? ?? const []).length;

        return hours == 7 ? 'On, during set hours' : 'On, at all hours';
      case 'form':
        final fields = (_section('form')['fields'] as List? ?? const []).length;

        return fields == 1 ? '1 question' : '$fields questions';
      case 'look':
        return '${style['accent'] ?? ''} · ${style['radius'] ?? 12}px corners';
      default:
        // `default` is not one of these. Every widget always has it — the
        // server guarantees it — so counting it would make all four cards read
        // "1 named preset" for a site where nobody has named anything.
        final named = Map<String, dynamic>.from(
          (_config['presets'] as Map? ?? const {})[key] as Map? ?? const {},
        ).keys.where((name) => name != 'default').length;

        if (named == 0) return 'Default look';

        return named == 1 ? '1 named preset' : '$named named presets';
    }
  }

  List<Widget> _cards(
    ThemeData theme,
    Map<String, dynamic> chat,
    Map<String, dynamic> style,
  ) => [
    // No 'Widgets' heading. The tab strip directly above already says it, and
    // the same word twice reads as two things — the precedent is the HR
    // dashboard's heading, removed for sitting under a chip of its own name.
    Text(
      'Open one to change it and see it previewed on its own. Everything here '
      'saves together.',
      style: theme.textTheme.bodySmall,
    ),
    const SizedBox(height: 16),
    Wrap(
      spacing: 12,
      runSpacing: 12,
      children: [
        for (final spec in _catalogue)
          _WidgetCard(
            spec: spec,
            status: _cardStatus(spec.key, chat, style),
            onOpen: () => setState(() => _focus = spec.key),
          ),
      ],
    ),
  ];

  /// The way back, and the name of what is open.
  Widget _focusHeader(ThemeData theme) {
    final spec = _catalogue.firstWhere((s) => s.key == _focus);

    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: Row(
        children: [
          IconButton(
            tooltip: 'Back to the widgets',
            icon: const Icon(Icons.arrow_back),
            onPressed: () => setState(() => _focus = null),
          ),
          const SizedBox(width: 4),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(spec.label, style: theme.textTheme.titleSmall),
                Text(spec.blurb, style: theme.textTheme.bodySmall),
              ],
            ),
          ),
        ],
      ),
    );
  }

  /// The fields of whichever widget is open.
  List<Widget> _focusedFields(
    ThemeData theme,
    Map<String, dynamic> chat,
    Map<String, dynamic> form,
    Map<String, dynamic> style,
  ) {
    switch (_focus) {
      case 'chat':
        return _chatFields(theme, chat);
      case 'form':
        return _formFields(theme, form);
      case 'look':
        return _lookFields(theme, style);
      default:
        return _renderedFields(
          theme,
          _renderedCatalogue.firstWhere((r) => r.key == _focus),
        );
    }
  }

  List<Widget> _chatFields(ThemeData theme, Map<String, dynamic> chat) => [
      _chatSwitch(theme, chat),
      _text(theme, 'Title', chat['title'], (v) => _set('chat', 'title', v)),
      _text(theme, 'Opening line', chat['intro'], (v) => _set('chat', 'intro', v)),
      _text(theme, 'Bubble label', chat['button'], (v) => _set('chat', 'button', v)),
      _text(theme, 'Box placeholder', chat['placeholder'],
          (v) => _set('chat', 'placeholder', v)),

      // What the visitor is asked BEFORE the conversation starts. Without
      // these three answers a chat arrives in Leads with nobody's name on
      // it, and the only way to reply is to hope they come back.
      _text(theme, 'Before they start', chat['lead_in'],
          (v) => _set('chat', 'lead_in', v)),
      _text(theme, 'Name question', chat['name_label'],
          (v) => _set('chat', 'name_label', v)),
      _text(theme, 'Message question', chat['message_label'],
          (v) => _set('chat', 'message_label', v)),
      _text(theme, 'Start button', chat['start'], (v) => _set('chat', 'start', v)),

      // Sent back the moment they press it. A real message on the thread, so
      // whoever picks the conversation up in Leads can see what the person
      // was already told. Leave it empty to send nothing.
      _text(theme, 'Automatic first reply', chat['auto_reply'],
          (v) => _set('chat', 'auto_reply', v)),
      _dropdown(
        theme,
        'Where it sits',
        '${chat['position'] ?? 'bottom-right'}',
        const ['bottom-right', 'bottom-left', 'inline'],
        (v) => _set('chat', 'position', v),
      ),

      // Toula's Live Chat list: who answers, how the bubble looks, and when
      // and where it shows. Folded away, so the everyday wording stays on top.
      ExpansionTile(
        title: Text('More chat options', style: theme.textTheme.titleSmall),
        tilePadding: EdgeInsets.zero,
        childrenPadding: EdgeInsets.zero,
        children: [
          _text(theme, 'Agent name', chat['agent_name'],
              (v) => _set('chat', 'agent_name', v)),
          _text(theme, 'Agent photo (https:// link)', chat['avatar_url'],
              (v) => _set('chat', 'avatar_url', v)),
          _flag(theme, 'Show an online dot', chat['show_status'] == true,
              (on) => _set('chat', 'show_status', on)),
          _text(theme, 'Online wording', chat['online_text'],
              (v) => _set('chat', 'online_text', v)),
          _dropdown(theme, 'Bubble icon', '${chat['bubble_icon'] ?? 'chat'}',
              const ['chat', 'message', 'phone', 'help'],
              (v) => _set('chat', 'bubble_icon', v)),
          _number(theme, 'Bubble height (px)', chat['bubble_size'], 48,
              (v) => _set('chat', 'bubble_size', v)),
          _number(theme, 'Distance from the side (px)', chat['offset_x'], 20,
              (v) => _set('chat', 'offset_x', v)),
          _number(theme, 'Distance from the bottom (px)', chat['offset_y'], 88,
              (v) => _set('chat', 'offset_y', v)),
          _dropdown(theme, 'How it appears', '${chat['animation'] ?? 'slide'}',
              const ['slide', 'fade', 'none'],
              (v) => _set('chat', 'animation', v)),
          _number(theme, 'Wait before showing (seconds)', chat['delay'], 0,
              (v) => _set('chat', 'delay', v)),
          _dropdown(theme, 'Show on', '${chat['devices'] ?? 'all'}',
              const ['all', 'desktop', 'mobile'],
              (v) => _set('chat', 'devices', v)),
          _text(
              theme,
              'Never on these pages (one path per line, /jobs/* for a section)',
              chat['hide_on'],
              (v) => _set('chat', 'hide_on', v),
              lines: 3),
          _colourBox('Bubble colour', chat['bubble_bg'],
              (v) => _set('chat', 'bubble_bg', v), hint: 'empty uses the accent'),
          _colourBox('Bubble text colour', chat['bubble_color'],
              (v) => _set('chat', 'bubble_color', v)),
          _colourBox('Header colour', chat['header_bg'],
              (v) => _set('chat', 'header_bg', v)),
          _colourBox('Header text colour', chat['header_color'],
              (v) => _set('chat', 'header_color', v)),
          _colourBox('Visitor message colour', chat['visitor_bg'],
              (v) => _set('chat', 'visitor_bg', v)),
          _colourBox('Business message colour', chat['business_bg'],
              (v) => _set('chat', 'business_bg', v)),
          // Toula, 2026-10-01: each widget's own buttons and focus border.
          _colourBox('Send button colour', chat['send_bg'],
              (v) => _set('chat', 'send_bg', v), hint: 'empty uses the accent'),
          _colourBox('Start chat button colour', chat['start_bg'],
              (v) => _set('chat', 'start_bg', v), hint: 'empty uses the accent'),
          _colourBox('Border of the box being typed in', chat['focus_color'],
              (v) => _set('chat', 'focus_color', v), hint: 'empty uses the accent'),
          _flag(theme, 'Shadow', chat['shadow'] != false,
              (on) => _set('chat', 'shadow', on)),
          _flag(theme, 'Report opens and starts to analytics',
              chat['analytics'] == true, (on) => _set('chat', 'analytics', on)),
        ],
      ),

      const SizedBox(height: 16),
      /*
       * Both keys in one call, because the server drops a schedule with no
       * zone and a zone with no schedule. Setting them one at a time would
       * mean a save that silently discards the half that arrived first.
       */
      ChatHoursField(
        hours: (chat['hours'] as List? ?? const [])
            .whereType<Map>()
            .map((day) => Map<String, dynamic>.from(day))
            .toList(),
        timezone: '${chat['timezone'] ?? ''}',
        enabled: widget.canEdit,
        onChanged: (hours, timezone) => setState(() {
          final part = _section('chat');
          part['hours'] = hours;
          part['timezone'] = timezone;
          _config['chat'] = part;
          _saved = null;
        }),
      ),

  ];

  List<Widget> _formFields(ThemeData theme, Map<String, dynamic> form) => [
      _text(theme, 'Title', form['title'], (v) => _set('form', 'title', v)),
      _text(theme, 'Line under the title', form['subtitle'],
          (v) => _set('form', 'subtitle', v)),
      _text(theme, 'Button', form['button'], (v) => _set('form', 'button', v)),
      _text(theme, 'Thank you message', form['thanks'],
          (v) => _set('form', 'thanks', v)),
      _text(theme, 'Message when it fails', form['error'],
          (v) => _set('form', 'error', v)),
      _text(theme, 'After sending, go to (optional link)', form['redirect'],
          (v) => _set('form', 'redirect', v)),
      SwitchListTile(
        contentPadding: EdgeInsets.zero,
        dense: true,
        title: const Text('Ask "I\'m not a robot"'),
        value: form['antibot'] == true,
        onChanged: widget.canEdit ? (on) => _set('form', 'antibot', on) : null,
      ),
      if (form['antibot'] == true)
        _text(theme, 'Robot check wording', form['antibot_label'],
            (v) => _set('form', 'antibot_label', v)),
      // Always on since 2026-10-06 (Jose: "Activate captcha on all websites"),
      // so it is said rather than switched: the visitor's browser solves a
      // puzzle EzBizi checks, nothing to click, no Google or Cloudflare.
      Padding(
        key: const Key('form-captcha'),
        padding: const EdgeInsets.symmetric(vertical: 6),
        child: Row(
          children: [
            Icon(Icons.verified_user_outlined, size: 18, color: theme.colorScheme.primary),
            const SizedBox(width: 8),
            Expanded(
              child: Text(
                'Robots are stopped by a CAPTCHA on every form and chat. '
                'Visitors see nothing to solve.',
                style: theme.textTheme.bodySmall,
              ),
            ),
          ],
        ),
      ),
      _text(theme, 'Also email each enquiry to (up to 5, commas between)',
          form['notify'], (v) => _set('form', 'notify', v)),
      SwitchListTile(
        contentPadding: EdgeInsets.zero,
        dense: true,
        title: const Text('Offer the consent box'),
        value: form['consent_on'] != false,
        onChanged: widget.canEdit ? (on) => _set('form', 'consent_on', on) : null,
      ),
      if (form['consent_on'] != false)
        _text(theme, 'Consent box wording', form['consent'],
            (v) => _set('form', 'consent', v)),
      const SizedBox(height: 12),
      _FieldList(
        fields: (form['fields'] as List? ?? const [])
            .whereType<Map>()
            .map((f) => Map<String, dynamic>.from(f))
            .toList(),
        types: _fieldTypes,
        canEdit: widget.canEdit,
        onChanged: (fields) => _set('form', 'fields', fields),
      ),
      const SizedBox(height: 16),
      // How it looks: a preset, like the rendered widgets, so one page's form
      // can sit dark and another's light.
      ..._renderedFields(theme, _formLook),
  ];

  static const _Rendered _formLook = _Rendered(
    'form',
    'Contact form look',
    'How the form is drawn.',
    [
      _Number('field_gap', 'Space between questions', 0, 40, 12, suffix: 'px'),
      _Choice('button_width', 'Send button', ['auto', 'full']),
    ],
  );

  /// Shared by every widget, which is why it is its own card rather than a
  /// section inside one: a colour changed here moves the chat, the form and
  /// the four rendered widgets together, and a person who found it under
  /// "Live chat" would reasonably think it belonged to the chat.
  List<Widget> _lookFields(ThemeData theme, Map<String, dynamic> style) => [
      const SizedBox(height: 4),
      Text(
        _brand == null
            // Said plainly rather than hidden: a business that has told us no
            // colour is getting OURS on THEIR website, and the person who
            // would mind is the one least likely to be reading this screen.
            ? 'These widgets appear on your own website, so they start from '
                'your brand colour — you have not set one, so they are using '
                'a neutral blue until you do.'
            : 'Starting from your brand colour, $_brand. Change anything here '
                'and this site keeps its own instead.',
        style: theme.textTheme.bodySmall,
      ),
      const SizedBox(height: 4),
      Text(
        'Shared by both widgets — a site that sets its accent means it for '
        'the chat and the form.',
        style: theme.textTheme.bodySmall,
      ),
      const SizedBox(height: 8),
      _colourBox('Accent colour', style['accent'],
          (v) => _set('style', 'accent', v)),
      _presets(theme),
      _colourBox('Text on the accent', style['on_accent'],
          (v) => _set('style', 'on_accent', v)),
      _pair(theme, style['on_accent'], style['accent'], 'the accent'),
      _colourBox('Text colour', style['text'], (v) => _set('style', 'text', v)),
      _colourBox('Background', style['background'],
          (v) => _set('style', 'background', v)),
      _pair(theme, style['text'], style['background'], 'the background'),
      _text(theme, 'Font family', style['font'], (v) => _set('style', 'font', v)),
      _text(theme, 'Corner radius', '${style['radius'] ?? 12}',
          (v) => _set('style', 'radius', int.tryParse(v) ?? 12)),

  ];

  /// Whether this website has a live chat at all.
  ///
  /// Mia, 2026-09-18: *"i also want to have a toggle to turn it on or off, on
  /// being the default, (by turning it off, it basically disappears on the
  /// website, effectively disabling the chat)"*.
  ///
  /// ── Why the wording works so hard to be unlike the schedule ──
  ///
  /// Two controls in this one section can take the bubble off a page, and
  /// somebody who mixes them up switches the chat off for ever believing they
  /// have set office hours. So each says what the other is not: the schedule
  /// below hides the bubble outside the hours somebody keeps, this decides
  /// whether the website has a chat to hide.
  ///
  /// ── Why nothing below it is disabled ──
  ///
  /// Fixing the wording before switching it back on is the ordinary order of
  /// doing this, and a greyed-out section forces the opposite: turn the chat
  /// on, then edit, with the unfinished wording live on the website for as
  /// long as that takes. Only the preview goes, because the preview is the one
  /// thing here that claims to be what a visitor sees.
  ///
  /// There is no equivalent for the contact form, and that is not an
  /// oversight — see `WidgetDefaults::chat()`. The chat puts ITSELF on every
  /// page through `wp_footer`, so a switch is the only way off; the form
  /// appears where somebody placed a shortcode, so a second invisible way to
  /// blank it is how a deliberately placed form goes missing.
  Widget _chatSwitch(ThemeData theme, Map<String, dynamic> chat) {
    // Absent reads as ON. The key is newer than some stored configs, and the
    // server's own default is true — a missing key must not read as a chat
    // somebody switched off.
    final on = chat['enabled'] != false;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
      SwitchListTile(
        contentPadding: EdgeInsets.zero,
        value: on,
        onChanged:
            widget.canEdit ? (v) => _set('chat', 'enabled', v) : null,
        title: const Text('Show the chat on this website'),
        subtitle: Text(
          'On, the bubble is on every page. Off, there is no chat on the '
          'website at all — which is not what the chat hours below do: '
          'those keep the bubble off the page outside your hours.',
          style: theme.textTheme.bodySmall,
        ),
      ),
      if (!on)
        Padding(
          padding: const EdgeInsets.only(top: 4, bottom: 12),
          child: Container(
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              color: theme.colorScheme.surfaceContainerHighest,
              borderRadius: BorderRadius.circular(8),
              border: Border.all(color: theme.colorScheme.outlineVariant),
            ),
            child: Text(
              'There is no chat on this website. No visitor sees a bubble on '
              'any page, and nothing anybody types can reach Leads. The '
              'wording below is still yours to change — it goes live the '
              'moment the switch above does.',
              style: theme.textTheme.bodySmall,
            ),
          ),
        ),

      /*
       * ── The switch that covers the business ──
       *
       * Jose, 2026-09-23: he switched the chat off HERE, on one website, and
       * kept receiving chats all day. A-1 Chimney has 47 sites; the other 46
       * follow the primary, which was still on. Nothing was broken and the
       * screen said nothing, because a screen that shows one site at a time
       * cannot say "and the other forty-six are still on" unless somebody
       * makes it.
       *
       * So the count is on the label. "every one of this company's 47
       * websites" is the sentence that would have stopped the whole day.
       */
      if (widget.canEdit && _siteCount > 1) ...[
        const SizedBox(height: 4),
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            OutlinedButton.icon(
              onPressed: _working ? null : () => _chatEverywhere(false),
              icon: const Icon(Icons.public_off, size: 18),
              label: Text('Turn the chat off on all $_siteCount websites'),
            ),
            OutlinedButton.icon(
              onPressed: _working ? null : () => _chatEverywhere(true),
              icon: const Icon(Icons.public, size: 18),
              label: Text('Turn it on on all $_siteCount'),
            ),
          ],
        ),
        const SizedBox(height: 4),
        Text(
          'Every website this company owns, including the ones that answer for '
          'themselves rather than following the primary.',
          style: theme.textTheme.bodySmall,
        ),
        const SizedBox(height: 8),
      ],
      ],
    );
  }

  /// Switch the chat for every site of this company, and reload.
  ///
  /// Reloaded rather than patched locally: the answer covers sites this screen
  /// is not showing, and a pane that said "off" while another site's row said
  /// otherwise is the confusion this button exists to end.
  Future<void> _chatEverywhere(bool enabled) async {
    setState(() {
      _working = true;
      _saved = null;
      _error = null;
    });

    final response = await widget.api.post(
      '/web/sites/${widget.siteId}/widgets/chat',
      {'enabled': enabled, 'scope': 'company'},
    );

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _working = false;
        _error = response.message;
      });

      return;
    }

    final changed = response.body['changed'];

    setState(() {
      _working = false;
      _config = Map<String, dynamic>.from(
        response.body['config'] as Map? ?? const {},
      );
      _saved = enabled
          ? 'The chat is on again on $changed websites.'
          : 'The chat is off on $changed websites. Their pages update within '
                'a few minutes.';
    });
  }

  /// Ask every site of this company to throw away its cached pages.
  ///
  /// ── What this promises, and what it cannot ──
  ///
  /// EzBizi cannot reach into a customer's website. The plugin PULLS every
  /// five minutes and, since 1.13.0, clears the site's page cache whenever
  /// what it pulled differs from what it had — so a saved change already
  /// reaches visitors on its own. This guarantees the next pull counts as a
  /// change, which matters when the cached page is stale for a reason EzBizi
  /// never saw.
  ///
  /// The wording says "in the next few minutes" rather than "cleared". This
  /// side cannot observe a customer's cache and must not claim to.
  Future<void> _refreshEverywhere() async {
    setState(() {
      _working = true;
      _saved = null;
      _error = null;
    });

    final response = await widget.api.post(
      '/web/sites/${widget.siteId}/widgets/refresh',
      {'scope': _siteCount > 1 ? 'company' : 'site'},
    );

    if (!mounted) return;

    setState(() {
      _working = false;

      if (!response.ok) {
        _error = response.message;

        return;
      }

      final asked = response.body['asked'];

      _saved = asked == 1
          ? 'Asked this website to rebuild its pages. It updates within a few '
                'minutes.'
          : 'Asked all $asked websites to rebuild their pages. They update '
                'within a few minutes.';
    });
  }

  /// One rendered widget's presets, dressed by name.
  ///
  /// ── Why a name is the only shortcode argument ──
  ///
  /// Every other setting on this screen reaches 110 websites because nobody
  /// types it into a page. These four cannot be one appearance each — a row of
  /// small round icons belongs in a footer and big labelled ones belong in a
  /// contact panel, on the same site — so the name is the argument and the
  /// values behind it still live here. `WidgetPresets` carries the whole of
  /// that argument and why it ends this way.
  ///
  /// One widget at a time now, rather than all four in a column: the card that
  /// opened this is the choice the column used to ask for by scrolling.
  List<Widget> _renderedFields(ThemeData theme, _Rendered rendered) {
    final stored = Map<String, dynamic>.from(_config['presets'] as Map? ?? {});

    return [
      Text(
        'Paste the shortcode where it belongs on the page; everything about '
        'how it looks is set here. A page that needs a different look asks '
        'for a preset by name, and a name nobody has made renders the '
        'default rather than nothing. One widget can also change any option '
        'for itself — in the shortcode, like size="40", or under "This '
        'widget only" in Elementor — and keep the rest of its preset.',
        style: theme.textTheme.bodySmall,
      ),
      const SizedBox(height: 12),
      _PresetCard(
        rendered: rendered,
        schema: Map<String, dynamic>.from(
          _schema[rendered.key] as Map? ?? const {},
        ),
        presets: Map<String, dynamic>.from(
          stored[rendered.key] as Map? ?? const {},
        ),
        canEdit: widget.canEdit,
        onChanged: (named) => setState(() {
          final all = Map<String, dynamic>.from(_config['presets'] as Map? ?? {});
          all[rendered.key] = named;
          _config['presets'] = all;
          _saved = null;
        }),
      ),
      // The list the widget draws, kept here rather than on a tab of its
      // own: it is only ever looked for by somebody looking at this widget.
      if (_listKinds[rendered.key] case final kind?)
        SiteListEditor(
          key: ValueKey('list-${rendered.key}-${widget.siteId}'),
          api: widget.api,
          siteId: widget.siteId,
          canEdit: widget.canEdit,
          kind: kind,
        ),
    ];
  }

  static const Map<String, SiteListKind> _listKinds = {
    'team': SiteListKind.team,
    'branches': SiteListKind.branches,
    'store_locator': SiteListKind.stores,
    'services': SiteListKind.services,
    'reviews': SiteListKind.reviews,
    'before_after': SiteListKind.beforeAfter,
    'faq': SiteListKind.faq,
  };

  /// The widgets as a visitor meets them, drawn from the values being edited.
  ///
  /// ── What it is honest about ──
  ///
  /// It cannot be pixel-exact and does not pretend to be: the customer's own
  /// theme supplies the typeface, the page background and the spacing around
  /// anything embedded in it, and none of that is ours to know. What it IS
  /// exact about is every value on this screen — the colours, the corner
  /// radius, the wording, and the questions in the order the form asks them.
  /// Those are the things somebody is changing while they look at it.
  ///
  /// ── Why a switched-off chat is the one thing it will not draw ──
  ///
  /// Everything else on this screen is dressing, and dressing a widget before
  /// turning it on is the ordinary order of doing this — so the wording fields
  /// stay live and this pane keeps following them. The switch is different in
  /// kind: it is a claim about what is on the website, and a pane that drew a
  /// bubble for a site with no bubble would be the only thing here that lied.
  /// So the chat goes, and the space says why.
  Widget _preview(ThemeData theme) {
    final chat = _section('chat');
    final form = _section('form');
    final style = _section('style');

    final accent = _colour(style['accent']) ?? theme.colorScheme.primary;
    final onAccent = _colour(style['on_accent']) ?? theme.colorScheme.onPrimary;
    final text = _colour(style['text']) ?? theme.colorScheme.onSurface;
    final surface = _colour(style['background']) ?? theme.colorScheme.surface;
    final radius =
        (((style['radius'] as num?) ?? 12).toDouble()).clamp(0.0, 40.0);

    return ListView(
      key: const ValueKey('tour-widgets-preview'),
      padding: const EdgeInsets.fromLTRB(16, 20, 16, 20),
      children: [
        Text('Preview', style: theme.textTheme.titleSmall),
        const SizedBox(height: 4),
        Text(
          'Your colours, wording and questions, exactly as set. The typeface '
          'and the page around them come from your own website.',
          style: theme.textTheme.bodySmall,
        ),
        const SizedBox(height: 16),
        _previewHeading(theme, _catalogue.firstWhere((s) => s.key == _focus).label),
        const SizedBox(height: 8),
        ..._previewFor(theme, chat, form, accent, onAccent, text, surface, radius),
      ],
    );
  }

  /// The preview of whichever widget is open, and only that one.
  ///
  /// Jose, 2026-09-23: *"so i dont see all the previews at once on the right
  /// side"*. The pane used to draw the chat and the form together and nothing
  /// at all for the other four, which made the right-hand side simultaneously
  /// too much and too little.
  ///
  /// EVERY widget answers here, including the four rendered ones that had no
  /// preview before. Those are markup the PLUGIN writes on a page we do not
  /// serve, so what is drawn is an approximation of shape and dressing rather
  /// than a screenshot — the same honesty the pane has always claimed, applied
  /// to four more things. What it IS exact about is the values on this screen:
  /// the colours, the corner radius, the icon size, the column count.
  List<Widget> _previewFor(
    ThemeData theme,
    Map<String, dynamic> chat,
    Map<String, dynamic> form,
    Color accent,
    Color onAccent,
    Color text,
    Color surface,
    double radius,
  ) {
    switch (_focus) {
      case 'chat':
        // The chat's own colours, each falling back as the plugin does (Jose,
        // 2026-10-05: the preview stayed orange after a change to blue).
        final headBg = _colour(chat['header_bg']) ?? accent;
        final headInk = _colour(chat['header_color']) ?? onAccent;
        final bubbleBg = _colour(chat['bubble_bg']) ?? accent;
        final bubbleInk = _colour(chat['bubble_color']) ?? onAccent;
        final youBg = _colour(chat['visitor_bg']) ?? accent;
        final themBg = _colour(chat['business_bg']) ?? const Color(0xFFF1F3F5);
        return [
        if (chat['enabled'] == false)
          _mockPage(
            theme,
            child: Text(
              'No chat on this website. The bubble is not placed on any page, '
              'so there is nothing here for a visitor to open.',
              style: theme.textTheme.bodySmall,
            ),
          ),
        if (chat['enabled'] != false) _mockPage(
          theme,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              // What a visitor meets first: the coloured header, then the
              // three questions asked BEFORE the conversation. The message
              // thread is only reached after those, so previewing the thread
              // would show the screen almost nobody sees first.
              _mockPanel(
                surface: surface,
                radius: radius,
                padding: EdgeInsets.zero,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Container(
                      width: double.infinity,
                      padding: const EdgeInsets.fromLTRB(12, 10, 12, 10),
                      color: headBg,
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            _line(chat['title']),
                            style: TextStyle(
                              color: headInk,
                              fontWeight: FontWeight.w700,
                              fontSize: 14,
                            ),
                          ),
                          if (_line(chat['intro']).isNotEmpty)
                            Text(
                              _line(chat['intro']),
                              style: TextStyle(
                                color: headInk.withValues(alpha: 0.9),
                                fontSize: 12,
                              ),
                            ),
                        ],
                      ),
                    ),
                    Padding(
                      padding: const EdgeInsets.all(12),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          if (_line(chat['lead_in']).isNotEmpty) ...[
                            Text(
                              _line(chat['lead_in']),
                              style: TextStyle(
                                color: text.withValues(alpha: 0.85),
                                fontSize: 12,
                              ),
                            ),
                            const SizedBox(height: 10),
                          ],
                          for (final ask in [
                            chat['name_label'],
                            // Fixed since 2026-10-01: each its own box, for Leads.
                            'Phone',
                            'ZIP code',
                            'Email',
                            chat['message_label'],
                          ]) ...[
                            Text(
                              '${_line(ask)} *',
                              style: TextStyle(
                                color: text,
                                fontSize: 12,
                                fontWeight: FontWeight.w600,
                              ),
                            ),
                            const SizedBox(height: 4),
                            _mockInput('', radius, text),
                            const SizedBox(height: 10),
                          ],
                          SizedBox(
                            width: double.infinity,
                            child: _mockButton(
                              _line(chat['start']),
                              _colour(chat['start_bg']) ?? accent,
                              onAccent,
                              radius,
                            ),
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
              // What happens the instant they press it. Previewed because it
              // is the only message on the thread nobody will proof-read
              // before a stranger reads it.
              if (_line(chat['auto_reply']).isNotEmpty) ...[
                const SizedBox(height: 8),
                _mockPanel(
                  surface: surface,
                  radius: radius,
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Align(
                        alignment: Alignment.centerRight,
                        child: _mockBubble(
                          'Hello, I need…',
                          youBg,
                          onAccent,
                          radius,
                        ),
                      ),
                      const SizedBox(height: 6),
                      _mockBubble(
                        _line(chat['auto_reply']),
                        themBg,
                        const Color(0xFF1B1B1B),
                        radius,
                      ),
                    ],
                  ),
                ),
              ],

              const SizedBox(height: 8),
              // The closed bubble, which is what most visitors ever see of it.
              _mockButton(_line(chat['button']), bubbleBg, bubbleInk, radius),
            ],
          ),
        ),
        ];
      case 'form':
        return [
        _mockPage(
          theme,
          child: _mockPanel(
            surface: surface,
            radius: radius,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                for (final field in (form['fields'] as List? ?? const []))
                  if (field is Map && _line(field['label']).isNotEmpty) ...[
                    Padding(
                      padding: const EdgeInsets.only(bottom: 4),
                      child: Text(
                        _line(field['label']) +
                            (field['required'] == true ? ' *' : ''),
                        style: TextStyle(color: text, fontSize: 12),
                      ),
                    ),
                    _mockInput(
                      '',
                      radius,
                      text,
                      // A textarea is taller, which changes how long the whole
                      // form looks — the thing being judged when a question is
                      // added.
                      tall: field['type'] == 'textarea',
                    ),
                    const SizedBox(height: 10),
                  ],
                Align(
                  alignment: Alignment.centerLeft,
                  child: _mockButton(
                    _line(form['button']),
                    accent,
                    onAccent,
                    radius,
                  ),
                ),
              ],
            ),
          ),
        ),
        ];
      case 'look':
        return _lookPreview(theme, accent, onAccent, text, surface, radius);
      default:
        return [
          _mockPage(
            theme,
            child: renderedPreview(
              key: _focus!,
              theme: theme,
              options: _previewOptions(_focus!),
              accent: accent,
              onAccent: onAccent,
              text: text,
              surface: surface,
              radius: radius,
            ),
          ),
          const SizedBox(height: 8),
          Text(
            'Drawn from the DEFAULT preset. A page that asks for one by name '
            'gets that one instead.',
            style: theme.textTheme.bodySmall,
          ),
        ];
    }
  }

  /// The default preset's values for one rendered widget, falling back to the
  /// catalogue's own defaults for anything nobody has set.
  Map<String, dynamic> _previewOptions(String key) {
    final rendered = _renderedCatalogue.firstWhere((r) => r.key == key);
    final named = Map<String, dynamic>.from(
      (_config['presets'] as Map? ?? const {})[key] as Map? ?? const {},
    );
    final stored = Map<String, dynamic>.from(
      named['default'] as Map? ?? const {},
    );

    return {
      ...stored,
      for (final knob in rendered.knobs) knob.key: stored[knob.key] ?? knob.fallbackValue,
    };
  }

  /// Look has no widget of its own, so it previews what it CHANGES: the same
  /// dressing every other widget wears, on one panel and one button.
  List<Widget> _lookPreview(
    ThemeData theme,
    Color accent,
    Color onAccent,
    Color text,
    Color surface,
    double radius,
  ) => [
    _mockPage(
      theme,
      child: _mockPanel(
        surface: surface,
        radius: radius,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              'A panel, in your colours',
              style: TextStyle(color: text, fontSize: 13, fontWeight: FontWeight.w700),
            ),
            const SizedBox(height: 6),
            Text(
              'Body text sits on the background colour, and the corner radius '
              'below is the one every widget uses.',
              style: TextStyle(color: text.withValues(alpha: 0.85), fontSize: 12),
            ),
            const SizedBox(height: 10),
            _mockInput('A box somebody types in', radius, text),
            const SizedBox(height: 10),
            SizedBox(
              width: double.infinity,
              child: _mockButton('A button', accent, onAccent, radius),
            ),
          ],
        ),
      ),
    ),
    const SizedBox(height: 8),
    Text(
      'Every widget wears this. Changing it here moves the chat, the form and '
      'the four rendered widgets together.',
      style: theme.textTheme.bodySmall,
    ),
  ];

  static String _line(Object? value) => '${value ?? ''}';

  /// Just the section name.
  ///
  /// It used to carry "off on your site" beside it, and that phrase is what
  /// made the old switches dangerous: every site was switched off, so every
  /// site showed nothing, and the only notice of it was grey text in a preview
  /// that reads as styling. The chat's switch came back on 2026-09-18 and this
  /// stayed a heading — an off chat is said in the space where the chat would
  /// have been, which is the part nobody mistakes for decoration.
  Widget _previewHeading(ThemeData theme, String label) {
    return Text(label, style: theme.textTheme.bodyMedium);
  }

  /// A stand-in for the customer's page, so the widget is not judged against
  /// EzBizi's own background — the one background it will never be on.
  Widget _mockPage(ThemeData theme, {required Widget child}) {
    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: theme.colorScheme.surfaceContainerHighest,
        borderRadius: BorderRadius.circular(8),
      ),
      child: child,
    );
  }

  /// One message, leaning the way it came from.
  Widget _mockBubble(String text, Color fill, Color ink, double radius) {
    return Container(
      constraints: const BoxConstraints(maxWidth: 240),
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 7),
      decoration: BoxDecoration(
        color: fill,
        borderRadius: BorderRadius.circular(radius + 2),
      ),
      child: Text(text, style: TextStyle(color: ink, fontSize: 12)),
    );
  }

  Widget _mockPanel({
    required Color surface,
    required double radius,
    required Widget child,
    // Zero for a panel that paints its own header to the edges, which cannot
    // happen while the padding is on the outside.
    EdgeInsets padding = const EdgeInsets.all(12),
  }) {
    return Container(
      padding: padding,
      clipBehavior: Clip.antiAlias,
      decoration: BoxDecoration(
        color: surface,
        borderRadius: BorderRadius.circular(radius),
        border: Border.all(color: const Color(0x22000000)),
      ),
      child: child,
    );
  }

  Widget _mockButton(
    String label,
    Color accent,
    Color onAccent,
    double radius,
  ) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
      decoration: BoxDecoration(
        color: accent,
        borderRadius: BorderRadius.circular(radius),
      ),
      child: Text(
        label,
        style: TextStyle(
          color: onAccent,
          fontSize: 13,
          fontWeight: FontWeight.w600,
        ),
      ),
    );
  }

  Widget _mockInput(
    String placeholder,
    double radius,
    Color text, {
    bool tall = false,
  }) {
    return Container(
      height: tall ? 52 : 30,
      alignment: Alignment.topLeft,
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 6),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular((radius - 6).clamp(0.0, 40.0)),
        border: Border.all(color: const Color(0x33000000)),
      ),
      child: Text(
        placeholder,
        style: TextStyle(color: text.withValues(alpha: 0.45), fontSize: 12),
      ),
    );
  }

  /// What a visitor will actually see: [fg] rendered on [bg], with the WCAG
  /// contrast of the pair beside it.
  ///
  /// Nothing else on this screen shows the colours rendered, so the two boxes
  /// above are picked blind — and the pair that matters is a *relationship*,
  /// which no single field can be wrong about on its own.
  ///
  /// Derived here and never stored. The ratio is a fact about the two values
  /// beside it, so a saved copy is a copy that can disagree with them.
  ///
  /// **Warn, never block.** A customer's brand may genuinely be low-contrast,
  /// and this is their website, not ours. Saying what a visitor will see is
  /// this screen's job; overruling them is not.
  Widget _pair(ThemeData theme, Object? fg, Object? bg, String what) {
    final f = _colour(fg), b = _colour(bg);
    // Mid-typing (`#0b5`, `#`, empty) is not a finding. Say nothing until both
    // boxes hold a colour the server would actually keep.
    if (f == null || b == null) return const SizedBox.shrink();

    final lf = f.computeLuminance(), lb = b.computeLuminance();
    final ratio = ((lf > lb ? lf : lb) + 0.05) / ((lf < lb ? lf : lb) + 0.05);
    final low = ratio < 4.5;

    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.center,
        children: [
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
            decoration: BoxDecoration(
              color: b,
              borderRadius: BorderRadius.circular(6),
              // The swatch has to read as a swatch even when the colour it
              // carries is the page's own, or a white-on-white pair shows as
              // nothing at all and looks like a rendering fault.
              border: Border.all(color: theme.dividerColor),
            ),
            child: Text('Send', style: TextStyle(fontSize: 13, color: f)),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              low
                  ? 'Contrast against $what is ${ratio.toStringAsFixed(1)}:1. '
                      'Visitors may not be able to read this; 4.5:1 or more is '
                      'the usual floor.'
                  : 'Contrast against $what is ${ratio.toStringAsFixed(1)}:1.',
              style: theme.textTheme.bodySmall?.copyWith(
                color: low ? theme.colorScheme.error : null,
              ),
            ),
          ),
        ],
      ),
    );
  }

  /// Seven colours a customer can pick instead of typing one.
  ///
  /// ── Why each carries its own text colour ──
  ///
  /// Picking sets BOTH halves of the pair. If it set only the accent, the old
  /// text colour would stay and the swatch below could warn about a
  /// combination we had just offered — which is the failure the presets exist
  /// to prevent.
  ///
  /// ── Why the pairs are a table and not a calculation ──
  ///
  /// The server derives text colour by luminance, and a second copy of that
  /// arithmetic here is a second definition of readable that drifts the day the
  /// threshold moves. Seven measured pairs are data, not an algorithm. Every
  /// one clears 4.5:1 against its own text — computed rather than assumed,
  /// tightest #00838f at 4.64:1.
  ///
  /// **#00838f stays whatever else goes.** It is the colour that exposed the
  /// near-black bug in the server's derivation — 4.18:1 against #111111 and
  /// 4.64:1 against #000000 — so this list is incidentally a regression suite
  /// for that constant. Chosen and measured by the UI/UX session, who keep the
  /// same seven in the organization colour picker.
  static const List<(String, String)> _swatches = [
    ('#00838f', '#000000'),
    ('#0b5cad', '#ffffff'),
    ('#b3261e', '#ffffff'),
    ('#2e7d32', '#ffffff'),
    ('#6a1b9a', '#ffffff'),
    ('#e65100', '#000000'),
    ('#37474f', '#ffffff'),
  ];

  Widget _presets(ThemeData theme) {
    if (!widget.canEdit) return const SizedBox.shrink();

    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: Wrap(
        spacing: 8,
        runSpacing: 8,
        crossAxisAlignment: WrapCrossAlignment.center,
        children: [
          Text('or pick', style: theme.textTheme.bodySmall),
          for (final (accent, onAccent) in _swatches)
            Tooltip(
              message: accent,
              child: InkWell(
                onTap: () => setState(() {
                  final part = _section('style');
                  part['accent'] = accent;
                  part['on_accent'] = onAccent;
                  _config['style'] = part;
                  _saved = null;
                  _external++;
                }),
                borderRadius: BorderRadius.circular(4),
                child: Container(
                  width: 26,
                  height: 26,
                  decoration: BoxDecoration(
                    color: _colour(accent),
                    borderRadius: BorderRadius.circular(4),
                    // Outlined, so a swatch reads as a swatch against any
                    // background rather than dissolving into the card.
                    border: Border.all(color: theme.colorScheme.outlineVariant),
                  ),
                  child: Center(
                    child: Text(
                      'A',
                      style: TextStyle(
                        color: _colour(onAccent),
                        fontSize: 13,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                  ),
                ),
              ),
            ),
        ],
      ),
    );
  }

  /// Both hex forms the server keeps (`#rgb` and `#rrggbb`), so a colour that
  /// saves is a colour that gets checked — see `WidgetDefaults::colour`.
  static Color? _colour(Object? v) {
    final m = RegExp(r'^#([0-9a-f]{3}|[0-9a-f]{6})$')
        .firstMatch('${v ?? ''}'.trim().toLowerCase());
    if (m == null) return null;
    final h = m.group(1)!;
    final full = h.length == 3 ? h.split('').map((c) => '$c$c').join() : h;
    return Color(int.parse('ff$full', radix: 16));
  }

  Widget _text(
    ThemeData theme,
    String label,
    Object? value,
    void Function(String) onChanged, {
    int lines = 1,
  }) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: TextFormField(
        // Keyed by label so a site swap rebuilds rather than keeping the
        // previous site's text in the box.
        key: ValueKey('$label:${widget.siteId}:$_external'),
        initialValue: '${value ?? ''}',
        enabled: widget.canEdit,
        minLines: lines,
        maxLines: lines,
        style: theme.textTheme.bodyMedium,
        decoration: InputDecoration(labelText: label, isDense: true),
        onChanged: onChanged,
      ),
    );
  }

  /// A colour: typed, or picked in place (Jose, 2026-10-01).
  Widget _colourBox(String label, Object? value, void Function(String) onChanged,
          {String hint = '#rrggbb, empty uses the default'}) =>
      ColourField(
        // Keyed like the text boxes, so a site swap shows that site's colour.
        key: ValueKey('$label:${widget.siteId}:$_external'),
        label: label,
        value: '${value ?? ''}',
        enabled: widget.canEdit,
        hint: hint,
        onChanged: onChanged,
      );

  Widget _number(ThemeData theme, String label, Object? value, int fallback,
          void Function(int) onChanged) =>
      _text(theme, label, value ?? fallback,
          (v) => onChanged(int.tryParse(v.trim()) ?? fallback));

  Widget _flag(ThemeData theme, String label, bool value,
          void Function(bool) onChanged) =>
      SwitchListTile(
        contentPadding: EdgeInsets.zero,
        dense: true,
        value: value,
        onChanged: widget.canEdit ? onChanged : null,
        title: Text(label, style: theme.textTheme.bodyMedium),
      );

  Widget _dropdown(
    ThemeData theme,
    String label,
    String value,
    List<String> options,
    void Function(String) onChanged,
  ) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: SearchPickerField<String>(
               value: options.contains(value) ? value : options.first,
               label: label,
               isDense: true,
               options: [
          for (final option in options)
            SearchOption(value: option, label: option),
        ],
               onChanged: widget.canEdit
            ? (chosen) => chosen == null ? null : onChanged(chosen)
            : null,
             ),
    );
  }
}

/// The questions the form asks, in the order it asks them.
/// One question on the contact form.
///
/// It owns its controller rather than being redrawn from a map of strings, and
/// that is the fix for a bug Jose photographed: typing "First name" left "Fi"
/// in the box and the rest somewhere else.
///
/// The row used to be a `TextFormField(initialValue:)` whose KEY contained its
/// own text — `ValueKey('label:$i:${fields[i]['label']}')`. Every keystroke
/// wrote the text back to the parent, the parent rebuilt, the key was now a
/// different key, so Flutter threw the field away and built a new one. Focus
/// died with it, once per character.
///
/// Dropping the value from the key alone would have brought back the older bug
/// it was put there to fix: keyed on the index, deleting row 0 shifts row 1 up
/// and the surviving State keeps the deleted row's text. Text that follows a
/// controller cannot do either, and a key made once per row keeps focus with
/// the row it belongs to. Same shape as `_ConditionRow` in the automation
/// builder, for the same reason.
///
/// Guarded by "focus stays in the question being typed into" in
/// web_widgets_test.dart. Four earlier attempts could not fail against the old
/// shape; that one does, because it asks where FOCUS is after a keystroke
/// rather than what the widget looks like — which is the thing the bug
/// destroys.
class _QuestionRow {
  _QuestionRow({
    String label = '',
    this.type = 'text',
    this.required = false,
    this.width = 100,
    String placeholder = '',
    String help = '',
    String options = '',
    String value = '',
    this.showIfField = '',
    String showIfValue = '',
  })  : label = TextEditingController(text: label),
        placeholder = TextEditingController(text: placeholder),
        help = TextEditingController(text: help),
        options = TextEditingController(text: options),
        value = TextEditingController(text: value),
        showIfValue = TextEditingController(text: showIfValue);

  /// Shown only when this question (by its wording) is answered with
  /// [showIfValue], or answered at all when that is empty (Toula).
  String showIfField;
  final TextEditingController showIfValue;

  final TextEditingController label;
  final TextEditingController placeholder;
  final TextEditingController help;

  /// One choice per line, for a select, radio or checkbox question.
  final TextEditingController options;

  /// What a hidden question always sends.
  final TextEditingController value;
  final Key key = UniqueKey();

  String type;
  bool required;
  int width;
  bool open = false;

  /// Everything the question carries. Dropping a key here is what used to
  /// wipe a select's options on the next save, silently.
  Map<String, dynamic> toJson() => {
    'label': label.text,
    'type': type,
    'required': required,
    'width': width,
    'placeholder': placeholder.text,
    'help': help.text,
    if (const ['select', 'radio', 'checkbox'].contains(type))
      'options': [
        for (final line in options.text.split('\n'))
          if (line.trim().isNotEmpty) line.trim(),
      ],
    if (type == 'hidden') 'value': value.text,
    if (showIfField.isNotEmpty)
      'show_if': {'field': showIfField, 'value': showIfValue.text},
  };

  void dispose() {
    showIfValue.dispose();
    label.dispose();
    placeholder.dispose();
    help.dispose();
    options.dispose();
    value.dispose();
  }
}

class _FieldList extends StatefulWidget {
  const _FieldList({
    required this.fields,
    required this.types,
    required this.canEdit,
    required this.onChanged,
  });

  final List<Map<String, dynamic>> fields;
  final List<String> types;
  final bool canEdit;
  final void Function(List<Map<String, dynamic>>) onChanged;

  @override
  State<_FieldList> createState() => _FieldListState();
}

class _FieldListState extends State<_FieldList> {
  late List<_QuestionRow> _rows;

  @override
  void initState() {
    super.initState();
    _rows = _rowsFrom(widget.fields);
  }

  @override
  void didUpdateWidget(covariant _FieldList old) {
    super.didUpdateWidget(old);

    /*
     * Rebuild the rows only when the LIST changes underneath us — a different
     * site chosen in the rail, or a save coming back. Not on every keystroke:
     * the parent is told about each one, so its `fields` differ from ours
     * constantly, and rebuilding then would throw away the controller somebody
     * is typing into, which is the bug this class exists to fix.
     */
    if (widget.fields.length != _rows.length) {
      for (final row in _rows) {
        row.dispose();
      }

      _rows = _rowsFrom(widget.fields);
    }
  }

  @override
  void dispose() {
    for (final row in _rows) {
      row.dispose();
    }
    super.dispose();
  }

  List<_QuestionRow> _rowsFrom(List<Map<String, dynamic>> fields) => [
    for (final field in fields)
      _QuestionRow(
        label: '${field['label'] ?? ''}',
        type: widget.types.contains(field['type'])
            ? '${field['type']}'
            : widget.types.first,
        required: field['required'] == true,
        width: (field['width'] as num?)?.toInt() ?? 100,
        placeholder: '${field['placeholder'] ?? ''}',
        help: '${field['help'] ?? ''}',
        options: (field['options'] as List? ?? const []).join('\n'),
        value: '${field['value'] ?? ''}',
        showIfField: '${(field['show_if'] as Map?)?['field'] ?? ''}',
        showIfValue: '${(field['show_if'] as Map?)?['value'] ?? ''}',
      ),
  ];

  /// What the rows currently say, handed up as the plain list the API takes.
  void _publish() => widget.onChanged([for (final row in _rows) row.toJson()]);

  /// The rest of one question: required, width, placeholder, help, its
  /// choices or its hidden value. Folded, because most questions need none.
  Widget _more(ThemeData theme, _QuestionRow row) {
    InputDecoration box(String label, {String? hint}) =>
        InputDecoration(labelText: label, hintText: hint, isDense: true);

    return Container(
      key: ValueKey('more-${row.key}'),
      margin: const EdgeInsets.only(bottom: 12),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        border: Border.all(color: theme.colorScheme.outlineVariant),
        borderRadius: BorderRadius.circular(8),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            row.label.text.isEmpty ? 'This question' : row.label.text,
            style: theme.textTheme.bodySmall,
          ),
          SwitchListTile(
            contentPadding: EdgeInsets.zero,
            dense: true,
            title: const Text('Required'),
            value: row.required,
            onChanged: widget.canEdit
                ? (on) {
                    setState(() => row.required = on);
                    _publish();
                  }
                : null,
          ),
          SearchPickerField<int>(
            value: row.width,
            label: 'Width on a wide screen',
            isDense: true,
            options: [
              for (final w in const [100, 75, 66, 50, 33, 25])
                SearchOption(value: w, label: '$w%'),
            ],
            onChanged: widget.canEdit
                ? (w) {
                    if (w == null) return;
                    setState(() => row.width = w);
                    _publish();
                  }
                : null,
          ),
          const SizedBox(height: 8),
          TextField(
            controller: row.placeholder,
            enabled: widget.canEdit,
            decoration: box('Placeholder', hint: 'Shown in the empty box'),
            onChanged: (_) => _publish(),
          ),
          const SizedBox(height: 8),
          TextField(
            controller: row.help,
            enabled: widget.canEdit,
            decoration: box('Help text', hint: 'A line under the box'),
            onChanged: (_) => _publish(),
          ),
          if (const ['select', 'radio', 'checkbox'].contains(row.type)) ...[
            const SizedBox(height: 8),
            TextField(
              controller: row.options,
              enabled: widget.canEdit,
              minLines: 3,
              maxLines: 8,
              decoration: box('Choices, one per line'),
              onChanged: (_) => _publish(),
            ),
          ],
          if (row.type == 'hidden') ...[
            const SizedBox(height: 8),
            TextField(
              controller: row.value,
              enabled: widget.canEdit,
              decoration: box('Value it always sends'),
              onChanged: (_) => _publish(),
            ),
          ],
          const SizedBox(height: 8),
          SearchPickerField<String>(
            key: ValueKey('show-if-${row.key}'),
            value: row.showIfField,
            label: 'Show only when',
            isDense: true,
            options: [
              const SearchOption(value: '', label: 'Always shown'),
              for (final other in _rows)
                if (other != row && other.label.text.trim().isNotEmpty)
                  SearchOption(
                    value: other.label.text.trim(),
                    label: other.label.text.trim(),
                  ),
            ],
            onChanged: widget.canEdit
                ? (field) {
                    setState(() => row.showIfField = field ?? '');
                    _publish();
                  }
                : null,
          ),
          if (row.showIfField.isNotEmpty) ...[
            const SizedBox(height: 8),
            TextField(
              controller: row.showIfValue,
              enabled: widget.canEdit,
              decoration: box('…is answered',
                  hint: 'e.g. Chimney — empty means any answer'),
              onChanged: (_) => _publish(),
            ),
          ],
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text('Questions', style: theme.textTheme.bodyMedium),
        const SizedBox(height: 4),
        Text(
          'Name, Email and Phone are recognised and fill in the enquirer\u2019s '
          'details. Anything else becomes a line of the message.',
          style: theme.textTheme.bodySmall,
        ),
        const SizedBox(height: 8),
        for (final row in _rows)
          Padding(
            key: row.key,
            padding: const EdgeInsets.only(bottom: 8),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Expanded(
                  flex: 3,
                  child: TextField(
                    controller: row.label,
                    enabled: widget.canEdit,
                    decoration: const InputDecoration(
                      labelText: 'Question',
                      isDense: true,
                    ),
                    // No setState: the controller already holds the text, and
                    // rebuilding on every keystroke is what broke this.
                    onChanged: (_) => _publish(),
                  ),
                ),
                const SizedBox(width: 8),
                Expanded(
                  flex: 2,
                  child: SearchPickerField<String>(
                    // Its chosen label ellipsises rather than pushing the row
                    // wider, which is what the dropdown's isExpanded was for.
                    value: row.type,
                    label: 'Answer',
                    isDense: true,
                    options: [
                      for (final type in widget.types)
                        SearchOption(value: type, label: type),
                    ],
                    onChanged: widget.canEdit
                        ? (value) {
                            if (value == null) return;

                            setState(() => row.type = value);
                            _publish();
                          }
                        : null,
                  ),
                ),
                IconButton(
                  tooltip: row.open ? 'Fewer settings' : 'More settings',
                  icon: Icon(row.open ? Icons.expand_less : Icons.tune, size: 18),
                  onPressed: () => setState(() => row.open = !row.open),
                ),
                IconButton(
                  tooltip: 'Remove',
                  icon: const Icon(Icons.close, size: 18),
                  onPressed: widget.canEdit
                      ? () {
                          setState(() {
                            _rows.remove(row);
                            row.dispose();
                          });
                          _publish();
                        }
                      : null,
                ),
              ],
            ),
          ),
        for (final row in _rows)
          if (row.open) _more(theme, row),
        if (widget.canEdit)
          Align(
            alignment: Alignment.centerLeft,
            child: TextButton.icon(
              onPressed: () {
                setState(() => _rows.add(_QuestionRow()));
                _publish();
              },
              icon: const Icon(Icons.add, size: 18),
              label: const Text('Add a question'),
            ),
          ),
      ],
    );
  }
}

// ── Rendered widgets: presets ──────────────────────────────────────

/// The preset every widget has and nobody may take away.
///
/// A live page must not go blank over a typo: `preset="foter"` falls back to
/// this one, so `WidgetPresets::resolve` guarantees it exists whatever is
/// stored. A screen that offered to delete or rename it would be offering to
/// remove the thing that catches the typo — the server would put it straight
/// back, which is worse than not offering.
const String _defaultPreset = 'default';

/// One option on one widget.
sealed class _Knob {
  const _Knob(this.key, this.label);

  final String key;
  final String label;

  /// What this knob is worth when nobody has set it.
  ///
  /// Each subclass already carried its own default under a different name —
  /// the first option, `fallback`, `fallback` again — and the preview needs
  /// one question it can ask any knob. The values are unchanged; this only
  /// gives them a single name to be asked for by.
  Object get fallbackValue => switch (this) {
    _Choice(:final options) => options.first,
    _Number(:final fallback) => fallback,
    _Flag(:final fallback) => fallback,
    _Colour() => '',
    // Empty, and the plugin reads empty as "use the wording that matches the
    // action" — so a site that says nothing still gets a sentence.
    _Text() => '',
  };
}

class _Choice extends _Knob {
  const _Choice(super.key, super.label, this.options);

  /// The server's default FIRST, because that is what an unrecognised stored
  /// value falls back to there and this has to agree with it.
  final List<String> options;
}

class _Number extends _Knob {
  const _Number(
    super.key,
    super.label,
    this.min,
    this.max,
    this.fallback, {
    this.step = 1,
    this.suffix = '',
  });

  final int min;
  final int max;
  final int fallback;

  /// What one notch of the slider moves. Height runs 120–900, and 780 notches
  /// is a control nobody can land on a round number with.
  final int step;
  final String suffix;

  int read(Object? raw) {
    final value = raw is num ? raw.round() : int.tryParse('$raw') ?? fallback;

    return value.clamp(min, max);
  }

  int snap(double raw) =>
      (((raw - min) / step).round() * step + min).clamp(min, max);
}

class _Flag extends _Knob {
  const _Flag(super.key, super.label, this.fallback);

  final bool fallback;
}

/// A colour as #rrggbb, or empty for the website's own.
class _Colour extends _Knob {
  const _Colour(super.key, super.label);
}


/// An option somebody types.
///
/// The three above cover presentation — which arrangement, how big, on or off
/// — and that was all the first four widgets needed. A call to action needs
/// WORDS: A1's bar says "CALL US TODAY" and Everdoors' popup says something
/// else entirely, and neither is a choice from a list.
///
/// `max` matches `WidgetPresets::TEXT` on the server, which truncates. Two
/// numbers that must agree is worse than one, but the alternative is a box
/// that accepts eighty characters and silently stores sixty.
class _Text extends _Knob {
  const _Text(super.key, super.label, {this.hint = '', this.max = 80});

  final String hint;
  final int max;
}

/// One widget that renders where somebody puts it.
/// One tile on the widgets grid.
class _WidgetCardSpec {
  const _WidgetCardSpec(this.key, this.label, this.blurb, this.icon);

  final String key;
  final String label;
  final String blurb;
  final IconData icon;
}

/// An icon per rendered widget, kept here rather than in `_renderedCatalogue`
/// because that list is a hand copy of the SERVER's preset catalogue and
/// nothing on the server has an opinion about icons.
const Map<String, IconData> _renderedIcons = {
  'social_links': Icons.share_outlined,
  'city_list': Icons.location_city_outlined,
  'hours_table': Icons.schedule_outlined,
  'google_map': Icons.map_outlined,
  'contact_info': Icons.contact_page_outlined,
  'cta': Icons.ads_click_outlined,
  'phone_box': Icons.phone_outlined,
  'review_faces': Icons.sentiment_satisfied_outlined,
  'team': Icons.groups_outlined,
  'branches': Icons.storefront_outlined,
  'store_locator': Icons.travel_explore_outlined,
  'services': Icons.handyman_outlined,
  'reviews': Icons.reviews_outlined,
  'before_after': Icons.compare_outlined,
  'faq': Icons.quiz_outlined,
  'post_title': Icons.title,
};

/// A widget as a card: what it is, what it is set to, and a way in.
///
/// Fixed width rather than a grid with a column count. The cards wrap, so a
/// narrow pane gets one per row and a wide one gets four, without this screen
/// having to know which — and 260 is the width at which the longest status
/// line ("On, during set hours") fits on one line.
class _WidgetCard extends StatelessWidget {
  const _WidgetCard({
    required this.spec,
    required this.status,
    required this.onOpen,
  });

  final _WidgetCardSpec spec;
  final String status;
  final VoidCallback onOpen;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    /*
     * Jose, 2026-09-23: *"The cards should be uniform, same sizes"*.
     *
     * The width was already fixed; the HEIGHT was whatever the content came
     * to. `Wrap` does not stretch its children, and the blurbs run to one line
     * or two — "Where the business is." against "The bubble a visitor opens to
     * start a conversation." — so the row was ragged along the bottom.
     * Measured before fixing it: 115 against 132.
     *
     * 132 is the tallest the content reaches, not a number chosen to look
     * right: icon row, a two-line blurb (`maxLines: 2` below caps it) and one
     * status line. The blurb takes the slack via `Expanded`, so a one-line
     * blurb pads downward and every status line lands on the same baseline
     * across the row — which is the part that reads as uniform rather than
     * the outlines matching.
     */
    return SizedBox(
      width: 260,
      height: MediaQuery.textScalerOf(context).scale(132),
      child: Card(
        margin: EdgeInsets.zero,
        clipBehavior: Clip.antiAlias,
        child: InkWell(
          onTap: onOpen,
          child: Padding(
            padding: const EdgeInsets.all(14),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              // No `mainAxisSize.min` any more: the height is the SizedBox's
              // now, and a Column told to shrink around an `Expanded` child is
              // two answers to the same question.
              children: [
                Row(
                  children: [
                    Icon(spec.icon, size: 20, color: theme.colorScheme.primary),
                    const SizedBox(width: 8),
                    Expanded(
                      child: Text(spec.label, style: theme.textTheme.titleSmall),
                    ),
                  ],
                ),
                const SizedBox(height: 6),
                Expanded(
                  child: Text(
                    spec.blurb,
                    style: theme.textTheme.bodySmall,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
                const SizedBox(height: 10),
                // What it is set to now, so the grid answers "what is on here"
                // without opening anything.
                Text(
                  status,
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: theme.colorScheme.primary,
                    fontWeight: FontWeight.w600,
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _Rendered {
  const _Rendered(this.key, this.label, this.blurb, this.knobs);

  /// The shortcode's own name: `[ezbizi_social_links]`.
  final String key;
  final String label;
  final String blurb;
  final List<_Knob> knobs;
}

/// The four widgets and every option each one has.
///
/// A hand copy of `WidgetPresets::defaults()`, `CHOICES` and `RANGES`, and a
/// copy on purpose. The server CLAMPS rather than refuses, so a control that
/// offered a value outside these bounds would save something other than what
/// it showed and say nothing — the failure that has no symptom. An option
/// added there has to be added here or it cannot be set at all.
/// The icons a Contact details section may wear (WidgetPresets::CONTACT_ICONS).
const _contactIcons = ['auto', 'phone', 'mobile', 'email', 'address', 'home', 'hours', 'calendar', 'license', 'star', 'chat', 'globe', 'arrow', 'none'];

const List<_Rendered> _renderedCatalogue = [
  _Rendered(
    'social_links',
    'Social links',
    'The row of icons, usually in a footer.',
    [
      _Choice('style', 'Style', ['plain', 'solid', 'outline']),
      _Choice('shape', 'Shape', ['circle', 'square', 'rounded']),
      _Number('size', 'Icon size', 16, 64, 28, suffix: 'px'),
      _Number('gap', 'Space between', 0, 40, 10, suffix: 'px'),
      _Flag('labels', 'Name each network beside its icon', false),
      _Text('networks', 'Which networks, in order',
          hint: 'e.g. facebook,instagram,youtube — empty shows every one set',
          max: 300),
      _Choice('colors', 'Colours', ['single', 'brand']),
      _Flag('new_tab', 'Open in a new tab', true),
      _Choice('hover', 'On hover', ['none', 'fade', 'grow', 'lift']),
      _Text('custom', 'Other platforms',
          hint: 'Name | link | icon image link, separated by ; — the icon is optional',
          max: 1000),
    ],
  ),
  _Rendered(
    'city_list',
    'Towns served',
    'The places this business covers, linked to their own pages.',
    [
      _Choice('layout', 'Arrangement', ['columns', 'list', 'accordion']),
      _Number('columns', 'Columns', 1, 6, 3),
      _Flag('group', 'Group them by county', true),
      _Flag('link', 'Link each town to its page', true),
      _Flag('show_state', 'Add the state after each town', true),
      _Flag('search', 'A search box above the towns', false),
      _Text('search_placeholder', 'Search box text', max: 60),
      _Text('empty_text', 'When a search finds nothing', max: 80),
      _Choice('heading_tag', 'Group names as', ['none', 'h2', 'h3', 'h4', 'h5', 'h6']),
      _Text('other_label', 'Panel for towns in no group', max: 40),
      _Flag('count', 'Show how many towns each group has', false),
      _Flag('bullets', 'Bullets', false),
      _Flag('open_first', 'Folded: open the first group', false),
      _Flag('one_open', 'Folded: one group open at a time', false),
      _Flag('new_tab', 'Open town pages in a new tab', false),
      _Number('column_gap', 'Space between columns', 0, 80, 28, suffix: 'px'),
    ],
  ),
  _Rendered(
    'hours_table',
    'Opening hours',
    'The published week, as a table.',
    [
      _Flag('today', 'Mark today’s row', true),
      _Flag('compact', 'Collapse days that share hours', false),
      _Choice('layout', 'Arrangement', ['table', 'stack', 'inline']),
      _Text('title', 'Title above', max: 80),
      _Flag('show_closed', 'Show closed days', true),
      _Text('closed_text', 'A closed day says', max: 30),
      _Choice('clock', 'Clock', ['12', '24']),
      _Choice('days', 'Day names', ['short', 'long']),
      _Flag('status', 'Say whether it is open right now', false),
      _Text('open_text', 'When open', max: 30),
      _Text('shut_text', 'When closed', max: 30),
      _Flag('dividers', 'Lines between days', false),
      _Colour('divider_color', 'Line colour'),
    ],
  ),
  /*
   * Jose, 2026-09-23, after walking A1 Tools' three live sites: "DO ALL OF
   * THEM, END TO END". These three come first because they are the ones every
   * page of a-1chimney.com, everdoors.com and crestln.com carries.
   */
  _Rendered(
    'contact_info',
    'Contact details',
    'Phone, email and address as one block, the way a footer shows them.',
    [
      _Choice('layout', 'Arrangement', ['stack', 'row', 'compact', 'grid', 'inline']),
      _Flag('icons', 'Show an icon beside each one', true),
      _Flag('labels', 'Label each one', true),
      _Flag('phone', 'Include the phone number', true),
      _Flag('email', 'Include the email address', true),
      _Flag('address', 'Include the address', true),
      _Flag('hours', 'Include the hours', false),
      _Flag('license', 'Include the licence number', false),
      _Flag('secondary', 'Include the second number and inbox', false),
      _Text('phone_title', 'Phone title', max: 40),
      _Text('email_title', 'Email title', max: 40),
      _Text('address_title', 'Address title', max: 40),
      _Text('hours_title', 'Hours title', max: 40),
      _Text('license_title', 'Licence title', max: 40),
      _Text('closed_text', 'A closed day says', max: 30),
      _Text('order', 'Order', hint: 'phone,email,address,hours,license', max: 60),
      _Number('icon_gap', 'Space beside the icon', 0, 40, 10, suffix: 'px'),
      _Number('item_gap', 'Space between items', 0, 80, 18, suffix: 'px'),
      _Flag('links', 'Make them tappable (call, email, map)', true),
      _Flag('underline', 'Underline links', false),
      _Flag('dividers', 'Lines between items', false),
      _Colour('divider_color', 'Line colour'),
      _Text('extra_phones', 'More phone numbers',
          hint: 'Label | number, separated by ; — e.g. Fax | 555-0101; Office | 555-0102',
          max: 500),
      _Choice('phone_icon', 'Phone icon', _contactIcons),
      _Choice('email_icon', 'Email icon', _contactIcons),
      _Choice('address_icon', 'Address icon', _contactIcons),
      _Choice('hours_icon', 'Hours icon', _contactIcons),
      _Choice('license_icon', 'Licence icon', _contactIcons),
    ],
  ),
  _Rendered(
    'cta',
    'Call to action',
    'A button that calls, books or jumps to the form — in place, along the '
        'bottom, or as a panel.',
    [
      _Choice('placement', 'Where it sits', ['inline', 'bar', 'popup']),
      _Choice('action', 'What it does',
          ['call', 'book', 'form', 'email', 'review', 'url', 'sms', 'whatsapp']),
      _Choice('label', 'Button shows', ['words', 'value']),
      _Text('text', 'Wording', hint: 'Leave empty to match what it does.'),
      _Text('url', 'Link, when it opens a link', hint: 'https://…', max: 500),
      _Text('url_params', 'Tracking added to the link',
          hint: 'e.g. utm_source=website&utm_medium=cta', max: 200),
      _Flag('new_tab', 'Open links in a new tab', false),
      _Flag('icon', 'Show an icon', true),
      _Choice('icon_type', 'Which icon',
          ['auto', 'phone', 'email', 'calendar', 'star', 'arrow', 'chat', 'none']),
      _Choice('icon_position', 'Icon side', ['left', 'right']),
      _Number('icon_gap', 'Space beside the icon', 0, 40, 8, suffix: 'px'),
      _Flag('full_width', 'Fill the width it is given', false),
      _Choice('animation', 'Animation', ['none', 'pulse', 'shake', 'bounce', 'glow']),
      _Number('animation_speed', 'One cycle every', 1, 10, 2, suffix: 's'),
      _Choice('animate_on', 'Animate', ['always', 'hover']),
      _Colour('glow_color', 'Glow colour'),
      _Choice('mobile', 'On phones', ['same', 'bar', 'hide']),
    ],
  ),
  _Rendered(
    'phone_box',
    'Phone button',
    'The number on its own, big enough to tap.',
    [
      _Choice('size', 'Size', ['small', 'medium', 'large']),
      _Text('label', 'Line above it', hint: 'Optional.', max: 60),
      _Flag('icon', 'Show an icon', true),
      _Flag('link', 'Tapping it dials', true),
      _Choice('which', 'Which number', ['primary', 'secondary']),
      _Choice('link_type', 'A tap', ['call', 'sms', 'whatsapp']),
      _Choice('icon_position', 'Icon', ['left', 'right', 'top']),
      _Choice('format', 'Written as', ['as_entered', 'dashes', 'dots', 'parentheses']),
    ],
  ),
  _Rendered(
    'review_faces',
    'Review faces',
    'A happy and a sad face. Happy goes to your review link; sad opens a '
        'private form that arrives in Leads.',
    [
      _Choice('tone', 'Background it sits on', ['light', 'dark']),
      _Number('face_size', 'Face size', 40, 120, 56, suffix: 'px'),
      _Text('title', 'Heading'),
      _Text('text', 'Line under it', max: 160),
      _Text('happy_label', 'Happy face label', max: 30),
      _Text('sad_label', 'Sad face label', max: 30),
      _Text('happy_title', 'After happy: heading'),
      _Text('happy_text', 'After happy: line', max: 160),
      _Text('button', 'Review button', max: 60),
      _Text('sad_title', 'After sad: heading'),
      _Text('sad_text', 'After sad: line', max: 160),
      _Text('submit', 'Send button', max: 40),
      _Text('thanks', 'Once sent', max: 200),
      _Flag('ask_public', 'After a complaint, still offer the review link',
          true),
      _Text('public_prompt', 'Wording of that offer', max: 200),
    ],
  ),
  _Rendered(
    'team',
    'Team',
    'The people on your team page, grouped under their headings. The list '
        'itself is kept below.',
    [
      _Number('columns', 'Columns', 1, 6, 3),
      _Choice('photo', 'Photo shape', ['square', 'circle']),
      _Flag('bio', 'Show what each person wrote', true),
    ],
  ),
  _Rendered(
    'branches',
    'Branches',
    'The branches this website answers for, each with its phone, a map and '
        'directions.',
    [
      _Number('columns', 'Columns', 1, 4, 3),
      _Flag('address', 'Show the address', true),
      _Flag('map', 'Show a map', true),
      _Flag('directions', 'Show a directions button', true),
    ],
  ),
  _Rendered(
    'store_locator',
    'Store locator',
    'Every location the company has, with a search box and a state filter.',
    [
      _Flag('search', 'Show the search box', true),
      _Flag('images', 'Show each picture', true),
      _Flag('maps', 'Show a map on each', true),
    ],
  ),
  _Rendered(
    'services',
    'Services',
    'The services the company offers, as cards or a list.',
    [
      _Choice('layout', 'Arrangement', ['grid', 'list']),
      _Number('columns', 'Columns', 1, 4, 3),
      _Flag('image', 'Show each picture', true),
      _Flag('icon', 'Show each icon', true),
      _Flag('description', 'Show each description', true),
      _Flag('phone', 'Show each phone number', false),
      _Text('category', 'Only this group', hint: 'Empty shows every one', max: 60),
      _Text('button_text', 'Link wording', hint: 'Empty hides the link', max: 40),
      _Flag('new_tab', 'Open links in a new tab', false),
    ],
  ),
  _Rendered(
    'reviews',
    'Reviews',
    'Featured reviews, or the Google reviews EzBizi already has.',
    [
      _Choice('source', 'Which reviews', ['auto', 'featured', 'google']),
      _Choice('layout', 'Arrangement', ['grid', 'list', 'slider']),
      _Number('columns', 'Columns', 1, 4, 3),
      _Number('limit', 'How many', 1, 50, 6),
      _Number('min_rating', 'Lowest star rating shown', 1, 5, 4),
      _Flag('stars', 'Show the stars', true),
      _Flag('show_source', 'Say where each was left', true),
      _Flag('date', 'Show the date', true),
      _Flag('photo', 'Show the reviewer\'s photo', true),
      _Number('excerpt', 'Shorten to (characters, 0 = whole)', 0, 1000, 0, step: 10),
      _Flag('summary', 'Average rating above them', false),
      _Colour('star_color', 'Star colour'),
    ],
  ),
  _Rendered(
    'before_after',
    'Before & after',
    'Project photos compared, by dragging or side by side.',
    [
      _Choice('layout', 'Arrangement', ['slider', 'side']),
      _Number('columns', 'Columns', 1, 4, 2),
      _Flag('title', 'Show each title', true),
      _Flag('description', 'Show each description', true),
      _Text('category', 'Only this service', hint: 'Empty shows every one', max: 60),
      _Text('before_label', 'Before label', max: 30),
      _Text('after_label', 'After label', max: 30),
      _Colour('handle_color', 'Divider colour'),
    ],
  ),
  _Rendered(
    'faq',
    'FAQ',
    'Questions that open to their answers, with the FAQ markup search engines read.',
    [
      _Text('category', 'Only this group', hint: 'Empty shows every one', max: 60),
      _Flag('show_category', 'Group headings', false),
      _Choice('icon', 'Open and close mark', ['plus', 'chevron']),
      _Flag('schema', 'FAQ markup for search engines', true),
      _Flag('open_first', 'First answer open', false),
      _Flag('one_open', 'One answer open at a time', true),
    ],
  ),
  _Rendered(
    'google_map',
    'Map',
    'Where the business is.',
    [
      _Number('height', 'Height', 120, 900, 320, step: 10, suffix: 'px'),
      _Number('height_mobile', 'Height on phones (0 = same)', 0, 900, 0, step: 10, suffix: 'px'),
      _Number('zoom', 'Zoom', 1, 20, 14),
      _Choice('map_type', 'Map type', ['roadmap', 'satellite', 'hybrid', 'terrain']),
      _Flag('grayscale', 'Grey', false),
      _Flag('address', 'Address under the map', false),
      _Flag('directions', 'Directions button', false),
      _Text('directions_text', 'Directions wording', max: 40),
    ],
  ),
  _Rendered(
    'post_title',
    'Page title',
    'The title of the page it sits on, or the town it is about.',
    [
      _Choice('tag', 'Drawn as', ['none', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'span', 'div']),
      _Choice('format', 'Shows', ['title', 'city', 'city_state', 'address']),
      _Text('prefix', 'Words before', max: 80),
      _Text('suffix', 'Words after', max: 80),
      _Text('fallback', 'When there is nothing to show', max: 120),
    ],
  ),
];

/// `WidgetPresets::name`, in the box rather than after the save.
///
/// It STRIPS rather than substitutes, exactly as the PHP does: "Footer Icons"
/// becomes `footericons`, not `footer-icons`. Guessing the nicer answer here
/// would mean the name printed in the shortcode on this screen is not the name
/// the server stored, and somebody would paste a shortcode that silently
/// rendered the default.
String _presetName(String raw) {
  final clean =
      raw.trim().toLowerCase().replaceAll(RegExp(r'[^a-z0-9_-]'), '');

  return clean.length <= 32 ? clean : clean.substring(0, 32);
}

/// What is wrong with a name, or null.
String? _presetProblem(String name, Set<String> taken) {
  if (name.isEmpty) {
    return 'Lowercase letters, numbers, - and _ only.';
  }

  if (name == _defaultPreset) {
    return 'Every widget already has a default.';
  }

  if (taken.contains(name)) {
    return 'There is already a preset called “$name”.';
  }

  return null;
}

/// Normalises while somebody types, so the name in the box is the name that
/// will be stored — the surprise this exists to prevent is a name that changes
/// on save.
class _PresetNameFormatter extends TextInputFormatter {
  const _PresetNameFormatter();

  @override
  TextEditingValue formatEditUpdate(
    TextEditingValue old,
    TextEditingValue next,
  ) {
    final clean = _presetName(next.text);

    if (clean == next.text) return next;

    // Only characters are ever removed, so the caret keeps its place by
    // moving back as far as the text did.
    final lost = next.text.length - clean.length;
    final at = (next.selection.baseOffset - lost).clamp(0, clean.length);

    return TextEditingValue(
      text: clean,
      selection: TextSelection.collapsed(offset: at),
    );
  }
}

/// One widget's presets: the default, any named ones, and the shortcodes.
///
/// ── Why it is a widget of its own rather than four more methods ──
///
/// The box somebody types a new name into has to own its controller. Written
/// as part of the editor it would be a value in the config, so every keystroke
/// would rebuild the pane and take the cursor with it — the `_QuestionRow` bug,
/// which cost enough the first time to be worth not repeating. Nothing leaves
/// here until Add is pressed.
class _PresetCard extends StatefulWidget {
  const _PresetCard({
    required this.rendered,
    required this.presets,
    required this.canEdit,
    required this.onChanged,
    this.schema = const {},
  });

  final _Rendered rendered;

  /// Option → rule, for this widget. The ones with a `part` are the generated
  /// style options, drawn in groups below the widget's own knobs.
  final Map<String, dynamic> schema;

  /// Name → values, as the server sends them. `default` is always among them.
  final Map<String, dynamic> presets;
  final bool canEdit;

  /// The whole map for this widget, because adding, renaming and deleting are
  /// all changes to the set of KEYS and cannot be said one value at a time.
  final void Function(Map<String, dynamic>) onChanged;

  @override
  State<_PresetCard> createState() => _PresetCardState();
}

class _PresetCardState extends State<_PresetCard> {
  final TextEditingController _name = TextEditingController();
  String? _problem;

  @override
  void dispose() {
    _name.dispose();
    super.dispose();
  }

  /// The default first, then the named ones in the order they were added.
  List<String> get _names {
    final named =
        widget.presets.keys.where((key) => key != _defaultPreset).toList();

    return [_defaultPreset, ...named];
  }

  Set<String> get _taken => widget.presets.keys.toSet();

  Map<String, dynamic> _values(String name) =>
      Map<String, dynamic>.from(widget.presets[name] as Map? ?? const {});

  void _put(String name, Map<String, dynamic> values) {
    widget.onChanged(Map<String, dynamic>.from(widget.presets)..[name] = values);
  }

  void _add() {
    final name = _presetName(_name.text);
    final problem = _presetProblem(name, _taken);

    if (problem != null) {
      setState(() => _problem = problem);

      return;
    }

    // Starts from the DEFAULT rather than from the catalogue's own values: a
    // named preset is almost always "the usual one, but smaller", and copying
    // what is already in use is the shorter way to say that.
    _put(name, _values(_defaultPreset));

    _name.clear();
    setState(() => _problem = null);
  }

  void _delete(String name) {
    widget.onChanged(Map<String, dynamic>.from(widget.presets)..remove(name));
  }

  /// Rebuilt in order rather than removed and re-added, so renaming a preset
  /// does not also move it to the bottom of the list.
  void _rename(String from, String to) {
    final next = <String, dynamic>{};

    widget.presets.forEach((key, value) {
      next[key == from ? to : key] = value;
    });

    widget.onChanged(next);
  }

  Future<void> _promptRename(String from) async {
    final to = await showDialog<String>(
      context: context,
      builder: (context) => _PresetNameDialog(
        heading: 'Rename “$from”',
        initial: from,
        taken: _taken.difference({from}),
        action: 'Rename',
      ),
    );

    if (to == null || to == from || !mounted) return;

    _rename(from, to);
  }

  String _shortcode(String name) => name == _defaultPreset
      ? '[ezbizi_${widget.rendered.key}]'
      : '[ezbizi_${widget.rendered.key} preset="$name"]';

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Container(
      margin: const EdgeInsets.only(bottom: 16),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        border: Border.all(color: theme.colorScheme.outlineVariant),
        borderRadius: BorderRadius.circular(8),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            widget.rendered.label,
            style: theme.textTheme.bodyMedium?.copyWith(
              fontWeight: FontWeight.w600,
            ),
          ),
          const SizedBox(height: 2),
          Text(widget.rendered.blurb, style: theme.textTheme.bodySmall),
          for (final name in _names) _preset(theme, name),
          if (widget.canEdit)
            Padding(
              padding: const EdgeInsets.only(top: 16),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Expanded(
                    child: TextField(
                      key: ValueKey('preset-add:${widget.rendered.key}'),
                      controller: _name,
                      inputFormatters: const [_PresetNameFormatter()],
                      style: theme.textTheme.bodyMedium,
                      decoration: InputDecoration(
                        labelText: 'Another preset',
                        hintText: 'footer',
                        isDense: true,
                        errorText: _problem,
                        helperText: 'Lowercase letters, numbers, - and _.',
                        helperMaxLines: 2,
                      ),
                      onSubmitted: (_) => _add(),
                    ),
                  ),
                  const SizedBox(width: 8),
                  Padding(
                    padding: const EdgeInsets.only(top: 2),
                    child: TextButton.icon(
                      onPressed: _add,
                      icon: const Icon(Icons.add, size: 18),
                      label: const Text('Add'),
                    ),
                  ),
                ],
              ),
            ),
        ],
      ),
    );
  }

  Widget _preset(ThemeData theme, String name) {
    final isDefault = name == _defaultPreset;
    final values = _values(name);

    return Padding(
      key: ValueKey('preset:${widget.rendered.key}:$name'),
      padding: const EdgeInsets.only(top: 12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  isDefault ? 'Default' : name,
                  style: theme.textTheme.bodyMedium,
                ),
              ),
              if (widget.canEdit && !isDefault) ...[
                IconButton(
                  tooltip: 'Rename this preset',
                  icon: const Icon(Icons.edit_outlined, size: 18),
                  onPressed: () => _promptRename(name),
                ),
                IconButton(
                  tooltip: 'Delete this preset',
                  icon: const Icon(Icons.close, size: 18),
                  onPressed: () => _delete(name),
                ),
              ],
            ],
          ),
          // Left-aligned in a column of its own rather than beside the name: a
          // 32-character preset makes this chip longer than a phone is wide,
          // and in a Row it would have nowhere to wrap to.
          Align(
            alignment: Alignment.centerLeft,
            child: ShortcodeButton(code: _shortcode(name)),
          ),
          const SizedBox(height: 8),
          ..._colourGroup(theme, values, name),
          for (final knob in widget.rendered.knobs)
            _knob(theme, knob, values, name),
          ..._styleGroups(theme, values, name),
        ],
      ),
    );
  }

  /// Jose, 2026-09-25: "i want all the options, for all the widgets".
  ///
  /// The style options are generated on the server — colours, type, border,
  /// spacing for each part of the widget — so they are drawn from its schema,
  /// one folded group per part. Every one starts empty, meaning "the website's
  /// own look", which is why they can sit folded away until somebody wants one.
  /// Every colour of the widget, open and first (Toula, 2026-10-05: "it
  /// still doesn't allow me to change the color of each widget"). They were
  /// all there, folded inside the "Style — …" groups at the foot of the
  /// card, where nobody found them; the chat's colours sit in plain sight,
  /// and so do these now. One picker each, named by part: "Icons — colour".
  List<Widget> _colourGroup(
    ThemeData theme,
    Map<String, dynamic> values,
    String preset,
  ) {
    final colours = <Widget>[];

    widget.schema.forEach((key, raw) {
      final rule = Map<String, dynamic>.from(raw as Map? ?? const {});
      final part = rule['part'];
      if (part is! String || rule['type'] != 'color') return;

      final where = _partLabels[part] ?? part;
      final what = (_propLabels[rule['prop']] ?? '${rule['prop']}').toLowerCase();
      colours.add(_knob(
        theme,
        _Colour(key, '${where[0].toUpperCase()}${where.substring(1)} — $what'),
        values,
        preset,
      ));
    });

    if (colours.isEmpty) return const [];

    return [
      ExpansionTile(
        key: ValueKey('colours-$preset'),
        initiallyExpanded: true,
        tilePadding: EdgeInsets.zero,
        childrenPadding: const EdgeInsets.only(bottom: 8),
        title: Text('Colours', style: theme.textTheme.bodyMedium),
        subtitle: Text(
          'Empty keeps the website\'s own colour.',
          style: theme.textTheme.bodySmall,
        ),
        children: colours,
      ),
    ];
  }

  List<Widget> _styleGroups(
    ThemeData theme,
    Map<String, dynamic> values,
    String preset,
  ) {
    final parts = <String, List<_Knob>>{};

    widget.schema.forEach((key, raw) {
      final rule = Map<String, dynamic>.from(raw as Map? ?? const {});
      final part = rule['part'];
      if (part is! String) return;
      // Drawn under Colours, above; once is enough.
      if (rule['type'] == 'color') return;

      final label = _propLabels[rule['prop']] ?? '${rule['prop']}';
      final knob = switch (rule['type']) {
        'color' => _Colour(key, label),
        'range' => _Number(key, label, (rule['min'] as num).toInt(),
            (rule['max'] as num).toInt(), 0),
        'choice' => _Choice(key, label,
            [for (final c in rule['choices'] as List) '$c']),
        _ => _Text(key, label,
            hint: 'Empty uses the website\'s own font.', max: 60),
      };

      parts.putIfAbsent(part, () => []).add(knob);
    });

    return [
      for (final entry in parts.entries)
        ExpansionTile(
          key: ValueKey('style-$preset-${entry.key}'),
          tilePadding: EdgeInsets.zero,
          childrenPadding: const EdgeInsets.only(bottom: 8),
          title: Text(
            'Style — ${_partLabels[entry.key] ?? entry.key}',
            style: theme.textTheme.bodyMedium,
          ),
          subtitle: Text(
            'Empty or 0 keeps the website\'s own look.',
            style: theme.textTheme.bodySmall,
          ),
          children: [
            for (final knob in entry.value) _knob(theme, knob, values, preset),
          ],
        ),
    ];
  }

  static const Map<String, String> _partLabels = {
    'box': 'the whole widget',
    'icon': 'icons',
    'label': 'labels',
    'value': 'values',
    'text': 'text',
    'title': 'heading',
    'heading': 'headings',
    'link': 'links',
    'button': 'button',
    'number': 'number',
    'day': 'day names',
    'time': 'times',
    'closed': 'closed days',
    'name': 'names',
    'role': 'job titles',
    'photo': 'photos',
    'card': 'each card',
  };

  static const Map<String, String> _propLabels = {
    'color': 'Colour',
    'hover_color': 'Colour on hover',
    'bg': 'Background',
    'hover_bg': 'Background on hover',
    'border_color': 'Border colour',
    'hover_border_color': 'Border colour on hover',
    'border_width': 'Border width',
    'radius': 'Corner radius',
    'padding': 'Padding',
    'padding_y': 'Padding top and bottom',
    'padding_x': 'Padding at the sides',
    'shadow': 'Shadow',
    'align': 'Alignment',
    'devices': 'Show on',
    'font_family': 'Font',
    'font_size': 'Text size',
    'font_weight': 'Weight',
    'text_transform': 'Capitals',
    'letter_spacing': 'Letter spacing',
    'line_height': 'Line height (%)',
    'size': 'Size',
  };

  Widget _knob(
    ThemeData theme,
    _Knob knob,
    Map<String, dynamic> values,
    String preset,
  ) {
    void set(Object? chosen) =>
        _put(preset, Map<String, dynamic>.from(values)..[knob.key] = chosen);

    return switch (knob) {
      _Choice choice => Padding(
          padding: const EdgeInsets.only(bottom: 8),
          child: SearchPickerField<String>(
            value: choice.options.contains('${values[choice.key]}')
                ? '${values[choice.key]}'
                : choice.options.first,
            label: choice.label,
            isDense: true,
            options: [
              for (final option in choice.options)
                SearchOption(
                  value: option,
                  // An empty choice is "not set": the website's own look.
                  label: option.isEmpty ? 'Website default' : option,
                ),
            ],
            onChanged: widget.canEdit
                ? (chosen) => chosen == null ? null : set(chosen)
                : null,
          ),
        ),
      _Number number => _slider(theme, number, values, set),
      _Flag flag => SwitchListTile(
          contentPadding: EdgeInsets.zero,
          dense: true,
          value: values[flag.key] == null
              ? flag.fallback
              : values[flag.key] == true,
          onChanged: widget.canEdit ? (on) => set(on) : null,
          title: Text(flag.label, style: theme.textTheme.bodyMedium),
        ),
      _Colour colour => ColourField(
          key: ValueKey('$preset:${colour.key}'),
          label: colour.label,
          value: '${values[colour.key] ?? ''}',
          enabled: widget.canEdit,
          hint: "#rrggbb, empty uses the website's",
          onChanged: (v) => set(v.trim()),
        ),
      _Text text => Padding(
          padding: const EdgeInsets.only(bottom: 8),
          child: TextFormField(
            // Keyed by preset AND option, so switching preset rebuilds the
            // box with that preset's words. Without the preset in the key the
            // field keeps the previous one's text, which is the bug the
            // question rows on this screen already had once.
            key: ValueKey('$preset:${text.key}'),
            initialValue: '${values[text.key] ?? ''}',
            enabled: widget.canEdit,
            maxLength: text.max,
            decoration: InputDecoration(
              labelText: text.label,
              helperText: text.hint.isEmpty ? null : text.hint,
              isDense: true,
              counterText: '',
            ),
            onChanged: set,
          ),
        ),
    };
  }

  /// A slider with its value in the label above it.
  ///
  /// The number has to be readable while the thumb is where it is: Material's
  /// own bubble only shows during a drag, and "how big are these icons" is a
  /// question somebody asks without touching anything.
  Widget _slider(
    ThemeData theme,
    _Number number,
    Map<String, dynamic> values,
    void Function(Object?) set,
  ) {
    final value = number.read(values[number.key]);

    return Padding(
      padding: const EdgeInsets.only(bottom: 4),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            '${number.label} — $value${number.suffix}',
            style: theme.textTheme.bodySmall,
          ),
          Slider(
            value: value.toDouble(),
            min: number.min.toDouble(),
            max: number.max.toDouble(),
            divisions: ((number.max - number.min) / number.step).round(),
            label: '$value${number.suffix}',
            onChanged: widget.canEdit ? (raw) => set(number.snap(raw)) : null,
          ),
        ],
      ),
    );
  }
}

/// Naming a preset, in a box that owns its own text.
///
/// A dialog rather than an editable label in the row, because renaming changes
/// a KEY: a rename applied per keystroke would walk through `f`, `fo`, `foo`
/// as three differently-named presets, and the shortcode printed beside it
/// would advertise each one in turn.
class _PresetNameDialog extends StatefulWidget {
  const _PresetNameDialog({
    required this.heading,
    required this.initial,
    required this.taken,
    required this.action,
  });

  final String heading;
  final String initial;

  /// Names already in use on this widget, not counting the one being renamed.
  final Set<String> taken;
  final String action;

  @override
  State<_PresetNameDialog> createState() => _PresetNameDialogState();
}

class _PresetNameDialogState extends State<_PresetNameDialog> {
  late final TextEditingController _name = TextEditingController(
    text: widget.initial,
  );
  String? _problem;

  @override
  void dispose() {
    _name.dispose();
    super.dispose();
  }

  void _submit() {
    final name = _presetName(_name.text);
    final problem = _presetProblem(name, widget.taken);

    if (problem != null) {
      setState(() => _problem = problem);

      return;
    }

    Navigator.of(context).pop(name);
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: Text(widget.heading),
      content: TextField(
        controller: _name,
        autofocus: true,
        inputFormatters: const [_PresetNameFormatter()],
        decoration: InputDecoration(
          labelText: 'Preset name',
          errorText: _problem,
          helperText: 'Lowercase letters, numbers, - and _.',
          helperMaxLines: 2,
        ),
        onSubmitted: (_) => _submit(),
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: const Text('Cancel'),
        ),
        FilledButton(onPressed: _submit, child: Text(widget.action)),
      ],
    );
  }
}
