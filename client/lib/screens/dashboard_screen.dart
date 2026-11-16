import 'package:flutter/material.dart';
import '../core/api_client.dart';
import '../core/live_changes.dart';
import '../core/dashboard_panels.dart';
import '../core/deep_links.dart';
import '../core/job_money.dart';
import '../core/numbers.dart';
import '../core/session.dart';
import '../core/theme.dart';
import '../widgets/async_body.dart';
import '../widgets/dashboard_card.dart';
import '../widgets/dashboard_chart.dart';
import '../widgets/dashboard_grid.dart';
import '../widgets/dashboard_templates.dart';
import '../widgets/search_picker.dart';
import '../widgets/side_panel.dart';
import '../widgets/tag.dart';

/// The dashboard: one box per reachable module, one period for the page.
///
/// Every glance is rendered from what the server sends. There is no picker —
/// a module you can open is a box, and a module that is not built yet still
/// has one, tagged coming soon. A chart presentation draws the server's
/// slices or series above the sentences; text is sentences only.
///
/// Jose's pick of UI queue 50 (2026-10-05, desktop 1 and phone 4): the boxes
/// sit in sections cut where his sidebar is cut, on four columns, and each is
/// Small, Medium or Large. Customize is where they are moved and sized.
class DashboardScreen extends StatefulWidget {
  const DashboardScreen({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<DashboardScreen> createState() => _DashboardScreenState();
}

class _DashboardScreenState extends State<DashboardScreen> {
  bool _loading = true;

  /// Which load owns the screen — see customers_screen for the whole argument.
  ///
  /// A write here ends in a refresh, and the control that triggers it is no
  /// longer hidden behind a spinner, so two can be in flight together. Whichever
  /// answer lands LAST used to win regardless of which was asked for last, and
  /// nothing was scheduled afterwards to correct it.
  int _request = 0;
  bool _loaded = false;
  String? _error;

  /// Today until somebody picks (Jose, 2026-10-01: "i also want the default
  /// to be Day ( as in today ) instead of Week").
  String _period = 'day';
  List<Map<String, dynamic>> _glances = [];

  /// When the figures on screen were fetched; null while they are a cached
  /// copy from the last visit (the card then says nothing rather than lie).
  DateTime? _asOf;

  /// Whether the boxes show their arranging controls.
  bool _customizing = false;

  /// A whole layout just applied (a template, or the standard one): what to
  /// say about it, and the layout it replaced, for Undo.
  ({String said, List<Map<String, dynamic>> before})? _applied;

  /// Whether the server stores a size per box. Until it does there is nothing
  /// to switch, and sending one would be a key it never reads back.
  bool get _sized => _glances.any((glance) => glance['size'] is String);

  static const _periods = <(String, String)>[
    ('day', 'Day'),
    ('week', 'Week'),
    ('month', 'Month'),
    ('quarter', 'Quarter'),
    ('year', 'Year'),
  ];

  /// The last dashboard each person saw, per view, for this run of the app.
  ///
  /// Coming back to the dashboard paints it at once from here and refreshes
  /// underneath, instead of a spinner over the whole screen for as long as the
  /// slowest box takes (Jose, 2026-10-16: "takes too long when it should be
  /// instant"). Keyed by person AND view, so switching company never shows
  /// another view's numbers; held in memory only, and hung on the ApiClient,
  /// so it lives as long as the client that was allowed to read it (and two
  /// tests, each with its own client, never share one).
  static final Expando<Map<String, Map<String, dynamic>>> _lastSeenBy =
      Expando();

  Map<String, Map<String, dynamic>> get _lastSeen =>
      _lastSeenBy[widget.api] ??= <String, Map<String, dynamic>>{};

  String? get _seenKey {
    final me = widget.session.me;
    return me == null ? null : '${me.id}|${me.scope.type}:${me.scope.id}';
  }

  @override
  void initState() {
    super.initState();
    final seen = _lastSeen[_seenKey];
    if (seen != null) {
      _loaded = true;
      _period = seen['period'] as String? ?? 'day';
      _glances = (seen['glances'] as List<dynamic>)
          .cast<Map<String, dynamic>>();
    }
    _load();
    // The glances count what the modules hold: any of them changing re-reads
    // the board quietly, at most once a minute (each glance is a query).
    _live = LiveChanges.of(widget.api).subscribe(_glanceTypes, (_) {
      final now = DateTime.now();
      if (!_loading &&
          now.difference(_lastLive) >= const Duration(minutes: 1)) {
        _lastLive = now;
        return _load(quiet: true);
      }
    }, active: LiveChanges.visible(this));
  }

  static const _glanceTypes = {
    'jobs',
    'leads',
    'customers',
    'inventory',
    'inventory_orders',
    'vehicles',
    'people',
    'invoices',
    'phone_calls',
    'reviews',
    'complaints',
  };

  LiveSubscription? _live;
  DateTime _lastLive = DateTime.fromMillisecondsSinceEpoch(0);

  @override
  void dispose() {
    _live?.cancel();
    super.dispose();
  }

  /// [quiet]: a live re-read; a failure keeps the glances on screen.
  Future<void> _load({bool quiet = false}) async {
    final request = ++_request;

    if (!quiet) {
      setState(() {
        // A spinner only when there is nothing to keep — see AsyncBody. An
        // empty glances list is a legitimate answer (no modules in reach), so
        // emptiness is not "still loading"; only a successful fetch is loaded.
        // A failed first fetch must not count: Try again then has nothing to
        // keep, and a spinner is the honest thing to draw.
        _loading = !_loaded;
        _error = null;
      });
    }

    final response = await widget.api.get('/dashboard');

    // Superseded by a newer load — applying this would undo it.
    if (!mounted || request != _request) return;
    if (quiet && !response.ok) return;

    setState(() {
      _loading = false;
      if (!response.ok) {
        _error = response.message;
        return;
      }
      _loaded = true;
      _error = null;
      _asOf = DateTime.now();
      _period = response.body['period'] as String? ?? 'day';
      _glances = (response.body['glances'] as List<dynamic>)
          .cast<Map<String, dynamic>>();
    });
    if (response.ok) {
      final key = _seenKey;
      if (key != null) _lastSeen[key] = response.body;
      _rememberLayout(response.body, fallback: _glances);
    }
  }

  /// Put the glances in the order somebody just dragged them into, and remember
  /// it. Period is left off: a reorder is not a change of window.
  ///
  /// The card moves on this frame. Waiting for a round trip would mean a card
  /// that stays put for as long as the network takes, which reads as a control
  /// that did not work — and invites a second drag. If the save fails the
  /// message is shown and the screen is reloaded, which puts the boxes back
  /// where the server still has them rather than leaving a lie on screen.
  Future<void> _reorder(List<String> keys) async {
    final byKey = {
      for (final glance in _glances) glance['key'] as String: glance,
    };

    setState(() {
      _glances = [
        for (final key in keys)
          if (byKey.containsKey(key)) byKey[key]!,
      ];
    });

    await _saveLayout();
  }

  /// One section's new order, written back into the whole list. The other
  /// sections' boxes keep their places; this one's take its places in turn.
  List<String> _withSection(List<String> section) {
    final members = section.toSet();
    final next = section.iterator;

    return [
      for (final glance in _glances)
        if (members.contains(glance['key']) && next.moveNext())
          next.current
        else
          glance['key'] as String,
    ];
  }

  /// Save every box as it now is. A failed save says why and reloads, which
  /// puts the boxes back where the server still has them.
  Future<void> _saveLayout() async {
    final response = await widget.api.put('/dashboard/layout', {
      'glances': [for (final glance in _glances) _entryFor(glance)],
    });

    if (!mounted) return;
    if (response.ok) {
      _rememberLayout(response.body, fallback: _glances);
      return;
    }

    ScaffoldMessenger.of(
      context,
    ).showSnackBar(SnackBar(content: Text(response.message)));

    _load();
  }

  /// Resize on this frame, like a drag, and save.
  Future<void> _setSize(String key, String size) async {
    if (!_glances.any((g) => g['key'] == key && g['size'] != size)) return;

    setState(() {
      _glances = [
        for (final glance in _glances)
          if (glance['key'] == key) {...glance, 'size': size} else glance,
      ];
    });

    await _saveLayout();
  }

  /// Whether the server keeps a box hidden. Until it does there is no hide:
  /// a box taken away here would come back on the next load.
  bool get _hideable => _glances.any((glance) => glance.containsKey('hidden'));

  /// Take a box off the dashboard, on this frame, and save.
  Future<void> _hide(String key) async {
    setState(() {
      _glances = [
        for (final glance in _glances)
          if (glance['key'] == key) {...glance, 'hidden': true} else glance,
      ];
    });

    await _saveLayout();
  }

  /// Put a hidden box back. It came without data (a hidden box costs the
  /// server nothing), so the dashboard is read again to fill it.
  Future<void> _addBox() async {
    final hidden = [
      for (final glance in _glances)
        if (glance['hidden'] == true) glance,
    ];
    if (hidden.isEmpty) return;

    final key = await showSearchPicker<String>(
      context,
      title: 'Add a box',
      options: [
        for (final glance in hidden)
          SearchOption(
            value: glance['key'] as String,
            label: '${glance['label']}',
          ),
      ],
    );
    if (key == null || !mounted) return;

    setState(() {
      _glances = [
        for (final glance in _glances)
          if (glance['key'] == key) {...glance, 'hidden': false} else glance,
      ];
    });

    await _saveLayout();
    if (mounted) await _load();
  }

  /// The recommended layouts (UI queue 53, Jose: 1 and 4), applied at once
  /// with Undo on the dashboard rather than behind an "are you sure".
  Future<void> _openTemplates() async {
    final choice = await showSidePanel<DashboardTemplateChoice>(
      context,
      builder: (context) => SidePanel(
        title: 'Templates',
        child: DashboardTemplatesPanel(api: widget.api),
      ),
    );
    if (choice == null || !mounted) return;

    final template = choice.template;
    // As the server sent them: a Small box names no mode and keeps its own.
    final glances = template == null
        ? const <Map<String, dynamic>>[]
        : glancesOf(template);
    final hidden = glances.where((glance) => glance['hidden'] == true).length;

    await _replaceLayout(
      glances,
      said: template == null
          ? 'Back to the standard layout.'
          : 'Using ${template['label']}. '
                '${hidden == 1 ? '1 box is' : '$hidden boxes are'} hidden; '
                'Add a box brings any of them back.',
    );
  }

  /// Save a whole layout at once, keeping the one it replaces for Undo. The
  /// standard layout is an empty save.
  Future<void> _replaceLayout(
    List<Map<String, dynamic>> glances, {
    required String said,
  }) async {
    final before = [for (final glance in _glances) _entryFor(glance)];
    final response = await widget.api.put('/dashboard/layout', {
      'glances': glances,
    });
    if (!mounted) return;

    if (!response.ok) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(response.message)));
      return;
    }

