import 'package:flutter/material.dart';
import '../core/api_client.dart';
import '../core/dashboard_panels.dart';
import '../core/session.dart';
import '../widgets/async_body.dart';
import '../widgets/dashboard_card.dart';
import '../widgets/dashboard_chart.dart';
import '../widgets/dashboard_grid.dart';
import '../widgets/tag.dart';

/// The dashboard: one box per reachable module, one period for the page.
///
/// Every glance is rendered from what the server sends. There is no picker —
/// a module you can open is a box, and a module that is not built yet still
/// has one, tagged coming soon. A chart presentation draws the server's
/// slices or series above the sentences; text is sentences only.
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
  String _period = 'month';
  List<Map<String, dynamic>> _glances = [];

  static const _periods = <(String, String)>[
    ('week', 'Week'),
    ('month', 'Month'),
    ('quarter', 'Quarter'),
    ('year', 'Year'),
  ];

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final request = ++_request;

    setState(() {
      // A spinner only when there is nothing to keep — see AsyncBody. An
      // empty glances list is a legitimate answer (no modules in reach), so
      // emptiness is not "still loading"; only a successful fetch is loaded.
      // A failed first fetch must not count: Try again then has nothing to
      // keep, and a spinner is the honest thing to draw.
      _loading = !_loaded;
      _error = null;
    });

    final response = await widget.api.get('/dashboard');

    // Superseded by a newer load — applying this would undo it.
    if (!mounted || request != _request) return;

    setState(() {
      _loading = false;
      if (!response.ok) {
        _error = response.message;
        return;
      }
      _loaded = true;
      _error = null;
      _period = response.body['period'] as String? ?? 'month';
      _glances = (response.body['glances'] as List<dynamic>)
          .cast<Map<String, dynamic>>();
    });
    if (response.ok) {
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

  /// A glance as the layout stores it. Presentation and options travel with
  /// it, or saving a new ORDER would quietly reset how every box was drawn.
  Map<String, dynamic> _entryFor(Map<String, dynamic> glance) => {
    'key': glance['key'],
    'presentation': glance['presentation'] ?? 'text',
    'options': glance['options'] ?? <String, dynamic>{},
  };

  /// Compact, because the header already holds three 48-point move/drag
  /// controls. Coming soon and an empty `allowed_presentations` draw nothing
  /// — a disabled menu would invite somebody to work out why it does nothing.
  ///
  /// One option is the same case as none: `web_management` and
  /// `business_profile` allow only `text`, and a menu whose single entry is
  /// already ticked is a control that cannot change anything.
  Widget? _presentationMenu(Map<String, dynamic> glance) {
    final options = _presentationsOf(glance);
    if (options.length < 2) return null;

    final selected = glance['presentation'] as String? ?? 'text';
    final label = glance['label'] as String? ?? 'this box';

    return PopupMenuButton<String>(
      tooltip: 'How to draw “$label”',
      padding: EdgeInsets.zero,
      iconSize: 18,
      initialValue: options.contains(selected) ? selected : null,
      icon: Icon(_iconFor(selected)),
      onSelected: (value) => _setPresentation(glance['key'] as String, value),
      itemBuilder: (context) => [
        for (final option in options)
          PopupMenuItem(
            value: option,
            child: Text(_labelFor(option)),
          ),
      ],
    );
  }

  /// Exactly what the server allows, and nothing else.
  ///
  /// This used to append `text` to any list that omitted it, as an escape from
  /// a chart. The server does not accept it back — `declaredPresentation`
  /// drops a kind the glance never offered to the glance's default — so the
  /// entry drew text on this frame and the next load put the chart back, with
  /// no message. A glance that should offer text says so in its own
  /// `PRESENTATIONS`; every live one already does.
  static List<String> _presentationsOf(Map<String, dynamic> glance) {
    final raw = glance['allowed_presentations'];
    if (raw is! List) return const [];

    return [for (final item in raw) '$item'];
  }

  static String _labelFor(String presentation) => switch (presentation) {
        'pie' => 'Pie',
        'bar' => 'Bar',
        'line' => 'Line',
        'timeline' => 'Timeline',
        'text' => 'Text',
        _ => presentation,
      };

  static IconData _iconFor(String presentation) => switch (presentation) {
        'pie' => Icons.pie_chart,
        'bar' => Icons.bar_chart,
        'line' => Icons.show_chart,
        'timeline' => Icons.timeline,
        _ => Icons.notes,
      };

  @override
  Widget build(BuildContext context) {
    return AsyncBody(
      loading: _loading,
      error: _error,
      onRetry: _load,
      child: ListView(
        // A phone's 16px gutter: 24 took the room a card's title needs.
        padding: EdgeInsets.all(
          MediaQuery.sizeOf(context).width < 600 ? 16 : 24,
        ),
        children: [
          // The title moved to the shell header. Refresh stays on the right,
          // on the period chips' line: a line of its own was 60px holding one
          // icon, which a phone cannot spare.
          Row(
            children: [
              Expanded(
                child: Wrap(
                  spacing: 8,
                  runSpacing: 8,
                  children: [
                    for (final (key, label) in _periods)
                      ChoiceChip(
                        // The fill says which is chosen. The tick made the
                        // four too wide to share a phone's line with Refresh.
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
              IconButton(
                onPressed: _load,
                icon: const Icon(Icons.refresh),
                tooltip: 'Refresh',
              ),
            ],
          ),
          const SizedBox(height: 16),
          DashboardGrid(
            onReorder: _reorder,
            panels: [
              for (final glance in _glances)
                DashboardPanel(
                  key: glance['key'] as String,
                  title: glance['label'] as String,
                  icon: dashboardPanelIcon(glance['key'] as String),
                  badge: glance['coming_soon'] == true
                      ? const Tag('Coming soon', warn: true)
                      : null,
                  controls: _presentationMenu(glance),
                  body: _GlanceBody(glance: glance),
                ),
            ],
          ),
        ],
      ),
    );
  }
}

/// What one glance says, rendered from its payload rather than its name.
///
/// A pie / bar / line / timeline with slices or series draws the chart above
/// the sentences. Text, empty, and coming soon are sentences only — an empty
/// chart is not a sentence. The BOX around it is DashboardCard, through
/// DashboardGrid.
class _GlanceBody extends StatelessWidget {
  const _GlanceBody({required this.glance});

  final Map<String, dynamic> glance;

  @override
  Widget build(BuildContext context) {
    final data =
        glance['data'] as Map<String, dynamic>? ?? const <String, dynamic>{};

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: _body(
        data,
        glance['coming_soon'] == true,
        glance['presentation'] as String? ?? 'text',
      ),
    );
  }

  List<Widget> _body(
    Map<String, dynamic> data,
    bool comingSoon,
    String presentation,
  ) {
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
        else
          for (final note in notes) DashboardNote(note),
      ];
    }

    return [
      if (DashboardChart.canDraw(presentation, data))
        DashboardChart(
          presentation: presentation,
          slices: data['slices'] is List ? data['slices'] as List : null,
          series: data['series'] is List ? data['series'] as List : null,
        ),
      ..._sentences(data),
      // No money line, ever. Jose, 2026-09-29: "do not show any financial
      // info on the dashboard widgets" — anybody who can open a module sees
      // its box, dispatchers included. There used to be a renderer here that
      // drew any {amount_minor, currency} list; it is deleted rather than
      // left in place, so a glance that starts sending money again draws
      // nothing instead of drawing it. The server test
      // `test_no_glance_sends_money_to_the_dashboard` is the real guard,
      // because the response is readable whether or not it is drawn.
    ];
  }

  static List<Widget> _sentences(Map<String, dynamic> data) {
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

    // Same fallback, same reason as the one above.
    return [const DashboardNote('Nothing to show.')];
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
