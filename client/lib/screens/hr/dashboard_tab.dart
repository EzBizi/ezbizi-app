import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/dashboard_panels.dart';
import '../../core/session.dart';
import '../../widgets/async_body.dart';
import '../../widgets/dashboard_card.dart';
import '../../widgets/dashboard_chart.dart';
import 'hr_overview.dart';

/// Human Resources at a glance — the same one box the main dashboard draws.
///
/// Not five widget panels. The HR plan's five figures are the *text* of this
/// chip; the chart is in-flight vs stalled. Period chips write the same
/// `dashboard.period` the main board uses, so the window is reachable without
/// leaving the module. One card cannot be rearranged.
class DashboardTab extends StatefulWidget {
  const DashboardTab({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<DashboardTab> createState() => _DashboardTabState();
}

class _DashboardTabState extends State<DashboardTab> {
  bool _loading = true;
  bool _loaded = false;
  String? _error;
  int _request = 0;
  String _period = 'day';
  Map<String, dynamic>? _glance;

  /// The whole dashboard (UI queue 42), from `GET /hr/dashboard/overview`.
  /// Null until that answers, and the glance card stands in.
  Map<String, dynamic>? _overview;

  static const _periods = <(String, String)>[
    ('day', 'Day'),
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
      _loading = !_loaded;
      _error = null;
    });

    final response = await widget.api.get('/hr/dashboard');

    if (!mounted || request != _request) return;

    setState(() {
      _loading = false;
      if (!response.ok) {
        _error = response.message;
        return;
      }
      _loaded = true;
      _error = null;
      _period = response.body['period'] as String? ?? 'day';
      final glance = response.body['glance'];
      _glance = glance is Map<String, dynamic> ? glance : null;
    });

    final overview = await widget.api.get('/hr/dashboard/overview', {
      'period': _period,
    });
    if (!mounted || request != _request) return;
    setState(() {
      _overview = overview.ok && overview.data is Map
          ? Map<String, dynamic>.from(overview.data as Map)
          : null;
    });
  }