    setState(() => _applied = (said: said, before: before));
    await _load();
  }

  /// Put back the layout a template replaced, every box as it was.
  Future<void> _undoLayout() async {
    final applied = _applied;
    if (applied == null) return;

    setState(() => _applied = null);
    final response = await widget.api.put('/dashboard/layout', {
      'glances': applied.before,
    });
    if (!mounted) return;

    if (!response.ok) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(response.message)));
    }
    await _load();
  }

  Future<void> _setPeriod(String period) async {
    if (period == _period) return;

    setState(() => _period = period);

    final response = await widget.api.put('/dashboard/layout', {
      'period': period,
      'glances': [for (final glance in _glances) _entryFor(glance)],
    });

    if (!mounted) return;

    if (!response.ok) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(response.message)));
    } else {
      _rememberLayout(response.body, fallback: _glances);
    }

    _load();
  }

  /// Remember how this box is drawn. The payload does not change with
  /// presentation — slices and series are already on the glance — so the
  /// card updates on this frame. A failed save reloads, the same as a
  /// rejected drag.
  Future<void> _setPresentation(String key, String presentation) async {
    Map<String, dynamic>? current;
    for (final glance in _glances) {
      if (glance['key'] == key) {
        current = glance;
        break;
      }
    }
    if (current == null) return;
    if ((current['presentation'] ?? 'text') == presentation) return;

    setState(() {
      _glances = [
        for (final glance in _glances)
          if (glance['key'] == key)
            {...glance, 'presentation': presentation}
          else
            glance,
      ];
    });

    await _saveLayout();
  }

  /// Keep the stored layout in the session so the HR chip's first save does
  /// not name only `{hr}` and wipe Jobs options the main board just wrote.
  void _rememberLayout(
    Map<String, dynamic> body, {
    required List<Map<String, dynamic>> fallback,
  }) {
    final glances = body['glances'];
    final source = glances is List && glances.isNotEmpty ? glances : fallback;
    if (source.isEmpty) return;

    widget.session.rememberPreference('dashboard.glances', [
      for (final item in source)
        if (item is Map) _entryFor(Map<String, dynamic>.from(item)),
    ]);
  }

  /// A glance as the layout stores it. Presentation, options and size travel
  /// with it, or saving a new ORDER would quietly reset how every box was
  /// drawn. Size only once the server sends one.
  Map<String, dynamic> _entryFor(Map<String, dynamic> glance) => {
    'key': glance['key'],
    'presentation': glance['presentation'] ?? 'text',
    'options': glance['options'] ?? <String, dynamic>{},
    if (glance['size'] is String) 'size': glance['size'],
    if (glance['hidden'] is bool) 'hidden': glance['hidden'],
  };

  @override
  Widget build(BuildContext context) {
    final byKey = {
      for (final glance in _glances) glance['key'] as String: glance,
    };
    final sections = dashboardSections([
      for (final glance in _glances)
        // Muse audit 10.5: a "Coming soon" box points at a tab that does not
        // exist yet, so it is not drawn until the module ships.
        if (glance['hidden'] != true && glance['coming_soon'] != true)
          glance['key'] as String,
    ], widget.session.me?.navigationOrder ?? const []);

    return AsyncBody(
      loading: _loading,
      error: _error,
      onRetry: _load,
      child: ListView(
        key: const ValueKey('tour-dashboard-boxes'),
        // A phone's 16px gutter: 24 took the room a card's title needs.
        // The top matches the 8px every other module's tab strip leaves
        // under the title row.
        padding: switch (MediaQuery.sizeOf(context).width < 600 ? 16.0 : 24.0) {
          final g => EdgeInsets.fromLTRB(g, 8, g, g),
        },
        children: [
          if (_customizing) _arrangeBar(context) else _periodRow(context),
          if (_applied case final applied?) ...[
            const SizedBox(height: 12),
            _appliedBar(context, applied.said),
          ],
          const SizedBox(height: 16),
          // Every box hidden is a dashboard somebody emptied, not one with
          // nothing to say: it says how to bring a box back.
          if (sections.isEmpty && _glances.isNotEmpty)
            DashboardNote(
              _customizing
                  ? 'Every box is hidden. Add a box brings one back.'
                  : 'Every box is hidden. Customize, then Add a box, brings '
                        'one back.',
            ),
          for (final (i, section) in sections.indexed) ...[
            // The sidebar's own rule between its groups.
            if (i > 0) const Divider(height: 33),
            DashboardGrid(
              // By position, not by the first box: a box moved to the front of
              // its section would otherwise make it a new grid and drop the
              // focus the move buttons hand along.
              key: ValueKey('dashboard-section-$i'),
              customizing: _customizing,
              onReorder: (keys) => _reorder(_withSection(keys)),
              onResize: _sized ? _setSize : null,
              onHide: _hideable ? _hide : null,
              panels: [for (final key in section) _panelFor(byKey[key]!)],
            ),
          ],
        ],
      ),
    );
  }

  DashboardPanel _panelFor(Map<String, dynamic> glance) {
    final size = glance['size'] as String? ?? 'medium';
    final compact = size == 'small' && _GlanceBody.figuresOf(glance).isNotEmpty;

    return DashboardPanel(
      key: glance['key'] as String,
      title: glance['label'] as String,
      icon: dashboardPanelIcon(glance['key'] as String),
      badge: glance['coming_soon'] == true ? const Tag('Coming soon') : null,
      // A Small box with figures draws its headline and no chart, so a menu
      // of charts would switch something that is not on screen.
      controls: compact
          ? null
          : PresentationMenu.of(
              glance,
              onSelected: (value) =>
                  _setPresentation(glance['key'] as String, value),
            ),
      size: size,
      // The whole card opens its module (Muse audit 2A); the chart menu
      // stays on its own icon. Coming-soon boxes have nowhere to go.
      onOpen: glance['coming_soon'] == true
          ? null
          : () => DeepLinks.openModule(_moduleOf(glance['key'] as String)),
      body: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _GlanceBody(glance: glance, size: size),
          if (_asOf case final at?)
            Padding(
              padding: const EdgeInsets.only(top: 8),
              child: Text(
                'As of ${TimeOfDay.fromDateTime(at).format(context)}',
                style: Theme.of(context).textTheme.labelSmall?.copyWith(
                  color: Theme.of(context).colorScheme.onSurfaceVariant,
                ),
              ),
            ),
        ],
      ),
    );
  }

  /// The sidebar module a box belongs to. Most box keys ARE module keys;
  /// the few that live inside another module say so here.
  static String _moduleOf(String key) =>
      const {'newsletter': 'marketing'}[key] ?? key;

  /// What Customize looks like: what to do, and the way out.
  Widget _arrangeBar(BuildContext context) {
    final theme = Theme.of(context);
    final words = Text(
      switch ((_sized, _hideable)) {
        (true, true) => 'Drag a box to move it, pick its size, or hide it.',
        (true, false) => 'Drag a box to move it, or pick its size.',
        (false, true) => 'Drag a box to move it, or hide it.',
        (false, false) => 'Drag a box to move it.',
      },
      style: theme.textTheme.bodyMedium?.copyWith(
        color: theme.colorScheme.onPrimaryContainer,
      ),
    );
    final tools = [
      TextButton.icon(
        key: const Key('dashboard-templates'),
        onPressed: _openTemplates,
        icon: const Icon(Icons.view_quilt_outlined),
        label: const Text('Templates'),
      ),
      if (_glances.any((glance) => glance['hidden'] == true))
        TextButton.icon(
          onPressed: _addBox,
          icon: const Icon(Icons.add),
          label: const Text('Add a box'),
        ),
    ];
    final done = FilledButton(
      onPressed: () => setState(() => _customizing = false),
      child: const Text('Done'),
    );

    return Container(
      padding: const EdgeInsets.fromLTRB(14, 6, 6, 6),
      decoration: BoxDecoration(
        color: theme.colorScheme.primaryContainer,
        borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
      ),
      // A phone puts Templates and Add a box on a line of their own: beside
      // the words they left them a sliver, and the bar grew five lines tall,
      // pushing the boxes it is about off the screen.
      child: MediaQuery.sizeOf(context).width < 600
          ? Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Row(
                  children: [
                    Expanded(child: words),
                    done,
                  ],
                ),
                Wrap(spacing: 4, children: tools),
              ],
            )
          : Row(
              children: [
                Expanded(child: words),
                for (final tool in tools) ...[tool, const SizedBox(width: 4)],
                done,
              ],
            ),
    );
  }

  /// What a template just did, with the way back.
  Widget _appliedBar(BuildContext context, String said) {
    final theme = Theme.of(context);
    final info = ToneColours.of(context, Tone.info);

    return Container(
      key: const Key('dashboard-template-applied'),
      padding: const EdgeInsets.fromLTRB(14, 4, 4, 4),
      decoration: BoxDecoration(
        color: info.fill,
        borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
      ),
      child: Row(
        children: [
          Expanded(
            child: Text(
              said,
              style: theme.textTheme.bodyMedium?.copyWith(color: info.ink),
            ),
          ),
          TextButton(
            key: const Key('dashboard-template-undo'),
            onPressed: _undoLayout,
            child: const Text('Undo'),
          ),
          IconButton(
            tooltip: 'Dismiss',
            onPressed: () => setState(() => _applied = null),
            icon: const Icon(Icons.close, size: 18),
          ),
        ],
      ),
    );
  }

  Widget _periodRow(BuildContext context) {
    final phone = MediaQuery.sizeOf(context).width < 600;

    // The title moved to the shell header. Refresh stays on the right, on the
    // period chips' line: a line of its own was 60px holding one icon, which a
    // phone cannot spare. Customize sits beside it.
    //
    // A phone has one period chip that opens the five (phone 4 of the gallery):
    // five chips and Refresh filled a 390 phone's line exactly, and Customize
    // pushed Year onto a second one.
    return Row(
      children: [
        if (phone)
          PopupMenuButton<String>(
            key: const ValueKey('tour-dashboard-period'),
            tooltip: 'Period',
            initialValue: _period,
            onSelected: _setPeriod,
            itemBuilder: (context) => [
              for (final (key, label) in _periods)
                PopupMenuItem(value: key, child: Text(label)),
            ],
            child: Container(
              height: 36,
              padding: const EdgeInsets.only(left: 12, right: 4),
              decoration: BoxDecoration(
                color: Theme.of(context).colorScheme.secondaryContainer,
                borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
              ),
              child: Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(
                    _periods
                        .firstWhere(
                          (p) => p.$1 == _period,
                          orElse: () => _periods.first,
                        )
                        .$2,
                  ),
                  const Icon(Icons.arrow_drop_down),
                ],
              ),
            ),
          ),
        if (phone)
          const Spacer()
        else
          Expanded(
            child: Wrap(
              key: const ValueKey('tour-dashboard-period'),
              spacing: 8,
              runSpacing: 8,
              children: [
                for (final (key, label) in _periods)
                  ChoiceChip(
                    // The fill says which is chosen; a tick as well is a
                    // second way of saying it, and the width it takes.
                    showCheckmark: false,
                    label: Text(label),
                    selected: _period == key,
                    onSelected: (on) {
                      if (on) _setPeriod(key);
                    },
                  ),
              ],
            ),
          ),
        if (_glances.isNotEmpty)
          phone
              ? IconButton(
                  key: const ValueKey('tour-dashboard-customize'),
                  onPressed: () => setState(() => _customizing = true),
                  icon: const Icon(Icons.dashboard_customize_outlined),
                  tooltip: 'Customize',
                )
              : TextButton.icon(
                  key: const ValueKey('tour-dashboard-customize'),
                  onPressed: () => setState(() => _customizing = true),
                  icon: const Icon(Icons.dashboard_customize_outlined),
                  label: const Text('Customize'),
                ),
        IconButton(
          key: const ValueKey('tour-dashboard-refresh'),
          onPressed: _load,
          icon: const Icon(Icons.refresh),
          tooltip: 'Refresh',
        ),
      ],
    );
  }
}

