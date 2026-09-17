import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../widgets/async_body.dart';
import '../../widgets/error_note.dart';

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

    final list = ListView(
      children: [
        for (final site in _sites)
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
    required this.canEdit,
    required this.previewWidth,
    required this.onPreviewDelta,
    required this.onPreviewSettled,
  });

  final ApiClient api;
  final String siteId;
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
  bool _loading = true;
  bool _saving = false;
  String? _error;
  String? _saved;

  /// True when this site dresses itself; false when it follows the primary.
  bool _own = false;

  Map<String, dynamic> _config = {};
  Map<String, dynamic> _inherited = {};

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

    final formPane = ListView(
      padding: const EdgeInsets.all(20),
      children: [
        if (!_own)
          Padding(
            padding: const EdgeInsets.only(bottom: 12),
            child: Text(
              'Following the primary website. Change anything here and this '
              'site starts answering for itself.',
              style: theme.textTheme.bodySmall,
            ),
          ),

        Text('Live chat', style: theme.textTheme.titleSmall),
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
        _text(theme, 'Contact question', chat['contact_label'],
            (v) => _set('chat', 'contact_label', v)),
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

        const Divider(height: 32),
        Text('Contact form', style: theme.textTheme.titleSmall),
        _text(theme, 'Button', form['button'], (v) => _set('form', 'button', v)),
        _text(theme, 'Thank you message', form['thanks'],
            (v) => _set('form', 'thanks', v)),
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

        const Divider(height: 32),
        Text('Look', style: theme.textTheme.titleSmall),
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
        _text(theme, 'Accent colour (#rrggbb)', style['accent'],
            (v) => _set('style', 'accent', v)),
        _presets(theme),
        _text(theme, 'Text on the accent', style['on_accent'],
            (v) => _set('style', 'on_accent', v)),
        _pair(theme, style['on_accent'], style['accent'], 'the accent'),
        _text(theme, 'Text colour', style['text'], (v) => _set('style', 'text', v)),
        _text(theme, 'Background', style['background'],
            (v) => _set('style', 'background', v)),
        _pair(theme, style['text'], style['background'], 'the background'),
        _text(theme, 'Font family', style['font'], (v) => _set('style', 'font', v)),
        _text(theme, 'Corner radius', '${style['radius'] ?? 12}',
            (v) => _set('style', 'radius', int.tryParse(v) ?? 12)),

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
              FilledButton(
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
  /// ── Why a switched-off widget still draws ──
  ///
  /// Dressing one before turning it on is the ordinary order of doing this. A
  /// preview that stayed blank until the switch flipped would hide the thing
  /// during the exact minutes somebody was working on it, so an off widget
  /// draws and says that it is off.
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
        _previewHeading(theme, 'Live chat'),
        const SizedBox(height: 8),
        _mockPage(
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
                      color: accent,
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            _line(chat['title']),
                            style: TextStyle(
                              color: onAccent,
                              fontWeight: FontWeight.w700,
                              fontSize: 14,
                            ),
                          ),
                          if (_line(chat['intro']).isNotEmpty)
                            Text(
                              _line(chat['intro']),
                              style: TextStyle(
                                color: onAccent.withValues(alpha: 0.9),
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
                            chat['contact_label'],
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
                              accent,
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
                          accent,
                          onAccent,
                          radius,
                        ),
                      ),
                      const SizedBox(height: 6),
                      _mockBubble(
                        _line(chat['auto_reply']),
                        const Color(0xFFF1F3F5),
                        const Color(0xFF1B1B1B),
                        radius,
                      ),
                    ],
                  ),
                ),
              ],

              const SizedBox(height: 8),
              // The closed bubble, which is what most visitors ever see of it.
              _mockButton(_line(chat['button']), accent, onAccent, radius),
            ],
          ),
        ),
        const SizedBox(height: 20),
        _previewHeading(theme, 'Contact form'),
        const SizedBox(height: 8),
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
      ],
    );
  }

  static String _line(Object? value) => '${value ?? ''}';

  /// Just the section name now.
  ///
  /// It used to carry "off on your site" beside it, because a widget could be
  /// switched off while its editor sat there looking live. Jose removed the
  /// switches on 2026-09-15 — placing the widget is what shows it — so there is
  /// no off state left to announce.
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
    void Function(String) onChanged,
  ) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: TextFormField(
        // Keyed by label so a site swap rebuilds rather than keeping the
        // previous site's text in the box.
        key: ValueKey('$label:${widget.siteId}:$_external'),
        initialValue: '${value ?? ''}',
        enabled: widget.canEdit,
        style: theme.textTheme.bodyMedium,
        decoration: InputDecoration(labelText: label, isDense: true),
        onChanged: onChanged,
      ),
    );
  }

  Widget _dropdown(
    ThemeData theme,
    String label,
    String value,
    List<String> options,
    void Function(String) onChanged,
  ) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: DropdownButtonFormField<String>(
        // Sizes to its slot and ellipsises, rather than to its longest option.
        // Without it the button keeps its intrinsic width and overflows the row
        // by 13px once a pane is dragged narrow — which nothing could do until
        // the panes became draggable.
        isExpanded: true,
        initialValue: options.contains(value) ? value : options.first,
        decoration: InputDecoration(labelText: label, isDense: true),
        items: [
          for (final option in options)
            DropdownMenuItem(value: option, child: Text(option)),
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
class _QuestionRow {
  _QuestionRow({String label = '', this.type = 'text', this.required = false})
    : label = TextEditingController(text: label);

  final TextEditingController label;
  final Key key = UniqueKey();

  String type;
  bool required;

  Map<String, dynamic> toJson() => {
    'label': label.text,
    'type': type,
    'required': required,
  };

  void dispose() => label.dispose();
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
      ),
  ];

  /// What the rows currently say, handed up as the plain list the API takes.
  void _publish() => widget.onChanged([for (final row in _rows) row.toJson()]);

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
                  child: DropdownButtonFormField<String>(
                    // See the note on the other one: sizes to its slot, so a
                    // narrowed pane ellipsises instead of overflowing.
                    isExpanded: true,
                    initialValue: row.type,
                    decoration: const InputDecoration(
                      labelText: 'Answer',
                      isDense: true,
                    ),
                    items: [
                      for (final type in widget.types)
                        DropdownMenuItem(value: type, child: Text(type)),
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