  Future<void> _setPeriod(String period) async {
    if (period == _period) return;

    setState(() => _period = period);

    final glances = await _glancesForSave();
    if (!mounted) return;

    final body = <String, dynamic>{'period': period};
    if (glances != null) body['glances'] = glances;

    final response = await widget.api.put('/dashboard/layout', body);

    if (!mounted) return;

    if (!response.ok) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(response.message)));
    } else {
      _rememberLayout(response.body, fallback: glances);
    }

    _load();
  }

  Future<void> _setPresentation(String presentation) async {
    final current = _glance;
    if (current == null) return;
    if ((current['presentation'] ?? 'text') == presentation) return;

    setState(() {
      _glance = {...current, 'presentation': presentation};
    });

    final glances = await _glancesForSave(presentation: presentation);
    if (!mounted) return;

    if (glances == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Could not save how this box is drawn.')),
      );
      _load();
      return;
    }

    final response = await widget.api.put('/dashboard/layout', {
      'glances': glances,
    });

    if (!mounted) return;

    if (response.ok) {
      _rememberLayout(response.body, fallback: glances);
      return;
    }

    ScaffoldMessenger.of(
      context,
    ).showSnackBar(SnackBar(content: Text(response.message)));

    _load();
  }

  /// Keep the union the server stored. PUT echoes `glances` after appending
  /// reachable modules this chip omitted. Without writing that into the
  /// session, the next tap sends `{hr}` again and saveLayout rebuilds the
  /// rest at defaults — wiping Jobs options and any order already saved.
  void _rememberLayout(
    Map<String, dynamic> body, {
    List<Map<String, dynamic>>? fallback,
  }) {
    final glances = body['glances'];
    final source = glances is List && glances.isNotEmpty ? glances : fallback;
    if (source == null || source.isEmpty) return;

    widget.session.rememberPreference('dashboard.glances', [
      for (final item in source)
        if (item is Map)
          {
            'key': item['key'],
            'presentation': item['presentation'] ?? 'text',
            'options': item['options'] ?? <String, dynamic>{},
          },
    ]);
  }

  /// Session layout if /me already has it; otherwise GET /dashboard so the
  /// first save names every reachable box, not only `{hr}`. Null means omit
  /// `glances` and write the period only.
  Future<List<Map<String, dynamic>>?> _glancesForSave({
    String? presentation,
  }) async {
    final fromSession = _layoutFromSession(presentation: presentation);
    if (fromSession != null) return fromSession;

    final response = await widget.api.get('/dashboard');
    if (!mounted || !response.ok) return null;

    final glances = response.body['glances'];
    if (glances is! List || glances.isEmpty) return null;

    final layout = [
      for (final item in glances)
        if (item is Map) _entryFor(item, presentation: presentation),
    ];
    widget.session.rememberPreference('dashboard.glances', layout);
    return layout;
  }

  List<Map<String, dynamic>>? _layoutFromSession({String? presentation}) {
    final saved = widget.session.me?.preferences['dashboard.glances'];
    if (saved is! List || saved.isEmpty) return null;

    return [
      for (final item in saved)
        if (item is Map) _entryFor(item, presentation: presentation),
    ];
  }

  Map<String, dynamic> _entryFor(Map item, {String? presentation}) {
    final chosen =
        presentation ?? _glance?['presentation'] as String? ?? 'text';

    return {
      'key': item['key'],
      'presentation': item['key'] == 'hr'
          ? chosen
          : (item['presentation'] ?? 'text'),
      'options': item['options'] ?? <String, dynamic>{},
    };
  }

  @override
  Widget build(BuildContext context) {
    final glance = _glance;

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
          // "At a glance" was here, directly beneath a lit-up "Dashboard" tab
          // chip — the same fact stated twice. The module's name is in the
          // shell header above; the chip says which pane. Refresh stays, on
          // the period chips' line rather than 60px of its own.
          Row(
            children: [
              Expanded(
                // UI pass 11-16: wrapped, Year sat alone on a second line at
                // 375. One line that scrolls sideways instead.
                child: SingleChildScrollView(
                  scrollDirection: Axis.horizontal,
                  child: Wrap(
                    key: const ValueKey('tour-hr-period'),
                    spacing: MediaQuery.sizeOf(context).width < 600 ? 6 : 8,
                    runSpacing: 8,
                    children: [
                      for (final (key, label) in _periods)
                        ChoiceChip(
                          // The fill says which is chosen. The tick made the
                          // four too wide to share a phone's line with Refresh;
                          // Day made five, so a phone draws them tighter.
                          showCheckmark: false,
                          visualDensity: MediaQuery.sizeOf(context).width < 600
                              ? VisualDensity.compact
                              : null,
                          labelPadding: MediaQuery.sizeOf(context).width < 600
                              ? const EdgeInsets.symmetric(horizontal: 2)
                              : null,
                          padding: MediaQuery.sizeOf(context).width < 600
                              ? const EdgeInsets.symmetric(horizontal: 6)
                              : null,
                          label: Text(label),
                          selected: _period == key,
                          onSelected: (on) {
                            if (on) _setPeriod(key);
                          },
                        ),
                    ],
                  ),
                ),
              ),
              IconButton(
                key: const ValueKey('tour-hr-refresh'),
                onPressed: _load,
                icon: const Icon(Icons.refresh),
                tooltip: 'Refresh',
              ),
            ],
          ),
          const SizedBox(height: 16),
          if (_overview case final overview?)
            HrOverview(data: overview, period: _period, session: widget.session)
          else if (glance != null)
            DashboardCard(
              title: glance['label'] as String? ?? 'Human Resources',
              icon: dashboardPanelIcon(glance['key'] as String? ?? 'hr'),
              controls: PresentationMenu.of(
                glance,
                onSelected: _setPresentation,
              ),
              child: _GlanceBody(glance: glance),
            ),
        ],
      ),
    );
  }
}

/// Same drawing rule as the main dashboard: chart above sentences, empty and
/// coming soon as a sentence, no client-side totals.
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
      return [DashboardNote(_sentenceOf(data) ?? 'Nothing to show yet.')];
    }

    return [
      // The chart's own question, asked out loud. `canDraw` and the painter's
      // `isDrawable` go through one private switch inside the widget, so this
      // is the definition rather than a copy of it — which is what the deleted
      // `_chartable` was, and it had drifted: it called an all-zero pie
      // drawable where the painter did not. Asking here rather than building
      // and letting the widget decline keeps the tree honest, so a test may
      // say `findsNothing` and mean it.
      if (DashboardChart.canDraw(presentation, data))
        DashboardChart(
          presentation: presentation,
          slices: data['slices'] is List ? data['slices'] as List : null,
          series: data['series'] is List ? data['series'] as List : null,
        ),
      ..._sentences(data),
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

    return [const DashboardNote('Nothing to show yet.')];
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