/// What one glance says, rendered from its payload rather than its name.
///
/// A pie / bar / line / timeline with slices or series draws the chart above
/// the sentences. Text, empty, and coming soon are sentences only — an empty
/// chart is not a sentence. The BOX around it is DashboardCard, through
/// DashboardGrid.
///
/// A glance that sends `figures` is drawn by its size: Small is the headline
/// figure, Medium every figure, the chart and the box's `notes`, Large adds
/// what needs somebody. Its `sentences` are NOT drawn then: they say the same
/// numbers for the installed builds that predate figures, and `notes` carries
/// whatever is not a figure. A glance without figures draws as it always has,
/// at every size.
class _GlanceBody extends StatelessWidget {
  const _GlanceBody({required this.glance, required this.size});

  final Map<String, dynamic> glance;
  final String size;

  /// The box's figures, the first its headline. Empty when it sends none, and
  /// a figure this build cannot read is left out rather than drawn as a zero.
  static List<Map<String, dynamic>> figuresOf(Map<String, dynamic> glance) {
    final data = glance['data'];
    final raw = data is Map ? data['figures'] : null;
    if (raw is! List) return const [];

    return [
      for (final item in raw)
        if (item is Map && item['value'] is int && item['label'] is String)
          Map<String, dynamic>.from(item),
    ];
  }

  @override
  Widget build(BuildContext context) {
    final data =
        glance['data'] as Map<String, dynamic>? ?? const <String, dynamic>{};

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: _body(
        context,
        data,
        glance['coming_soon'] == true,
        glance['presentation'] as String? ?? 'text',
      ),
    );
  }

  List<Widget> _body(
    BuildContext context,
    Map<String, dynamic> data,
    bool comingSoon,
    String presentation,
  ) {
    // A box that could not load says so in the colour of a fault, not in the
    // muted voice of a box with nothing in it: "nothing happened" and "we
    // could not ask" are different facts.
    if (glance['failed'] == true) {
      final scheme = Theme.of(context).colorScheme;

      return [
        Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Icon(Icons.error_outline, size: 18, color: scheme.error),
            const SizedBox(width: 8),
            Expanded(
              child: Text(
                _sentencesOf(data).firstOrNull ??
                    'This box could not load right now.',
                style: TextStyle(color: scheme.error),
              ),
            ),
          ],
        ),
      ];
    }

    if (comingSoon || data['empty'] == true) {
      final notes = _sentencesOf(data);

      return [
        // EVERY sentence, not the first one.
        //
        // This drew `_sentenceOf(data)` alone until 2026-09-11, so an empty
        // box with three sentences showed one and threw two away — no error,
        // no gap on screen, nothing to notice. I documented that as a trap in
        // 0d200ed3 and told two sessions to avoid `empty: true`; Email then
        // found it live. Two production mailboxes had been broken since
        // 2026-08-20. Broken means no slice, both broken means no slices at
        // all, so `empty` went true and the home screen named one dead inbox
        // and silently dropped the other. Three weeks.
        //
        // Documenting a trap makes it somebody's job to remember. The box has
        // no reason to ration sentences here — an empty box is exactly where
        // the words ARE the content, which is the case where losing them
        // costs most.
        //
        // "Nothing to show." and not "…yet" when a glance sent nothing at
        // all: UI/UX ruled 2026-09-09 that an empty sentence describes the
        // BOX, not the world, and this line is reached precisely when we do
        // not know what the box holds.
        if (notes.isEmpty)
          const DashboardNote('Nothing to show.')
        else ...[
          // 54 near-identical sentences buried the box: show three, keep the
          // rest one tap away so none is dropped.
          for (final note in notes.take(3)) DashboardNote(note),
          if (notes.length > 3)
            ExpansionTile(
              tilePadding: EdgeInsets.zero,
              title: Text('${notes.length - 3} more'),
              children: [for (final note in notes.skip(3)) DashboardNote(note)],
            ),
        ],
      ];
    }

    final figures = figuresOf(glance);
    final small = size == 'small' && figures.isNotEmpty;
    final charted = !small && DashboardChart.canDraw(presentation, data);
    final chart = DashboardChart(
      presentation: presentation,
      slices: data['slices'] is List ? data['slices'] as List : null,
      series: data['series'] is List ? data['series'] as List : null,
    );

    // Money is drawn only as a figure carrying `currency`, never from any
    // other key. Jose, 2026-10-05: "its a role y permission things where (
    // can see financials ) should be toggled" — the server sends an amount
    // only to a person whose role holds that module's financials, so the
    // response is the gate, not this screen (it can be read whether or not it
    // is drawn). The old {amount_minor, currency} lists stay undrawn.
    return [
      if (figures.isNotEmpty) ...[
        _Figures(figures: small ? figures.take(1).toList() : figures),
        if (charted) ...[const SizedBox(height: 12), chart],
        if (!small)
          for (final note in _notesOf(data))
            Padding(padding: const EdgeInsets.only(top: 6), child: Text(note)),
      ] else ...[
        if (charted) chart,
        ..._sentences(data, charted: charted),
      ],
      ..._attention(context, data),
    ];
  }

  /// What needs somebody. Large lists it; a smaller box says how much there is
  /// rather than dropping it, because a warning a size choice hides is a
  /// warning nobody chose to hide.
  List<Widget> _attention(BuildContext context, Map<String, dynamic> data) {
    final raw = data['attention'];
    final rows = [
      if (raw is List)
        for (final row in raw)
          if (row is Map &&
              row['text'] is String &&
              '${row['text']}'.isNotEmpty)
            (bad: row['tone'] == 'bad', text: row['text'] as String),
    ];
    if (rows.isEmpty) return const [];

    final theme = Theme.of(context);
    Widget line(bool bad, String text) {
      final colours = ToneColours.of(context, bad ? Tone.bad : Tone.caution);

      return Padding(
        padding: const EdgeInsets.only(top: 8),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Icon(
              bad ? Icons.error_outline : Icons.warning_amber_rounded,
              size: 16,
              color: colours.ink,
            ),
            const SizedBox(width: 8),
            Expanded(child: Text(text, style: theme.textTheme.bodySmall)),
          ],
        ),
      );
    }

    if (size == 'large') {
      return [for (final row in rows) line(row.bad, row.text)];
    }

    return [
      line(
        rows.any((row) => row.bad),
        rows.length == 1
            ? '1 needs attention'
            : '${rows.length} need attention',
      ),
    ];
  }

  static List<String> _notesOf(Map<String, dynamic> data) {
    final notes = data['notes'];
    if (notes is! List) return const [];

    return [
      for (final note in notes)
        if ('$note'.isNotEmpty) '$note',
    ];
  }

  static List<Widget> _sentences(
    Map<String, dynamic> data, {
    required bool charted,
  }) {
    final sentences = data['sentences'];

    if (sentences is List && sentences.isNotEmpty) {
      return [
        for (final sentence in sentences)
          Padding(
            padding: const EdgeInsets.only(bottom: 6),
            child: Text('$sentence'),
          ),
      ];
    }

    final sentence = _sentenceOf(data);

    if (sentence != null) return [Text(sentence)];

    // Same fallback, same reason as the one above — but not under a drawn
    // chart, which has already shown something (Fleet, Phone, Email, Jobs and
    // Automations send slices and no sentence).
    return [if (!charted) const DashboardNote('Nothing to show.')];
  }

  /// Every sentence a payload carries, in order, whichever key it used.
  ///
  /// `sentences` is the plural form and `sentence` the singular; a glance
  /// sends one or the other. Empty list when it sent neither.
  static List<String> _sentencesOf(Map<String, dynamic> data) {
    final sentences = data['sentences'];

    if (sentences is List && sentences.isNotEmpty) {
      return [
        for (final sentence in sentences)
          if ('$sentence'.isNotEmpty) '$sentence',
      ];
    }

    final sentence = data['sentence'];

    return sentence is String && sentence.isNotEmpty ? [sentence] : const [];
  }

  static String? _sentenceOf(Map<String, dynamic> data) {
    final sentence = data['sentence'];

    if (sentence is String && sentence.isNotEmpty) return sentence;

    final sentences = data['sentences'];

    if (sentences is List && sentences.isNotEmpty) {
      return sentences.first.toString();
    }

    return null;
  }
}

/// A box's figures: the headline large, the rest beside it, each with its
/// change since the period before when there is one.
class _Figures extends StatelessWidget {
  const _Figures({required this.figures});

  final List<Map<String, dynamic>> figures;

  /// "▲ 12%", "▼ <1%" or "= 0%". Null without a previous value, and null
  /// against a previous zero: the Dashboard module's contract (2026-10-05)
  /// draws no arrow there, because a change from nothing has no size and a
  /// window with no history also reads zero.
  static String? trendOf(int value, int? previous) {
    if (previous == null || previous <= 0 || value < 0) return null;
    if (value == previous) return '= 0%';

    final percent = (value - previous) * 100 / previous;
    final arrow = percent > 0 ? '▲' : '▼';

    // Never "0%" for a change: 1,000 against 999 moved, and a zero would say
    // it did not.
    return percent.abs() < 1
        ? '$arrow <1%'
        : '$arrow ${percent.abs().round()}%';
  }

  @override
  Widget build(BuildContext context) {
    return Wrap(
      spacing: 24,
      runSpacing: 8,
      children: [
        for (final (i, figure) in figures.indexed)
          _figure(context, figure, headline: i == 0),
      ],
    );
  }

  Widget _figure(
    BuildContext context,
    Map<String, dynamic> figure, {
    required bool headline,
  }) {
    final theme = Theme.of(context);
    final currency = figure['currency'];
    String show(int n) => currency is String
        ? formatMoney(n, currency: currency, cents: false)
        : formatCount(n);

    final value = figure['value'] as int;
    final previous = figure['previous'] is int
        ? figure['previous'] as int
        : null;
    final trend = trendOf(value, previous);
    final neutral = ToneColours.of(context, Tone.neutral);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      mainAxisSize: MainAxisSize.min,
      children: [
        // A Wrap, not a Row: a Small box on a phone is 130 points wide, and
        // "43,968" beside its change does not always fit on one line.
        Wrap(
          spacing: 8,
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            Text(
              show(value),
              style:
                  (headline
                          ? theme.textTheme.headlineSmall
                          : theme.textTheme.titleMedium)
                      ?.copyWith(fontWeight: FontWeight.w800),
            ),
            if (trend != null)
              Tooltip(
                message: '${show(previous!)} the period before',
                child: Container(
                  padding: const EdgeInsets.symmetric(
                    horizontal: 6,
                    vertical: 1,
                  ),
                  decoration: BoxDecoration(
                    color: neutral.fill,
                    borderRadius: BorderRadius.circular(
                      EzBiziTheme.controlRadius,
                    ),
                  ),
                  child: Text(
                    trend,
                    style: theme.textTheme.labelSmall?.copyWith(
                      color: neutral.ink,
                      fontWeight: FontWeight.w800,
                    ),
                  ),
                ),
              ),
          ],
        ),
        Text(figure['label'] as String, style: theme.textTheme.bodySmall),
      ],
    );
  }
}
